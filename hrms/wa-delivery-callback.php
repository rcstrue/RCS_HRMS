<?php
/**
 * WhatsApp Delivery Callback — server-to-server endpoint
 * Called by the Node bot AFTER each ACTUAL WhatsApp message send,
 * so HRMS only flips a whatsapp_logs row to 'sent'/'failed' on real delivery.
 *
 * Security:
 *  - Standalone (outside module router) so no PHP session is required.
 *  - Requires X-API-Key matching the configured bot key (same as bot uses).
 *  - Only updates rows currently in 'queued' status, by exact log id.
 */
define('RCS_HRMS', true);
define('APP_ROOT', __DIR__);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json');

// ── Validate API key (compare against bot key from settings) ──
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($provided === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing API key']);
    exit;
}

$rows = $db->fetchAll("SELECT setting_value FROM settings WHERE setting_key = 'notif_wa_bot_key'");
$botKey = '';
foreach ($rows as $r) {
    if (!empty($r['setting_value'])) {
        $botKey = $r['setting_value'];
        break;
    }
}
if ($botKey === '' || !hash_equals($botKey, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Parse JSON body ──
$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) $payload = [];

$logId    = (int)($payload['log_id'] ?? 0);
$mobile   = preg_replace('/[^0-9]/', '', (string)($payload['mobile'] ?? ''));
$ok       = !empty($payload['ok']);
$messageId = isset($payload['messageId']) ? substr((string)$payload['messageId'], 0, 100) : null;
$error    = isset($payload['error']) ? substr((string)$payload['error'], 0, 500) : null;

if ($logId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'log_id is required']);
    exit;
}

// ── Only flip rows still waiting (queued) — never overwrite a later state ──
$updates = [
    'status'        => $ok ? 'sent' : 'failed',
    'error'         => $ok ? null : ($error ?: 'Send failed'),
    'wa_message_id' => $ok ? $messageId : null,
];
if (!empty($mobile)) {
    $updates['mobile'] = $mobile;
}

$affected = $db->update(
    'whatsapp_logs',
    $updates,
    'id = :log_id AND status = :expected_status',
    [':log_id' => $logId, ':expected_status' => 'queued']
);

if ($affected === 0) {
    // Row already processed or doesn't exist — idempotent, not an error
    echo json_encode(['success' => true, 'message' => 'No update needed', 'updated' => false]);
    exit;
}

echo json_encode([
    'success' => true,
    'updated' => true,
    'log_id'  => $logId,
    'status'  => $ok ? 'sent' : 'failed',
]);