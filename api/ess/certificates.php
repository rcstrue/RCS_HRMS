<?php
/**
 * ESS Certificates API
 *
 * GET  /api/ess/certificates              — list available certificate types
 * POST /api/ess/certificates?type=X       — generate verification code + return data for PDF
 *
 * Security: Always uses JWT-authenticated employee_id.
 *           No URL/POST parameter can override which employee's data is returned.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$authId = requireAuth();   // returns employee_id from JWT, exits 401 on failure
$conn   = getDbConnection();

// ─── GET: Return available certificate types + employee status ─────────────────
if ($method === 'GET') {
    _getCertificateList($conn, $authId);
    $conn->close();
    exit;
}

// ─── POST: Generate verification record + return full certificate data ─────────
if ($method === 'POST') {
    $input = getInput();
    $type  = $input['type'] ?? '';

    if (!in_array($type, ['appointment', 'salary', 'experience'])) {
        jsonError('Invalid certificate type. Must be: appointment, salary, or experience.');
    }

    _generateCertificate($conn, $authId, $type);
    $conn->close();
    exit;
}

jsonError('Method not allowed', 405);

// ═══════════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════════

function _getCertificateList(mysqli $conn, string $authId): void
{
    // Verify employee exists and is active
    $stmt = $conn->prepare('SELECT status FROM employees WHERE id = ?');
    $stmt->bind_param('s', $authId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonError('Employee record not found', 403);
    }

    $isActive = in_array($row['status'], ['approved', 'active']);

    // All 3 certificates available for active employees (per user requirement)
    $certificates = [];
    if ($isActive) {
        $certificates = [
            [
                'type'        => 'appointment',
                'name'        => 'Appointment Letter',
                'description' => 'Original appointment letter issued at the time of joining.',
                'available'   => true,
            ],
            [
                'type'        => 'salary',
                'name'        => 'Salary Certificate',
                'description' => 'Current salary certificate for official purposes.',
                'available'   => true,
            ],
            [
                'type'        => 'experience',
                'name'        => 'Experience Certificate',
                'description' => 'Certificate of experience and service tenure.',
                'available'   => true,
            ],
        ];
    }

    jsonSuccess([
        'certificates' => $certificates,
        'employee_status' => $row['status'],
    ]);
}

function _generateCertificate(mysqli $conn, string $authId, string $type): void
{
    // 1) Verify employee is active
    $stmt = $conn->prepare('
        SELECT e.*, c.name AS client_name, u.name AS unit_name
        FROM employees e
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE e.id = ? AND e.status IN ("approved","active")
    ');
    $stmt->bind_param('s', $authId);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$emp) {
        jsonError('Employee not found or not active. Certificates are only available for active employees.', 403);
    }

    // 2) Get company details
    $company = $conn->query('SELECT * FROM companies LIMIT 1')->fetch_assoc();

    // 3) Get salary structure
    $salary = null;
    $stmt = $conn->prepare('
        SELECT basic_da, hra, washing_allowance, gross_salary,
               pf_applicable, esi_applicable, pt_applicable
        FROM employee_salary_structures
        WHERE employee_id = ? AND effective_from <= CURDATE()
        ORDER BY effective_from DESC LIMIT 1
    ');
    $stmt->bind_param('s', $authId);
    $stmt->execute();
    $salary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 4) Get latest payroll for deductions (salary cert)
    $payroll = null;
    $stmt = $conn->prepare('
        SELECT p.* FROM payroll p
        WHERE p.employee_id = ?
        ORDER BY p.year DESC, p.month DESC LIMIT 1
    ');
    $stmt->bind_param('s', $authId);
    $stmt->execute();
    $payroll = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 5) Calculate tenure for experience certificate
    $tenure = '';
    if ($emp['date_of_joining']) {
        $doj = new DateTime($emp['date_of_joining']);
        $end = new DateTime();
        $diff = $doj->diff($end);
        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
        if ($diff->m > 0) $parts[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
        if ($diff->d > 0 && $diff->y === 0) $parts[] = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
        $tenure = implode(', ', $parts) ?: '0 days';
    }

    // 6) Build certificate number
    $empCode = str_pad($emp['employee_code'] ?? $authId, 4, '0', STR_PAD_LEFT);
    $year = date('Y');
    $prefix = strtoupper(substr($type, 0, 3));
    $certNumber = "RCS/{$prefix}/{$year}/{$empCode}";

    // 7) Generate verification code & store
    $verificationCode = bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');

    // Snapshot employee data (frozen at issue time)
    $snapshot = json_encode([
        'employee_code' => $emp['employee_code'],
        'full_name'     => $emp['full_name'],
        'father_name'   => $emp['father_name'],
        'gender'        => $emp['gender'],
        'designation'   => $emp['designation'],
        'department'    => $emp['department'],
        'client_name'   => $emp['client_name'],
        'unit_name'     => $emp['unit_name'],
        'date_of_joining' => $emp['date_of_joining'],
        'employee_type' => $emp['employment_type'],
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare('
        INSERT INTO certificate_verifications
            (verification_code, employee_id, certificate_type, certificate_number, issued_at, employee_snapshot)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('sissss',
        $verificationCode,
        $authId,
        $type,
        $certNumber,
        $now,
        $snapshot
    );
    $stmt->execute();
    $stmt->close();

    // 8) Return everything needed for PDF generation
    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                   7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

    jsonSuccess([
        'certificate_type'    => $type,
        'certificate_number'  => $certNumber,
        'date_of_issue'       => date('d/m/Y'),
        'verification_code'   => $verificationCode,
        'verify_url'          => 'https://join.rcsfacility.com/#verify?cert=' . $verificationCode,

        // Employee
        'employee' => [
            'employee_code'   => $emp['employee_code'],
            'full_name'       => $emp['full_name'],
            'father_name'     => $emp['father_name'] ?? '',
            'gender'          => $emp['gender'] ?? 'Male',
            'designation'     => $emp['designation'] ?? 'Worker',
            'department'      => $emp['department'] ?? '',
            'date_of_joining' => $emp['date_of_joining'],
            'probation_period'=> $emp['probation_period'] ?? 3,
            'address'         => $emp['address'] ?? '',
            'district'        => $emp['district'] ?? '',
            'state'           => $emp['state'] ?? '',
            'pin_code'        => $emp['pin_code'] ?? '',
            'uan_number'      => $emp['uan_number'] ?? '',
            'esic_number'     => $emp['esic_number'] ?? '',
        ],

        // Salary (for salary cert)
        'salary' => $salary ? [
            'basic_da'          => (float)($salary['basic_da'] ?? 0),
            'hra'               => (float)($salary['hra'] ?? 0),
            'washing_allowance' => (float)($salary['washing_allowance'] ?? 0),
            'gross_salary'      => (float)($salary['gross_salary'] ?? 0),
            'pf_applicable'     => (bool)($salary['pf_applicable'] ?? false),
            'esi_applicable'    => (bool)($salary['esi_applicable'] ?? false),
            'pt_applicable'     => (bool)($salary['pt_applicable'] ?? false),
        ] : null,

        // Payroll deductions (for salary cert)
        'payroll' => $payroll ? [
            'month'            => (int)$payroll['month'],
            'year'             => (int)$payroll['year'],
            'month_name'       => $monthNames[(int)$payroll['month']] ?? '',
            'total_days'       => (int)($payroll['total_days'] ?? 0),
            'paid_days'        => (int)($payroll['paid_days'] ?? 0),
            'pf_employee'      => (float)($payroll['pf_employee'] ?? 0),
            'esi_employee'     => (float)($payroll['esi_employee'] ?? 0),
            'professional_tax' => (float)($payroll['professional_tax'] ?? 0),
            'total_deductions' => (float)($payroll['total_deductions'] ?? 0),
            'net_pay'          => (float)($payroll['net_pay'] ?? 0),
            'ctc'              => (float)($payroll['ctc'] ?? 0),
            'gross_earnings'   => (float)($payroll['gross_earnings'] ?? 0),
        ] : null,

        // Tenure (for experience cert)
        'tenure' => $tenure,

        // Company
        'company' => [
            'name'      => $company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD',
            'address'   => $company['address'] ?? '110, Someswar Square, Vesu',
            'city'      => $company['city'] ?? 'Surat',
            'state'     => $company['state'] ?? 'Gujarat',
            'pincode'   => $company['pincode'] ?? '395007',
            'gst'       => $company['gst_number'] ?? '',
            'pan'       => $company['pan_number'] ?? '',
            'email'     => $company['contact_email'] ?? 'hr@rcsfacility.com',
            'phone'     => $company['contact_phone'] ?? '',
        ],
    ]);
}