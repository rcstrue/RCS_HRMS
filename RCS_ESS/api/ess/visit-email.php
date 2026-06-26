<?php
/**
 * ESS API — Unit Visit Email Report Endpoint
 * POST:  Send a unit visit checklist report via email
 *
 * This endpoint generates an HTML email report for a visit and sends it to
 * the employee's registered email address. It can also be used standalone.
 *
 * Body: { "visit_id": <int>, "recipient_email"?: <string> }
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'POST':
            _handlePost();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    jsonOutput(['success' => false, 'error' => 'Server error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
}

// ══════════════════════════════════════════════════════════════════════════
// POST Handler
// ══════════════════════════════════════════════════════════════════════════

function _handlePost(): void
{
    $authId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    $visitId = (int)($input['visit_id'] ?? 0);
    if ($visitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Visit ID is required'], 400);
    }

    // Fetch visit with full details
    $stmt = $conn->prepare("
        SELECT v.*, u.name AS unit_name, c.name AS client_name,
               e.full_name AS employee_name, e.employee_code, e.email AS employee_email,
               e.mobile_number AS employee_mobile
        FROM ess_unit_visits v
        LEFT JOIN units u ON u.id = v.unit_id
        LEFT JOIN clients c ON c.id = u.client_id
        LEFT JOIN employees e ON e.id = v.employee_id
        WHERE v.id = ?
    ");
    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$visit) {
        $conn->close();
        jsonOutput(['success' => false, 'error' => 'Visit not found'], 404);
    }

    // Permission check: own visit or approver
    if ((string)$visit['employee_id'] !== (string)$authId) {
        // Check if requester is an approver (manager+)
        $role = getEmployeeRole($conn, $authId);
        $allowedRoles = ['admin', 'regional_manager', 'manager', 'field_officer'];
        if (!in_array($role, $allowedRoles)) {
            $conn->close();
            jsonOutput(['success' => false, 'error' => 'Access denied'], 403);
        }
    }

    // Determine recipient
    $recipientEmail = trim($input['recipient_email'] ?? '');
    if (empty($recipientEmail)) {
        $recipientEmail = $visit['employee_email'] ?? '';
    }
    if (empty($recipientEmail)) {
        $conn->close();
        jsonOutput(['success' => false, 'error' => 'No email address on file for this employee'], 400);
    }

    // Fetch checklist items grouped by category
    $itemsStmt = $conn->prepare('
        SELECT ci.id, ci.checklist_item_id, ci.category_id, ci.status, ci.remarks, ci.photo_url,
               cmi.name AS item_name, cmc.name AS category_name
        FROM ess_visit_checklist_items ci
        LEFT JOIN ess_checklist_items cmi ON cmi.id = ci.checklist_item_id
        LEFT JOIN ess_checklist_categories cmc ON cmc.id = ci.category_id
        WHERE ci.visit_id = ?
        ORDER BY cmc.display_order ASC, cmi.display_order ASC, ci.id ASC
    ');
    $itemsStmt->bind_param('i', $visitId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $checklistItems = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $checklistItems[] = $row;
    }
    $itemsResult->free();
    $itemsStmt->close();
    $conn->close();

    // Build email content
    $subject = sprintf(
        'Unit Visit Report: %s - %s %s (%d%%)',
        $visit['unit_name'] ?? 'Unknown Unit',
        _monthName((int)$visit['visit_month']),
        $visit['visit_year'],
        (int)($visit['score_percent'] ?? 0)
    );

    $htmlBody = _buildEmailHTML($visit, $checklistItems);
    $textBody = _buildEmailText($visit, $checklistItems);

    // Send email
    $sent = _sendEmail($recipientEmail, $subject, $htmlBody, $textBody);

    if (!$sent) {
        jsonOutput(['success' => false, 'error' => 'Failed to send email. Please try again later.'], 500);
    }

    // Log audit
    // (We'd need a new connection for this, skip for now since unit-visits.php already logs)
    jsonOutput([
        'success' => true,
        'message' => 'Report email sent to ' . htmlspecialchars($recipientEmail),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════
// Email HTML Builder
// ══════════════════════════════════════════════════════════════════════════

function _buildEmailHTML(array $visit, array $items): string
{
    $score = (int)($visit['score_percent'] ?? 0);
    $totalScore = (float)($visit['total_score'] ?? 0);
    $maxScore = (float)($visit['max_score'] ?? 0);
    $scoreColor = $score >= 80 ? '#059669' : ($score >= 60 ? '#d97706' : '#dc2626');
    $scoreLabel = $score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : ($score >= 40 ? 'Average' : 'Poor'));

    // Group items by category
    $grouped = [];
    foreach ($items as $item) {
        $catId = (int)$item['category_id'];
        if (!isset($grouped[$catId])) {
            $grouped[$catId] = [
                'name' => $item['category_name'] ?? 'Unknown Category',
                'items' => [],
            ];
        }
        $grouped[$catId]['items'][] = $item;
    }

    // Build category sections
    $categoryHTML = '';
    foreach ($grouped as $cat) {
        $catYes = 0;
        $catTotal = 0;
        $rowsHTML = '';

        foreach ($cat['items'] as $item) {
            $isNa = $item['status'] === 'na';
            $isYes = $item['status'] === 'yes';
            if (!$isNa) {
                $catTotal++;
                if ($isYes) $catYes++;
            }

            $statusIcon = $isNa
                ? '<span style="color:#9CA3AF;font-weight:600">N/A</span>'
                : ($isYes
                    ? '<span style="color:#059669;font-weight:700">&#10003; Yes</span>'
                    : '<span style="color:#dc2626;font-weight:700">&#10007; No</span>');

            $remarksCell = !empty($item['remarks'])
                ? '<td style="padding:6px 8px;font-size:12px;color:#6B7280;border-bottom:1px solid #E5E7EB">' . htmlspecialchars($item['remarks']) . '</td>'
                : '<td style="padding:6px 8px;border-bottom:1px solid #E5E7EB"></td>';

            $rowsHTML .= '<tr>
                <td style="padding:6px 8px;font-size:12px;color:#374151;border-bottom:1px solid #E5E7EB">' . htmlspecialchars($item['item_name'] ?? '') . '</td>
                <td style="padding:6px 8px;text-align:center;border-bottom:1px solid #E5E7EB;width:80px">' . $statusIcon . '</td>
                ' . $remarksCell . '
            </tr>';
        }

        $catPct = $catTotal > 0 ? round(($catYes / $catTotal) * 100) : 0;
        $catColor = $catPct >= 80 ? '#059669' : ($catPct >= 60 ? '#d97706' : '#dc2626');

        $categoryHTML .= '
            <div style="margin-bottom:16px">
                <div style="display:flex;align-items:center;justify-content:space-between;background:#F9FAFB;padding:8px 12px;border:1px solid #E5E7EB;border-radius:6px 6px 0 0">
                    <span style="font-size:13px;font-weight:700;color:#374151">' . htmlspecialchars($cat['name']) . '</span>
                    <span style="font-size:12px;font-weight:600;color:' . $catColor . '">' . $catPct . '% (' . $catYes . '/' . $catTotal . ')</span>
                </div>
                <table style="width:100%;border-collapse:collapse;border:1px solid #E5E7EB;border-top:none">
                    <thead>
                        <tr style="background:#F3F4F6">
                            <th style="padding:6px 8px;text-align:left;font-size:10px;font-weight:600;color:#6B7280;text-transform:uppercase">Item</th>
                            <th style="padding:6px 8px;text-align:center;font-size:10px;font-weight:600;color:#6B7280;text-transform:uppercase;width:80px">Status</th>
                            <th style="padding:6px 8px;text-align:left;font-size:10px;font-weight:600;color:#6B7280;text-transform:uppercase">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHTML . '</tbody>
                </table>
            </div>';
    }

    $notesSection = '';
    if (!empty($visit['notes'])) {
        $notesSection = '
            <div style="padding:12px 18px;background:#FFFBEB;border-top:1px solid #E5E7EB">
                <h3 style="font-size:12px;font-weight:700;color:#92400E;text-transform:uppercase;margin-bottom:4px">General Notes</h3>
                <p style="font-size:12px;color:#78350F">' . htmlspecialchars($visit['notes']) . '</p>
            </div>';
    }

    $rejectionSection = '';
    if (!empty($visit['rejection_reason'])) {
        $rejectionSection = '
            <div style="padding:12px 18px;background:#FEF2F2;border-top:1px solid #FECACA">
                <h3 style="font-size:12px;font-weight:700;color:#991B1B;text-transform:uppercase;margin-bottom:4px">Rejection Reason</h3>
                <p style="font-size:12px;color:#7F1D1D">' . htmlspecialchars($visit['rejection_reason']) . '</p>
            </div>';
    }

    $submittedDate = !empty($visit['created_at'])
        ? date('d M Y, h:i A', strtotime($visit['created_at']))
        : 'N/A';

    $visitLabel = (int)$visit['visit_number'] === 1 ? 'First' : 'Second';
    $monthName = _monthName((int)$visit['visit_month']);

    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>' . htmlspecialchars($subject ?? 'Unit Visit Report') . '</title></head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:Arial,Helvetica,sans-serif">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;padding:20px 0">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
                <!-- Header -->
                <tr>
                    <td style="background:#059669;padding:16px 20px">
                        <h1 style="margin:0;font-size:18px;color:#FFFFFF;font-weight:700">Unit Visit Checklist Report</h1>
                        <p style="margin:4px 0 0;font-size:11px;color:rgba(255,255,255,0.8)">RCS TRUE FACILITIES PVT LTD</p>
                    </td>
                    <td style="background:#059669;padding:16px 20px;text-align:right">
                        <p style="margin:0;font-size:14px;color:#FFFFFF;font-weight:600">' . htmlspecialchars($monthName . ' ' . $visit['visit_year']) . '</p>
                        <p style="margin:4px 0 0;font-size:10px;color:rgba(255,255,255,0.7)">' . $visitLabel . ' Visit</p>
                    </td>
                </tr>

                <!-- Info Bar -->
                <tr>
                    <td colspan="2" style="background:#F9FAFB;padding:12px 20px;border-bottom:1px solid #E5E7EB">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="width:25%">
                                    <p style="margin:0;font-size:9px;color:#9CA3AF;text-transform:uppercase;font-weight:600">Employee</p>
                                    <p style="margin:2px 0 0;font-size:12px;color:#374151;font-weight:600">' . htmlspecialchars($visit['employee_name'] ?? '') . '</p>
                                </td>
                                <td style="width:25%">
                                    <p style="margin:0;font-size:9px;color:#9CA3AF;text-transform:uppercase;font-weight:600">Client / Unit</p>
                                    <p style="margin:2px 0 0;font-size:12px;color:#374151;font-weight:600">' . htmlspecialchars(($visit['client_name'] ?? '') . ' / ' . ($visit['unit_name'] ?? '')) . '</p>
                                </td>
                                <td style="width:25%">
                                    <p style="margin:0;font-size:9px;color:#9CA3AF;text-transform:uppercase;font-weight:600">Status</p>
                                    <p style="margin:2px 0 0;font-size:12px;color:#374151;font-weight:600;text-transform:capitalize">' . htmlspecialchars($visit['status'] ?? 'submitted') . '</p>
                                </td>
                                <td style="width:25%">
                                    <p style="margin:0;font-size:9px;color:#9CA3AF;text-transform:uppercase;font-weight:600">Submitted</p>
                                    <p style="margin:2px 0 0;font-size:12px;color:#374151;font-weight:600">' . $submittedDate . '</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Score Bar -->
                <tr>
                    <td colspan="2" style="padding:16px 20px;border-bottom:1px solid #E5E7EB">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="80" valign="top">
                                    <div style="width:70px;height:70px;border-radius:50%;border:4px solid ' . $scoreColor . ';text-align:center;line-height:62px">
                                        <span style="font-size:22px;font-weight:800;color:' . $scoreColor . '">' . $score . '%</span>
                                    </div>
                                </td>
                                <td valign="middle" style="padding-left:16px">
                                    <p style="margin:0;font-size:16px;font-weight:700;color:' . $scoreColor . '">' . $scoreLabel . '</p>
                                    <p style="margin:2px 0 0;font-size:12px;color:#6B7280">' . $totalScore . ' out of ' . $maxScore . ' points earned</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Checklist Content -->
                <tr>
                    <td colspan="2" style="padding:16px 20px">
                        ' . $categoryHTML . '
                    </td>
                </tr>

                ' . ($notesSection ? '<tr><td colspan="2">' . $notesSection . '</td></tr>' : '') . '
                ' . ($rejectionSection ? '<tr><td colspan="2">' . $rejectionSection . '</td></tr>' : '') . '

                <!-- Footer -->
                <tr>
                    <td colspan="2" style="padding:12px 20px;border-top:1px solid #E5E7EB;text-align:center">
                        <p style="margin:0;font-size:10px;color:#9CA3AF;font-style:italic">This is a computer generated report from RCS ESS Portal.</p>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>
</body>
</html>';
}

// ══════════════════════════════════════════════════════════════════════════
// Plain Text Fallback
// ══════════════════════════════════════════════════════════════════════════

function _buildEmailText(array $visit, array $items): string
{
    $score = (int)($visit['score_percent'] ?? 0);
    $visitLabel = (int)$visit['visit_number'] === 1 ? 'First' : 'Second';
    $monthName = _monthName((int)$visit['visit_month']);

    $text = "UNIT VISIT CHECKLIST REPORT\n";
    $text .= "RCS TRUE FACILITIES PVT LTD\n";
    $text .= str_repeat('=', 50) . "\n\n";
    $text .= "Employee: " . ($visit['employee_name'] ?? '') . "\n";
    $text .= "Client/Unit: " . ($visit['client_name'] ?? '') . " / " . ($visit['unit_name'] ?? '') . "\n";
    $text .= "Visit: {$visitLabel} Visit - {$monthName} " . $visit['visit_year'] . "\n";
    $text .= "Score: {$score}% (" . ($visit['total_score'] ?? 0) . "/" . ($visit['max_score'] ?? 0) . " points)\n";
    $text .= "Status: " . ucfirst($visit['status'] ?? 'submitted') . "\n";
    $text .= "Submitted: " . ($visit['created_at'] ?? 'N/A') . "\n\n";

    // Group by category
    $grouped = [];
    foreach ($items as $item) {
        $catId = (int)$item['category_id'];
        if (!isset($grouped[$catId])) {
            $grouped[$catId] = [
                'name' => $item['category_name'] ?? 'Unknown',
                'items' => [],
            ];
        }
        $grouped[$catId]['items'][] = $item;
    }

    foreach ($grouped as $cat) {
        $text .= "\n--- " . strtoupper($cat['name']) . " ---\n";
        foreach ($cat['items'] as $item) {
            $status = $item['status'] === 'na' ? 'N/A' : ($item['status'] === 'yes' ? '[YES]' : '[NO]');
            $line = "  {$status} " . ($item['item_name'] ?? '');
            if (!empty($item['remarks'])) {
                $line .= " - " . $item['remarks'];
            }
            $text .= $line . "\n";
        }
    }

    if (!empty($visit['notes'])) {
        $text .= "\nGENERAL NOTES:\n" . $visit['notes'] . "\n";
    }
    if (!empty($visit['rejection_reason'])) {
        $text .= "\nREJECTION REASON:\n" . $visit['rejection_reason'] . "\n";
    }

    $text .= "\n" . str_repeat('=', 50) . "\n";
    $text .= "This is a computer generated report from RCS ESS Portal.\n";

    return $text;
}

// ══════════════════════════════════════════════════════════════════════════
// Email Sender
// ══════════════════════════════════════════════════════════════════════════

function _sendEmail(string $to, string $subject, string $htmlBody, string $textBody): bool
{
    // Boundary for multipart message
    $boundary = '===VisitReport_' . md5(uniqid((string)time(), true)) . '===';

    // Build multipart/alternative email
    $headers  = "From: RCS ESS Portal <noreply@rcsfacility.com>\r\n";
    $headers .= "Reply-To: support@rcsfacility.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: RCS-ESS-Portal/2.0\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($textBody));

    $message .= "\r\n--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($htmlBody));

    $message .= "\r\n--{$boundary}--\r\n";

    return mail($to, $subject, $message, $headers, '-fnoreply@rcsfacility.com');
}

// ══════════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════════

function _monthName(int $month): string
{
    $names = ['', 'January', 'February', 'March', 'April', 'May', 'June',
              'July', 'August', 'September', 'October', 'November', 'December'];
    return $names[$month] ?? '';
}