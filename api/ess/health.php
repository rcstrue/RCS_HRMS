<?php
/**
 * ESS Health & Security-Posture Check
 *
 * GET /api/ess/health            — basic liveness (no config required, always works)
 * GET /api/ess/health?detail=1   — include security posture (attempts to load config;
 *                                   reports booleans only — NO secrets are ever leaked)
 *
 * The detail mode lets operators verify (from a browser or curl) that:
 *   - the live config.php is present and loadable
 *   - JWT_SECRET / API_KEY are no longer the default placeholders
 *   - JWT expiry has been reduced from the 4-day default
 *   - the hardening revision is current
 *
 * This is intentionally unauthenticated so it can be used as a deploy-time smoke
 * test. It never returns secret values — only booleans and version stamps.
 */
define('RCS_ESS_HEALTH_CHECK', true); // bypass the placeholder-secret guard in example.config.php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/security-headers.php';

$detail = ($_GET['detail'] ?? '') === '1';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? 0) == 443);

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
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    ],
    // Hardening revision stamp — bump when a new hardening round is applied.
    'hardening' => [
        'hardening_version'        => 1,
        'security_headers_sent'    => true,
        'xtransform_proxy_removed' => true,   // Caddyfile SSRF block deleted in r1
        'placeholder_secret_guard' => true,   // example.config.php refuses placeholder secrets
        'jwt_expiry_reduced'       => true,    // 4 days -> 24 hours
    ],
];

if ($detail) {
    $posture = [
        'config_present'         => false,
        'config_loadable'        => false,
        'using_example_config'   => true,
        'jwt_secret_is_placeholder' => null,
        'api_key_is_placeholder'   => null,
        'jwt_expiry_seconds'      => null,
    ];

    $cfgPath = __DIR__ . '/config.php';
    if (is_file($cfgPath)) {
        $posture['config_present']       = true;
        $posture['using_example_config'] = false;
        try {
            require_once $cfgPath;
            $posture['config_loadable']          = true;
            $posture['jwt_secret_is_placeholder'] = (defined('JWT_SECRET') && JWT_SECRET === 'your_jwt_secret_here');
            $posture['api_key_is_placeholder']   = (defined('API_KEY') && API_KEY === 'your_api_key_here');
            $posture['jwt_expiry_seconds']        = defined('JWT_EXPIRY') ? JWT_EXPIRY : null;
            // Overall posture verdict
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

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
