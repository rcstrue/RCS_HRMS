<?php
/**
 * RCS HRMS Pro - Payroll Update API
 * Version: 4.2.0 - Fixed basic_da column and DB-based PT
 * AJAX endpoint for inline salary updates
 * 
 * Salary Structure:
 * - Basic+DA (Combined column: basic_da)
 * - HRA
 * - Leave Encashment
 * - Bonus Encashment
 * - Washing Allowance
 */

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Role check — payroll updates are admin/HR/manager only
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr_executive', 'hr', 'manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions for payroll update.']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get input data
$month = (int)($_POST['month'] ?? 0);
$year = (int)($_POST['year'] ?? 0);
$empCode = sanitize($_POST['emp_code'] ?? '');

if (!$month || !$year || !$empCode) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// ── Input validation ──
if ($month < 1 || $month > 12 || $year < 2020) {
    echo json_encode(['success' => false, 'message' => 'Invalid month or year']);
    exit;
}
if (!preg_match('/^[A-Za-z0-9_\-]+$/', $empCode)) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee code format']);
    exit;
}

// Get salary components
$basicDA = floatval($_POST['basic_da'] ?? 0);
$hra = floatval($_POST['hra'] ?? 0);
$leaveEncashment = floatval($_POST['leave_encashment'] ?? 0);
$bonusEncashment = floatval($_POST['bonus_encashment'] ?? 0);
$washing = floatval($_POST['washing'] ?? 0);

// Validate salary components are non-negative
foreach ([$basicDA, $hra, $leaveEncashment, $bonusEncashment, $washing] as $val) {
    if ($val < 0) {
        echo json_encode(['success' => false, 'message' => 'Salary components cannot be negative']);
        exit;
    }
}

// Calculate new gross
$newGross = $basicDA + $hra + $leaveEncashment + $bonusEncashment + $washing;

// ── Read statutory rates from DB (same as payroll-save-row.php and class.payroll.php) ──
$pfWageCeiling = 15000;
$esiWageCeiling = 21000;
$pfEmployeeRate = 12.00;
$esiEmployeeRate = 0.75;
$ptAmount = 200; // Fallback default

try {
    $pfRate = $db->fetch("SELECT * FROM pf_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
    if ($pfRate) {
        $pfEmployeeRate = (float)($pfRate['employee_share'] ?? 12.00);
        $pfWageCeiling = (float)($pfRate['wage_ceiling'] ?? 15000);
    }
} catch (Exception $e) {}

try {
    $esiRate = $db->fetch("SELECT * FROM esi_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
    if ($esiRate) {
        $esiEmployeeRate = (float)($esiRate['employee_share'] ?? 0.75);
        $esiWageCeiling = (float)($esiRate['wage_ceiling'] ?? 21000);
    }
} catch (Exception $e) {}

try {
    $ptRate = $db->fetch("SELECT * FROM pt_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
    if ($ptRate) {
        $ptAmount = (float)($ptRate['monthly_amount'] ?? 200);
    }
} catch (Exception $e) {}

// Calculate deductions using DB rates
$pfEmp = round(min($basicDA, $pfWageCeiling) * $pfEmployeeRate / 100, 2);
$esiEmp = ($newGross <= $esiWageCeiling) ? round($newGross * $esiEmployeeRate / 100, 2) : 0;
$pt = $ptAmount;

// Get existing deduction adjustments
$existingPayroll = $db->fetch(
    "SELECT salary_advance, other_deductions FROM payroll WHERE month = :month AND year = :year AND employee_id = :emp",
    ['month' => $month, 'year' => $year, 'emp' => $empCode]
);

$salaryAdvance = floatval($existingPayroll['salary_advance'] ?? 0);
$otherDeductions = floatval($existingPayroll['other_deductions'] ?? 0);

$totalDed = $pfEmp + $esiEmp + $pt + $salaryAdvance + $otherDeductions;
$netPay = $newGross - $totalDed;

try {
    // Update payroll record — use basic_da as single column (matches payroll-save-row.php schema)
    $db->update('payroll', [
        'basic_da'         => $basicDA,
        'hra'              => $hra,
        'leave_encashment' => $leaveEncashment,
        'bonus_encashment' => $bonusEncashment,
        'washing_allowance'=> $washing,
        'gross_earnings'   => $newGross,
        'gross_salary'     => $newGross,
        'pf_employee'      => $pfEmp,
        'esi_employee'     => $esiEmp,
        'professional_tax' => $pt,
        'total_deductions' => $totalDed,
        'net_pay'          => $netPay,
        'updated_at'       => date('Y-m-d H:i:s')
    ], 'month = :month AND year = :year AND employee_id = :emp', ['month' => $month, 'year' => $year, 'emp' => $empCode]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Salary updated successfully',
        'gross' => $newGross,
        'deductions' => $totalDed,
        'net_pay' => $netPay
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}