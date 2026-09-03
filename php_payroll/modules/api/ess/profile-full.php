<?php
/**
 * RCS HRMS Pro - ESS Full Profile API
 * Returns the COMPLETE employee record with JOINs.
 *
 * GET: ?employee_id=123
 *
 * Requires ESS portal session (employee_portal) or admin session.
 */

// ── CORS headers (ESS mobile app) ───────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

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

try {
    $db = Database::getInstance();

    // Determine employee_id: from query param or from session
    $employeeId = (int)($_GET['employee_id'] ?? 0);

    // Non-admin users can only view their own profile
    if (!$isAdmin) {
        $sessionEmpId = (int)$_SESSION['employee_portal']['employee_id'];
        if ($employeeId > 0 && $employeeId !== $sessionEmpId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied. You can only view your own profile.']);
            exit;
        }
        $employeeId = $sessionEmpId;
    }

    if ($employeeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'employee_id is required.']);
        exit;
    }

    // ── Full employee record with JOINs ──────────────────────────────────────
    $employee = $db->fetch(
        "SELECT e.*,
                c.name AS client_name,
                u.name AS unit_name,
                ess.basic_da, ess.hra, ess.gross_salary,
                ess.leave_encashment, ess.bonus_encashment, ess.washing_allowance,
                ess.other_allowance, ess.effective_from
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u   ON e.unit_id = u.id
         LEFT JOIN employee_salary_structures ess
                ON e.id = ess.employee_id
               AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE e.id = :id
         LIMIT 1",
        ['id' => $employeeId]
    );

    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Employee not found.']);
        exit;
    }

    // ── Related data ─────────────────────────────────────────────────────────

    // Bank details (from employee row — bank fields are columns on employees)
    $bankDetails = [
        'bank_name'            => $employee['bank_name'] ?? '',
        'bank_account_number'  => $employee['bank_account_number'] ?? $employee['account_number'] ?? '',
        'bank_ifsc_code'       => $employee['bank_ifsc_code'] ?? $employee['ifsc_code'] ?? '',
        'bank_branch'          => $employee['bank_branch'] ?? '',
    ];

    // Nominee details (from employee row)
    $nomineeDetails = [
        'nominee_name'       => $employee['nominee_name'] ?? '',
        'nominee_relationship' => $employee['nominee_relationship'] ?? '',
        'nominee_dob'        => $employee['nominee_dob'] ?? '',
        'nominee_contact'    => $employee['nominee_contact'] ?? '',
    ];

    // Emergency contact (from employee row)
    $emergencyContact = [
        'emergency_contact_name'     => $employee['emergency_contact_name'] ?? '',
        'emergency_contact_number'   => $employee['emergency_contact_number'] ?? '',
        'emergency_contact_relation' => $employee['emergency_contact_relation'] ?? '',
    ];

    // Documents (separate table)
    $documents = $db->fetchAll(
        "SELECT id, document_type, file_path, uploaded_at, created_at
         FROM employee_documents
         WHERE employee_id = :id
         ORDER BY created_at DESC",
        ['id' => $employeeId]
    );

    // Family members (separate table)
    $familyMembers = $db->fetchAll(
        "SELECT * FROM employee_family WHERE employee_id = :id ORDER BY id",
        ['id' => $employeeId]
    );

    // ── Build response (exclude sensitive internal columns) ──────────────────
    $response = [
        'id'                      => (int)$employee['id'],
        'employee_code'           => $employee['employee_code'],
        'full_name'               => $employee['full_name'],
        'father_name'             => $employee['father_name'] ?? '',
        'mother_name'             => $employee['mother_name'] ?? '',
        'date_of_birth'           => $employee['date_of_birth'],
        'gender'                  => $employee['gender'] ?? '',
        'blood_group'             => $employee['blood_group'] ?? '',
        'marital_status'          => $employee['marital_status'] ?? '',
        'mobile_number'           => $employee['mobile_number'],
        'alternate_mobile'        => $employee['alternate_mobile'] ?? '',
        'email'                   => $employee['email'] ?? '',
        'aadhaar_number'          => $employee['aadhaar_number'] ?? '',
        'pan_number'              => $employee['pan_number'] ?? '',
        'uan_number'              => $employee['uan_number'] ?? '',
        'esic_number'             => $employee['esic_number'] ?? '',
        'pf_number'               => $employee['pf_number'] ?? '',
        'designation'             => $employee['designation'] ?? '',
        'department'              => $employee['department'] ?? '',
        'worker_category'         => $employee['worker_category'] ?? '',
        'date_of_joining'         => $employee['date_of_joining'],
        'date_of_leaving'         => $employee['date_of_leaving'] ?? '',
        'status'                  => $employee['status'],
        'profile_pic_url'         => $employee['profile_pic_url'] ?? '',
        'address'                 => $employee['address'] ?? '',
        'pin_code'                => $employee['pin_code'] ?? '',
        'district'                => $employee['district'] ?? '',
        'state'                   => $employee['state'] ?? '',
        'client_id'               => $employee['client_id'] ? (int)$employee['client_id'] : null,
        'client_name'             => $employee['client_name'] ?? '',
        'unit_id'                 => $employee['unit_id'] ? (int)$employee['unit_id'] : null,
        'unit_name'               => $employee['unit_name'] ?? '',
        // Salary structure
        'basic_da'                => $employee['basic_da'],
        'hra'                     => $employee['hra'],
        'gross_salary'            => $employee['gross_salary'],
        'leave_encashment'        => $employee['leave_encashment'],
        'bonus_encashment'        => $employee['bonus_encashment'],
        'washing_allowance'       => $employee['washing_allowance'],
        'other_allowance'         => $employee['other_allowance'],
        'salary_effective_from'   => $employee['effective_from'],
    ];

    echo json_encode([
        'success'           => true,
        'data'              => $response,
        'bank_details'      => $bankDetails,
        'nominee_details'   => $nomineeDetails,
        'emergency_contact' => $emergencyContact,
        'documents'         => $documents,
        'family_members'    => $familyMembers,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
    ]);
}
