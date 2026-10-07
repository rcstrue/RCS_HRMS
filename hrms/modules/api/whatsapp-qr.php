<?php
/**
 * HRMS Proxy: WhatsApp QR
 * GET — authenticated admin only; server calls bot /api/qr
 */
require_once __DIR__ . '/../../hrms/includes/config/config.php';
require_once __DIR__ . '/../../hrms/includes/database.php';

session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit;
}
$allowed = ['admin','hr_executive','hr'];
if (!in_array($_SESSION['role_code']??'', $allowed, true)) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Access denied']); exit;
}

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url','notif_wa_bot_key')");
$botUrl=''; $botKey='';
foreach ($rows as $r) {
    if ($r['setting_key']==='notif_wa_bot_url') $botUrl=rtrim($r['setting_value'],'/');
    if ($r['setting_key']==='notif_wa_bot_key') $botKey=$r['setting_value'];
}
if (empty($botUrl)||empty($botKey)){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Bot not configured']); exit; }

$ch = curl_init(); 
curl_setopt_array($ch,[
    CURLOPT_URL=>$botUrl.'/api/qr',
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_HTTPHEADER=>['X-API-Key: '.$botKey],
]);
$res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

if ($code!==200) { http_response_code(502); echo json_encode(['success'=>false,'error'=>'Bot unreachable']); exit; }
$data = json_decode($res,true);

// Option A (preferred): return raw QR string — HRMS browser uses qrcode library
// Option B: include base64 image if bot provides it (current bot returns qr string only)
echo json_encode([
    'success'=>true,
    'available'=>($data['available']??false),
    'qr'=>($data['available']??false)?($data['qr']??null):null,
    // Do NOT expose bot URL/key / auth file paths / raw session data
]);
