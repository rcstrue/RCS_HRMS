<?php
/**
 * Verification harness for the Simpliance minimum-wage sync.
 *
 * Regression protection for the defects that made "Run Sync (All States)"
 * report success while the wage table stayed empty, and for the parsing rules
 * that the live data actually requires.
 *
 *   php .zscripts/verify-min-wage-sync.php            → parsing logic (default)
 *   php .zscripts/verify-min-wage-sync.php logic      → same
 *   php .zscripts/verify-min-wage-sync.php nonce      → API endpoint gate
 *                                                       (nonce window + role)
 *
 * Exits non-zero when any assertion fails.
 *
 * NOTE: every string asserted below was taken from the live Simpliance JSON
 * endpoint (https://www.simpliance.in/minimum-wages/ajax/{stateId}/{version}).
 *
 * NOT COVERED HERE, verify by hand against the live site: the stateId/version
 * values scraped from the state HTML page. The page's inline script cannot be
 * fetched from CI (the fetch layer strips <script> bodies), so the patterns in
 * MinimumWageSync::extractStateIdVersion() are asserted only against synthetic
 * HTML. If a state reports "Could not extract stateId/version", the raw page is
 * dumped to sys_get_temp_dir()/simpliance_debug_<slug>.html — read it and
 * extend the patterns.
 */

define('RCS_HRMS', true);
define('APP_ROOT', dirname(__DIR__) . '/hrms');

require_once APP_ROOT . '/includes/class.minimumwagesync.php';

$failures = 0;
$checks   = 0;

function check($label, $got, $expected, &$failures, &$checks) {
    $checks++;
    $ok = ($got === $expected);
    if (!$ok) {
        $failures++;
        printf("FAIL  %-56s got %s, expected %s\n", $label, json_encode($got), json_encode($expected));
    } else {
        printf("ok    %-56s %s\n", $label, json_encode($got));
    }
}

$mode = $argv[1] ?? 'logic';

if ($mode === 'nonce') {
    // ── API endpoint gate ───────────────────────────────────────────────
    // The endpoint validates a session-derived nonce and a role. Both need a
    // session and a $db; stub the database wrapper and run one case per process
    // via the CASE env var so the endpoint's function declarations never clash.
    if (!session_id()) {
        @session_start();
    }
    $_SESSION = ['role_code' => getenv('MW_ROLE') ?: 'admin'];

    $db = new class {
        public function fetchAll($sql, $params = []) {
            if (stripos($sql, "TABLE_NAME = 'states'") !== false) return [['COLUMN_NAME' => 'simpliance_slug']];
            if (stripos($sql, 'simpliance_slug IS NULL') !== false) return [];
            if (stripos($sql, 'FROM states') !== false) {
                return [['id' => 11, 'state_name' => 'Gujarat', 'simpliance_slug' => 'gujarat']];
            }
            return [];
        }
        public function query($sql, $params = []) { return true; }
    };

    $window = (int)floor(time() / 300);
    $nonce  = getenv('MW_NONCE') === 'stale'
        ? hash('sha256', session_id() . '|' . ($window - 1))
        : (getenv('MW_NONCE') === 'bad'
            ? str_repeat('a', 64)
            : hash('sha256', session_id() . '|' . $window));

    $_POST = ['ajax_action' => 'list-states', 'sync_nonce' => $nonce];

    ob_start();
    require APP_ROOT . '/modules/api/minimum-wage-sync.php';
    $payload = json_decode(ob_get_clean(), true);

    $expectSuccess = !in_array(getenv('MW_ROLE') ?: 'admin', ['worker', 'supervisor'], true)
        && getenv('MW_NONCE') !== 'bad';

    $checks++;
    $ok = is_array($payload) && (($payload['success'] ?? false) === $expectSuccess);
    if (!$ok) $failures++;
    printf("%s  role=%-10s nonce=%-6s success=%s\n",
        $ok ? 'ok  ' : 'FAIL',
        getenv('MW_ROLE') ?: 'admin',
        getenv('MW_NONCE') ?: 'fresh',
        var_export($payload['success'] ?? null, true));

    if ($expectSuccess) {
        // Every response must hand back a fresh nonce for the next chunk request.
        check('response carries a refreshed nonce',
            isset($payload['nonce']) && $payload['nonce'] !== '', true, $failures, $checks);
        check('state list is returned', count($payload['states'] ?? []), 1, $failures, $checks);
    }
} else {
    // ── parsing logic ───────────────────────────────────────────────────
    $sync    = new MinimumWageSync(new stdClass());
    $mapCat  = new ReflectionMethod('MinimumWageSync', 'mapCategory');
    $mapRow  = new ReflectionMethod('MinimumWageSync', 'mapRowCategory');
    $collect = new ReflectionMethod('MinimumWageSync', 'collectRows');
    $extract = new ReflectionMethod('MinimumWageSync', 'extractStateIdVersion');
    foreach ([$mapCat, $mapRow, $collect, $extract] as $m) {
        $m->setAccessible(true);
    }

    echo "── mapCategory(): real Simpliance class_of_employment strings ──\n";
    $categoryCases = [
        // Andaman & Nicobar — the decorated form that broke the old exact-match table
        'Unskilled (peon etc)'          => 'Unskilled',
        'Semi-skilled (Assistant etc)'  => 'Semi-Skilled',
        'Skilled (Clerk etc)'           => 'Skilled',
        'Highly Skilled (Manager etc)'  => 'Highly Skilled',
        // plain spellings
        'Unskilled'                     => 'Unskilled',
        'Semi Skilled'                  => 'Semi-Skilled',
        'semi-skilled'                  => 'Semi-Skilled',
        'Highly skilled'                => 'Highly Skilled',
        'Supervisor'                    => 'Supervisor',
        'Clerical'                      => 'Clerical',
        'Clerk'                         => 'Clerical',
        // drift between notification versions
        'Skilled '                      => 'Skilled',        // trailing space (Uttarakhand)
        'Semi Skilled/Unskilled Supervisory' => 'Semi-Skilled',
        'Skilled/clerical'              => 'Skilled',
        'Supervisory/Clerical'          => 'Supervisor',
        'Skilled Grade-I'               => 'Skilled',
        'Clerical And Supervisory Staff' => 'Clerical',
        'Skilled Class A'               => 'Skilled',        // Haryana `category`
        // truncated at ~50 chars by Simpliance itself
        'Bicycle Fitter/ Attender/ Peon/ Water Boy/ Shop Bo' => 'Unskilled',
        'Chowkidar (Watchman)'          => 'Unskilled',
        // "skilled" must never shadow unskilled / semi / highly
        'Unskilled Workers'             => 'Unskilled',
        'Highly Skilled Supervisor'     => 'Highly Skilled',
        // genuinely unmappable — must NOT be forced into the enum
        'Class-I (Staff)'               => null,             // Chandigarh salary class
        'Group II'                      => null,             // Karnataka grade
        'Furniture'                     => null,             // Manipur industry
        'Casual/Muster Roll Employees'  => null,
        'Non matriculates'              => null,
        'Driver'                        => null,
        '-'                             => null,
        ''                              => null,
    ];
    foreach ($categoryCases as $input => $expected) {
        check('mapCategory("' . $input . '")', $mapCat->invoke($sync, $input), $expected, $failures, $checks);
    }

    echo "\n── mapRowCategory(): field fallback when class_of_employment is unusable ──\n";
    $rowCases = [
        'Haryana: class "-", skill level in category' => [
            ['class_of_employment' => '-', 'category' => 'Unskilled'],
            ['Unskilled', 'category'],
        ],
        'Haryana: "Semi Skilled Class A"' => [
            ['class_of_employment' => '-', 'category' => 'Semi Skilled Class A'],
            ['Semi-Skilled', 'category'],
        ],
        'Kerala: every field "-" stays unmapped' => [
            ['class_of_employment' => '-', 'category' => '-', 'grade' => 'Grade III', 'designation' => '-'],
            [null, null],
        ],
        'Chandigarh: salary class, role in sub_category' => [
            ['class_of_employment' => 'Class-III (Staff)', 'sub_category' => 'Clerk'],
            ['Clerical', 'sub_category'],
        ],
        'Karnataka: grade, role in designation' => [
            ['class_of_employment' => 'Group II', 'category' => '-', 'grade' => '-',
             'sub_category' => '-', 'designation' => 'Office Supervisor'],
            ['Supervisor', 'designation'],
        ],
        'primary field wins when it maps' => [
            ['class_of_employment' => 'Skilled', 'category' => 'Unskilled'],
            ['Skilled', 'class_of_employment'],
        ],
        'Delhi meal/lodging split must not leak in' => [
            ['class_of_employment' => 'Non matriculates',
             'class_of_workers' => 'For Adult Workers in towns of more than One lakh Population'],
            [null, null],
        ],
    ];
    foreach ($rowCases as $label => $case) {
        check($label, $mapRow->invoke($sync, $case[0]), $case[1], $failures, $checks);
    }

    echo "\n── collectRows(): both Simpliance payload generations ──\n";
    $rowA = ['class_of_employment' => 'Unskilled', 'effective_date' => '2026-04-01', 'total_per_day' => 512.5];
    $rowB = ['class_of_employment' => 'Skilled',   'effective_date' => '2026-04-01', 'total_per_day' => 600];

    // up to ~version 20
    check('legacy shape {"data":{"1":[...]}}',
        $collect->invoke($sync, ['1' => [$rowA, $rowB], '2' => [$rowA]]),
        [$rowA, $rowB, $rowA], $failures, $checks);
    // version 21+ — the shape the old parser silently reduced to zero rows
    check('current shape {"data":[...]}',
        $collect->invoke($sync, [$rowA, $rowB]), [$rowA, $rowB], $failures, $checks);
    check('empty payload', $collect->invoke($sync, []), [], $failures, $checks);

    $numeric = ['class_of_employment' => 'Unskilled', 'basic_per_day' => 452, 'vda_per_day' => 60.5,
                'total_per_day' => 512.5, 'total_per_month' => 13325];
    check('numeric-typed fields survive', $collect->invoke($sync, [$numeric]), [$numeric], $failures, $checks);

    echo "\n── extractStateIdVersion(): the state page ──\n";
    check('classic "let stateId = 11; let version = 21;"',
        $extract->invoke($sync, 'let stateId = 11; let version = 21;'), [11, 21], $failures, $checks);
    check('quoted / colon form',
        $extract->invoke($sync, 'stateId: "11", version: \'21\''), [11, 21], $failures, $checks);
    check('reversed declaration order',
        $extract->invoke($sync, 'var version = 8; var stateId = 11;'), [11, 8], $failures, $checks);
    check('missing version → <select id="version"> fallback',
        $extract->invoke($sync, 'let stateId = 11;'
            . '<select id="version"><option value="21">Apr 2026</option>'
            . '<option value="20">Oct 2025</option></select>'), [11, 21], $failures, $checks);
    // a case-insensitive match would import Oct-2016 wages as if current
    check('bootstrapVersion must NOT be picked up as the version',
        $extract->invoke($sync, 'let bootstrapVersion = 533; let stateId = 11;'), [11, null], $failures, $checks);
}

echo "\n", $failures === 0
    ? "PASS — {$checks} assertion(s)\n"
    : "FAIL — {$failures} of {$checks} assertion(s)\n";
exit($failures === 0 ? 0 : 1);
