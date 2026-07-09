<?php
declare(strict_types=1);

/**
 * ESS API — Shared Configuration & Utilities
 * Employee Self Service application backend
 *
 * SETUP: Copy this file to config.php and update the values below.
 */

// ─── Database Constants ───────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

// ─── Security Constants ──────────────────────────────────────────────────────
define('API_KEY', 'your_api_key_here');
define('JWT_SECRET', 'your_jwt_secret_here');
define('JWT_EXPIRY', 86400); // 24 hours

// ─── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');

// ─── CORS Headers ─────────────────────────────────────────────────────────────
// IMPORTANT: In production, use cors.php for proper origin whitelisting.
// This fallback is for development ONLY.
header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$devOrigins = ['http://localhost:5173', 'http://localhost:3000'];
if (in_array($origin, $devOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── JSON Output ──────────────────────────────────────────────────────────────
/**
 * Output JSON response and exit
 */
function jsonOutput(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Read Input ───────────────────────────────────────────────────────────────
/**
 * Read JSON request body as associative array
 */
function getInput(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

// ─── Get Bearer Token ─────────────────────────────────────────────────────────
/**
 * Extract Bearer token from Authorization header
 */
function getBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

// ─── API Key Validation ───────────────────────────────────────────────────────
/**
 * Validate X-API-KEY header. Returns true if valid, sends 403 and exits if not.
 */
function validateApiKey(): bool
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(API_KEY, (string)$key)) {
        jsonOutput(['success' => false, 'error' => 'Invalid API key'], 403);
        return false;
    }
    return true;
}

// ─── Lightweight JWT (no composer dependency) ─────────────────────────────────
class SimpleJWT
{
    private static string $secret;
    private static string $algo = 'HS256';

    public static function init(string $secret): void
    {
        self::$secret = $secret;
    }

    /**
     * Encode payload into JWT token
     */
    public static function encode(array $payload, int $expirySeconds = 86400): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expirySeconds;

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => self::$algo]));
        $payloadEnc = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(hash_hmac('sha256', "$header.$payloadEnc", self::$secret, true));

        return "$header.$payloadEnc.$signature";
    }

    /**
     * Decode and validate JWT token. Returns payload array or null.
     *
     * @param string $token        The JWT string
     * @param bool   $allowExpired When true, returns payload even if expired
     *                              (used by refresh-token flow).
     */
    public static function decode(string $token, bool $allowExpired = false): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Verify signature
        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', "$header.$payload", self::$secret, true));
        if (!hash_equals($expectedSig, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data) {
            return null;
        }

        // Check expiry (skip if allowExpired is true — used for refresh flow)
        if (!$allowExpired && isset($data['exp']) && $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

// Initialize JWT
SimpleJWT::init(JWT_SECRET);

// ─── Auth Helper ──────────────────────────────────────────────────────────────
/**
 * Require authentication via JWT. Returns employee_id or exits with 401.
 */
function requireAuth(): string
{
    $token = getBearerToken();
    if (!$token) {
        jsonOutput(['success' => false, 'error' => 'Authorization token required'], 401);
    }

    $payload = SimpleJWT::decode($token);
    if (!$payload) {
        jsonOutput(['success' => false, 'error' => 'Invalid or expired token'], 401);
    }

    return (string)($payload['employee_id'] ?? '');
}

// ─── Database Connection ──────────────────────────────────────────────────────
/**
 * Create and return a mysqli connection
 */
function getDbConnection()
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    return $conn;
}

// ─── Employee Helpers ─────────────────────────────────────────────────────────
/**
 * Get employee role from ess_employee_cache
 */
function getEmployeeRole(mysqli $conn, string $employeeId): ?string
{
    $stmt = $conn->prepare('SELECT role FROM ess_employee_cache WHERE employee_id = ?');
    $stmt->bind_param('s', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row['role'];
    }
    $stmt->close();
    return null;
}

/**
 * Get team members (employees under the same manager/unit)
 */
function getTeamMembers($conn, $employeeId)
{
    // First, get the employee's unit and client info
    $stmt = $conn->prepare('SELECT unit_id, client_id FROM ess_employee_cache WHERE employee_id = ?');
    $stmt->bind_param('s', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $cache = $result->fetch_assoc();
    $stmt->close();

    if (!$cache) {
        return [];
    }

    // Find all employees in the same unit
    $query = 'SELECT employee_id, full_name, designation, role FROM ess_employee_cache WHERE employee_id != ?';
    $types = 's';
    $params = [$employeeId];

    if (!empty($cache['unit_id'])) {
        $query .= ' AND unit_id = ?';
        $types .= 'i';
        $params[] = $cache['unit_id'];
    } elseif (!empty($cache['client_id'])) {
        $query .= ' AND client_id = ?';
        $types .= 'i';
        $params[] = $cache['client_id'];
    }

    $query .= ' ORDER BY full_name';

    $stmt = $conn->prepare($query);
    if (!empty($params) && count($params) > 1) {
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param($types, $params[0]);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();

    return $members;
}

// ─── Pagination Helper ────────────────────────────────────────────────────────
// NOTE: buildPagination, buildPaginationResponse, getPaginationParams are now in helpers.php
// This file only provides: jsonOutput, getInput, getBearerToken, validateApiKey, SimpleJWT,
//   requireAuth, getDbConnection, getEmployeeRole, getTeamMembers
// Legacy bindDynamicParams is now an alias for safeBindParam in helpers.php

// ─── Dynamic Bind Params Helper (legacy — now delegates to helpers.php) ───────
if (!function_exists('bindDynamicParams')) {
    function bindDynamicParams($stmt, $types, $params)
    {
        safeBindParam($stmt, $types, $params);
    }
}
