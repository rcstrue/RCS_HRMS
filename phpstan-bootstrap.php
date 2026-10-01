<?php
// PHPStan bootstrap — define constants, globals, and stubs used across api/ and hrms/ code
// so static analysis doesn't fail on undefined variables/functions/constants.

// ── Global DB variables (set by included config files at runtime) ──
// HRMS modules use $db (mysqli) set by includes/config.php
// API files use $conn (mysqli) set by api/ess/config.php
global $db, $conn, $stmt, $result, $row;
$db = new \mysqli();
$conn = new \mysqli();

// ── Constants referenced in API code ──
defined('ESS_GUARD_ROLES_MANAGER') || define('ESS_GUARD_ROLES_MANAGER', ['supervisor','manager','hr','admin']);
defined('ESS_GUARD_ROLES_ADMIN') || define('ESS_GUARD_ROLES_ADMIN', ['hr','admin']);

// ── Common HRMS functions (defined in includes/) ──
if (!function_exists('sanitize')) {
    function sanitize($input) { return $input; }
}
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) { return $amount; }
}
if (!function_exists('getSettings')) {
    function getSettings($key = null) { return null; }
}
