<?php
/**
 * RCS HRMS Pro - Local Configuration
 * 
 * This file contains database credentials and sensitive settings.
 * This file is NOT tracked in git - add your actual credentials here.
 */

// Environment Setting
// Use 'development' for local testing (shows errors), 'production' for live server
define('APP_ENV', 'development');

// Database Configuration
define('DB_HOST', 'localhost:3306');          // Database host (usually localhost)
define('DB_NAME', 'rcsfaxhz_bolt');           // Database name
define('DB_USER', 'rcsfaxhz_bolt');           // Database username
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE');   // Database password - CHANGE THIS
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'RCS HRMS Pro');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://sid.rcsfacility.com/php_payroll/');  // Your application URL

// Session Settings
define('SESSION_NAME', 'rcs_hrms_session');
define('SESSION_LIFETIME', 7200); // 2 hours

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
define('LOG_LEVEL', 'debug'); // debug, info, warning, error
