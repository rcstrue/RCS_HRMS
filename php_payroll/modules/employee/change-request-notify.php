<?php
/**
 * RCS HRMS Pro — Async Change Request Notification Handler
 *
 * This file runs in the background (fire-and-forget via curl from change-requests.php).
 * It sends email + WhatsApp notifications without blocking the approve/reject action.
 *
 * Called via: POST with JSON body { emp_name, email, mobile, field, newValue, oldValue, reason, type }
 */

// Only allow async calls
if (!isset($_SERVER['HTTP_X_ASYNC_NOTIFY'])) {
    http_response_code(403);
    exit('Forbidden');
}

// Disable output buffering and time limits for background processing
while (ob_get_level()) ob_end_clean();
set_time_limit(30);
ignore_user_abort(true);

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    exit('No data');
}

// Bootstrap DB (minimal)
$baseDir = dirname(__DIR__, 3);
$configFile = $baseDir . '/config/config.php';
$dbFile    = $baseDir . '/includes/class.database.php';

if (!file_exists($configFile) || !file_exists($dbFile)) {
    error_log('[change-request-notify] Missing config/db files');
    exit('Config missing');
}

// Load DB config constants
require_once $configFile;
require_once $dbFile;

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log('[change-request-notify] DB connection failed: ' . $e->getMessage());
    exit('DB error');
}

// Load Notification class
$notifFile = $baseDir . '/includes/class.notification.php';
if (!file_exists($notifFile)) {
    error_log('[change-request-notify] Notification class not found');
    exit('Notif missing');
}

require_once $notifFile;

$empName = $input['emp_name'] ?? 'Employee';
$email   = $input['email'] ?? '';
$mobile  = $input['mobile'] ?? '';
$field   = $input['field'] ?? 'Unknown';
$newVal  = $input['newValue'] ?? '';
$oldVal  = $input['oldValue'] ?? '';
$reason  = $input['reason'] ?? '';
$type    = $input['type'] ?? 'approved';

$fieldLabel = ucfirst(str_replace('_', ' ', $field));

$isApproved = ($type === 'approved');

$color     = $isApproved ? '#10b981' : '#ef4444';
$statusTxt = $isApproved ? 'APPROVED' : 'REJECTED';
$subject   = "Change Request {$statusTxt} — {$fieldLabel}";

// Build email body
$htmlBody = "
<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
    <div style='background:{$color};padding:20px;text-align:center;'>
        <h2 style='color:#fff;margin:0;'>RCS True Facilities Pvt Ltd</h2>
    </div>
    <div style='padding:25px;background:#f9fafb;border:1px solid #e5e7eb;'>
        <p>Dear {$empName},</p>
        <p>Your change request has been <strong style='color:{$color};'>{$statusTxt}</strong>.</p>
        <table style='width:100%;border-collapse:collapse;margin:15px 0;'>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Field</td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$fieldLabel}</td></tr>";

if (!$isApproved) {
    $htmlBody .= "
            <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Requested Value</td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($newVal) . "</td></tr>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Rejection Reason</td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($reason) . "</td></tr>";
} else {
    $htmlBody .= "
            <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>Old Value</td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($oldVal) . "</td></tr>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;background:#f3f4f6;font-weight:bold;'>New Value</td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>" . htmlspecialchars($newVal) . "</td></tr>";
}

$htmlBody .= "
        </table>
        <p>" . ($isApproved ? 'If you did not request this change, please contact HR immediately.' : 'If you believe this was an error, please contact HR.') . "</p>
    </div>
    <p style='text-align:center;color:#9ca3af;font-size:12px;'>This is an automated message from RCS HRMS Pro.</p>
</div>";

// Build WhatsApp message
$waMsg = "Hello {$empName},\n\nYour change request has been {$statusTxt}.\n\nField: {$fieldLabel}";
if ($isApproved) {
    $waMsg .= "\nNew Value: {$newVal}";
} else {
    $waMsg .= "\nReason: {$reason}";
}
$waMsg .= "\n\n- RCS HRMS Pro";

// Send notifications (with short timeouts)
try {
    $notif = new Notification();

    // Email — fast
    if (!empty($email)) {
        $notif->sendEmail($email, $subject, $htmlBody);
    }

    // WhatsApp — 5 second max
    if (!empty($mobile)) {
        $notif->sendWhatsApp($mobile, $waMsg);
    }
} catch (Exception $e) {
    error_log('[change-request-notify] Notification error: ' . $e->getMessage());
}

exit('OK');
