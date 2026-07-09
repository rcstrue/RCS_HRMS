<?php
/**
 * ESS API — Payslip Endpoint
 * GET:  List available payroll periods for employee
 * GET:  Get payslip data for specific month/year
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':
            _handleGet();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    error_log('[ESS payslip] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

function _handleGet(): void
{
    $authId = requireAuth();
    $conn = getDbConnection();

    $month = (int)($_GET['month'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);

    // If month+year provided, return payslip data
    if ($month > 0 && $year > 0) {
        _getPayslipData($conn, $authId, $month, $year);
    } else {
        // Return list of available periods
        _getAvailablePeriods($conn, $authId);
    }

    $conn->close();
}

// ─── Get Available Periods ──────────────────────────────────────────────────────

function _getAvailablePeriods(mysqli $conn, string $employeeId): void
{
    $stmt = $conn->prepare('
        SELECT DISTINCT month, year
        FROM payroll
        WHERE employee_id = ? AND status NOT IN ("Draft", "Cancelled")
        ORDER BY year DESC, month DESC
        LIMIT 24
    ');
    $stmt->bind_param('s', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();

    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $m = (int)$row['month'];
        $y = (int)$row['year'];
        $periods[] = [
            'id' => (int)("{$y}{$m}"),
            'period_name' => _monthName($m) . ' ' . $y,
            'month' => $m,
            'year' => $y,
            'status' => 'Processed',
        ];
    }
    $stmt->close();

    jsonOutput([
        'success' => true,
        'data' => $periods
    ]);
}

// ─── Get Payslip Data ────────────────────────────────────────────────────────────

function _getPayslipData(mysqli $conn, string $employeeId, int $month, int $year): void
{
    // Build period info from month/year directly (no payroll_periods table dependency)
    $periodName = _monthName($month) . ' ' . $year;
    $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

    // Get employee info
    $empStmt = $conn->prepare('
        SELECT e.id, e.employee_code, e.full_name, e.mobile_number, e.gender,
               e.designation, e.department, e.date_of_joining,
               e.uan_number, e.esic_number,
               e.bank_name, e.account_number, e.ifsc_code,
               c.name AS client_name,
               u.name AS unit_name
        FROM employees e
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE e.id = ? AND e.status = "approved"
        LIMIT 1
    ');
    $empStmt->bind_param('s', $employeeId);
    $empStmt->execute();
    $employee = $empStmt->get_result()->fetch_assoc();
    $empStmt->close();

    if (!$employee) {
        jsonOutput(['success' => false, 'error' => 'Employee not found'], 404);
    }

    // Get payroll data directly by month/year
    $pStmt = $conn->prepare('
        SELECT p.*
        FROM payroll p
        WHERE p.employee_id = ? AND p.month = ? AND p.year = ?
          AND p.status NOT IN ("Draft", "Cancelled")
        LIMIT 1
    ');
    $pStmt->bind_param('sii', $employeeId, $month, $year);
    $pStmt->execute();
    $payroll = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();

    if (!$payroll) {
        jsonOutput(['success' => false, 'error' => 'Payslip not available for ' . _monthName($month) . ' ' . $year], 404);
    }

    // Known mapped fields (earnings + deductions + totals + meta)
    $knownFields = [
        'id', 'employee_id', 'month', 'year',
        'total_days', 'paid_days', 'unpaid_days', 'overtime_hours',
        'basic_da', 'hra', 'washing_allowance', 'leave_encashment',
        'bonus_encashment', 'overtime_amount', 'extra_days_amount',
        'gross_earnings', 'gross_salary', 'ctc', 'net_pay', 'total_deductions',
        'pf_employee', 'esi_employee', 'professional_tax', 'lwf_employee',
        'loan_emi', 'salary_advance', 'office_deduction', 'trust_deduction',
        'payment_mode', 'payment_status', 'status',
        'created_at', 'updated_at',
        // Employer contributions — not shown on employee payslip
        'pf_employer', 'esi_employer', 'eps_employer', 'edlis_employer',
        'epf_admin_charges', 'total_employer_contribution',
        // Non-deduction fields that may be numeric
        'unit_id', 'calculated_by', 'client_id',
    ];

    // Dynamically detect any unmapped deduction columns
    $extraDeductions = [];
    foreach ($payroll as $key => $value) {
        if (in_array($key, $knownFields)) continue;
        if (!is_numeric($value)) continue;
        $floatVal = (float)$value;
        if (abs($floatVal) < 0.01) continue; // skip zero/near-zero
        // Auto-generate a readable label from column name
        $label = str_replace(['_', '-'], ' ', $key);
        $label = ucwords($label);
        $extraDeductions[] = [
            'key'  => $key,
            'label' => $label,
            'value' => $floatVal,
        ];
    }

    jsonOutput([
        'success' => true,
        'data' => [
            'period' => [
                'id' => (int)("{$year}{$month}"),
                'period_name' => $periodName,
                'month' => $month,
                'year' => $year,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'employee' => [
                'id' => (int)$employee['id'],
                'employee_code' => $employee['employee_code'],
                'full_name' => $employee['full_name'],
                'mobile_number' => $employee['mobile_number'],
                'gender' => $employee['gender'],
                'designation' => $employee['designation'],
                'department' => $employee['department'],
                'date_of_joining' => $employee['date_of_joining'],
                'uan_number' => $employee['uan_number'],
                'esic_number' => $employee['esic_number'],
                'bank_name' => $employee['bank_name'],
                'account_number' => $employee['account_number'],
                'ifsc_code' => $employee['ifsc_code'],
                'client_name' => $employee['client_name'],
                'unit_name' => $employee['unit_name'],
            ],
            'payroll' => [
                'total_days' => (int)$payroll['total_days'],
                'paid_days' => (float)$payroll['paid_days'],
                'unpaid_days' => (float)$payroll['unpaid_days'],
                'overtime_hours' => (float)$payroll['overtime_hours'],
                'basic_da' => (float)$payroll['basic_da'],
                'hra' => (float)$payroll['hra'],
                'washing_allowance' => (float)$payroll['washing_allowance'],
                'leave_encashment' => (float)$payroll['leave_encashment'],
                'bonus_encashment' => (float)$payroll['bonus_encashment'],
                'overtime_amount' => (float)$payroll['overtime_amount'],
                'extra_days_amount' => (float)$payroll['extra_days_amount'],
                'gross_earnings' => (float)$payroll['gross_earnings'],
                'pf_employee' => (float)$payroll['pf_employee'],
                'esi_employee' => (float)$payroll['esi_employee'],
                'professional_tax' => (float)$payroll['professional_tax'],
                'lwf_employee' => (float)$payroll['lwf_employee'],
                'salary_advance' => (float)$payroll['salary_advance'],
                'office_deduction' => (float)$payroll['office_deduction'],
                'trust_deduction' => (float)$payroll['trust_deduction'],
                'loan_emi' => (float)($payroll['loan_emi'] ?? 0),
                'total_deductions' => (float)$payroll['total_deductions'],
                'net_pay' => (float)$payroll['net_pay'],
                'gross_salary' => (float)$payroll['gross_salary'],
                'ctc' => (float)$payroll['ctc'],
                'payment_mode' => $payroll['payment_mode'],
                'payment_status' => $payroll['payment_status'],
                'status' => $payroll['status'],
                'extra_deductions' => $extraDeductions,
            ]
        ]
    ]);
}

function _monthName(int $month): string
{
    $months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
    return $months[$month] ?? '';
}
