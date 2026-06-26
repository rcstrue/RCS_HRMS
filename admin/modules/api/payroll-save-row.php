<?php
/**
 * RCS HRMS Pro - Payroll Save Row API
 * Version: 1.0.0
 *
 * AJAX endpoint for saving a single employee's payroll row.
 * Route: index.php?page=api/payroll-save-row
 *
 * Receives JSON POST with:
 *   - employee_id (int, employees.id)
 *   - employee_code (string, employees.employee_code)
 *   - month, year, unit_id
 *   - pf_applicable, esi_applicable, pt_applicable, lwf_applicable (bool)
 *   - wage_details { basic_da, hra, leave_encashment, bonus_encashment, washing_allowance, gross_salary }
 *   - attendance { total_present, total_wo, total_extra, overtime_hours, total_paid_days }
 *   - advances { advance, office_deduction }
 *   - payroll { all calculated values }
 *
 * Handles:
 *   1. Upsert attendance_summary (employee_id = employees.id)
 *   2. Upsert employee_salary_structures (effective_from = YYYY-MM-01)
 *   3. Upsert employee_advances (employee_id = employees.id)
 *   4. Upsert payroll (employee_id = employee_code string)
 */

header('Content-Type: application/json');

// ── Authentication check ────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// ── Only POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Parse JSON input ────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// ── Validate required fields ────────────────────────────────────
$employeeId   = (int)($data['employee_id'] ?? 0);
$employeeCode = trim($data['employee_code'] ?? '');
$month        = (int)($data['month'] ?? 0);
$year         = (int)($data['year'] ?? 0);
$unitId       = (int)($data['unit_id'] ?? 0);
$payrollPeriodId = (int)($data['payroll_period_id'] ?? 0);

if ($employeeId <= 0 || empty($employeeCode) || $month < 1 || $month > 12 || $year < 2020) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields (employee_id, employee_code, month, year)']);
    exit;
}

$wageDetails = $data['wage_details'] ?? [];
$attendance  = $data['attendance'] ?? [];
$advances    = $data['advances'] ?? [];
$payroll     = $data['payroll'] ?? [];

// ── Server-side attendance validation (Audit Issue #5) ──
$attPresent  = (float)($attendance['total_present'] ?? 0);
$attWO       = (float)($attendance['total_wo'] ?? 0);
$attExtra    = (float)($attendance['total_extra'] ?? 0);
$attOtHours  = (float)($attendance['overtime_hours'] ?? 0);
$attPaidDays = (float)($attendance['total_paid_days'] ?? 0);

$validationErrors = [];
if ($attPresent < 0) $validationErrors[] = 'Present days cannot be negative';
if ($attWO < 0)      $validationErrors[] = 'Weekly off days cannot be negative';
if ($attExtra < 0)   $validationErrors[] = 'Extra days cannot be negative';
if ($attOtHours < 0) $validationErrors[] = 'OT hours cannot be negative';

// Get total_days for validation
$calDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$vTotalDays = $calDays;
if ($payrollPeriodId > 0) {
    $vPeriod = $db->fetch("SELECT pay_days FROM payroll_periods WHERE id = ?", [$payrollPeriodId]);
    if ($vPeriod) $vTotalDays = (int)$vPeriod['pay_days'];
}
$computedPaidDays = $attPresent + $attWO + $attExtra;
if ($computedPaidDays > $vTotalDays) {
    $validationErrors[] = "Paid days ($computedPaidDays) cannot exceed total days ($vTotalDays)";
}
if ($attOtHours > 12) {
    $validationErrors[] = "OT hours ($attOtHours) exceeds daily limit — verify if authorized";
}

if (!empty($validationErrors)) {
    echo json_encode([
        'success' => false,
        'message' => 'Validation: ' . implode('; ', $validationErrors)
    ]);
    exit;
}

try {
    $db->exec("START TRANSACTION");

    // Ensure loan_emi column exists in payroll table
    try {
        $db->query("SELECT loan_emi FROM payroll LIMIT 1");
    } catch (Exception $colEx) {
        $db->exec("ALTER TABLE payroll ADD COLUMN loan_emi DECIMAL(10,2) DEFAULT 0.00 AFTER salary_advance");
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. ATTENDANCE_SUMMARY — Upsert on employee_id + month + year
    // ═══════════════════════════════════════════════════════════════
    $existingAtt = $db->fetch(
        "SELECT id FROM attendance_summary WHERE employee_id = ? AND month = ? AND year = ?",
        [$employeeId, $month, $year]
    );

    $attData = [
        'employee_id'    => $employeeId,
        'month'          => $month,
        'year'           => $year,
        'total_present'  => (float)($attendance['total_present'] ?? 0),
        'total_wo'       => (float)($attendance['total_wo'] ?? 0),
        'total_extra'    => (float)($attendance['total_extra'] ?? 0),
        'overtime_hours' => (float)($attendance['overtime_hours'] ?? 0),
        'total_paid_days'=> (float)($attendance['total_paid_days'] ?? 0),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];

    if ($existingAtt) {
        $db->update('attendance_summary', $attData, 'id = :id', ['id' => $existingAtt['id']]);
    } else {
        $attData['created_at'] = date('Y-m-d H:i:s');
        $db->insert('attendance_summary', $attData);
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. EMPLOYEE_SALARY_STRUCTURES — Upsert for this month
    // ═══════════════════════════════════════════════════════════════
    $effFrom = sprintf('%04d-%02d-01', $year, $month);

    $existingSal = $db->fetch(
        "SELECT id FROM employee_salary_structures 
         WHERE employee_id = ? AND effective_from = ?",
        [$employeeId, $effFrom]
    );

    $grossSalary = (float)($wageDetails['gross_salary'] ?? 0);
    if ($grossSalary == 0) {
        $grossSalary = (float)($wageDetails['basic_da'] ?? 0)
                     + (float)($wageDetails['hra'] ?? 0)
                     + (float)($wageDetails['leave_encashment'] ?? 0)
                     + (float)($wageDetails['bonus_encashment'] ?? 0)
                     + (float)($wageDetails['washing_allowance'] ?? 0);
    }

    $pfApplicable  = !empty($data['pf_applicable']) ? 1 : 0;
    $esiApplicable = !empty($data['esi_applicable']) ? 1 : 0;
    $ptApplicable  = !empty($data['pt_applicable']) ? 1 : 0;
    $lwfApplicable = !empty($data['lwf_applicable']) ? 1 : 0;

    $salData = [
        'employee_id'       => $employeeId,
        'effective_from'    => $effFrom,
        'effective_to'      => null,  // Current (no end date)
        'basic_da'          => (float)($wageDetails['basic_da'] ?? 0),
        'hra'               => (float)($wageDetails['hra'] ?? 0),
        'leave_encashment'  => (float)($wageDetails['leave_encashment'] ?? 0),
        'bonus_encashment'  => (float)($wageDetails['bonus_encashment'] ?? 0),
        'washing_allowance' => (float)($wageDetails['washing_allowance'] ?? 0),
        'gross_salary'      => $grossSalary,
        'pf_applicable'     => $pfApplicable,
        'esi_applicable'    => $esiApplicable,
        'pt_applicable'     => $ptApplicable,
        'lwf_applicable'    => $lwfApplicable,
        'overtime_applicable'=> 1,
        'updated_at'        => date('Y-m-d H:i:s'),
    ];

    if ($existingSal) {
        $db->update('employee_salary_structures', $salData, 'id = :id', ['id' => $existingSal['id']]);
    } else {
        $salData['created_at'] = date('Y-m-d H:i:s');
        $db->insert('employee_salary_structures', $salData);
    }

    // Close any previous salary structure that was open-ended
    // (effective_to IS NULL and effective_from < current effFrom)
    $db->query(
        "UPDATE employee_salary_structures 
         SET effective_to = DATE_SUB(?, INTERVAL 1 DAY), updated_at = NOW() 
         WHERE employee_id = ? AND effective_from < ? AND effective_to IS NULL AND id != ?",
        [$effFrom, $employeeId, $effFrom, $existingSal ? $existingSal['id'] : 0]
    );

    // ═══════════════════════════════════════════════════════════════
    // 3. EMPLOYEE_ADVANCES — Upsert on employee_id + month + year
    // ═══════════════════════════════════════════════════════════════
    $existingAdv = $db->fetch(
        "SELECT id FROM employee_advances WHERE employee_id = ? AND month = ? AND year = ?",
        [$employeeId, $month, $year]
    );

    $totalAdvance = (float)($advances['advance'] ?? 0);
    $officeDeduction = (float)($advances['office_deduction'] ?? 0);

    $advData = [
        'employee_id'    => $employeeId,
        'month'          => $month,
        'year'           => $year,
        'adv1'           => $totalAdvance,   // Total advance stored in adv1
        'adv2'           => 0,
        'office_advance' => $officeDeduction, // Office deduction stored in office_advance
        'dress_advance'  => 0,
        'updated_at'     => date('Y-m-d H:i:s'),
    ];

    // Fix: exclude office_deduction from advance total to avoid double-counting
    // process.php queries advance separately from office_deduction.
    // We store them in separate columns so total_advance = adv1 only (no office_advance in it).

    if ($existingAdv) {
        $db->update('employee_advances', $advData, 'id = :id', ['id' => $existingAdv['id']]);
    } else {
        $advData['created_at'] = date('Y-m-d H:i:s');
        $db->insert('employee_advances', $advData);
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. PAYROLL — Upsert on employee_id(=code) + payroll_period_id (preferred) or month+year
    // ═══════════════════════════════════════════════════════════════
    $existingPay = null;
    if ($payrollPeriodId > 0) {
        $existingPay = $db->fetch(
            "SELECT id FROM payroll WHERE employee_id = ? AND payroll_period_id = ?",
            [$employeeCode, $payrollPeriodId]
        );
    }
    if (empty($existingPay)) {
        // Fallback: join via payroll_periods to find by month/year
        $existingPay = $db->fetch(
            "SELECT p.id FROM payroll p
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE p.employee_id = ? AND pp.month = ? AND pp.year = ?
             ORDER BY p.id DESC LIMIT 1",
            [$employeeCode, $month, $year]
        );
    }

    $totalDays = (int)($payroll['total_days'] ?? 0) ?: cal_days_in_month(CAL_GREGORIAN, $month, $year);
    // Override total_days from payroll period if available
    if ($payrollPeriodId > 0) {
        $periodDays = $db->fetch("SELECT pay_days FROM payroll_periods WHERE id = ?", [$payrollPeriodId]);
        if ($periodDays) $totalDays = (int)$periodDays['pay_days'];
    }

    // Read PF/ESI rates from DB (for employer contribution calculation if not sent from JS)
    $pfEmployerShare = 3.67; $pfEmployerEps = 8.33; $pfEmployerEdlis = 0.50; $pfEpfAdmin = 0.50; $esiEmployerShare = 3.25;
    try {
        $pfRate = $db->fetch("SELECT * FROM pf_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
        if ($pfRate) {
            $pfEmployerShare = (float)$pfRate['employer_share_pf'];
            $pfEmployerEps  = (float)$pfRate['employer_share_eps'];
            $pfEmployerEdlis = (float)$pfRate['employer_share_edlis'];
            $pfEpfAdmin     = (float)$pfRate['epf_admin_charges'];
        }
    } catch (Exception $e) {}
    try {
        $esiRate = $db->fetch("SELECT * FROM esi_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
        if ($esiRate) {
            $esiEmployerShare = (float)$esiRate['employer_share'];
        }
    } catch (Exception $e) {}

    // CTC: gross + employer contributions (matching class.payroll.php)
    $ctcFromPayload = round2($payroll['ctc'] ?? 0);
    if ($ctcFromPayload > 0) {
        $ctc = $ctcFromPayload;
    } else {
        // Fallback: calculate from saved values
        $grossSalary = round2($payroll['gross_salary'] ?? 0);
        $pfEmployeeVal = round2($payroll['pf_employee'] ?? 0);
        $esiEmployeeVal = round2($payroll['esi_employee'] ?? 0);
        $pfBase = min($pfEmployeeVal, round2($payroll['basic_da'] ?? 0));
        $employerContrib = round2($pfBase * ($pfEmployerShare + $pfEmployerEps + $pfEmployerEdlis + $pfEpfAdmin) / 100);
        $ctc = round2($grossSalary + $employerContrib);
    }

    // Round all monetary values to 2 decimal places
    // NOTE: gross_earnings from JS already INCLUDES overtime_amount — do NOT add it again
    $calcGross  = round2($payroll['gross_earnings'] ?? 0);
    $calcDed    = round2($payroll['total_deductions'] ?? 0);
    $calcNetPay = round($calcGross - $calcDed); // Round to nearest ₹.00

    $payData = [
        'employee_id'      => $employeeCode,
        'unit_id'          => $unitId,
        'payroll_period_id'=> $payrollPeriodId ?: null,
        'basic_da'         => round2($payroll['basic_da'] ?? 0),
        'hra'              => round2($payroll['hra'] ?? 0),
        'leave_encashment' => round2($payroll['leave_encashment'] ?? 0),
        'bonus_encashment' => round2($payroll['bonus_encashment'] ?? 0),
        'washing_allowance'=> round2($payroll['washing_allowance'] ?? 0),
        'overtime_amount'  => round2($payroll['overtime_amount'] ?? 0),
        'overtime_hours'   => round2($payroll['overtime_hours'] ?? 0),
        'gross_earnings'   => $calcGross,
        'gross_salary'     => round2($payroll['gross_salary'] ?? 0),
        'pf_employee'      => round2($payroll['pf_employee'] ?? 0),
        'esi_employee'     => round2($payroll['esi_employee'] ?? 0),
        'professional_tax' => round2($payroll['professional_tax'] ?? 0),
        'lwf_employee'     => round2($payroll['lwf_employee'] ?? 0),
        'salary_advance'   => round2($payroll['salary_advance'] ?? 0),
        'office_deduction' => round2($payroll['office_deduction'] ?? 0),
        'total_deductions' => $calcDed,
        'net_pay'          => $calcNetPay,
        'paid_days'        => (int)($payroll['paid_days'] ?? 0),
        'total_days'       => $totalDays,
        'ctc'              => $ctc,
        'status'           => 'Processed',
        'salary_hold'      => 0,
        'payroll_dirty'    => 0,
        'last_calculated_at' => date('Y-m-d H:i:s'),
        'calculated_by'    => (int)($_SESSION['user_id'] ?? 0) ?: null,
        'updated_at'       => date('Y-m-d H:i:s'),
    ];

    // Employer contribution columns (matching process.php)
    $payData['pf_employer']            = round2($payroll['pf_employer'] ?? 0);
    $payData['eps_employer']           = round2($payroll['eps_employer'] ?? 0);
    $payData['edlis_employer']         = round2($payroll['edlis_employer'] ?? 0);
    $payData['epf_admin_charges']      = round2($payroll['epf_admin_charges'] ?? 0);
    $payData['esi_employer']           = round2($payroll['esi_employer'] ?? 0);
    $payData['total_employer_contribution'] = round2($payroll['total_employer_contribution'] ?? 0);

    // Include loan_emi from the grid input
    $payData['loan_emi'] = round2($advances['loan_emi'] ?? 0);

    if ($existingPay) {
        $db->update('payroll', $payData, 'id = :id', ['id' => $existingPay['id']]);
    } else {
        $payData['created_at'] = date('Y-m-d H:i:s');
        $db->insert('payroll', $payData);
    }

    // ── Update employee statutory flags in salary structure ──────
    try {
        $db->update('employee_salary_structures', [
            'pf_applicable'  => $pfApplicable,
            'esi_applicable' => $esiApplicable,
            'pt_applicable'  => $ptApplicable,
            'lwf_applicable' => $lwfApplicable,
        ], 'employee_id = :eid AND effective_to IS NULL', ['eid' => $employeeId]);
    } catch (Exception $e) {
        // Column may not exist — ignore
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. LOAN EMI LOG & BALANCE UPDATE
    // (Payroll record already saved with loan_emi from grid — don't double-count)
    // Only update loan_emi_log and employee_loans balance.
    // ═══════════════════════════════════════════════════════════════
    $loanDeductionTotal = 0;
    try {
        // Ensure loan tables exist
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
            `payroll_id` int(11) DEFAULT NULL,
            `deducted_via_payroll` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_loan_month_year` (`loan_id`, `month`, `year`),
            KEY `idx_employee_month` (`employee_id`, `month`, `year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Only proceed if the grid had a loan_emi value > 0
        $gridLoanEmi = (float)($advances['loan_emi'] ?? 0);
        if ($gridLoanEmi <= 0) {
            // No EMI to process — but clean up any stale emi_log entries
            // (from previous failed saves that partially committed)
            try {
                $db->query("DELETE FROM loan_emi_log WHERE employee_id = ? AND month = ? AND year = ? AND loan_id IN (SELECT id FROM employee_loans WHERE employee_id = ?)",
                    [$employeeId, $month, $year, $employeeId]);
            } catch (Exception $e) {}
            goto loan_done;
        }

        // Get active loans for this employee that have started by this month
        $empLoans = $db->fetchAll(
            "SELECT * FROM employee_loans
             WHERE employee_id = ? AND status = 'Active'
             AND balance_amount > 0
             AND (start_year < ? OR (start_year = ? AND start_month <= ?))",
            [$employeeId, $year, $year, $month]
        );

        foreach ($empLoans as $empLoan) {
            // Check if EMI already deducted for this month
            $alreadyDeducted = $db->fetch(
                "SELECT id FROM loan_emi_log WHERE loan_id = ? AND month = ? AND year = ?",
                [$empLoan['id'], $month, $year]
            );

            if ($alreadyDeducted) {
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

            // Handle last EMI: adjust to remaining balance
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

            // Record EMI log (linked to payroll row — Audit Issue #7)
            $db->insert('loan_emi_log', [
                'loan_id' => $empLoan['id'],
                'employee_id' => $employeeId,
                'month' => $month,
                'year' => $year,
                'emi_amount' => $emiAmount,
                'principal_component' => $principalComponent,
                'interest_component' => $interestComponent,
                'balance_after' => $newBalance,
                'deducted_via_payroll' => 1
            ]);

            // Try to link to payroll row (payroll.employee_id is employee_code string)
            try {
                $linkedPayrollId = null;
                if ($payrollPeriodId > 0) {
                    $pRow = $db->fetch(
                        "SELECT id FROM payroll WHERE employee_id = ? AND payroll_period_id = ?",
                        [$employeeCode, $payrollPeriodId]
                    );
                    $linkedPayrollId = $pRow ? (int)$pRow['id'] : null;
                }
                if ($linkedPayrollId) {
                    $db->query(
                        "UPDATE loan_emi_log SET payroll_id = ? WHERE loan_id = ? AND month = ? AND year = ?",
                        [$linkedPayrollId, $empLoan['id'], $month, $year]
                    );
                }
            } catch (Exception $e) {}

            // Update loan balance and status
            $newStatus = ($newBalance <= 0) ? 'Closed' : 'Active';
            $db->update('employee_loans', [
                'balance_amount' => $newBalance,
                'emi_deducted' => (int)$empLoan['emi_deducted'] + 1,
                'status' => $newStatus
            ], 'id = :id', ['id' => $empLoan['id']]);

            $loanDeductionTotal += $emiAmount;
        }

        // NOTE: We do NOT update the payroll record here.
        // The loan_emi was already included in the grid input and saved in step 4.
        // Updating again would double-count the deduction.

    } catch (Exception $loanEx) {
        // Log but don't fail payroll save if loan deduction fails
        error_log('Loan EMI auto-deduction failed: ' . $loanEx->getMessage());
    }
    loan_done:

    $db->exec("COMMIT");

    echo json_encode([
        'success' => true,
        'message' => 'Payroll saved for ' . $employeeCode . ($loanDeductionTotal > 0 ? ' (Loan EMI ₹' . number_format($loanDeductionTotal, 2) . ' auto-deducted)' : ''),
        'employee_code' => $employeeCode,
        'net_pay' => $payData['net_pay'],
        'gross_salary' => $payData['gross_salary'],
        'loan_deduction' => $loanDeductionTotal,
    ]);

} catch (Exception $e) {
    // Rollback on error
    try { $db->exec("ROLLBACK"); } catch (Exception $re) {}

    error_log("Payroll Save Row Error [{$employeeCode}]: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Error saving payroll: ' . $e->getMessage(),
        'employee_code' => $employeeCode,
    ]);
}

/**
 * Helper: round to 2 decimal places
 */
function round2($val) {
    return round((float)$val, 2);
}
