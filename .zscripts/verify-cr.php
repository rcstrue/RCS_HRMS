<?php
/**
 * Verification harness for hrms/modules/employee/change-requests.php
 * Drives the REAL page file against a stub $db (no MySQL available locally).
 * Usage: php verify-cr.php [notif|all|pending|approve|reject|reject-noreason]
 */
define('RCS_HRMS', true);
$scenario = $argv[1] ?? 'all';

require_once __DIR__ . '/../hrms/config/config.php';

$ERRORS = [];
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) use (&$ERRORS) {
    $ERRORS[] = sprintf('%s in %s:%d', $str, basename((string)$file), $line);
    return true;
});

class StubStatement
{
    public function __construct(private StubDb $db) {}
    public function execute($params = []) { $this->db->prepared[] = $params; return true; }
    public function fetch($mode = null) { return false; }
}

class StubDb
{
    public array $inserts  = [];
    public array $execs    = [];
    public array $updates  = [];
    public array $queries  = [];
    public array $prepared = [];
    public array $txLog    = [];
    public ?string $failOnTable = null;

    private array $requests;
    private array $employees = [
        7 => ['id' => 7, 'full_name' => 'Ramesh Kumar', 'employee_code' => 'GFLA110007', 'mobile_number' => '9000000007', 'email' => 'r@x.com', 'designation' => 'Guard'],
        8 => ['id' => 8, 'full_name' => 'Sita Devi',    'employee_code' => 'GFLA110008', 'mobile_number' => '9000000008', 'email' => 's@x.com', 'designation' => 'Cook'],
    ];

    public function __construct()
    {
        // Fixtures mirror real stored shapes:
        //  - old_value already carrying "/uploads/" (the previously-broken prefix case)
        //  - new_value bare relative path (what api/ess/upload.php returns)
        //  - reviewed_by = a USERS id (what the page always wrote)
        //  - one request whose employee row does not exist (fallback label path)
        $this->requests = [
            ['id' => 101, 'employee_id' => 7, 'field_name' => 'profile_pic_url', 'old_value' => '/uploads/profile/old.jpg', 'new_value' => 'profile/new.jpg', 'reason' => 'New photo', 'status' => 'pending',  'created_at' => '2026-09-01 10:00:00', 'reviewed_at' => null, 'reviewed_by' => null, 'rejection_reason' => null],
            ['id' => 102, 'employee_id' => 8, 'field_name' => 'account_number',  'old_value' => '123456', 'new_value' => '9998887776', 'reason' => 'Bank changed', 'status' => 'approved', 'created_at' => '2026-09-02 11:00:00', 'reviewed_at' => '2026-09-03 09:00:00', 'reviewed_by' => 5, 'rejection_reason' => null],
            ['id' => 103, 'employee_id' => 7, 'field_name' => 'aadhaar_front_url', 'old_value' => '/uploads/aadhaar/a.jpg', 'new_value' => '/uploads/aadhaar/b.jpg', 'reason' => 'Reupload', 'status' => 'rejected', 'created_at' => '2026-09-04 12:00:00', 'reviewed_at' => '2026-09-05 12:00:00', 'reviewed_by' => 5, 'rejection_reason' => 'Blurry scan'],
            ['id' => 104, 'employee_id' => 99, 'field_name' => 'bank_name', 'old_value' => 'HDFC', 'new_value' => 'SBI', 'reason' => null, 'status' => 'pending', 'created_at' => '2026-09-06 13:00:00', 'reviewed_at' => null, 'reviewed_by' => null, 'rejection_reason' => null],
        ];
    }

    private function filtered(?string $status): array
    {
        if ($status === null) return $this->requests;
        return array_values(array_filter($this->requests, fn($r) => $r['status'] === $status));
    }

    public function fetch($sql, $params = [])
    {
        $this->queries[] = $sql;
        if (stripos($sql, 'employee_change_requests') !== false) {
            foreach ($this->requests as $r) {
                if ($r['id'] === (int)($params['id'] ?? 0) && ($params['id'] ?? null) !== null) {
                    return strpos($sql, "status = 'pending'") === false || $r['status'] === 'pending' ? $r : false;
                }
            }
            return false;
        }
        if (stripos($sql, 'FROM users') !== false) {
            return ['first_name' => 'Shailesh', 'last_name' => 'Patel', 'username' => 'shailesh'];
        }
        if (stripos($sql, 'FROM employees') !== false) {
            $id = (int)($params['eid'] ?? $params['id'] ?? 0);
            return $this->employees[$id] ?? false;
        }
        return false;
    }

    public function fetchAll($sql, $params = [])
    {
        $this->queries[] = $sql;
        if (stripos($sql, 'FROM employee_change_requests') !== false) {
            return $this->filtered(isset($params['crstatus']) ? (string)$params['crstatus'] : null);
        }
        if (stripos($sql, 'FROM employees') !== false) return array_values($this->employees);
        return [];
    }

    public function fetchColumn($sql, $params = [])
    {
        $this->queries[] = $sql;
        foreach (['pending', 'approved', 'rejected'] as $s) {
            if (stripos($sql, "status = '{$s}'") !== false) return count($this->filtered($s));
        }
        return 0;
    }

    public function prepare($sql) { $this->queries[] = $sql; return new StubStatement($this); }
    public function insert($table, $data) { $this->inserts[] = ['table' => $table, 'data' => $data]; return 1; }
    public function update($t, $d, $w, $p = [])
    {
        if ($this->failOnTable === $t) {
            throw new Exception("simulated DB failure updating {$t}");
        }
        $this->updates[] = ['table' => $t, 'data' => $d, 'where_params' => $p];
        return 1;
    }
    public function exec($sql) { $this->execs[] = $sql; return 1; }

    // Transaction support — bulk approve wraps its batch (the real Database class
    // delegates these to PDO; here we only record that they were called).
    public function beginTransaction() { $this->txLog[] = 'begin'; return true; }
    public function commit() { $this->txLog[] = 'commit'; return true; }
    public function rollBack() { $this->txLog[] = 'rollback'; return true; }

    public function updatesFor(string $table): array
    {
        return array_values(array_filter($this->updates, fn($u) => $u['table'] === $table));
    }
    public function insertsFor(string $table): array
    {
        return array_values(array_filter($this->inserts, fn($i) => $i['table'] === $table));
    }
}

$db = new StubDb();

function report(string $scenario, array $checks, array $errors, string $html = ''): void
{
    $fail = 0;
    echo "── scenario: {$scenario} ──\n";
    foreach ($checks as $name => $pass) {
        printf("%-4s %s\n", $pass ? 'OK' : 'FAIL', $name);
        if (!$pass) $fail++;
    }
    if ($errors) {
        echo "PHP diagnostics:\n";
        foreach (array_slice($errors, 0, 8) as $e) echo "  - $e\n";
    }
    if ($fail && $html !== '') {
        echo "HTML snippet around failure:\n" . substr($html, 0, 700) . "\n";
    }
    echo $fail === 0 ? "RESULT: ALL PASS\n" : "RESULT: {$fail} FAILURE(S)\n";
}

// ── Scenario 1: notification helper (fix #1) ────────────────────────────────
if ($scenario === 'notif') {
    require __DIR__ . '/../hrms/modules/employee/change-requests.php';
    sendInAppNotification(7, 'Title X', 'Message Y', 'success', '/profile/change-requests');
    $i = $db->inserts[0] ?? null;
    report('notif (fix #1: notification insert)', [
        'insert() called once on ess_notifications' => count($db->inserts) === 1 && ($i['table'] ?? '') === 'ess_notifications',
        'employee_id bound as string "7"'           => ($i['data']['employee_id'] ?? null) === '7',
        'title/message/type bound'                  => ($i['data']['title'] ?? '') === 'Title X' && ($i['data']['message'] ?? '') === 'Message Y' && ($i['data']['type'] ?? '') === 'success',
        'is_read explicitly 0'                      => ($i['data']['is_read'] ?? null) === 0,
        'created_at set'                            => !empty($i['data']['created_at']),
        'raw exec() never used'                     => $db->execs === [],
    ], $ERRORS);
    exit(0);
}

// ── POST scenarios ──────────────────────────────────────────────────────────
if (in_array($scenario, ['approve', 'reject', 'reject-noreason', 'bulk', 'bulk-fail', 'csrf-bad'], true)) {
    $_SERVER['REQUEST_METHOD']  = 'POST';
    $_SERVER['REQUEST_URI']     = '/hrms/index.php?page=employee/change-requests';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SESSION['user_id']        = 5;
    $_SESSION['role_code']      = 'admin';
    $_SESSION['csrf_token']     = 'testtoken';
    $_POST = ['csrf_token' => 'testtoken'];

    if ($scenario === 'approve') {
        $_POST['action'] = 'approve';
        $_POST['id']     = 101;   // pending, profile_pic_url, employee 7
    } elseif ($scenario === 'reject') {
        $_POST['action'] = 'reject';
        $_POST['id']     = 104;   // pending, bank_name, employee 99 (missing row -> fallback)
        $_POST['rejection_reason'] = 'Wrong details';
    } elseif ($scenario === 'bulk' || $scenario === 'bulk-fail') {
        $_POST['action'] = 'bulk_approve';
        $_POST['selected_ids'] = ['101', '104'];   // both pending
        if ($scenario === 'bulk-fail') {
            // Inject a failure on the request-row update to exercise rollback
            $db->failOnTable = 'employee_change_requests';
        }
    } elseif ($scenario === 'csrf-bad') {
        $_POST['action'] = 'approve';
        $_POST['id']     = 101;
        $_POST['csrf_token'] = 'wrong-token';
    } else {
        $_POST['action'] = 'reject';
        $_POST['id']     = 104;
        $_POST['rejection_reason'] = '   ';  // whitespace only
    }

    ob_start();
    register_shutdown_function(function () use ($scenario, $db, &$ERRORS) {
        $html = ob_get_clean();

        if ($scenario === 'approve') {
            $emp   = $db->updatesFor('employees');
            $cr    = $db->updatesFor('employee_change_requests');
            $notes = $db->insertsFor('ess_notifications');
            report('approve (POST, end-to-end)', [
                'employee record updated with new value'   => count($emp) === 1 && ($emp[0]['data']['profile_pic_url'] ?? null) === 'profile/new.jpg',
                'employee update targets right row'        => ($emp[0]['where_params']['id'] ?? null) === 7,
                'request marked approved + reviewed_by'    => count($cr) === 1 && ($cr[0]['data']['status'] ?? '') === 'approved' && ($cr[0]['data']['reviewed_by'] ?? null) === 5,
                'reviewed_at stamped'                      => !empty($cr[0]['data']['reviewed_at']),
                'in-app notification queued (fix #1)'      => count($notes) === 1 && ($notes[0]['data']['employee_id'] ?? null) === '7',
                'notification names the field'             => stripos($notes[0]['data']['title'] ?? '', 'Profile pic url') !== false,
                'audit log written (logActivity)'          => (bool)array_filter($db->queries, fn($q) => stripos($q, 'INSERT INTO audit_log') !== false),
                'success flash set'                        => ($_SESSION['flash']['type'] ?? '') === 'success',
                'raw exec() never used'                    => $db->execs === [],
            ], $ERRORS, $html);
        } elseif ($scenario === 'reject') {
            $emp   = $db->updatesFor('employees');
            $cr    = $db->updatesFor('employee_change_requests');
            $notes = $db->insertsFor('ess_notifications');
            report('reject (POST, end-to-end)', [
                'employee record untouched by reject'      => count($emp) === 0,
                'request marked rejected + reason stored'  => count($cr) === 1 && ($cr[0]['data']['status'] ?? '') === 'rejected' && ($cr[0]['data']['rejection_reason'] ?? '') === 'Wrong details',
                'reviewed_by recorded'                     => ($cr[0]['data']['reviewed_by'] ?? null) === 5,
                'rejection notification queued (fix #1)'   => count($notes) === 1 && ($notes[0]['data']['employee_id'] ?? null) === '99',
                'notification carries the reason'          => stripos($notes[0]['data']['message'] ?? '', 'Wrong details') !== false,
                'audit log written'                        => (bool)array_filter($db->queries, fn($q) => stripos($q, 'INSERT INTO audit_log') !== false),
                'success flash set'                        => ($_SESSION['flash']['type'] ?? '') === 'success',
                'raw exec() never used'                    => $db->execs === [],
            ], $ERRORS, $html);
        } elseif ($scenario === 'bulk') {
            $emp   = $db->updatesFor('employees');
            $cr    = $db->updatesFor('employee_change_requests');
            $notes = $db->insertsFor('ess_notifications');
            $audits = array_filter($db->queries, fn($q) => stripos($q, 'INSERT INTO audit_log') !== false);
            report('bulk approve (POST) — fix #3', [
                'both employee records updated'          => count($emp) === 2,
                'profile photo applied to employee 7'    => (bool)array_filter($emp, fn($u) => ($u['data']['profile_pic_url'] ?? null) === 'profile/new.jpg'),
                'bank name applied'                      => (bool)array_filter($emp, fn($u) => ($u['data']['bank_name'] ?? null) === 'SBI'),
                'both requests marked approved'          => count($cr) === 2 && !array_filter($cr, fn($u) => ($u['data']['status'] ?? '') !== 'approved'),
                'notifications sent to both employees'   => count($notes) === 2 && count(array_filter($notes, fn($n) => ($n['data']['type'] ?? '') === 'success')) === 2,
                'notifications name the fields'          => count(array_filter($notes, fn($n) => stripos($n['data']['title'] ?? '', 'Profile pic url') !== false || stripos($n['data']['title'] ?? '', 'Bank name') !== false)) === 2,
                'audit rows written for both'            => count($audits) === 2,
                'batch wrapped in a transaction'         => $db->txLog === ['begin', 'commit'],
                'success flash set'                      => ($_SESSION['flash']['type'] ?? '') === 'success',
                'raw exec() never used'                  => $db->execs === [],
            ], $ERRORS, $html);
        } elseif ($scenario === 'bulk-fail') {
            report('bulk approve rollback (injected failure)', [
                'batch rolled back'                      => $db->txLog === ['begin', 'rollback'],
                'commit never called'                    => !in_array('commit', $db->txLog, true),
                'error flash set'                        => ($_SESSION['flash']['type'] ?? '') === 'error',
            ], $ERRORS, $html);
        } elseif ($scenario === 'csrf-bad') {
            report('CSRF rejection (POST with bad token)', [
                'no database writes at all'              => $db->updates === [] && $db->inserts === [] && $db->execs === [],
                'error flash set'                        => ($_SESSION['flash']['type'] ?? '') === 'error',
            ], $ERRORS, $html);
        } else {
            report('reject without reason (guard)', [
                'no database writes at all'                => $db->updates === [] && $db->inserts === [] && $db->execs === [],
                'danger flash set (reason required)'       => ($_SESSION['flash']['type'] ?? '') === 'danger',
            ], $ERRORS, $html);
        }
    });
    require __DIR__ . '/../hrms/modules/employee/change-requests.php';
    exit(0);
}

// ── GET render scenarios (fixes #2, #4, #7) ─────────────────────────────────
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/hrms/index.php?page=employee/change-requests';
$_SESSION['user_id']       = 5;
$_SESSION['role_code']     = 'admin';
$_GET = ['page' => 'employee/change-requests'];
if ($scenario === 'pending') $_GET['status'] = 'pending';

ob_start();
require __DIR__ . '/../hrms/modules/employee/change-requests.php';
$html = ob_get_clean();

$expectStatus = $scenario === 'pending' ? 'pending' : 'all';
$pendingOnly  = $expectStatus === 'pending';
$usedUsers = (bool)array_filter($db->queries, fn($q) => stripos($q, 'SELECT first_name, last_name, username FROM users') !== false);
$usedEmployeesForReviewer = (bool)array_filter($db->queries, fn($q) => preg_match('/FROM employees WHERE id = :rid/i', $q));

report("render ({$scenario})", [
    'no PHP fatal/warning/notice in output'   => !preg_match('/Fatal error|Warning:|Deprecated:|Notice:/', $html),
    'no /uploads//uploads/ double prefix'     => strpos($html, '/uploads//uploads/') === false,
    'prefixed old_value -> single prefix'     => !$pendingOnly || strpos($html, 'src="/uploads/profile/old.jpg"') !== false,
    'bare new_value -> prefixed once'         => !$pendingOnly || strpos($html, 'src="/uploads/profile/new.jpg"') !== false,
    'status filter kept in search form'       => strpos($html, 'name="status" value="' . $expectStatus . '"') !== false,
    'csrf token in both HTML forms'           => substr_count($html, 'name="csrf_token"') === 2,
    'csrf token injected into JS form'        => strpos($html, "createInput('csrf_token'") !== false,
    'reviewer resolved via users table'       => $pendingOnly ? !$usedUsers && !$usedEmployeesForReviewer : $usedUsers && !$usedEmployeesForReviewer,
    'reviewer name rendered for reviewed rows'=> $pendingOnly ? true : substr_count($html, 'Shailesh Patel') === 2,
    'pending rows present'                    => strpos($html, 'Ramesh Kumar') !== false,
    'missing-employee fallback label'         => !$pendingOnly || strpos($html, 'Employee #99') !== false,
    'approved rows excluded when filtered'    => !$pendingOnly || strpos($html, 'Sita Devi') === false,
    'bulk approve control present'            => strpos($html, 'Approve Selected') !== false,
    'raw exec() never used'                   => $db->execs === [],
], $ERRORS, $html);
