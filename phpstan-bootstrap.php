<?php
/**
 * PHPStan bootstrap — defines constants and stubs so static analysis can run
 * without a real database connection or session.
 */

// App guard constant (checked by config.php and constants.php)
define('RCS_HRMS', true);
define('APP_ROOT', dirname(__FILE__) . '/php_payroll');

// DB credentials (dummy values — PHPStan only needs the constants to exist)
define('DB_HOST', 'localhost');
define('DB_NAME', 'test_hrms');
define('DB_USER', 'test');
define('DB_PASS', 'test');
define('DB_CHARSET', 'utf8mb4');

// App settings
define('APP_NAME', 'RCS HRMS Pro');
define('APP_VERSION', '1.0.0');
define('APP_URL', '');
define('APP_ENV', 'development');
define('SESSION_NAME', 'rcs_hrms_session');
define('SESSION_LIFETIME', 345600);

// Prevent session_start() from failing in CI (no headers)
if (session_status() === PHP_SESSION_NONE) {
    // Suppress headers-already-sent warnings in CI
    @session_start();
}
