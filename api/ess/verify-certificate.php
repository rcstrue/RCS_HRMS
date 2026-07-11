<?php
/**
 * Public Certificate Verification API
 *
 * GET /api/ess/verify-certificate.php?cert=XXXXX
 *
 * NO AUTH REQUIRED — this is a public endpoint for QR code scanning.
 * Only returns safe, non-sensitive employee information.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$code = $_GET['cert'] ?? '';

if (empty($code)) {
    jsonError('Missing verification code', 400);
}

// Sanitize — only allow hex chars
if (!preg_match('/^[a-f0-9]{32}$/', $code)) {
    jsonError('Invalid verification code format', 400);
}

$conn = getDbConnection();

$stmt = $conn->prepare('
    SELECT cv.*,
           e.status AS employee_current_status
    FROM certificate_verifications cv
    JOIN employees e ON e.id = cv.employee_id
    WHERE cv.verification_code = ?
    LIMIT 1
');
$stmt->bind_param('s', $code);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    jsonError('Certificate not found. This may be an invalid or expired verification link.', 404);
}

$snapshot = json_decode($row['employee_snapshot'], true);
$isEmployeeStillActive = in_array($row['employee_current_status'], ['approved', 'active']);

jsonSuccess([
    'certificate_type'   => $row['certificate_type'],
    'certificate_number' => $row['certificate_number'],
    'issued_at'          => $row['issued_at'],
    'is_valid'           => $isEmployeeStillActive,

    // Safe employee details from snapshot
    'employee' => [
        'employee_code'  => $snapshot['employee_code'] ?? '',
        'full_name'      => $snapshot['full_name'] ?? '',
        'designation'    => $snapshot['designation'] ?? '',
        'department'     => $snapshot['department'] ?? '',
        'client_name'    => $snapshot['client_name'] ?? '',
        'unit_name'      => $snapshot['unit_name'] ?? '',
        'date_of_joining'=> $snapshot['date_of_joining'] ?? '',
        'gender'         => $snapshot['gender'] ?? '',
    ],
]);