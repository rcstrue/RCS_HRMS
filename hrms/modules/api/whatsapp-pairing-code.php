<?php
/**
 * HRMS Proxy: WhatsApp Pairing Code
 * POST { phone } — admin, CSRF protected; calls bot /api/pairing-code
 *
 * Alternative to QR login: generates an 8-character pairing code that the
 * user enters on their phone (WhatsApp → Linked Devices → Link with phone
 * number instead). Useful when QR scanning isn't practical.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';

session_start();
if (!isset($_SESSION['user_id'])||empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
$allowed=['admin','hr_executive','hr'];
if (!in_array($_SESSION['role_code']??'',$allowed,true)){ http_response_code(403); echo json_encode(['success'=>false,'error'=>'Access denied']); exit; }

if (!validateCSRFToken($_POST['csrf_token']??'')) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit;
}

// Phone number = the WhatsApp number of the account being linked (the user's
// own number, NOT a recipient). Must be 10+ digits after sanitising.
$phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
if (strlen($phone) < 10) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Valid phone number (10+ digits) is required']); exit;
}

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url','notif_wa_bot_key')");
$botUrl=''; $botKey='';
foreach ($rows as $r) {
    if ($r['setting_key']==='notif_wa_bot_url') $botUrl=rtrim($r['setting_value'],'/');
    if ($r['setting_key']==='notif_wa_bot_key') $botKey=$r['setting_value'];
}
if (empty($botUrl)||empty($botKey)){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Bot not configured']); exit; }

// Call the bot's /api/pairing-code endpoint. The bot starts a fresh socket
// if needed (including clearing stale session on hard-stop), then calls
// sock.requestPairingCode(phone) and returns the 8-char code.
$ch = curl_init(); curl_setopt_array($ch,[
    CURLOPT_URL=>$botUrl.'/api/pairing-code',
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$botKey],
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone]),
]);
$res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

if ($code!==200) { http_response_code(502); echo json_encode(['success'=>false,'error'=>'Bot unreachable or pairing code generation failed']); exit; }
echo $res;
