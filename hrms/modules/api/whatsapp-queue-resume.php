<?php
/**
 * HRMS Proxy: WhatsApp Queue Resume
 * POST — admin, CSRF protected; calls bot /api/queue/resume
 *
 * Used to resume the queue after a drain-cap pause (the worker stops after
 * `drain_cap_per_connection` sends on a single connection, to prevent a
 * backlog burst after restart). Also clears admin-pause if active.
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

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url','notif_wa_bot_key')");
$botUrl=''; $botKey='';
foreach ($rows as $r) {
    if ($r['setting_key']==='notif_wa_bot_url') $botUrl=rtrim($r['setting_value'],'/');
    if ($r['setting_key']==='notif_wa_bot_key') $botKey=$r['setting_value'];
}
if (empty($botUrl)||empty($botKey)){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Bot not configured']); exit; }

$ch = curl_init(); curl_setopt_array($ch,[
    CURLOPT_URL=>$botUrl.'/api/queue/resume',
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>15, CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$botKey],
]);
$res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

if ($code!==200) { http_response_code(502); echo json_encode(['success'=>false,'error'=>'Bot unreachable or resume failed']); exit; }
echo $res;
