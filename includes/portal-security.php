<?php
/**
 * RCS HRMS Pro — Employee Portal Security Helpers
 *
 * Provides lockout / rate-limiting + PIN verification for the employee portal
 * login. Mirrors the admin login lockout pattern in class.auth.php but is
 * standalone (the portal login does not use the Auth class — it uses a
 * mobile/employee-code + PIN flow).
 *
 * Lockout schedule (same as admin):
 *   5 attempts  → 15 minutes
 *   10 attempts → 1 hour
 *   20 attempts → 24 hours
 *
 * The login_attempts table is shared with the admin login (created by
 * class.auth.php::_ensureLoginAttemptsTable). We key portal attempts by a
 * 'portal:<identifier>' prefix so admin and portal lockouts are independent.
 */

if (!defined('RCS_HRMS')) {
    die('Direct access not allowed');
}

/**
 * Build the lockout key for the portal. Uses mobile OR employee code, prefixed
 * so it cannot collide with admin usernames.
 */
function portal_lockout_key(string $mobile, string $employeeCode): string
{
    $ident = $mobile !== '' ? $mobile : $employeeCode;
    return 'portal:' . $ident;
}

/**
 * Ensure the login_attempts table exists (mirrors class.auth.php). Safe to call
 * even if the admin Auth class already created it.
 */
function portal_ensure_lockout_table(Database $db): void
{
    static $created = false;
    if ($created) return;
    try {
        $db->query("
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
    } catch (Exception $e) {
        // Table may already exist — ignore.
    }
    $created = true;
}

/**
 * Check whether the portal login for this identifier+IP is currently locked.
 * Returns an error message string if locked, or null if the attempt is allowed.
 */
function portal_check_lockout(Database $db, string $mobile, string $employeeCode, string $ip): ?string
{
    portal_ensure_lockout_table($db);
    $key = portal_lockout_key($mobile, $employeeCode);

    $row = $db->fetch(
        "SELECT attempts, locked_until FROM login_attempts WHERE username = :u AND ip = :i",
        ['u' => $key, 'i' => $ip]
    );

    if (!$row) {
        return null;
    }

    if ($row['locked_until'] !== null) {
        $now  = new DateTime('now');
        $lock = new DateTime($row['locked_until']);
        if ($now < $lock) {
            $diff  = $now->diff($lock);
            $mins  = $diff->i + ($diff->h * 60) + ($diff->d * 1440);
            $hours = (int) floor($mins / 60);
            if ($hours >= 1) {
                return "Account temporarily locked due to too many failed attempts. Try again in {$hours} hour" . ($hours > 1 ? 's' : '') . ".";
            }
            return "Account temporarily locked due to too many failed attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . ".";
        }
        // Lockout expired — reset.
        portal_clear_lockout($db, $mobile, $employeeCode, $ip);
    }

    return null;
}

/**
 * Calculate the lockout datetime based on attempt count.
 * Returns null if no lockout applies, or a DATETIME string.
 */
function portal_calc_lockout(int $attempts): ?string
{
    if ($attempts >= 20) {
        return date('Y-m-d H:i:s', strtotime('+24 hours'));
    }
    if ($attempts >= 10) {
        return date('Y-m-d H:i:s', strtotime('+1 hour'));
    }
    if ($attempts >= 5) {
        return date('Y-m-d H:i:s', strtotime('+15 minutes'));
    }
    return null;
}

/**
 * Record a failed portal login attempt. Increments the counter and applies a
 * lockout window when thresholds are crossed.
 */
function portal_record_failed_login(Database $db, string $mobile, string $employeeCode, string $ip): void
{
    portal_ensure_lockout_table($db);
    $key = portal_lockout_key($mobile, $employeeCode);

    $row = $db->fetch(
        "SELECT attempts FROM login_attempts WHERE username = :u AND ip = :i",
        ['u' => $key, 'i' => $ip]
    );

    $now = date('Y-m-d H:i:s');

    if ($row) {
        $attempts     = (int) $row['attempts'] + 1;
        $lockedUntil  = portal_calc_lockout($attempts);
        $db->query(
            "UPDATE login_attempts SET attempts = :a, last_attempt = :t, locked_until = :l WHERE username = :u AND ip = :i",
            ['a' => $attempts, 't' => $now, 'l' => $lockedUntil, 'u' => $key, 'i' => $ip]
        );
    } else {
        $db->query(
            "INSERT INTO login_attempts (username, ip, attempts, last_attempt, locked_until) VALUES (:u, :i, 1, :t, NULL)",
            ['u' => $key, 'i' => $ip, 't' => $now]
        );
    }
}

/**
 * Clear the failed-login counter on a successful portal login.
 */
function portal_clear_lockout(Database $db, string $mobile, string $employeeCode, string $ip): void
{
    portal_ensure_lockout_table($db);
    $key = portal_lockout_key($mobile, $employeeCode);
    $db->query(
        "DELETE FROM login_attempts WHERE username = :u AND ip = :i",
        ['u' => $key, 'i' => $ip]
    );
}

/**
 * Verify a PIN against the stored value in ess_employee_cache.
 *
 * SECURITY: Previously the portal login supported a birth-year fallback — if no
 * PIN was stored, the 4-digit birth year was accepted. That made every employee
 * whose PIN wasn't set brute-forceable in ≤100 tries. Birth-year fallback is now
 * REMOVED. If no PIN is stored, the login is rejected and the employee must
 * contact HR to set a PIN (via the ESS app or admin).
 *
 * Plaintext PINs are still verified (for legacy rows) but are upgraded to bcrypt
 * on successful verification so the plaintext is purged over time.
 */
function portal_verify_pin(Database $db, int $employeeId, string $pin): bool
{
    $cache = $db->fetch(
        "SELECT pin FROM ess_employee_cache WHERE employee_id = ?",
        [$employeeId]
    );
    $storedPin = $cache['pin'] ?? null;

    if (!$storedPin) {
        // No PIN set — refuse. (Previously this fell back to birth year.)
        return false;
    }

    $isHashed = (strlen($storedPin) >= 60 && strpos($storedPin, '$2y$') === 0);

    if ($isHashed) {
        return password_verify($pin, $storedPin);
    }

    // Legacy plaintext PIN — verify then upgrade to bcrypt.
    if (hash_equals($storedPin, $pin)) {
        $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash) {
            try {
                $db->query(
                    "UPDATE ess_employee_cache SET pin = :pin WHERE employee_id = :id",
                    ['pin' => $hash, 'id' => (string) $employeeId]
                );
            } catch (Exception $e) {
                // Upgrade failure is non-fatal — the PIN was still valid.
            }
        }
        return true;
    }

    return false;
}
