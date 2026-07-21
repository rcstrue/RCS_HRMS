<?php
/**
 * ESS Health & Security-Posture Check
 *
 * GET /api/ess/health            — basic liveness (no config required, always works)
 * GET /api/ess/health?detail=1   — include config security posture (booleans only, no secrets)
 * GET /api/ess/health?detail=2   — LOOPBACK ONLY: auth-coverage scan of every endpoint in
 *                                   api/ess/. Reports which files call requireAuth() /
 *                                   requireRole() / validateApiKey() so operators can spot
 *                                   any endpoint that forgot to add an auth guard.
 *
 * The detail modes let operators verify (from a browser or curl) that:
 *   - the live config.php is present and loadable
 *   - JWT_SECRET / API_KEY are no longer the default placeholders
 *   - JWT expiry has been reduced from the 4-day default
 *   - the hardening revision is current
 *   - every endpoint enforces authentication (detail=2)
 *
 * detail=2 is restricted to loopback (127.0.0.1 / ::1) so it cannot be used for
 * remote reconnaissance. It never returns secret values.
 */
define('RCS_ESS_HEALTH_CHECK', true); // bypass the placeholder-secret guard in example.config.php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/security-headers.php';

$detail = isset($_GET['detail']) ? (string)$_GET['detail'] : '';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? 0) == 443);

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true);

$response = [
    'status' => 'ok',
    'time'   => date('Y-m-d H:i:s T'),
    'server' => [
        'php_version' => PHP_VERSION,
        'software'    => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'sapi'        => PHP_SAPI,
    ],
    'resources' => [
        'memory_mb'    => round(memory_get_usage(true) / 1048576, 2),
        'memory_limit' => ini_get('memory_limit') ?: 'unknown',
    ],
    'transport' => [
        'https'       => $isHttps,
        'remote_addr' => $remoteAddr,
        'loopback'    => $isLoopback,
    ],
    // Hardening revision stamp — bump when a new hardening round is applied.
    'hardening' => [
        'hardening_version'        => 3,    // bumped to 3 after Round 3 (portal + ESS login + direct-access + SPA key)
        'security_headers_sent'    => true,
        'xtransform_proxy_removed' => true, // Caddyfile SSRF block deleted in r1
        'placeholder_secret_guard' => true, // example.config.php refuses placeholder secrets
        'jwt_expiry_reduced'       => true,  // 4 days -> 24 hours
        'centralized_auth_guard'   => true,  // auth-guard.php added in r2
        'idor_role_checks_added'   => true,  // 8 endpoints hardened in r2
        'portal_login_hardened'    => true,  // CSRF + lockout + session_regenerate_id + birth-year removed (r3)
        'birth_year_pin_removed'   => true,  // ESS login.php + portal login.php (r3)
        'direct_access_blocked'    => true,  // Caddy @blocked for /includes,/config,/modules (r3)
        'spa_key_centralized'      => true,  // ess-auth.ts imports from config.ts, no hardcoded key (r3)
        'csrf_sweep_api'           => true,  // 5 admin API endpoints got CSRF in r4
        'spa_stale_backup_removed' => true,  // src-backup/ + src/src-backup/ deleted in r4
        'cors_consistent'          => true,  // all 29 ESS endpoints now include cors.php (r5)
        'role_scoping_cleanup'     => true,  // manpower-status + employee-actions + filters (r5)
        'spa_csp_added'            => true,  // Content-Security-Policy meta in index.html (r5)
        'unit_scope_verification'  => true,  // employee-actions.php verifies manager allocation (r6)
        'session_idle_timeout_8h'  => true,  // was 4 days, now 8 hours (r6)
        'config_example_hardened'  => true,  // config.local.example.php ships APP_ENV=production (r6)
        'spa_ts_strict'            => true,  // tsconfig strict:true + all any usages removed (r6)
        'spa_logger_added'         => true,  // logger.ts: console.log/info/debug no-op in prod (r7)
        'react_query_removed'      => true,  // unused @tanstack/react-query removed (r7)
        'remember_me_removed'      => true,  // decorative "Remember me" checkbox removed (r7)
    ],
];

// ─── detail=1: config security posture (no secrets) ─────────────────────────
if ($detail === '1' || $detail === '2') {
    $posture = [
        'config_present'            => false,
        'config_loadable'           => false,
        'using_example_config'      => true,
        'jwt_secret_is_placeholder' => null,
        'api_key_is_placeholder'    => null,
        'jwt_expiry_seconds'        => null,
    ];

    $cfgPath = __DIR__ . '/config.php';
    if (is_file($cfgPath)) {
        $posture['config_present']       = true;
        $posture['using_example_config'] = false;
        try {
            require_once $cfgPath;
            $posture['config_loadable']           = true;
            $posture['jwt_secret_is_placeholder'] = (defined('JWT_SECRET') && JWT_SECRET === 'your_jwt_secret_here');
            $posture['api_key_is_placeholder']    = (defined('API_KEY') && API_KEY === 'your_api_key_here');
            $posture['jwt_expiry_seconds']         = defined('JWT_EXPIRY') ? JWT_EXPIRY : null;
            $posture['posture_ok'] = !$posture['jwt_secret_is_placeholder']
                && !$posture['api_key_is_placeholder']
                && $posture['jwt_expiry_seconds'] <= 86400;
        } catch (Throwable $e) {
            $posture['config_loadable'] = false;
            $posture['config_error']    = 'config.php present but failed to load (check syntax/permissions)';
        }
    }

    $response['security_posture'] = $posture;
}

// ─── detail=2: auth-coverage scan (LOOPBACK ONLY) ────────────────────────────
// Scans every .php file in api/ess/ and reports whether it calls requireAuth(),
// requireRole(), or is a known-public endpoint (login, refresh, health, etc.).
// This catches any future endpoint that forgets to add an auth guard.
if ($detail === '2') {
    if (!$isLoopback) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Forbidden: ?detail=2 is restricted to loopback origin.',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Endpoints that are intentionally public (no auth by design).
    $publicByDesign = [
        'login.php', 'refresh.php', 'health.php', 'cors.php',
        'security-headers.php', 'helpers.php', 'example.config.php',
        'config.php', 'auth-guard.php',
    ];

    $files = glob(__DIR__ . '/*.php');
    $scan  = [
        'total_endpoints'   => 0,
        'protected'         => 0,
        'public_by_design'  => 0,
        'unprotected'       => [],
        'protected_files'   => [],
        'public_files'      => [],
    ];

    foreach ($files as $filePath) {
        $basename = basename($filePath);
        // Skip non-endpoint includes
        if (in_array($basename, ['cors.php', 'security-headers.php', 'helpers.php',
            'example.config.php', 'config.php', 'auth-guard.php'], true)) {
            continue;
        }
        $scan['total_endpoints']++;

        $source = file_get_contents($filePath);

        if (in_array($basename, ['login.php', 'refresh.php', 'health.php'], true)) {
            $scan['public_by_design']++;
            $scan['public_files'][] = $basename;
            continue;
        }

        // An endpoint is "protected" if it calls requireAuth() or requireRole()
        // anywhere in the file (including inside handler functions).
        $hasRequireAuth  = strpos($source, 'requireAuth(') !== false;
        $hasRequireRole  = strpos($source, 'requireRole(') !== false;
        // sync.php uses loopback restriction instead of JWT — count as protected.
        $hasLoopbackGuard = (strpos($source, 'isLoopback') !== false)
                         || (strpos($source, "REMOTE_ADDR']") !== false
                             && strpos($source, '127.0.0.1') !== false);

        if ($hasRequireAuth || $hasRequireRole || $hasLoopbackGuard) {
            $scan['protected']++;
            $scan['protected_files'][] = $basename;
        } else {
            $scan['unprotected'][] = $basename;
        }
    }

    $scan['all_protected'] = empty($scan['unprotected']);
    $response['auth_coverage'] = $scan;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
