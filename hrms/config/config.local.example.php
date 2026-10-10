<?php
/**
 * RCS HRMS Pro - Local Configuration (LEGACY)
 *
 * DEPRECATED: The preferred method is to use /home/rcsfaxhz/.env
 * (see .env.example in the repo root). The .env file is the SINGLE source
 * of truth for all credentials and lives OUTSIDE public_html.
 *
 * This file is kept for backward compatibility. If /home/rcsfaxhz/.env
 * exists, its values take priority over anything defined here.
 *
 * To migrate from config.local.php to .env:
 *   1. Copy your real values from this file to /home/rcsfaxhz/.env
 *   2. Delete this file (config.local.php) — the .env loader handles everything
 *   3. Verify HRMS still works (it should — .env is checked first)
 *
 * This file is NOT tracked in git - add your actual credentials here.
 */

// Environment Setting
// Use 'development' for local testing (shows errors), 'production' for live server.
// SECURITY: default to 'production' — operators copying this file get safe
// settings. Switch to 'development' ONLY on a local dev machine.
define('APP_ENV', 'production');

// Database Configuration
define('DB_HOST', 'localhost:3306');          // Database host (usually localhost)
define('DB_NAME', 'rcsfaxhz_bolt');           // Database name
define('DB_USER', 'rcsfaxhz_bolt');           // Database username
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE');   // Database password - CHANGE THIS
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'RCS HRMS Pro');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://join.rcsfacility.com/hrms/');  // Your application URL

// Session Settings
define('SESSION_NAME', 'rcs_hrms_session');
define('SESSION_LIFETIME', 7200); // 2 hours (cookie + gc_maxlifetime)
// Idle timeout: seconds of inactivity before the session is invalidated.
// 28800 = 8 hours (a standard workday). Was 345600 (4 days) — too long for
// an HRMS handling PII + payroll data.
define('SESSION_IDLE_TIMEOUT', 28800);

// Security Settings
define('ENCRYPTION_KEY', 'YOUR_32_CHAR_ENCRYPTION_KEY_HERE');  // Change to a random 32 character string

// Email Settings (for notifications)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');
define('SMTP_FROM', 'noreply@rcsfacility.com');
define('SMTP_FROM_NAME', 'RCS HRMS Pro');

// File Upload Settings
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_PATH', APP_ROOT . '/uploads/');
define('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,pdf,doc,docx,xls,xlsx');

// API Keys (if any third-party integrations)
// define('SMS_API_KEY', '');
// define('SMS_SENDER_ID', 'RCSHRMS');

// Logging
define('LOG_PATH', APP_ROOT . '/logs/');
// SECURITY: default to 'error' — 'debug' fills the disk with verbose logs in
// production (and may log sensitive request data). Switch to 'debug' only on
// a local dev machine.
define('LOG_LEVEL', 'error'); // debug, info, warning, error
