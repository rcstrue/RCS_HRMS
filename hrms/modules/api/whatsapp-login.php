<?php
/**
 * HRMS Proxy: WhatsApp Login / Reconnect
 * POST — authenticated admin; calls bot /api/login
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';

session_start();
if (!isset($_SESSION['user_id'])||empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
$allowed=['admin','hr_executive','hr'];
if (!in_array($_SESSION['role_code']??'',$allowed,true)){ http_response_code(403); echo json_encode(['success'=>false,'error'=>'Access denied']); exit; }

// CSRF protection (Rule 13 — apply CSRF for POST)
if (!validateCSRFToken($_POST['csrf_token']??'')) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit;
}

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url','notif_wa_bot_key')");
$botUrl=''; $botKey='';
foreach ($rows as $r) {
    if ($r['setting_key']==='notif_wa_bot_url') $botUrl=rtrim($r['setting_value'],'/');
    if ($r['setting_key']==='notif_wa_bot_key') $botKey=$r['setting_value'];
}
if (empty($botUrl)||empty($botKey)){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Bot not configured']); exit; }

$ch = curl_init(); curl_setopt_array($ch,[
    CURLOPT_URL=>$botUrl.'/api/login',
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_HTTPHEADER=>[
        'Content-Type: application/json', 'X-API-Key: '.$botKey
    ],
]);
$res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

if ($code!==200) { http_response_code(502); echo json_encode(['success'=>false,'error'=>'Bot unreachable or not responding']); exit; }
echo $res; // Pass bot response through directly (contains success/connected/message)
