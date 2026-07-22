<?php
/**
 * ESS API — Refresh Token Endpoint
 * POST: Accept an expired (but validly-signed) JWT and issue a fresh one.
 *
 * Security considerations:
 * - The old token's signature is still verified (prevents forged tokens).
 * - Expiry is tolerated up to REFRESH_WINDOW_SECONDS beyond exp.
 * - A new token is minted with the same claims but a fresh iat/exp.
 * - This endpoint requires the same X-API-KEY as all other ESS endpoints.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

// How long past expiry we still allow a refresh (grace period)
define('REFRESH_WINDOW_SECONDS', 86400); // 24 hours grace (token valid 4 days, refresh window extends 1 more day)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

try {
    validateApiKey();

    $input = getInput();
    // Read token from JSON body (backward compat) OR from the ess_jwt cookie
    // (Round 10 — staged HttpOnly cookie migration). The cookie takes priority
    // if present because it's the more secure path (HttpOnly = JS can't read it).
    $token = $_COOKIE['ess_jwt'] ?? ($input['token'] ?? '');

    if (empty($token)) {
        jsonOutput(['success' => false, 'error' => 'Token is required'], 400);
    }

    // Decode with allowExpired = true — we want to accept recently-expired tokens
    $payload = SimpleJWT::decode($token, true);

    if (!$payload) {
        jsonOutput(['success' => false, 'error' => 'Invalid or malformed token'], 401);
    }

    // Reject tokens that are too far past expiry (beyond grace window)
    if (isset($payload['exp']) && (time() - $payload['exp']) > REFRESH_WINDOW_SECONDS) {
        jsonOutput(['success' => false, 'error' => 'Token too old — please log in again'], 401);
    }

    // Require essential claims
    $employeeId = $payload['employee_id'] ?? null;
    $role       = $payload['role'] ?? null;
    $fullName   = $payload['full_name'] ?? '';

    if (!$employeeId) {
        jsonOutput(['success' => false, 'error' => 'Token missing required claims'], 401);
    }

    // Verify the employee still exists and is active
    $conn = getDbConnection();
    $stmt = $conn->prepare('SELECT id, status FROM employees WHERE id = ? AND status IN ("approved", "active") LIMIT 1');
    $stmt->bind_param('s', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonOutput(['success' => false, 'error' => 'Employee not found or inactive'], 401);
    }

    // Issue a fresh token
    $newToken = SimpleJWT::encode([
        'employee_id' => $employeeId,
        'role'        => $role,
        'full_name'   => $fullName,
    ], JWT_EXPIRY);

    // Refresh the HttpOnly cookie with the new token (Round 10)
    // SameSite=Lax + Secure=true (matches login.php)
    setcookie('ess_jwt', $newToken, [
        'expires'  => time() + JWT_EXPIRY,
        'path'     => '/',
        'secure'   => true, // always secure — the site is HTTPS-only
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    jsonOutput([
        'success' => true,
        'data'    => [
            'token' => $newToken,
        ],
    ]);

} catch (\Throwable $e) {
    error_log('[ESS refresh] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => 'Internal server error'], 500);
}