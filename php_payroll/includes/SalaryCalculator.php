<?php
/**
 * RCS HRMS Pro — Salary Calculator Utilities
 * Reverse calculation from Net → Gross using New Wage Code rules.
 * Template auto-allocation for new employees.
 */

if (!function_exists('reverseCalculateSalary')) {

/**
 * Reverse calculate gross components from a target Net Salary.
 * New Wage Code: Basic+DA must be >= 50% of total gross.
 *
 * @param float  $netSalary      Target net (take-home)
 * @param float  $bonusPercent   0 or 8.33 — bonus provision % of basic_da
 * @param float  $leavePercent   0 to 11.23 — leave encashment % of basic_da
 * @param bool   $pfApplicable
 * @param bool   $esiApplicable
 * @param bool   $ptApplicable
 * @param bool   $lwfApplicable
 * @param string $state          For PT + LWF lookup
 * @param string $workerCategory For minimum wage check
 * @param string $effectiveDate  For minimum wage effective_from lookup (Y-m-d)
 * @param object $db             Database instance
 * @return array
 */
function reverseCalculateSalary(
    float $netSalary,
    float $bonusPercent,
    float $leavePercent,
    bool $pfApplicable,
    bool $esiApplicable,
    bool $ptApplicable,
    bool $lwfApplicable,
    string $state,
    string $workerCategory,
    string $effectiveDate,
    $db
): array {
    if ($netSalary <= 0) {
        return _emptyResult(0);
    }

    // ── Step 1: Initial PT + LWF estimate ──
    $ptAmount = _lookupPT($db, $state, $netSalary);
    $lwfAmount = _lookupLWF($db, $state);

    // ── Step 2: Iterative solver (converges in 3-5 iterations) ──
    $grossEstimate = $netSalary + $ptAmount + $lwfAmount;
    $deductionRate = ($pfApplicable ? 0.12 : 0) + ($esiApplicable ? 0.0075 : 0);
    if ($deductionRate < 1) {
        $grossEstimate = $grossEstimate / (1 - $deductionRate);
    }
    if ($grossEstimate <= 0) {
        return _emptyResult($netSalary);
    }

    $basicDa = 0; $hra = 0; $bonusAmt = 0; $leaveAmt = 0; $actualGross = 0;
    $pfDeduction = 0; $esiDeduction = 0; $totalDeductions = 0;

    for ($i = 0; $i < 6; $i++) {
        // Basic+DA = 50% of gross (New Wage Code)
        $basicDa = round($grossEstimate * 0.50, 2);
        $bonusAmt = round($basicDa * $bonusPercent / 100, 2);
        $leaveAmt = round($basicDa * $leavePercent / 100, 2);
        $washingAmt = 0;

        // HRA = residual (absorbs all rounding)
        $hra = max(0, round($grossEstimate - $basicDa - $bonusAmt - $leaveAmt - $washingAmt, 2));

        // ── Recheck 50-50 compliance ──
        $actualGross = $basicDa + $bonusAmt + $leaveAmt + $washingAmt + $hra;
        if ($basicDa < $actualGross * 0.50 - 0.01) {
            $basicDa = round($actualGross / 2, 2);
            $bonusAmt = round($basicDa * $bonusPercent / 100, 2);
            $leaveAmt = round($basicDa * $leavePercent / 100, 2);
            $hra = max(0, round($actualGross - $basicDa - $bonusAmt - $leaveAmt, 2));
            $actualGross = $basicDa + $bonusAmt + $leaveAmt + $washingAmt + $hra;
        }

        // ── Deductions ──
        $pfDeduction = $pfApplicable ? round(min($basicDa, 15000) * 0.12, 2) : 0;
        $esiDeduction = ($esiApplicable && $actualGross <= 21000) ? round($actualGross * 0.0075, 2) : 0;
        $ptAmount = _lookupPT($db, $state, $actualGross);
        $totalDeductions = $pfDeduction + $esiDeduction + $ptAmount + $lwfAmount;
        $calculatedNet = round($actualGross - $totalDeductions, 2);

        // ── Adjust HRA to hit exact net ──
        $diff = $netSalary - $calculatedNet;
        $hra = max(0, round($hra + $diff, 2));
        $actualGross = $basicDa + $bonusAmt + $leaveAmt + $washingAmt + $hra;

        // Recheck ESI on new gross
        $esiDeduction = ($esiApplicable && $actualGross <= 21000) ? round($actualGross * 0.0075, 2) : 0;
        $totalDeductions = $pfDeduction + $esiDeduction + $ptAmount + $lwfAmount;
        $calculatedNet = round($actualGross - $totalDeductions, 2);

        // ── Convergence ──
        if (abs($calculatedNet - $netSalary) < 0.01) break;
        $grossEstimate = $actualGross + ($netSalary - $calculatedNet);
    }

    // ── Minimum wage check ──
    $minWageWarning = _checkMinWage($db, $state, $workerCategory, $effectiveDate, $actualGross);

    return [
        'net_salary'         => round($netSalary, 2),
        'basic_da'           => round($basicDa, 2),
        'hra'                => round($hra, 2),
        'leave_encashment'   => round($leaveAmt, 2),
        'bonus_encashment'   => round($bonusAmt, 2),
        'washing_allowance'  => 0,
        'gross_salary'       => round($actualGross, 2),
        'deductions'         => [
            'pf'  => round($pfDeduction, 2),
            'esi' => round($esiDeduction, 2),
            'pt'  => round($ptAmount, 2),
            'lwf' => round($lwfAmount, 2),
        ],
        'total_deductions'   => round($totalDeductions, 2),
        'calculated_net'     => round($calculatedNet, 2),
        'fifty_fifty_ok'     => $basicDa >= ($actualGross * 0.50 - 0.01),
        'basic_percent'      => $actualGross > 0 ? round(($basicDa / $actualGross) * 100, 2) : 0,
        'min_wage_warning'   => $minWageWarning,
        'iterations'         => $i + 1,
    ];
}

/**
 * Apply matching salary template to an employee.
 *
 * @param int    $empId
 * @param object $db
 * @param int    $month  (1-12)
 * @param int    $year   (e.g. 2026)
 * @return bool
 */
function applyTemplateToEmployee(int $empId, $db, int $month, int $year): bool {
    $emp = $db->fetch(
        "SELECT e.unit_id, e.worker_category, u.state
         FROM employees e
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.id = ?",
        [$empId]
    );
    if (!$emp || !$emp['unit_id']) return false;

    $templates = $db->fetchAll(
        "SELECT * FROM unit_salary_templates
         WHERE unit_id = ? AND is_active = 1
         ORDER BY is_default DESC, id ASC",
        [$emp['unit_id']]
    );
    if (empty($templates)) return false;

    // Find matching template by worker_category
    $matched = null;
    $empCategory = $emp['worker_category'] ?? '';
    foreach ($templates as $t) {
        $cats = array_map('trim', explode(',', $t['worker_categories'] ?? ''));
        $cats = array_filter($cats);
        if (in_array($empCategory, $cats)) {
            $matched = $t;
            break;
        }
    }
    // Fallback: catch-all (empty/null worker_categories)
    if (!$matched) {
        foreach ($templates as $t) {
            if (empty($t['worker_categories'])) {
                $matched = $t;
                break;
            }
        }
    }
    if (!$matched) return false;

    $effectiveFrom = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';

    // Close any existing open-ended structures
    $db->query(
        "UPDATE employee_salary_structures
         SET effective_to = DATE_SUB(?, INTERVAL 1 DAY), updated_at = NOW()
         WHERE employee_id = ? AND effective_to IS NULL",
        [$effectiveFrom, $empId]
    );

    // Insert new structure from template
    try {
        $db->query(
            "INSERT INTO employee_salary_structures
             (employee_id, template_id, applied_month, effective_from,
              basic_da, hra, leave_encashment, bonus_encashment, washing_allowance,
              gross_salary, pf_applicable, esi_applicable, pt_applicable, lwf_applicable,
              overtime_applicable, bonus_applicable, gratuity_applicable, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $empId, $matched['id'], $effectiveFrom, $effectiveFrom,
                $matched['basic_da'], $matched['hra'], $matched['leave_encashment'],
                $matched['bonus_encashment'], 0,
                $matched['gross_salary'], $matched['pf_applicable'], $matched['esi_applicable'],
                $matched['pt_applicable'], $matched['lwf_applicable'],
                $matched['overtime_applicable'], $matched['bonus_applicable'],
                $matched['gratuity_applicable'],
                $_SESSION['user_id'] ?? null,
            ]
        );
    } catch (\Throwable $e) {
        error_log("[applyTemplate] Failed for emp {$empId}: " . $e->getMessage());
        return false;
    }
    return true;
}

// ── Internal helpers ──

function _lookupPT($db, string $state, float $salaryEstimate): float {
    if (!$state || !$salaryEstimate) return 0;
    try {
        $row = $db->fetch(
            "SELECT ptr.pt_amount FROM professional_tax_rates ptr
             JOIN states s ON ptr.state_id = s.id
             WHERE (s.state_code = ? OR s.state_name = ?)
             AND ptr.salary_from <= ?
             AND (ptr.salary_to IS NULL OR ptr.salary_to >= ?)
             ORDER BY ptr.salary_from DESC LIMIT 1",
            [$state, $state, $salaryEstimate, $salaryEstimate]
        );
        return floatval($row['pt_amount'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function _lookupLWF($db, string $state): float {
    if (!$state) return 0;
    try {
        $row = $db->fetch(
            "SELECT employee_contribution FROM lwf_rates
             WHERE (state_code = ? OR state = ?) AND is_active = 1 LIMIT 1",
            [$state, $state]
        );
        return floatval($row['employee_contribution'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function _checkMinWage($db, string $state, string $category, string $date, float $gross): ?string {
    if (!$state && !$category) return null;
    try {
        $row = $db->fetch(
            "SELECT minimum_wage FROM minimum_wages
             WHERE (state = ? OR state = 'All')
             AND designation LIKE CONCAT('%', ?, '%')
             AND effective_from <= ?
             ORDER BY effective_from DESC LIMIT 1",
            [$state ?: 'All', $category, $date ?: date('Y-m-d')]
        );
        $minWage = floatval($row['minimum_wage'] ?? 0);
        if ($minWage > 0 && $gross > 0 && $gross < $minWage) {
            return "Gross {$gross} is below minimum wage {$minWage} for {$category}";
        }
    } catch (\Throwable $e) {}
    return null;
}

function _emptyResult(float $netSalary): array {
    return [
        'net_salary' => round($netSalary, 2), 'basic_da' => 0, 'hra' => 0,
        'leave_encashment' => 0, 'bonus_encashment' => 0, 'washing_allowance' => 0,
        'gross_salary' => 0, 'deductions' => ['pf' => 0, 'esi' => 0, 'pt' => 0, 'lwf' => 0],
        'total_deductions' => 0, 'calculated_net' => 0, 'fifty_fifty_ok' => false,
        'basic_percent' => 0, 'min_wage_warning' => null, 'iterations' => 0,
    ];
}
}
