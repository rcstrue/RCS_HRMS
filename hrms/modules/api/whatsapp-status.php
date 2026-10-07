<?php
/**
 * HRMS Proxy: WhatsApp Status
 * Authenticated admin endpoint → calls Node bot /api/status
 * Never exposes API key to browser.
 */
require_once __DIR__ . '/../../hrms/includes/config/config.php';
require_once __DIR__ . '/../../hrms/includes/database.php';

// Auth check
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Admin / HR Executive permission
$allowed = ['admin', 'hr_executive', 'hr'];
if (!in_array($_SESSION['role_code'] ?? '', $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Admin/HR required.']);
    exit;
}

// CSRF check for POST (not needed for GET, but safe to allow GET only)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load bot URL / key from DB settings (never expose in response to browser)
$db = Database::getInstance();
$rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url','notif_wa_bot_key')");
$botUrl = '';
$botKey = '';
foreach ($rows as $r) {
    if ($r['setting_key'] === 'notif_wa_bot_url') $botUrl = rtrim($r['setting_value'], '/');
    if ($r['setting_key'] === 'notif_wa_bot_key') $botKey = $r['setting_value'];
}

if (empty($botUrl) || empty($botKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'WhatsApp Bot API not configured']);
    exit;
}

// Call Node bot server-side (Rule 13 — server-to-server via cURL)
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $botUrl . '/api/status',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['X-API-Key: ' . $botKey],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => 'Bot unreachable: ' . ($curlError ?: 'HTTP ' . $httpCode),
        'connected' => false,
        'loginRequired' => true,
        'qrAvailable' => false
    ]);
    exit;
}

$result = json_decode($response, true);

// Pass only safe fields to browser (Rule 12 — never expose API key, never expose auth files)
echo json_encode([
    'success' => true,
    'connected' => $result['connected'] ?? false,
    'phone' => ($result['connected'] ?? false) ? ($result['phone'] ?? null) : null,
    'name' => ($result['connected'] ?? false) ? ($result['name'] ?? null) : null,
    'queueLength' => $result['queueLength'] ?? 0,
    'messagesSent' => $result['messagesSent'] ?? 0,
    'loginRequired' => $result['loginRequired'] ?? false,
    'qrAvailable' => $result['qrAvailable'] ?? false,
    'message' => $result['connected'] ?? false ? 'WhatsApp Bot is connected' : 'WhatsApp Bot is offline'
]);
