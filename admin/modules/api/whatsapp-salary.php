<?php
/**
 * WhatsApp Salary Notification API
 * Called from payroll process page to send bulk salary credit WhatsApp messages
 */

header('Content-Type: application/json');

$periodId = (int)($_GET['period_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$unitId   = (int)($_GET['unit_id'] ?? 0);

if (!$periodId || !$clientId) {
    echo json_encode(['success' => false, 'message' => 'Missing period_id or client_id']);
    exit;
}

// Get WhatsApp bot config from settings
$waUrl = '';
$waKey = '';

try {
    $settings = $db->fetchAll(
        "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url', 'notif_wa_bot_key')"
    );
    foreach ($settings as $s) {
        if ($s['setting_key'] === 'notif_wa_bot_url') $waUrl = rtrim($s['setting_value'], '/');
        if ($s['setting_key'] === 'notif_wa_bot_key') $waKey = $s['setting_value'];
    }
} catch (Exception $e) {}

if (empty($waUrl) || empty($waKey)) {
    echo json_encode(['success' => false, 'message' => 'WhatsApp Bot not configured. Go to Settings > Notifications to set WhatsApp Bot URL and API Key.']);
    exit;
}

// Get period info
$period = $db->fetch("SELECT month, year FROM payroll_periods WHERE id = ?", [$periodId]);
if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Payroll period not found']);
    exit;
}

$month = (int)$period['month'];
$year  = (int)$period['year'];
$monthYear = date('F Y', mktime(0, 0, 0, $month, 1, $year));

// Get employees with payroll data for this period/client/unit
$where = "p.payroll_period_id = :pid AND p.net_pay > 0";
$params = ['pid' => $periodId];

// Join employees table to get mobile and filter by client
$where .= " AND e.client_id = :cid";
$params['cid'] = $clientId;

if ($unitId) {
    $where .= " AND e.unit_id = :uid";
    $params['uid'] = $unitId;
}

$rows = $db->fetchAll(
    "SELECT e.full_name, e.mobile, e.employee_code,
            p.gross_earnings, p.total_deductions, p.net_pay
     FROM payroll p
     JOIN employees e ON p.employee_id = e.employee_code
     WHERE $where
     ORDER BY e.employee_code",
    $params
);

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'No payroll records found for this selection']);
    exit;
}

// Build WhatsApp messages for each employee
$messages = [];
$skippedNoMobile = 0;

foreach ($rows as $row) {
    $mobile = preg_replace('/[^0-9]/', '', $row['mobile'] ?? '');
    if (strlen($mobile) < 10) {
        $skippedNoMobile++;
        continue;
    }
    if (strlen($mobile) == 10) $mobile = '91' . $mobile;

    $msg = "💰 *SALARY CREDITED*\n\n" .
           "Dear *" . trim($row['full_name']) . "*,\n\n" .
           "Your salary for *" . $monthYear . "* has been credited to your bank account.\n\n" .
           "📋 *Payslip Details:*\n" .
           "Gross: *Rs. " . number_format($row['gross_earnings'] ?? 0, 2) . "*\n" .
           "Deductions: *Rs. " . number_format($row['total_deductions'] ?? 0, 2) . "*\n" .
           "*Net Pay: Rs. " . number_format($row['net_pay'] ?? 0, 2) . "*\n\n" .
           "Login to HRMS portal for detailed payslip.\n\n" .
           "_RCS TRUE FACILITIES PVT LTD_";

    $messages[] = ['to' => $mobile, 'message' => $msg];
}

if (empty($messages)) {
    echo json_encode(['success' => false, 'message' => "No employees with valid mobile numbers found. ($skippedNoMobile skipped)"]);
    exit;
}

// Send via WhatsApp Bot API (bulk endpoint)
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $waUrl . '/api/send-bulk',
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 600, // 10 min for bulk
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $waKey
    ],
    CURLOPT_POSTFIELDS => json_encode(['messages' => $messages])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['success' => false, 'message' => 'Cannot reach WhatsApp Bot: ' . $curlError]);
    exit;
}

$result = json_decode($response, true);

$sent = $result['data']['sent'] ?? 0;
$failed = $result['data']['failed'] ?? 0;
$queued = $result['data']['queued'] ?? 0;
$total = count($messages);

$summary = "Sent to $sent/$total employees";
if ($queued > 0) $summary .= ", $queued queued";
if ($failed > 0) $summary .= ", $failed failed";
if ($skippedNoMobile > 0) $summary .= " ($skippedNoMobile no mobile)";

echo json_encode([
    'success' => ($httpCode == 200 && ($result['success'] ?? false)),
    'message' => $summary,
    'sent' => $sent,
    'failed' => $failed,
    'queued' => $queued,
    'skipped' => $skippedNoMobile
]);
