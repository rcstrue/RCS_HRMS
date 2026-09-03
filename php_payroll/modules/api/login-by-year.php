<?php
/**
 * RCS HRMS Pro - Login by Birth Year (ESS Mobile App)
 * Public endpoint — no admin session required.
 *
 * POST: { mobile_number: "9876543210", birth_year: "1990" }
 *
 * Finds employee by mobile_number, extracts year from date_of_birth,
 * and compares with the submitted birth_year.
 *
 * Route (standalone): Called directly, not through index.php router.
 */

// ── CORS headers (ESS mobile app) ───────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Bootstrap ───────────────────────────────────────────────────────────────
define('RCS_HRMS', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $db = Database::getInstance();

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request body. Expected JSON.']);
        exit;
    }

    $mobileNumber = sanitize($input['mobile_number'] ?? '');
    $birthYear    = sanitize($input['birth_year'] ?? '');

    // Validate inputs
    if (empty($mobileNumber)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'mobile_number is required.']);
        exit;
    }
    if (empty($birthYear) || !preg_match('/^\d{4}$/', $birthYear)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'birth_year must be a 4-digit year (e.g. 1990).']);
        exit;
    }

    // Find employee by mobile number — same query as portal/login.php
    $employee = $db->fetch(
        "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.mobile_number,
                e.email, e.designation, e.department, e.date_of_joining,
                e.worker_category, e.status, e.profile_pic_url,
                e.uan_number, e.esic_number, e.date_of_birth,
                c.name as client_name,
                u.name as unit_name,
                ess.basic_da, ess.hra, ess.gross_salary
         FROM employees e
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id
             AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.mobile_number = :mobile_number
           AND e.status = 'approved'
         LIMIT 1",
        ['mobile_number' => $mobileNumber]
    );

    if (!$employee) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No approved employee found with this mobile number.']);
        exit;
    }

    // Extract year from date_of_birth
    $dobYear = null;
    if (!empty($employee['date_of_birth']) && $employee['date_of_birth'] !== '0000-00-00') {
        $dobYear = date('Y', strtotime($employee['date_of_birth']));
    }

    if ($dobYear !== $birthYear) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Birth year does not match our records.']);
        exit;
    }

    // ── Set up ESS session ──────────────────────────────────────────────────
    session_regenerate_id(true);

    $_SESSION['employee_portal'] = [
        'logged_in'     => true,
        'employee_id'   => $employee['id'],
        'employee_code' => $employee['employee_code'],
        'full_name'     => $employee['full_name'],
        'designation'   => $employee['designation'],
        'client_name'   => $employee['client_name'],
        'unit_name'     => $employee['unit_name'],
        'photo_path'    => $employee['profile_pic_url'],
        'login_time'    => time(),
    ];

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $db->insert('audit_log', [
            'user_id'    => $employee['id'],
            'action'     => 'ess_login_by_year',
            'details'    => json_encode([
                'employee_code' => $employee['employee_code'],
                'ip'            => $ip,
            ]),
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $logEx) {
        error_log('ESS login-by-year audit_log insert failed: ' . $logEx->getMessage());
    }

    // Return same shape as portal login session data + full employee row
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data'    => [
            'employee_id'   => (int)$employee['id'],
            'employee_code' => $employee['employee_code'],
            'full_name'     => $employee['full_name'],
            'father_name'   => $employee['father_name'],
            'mobile_number' => $employee['mobile_number'],
            'email'         => $employee['email'],
            'designation'   => $employee['designation'],
            'department'    => $employee['department'],
            'date_of_joining' => $employee['date_of_joining'],
            'worker_category' => $employee['worker_category'],
            'profile_pic_url' => $employee['profile_pic_url'],
            'client_name'   => $employee['client_name'],
            'unit_name'     => $employee['unit_name'],
            'basic_da'      => $employee['basic_da'],
            'hra'           => $employee['hra'],
            'gross_salary'  => $employee['gross_salary'],
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
    ]);
}
