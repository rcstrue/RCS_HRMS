<?php
/**
 * RCS ESS - CORS Handler
 * Must be included at the VERY TOP of every API file (before any output).
 *
 * Strict HTTPS-only whitelist. No wildcards, no HTTP fallback.
 * To add a new domain, add it to the $allowedOrigins array below.
 */

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . getAllowedOrigin());
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cache-Control');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Set CORS headers for all requests
header('Access-Control-Allow-Origin: ' . getAllowedOrigin());
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cache-Control');
header('Access-Control-Allow-Credentials: true');

/**
 * Strict HTTPS-only origin whitelist.
 * Returns the whitelisted origin, or empty string to deny CORS for unknown origins.
 */
function getAllowedOrigin() {
    $allowedOrigins = [
        'https://join.rcsfacility.com',
        'https://sid.rcsfacility.com',
    ];

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    return in_array($requestOrigin, $allowedOrigins, true) ? $requestOrigin : '';
}
