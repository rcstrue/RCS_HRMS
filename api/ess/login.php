<?php
/**
 * ESS API — Login Endpoint
 * POST: Validate mobile + PIN, return JWT token and employee data
 *
 * PIN Logic (SECURITY — Round 3 hardening):
 *   1. Check ess_employee_cache.pin — if set, validate against it (custom PIN).
 *      Plaintext PINs are auto-upgraded to bcrypt on successful verify.
 *   2. If NO custom PIN is set, login is REFUSED with a clear message directing
 *      the employee to contact HR to set a PIN. (Previously the 4-digit birth
 *      year was accepted as a fallback — that made every employee whose PIN
 *      wasn't set brute-forceable in ≤100 tries. Birth-year fallback is REMOVED.)
 *   3. Custom PIN is saved ONLY in ess_employee_cache.pin (NOT in employees table).
 *
 * The force-PIN-change flow (has_custom_pin=false) is preserved for the admin-
 * seeded PIN case where HR sets a default PIN that the employee must change.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(array('success' => false, 'error' => 'Method not allowed. Use POST.'), 405);
}

try {
    validateApiKey();

    $input = getInput();

    // Accept both camelCase and snake_case field names
    $mobile = trim($input['mobile_number'] ?? $input['mobileNumber'] ?? '');
    $pin = trim($input['pin'] ?? '');

    if (empty($mobile)) {
        jsonOutput(array('success' => false, 'error' => 'Mobile number is required'), 400);
        return;
    }
    if (empty($pin) || !preg_match('/^\d{4,10}$/', $pin)) {
        jsonOutput(array('success' => false, 'error' => 'Invalid PIN format. Use 4-10 digits.'), 400);
        return;
    }

    // ─── Rate Limiting (DB-backed) ────────────────────────────────────────
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateId = 'ess_' . $mobile . '_' . $ip;

    // ─── Database Lookup — JOIN units for city ───────────────────────────
    $conn = getDbConnection();

    // Check rate limit / lockout (DB-backed, same table as HRMS admin)
    $lockMsg = _checkRateLimit($conn, $rateId);
    if ($lockMsg) {
        jsonOutput(array('success' => false, 'error' => $lockMsg), 429);
        return;
    }

    $stmt = $conn->prepare("
        SELECT e.id, e.full_name, e.mobile_number, e.email, e.designation, e.department,
               e.state AS emp_state, e.date_of_joining, e.employee_code, e.employee_role,
               e.app_role, e.worker_category, e.profile_pic_url, e.date_of_birth,
               c.name AS client_name, c.client_code,
               u.name AS unit_name, u.city AS unit_city, u.state AS unit_state,
               e.client_id, e.unit_id
        FROM employees e
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE e.mobile_number = ? AND e.status IN ('approved', 'active')
    ");
    if (!$stmt) {
        jsonOutput(array('success' => false, 'error' => 'Database query error'), 500);
        return;
    }
    $stmt->bind_param('s', $mobile);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    $stmt->close();

    if (!$employee) {
        _recordFailedLogin($conn, $rateId);
        jsonOutput(array('success' => false, 'error' => 'Invalid mobile number or PIN'), 401);
        return;
    }

    // ─── PIN Validation ───────────────────────────────────────────────────
    $employeeId = (string)$employee['id'];
    $validPin = false;
    $hasCustomPin = false;

    // Step 1: Check ess_employee_cache for custom PIN
    $cacheStmt = $conn->prepare('SELECT pin FROM ess_employee_cache WHERE employee_id = ?');
    if ($cacheStmt) {
        $cacheStmt->bind_param('s', $employeeId);
        $cacheStmt->execute();
        $cacheRow = $cacheStmt->get_result()->fetch_assoc();
        $cacheStmt->close();

        if ($cacheRow && !empty($cacheRow['pin'])) {
            // Custom PIN exists in cache — validate against it
            $hasCustomPin = true;
            $storedPin = $cacheRow['pin'];
            // verifyPin() handles both legacy plaintext and bcrypt hash transparently.
            // If plaintext matches, it auto-upgrades to bcrypt.
            $validPin = verifyPin($pin, $storedPin, $conn, $employeeId);
        }
    }

    // Step 2: SECURITY — birth-year fallback REMOVED (Round 3).
    // Previously, if no custom PIN was set, the 4-digit birth year was accepted
    // as a valid PIN. That made every employee without a custom PIN brute-forceable
    // in ≤100 tries. Now: if no custom PIN is set, refuse login with a clear
    // message. HR must seed a PIN (via admin or the pin.php endpoint) for
    // first-time users. The has_custom_pin=false flag is still returned when HR
    // seeds a default PIN, so the force-PIN-change flow still works.
    if (!$validPin && !$hasCustomPin) {
        _recordFailedLogin($conn, $rateId);
        jsonOutput(array(
            'success' => false,
            'error'   => 'No PIN set for this account. Please contact HR to set your PIN.',
            'code'    => 'PIN_NOT_SET',
        ), 401);
        return;
    }

    if (!$validPin) {
        _recordFailedLogin($conn, $rateId);
        jsonOutput(array('success' => false, 'error' => 'Invalid mobile number or PIN'), 401);
        return;
    }

    // Use centralized role determination (app_role as primary source)
    $role = determineEssRole($employee);

    // ─── Update Employee Cache (WITHOUT pin — pin is set only by change-pin endpoint) ──
    $unitName = $employee['unit_name'] ?? '';
    $clientName = $employee['client_name'] ?? '';
    $city = isset($employee['unit_city']) ? $employee['unit_city'] : '';
    $state = isset($employee['unit_state']) ? $employee['unit_state'] : '';
    $profilePicUrl = $employee['profile_pic_url'] ?? '';
    $designation = $employee['designation'] ?? '';
    $employeeCode = (string)($employee['employee_code'] ?? '');
    $clientId = (int)($employee['client_id'] ?? 0);
    $unitId = (int)($employee['unit_id'] ?? 0);

    // Extract all values into local variables first
    $fullName = $employee['full_name'] ?? '';
    $mobileNumber = $employee['mobile_number'] ?? '';

    $cacheStmt = $conn->prepare('
        INSERT INTO ess_employee_cache (
            employee_id, role, unit_id, unit_name, city, state,
            client_name, client_id, full_name, mobile_number,
            designation, profile_pic_url, employee_code
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            role = VALUES(role),
            unit_id = VALUES(unit_id),
            unit_name = VALUES(unit_name),
            city = VALUES(city),
            state = VALUES(state),
            client_name = VALUES(client_name),
            client_id = VALUES(client_id),
            full_name = VALUES(full_name),
            mobile_number = VALUES(mobile_number),
            designation = VALUES(designation),
            profile_pic_url = VALUES(profile_pic_url),
            employee_code = VALUES(employee_code)
    ');
    if (!$cacheStmt) {
        jsonOutput(array('success' => false, 'error' => 'Failed to update cache'), 500);
        return;
    }

    // Use bindDynamicParams helper (call_user_func_array with references)
    bindDynamicParams($cacheStmt, 'ssisssissssss', array(
        $employeeId, $role, $unitId, $unitName, $city, $state,
        $clientName, $clientId, $fullName, $mobileNumber,
        $designation, $profilePicUrl, $employeeCode
    ));
    $cacheStmt->execute();
    $cacheStmt->close();

    // ─── Generate JWT ─────────────────────────────────────────────────────
    $token = SimpleJWT::encode(array(
        'employee_id' => $employeeId,
        'role'        => $role,
        'full_name'   => $employee['full_name'],
    ), JWT_EXPIRY);

    _clearFailedLogins($conn, $rateId);

    // ─── Set JWT as HttpOnly cookie (Round 10 — staged migration) ──────
    // The token is ALSO returned in the JSON response for backward compat
    // with the SPA's existing localStorage-based auth. The cookie enables a
    // future migration to cookie-only auth (removing the XSS-vulnerable
    // localStorage token). The cookie is HttpOnly (JS can't read it), Secure
    // (HTTPS only), and SameSite=Strict (not sent on cross-site requests).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('ess_jwt', $token, [
        'expires'  => time() + JWT_EXPIRY,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    jsonOutput(array(
        'success' => true,
        'data' => array(
            'employee' => array(
                'employee_id' => $employeeId,
                'id' => (int)$employee['id'],
                'full_name' => $employee['full_name'],
                'mobile_number' => $employee['mobile_number'],
                'email' => isset($employee['email']) ? $employee['email'] : '',
                'designation' => $designation,
                'department' => isset($employee['department']) ? $employee['department'] : '',
                'employee_code' => $employeeCode,
                'role' => $role,
                'employee_role' => isset($employee['employee_role']) ? $employee['employee_role'] : '',
                'worker_category' => isset($employee['worker_category']) ? $employee['worker_category'] : '',
                'app_role' => isset($employee['app_role']) ? $employee['app_role'] : '',
                'profile_pic_url' => $profilePicUrl,
                'city' => $city,
                'state' => $state,
                'unit_name' => $unitName,
                'client_name' => $clientName,
                'client_id' => (int)$employee['client_id'],
                'unit_id' => (int)$employee['unit_id'],
                'date_of_joining' => isset($employee['date_of_joining']) ? $employee['date_of_joining'] : '',
            ),
            'role' => $role,
            'has_custom_pin' => $hasCustomPin,
            'token' => $token
        )
    ));

} catch (\Throwable $e) {
    error_log('[ESS login] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(array('success' => false, 'error' => 'Internal server error. Please try again later.'), 500);
}

function _ensureLoginAttemptsTable(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(255) NOT NULL,
            ip           VARCHAR(45)  NOT NULL,
            attempts     INT          NOT NULL DEFAULT 0,
            last_attempt DATETIME     NOT NULL,
            locked_until DATETIME     NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_ip (username, ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function _calcLockout(int $attempts): ?string
{
    if ($attempts >= 20) return date('Y-m-d H:i:s', strtotime('+24 hours'));
    if ($attempts >= 10) return date('Y-m-d H:i:s', strtotime('+1 hour'));
    if ($attempts >= 5)  return date('Y-m-d H:i:s', strtotime('+15 minutes'));
    return null;
}

function _checkRateLimit(mysqli $conn, string $rateId): ?string
{
    _ensureLoginAttemptsTable($conn);
    $stmt = $conn->prepare('SELECT attempts, locked_until FROM login_attempts WHERE username = ?');
    $stmt->bind_param('s', $rateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

    if ($row['locked_until'] !== null) {
        $now = new \DateTime('now');
        $lock = new \DateTime($row['locked_until']);
        if ($now < $lock) {
            $mins = ($lock->getTimestamp() - $now->getTimestamp()) / 60;
            $hours = (int)floor($mins / 60);
            $m = (int)$mins;
            if ($hours >= 1) {
                return "Too many failed attempts. Try again in {$hours} hour" . ($hours > 1 ? 's' : '') . ".";
            }
            return "Too many failed attempts. Try again in {$m} minute" . ($m !== 1 ? 's' : '') . ".";
        }
        _clearFailedLogins($conn, $rateId);
    }
    return null;
}

function _recordFailedLogin(mysqli $conn, string $rateId): void
{
    _ensureLoginAttemptsTable($conn);
    $stmt = $conn->prepare('SELECT attempts FROM login_attempts WHERE username = ?');
    $stmt->bind_param('s', $rateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $now = date('Y-m-d H:i:s');
    if ($row) {
        $attempts = (int)$row['attempts'] + 1;
        $lockedUntil = _calcLockout($attempts);
        $stmt = $conn->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = ?, locked_until = ? WHERE username = ?');
        $stmt->bind_param('isss', $attempts, $now, $lockedUntil, $rateId);
    } else {
        $stmt = $conn->prepare('INSERT INTO login_attempts (username, ip, attempts, last_attempt, locked_until) VALUES (?, ?, 1, ?, NULL)');
        $emptyIp = '';
        $stmt->bind_param('sss', $rateId, $emptyIp, $now);
    }
    $stmt->execute();
    $stmt->close();
}

function _clearFailedLogins(mysqli $conn, string $rateId): void
{
    _ensureLoginAttemptsTable($conn);
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE username = ?');
    $stmt->bind_param('s', $rateId);
    $stmt->execute();
    $stmt->close();
}
