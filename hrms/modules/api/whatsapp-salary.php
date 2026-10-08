<?php
/**
 * WhatsApp Salary Notification API
 * Called from payroll process page to queue bulk salary-credit WhatsApp messages.
 *
 * REDESIGNED (audit P2-10 + bulk-safe redesign):
 *  - No longer POSTs directly to the bot's /send-bulk with a 10-min curl timeout.
 *    Instead, enqueues rows into whatsapp_logs via waQueueEnqueue(), which the
 *    bot's single worker drains at the configured conservative pace.
 *  - Filters out salary_hold = 1 rows (held salaries must NOT receive a
 *    "salary credited" message — audit P2-10).
 *  - Honours the per-employee opt-in flag (whatsapp_opted_in) introduced by
 *    the bulk-safe redesign (requirement #10).
 *
 * The response returns immediately with campaign info; the payroll UI button
 * now says "Queued N messages" instead of waiting for sends to complete.
 */

// Auth check — only admin/hr can send WhatsApp notifications
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_code'] ?? '', ['admin', 'hr', 'hr_executive'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised']);
    exit;
}

header('Content-Type: application/json');

$month = (int)($_GET['month'] ?? 0);
$year  = (int)($_GET['year'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$unitId   = (int)($_GET['unit_id'] ?? 0);

if (!$month || !$year || !$clientId) {
    echo json_encode(['success' => false, 'message' => 'Missing month, year or client_id']);
    exit;
}

// WhatsApp bot config is no longer needed here — we enqueue directly into the
// DB queue. The bot picks rows up at its own pace. We still surface a clear
// error if the bot URL/key are unconfigured, so the operator knows messages
// will sit in the queue until they fix configuration.
$waConfig = waGetConfig();
if (empty($waConfig['api_url']) || empty($waConfig['api_key'])) {
    echo json_encode(['success' => false, 'message' => 'WhatsApp Bot not configured. Go to Settings > Notifications to set WhatsApp Bot URL and API Key.']);
    exit;
}

$monthYear = date('F Y', mktime(0, 0, 0, $month, 1, $year));

// Recipient selection — payroll rows for this period/client/unit, with:
//   - net_pay > 0              (no zero-net rows)
//   - salary_hold = 0          (audit P2-10: held salaries must not be announced)
//   - valid mobile             (10+ digits after sanitising)
//   - whatsapp_opted_in = 1    (bulk-safe redesign requirement #10)
$where = "p.month = :month AND p.year = :year AND p.net_pay > 0 AND p.salary_hold = 0";
$params = ['month' => $month, 'year' => $year];

$where .= " AND e.client_id = :cid";
$params['cid'] = $clientId;

if ($unitId) {
    $where .= " AND e.unit_id = :uid";
    $params['uid'] = $unitId;
}

// Opt-in filter is conditional on the column existing — older installs may
// not have run the migration yet, in which case we treat everyone as opted-in
// (the migration defaults the column to 1, so this is safe).
$optInClause = '';
try {
    $colCheck = $db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'whatsapp_opted_in'"
    );
    if ((int)$colCheck > 0) {
        $optInClause = " AND e.whatsapp_opted_in = 1";
    }
} catch (Exception $e) {
    // information_schema not readable — skip the filter (legacy behaviour).
}

$rows = $db->fetchAll(
    "SELECT e.id AS employee_db_id, e.full_name, e.mobile, e.employee_code,
            p.gross_earnings, p.total_deductions, p.net_pay
     FROM payroll p
     JOIN employees e ON p.employee_id = e.employee_code
     WHERE $where $optInClause
     ORDER BY e.employee_code",
    $params
);

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'No eligible payroll records found for this selection (held salaries and opted-out employees are excluded).']);
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

    $msg = "💰 *SALARY CREDITED*\n\n" .
           "Dear *" . trim($row['full_name']) . "*,\n\n" .
           "Your salary for *" . $monthYear . "* has been credited to your bank account.\n\n" .
           "📋 *Payslip Details:*\n" .
           "Gross: *Rs. " . number_format($row['gross_earnings'] ?? 0, 2) . "*\n" .
           "Deductions: *Rs. " . number_format($row['total_deductions'] ?? 0, 2) . "*\n" .
           "*Net Pay: Rs. " . number_format($row['net_pay'] ?? 0, 2) . "*\n\n" .
           "Login to HRMS portal for detailed payslip.\n\n" .
           "_RCS TRUE FACILITIES PVT LTD_";

    $messages[] = [
        'number'      => $mobile,
        'message'     => $msg,
        'employee_id' => (int)$row['employee_db_id'],
    ];
}

if (empty($messages)) {
    echo json_encode(['success' => false, 'message' => "No employees with valid mobile numbers found. ($skippedNoMobile skipped)"]);
    exit;
}

// Enqueue into the persistent DB queue. The bot's single worker drains these
// at the configured conservative pace (interval_min_s .. interval_max_s,
// batch_limit + batch_cooldown_s, day_limit cap). Returns immediately.
$res = waQueueEnqueue($messages);

$total = count($messages);
$queued = (int)($res['queued'] ?? 0);
$skippedDup = (int)($res['skipped'] ?? 0);  // duplicates skipped by message_hash

$summary = "Queued {$queued}/{$total} salary messages for gradual delivery";
if ($skippedDup > 0) {
    $summary .= " ({$skippedDup} duplicate skipped)";
}
if ($skippedNoMobile > 0) {
    $summary .= " ({$skippedNoMobile} no mobile)";
}
if (!empty($res['campaign_id'])) {
    $summary .= " — campaign: " . $res['campaign_id'];
}

echo json_encode([
    'success'      => $res['success'],
    'message'      => $summary,
    'sent'         => 0,                  // never claim sends here — status becomes 'sent' only when the bot confirms
    'failed'       => 0,
    'queued'       => $queued,
    'skipped'      => $skippedNoMobile + $skippedDup,
    'campaign_id'  => $res['campaign_id'] ?? null,
]);
