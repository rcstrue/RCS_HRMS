<?php
/**
 * RCS HRMS Pro - ESS Profile Update API
 * Updates allowed fields on the employee record (whitelist-based).
 *
 * PUT: { employee_id: 123, fields: { email: '...', blood_group: '...', ... } }
 *
 * Requires ESS portal session (employee_portal) or admin session.
 */

// ── CORS headers (ESS mobile app) ───────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: PUT, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// Only PUT allowed
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'PUT' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use PUT or POST.']);
    exit;
}

// ── Bootstrap ───────────────────────────────────────────────────────────────
define('RCS_HRMS', true);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/database.php';

// ── Session check (ESS portal or admin) ─────────────────────────────────────
$isEss   = isset($_SESSION['employee_portal']) && $_SESSION['employee_portal']['logged_in'];
$isAdmin = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

if (!$isEss && !$isAdmin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

// ── Whitelist of updatable fields ───────────────────────────────────────────
$ALLOWED_FIELDS = [
    'email',
    'blood_group',
    'marital_status',
    'profile_pic_url',
    'address',
    'pin_code',
    'district',
    'state',
    'emergency_contact_name',
    'emergency_contact_relation',
    'nominee_name',
    'nominee_relationship',
    'nominee_dob',
    'nominee_contact',
];

try {
    $db = Database::getInstance();

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST; // fallback for form-encoded
    }
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request body. Expected JSON.']);
        exit;
    }

    $employeeId = (int)($input['employee_id'] ?? 0);
    $fields     = $input['fields'] ?? [];

    if ($employeeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'employee_id is required.']);
        exit;
    }

    if (!is_array($fields) || empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'fields object is required and must be non-empty.']);
        exit;
    }

    // Non-admin users can only update their own profile
    if (!$isAdmin) {
        $sessionEmpId = (int)$_SESSION['employee_portal']['employee_id'];
        if ($employeeId !== $sessionEmpId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied. You can only update your own profile.']);
            exit;
        }
    }

    // ── Filter to whitelist only ─────────────────────────────────────────────
    $rejectedFields = [];
    $updateData     = [];

    foreach ($fields as $key => $value) {
        if (in_array($key, $ALLOWED_FIELDS, true)) {
            $updateData[$key] = sanitize($value);
        } else {
            $rejectedFields[] = $key;
        }
    }

    if (empty($updateData)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'  => 'No valid fields to update.',
            'rejected_fields' => $rejectedFields,
        ]);
        exit;
    }

    // Add updated_at timestamp
    $updateData['updated_at'] = date('Y-m-d H:i:s');

    // ── Perform update ────────────────────────────────────────────────────────
    $rowsAffected = $db->update(
        'employees',
        $updateData,
        'id = :emp_id',
        ['emp_id' => $employeeId]
    );

    if ($rowsAffected === false || $rowsAffected < 1) {
        // Check if employee exists
        $exists = $db->fetchColumn("SELECT COUNT(*) FROM employees WHERE id = ?", [$employeeId]);
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Employee not found.']);
            exit;
        }
        // Data was same as existing — still a success
    }

    $response = [
        'success'           => true,
        'message'           => 'Profile updated successfully.',
        'updated_fields'    => array_keys($updateData),
        'updated_employee_id' => $employeeId,
    ];

    if (!empty($rejectedFields)) {
        $response['rejected_fields'] = $rejectedFields;
        $response['warning'] = 'Some fields were rejected because they are not in the allowed list.';
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
    ]);
}
