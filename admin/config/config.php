<?php
/**
 * RCS HRMS Pro - Configuration File
 * Company: RCS TRUE FACILITIES PVT LTD
 *
 * This file loads database credentials from config.local.php
 * config.local.php is NOT tracked in git - your credentials are safe
 */

// Prevent direct access
if (!defined('RCS_HRMS')) {
    die('Direct access not allowed');
}

// Define application constant for path resolution
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Include constants file (for centralized constants) - must be after APP_ROOT is defined
require_once APP_ROOT . '/includes/constants.php';

// Load local configuration if exists (this file is NOT in git)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Database Settings (set defaults if not defined in config.local.php)
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'rcs_hrms');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

// Application Settings
if (!defined('APP_NAME')) {
    define('APP_NAME', 'RCS HRMS Pro');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
if (!defined('APP_URL')) {
    define('APP_URL', '');
}

// Session Settings
if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'rcs_hrms_session');
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 7200); // 2 hours
}

// Pagination
if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', DEFAULT_PAGE_SIZE);
}

// Date Formats
if (!defined('DATE_FORMAT')) {
    define('DATE_FORMAT', DATE_FORMAT_DISPLAY);
}
if (!defined('DATETIME_FORMAT')) {
    define('DATETIME_FORMAT', DATETIME_FORMAT_DISPLAY);
}

// File Upload Settings
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', MAX_FILE_SIZE_UPLOAD);
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', APP_ROOT . '/uploads/');
}

// API Settings
if (!defined('API_TIMEOUT')) {
    define('API_TIMEOUT', 30);
}

// API Keys for external integrations - Load from secure location
// Option 1: Load from environment variables (recommended for production)
// Option 2: Load from config.local.php (current implementation)
// Option 3: Load from separate secure file outside webroot
if (!defined('API_KEYS')) {
    // Default empty - should be defined in config.local.php
    // Example: define('API_KEYS', serialize(['key1', 'key2']));
    // For production, consider moving to environment variables:
    // $apiKeys = getenv('HRMS_API_KEYS');
    // define('API_KEYS', $apiKeys ?: serialize([]));
    define('API_KEYS', serialize([]));
}

// Function to validate API key
function isValidApiKey($key)
{
    $validKeys = unserialize(API_KEYS);
    if (empty($validKeys)) {
        // If no keys configured, check config.local.php or environment
        $localKeys = defined('LOCAL_API_KEYS') ? unserialize(LOCAL_API_KEYS) : [];
        return in_array($key, $localKeys);
    }
    return in_array($key, $validKeys);
}

// Error Reporting - Environment based
if (defined('APP_ENV') && APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    $logDir = APP_ROOT . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/error.log');
}

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Global helper: previous month (1-12)
// Current date is June 2026 → prev month = May (5)
function prev_month_num() {
    $m = (int)date('n') - 1;
    return $m < 1 ? 12 : $m;
}
function prev_month_year() {
    $m = (int)date('n') - 1;
    if ($m < 1) return (int)date('Y') - 1;
    return (int)date('Y');
}

// Start Session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    // Set custom session save path inside app directory (avoids system tmp issues)
    $sessDir = APP_ROOT . '/tmp_sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0755, true);
        // Create .htaccess to prevent direct access
        @file_put_contents($sessDir . '/.htaccess', 'Deny from all');
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        ini_set('session.save_path', $sessDir);
    }

    // Detect HTTPS for secure cookie setting
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

    session_name(SESSION_NAME);
    session_start();
}

// Autoload Classes (PSR-4 compatible)
spl_autoload_register(function ($class) {
    // Map namespace prefix to directory
    $prefix = 'RCS\\HRMS\\';
    $baseDir = APP_ROOT . '/includes/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        // Get the relative class name
        $relativeClass = substr($class, $len);
        // Replace namespace separators with directory separators
        $file = $baseDir . 'class.' . strtolower(str_replace('\\', '/', $relativeClass)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    // Fallback: legacy class loading (class.{name}.php)
    $classFile = APP_ROOT . '/includes/class.' . strtolower($class) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
        return;
    }

    // Check classes/{Name}.php
    $classFile = APP_ROOT . '/classes/' . $class . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Helper Functions
function sanitize($input)
{
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    if ($input === null) {
        return '';
    }
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

// CSRF Protection Functions
function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function getCSRFTokenField()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function validateCSRFToken($token = null)
{
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCSRFToken()
{
    if (!validateCSRFToken()) {
        http_response_code(403);
        die('CSRF token validation failed. Please refresh the page and try again.');
    }
}

// Input Validation Functions
function validateInt($value, $min = null, $max = null)
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false) return false;
    if ($min !== null && $value < $min) return false;
    if ($max !== null && $value > $max) return false;
    return $value;
}

function validateFloat($value, $min = null, $max = null)
{
    $value = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($value === false) return false;
    if ($min !== null && $value < $min) return false;
    if ($max !== null && $value > $max) return false;
    return $value;
}

function validateDate($date, $format = 'Y-m-d')
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function validateEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePhone($phone)
{
    return preg_match('/^[0-9]{10,15}$/', preg_replace('/[^0-9]/', '', $phone));
}

function formatDate($date)
{
    $result = '-';
    if (!empty($date) && $date != '0000-00-00') {
        $result = date(DATE_FORMAT, strtotime($date));
    }
    return $result;
}

function formatDateTime($datetime)
{
    $result = '-';
    if (!empty($datetime)) {
        $result = date(DATETIME_FORMAT, strtotime($datetime));
    }
    return $result;
}

function formatCurrency($amount)
{
    return '₹' . number_format($amount ?? 0, 2);
}

function maskAadhaar($aadhaar)
{
    $result = $aadhaar;
    if (empty($aadhaar) || strlen($aadhaar) < 4) {
        $result = $aadhaar;
    } else {
        $result = 'XXXX-XXXX-' . substr($aadhaar, -4);
    }
    return $result;
}

function setFlash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash()
{
    $result = null;
    if (isset($_SESSION['flash'])) {
        $result = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
    return $result;
}

/**
 * Safe redirect function - validates URLs to prevent open redirect attacks
 * Only allows relative URLs (internal pages) or whitelisted domains
 *
 * @param string $url The URL to redirect to
 */
function redirect($url)
{
    // Validate and sanitize the URL
    $url = sanitizeRedirectUrl($url);

    if (!headers_sent()) {
        header("Location: $url");
    } else {
        // NOTE: Don't use htmlspecialchars here - JavaScript doesn't need HTML encoding
        // The URL is already validated by sanitizeRedirectUrl for security
        echo '<script>window.location.href="' . addslashes($url) . '";</script>';
    }
    exit;
}

/**
 * Validate redirect URL to prevent open redirect attacks
 * Refactored to use single return statement
 *
 * @param string $url The URL to validate
 * @return string Safe URL (defaults to index.php if invalid)
 */
function sanitizeRedirectUrl($url)
{
    $result = 'index.php';

    // Empty URL defaults to index
    if (empty($url)) {
        return $result;
    }

    // Allow relative URLs starting with index.php (internal pages)
    if (preg_match('/^index\.php\?page=[a-zA-Z0-9\/_\-&=]+$/', $url)) {
        $result = $url;
        return $result;
    }

    // Allow relative URLs without protocol (internal paths)
    if (preg_match('/^[a-zA-Z0-9_\-\/\.]+\.php(\?.*)?$/', $url)) {
        $result = $url;
        return $result;
    }

    // Block external URLs with protocols (open redirect prevention)
    if (preg_match('/^https?:\/\//i', $url)) {
        // Only allow if it matches the app URL
        $appUrl = defined('APP_URL') ? APP_URL : '';
        if (!empty($appUrl) && strpos($url, $appUrl) === 0) {
            $result = $url;
        }
        // External URLs not allowed - fallback to index
        return $result;
    }

    // Block javascript: and data: URLs (XSS prevention)
    if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
        return $result;
    }

    // Allow other relative URLs
    $result = $url;
    return $result;
}

function logActivity($action, $module, $recordId, $description)
{
    global $db;
    if (isset($db)) {
        $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_values, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'] ?? null, $action, $module, $recordId, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}

// Password Helper Functions
function hashPassword($password)
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

function generateToken($length = 32)
{
    return bin2hex(random_bytes($length));
}

// Settings Helper Functions
function getSetting($key, $default = '')
{
    $result = $default;
    global $db;

    if (!isset($db)) {
        return $result;
    }

    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result = $row['setting_value'];
        }
    } catch (Exception $e) {
        // Return default on error
    }
    return $result;
}

function updateSetting($key, $value)
{
    $result = false;
    global $db;

    if (!isset($db)) {
        return $result;
    }

    try {
        // Check if setting exists
        $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $stmt = $db->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
            $stmt->execute([$key, $value]);
        }
        $result = true;
    } catch (Exception $e) {
        // Return false on error
    }
    return $result;
}
