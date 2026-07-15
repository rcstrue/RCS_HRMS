<?php
/**
 * RCS HRMS Pro - Payroll Processing Page
 * Version: 5.2.0 - With Recalculate Feature
 * 
 * Features:
 * - Client/Unit dropdown filters (like attendance page)
 * - Must select client and press Filter to show units
 * - Attendance count display per unit
 * - Process validation (requires attendance first)
 * - Recalculate button to refresh data after changes
 */

$pageTitle = 'Process Payroll';

// Ensure loan_emi column exists in payroll table
try {
    $db->query("SELECT loan_emi FROM payroll LIMIT 1");
} catch (Exception $e) {
    $db->exec("ALTER TABLE payroll ADD COLUMN loan_emi DECIMAL(10,2) DEFAULT 0.00 AFTER salary_advance");
}

// Ensure loan tables exist
try {
    $db->query("SELECT id FROM employee_loans LIMIT 1");
} catch (Exception $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS `employee_loans` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `unit_id` int(11) DEFAULT NULL,
        `loan_type` varchar(50) DEFAULT 'Personal',
        `amount` decimal(12,2) NOT NULL,
        `interest_rate` decimal(5,2) DEFAULT 0.00,
        `tenure_months` int(11) NOT NULL,
        `emi_amount` decimal(12,2) NOT NULL,
        `total_interest` decimal(12,2) DEFAULT 0.00,
        `total_repayable` decimal(12,2) NOT NULL,
        `balance_amount` decimal(12,2) NOT NULL,
        `emi_deducted` int(11) DEFAULT 0,
        `start_month` int(2) NOT NULL,
        `start_year` int(4) NOT NULL,
        `status` enum('Active','Closed','Settled','Written Off') DEFAULT 'Active',
        `remarks` text DEFAULT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_employee` (`employee_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    $db->query("SELECT id FROM loan_emi_log LIMIT 1");
} catch (Exception $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS `loan_emi_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `loan_id` int(11) NOT NULL,
        `employee_id` int(11) NOT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `emi_amount` decimal(12,2) NOT NULL,
        `principal_component` decimal(12,2) DEFAULT 0.00,
        `interest_component` decimal(12,2) DEFAULT 0.00,
        `balance_after` decimal(12,2) NOT NULL,
        `deducted_via_payroll` tinyint(1) DEFAULT 1,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_loan_month_year` (`loan_id`, `month`, `year`),
        KEY `idx_employee_month` (`employee_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Deduct loan EMI for all employees in a payroll period + unit.
 * Call this AFTER processPayroll() to add loan deductions.
 */
function deductLoansForPeriodUnit($db, $periodId, $unitId) {
    // Get month/year from payroll_periods
    $period = $db->fetch("SELECT month, year FROM payroll_periods WHERE id = ?", [$periodId]);
    if (!$period) return 0;
    
    $month = (int)$period['month'];
    $year  = (int)$period['year'];
    $totalDeducted = 0;
    
    // Get all payroll records for this period + unit
    $payrollRows = $db->fetchAll(
        "SELECT p.id as payroll_id, p.employee_id as emp_code, p.loan_emi, p.total_deductions, p.net_pay,
                e.id as employee_db_id
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         WHERE p.payroll_period_id = ? AND e.unit_id = ?",
        [$periodId, $unitId]
    );
    
    foreach ($payrollRows as $pRow) {
        $empDbId = (int)$pRow['employee_db_id'];
        $empCode = $pRow['emp_code'];
        $payrollId = (int)$pRow['payroll_id'];
        $currentLoanEmi = (float)$pRow['loan_emi'];
        
        // Get active loans for this employee started on or before this month
        $empLoans = $db->fetchAll(
            "SELECT * FROM employee_loans
             WHERE employee_id = ? AND status = 'Active'
             AND (start_year < ? OR (start_year = ? AND start_month <= ?))",
            [$empDbId, $year, $year, $month]
        );
        
        $empLoanTotal = 0;
        
        foreach ($empLoans as $empLoan) {
            // Check if EMI already deducted for this month
            $alreadyDeducted = $db->fetch(
                "SELECT id FROM loan_emi_log WHERE loan_id = ? AND month = ? AND year = ?",
                [$empLoan['id'], $month, $year]
            );
            
            if ($alreadyDeducted) {
                // Already deducted — add existing EMI amount
                $empLoanTotal += (float)$empLoan['emi_amount'];
                continue;
            }
            
            $emiAmount = (float)$empLoan['emi_amount'];
            $balanceAmount = (float)$empLoan['balance_amount'];
            
            // Calculate interest/principal split
            if ((float)$empLoan['interest_rate'] > 0) {
                $monthlyRate = (float)$empLoan['interest_rate'] / 12 / 100;
                $interestComponent = round($balanceAmount * $monthlyRate, 2);
                $principalComponent = round($emiAmount - $interestComponent, 2);
            } else {
                $interestComponent = 0;
                $principalComponent = $emiAmount;
            }
            
            // Handle last EMI
            if ($emiAmount > $balanceAmount) {
                $emiAmount = $balanceAmount;
                $principalComponent = $emiAmount - $interestComponent;
                if ($principalComponent < 0) {
                    $interestComponent = $emiAmount;
                    $principalComponent = 0;
                }
            }
            
            $newBalance = round($balanceAmount - $emiAmount, 2);
            if ($newBalance < 0) $newBalance = 0;
            
            // Record EMI log
            $db->insert('loan_emi_log', [
                'loan_id' => $empLoan['id'],
                'employee_id' => $empDbId,
                'month' => $month,
                'year' => $year,
                'emi_amount' => $emiAmount,
                'principal_component' => $principalComponent,
                'interest_component' => $interestComponent,
                'balance_after' => $newBalance,
                'deducted_via_payroll' => 1
            ]);
            
            // Update loan balance and status
            $newStatus = ($newBalance <= 0) ? 'Closed' : 'Active';
            $db->update('employee_loans', [
                'balance_amount' => $newBalance,
                'emi_deducted' => (int)$empLoan['emi_deducted'] + 1,
                'status' => $newStatus
            ], 'id = :id', ['id' => $empLoan['id']]);
            
            $empLoanTotal += $emiAmount;
        }
        
        // Update payroll record with total loan EMI for this employee
        if ($empLoanTotal > 0 && abs($empLoanTotal - $currentLoanEmi) > 0.01) {
            $currentTotalDed = (float)$pRow['total_deductions'];
            $currentNetPay   = (float)$pRow['net_pay'];
            
            // Remove old loan EMI from deductions (if any), add new
            $deductionAdjustment = $empLoanTotal - $currentLoanEmi;
            
            $newTotalDed = round($currentTotalDed + $deductionAdjustment, 2);
            $newNetPay   = round($currentNetPay - $deductionAdjustment, 2);
            
            $db->update('payroll', [
                'loan_emi' => round($empLoanTotal, 2),
                'total_deductions' => $newTotalDed,
                'net_pay' => $newNetPay,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $payrollId]);
            
            $totalDeducted += $deductionAdjustment;
        } elseif ($empLoanTotal > 0 && $currentLoanEmi == 0) {
            // First time setting loan EMI
            $currentTotalDed = (float)$pRow['total_deductions'];
            $currentNetPay   = (float)$pRow['net_pay'];
            
            $newTotalDed = round($currentTotalDed + $empLoanTotal, 2);
            $newNetPay   = round($currentNetPay - $empLoanTotal, 2);
            
            $db->update('payroll', [
                'loan_emi' => round($empLoanTotal, 2),
                'total_deductions' => $newTotalDed,
                'net_pay' => $newNetPay,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $payrollId]);
            
            $totalDeducted += $empLoanTotal;
        }
    }
    
    return $totalDeducted;
}

// Get all periods
$periods = $payroll->getPeriods();

// Get current month/year
$currentMonth = prev_month_num();
$currentYear = date('Y');

// Get filter values
$filterClientId = (int)($_GET['client_id'] ?? 0);
$filterUnitId = (int)($_GET['unit_id'] ?? 0);
$searchTerm = sanitize($_GET['search'] ?? '');
$filterPressed = isset($_GET['filter']) || $filterClientId > 0;

// Get clients and units
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$allUnits = $db->fetchAll("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name");

// Filter units by client
$units = [];
if ($filterClientId) {
    foreach ($allUnits as $u) {
        if ($u['client_id'] == $filterClientId) {
            $units[] = $u;
        }
    }
}

// Handle create period
if (isset($_POST['create_period'])) {
    $month = (int)($_POST['month'] ?? $currentMonth);
    $year = (int)($_POST['year'] ?? $currentYear);
    
    $result = $payroll->createPeriod($month, $year);
    if (!empty($result['success'])) {
        setFlash('success', 'Payroll period created successfully!');
        redirect('index.php?page=payroll/process&period_id=' . $result['period_id']);
    } else {
        setFlash('error', $result['message'] ?? 'Failed to create period');
    }
}

// Get selected period
$selectedPeriod = null;
$unitAttendanceData = [];
$payrollData = [];
$totals = null;

if (isset($_GET['period_id']) && !empty($_GET['period_id'])) {
    $periodId = (int)$_GET['period_id'];
    
    $stmt = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$periodId]);
    $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPeriod) {
        $periodMonth = (int)$selectedPeriod['month'];
        $periodYear = (int)$selectedPeriod['year'];
        
        // Only get units when filter is pressed AND client is selected
        if ($filterPressed && $filterClientId) {
            $unitQuery = "SELECT 
                    u.id as unit_id,
                    u.name as unit_name,
                    c.id as client_id,
                    c.name as client_name,
                    COUNT(DISTINCT e.id) as employee_count,
                    COUNT(DISTINCT CASE WHEN ats.id IS NOT NULL THEN e.id END) as attendance_count,
                    COALESCE(SUM(ats.total_present), 0) as total_attendance,
                    COALESCE(SUM(ats.total_extra), 0) as total_extra,
                    COALESCE(SUM(ats.overtime_hours), 0) as total_ot,
                    pus.status as payroll_status,
                    pus.employee_count as processed_employees,
                    pus.total_gross,
                    pus.total_net
                FROM units u
                LEFT JOIN clients c ON u.client_id = c.id
                LEFT JOIN employees e ON e.unit_id = u.id AND e.status = 'approved'
                LEFT JOIN attendance_summary ats ON ats.employee_id = e.id 
                    AND ats.month = ? AND ats.year = ?
                LEFT JOIN payroll_unit_status pus ON pus.unit_id = u.id 
                    AND pus.payroll_period_id = ?
                WHERE u.is_active = 1 AND u.client_id = ?";
            
            $unitParams = [$periodMonth, $periodYear, $periodId, $filterClientId];
            
            if ($filterUnitId) {
                $unitQuery .= " AND u.id = ?";
                $unitParams[] = $filterUnitId;
            }
            
            $unitQuery .= " GROUP BY u.id ORDER BY c.name, u.name";
            
            $unitAttendanceData = $db->fetchAll($unitQuery, $unitParams);
        }
        
        // Get payroll data for grid
        if ($filterClientId) {
            $whereClause = "p.payroll_period_id = ?";
            $params = [$selectedPeriod['id']];
            
            $whereClause .= " AND e.client_id = ?";
            $params[] = $filterClientId;
            
            if ($filterUnitId) {
                $whereClause .= " AND e.unit_id = ?";
                $params[] = $filterUnitId;
            }
            if ($searchTerm) {
                $whereClause .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)";
                $params[] = '%' . $searchTerm . '%';
                $params[] = '%' . $searchTerm . '%';
            }
            
            $payrollData = $db->fetchAll(
                "SELECT p.*, e.employee_code, e.full_name, e.designation,
                        c.name as client_name, u.name as unit_name,
                        COALESCE(p.basic_da, 0) as basic_da_display,
                        COALESCE(ats.total_present, 0) as att_present,
                        COALESCE(ats.total_wo, 0) as att_wo,
                        COALESCE(ats.total_extra, 0) as att_extra,
                        COALESCE(ats.overtime_hours, 0) as att_ot_hours
                 FROM payroll p
                 JOIN employees e ON p.employee_id = e.employee_code
                 LEFT JOIN clients c ON e.client_id = c.id
                 LEFT JOIN units u ON e.unit_id = u.id
                 LEFT JOIN attendance_summary ats ON ats.employee_id = e.id
                     AND ats.month = ? AND ats.year = ?
                 WHERE $whereClause
                 ORDER BY c.name, u.name, e.employee_code",
                array_merge([$periodMonth, $periodYear], $params)
            );
        }
        
        // Get totals
        $totals = $db->fetch(
            "SELECT COUNT(*) as employee_count,
                    SUM(gross_earnings) as total_gross,
                    SUM(total_deductions) as total_deductions,
                    SUM(net_pay) as total_net_pay,
                    SUM(ctc) as total_ctc,
                    SUM(CASE WHEN salary_hold = 1 THEN 1 ELSE 0 END) as held_count
             FROM payroll
             WHERE payroll_period_id = ?",
            [$selectedPeriod['id']]
        );
    }
}

// Handle process unit payroll
if (isset($_POST['process_unit']) && isset($_POST['period_id']) && isset($_POST['unit_id'])) {
    $periodId = (int)$_POST['period_id'];
    $unitId = (int)$_POST['unit_id'];
    
    // Check if attendance exists for this unit
    $periodInfo = $db->fetch("SELECT month, year FROM payroll_periods WHERE id = ?", [$periodId]);
    if ($periodInfo) {
        // Check attendance count vs employee count
        $attendanceCheck = $db->fetch(
            "SELECT 
                COUNT(DISTINCT e.id) as total_employees,
                COUNT(DISTINCT CASE WHEN ats.id IS NOT NULL THEN e.id END) as attendance_employees
             FROM employees e
             LEFT JOIN attendance_summary ats ON ats.employee_id = e.id 
                AND ats.month = ? AND ats.year = ?
             WHERE e.unit_id = ? AND e.status = 'approved'",
            [$periodInfo['month'], $periodInfo['year'], $unitId]
        );
        
        if (empty($attendanceCheck['attendance_employees'])) {
            setFlash('error', 'No attendance found for this unit. Please add attendance first!');
            redirect('index.php?page=payroll/process&period_id=' . $periodId . ($filterClientId ? '&client_id=' . $filterClientId : '') . '&filter=1');
        }
        
        // Warn if some employees are missing attendance
        if ($attendanceCheck['attendance_employees'] < $attendanceCheck['total_employees']) {
            $missing = $attendanceCheck['total_employees'] - $attendanceCheck['attendance_employees'];
            setFlash('warning', "Note: {$missing} employees have no attendance data and will be skipped.");
        }
    }
    
    $result = $payroll->processPayroll($periodId, ['unit_id' => $unitId]);
    
    if (!empty($result['success'])) {
        // Auto-deduct loan EMI for all employees in this unit/period
        $loanDeductionAmt = deductLoansForPeriodUnit($db, $periodId, $unitId);
        
        // Create or update unit status using named parameters
        $existingStatus = $db->fetch(
            "SELECT id FROM payroll_unit_status WHERE payroll_period_id = ? AND unit_id = ?",
            [$periodId, $unitId]
        );
        
        if ($existingStatus) {
            $db->update('payroll_unit_status', [
                'status' => 'Processed',
                'employee_count' => $result['processed'],
                'total_gross' => $result['total_gross'],
                'total_net' => ($result['total_net'] - $loanDeductionAmt),
                'processed_at' => date('Y-m-d H:i:s'),
                'processed_by' => $_SESSION['user_id']
            ], 'id = :id', ['id' => $existingStatus['id']]);
        } else {
            // Get client_id from unit
            $unitInfo = $db->fetch("SELECT client_id FROM units WHERE id = ?", [$unitId]);
            $db->query(
                "INSERT INTO payroll_unit_status 
                (payroll_period_id, client_id, unit_id, status, employee_count, total_gross, total_net, processed_at, processed_by)
                VALUES (?, ?, ?, 'processed', ?, ?, ?, NOW(), ?)",
                [$periodId, $unitInfo['client_id'] ?? null, $unitId, $result['processed'], $result['total_gross'], ($result['total_net'] - $loanDeductionAmt), $_SESSION['user_id']]
            );
        }
        
        $loanMsg = $loanDeductionAmt > 0 ? " Loan EMI of ₹" . number_format($loanDeductionAmt, 2) . " auto-deducted." : '';
        setFlash('success', "Processed {$result['processed']} employees for unit!" . $loanMsg);
    } else {
        setFlash('error', $result['message'] ?? 'Processing failed');
    }
    redirect('index.php?page=payroll/process&period_id=' . $periodId . ($filterClientId ? '&client_id=' . $filterClientId : '') . ($filterUnitId ? '&unit_id=' . $filterUnitId : '') . '&filter=1');
}

// Handle recalculate unit payroll (re-process with fresh data)
if (isset($_POST['recalculate_unit']) && isset($_POST['period_id']) && isset($_POST['unit_id'])) {
    $periodId = (int)$_POST['period_id'];
    $unitId = (int)$_POST['unit_id'];
    
    // First delete existing payroll for this unit/period
    $db->query(
        "DELETE FROM payroll WHERE payroll_period_id = ? AND employee_id IN 
         (SELECT employee_code FROM employees WHERE unit_id = ?)",
        [$periodId, $unitId]
    );
    
    // Reset unit status
    $db->update('payroll_unit_status', [
        'status' => 'pending',
        'employee_count' => 0,
        'total_gross' => 0,
        'total_net' => 0,
        'processed_at' => null
    ], 'payroll_period_id = :period_id AND unit_id = :unit_id', ['period_id' => $periodId, 'unit_id' => $unitId]);
    
    // Re-process
    $result = $payroll->processPayroll($periodId, ['unit_id' => $unitId]);
    
    if (!empty($result['success'])) {
        // Auto-deduct loan EMI for all employees in this unit/period
        $loanDeductionAmt = deductLoansForPeriodUnit($db, $periodId, $unitId);
        
        // Update unit status
        $db->update('payroll_unit_status', [
            'status' => 'Processed',
            'employee_count' => $result['processed'],
            'total_gross' => $result['total_gross'],
            'total_net' => ($result['total_net'] - $loanDeductionAmt),
            'processed_at' => date('Y-m-d H:i:s'),
            'processed_by' => $_SESSION['user_id']
        ], 'payroll_period_id = :period_id AND unit_id = :unit_id', ['period_id' => $periodId, 'unit_id' => $unitId]);
        
        $loanMsg = $loanDeductionAmt > 0 ? " Loan EMI of ₹" . number_format($loanDeductionAmt, 2) . " auto-deducted." : '';
        setFlash('success', "Recalculated! {$result['processed']} employees processed with fresh data." . $loanMsg);
    } else {
        setFlash('error', $result['message'] ?? 'Recalculation failed');
    }
    redirect('index.php?page=payroll/process&period_id=' . $periodId . ($filterClientId ? '&client_id=' . $filterClientId : '') . '&filter=1');
}

// Handle deduct loans only (without full recalculation)
if (isset($_POST['deduct_loans_unit']) && isset($_POST['period_id']) && isset($_POST['unit_id'])) {
    $periodId = (int)$_POST['period_id'];
    $unitId = (int)$_POST['unit_id'];
    
    $loanDeductionAmt = deductLoansForPeriodUnit($db, $periodId, $unitId);
    
    if ($loanDeductionAmt > 0) {
        setFlash('success', "Loan EMI of ₹" . number_format($loanDeductionAmt, 2) . " auto-deducted for this unit!");
    } else {
        setFlash('info', 'No new loan EMI to deduct. All active loans already processed for this month.');
    }
    redirect('index.php?page=payroll/process&period_id=' . $periodId . ($filterClientId ? '&client_id=' . $filterClientId : '') . ($filterUnitId ? '&unit_id=' . $filterUnitId : '') . '&filter=1');
}

// Handle finalize unit
if (isset($_POST['finalize_unit']) && isset($_POST['period_id']) && isset($_POST['unit_id'])) {
    $periodId = (int)$_POST['period_id'];
    $unitId = (int)$_POST['unit_id'];
    
    $db->update('payroll_unit_status', [
        'status' => 'finalized',
        'finalized_at' => date('Y-m-d H:i:s'),
        'finalized_by' => $_SESSION['user_id']
    ], 'payroll_period_id = :period_id AND unit_id = :unit_id', ['period_id' => $periodId, 'unit_id' => $unitId]);
    
    // Check if all units finalized
    $finalizedCount = $db->fetch(
        "SELECT COUNT(*) as count FROM payroll_unit_status WHERE payroll_period_id = ? AND status = 'finalized'",
        [$periodId]
    );
    
    $totalUnits = $db->fetch(
        "SELECT COUNT(*) as count FROM payroll_unit_status WHERE payroll_period_id = ?",
        [$periodId]
    );
    
    if ($finalizedCount['count'] >= $totalUnits['count'] && $totalUnits['count'] > 0) {
        $db->update('payroll_periods', [
            'status' => 'Approved',
            'finalized_units' => $finalizedCount['count']
        ], 'id = :id', ['id' => $periodId]);
    }
    
    setFlash('success', 'Unit finalized successfully!');
    redirect('index.php?page=payroll/process&period_id=' . $periodId . ($filterClientId ? '&client_id=' . $filterClientId : '') . '&filter=1');
}

// Handle approve payroll (all units)
if (isset($_POST['approve_payroll']) && isset($_POST['period_id'])) {
    $periodId = (int)$_POST['period_id'];
    
    $db->update('payroll_unit_status', [
        'status' => 'finalized',
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by' => $_SESSION['user_id']
    ], 'payroll_period_id = :period_id AND status = :status', ['period_id' => $periodId, 'status' => 'Processed']);
    
    $db->update('payroll_periods', [
        'status' => 'Approved',
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by' => $_SESSION['user_id']
    ], 'id = :id', ['id' => $periodId]);
    
    setFlash('success', 'Payroll approved successfully!');
    redirect('index.php?page=payroll/process&period_id=' . $periodId);
}

// Handle delete payroll
if (isset($_POST['delete_payroll']) && isset($_POST['period_id'])) {
    $periodId = (int)$_POST['period_id'];
    $result = $payroll->deletePayroll($periodId);
    
    if (!empty($result['success'])) {
        $db->query("DELETE FROM payroll_unit_status WHERE payroll_period_id = ?", [$periodId]);
        setFlash('success', 'Payroll deleted successfully!');
        redirect('index.php?page=payroll/process');
    } else {
        setFlash('error', $result['message'] ?? 'Failed to delete');
        redirect('index.php?page=payroll/process&period_id=' . $periodId);
    }
}

// Group periods by year
$periodsByYear = [];
foreach ($periods as $p) {
    $year = $p['year'] ?? date('Y', strtotime($p['start_date'] ?? 'now'));
    if (!isset($periodsByYear[$year])) {
        $periodsByYear[$year] = [];
    }
    $periodsByYear[$year][] = $p;
}
krsort($periodsByYear);
?>

<div class="row">
    <div class="col-12">
        <?php if (!$selectedPeriod): ?>
        <!-- Period Selection View -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-calendar me-2"></i>Payroll Processing</h5>
                <form method="POST" class="d-flex gap-2">
                    <select class="form-select form-select-sm" name="month" style="width: auto;">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $currentMonth ? 'selected' : ''; ?>>
                            <?php echo date('M', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                    <select class="form-select form-select-sm" name="year" style="width: auto;">
                        <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" name="create_period" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus"></i> Create Period
                    </button>
                </form>
            </div>
            
            <div class="card-body p-0">
                <!-- Year Tabs -->
                <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                    <?php $firstYear = true; foreach ($periodsByYear as $year => $yearPeriods): ?>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $firstYear ? 'active' : ''; ?>" 
                                data-bs-toggle="tab" data-bs-target="#year-<?php echo $year; ?>">
                            <?php echo $year; ?>
                        </button>
                    </li>
                    <?php $firstYear = false; endforeach; ?>
                    <?php if (empty($periodsByYear)): ?>
                    <li class="nav-item"><span class="nav-link active"><?php echo $currentYear; ?></span></li>
                    <?php endif; ?>
                </ul>
                
                <div class="tab-content p-3">
                    <?php $firstYear = true; foreach ($periodsByYear as $year => $yearPeriods): ?>
                    <div class="tab-pane fade <?php echo $firstYear ? 'show active' : ''; ?>" id="year-<?php echo $year; ?>">
                        <div class="row g-3">
                            <?php foreach ($yearPeriods as $p): ?>
                            <div class="col-md-3">
                                <a href="index.php?page=payroll/process&period_id=<?php echo $p['id']; ?>" 
                                   class="card h-100 text-decoration-none hover-lift">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0"><?php echo sanitize($p['period_name']); ?></h6>
                                            <span class="badge bg-<?php 
                                                echo $p['status'] === 'Draft' ? 'secondary' : 
                                                    ($p['status'] === 'Processed' ? 'info' : 
                                                    ($p['status'] === 'Approved' ? 'success' : 
                                                    ($p['status'] === 'Paid' ? 'primary' : 'warning'))); 
                                            ?>"><?php echo sanitize($p['status']); ?></span>
                                        </div>
                                        <small class="text-muted">
                                            <i class="bi bi-people me-1"></i><?php echo $p['employee_count'] ?? 0; ?> employees
                                        </small>
                                    </div>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php $firstYear = false; endforeach; ?>
                    
                    <?php if (empty($periodsByYear)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-plus fs-1"></i>
                        <p class="mt-3">No payroll periods found. Create a new period above.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Payroll Details View -->
        
        <!-- Header -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <a href="index.php?page=payroll/process" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h5 class="card-title mb-0">
                        <i class="bi bi-cash-stack me-2"></i>
                        <?php echo sanitize($selectedPeriod['period_name']); ?>
                        <span class="badge bg-<?php 
                            echo $selectedPeriod['status'] === 'Draft' ? 'secondary' : 
                                ($selectedPeriod['status'] === 'Approved' ? 'success' : 'warning'); 
                        ?> ms-2"><?php echo sanitize($selectedPeriod['status']); ?></span>
                    </h5>
                </div>
                <div class="btn-group btn-group-sm">
                    <?php if ($selectedPeriod['status'] === 'Processed'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="period_id" value="<?php echo $selectedPeriod['id']; ?>">
                        <button type="submit" name="approve_payroll" class="btn btn-success"
                                onclick="return confirm('Approve all payroll for this period?')">
                            <i class="bi bi-check-lg me-1"></i>Approve All
                        </button>
                    </form>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="period_id" value="<?php echo $selectedPeriod['id']; ?>">
                        <button type="submit" name="delete_payroll" class="btn btn-outline-danger"
                                onclick="return confirm('Delete payroll and re-process?')">
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if (in_array($selectedPeriod['status'], ['Processed', 'Approved'])): ?>
                    <a href="index.php?page=payroll/payslips&period_id=<?php echo $selectedPeriod['id']; ?>" 
                       class="btn btn-outline-primary">
                        <i class="bi bi-file-text me-1"></i>Payslips
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($totals && $totals['employee_count'] > 0): ?>
            <div class="card-body border-bottom py-2">
                <div class="row text-center g-2">
                    <div class="col">
                        <div class="small text-muted">Employees</div>
                        <div class="h5 mb-0"><?php echo number_format($totals['employee_count']); ?></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Gross</div>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($totals['total_gross']); ?></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Deductions</div>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($totals['total_deductions']); ?></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Net Pay</div>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($totals['total_net_pay']); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Unit-wise Processing Status (Simplified) -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-building me-2"></i>Unit-wise Processing Status</h6>
                <?php if ($filterClientId && !empty($unitAttendanceData)): ?>
                <button type="button" class="btn btn-sm btn-outline-info" onclick="recalculateAll()">
                    <i class="bi bi-arrow-repeat me-1"></i>Recalculate All
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Filters like Attendance Page -->
                <form method="GET" class="row g-3 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="payroll/process">
                    <input type="hidden" name="period_id" value="<?php echo $selectedPeriod['id']; ?>">
                    
                    <div class="col-md-3">
                        <label class="form-label">Client <span class="text-danger">*</span></label>
                        <select class="form-select" name="client_id" id="clientSelect" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filterClientId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filterUnitId == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="filter" value="1" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="index.php?page=payroll/process&period_id=<?php echo $selectedPeriod['id']; ?>" 
                           class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x me-1"></i>Clear
                        </a>
                    </div>
                </form>
                
                <?php if (!$filterClientId): ?>
                <!-- Show message when no client selected -->
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-filter-circle fs-1"></i>
                    <p class="mt-3">Please select a <strong>Client</strong> and click <strong>Filter</strong> to view units.</p>
                </div>
                <?php elseif (empty($unitAttendanceData)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mt-2">No units found for selected filters.</p>
                </div>
                <?php else: ?>
                <!-- Units Table with Attendance Data -->
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>Unit</th>
                                <th class="text-center">Employees</th>
                                <th class="text-center">Attendance</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unitAttendanceData as $unit): 
                                $employeeCount = $unit['employee_count'] ?? 0;
                                $attendanceCount = $unit['attendance_count'] ?? 0;
                                $hasAttendance = $attendanceCount > 0;
                                $payrollStatus = $unit['payroll_status'] ?? 'pending';
                            ?>
                            <tr>
                                <td><?php echo sanitize($unit['client_name'] ?? 'N/A'); ?></td>
                                <td><?php echo sanitize($unit['unit_name']); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $employeeCount; ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($hasAttendance): ?>
                                    <span class="badge bg-success" title="<?php echo $unit['total_attendance']; ?> total attendance days">
                                        <i class="bi bi-check me-1"></i><?php echo $attendanceCount; ?>/<?php echo $employeeCount; ?> Emp
                                    </span>
                                    <a href="index.php?page=attendance/add&client_id=<?php echo $unit['client_id']; ?>&unit_id=<?php echo $unit['unit_id']; ?>&month=<?php echo $periodMonth; ?>&year=<?php echo $periodYear; ?>&load=1" 
                                       class="btn btn-sm btn-outline-info ms-1" title="View/Edit Attendance" target="_blank">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-exclamation-triangle me-1"></i>No Attendance
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php 
                                        echo $payrollStatus === 'pending' ? 'secondary' : 
                                            ($payrollStatus === 'processed' ? 'info' : 
                                            ($payrollStatus === 'finalized' ? 'success' : 'warning'));
                                    ?>"><?php echo ucfirst($payrollStatus); ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($payrollStatus === 'pending'): ?>
                                        <?php if ($hasAttendance): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="period_id" value="<?php echo $selectedPeriod['id']; ?>">
                                            <input type="hidden" name="unit_id" value="<?php echo $unit['unit_id']; ?>">
                                            <button type="submit" name="process_unit" class="btn btn-sm btn-primary">
                                                <i class="bi bi-play-fill"></i> Process
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <a href="index.php?page=attendance/add&client_id=<?php echo $unit['client_id']; ?>&unit_id=<?php echo $unit['unit_id']; ?>&month=<?php echo $selectedPeriod['month']; ?>&year=<?php echo $selectedPeriod['year']; ?>&load=1" 
                                           class="btn btn-sm btn-warning">
                                            <i class="bi bi-calendar-plus me-1"></i>Add Attendance
                                        </a>
                                        <?php endif; ?>
                                    <?php elseif ($payrollStatus === 'processed'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="period_id" value="<?php echo $selectedPeriod['id']; ?>">
                                        <input type="hidden" name="unit_id" value="<?php echo $unit['unit_id']; ?>">
                                        <button type="submit" name="recalculate_unit" class="btn btn-sm btn-outline-info"
                                                onclick="return confirm('Recalculate payroll with fresh attendance/salary data?')">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="period_id" value="<?php echo $selectedPeriod['id']; ?>">
                                        <input type="hidden" name="unit_id" value="<?php echo $unit['unit_id']; ?>">
                                        <button type="submit" name="finalize_unit" class="btn btn-sm btn-success"
                                                onclick="return confirm('Finalize this unit?')">
                                            <i class="bi bi-check-lg"></i> Finalize
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-success"><i class="bi bi-check-circle"></i> Done</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payroll Data Grid -->
        <?php if (!empty($payrollData)): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-table me-2"></i>Payroll Data — Detailed Breakup</h6>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($filterUnitId && $selectedPeriod): ?>
                    <a href="index.php?page=payroll/process-edit&client_id=<?php echo $filterClientId; ?>&unit_id=<?php echo $filterUnitId; ?>&month=<?php echo $periodMonth; ?>&year=<?php echo $periodYear; ?>&period_id=<?php echo $selectedPeriod['id']; ?>&load=1" 
                       target="_blank" class="btn btn-info btn-sm">
                        <i class="bi bi-journal-text me-1"></i>Wage Register
                    </a>
                    <a href="index.php?page=advance/add&client_id=<?php echo $filterClientId; ?>&unit_id=<?php echo $filterUnitId; ?>&month=<?php echo $periodMonth; ?>&year=<?php echo $periodYear; ?>&load=1" 
                       target="_blank" class="btn btn-warning btn-sm">
                        <i class="bi bi-cash-stack me-1"></i>Add Advance
                    </a>
                    <button type="button" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;" onclick="sendSalaryWhatsApp()">
                        <i class="bi bi-whatsapp me-1"></i>Send Salary WhatsApp
                    </button>
                    <span id="whatsappStatus" style="font-size:0.82rem;"></span>
                    <?php endif; ?>
                    <small class="text-muted">All amounts in ₹ | OT included in Gross</small>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" style="font-size:0.78rem;">
                        <thead class="table-light">
                            <!-- Row 1: Grouped Headers -->
                            <tr>
                                <th rowspan="2" class="text-center" style="width:28px;">#</th>
                                <th rowspan="2">Code</th>
                                <th rowspan="2">Name</th>
                                <th rowspan="2">Unit</th>
                                <th colspan="5" class="text-center" style="background:#e7f3ff;">Attendance</th>
                                <th colspan="6" class="text-center" style="background:#e8f5e9;">Earnings (₹)</th>
                                <th rowspan="2" class="text-end" style="border-left:2px solid #adb5bd;background:#c8e6c9;"><strong>Gross</strong></th>
                                <th colspan="8" class="text-center" style="background:#fce4ec;">Deductions (₹)</th>
                                <th rowspan="2" class="text-end" style="border-left:2px solid #adb5bd;background:#ffcdd2;"><strong>Tot Ded</strong></th>
                                <th rowspan="2" class="text-end" style="border-left:2px solid #adb5bd;"><strong>Net Pay</strong></th>
                                <th rowspan="2" class="text-center" style="width:36px;">St</th>
                            </tr>
                            <!-- Row 2: Column Headers -->
                            <tr>
                                <th class="text-center" style="background:#e7f3ff;">Pr</th>
                                <th class="text-center" style="background:#e7f3ff;">W/O</th>
                                <th class="text-center" style="background:#e7f3ff;">Ex</th>
                                <th class="text-center fw-bold" style="background:#bbdefb;">PD</th>
                                <th class="text-center" style="background:#e7f3ff;">OT Hrs</th>
                                <!-- Earnings -->
                                <th class="text-end" style="background:#e8f5e9;">Basic+DA</th>
                                <th class="text-end" style="background:#e8f5e9;">HRA</th>
                                <th class="text-end" style="background:#e8f5e9;">L.Enc</th>
                                <th class="text-end" style="background:#e8f5e9;">B.Enc</th>
                                <th class="text-end" style="background:#e8f5e9;">Wash</th>
                                <th class="text-end" style="background:#e8f5e9;">OT Amt</th>
                                <!-- Deductions -->
                                <th class="text-end" style="background:#fce4ec;">PF</th>
                                <th class="text-end" style="background:#fce4ec;">ESI</th>
                                <th class="text-end" style="background:#fce4ec;">PT</th>
                                <th class="text-end" style="background:#fce4ec;">LWF</th>
                                <th class="text-end" style="background:#fce4ec;">Adv</th>
                                <th class="text-end" style="background:#f3e5f5;">Loan EMI</th>
                                <th class="text-end" style="background:#fce4ec;">Off</th>
                                <th class="text-end" style="background:#fce4ec;">Tr</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sumGross = 0; $sumDed = 0; $sumNet = 0;
                            foreach ($payrollData as $idx => $row): 
                                // Earnings
                                $basicDA  = floatval($row['basic_da_display'] ?? $row['basic_da'] ?? 0);
                                $hra      = floatval($row['hra'] ?? 0);
                                $leaveEnc = floatval($row['leave_encashment'] ?? 0);
                                $bonusEnc = floatval($row['bonus_encashment'] ?? 0);
                                $washing  = floatval($row['washing_allowance'] ?? 0);
                                $otAmount = floatval($row['overtime_amount'] ?? 0);
                                $gross    = floatval($row['gross_salary'] ?? ($basicDA + $hra + $leaveEnc + $bonusEnc + $washing + $otAmount));

                                // Deductions
                                $pfEmp    = floatval($row['pf_employee'] ?? 0);
                                $esiEmp   = floatval($row['esi_employee'] ?? 0);
                                $pt       = floatval($row['professional_tax'] ?? 0);
                                $lwf      = floatval($row['lwf_employee'] ?? 0);
                                $adv      = floatval($row['salary_advance'] ?? 0);
                                $loanEmi  = floatval($row['loan_emi'] ?? 0);
                                $offDed   = floatval($row['office_deduction'] ?? 0);
                                $trustDed = floatval($row['trust_deduction'] ?? 0);
                                $totDed   = floatval($row['total_deductions'] ?? 0);
                                $netPay   = floatval($row['net_pay'] ?? 0);

                                // Attendance breakdown
                                $attPr    = floatval($row['att_present'] ?? 0);
                                $attWO    = floatval($row['att_wo'] ?? 0);
                                $attEx    = floatval($row['att_extra'] ?? 0);
                                $paidDays = $attPr + $attWO + $attEx;
                                $otHrs    = floatval($row['att_ot_hours'] ?? 0);

                                $sumGross += $gross;
                                $sumDed   += $totDed;
                                $sumNet   += $netPay;
                            ?>
                            <tr class="<?php echo ($row['salary_hold'] ?? 0) ? 'table-warning' : ''; ?>">
                                <td class="text-center text-muted"><?php echo $idx + 1; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td class="text-nowrap"><?php echo sanitize($row['full_name']); ?></td>
                                <td><small><?php echo sanitize($row['unit_name']); ?></small></td>
                                <!-- Attendance -->
                                <td class="text-center"><?php echo $attPr; ?></td>
                                <td class="text-center"><?php echo $attWO; ?></td>
                                <td class="text-center"><?php echo $attEx; ?></td>
                                <td class="text-center fw-bold" style="background:#bbdefb;"><?php echo $paidDays; ?></td>
                                <td class="text-center text-info"><?php echo $otHrs; ?></td>
                                <!-- Earnings -->
                                <td class="text-end"><?php echo formatCurrency($basicDA); ?></td>
                                <td class="text-end"><?php echo formatCurrency($hra); ?></td>
                                <td class="text-end"><?php echo formatCurrency($leaveEnc); ?></td>
                                <td class="text-end"><?php echo formatCurrency($bonusEnc); ?></td>
                                <td class="text-end"><?php echo formatCurrency($washing); ?></td>
                                <td class="text-end text-info"><?php echo formatCurrency($otAmount); ?></td>
                                <!-- Gross -->
                                <td class="text-end fw-bold" style="border-left:2px solid #adb5bd;background:#c8e6c9;"><?php echo formatCurrency($gross); ?></td>
                                <!-- Deductions -->
                                <td class="text-end"><?php echo formatCurrency($pfEmp); ?></td>
                                <td class="text-end"><?php echo formatCurrency($esiEmp); ?></td>
                                <td class="text-end"><?php echo formatCurrency($pt); ?></td>
                                <td class="text-end"><?php echo formatCurrency($lwf); ?></td>
                                <td class="text-end"><?php echo formatCurrency($adv); ?></td>
                                <td class="text-end" style="background:#f3e5f5;"><?php echo $loanEmi > 0 ? formatCurrency($loanEmi) : '-'; ?></td>
                                <td class="text-end"><?php echo formatCurrency($offDed); ?></td>
                                <td class="text-end"><?php echo formatCurrency($trustDed); ?></td>
                                <!-- Total Deductions -->
                                <td class="text-end fw-bold text-danger" style="border-left:2px solid #adb5bd;background:#ffcdd2;"><?php echo formatCurrency($totDed); ?></td>
                                <!-- Net Pay -->
                                <td class="text-end fw-bold text-success" style="border-left:2px solid #adb5bd;"><?php echo formatCurrency($netPay); ?></td>
                                <!-- Status -->
                                <td class="text-center">
                                    <span class="badge bg-<?php 
                                        echo ($row['status'] ?? '') === 'Processed' ? 'info' : 
                                            (($row['status'] ?? '') === 'Approved' ? 'success' : 
                                            (($row['status'] ?? '') === 'Hold' ? 'warning' : 'secondary'));
                                    ?>" title="<?php echo sanitize($row['status'] ?? 'Draft'); ?>
                                    <?php if ($row['salary_hold'] ?? 0) echo ' — HELD'; ?>">
                                        <?php 
                                        $stLabel = $row['status'] ?? 'Draft';
                                        echo ($row['salary_hold'] ?? 0) ? 'HLD' : substr($stLabel, 0, 3); 
                                        ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold" style="font-size:0.78rem;">
                            <tr>
                                <td colspan="15"></td>
                                <td class="text-end" style="border-left:2px solid #adb5bd;background:#c8e6c9;"><?php echo formatCurrency($sumGross); ?></td>
                                <td colspan="8"></td>
                                <td class="text-end text-danger" style="border-left:2px solid #adb5bd;background:#ffcdd2;"><?php echo formatCurrency($sumDed); ?></td>
                                <td class="text-end text-success" style="border-left:2px solid #adb5bd;"><?php echo formatCurrency($sumNet); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
// Load units when client changes
document.getElementById('clientSelect')?.addEventListener('change', function() {
    const clientId = this.value;
    const unitSelect = document.getElementById('unitSelect');
    
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!clientId) {
        unitSelect.innerHTML = '<option value="">All Units</option>';
        return;
    }
    
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(response => response.json())
        .then(data => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data.units) {
                data.units.forEach(unit => {
                    const option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
        })
        .catch(() => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
        });
});

// Recalculate all units
// Send Salary WhatsApp Notification (bulk)
function sendSalaryWhatsApp() {
    var statusEl = document.getElementById('whatsappStatus');
    statusEl.innerHTML = '<span style="color:#f7c948">Sending WhatsApp notifications...</span>';

    fetch('index.php?page=api/whatsapp-salary&period_id=<?php echo $selectedPeriod["id"]; ?>&client_id=<?php echo $filterClientId; ?>&unit_id=<?php echo $filterUnitId; ?>')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statusEl.innerHTML = '<span style="color:#25D366">' + data.message + '</span>';
            } else {
                statusEl.innerHTML = '<span style="color:#ea0038">' + (data.message || 'Failed') + '</span>';
            }
        })
        .catch(err => {
            statusEl.innerHTML = '<span style="color:#ea0038">Error: ' + err.message + '</span>';
        });
}

function recalculateAll() {
    if (!confirm('This will recalculate payroll for ALL displayed units with fresh attendance/salary data. Continue?')) {
        return;
    }
    
    // Find all recalculate buttons and click them
    document.querySelectorAll('button[name="recalculate_unit"]').forEach(btn => {
        btn.click();
    });
}
</script>
JS;
?>
