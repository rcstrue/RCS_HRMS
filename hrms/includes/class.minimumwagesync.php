<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync (Pure PHP)
 *
 * Fetches state-wise minimum wage data from Simpliance.in
 * and inserts/updates the `minimum_wages` table.
 *
 * Uses the direct JSON API: /minimum-wages/ajax/{stateId}/{version}
 * which returns clean structured JSON — no cookies, CSRF, or POST needed.
 *
 * Flow:
 *   1. GET state page → extract Simpliance stateId + version from JS
 *   2. GET /minimum-wages/ajax/{stateId}/{version} → JSON
 *   3. Parse JSON rows → INSERT or UPDATE minimum_wages
 */

class MinimumWageSync {

    const BASE_URL   = 'https://www.simpliance.in/minimum-wages';
    const AJAX_URL   = 'https://www.simpliance.in/minimum-wages/ajax';
    // Retries on 403/5xx. Kept deliberately low: an unknown stateId/version pair
    // answers 403 immediately, and the old 3 retries @ 5/10/20s burned 35s per
    // state — long enough to blow the web-server timeout on a 36-state run.
    const MAX_RETRIES = 2;
    const RETRY_DELAY = 3;  // seconds (doubles each retry → 3s, 6s)

    // Safety valve for a single HTTP request. "Run Sync (All States)" walks the
    // state list until this many seconds have elapsed and then returns the
    // remaining states so the browser can continue in a fresh request. Without
    // it the whole run lived in one request and was killed by the gateway
    // (504 / HTML error page) before it could ever emit JSON.
    const REQUEST_TIME_BUDGET = 20;

    // Simpliance state name → URL slug
    const STATE_SLUG_MAP = [
        'andaman and nicobar islands' => 'andaman-and-nicobar-islands',
        'andhra pradesh'              => 'andhra-pradesh',
        'arunachal pradesh'           => 'arunachal-pradesh',
        'assam'                        => 'assam',
        'bihar'                        => 'bihar',
        'chandigarh'                   => 'chandigarh',
        'chhattisgarh'                 => 'chhattisgarh',
        'dadra and nagar haveli'       => 'dadra-and-nagar-haveli',
        'daman and diu'                => 'daman-and-diu',
        'delhi'                        => 'delhi',
        'goa'                          => 'goa',
        'gujarat'                      => 'gujarat',
        'haryana'                      => 'haryana',
        'himachal pradesh'             => 'himachal-pradesh',
        'jammu and kashmir'            => 'jammu-and-kashmir',
        'jharkhand'                    => 'jharkhand',
        'karnataka'                    => 'karnataka',
        'kerala'                       => 'kerala',
        'lakshadweep'                  => 'lakshadweep',
        'madhya pradesh'              => 'madhya-pradesh',
        'maharashtra'                  => 'maharashtra',
        'manipur'                      => 'manipur',
        'meghalaya'                    => 'meghalaya',
        'mizoram'                      => 'mizoram',
        'nagaland'                     => 'nagaland',
        'odisha'                       => 'odisha',
        'puducherry'                   => 'puducherry',
        'punjab'                       => 'punjab',
        'rajasthan'                    => 'rajasthan',
        'sikkim'                       => 'sikkim',
        'tamil nadu'                   => 'tamil-nadu',
        'telangana'                    => 'telangana',
        'tripura'                      => 'tripura',
        'uttar pradesh'               => 'uttar-pradesh',
        'uttarakhand'                  => 'uttarakhand',
        'west bengal'                  => 'west-bengal',
        'jammu & kashmir'             => 'jammu-and-kashmir',
        'orissa'                       => 'odisha',
        'pondicherry'                  => 'puducherry',
    ];

    // Simpliance class_of_employment → HRMS worker_category ENUM
    // Must match ENUM('Unskilled','Semi-Skilled','Skilled','Highly Skilled','Supervisor','Clerical')
    // NOTE: kept for backward compatibility only — Simpliance actually sends
    // strings like "Unskilled (peon etc)" / "Semi-skilled (Assistant etc)", which
    // an exact-match table can never hit. See CATEGORY_ALIASES + mapCategory().
    const CATEGORY_MAP = [
        'unskilled'      => 'Unskilled',
        'semi-skilled'   => 'Semi-Skilled',
        'semi skilled'   => 'Semi-Skilled',
        'skilled'        => 'Skilled',
        'highly skilled' => 'Highly Skilled',
        'highly-skilled' => 'Highly Skilled',
        'supervisor'     => 'Supervisor',
        'clerical'       => 'Clerical',
        'watchmen'       => 'Unskilled',
        'sweeper'        => 'Unskilled',
    ];

    /**
     * Normalised keyword → HRMS worker_category.
     *
     * Keys are lowercase with every non-alphanumeric character removed, so
     * "Semi-skilled (Assistant etc)" → "semiskilled". Matching runs in three
     * passes: exact, then prefix, then substring — always longest key first so
     * "unskilled"/"semiskilled"/"highlyskilled" win over the bare "skilled"
     * they contain.
     */
    const CATEGORY_ALIASES = [
        'highlyskilled' => 'Highly Skilled',
        'semiskilled'   => 'Semi-Skilled',
        'supervisory'   => 'Supervisor',
        'supervisor'    => 'Supervisor',
        'unskilled'     => 'Unskilled',
        'clerical'      => 'Clerical',
        'skilled'       => 'Skilled',
        'clerk'         => 'Clerical',
        'watchman'      => 'Unskilled',
        'watchmen'      => 'Unskilled',
        'chowkidar'     => 'Unskilled',
        'sweeper'       => 'Unskilled',
        'safai'         => 'Unskilled',
        'scavenger'     => 'Unskilled',
        'peon'          => 'Unskilled',
        'mazdoor'       => 'Unskilled',
        'helper'        => 'Unskilled',
        'khalasi'       => 'Unskilled',
        'attender'      => 'Unskilled',
        'attendant'     => 'Unskilled',
        'waterboy'      => 'Unskilled',
        'messenger'     => 'Unskilled',
        'officeboy'     => 'Unskilled',
        'cleaner'       => 'Unskilled',
    ];

    private $db;
    private $mwColumns = null;      // cached column list for minimum_wages table
    private $logHasUpdated = null;  // cached: does minimum_wage_sync_log have records_updated?

    // Column mapping: Simpliance JSON key → possible DB column names (checked in order)
    const COLUMN_MAP = [
        // VDA/DA — try both names since DB may use either
        'vda_per_day'    => ['da_per_day', 'vda_per_day'],
        'vda_per_month'  => ['da_per_month', 'vda_per_month'],
        // HRA
        'hra_per_month'  => ['special_allowance_per_month', 'hra_per_month'],
        // Always present
        'basic_per_day'       => ['basic_per_day'],
        'basic_per_month'     => ['basic_per_month'],
        'total_per_day'       => ['total_per_day'],
        'total_per_month'     => ['total_per_month'],
    ];

    public function __construct($db = null) {
        $this->db = $db;
        if (!$this->db) {
            $this->db = Database::getInstance();
        }
    }

    // ── Simple cURL GET with cookie jar + retry ───────────────────
    private function fetchUrl($url, $timeout = 30) {
        $cookieFile = sys_get_temp_dir() . '/simpliance_cookies_' . md5($url) . '.txt';

        $lastError  = null;
        $lastCode   = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = self::RETRY_DELAY * pow(2, $attempt - 1);
                sleep($delay);
            }

            // Fresh cookie file for clean session each attempt
            @unlink($cookieFile);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Connection: keep-alive',
                ],
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $lastError = $error;
            $lastCode  = $httpCode;

            if ($error) {
                $lastError = "cURL error: $error";
                continue; // retry on cURL errors
            }

            if ($httpCode === 200) {
                @unlink($cookieFile);
                return $body;
            }

            // Only retry on 403 or 5xx — don't retry 404 etc.
            if ($httpCode !== 403 && $httpCode < 500) break;

            $lastError = "HTTP $httpCode for $url (attempt " . ($attempt + 1) . "/" . (self::MAX_RETRIES + 1) . ")";
        }

        @unlink($cookieFile);
        if ($lastError) throw new Exception($lastError);
        throw new Exception("HTTP $lastCode for $url");
    }

    // ── Helpers ──────────────────────────────────────────────────────
    private function parseAmount($val) {
        if ($val === null || $val === '' || $val === '-') return 0;
        return round(floatval($val), 2);
    }

    /**
     * Flatten either generation of the Simpliance payload into wage rows.
     *
     * BOTH shapes are still served in production:
     *   older (up to ~version 20): {"data":{"<industryId>":[ {row}, ... ]}}
     *   newer (version 21+):       {"data":[ {row}, {row}, ... ]}
     *
     * The previous code only understood the first, so the newest (i.e. current)
     * notifications parsed to zero rows and the state imported nothing.
     */
    private function collectRows(array $data) {
        $allRows = [];
        $first   = reset($data);
        $isFlat  = is_array($first)
            && (isset($first['class_of_employment']) || isset($first['effective_date']) || isset($first['total_per_day']));

        if ($isFlat) {
            foreach ($data as $row) {
                if (is_array($row)) $allRows[] = $row;
            }
        } else {
            foreach ($data as $rows) {
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (is_array($row)) $allRows[] = $row;
                    }
                }
            }
        }

        return $allRows;
    }

    /**
     * Pull the Simpliance stateId and notification version out of a state page.
     *
     * The page used to expose `let stateId = 19; let version = 23;`. The patterns
     * here tolerate quotes, colons and case drift, and fall back to the version
     * <select> if the inline assignment is gone. `version` stays case-sensitive
     * on purpose: a case-insensitive match would happily latch onto unrelated
     * identifiers such as `bootstrapVersion` and silently import ancient wages.
     *
     * @return array{0:int|null,1:int|null} [stateId, version]
     */
    private function extractStateIdVersion($html) {
        $stateId = null;
        $version = null;

        if (preg_match('/stateId\s*[:=]\s*["\']?(\d{1,6})/i', $html, $m)) {
            $stateId = (int)$m[1];
        }

        if (preg_match('/(?<![A-Za-z0-9_$])version\s*[:=]\s*["\']?(\d{1,6})/', $html, $m)) {
            $version = (int)$m[1];
        }

        // Fallback: the notification dropdown on the state page. Its first entry
        // is the newest published version. A truthy preg_match_all guarantees at
        // least one match, so $opts[1][0] is always set here.
        if (!$version
            && preg_match('/<select[^>]*id=["\']version["\'][^>]*>(.*?)<\/select>/is', $html, $sel)
            && preg_match_all('/<option[^>]*value=["\']?(\d{1,6})/i', $sel[1], $opts)) {
            $version = (int)$opts[1][0];
        }

        return [$stateId, $version];
    }

    /**
     * Map a wage row onto an HRMS worker_category.
     *
     * `class_of_employment` is only a skill level for roughly half of India's
     * notifications. It is "-" for whole states (Haryana, Kerala), a job title
     * for others (Tamil Nadu, Puducherry, Sikkim), a salary class (Chandigarh
     * "Class-I (Staff)"), a grade (Karnataka "Group II", Nagaland "Skilled
     * Grade-I") or an industry (Manipur "Furniture"). So when the primary field
     * does not map, the remaining descriptive fields are tried in turn.
     *
     * @return array{0:string|null,1:string|null} [HRMS category, field that matched]
     */
    private function mapRowCategory(array $row) {
        $fields = ['class_of_employment', 'category', 'grade', 'sub_category', 'designation', 'class_of_workers'];

        foreach ($fields as $field) {
            if (!isset($row[$field])) continue;
            $value = trim((string)$row[$field]);
            if ($value === '' || $value === '-') continue;

            $category = $this->mapCategory($value);
            if ($category !== null) {
                return [$category, $field];
            }
        }

        return [null, null];
    }

    /**
     * Map a Simpliance `class_of_employment` value onto the HRMS
     * `minimum_wages.worker_category` ENUM.
     *
     * Real Simpliance values are decorated and inconsistent:
     *   "Unskilled (peon etc)", "Semi-skilled (Assistant etc)",
     *   "Skilled (Clerk etc)", "Highly Skilled (Manager etc)",
     *   "Unskilled", "Highly skilled", "Semi Skilled", "Class-I (Staff)"
     *
     * The previous implementation lowercased the raw string and looked it up in
     * an exact-match table, so "unskilled (peon etc)" missed and EVERY row of
     * EVERY state was discarded as "skipped" — the sync reported success while
     * writing nothing. Matching is now fuzzy-but-conservative:
     *   1. strip bracketed qualifiers, punctuation and case
     *   2. exact keyword match
     *   3. prefix match
     *   4. substring match
     * Longest keyword first, so "unskilled"/"semiskilled"/"highlyskilled" are
     * never shadowed by the "skilled" they contain.
     *
     * @return string|null HRMS category, or null when the value is genuinely
     *                     unrecognised (caller counts and reports it).
     */
    private function mapCategory($raw) {
        $s = strtolower(trim((string)$raw));
        if ($s === '' || $s === '-') return null;

        // Drop qualifiers: "unskilled (peon etc)" → "unskilled"
        $s = preg_replace('/\([^)]*\)/', ' ', $s);
        $s = preg_replace('/\[[^\]]*\]/', ' ', $s);
        $s = str_replace('&', ' and ', $s);

        // Normalise: letters and digits only ("semi-skilled" → "semiskilled")
        $norm = preg_replace('/[^a-z0-9]/', '', $s);
        if ($norm === '') return null;

        // 1. exact
        if (isset(self::CATEGORY_ALIASES[$norm])) {
            return self::CATEGORY_ALIASES[$norm];
        }

        // Longest keyword first so contained keywords can't win
        $keys = array_keys(self::CATEGORY_ALIASES);
        usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });

        // 2. prefix — "unskilledworker", "skilledlabour"
        foreach ($keys as $k) {
            if (strpos($norm, $k) === 0) {
                return self::CATEGORY_ALIASES[$k];
            }
        }

        // 3. substring — "classiii skilled staff"
        foreach ($keys as $k) {
            if (strpos($norm, $k) !== false) {
                return self::CATEGORY_ALIASES[$k];
            }
        }

        return null;
    }

    private function slugifyState($stateName) {
        $lower = strtolower(trim($stateName));
        return self::STATE_SLUG_MAP[$lower] ?? null;
    }

    /**
     * Ensure minimum_wages table has a zone VARCHAR column.
     * Stores zone name directly (e.g. "Zone I", "Zone II") or NULL for all-zone states.
     */
    private function ensureZoneColumn() {
        try {
            $cols = $this->db->fetchAll(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wages' AND COLUMN_NAME = 'zone'"
            );
            if (empty($cols)) {
                $this->db->query("ALTER TABLE minimum_wages ADD COLUMN zone VARCHAR(50) DEFAULT NULL AFTER state_id");
            }
        } catch (Exception $e) {}
    }

    /**
     * Get zone name from Simpliance row.
     * Returns null for no zone / dash (means all zones).
     */
    private function resolveZone($simplianceZone) {
        if (empty($simplianceZone) || trim($simplianceZone) === '-') {
            return null;
        }
        return trim($simplianceZone);
    }

    // ── Ensure simpliance_slug column + populate ─────────────────────
    public function ensureSlugs() {
        $output = [];

        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states' AND COLUMN_NAME = 'simpliance_slug'"
        );

        if (empty($cols)) {
            $this->db->query("ALTER TABLE states ADD COLUMN simpliance_slug VARCHAR(100) DEFAULT NULL AFTER state_name");
            $output[] = 'Added simpliance_slug column to states table.';
        }

        $rows = $this->db->fetchAll(
            "SELECT id, state_name FROM states WHERE is_active = 1 AND (simpliance_slug IS NULL OR simpliance_slug = '')"
        );

        if (!empty($rows)) {
            $updated = 0;
            foreach ($rows as $row) {
                $slug = $this->slugifyState($row['state_name']);
                if ($slug) {
                    $this->db->query("UPDATE states SET simpliance_slug = ? WHERE id = ?", [$slug, $row['id']]);
                    $output[] = "{$row['state_name']} → $slug";
                    $updated++;
                } else {
                    $output[] = "{$row['state_name']} → (no mapping)";
                }
            }
            $output[] = "Slugs populated: $updated/" . count($rows);
        } else {
            $output[] = 'All active states already have simpliance slugs.';
        }

        return $output;
    }

    // ── Get states ready for sync ───────────────────────────────────
    private function getSyncStates($stateFilter = null) {
        $rows = $this->db->fetchAll(
            "SELECT id, state_name, simpliance_slug FROM states WHERE is_active = 1 ORDER BY state_name"
        );

        $states = [];
        foreach ($rows as $row) {
            if (empty($row['simpliance_slug'])) {
                $row['simpliance_slug'] = $this->slugifyState($row['state_name']);
            }
            if ($row['simpliance_slug']) {
                if ($stateFilter === null) {
                    $states[] = $row;
                } elseif (
                    strtolower($row['simpliance_slug']) === strtolower($stateFilter) ||
                    stripos($row['state_name'], $stateFilter) !== false
                ) {
                    $states[] = $row;
                }
            }
        }
        return $states;
    }

    // ── Fetch single state from Simpliance ─────────────────────────
    private function fetchState($state, $dryRun = false) {
        $slug    = $state['simpliance_slug'];
        $pageUrl = self::BASE_URL . '/' . $slug;

        $result = [
            'state'           => $state['state_name'],
            'state_id'        => (int)$state['id'],
            'status'          => 'error',
            'records_added'   => 0,
            'records_updated' => 0,
            'records_skipped' => 0,
            'error_message'   => null,
        ];
        // Raw Simpliance category strings that could not be mapped, so the user
        // sees WHY a state imported nothing instead of a silent "skipped".
        $unmapped = [];

        try {
            // ── Step 1: GET state page → extract stateId + version ──
            $html = $this->fetchPage($pageUrl);

            list($stateId, $version) = $this->extractStateIdVersion($html);

            if (!$stateId || !$version) {
                $result['error_message'] = "Could not extract stateId/version from page (stateId="
                    . var_export($stateId, true) . ", version=" . var_export($version, true)
                    . ", page=" . strlen($html) . " bytes). Raw HTML dumped to "
                    . sys_get_temp_dir() . "/simpliance_debug_{$slug}.html";
                @file_put_contents(sys_get_temp_dir() . "/simpliance_debug_{$slug}.html", $html);
                return $result;
            }

            // ── Step 2: GET direct JSON API ──
            $apiUrl  = self::AJAX_URL . '/' . $stateId . '/' . $version;
            $jsonRaw = $this->fetchUrl($apiUrl);
            $apiData = json_decode($jsonRaw, true);

            if (!is_array($apiData) || !isset($apiData['data']) || !is_array($apiData['data'])) {
                $result['error_message'] = 'Invalid or empty JSON from API: ' . $apiUrl;
                return $result;
            }

            // ── Step 3: Collect all rows ──
            $allRows = $this->collectRows($apiData['data']);

            if (empty($allRows)) {
                $result['error_message'] = 'API returned 0 wage rows for version ' . $version;
                return $result;
            }

            // ── Step 4: Parse and INSERT or UPDATE ──
            $effectiveDate  = null;
            $fallbackMapped = 0;
            $validCategories = ['Unskilled', 'Semi-Skilled', 'Skilled', 'Highly Skilled', 'Supervisor', 'Clerical'];

            foreach ($allRows as $r) {
                // Effective date from first row (all rows in same response share it)
                if (!$effectiveDate && !empty($r['effective_date'])) {
                    $effectiveDate = $r['effective_date'];
                }

                list($workerCategory, $mappedFrom) = $this->mapRowCategory($r);

                // Skip if the row cannot be expressed in the HRMS ENUM — remember
                // the raw value so the user can see what the source called it.
                if ($workerCategory === null || !in_array($workerCategory, $validCategories, true)) {
                    $rawLabel = trim((string)($r['class_of_employment'] ?? ''));
                    if ($rawLabel === '' || $rawLabel === '-') {
                        $rawLabel = trim((string)($r['category'] ?? $r['grade'] ?? $r['designation'] ?? ''));
                    }
                    if ($rawLabel === '') $rawLabel = '(empty)';
                    $unmapped[$rawLabel] = ($unmapped[$rawLabel] ?? 0) + 1;
                    $result['records_skipped']++;
                    continue;
                }

                if ($mappedFrom !== null && $mappedFrom !== 'class_of_employment') {
                    // Source did not name a skill level in class_of_employment;
                    // the match came from a secondary descriptive field.
                    $fallbackMapped++;
                }

                // Parse amounts (JSON API uses numeric or '-' for missing)
                $basicPerDay    = $this->parseAmount($r['basic_per_day'] ?? null);
                $basicPerMonth  = $this->parseAmount($r['basic_per_month'] ?? null);
                $vdaPerDay      = $this->parseAmount($r['vda_per_day'] ?? null);
                $vdaPerMonth    = $this->parseAmount($r['vda_per_month'] ?? null);
                $hraPerMonth    = $this->parseAmount($r['hra_per_month'] ?? null);
                $totalPerDay    = $this->parseAmount($r['total_per_day'] ?? null);
                $totalPerMonth  = $this->parseAmount($r['total_per_month'] ?? null);

                // Derive missing values (day ↔ month, ×26)
                if ($basicPerMonth > 0 && $basicPerDay == 0) {
                    $basicPerDay = round($basicPerMonth / 26, 2);
                } elseif ($basicPerDay > 0 && $basicPerMonth == 0) {
                    $basicPerMonth = round($basicPerDay * 26, 2);
                }
                if ($vdaPerMonth > 0 && $vdaPerDay == 0) {
                    $vdaPerDay = round($vdaPerMonth / 26, 2);
                } elseif ($vdaPerDay > 0 && $vdaPerMonth == 0) {
                    $vdaPerMonth = round($vdaPerDay * 26, 2);
                }
                if ($totalPerMonth > 0 && $totalPerDay == 0) {
                    $totalPerDay = round($totalPerMonth / 26, 2);
                } elseif ($totalPerDay > 0 && $totalPerMonth == 0) {
                    $totalPerMonth = round($totalPerDay * 26, 2);
                }

                // Notification name
                $notificationName = trim($r['name'] ?? '');

                // Resolve zone (direct name, not FK). Some notifications use
                // `area` for the same concept (e.g. Arunachal Pradesh "Area I").
                $zone = $this->resolveZone($r['zone'] ?? '-');
                if ($zone === null) {
                    $zone = $this->resolveZone($r['area'] ?? '-');
                }

                if (!$effectiveDate) continue;

                // Build wage data map (Simpliance keys → values)
                $wageData = [
                    'basic_per_day'   => $basicPerDay,
                    'basic_per_month' => $basicPerMonth,
                    'vda_per_day'     => $vdaPerDay,
                    'vda_per_month'   => $vdaPerMonth,
                    'hra_per_month'   => $hraPerMonth,
                    'total_per_day'   => $totalPerDay,
                    'total_per_month' => $totalPerMonth,
                ];

                // Check for existing record (include zone in lookup)
                if ($zone) {
                    $existing = $this->db->fetchAll(
                        "SELECT id FROM minimum_wages
                         WHERE state_id = ? AND worker_category = ? AND effective_from = ? AND zone = ?
                         LIMIT 1",
                        [$state['id'], $workerCategory, $effectiveDate, $zone]
                    );
                } else {
                    $existing = $this->db->fetchAll(
                        "SELECT id FROM minimum_wages
                         WHERE state_id = ? AND worker_category = ? AND effective_from = ? AND zone IS NULL
                         LIMIT 1",
                        [$state['id'], $workerCategory, $effectiveDate]
                    );
                }

                if (!$dryRun) {
                    $existingId = !empty($existing) ? $existing[0]['id'] : null;
                    try {
                        $this->upsertWage(
                            $state['id'], $workerCategory, $effectiveDate,
                            $wageData, $version, $notificationName, $existingId, $zone
                        );
                    } catch (Exception $rowError) {
                        // One bad row (e.g. a legacy UNIQUE KEY that does not
                        // include `zone`) must not abandon the remaining rows of
                        // this state — that used to abort the whole state.
                        $result['records_skipped']++;
                        if (!$result['error_message']) {
                            $result['error_message'] = 'Row error: ' . mb_substr($rowError->getMessage(), 0, 200);
                        }
                        continue;
                    }
                    if ($existingId) {
                        $result['records_updated']++;
                    } else {
                        $result['records_added']++;
                    }
                } else {
                    if (!empty($existing)) {
                        $result['records_updated']++;
                    } else {
                        $result['records_added']++;
                    }
                }
            }

            // Nothing usable came out of this state → "partial", not "success".
            // Reporting success with 0 added / 0 updated is what made the button
            // look like it worked while the wage table stayed empty.
            if (($result['records_added'] + $result['records_updated']) === 0) {
                $result['status'] = 'partial';
                if (empty($unmapped) && !$result['error_message']) {
                    $result['error_message'] = 'Source returned ' . count($allRows)
                        . ' row(s) but none could be imported.';
                }
            } else {
                $result['status'] = 'success';
            }

            if (!empty($unmapped)) {
                $result['unmapped_categories'] = $unmapped;
                $labelList = [];
                foreach ($unmapped as $label => $n) {
                    $labelList[] = $label . ' (' . $n . ')';
                }
                $hint = 'No HRMS category for: ' . implode(', ', array_slice($labelList, 0, 4));
                if (count($labelList) > 4) $hint .= ' …';
                $result['error_message'] = $result['error_message']
                    ? $result['error_message'] . ' | ' . $hint
                    : $hint;
            }

            if ($fallbackMapped > 0) {
                $result['fallback_mapped'] = $fallbackMapped;
            }

            // Some rows landed but the state still has something to report
            // (unmapped categories, a row that failed, …) → partial, not success.
            if (!empty($result['error_message'])
                && ($result['records_added'] + $result['records_updated']) > 0) {
                $result['status'] = 'partial';
            }

            // Update last_scraped_at (silent fail if column missing)
            if (!$dryRun) {
                try {
                    $this->db->query("UPDATE states SET last_scraped_at = NOW() WHERE id = ?", [$state['id']]);
                } catch (Exception $e) {}
            }

        } catch (Exception $e) {
            $result['error_message'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $result;
    }

    /**
     * GET page — uses simple fetch, no cookies needed.
     */
    private function fetchPage($url) {
        return $this->fetchUrl($url);
    }

    /**
     * Detect which columns actually exist in minimum_wages table.
     * Caches result for the request lifetime.
     */
    private function detectColumns() {
        if ($this->mwColumns !== null) return $this->mwColumns;

        $rows = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wages'"
        );
        $this->mwColumns = array_column($rows, 'COLUMN_NAME');
        return $this->mwColumns;
    }

    /**
     * Resolve a Simpliance field to the actual DB column name.
     * Returns the first matching column that exists, or null.
     */
    private function resolveColumn($simplianceKey) {
        $candidates = self::COLUMN_MAP[$simplianceKey] ?? [$simplianceKey];
        $existing = $this->detectColumns();
        foreach ($candidates as $col) {
            if (in_array($col, $existing)) return $col;
        }
        return null;
    }

    /**
     * Build dynamic INSERT or UPDATE SQL using only columns that exist.
     * $data = ['basic_per_day' => 500, 'vda_per_day' => 60, ...]
     */
    private function upsertWage($stateId, $workerCategory, $effectiveDate, $data, $version, $notificationName, $existingId = null, $zone = null) {
        $existing = $this->detectColumns();

        // Build column→value map for columns that exist
        $setCols = [];
        $setVals = [];

        // Always-included fields
        if (in_array('state_id', $existing))        { $setCols[] = 'state_id';        $setVals[] = $stateId; }
        if (in_array('zone', $existing))            { $setCols[] = 'zone';            $setVals[] = $zone; }
        if (in_array('worker_category', $existing))  { $setCols[] = 'worker_category';  $setVals[] = $workerCategory; }
        if (in_array('effective_from', $existing))   { $setCols[] = 'effective_from';   $setVals[] = $effectiveDate; }
        if (in_array('is_active', $existing))        { $setCols[] = 'is_active';        $setVals[] = 1; }
        if (in_array('source', $existing))           { $setCols[] = 'source';           $setVals[] = 'Simpliance'; }
        if (in_array('version_id', $existing))       { $setCols[] = 'version_id';       $setVals[] = $version; }

        // Notification
        if ($notificationName && in_array('notification_number', $existing)) {
            $setCols[] = 'notification_number';
            $setVals[] = mb_substr($notificationName, 0, 100);
        }

        // Dynamic wage columns
        foreach ($data as $key => $val) {
            $col = $this->resolveColumn($key);
            if ($col && in_array($col, $existing)) {
                $setCols[] = $col;
                $setVals[] = $val;
            }
        }

        if (empty($setCols)) return false;

        if ($existingId) {
            // UPDATE
            if (in_array('updated_at', $existing)) {
                $setCols[] = 'updated_at';
                $setVals[] = date('Y-m-d H:i:s');
            }
            $setClause = implode(' = ?, ', $setCols) . ' = ?';
            $setVals[] = $existingId;
            $this->db->query("UPDATE minimum_wages SET $setClause WHERE id = ?", $setVals);
        } else {
            // INSERT
            $placeholders = implode(', ', array_fill(0, count($setCols), '?'));
            $colList = implode(', ', $setCols);
            $this->db->query("INSERT INTO minimum_wages ($colList) VALUES ($placeholders)", $setVals);
        }
        return true;
    }

    // ── List the states that would be synced (for chunked runs) ─────
    /**
     * Public wrapper around getSyncStates() so the browser can walk the list one
     * state per request instead of holding a single multi-minute connection open.
     *
     * @return array { success, states:[{state, slug}], count }
     */
    public function listStates($stateFilter = null) {
        $this->ensureSlugs();

        $states = [];
        foreach ($this->getSyncStates($stateFilter) as $s) {
            $states[] = [
                'state' => $s['state_name'],
                'slug'  => $s['simpliance_slug'],
            ];
        }

        $payload = [
            'success' => true,
            'states'  => $states,
            'count'   => count($states),
        ];
        if (empty($states)) {
            $payload['message'] = $stateFilter
                ? "No state found matching '$stateFilter' with a valid Simpliance slug."
                : 'No active states with slugs configured. Click "Auto-Setup Slugs" first.';
        }
        return $payload;
    }

    // ── Run full sync ───────────────────────────────────────────────
    /**
     * Sync one state, or a bounded slice of the state list.
     *
     * $stateFilter null means "all states", but the run is capped at
     * self::REQUEST_TIME_BUDGET seconds so the HTTP response always comes back
     * before the web server / proxy kills the connection. The caller resumes
     * with the slugs returned in `remaining`.
     *
     * @param string|null $stateFilter  slug, partial state name, or null for all
     * @param bool        $dryRun       preview only, nothing written
     * @param int|null    $timeBudget   seconds; null uses the class default
     */
    public function runSync($stateFilter = null, $dryRun = false, $timeBudget = null) {
        $this->ensureSlugs();
        $this->ensureZoneColumn();

        $states = $this->getSyncStates($stateFilter);

        if (empty($states)) {
            return [
                'success'        => false,
                'message'        => $stateFilter
                    ? "No state found matching '$stateFilter' with a valid Simpliance slug."
                    : 'No active states with slugs configured. Click "Auto-Setup Slugs" first.',
                'results'        => [],
                'total_added'    => 0,
                'total_updated'  => 0,
                'total_skipped'  => 0,
            ];
        }

        $results      = [];
        $totalAdded   = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $remaining    = [];

        $budget   = $timeBudget === null ? self::REQUEST_TIME_BUDGET : (int)$timeBudget;
        $started  = microtime(true);
        $isBulk   = ($stateFilter === null);
        $logReady = !$dryRun && $this->ensureSyncLog();

        foreach ($states as $index => $state) {
            // Time budget only applies to a bulk run — a single named state must
            // always be allowed to finish.
            if ($isBulk && $budget > 0 && (microtime(true) - $started) >= $budget) {
                for ($i = $index; $i < count($states); $i++) {
                    $remaining[] = $states[$i]['simpliance_slug'];
                }
                break;
            }

            $result = $this->fetchState($state, $dryRun);
            $results[]      = $result;
            $totalAdded   += $result['records_added'];
            $totalUpdated += $result['records_updated'];
            $totalSkipped += $result['records_skipped'];

            if ($logReady) {
                $this->writeSyncLog($result);
            }

            if (count($states) > 1) {
                // Small, randomised pause so the run does not look like a bot
                // sweep. Was 2–4s per state, which alone added ~90s to a
                // 36-state run; the chunked caller now paces itself instead.
                usleep(rand(400000, 900000));
            }
        }

        return [
            'success'       => true,
            'results'       => $results,
            'total_added'   => $totalAdded,
            'total_updated' => $totalUpdated,
            'total_skipped' => $totalSkipped,
            'dry_run'       => $dryRun,
            'done'          => empty($remaining),
            'remaining'     => $remaining,
            'timestamp'     => date('Y-m-d H:i:s'),
        ];
    }

    // ── Sync-log helpers ────────────────────────────────────────────

    /**
     * Make sure minimum_wage_sync_log exists and can record updates.
     *
     * The history table was created without `records_updated`, so a re-sync that
     * only refreshed existing rows logged "Added 0" and looked like nothing
     * happened. The column is added once, additively.
     *
     * @return bool true when the table is usable for logging
     */
    private function ensureSyncLog() {
        try {
            $tables = $this->db->fetchAll(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wage_sync_log'"
            );
            if (empty($tables)) return false;

            $cols = $this->db->fetchAll(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wage_sync_log'
                   AND COLUMN_NAME = 'records_updated'"
            );

            if (empty($cols)) {
                try {
                    $this->db->query(
                        "ALTER TABLE minimum_wage_sync_log
                         ADD COLUMN records_updated INT NOT NULL DEFAULT 0 AFTER records_added"
                    );
                    $this->logHasUpdated = true;
                } catch (Exception $e) {
                    // No ALTER privilege — keep logging without the update count
                    // rather than not logging at all.
                    $this->logHasUpdated = false;
                }
            } else {
                $this->logHasUpdated = true;
            }

            return true;
        } catch (Exception $e) {
            // Logging is best-effort — never let it break a sync.
            $this->logHasUpdated = false;
            return false;
        }
    }

    /**
     * Append one row to minimum_wage_sync_log.
     */
    private function writeSyncLog(array $result) {
        try {
            if ($this->logHasUpdated === null) {
                $cols = $this->db->fetchAll(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wage_sync_log'
                       AND COLUMN_NAME = 'records_updated'"
                );
                $this->logHasUpdated = !empty($cols);
            }

            if ($this->logHasUpdated) {
                $this->db->query(
                    "INSERT INTO minimum_wage_sync_log
                        (state, state_id, status, records_added, records_updated, records_skipped, error_message)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $result['state'], $result['state_id'], $result['status'],
                        $result['records_added'], $result['records_updated'],
                        $result['records_skipped'], $result['error_message'],
                    ]
                );
            } else {
                $this->db->query(
                    "INSERT INTO minimum_wage_sync_log
                        (state, state_id, status, records_added, records_skipped, error_message)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $result['state'], $result['state_id'], $result['status'],
                        $result['records_added'], $result['records_skipped'],
                        $result['error_message'],
                    ]
                );
            }
        } catch (Exception $e) {
            // Table shape may differ on older installs — skip logging, keep syncing.
        }
    }
}