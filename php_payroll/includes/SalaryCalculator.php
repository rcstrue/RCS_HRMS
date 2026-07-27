<?php
/**
 * RCS HRMS Pro — Salary Calculator Engine
 * =====================================================================
 * Reverse calculation from Target Net → Gross → Components.
 *
 * ALGORITHM (per HR spec — 2025):
 *
 *   INPUT:  target_net, worker_category, unit_id (→ state, zone, PF/ESI/PT/LWF flags)
 *
 *   STEP 1: Lookup min_wage for (state, zone, worker_category, effective_date)
 *   STEP 2: Lookup PT slab + LWF rate for state
 *
 *   STEP 3: Iterative solver (max 20 iterations, tolerance ₹0.01):
 *
 *     Basic+DA = MAX(min_wage, 50% of gross_estimate)
 *        ↑ For high target net, the 50% rule drives Basic+DA UP.
 *          For low target net, min_wage is the floor.
 *
 *     Then escalate components IN ORDER:
 *
 *       Level 0 — Basic+DA only
 *         gross = basic_da
 *         net = gross − (PF + ESI + PT + LWF)
 *         if net ≈ target → DONE (bonus=0, leave=0, hra=0)
 *         if net > target → ERROR (target below min wage feasibility)
 *
 *       Level 1 — + Bonus (8.33% of basic_da)
 *         Q3=C: only add if bonus ≤ gap_to_target; else SKIP bonus
 *         if net ≈ target → DONE (leave=0, hra=0)
 *
 *       Level 2 — + Leave Encashment (5% → 11.23% of basic_da, progressive)
 *         Binary search the exact leave% that hits target.
 *         if net ≈ target → DONE (hra=0)
 *
 *       Level 3 — + HRA (remaining balance, up to 40% of basic_da)
 *         if net ≈ target → DONE
 *         if HRA capped AND target not reached →
 *            Per Q4=A: raise gross_estimate → 50% rule raises basic_da → retry
 *
 *     If after 20 iterations still not matched → ERROR with validation msg
 *
 *   STATUTORY RULES:
 *     PF   = 12% × min(basic_da, ₹15,000)         [hard-coded ceiling, Q6=A]
 *     ESI  = 0.75% × gross                        [only if gross ≤ ₹21,000, Q6=A]
 *     PT   = from professional_tax_rates table    [by state + gross slab]
 *     LWF  = from lwf_rates table                 [by state, employee contribution]
 *
 *   All calculation logic lives HERE (backend). The UI never computes anything.
 *   (Architecture Rule 12 — no hard-coded rules in the UI.)
 */

if (!function_exists('reverseCalculateSalary')) {

/**
 * Reverse calculate gross components from a target Net Salary.
 *
 * @param float  $netSalary       Target net (take-home) — entered manually
 * @param float  $bonusPercent    IGNORED (auto-calculated) — kept for backward compat
 * @param float  $leavePercent    IGNORED (auto-calculated) — kept for backward compat
 * @param bool   $pfApplicable    PF deduction flag (default from unit, override allowed)
 * @param bool   $esiApplicable   ESI deduction flag
 * @param bool   $ptApplicable    PT deduction flag
 * @param bool   $lwfApplicable   LWF deduction flag
 * @param string $state           For PT + LWF + min wage lookup
 * @param string $workerCategory  For minimum wage lookup (REQUIRED)
 * @param string $effectiveDate   For minimum wage effective_from lookup (Y-m-d)
 * @param object $db              Database instance
 * @param bool   $bonusApplicable If false, skip bonus and go to leave (default true)
 * @return array                  Calculation result with components + deductions + warnings
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
    $db,
    bool $bonusApplicable = true
): array {
    if ($netSalary <= 0) {
        return _emptyResult(0);
    }

    // ── Constants (Q6=A: hard-coded statutory values) ──
    $PF_RATE            = 0.12;
    $PF_WAGE_CEILING    = 15000;     // PF calculated on Basic+DA capped at ₹15,000
    $ESI_RATE           = 0.0075;
    $ESI_WAGE_LIMIT     = 21000;     // ESI applies only if gross ≤ ₹21,000
    $BONUS_PCT          = 8.33;      // Statutory minimum bonus
    $LEAVE_MIN_PCT      = 5.0;       // Leave starts at 5% of Basic+DA
    $LEAVE_MAX_PCT      = 11.23;     // Leave capped at 11.23% of Basic+DA
    $HRA_MAX_PCT        = 40.0;      // HRA capped at 40% of Basic+DA
    $BASIC_FLOOR_PCT    = 50.0;      // New Wage Code: Basic+DA ≥ 50% of gross
    $TOLERANCE          = 0.01;      // ₹0.01 rounding tolerance
    $MAX_ITER           = 20;        // Max iterations for convergence

    // ── Step 1: Lookup minimum wage for (state, zone, category, date) ──
    // Zone is fetched by salary-calc.php from the units table and passed in $state
    // as "STATE|ZONE" — split here for min wage lookup only.
    $zone = '';
    $stateForLookup = $state;
    if (strpos($state, '|') !== false) {
        list($stateForLookup, $zone) = explode('|', $state, 2);
    }

    $minWage = _lookupMinWage($db, $stateForLookup, $zone, $workerCategory, $effectiveDate);

    // ── Step 2: Lookup LWF (PT is dynamic — depends on gross, looked up per iteration) ──
    $lwfAmount = $lwfApplicable ? _lookupLWF($db, $stateForLookup) : 0;

    // ── Helper: compute all deductions given (gross, basic_da) ──
    $computeDeductions = function(float $gross, float $basicDa) use (
        $pfApplicable, $esiApplicable, $ptApplicable, $lwfApplicable,
        $stateForLookup, $db, $lwfAmount,
        $PF_RATE, $PF_WAGE_CEILING, $ESI_RATE, $ESI_WAGE_LIMIT
    ): array {
        $pf  = $pfApplicable  ? round(min($basicDa, $PF_WAGE_CEILING) * $PF_RATE, 2) : 0;
        $esi = ($esiApplicable && $gross <= $ESI_WAGE_LIMIT) ? round($gross * $ESI_RATE, 2) : 0;
        $pt  = $ptApplicable  ? _lookupPT($db, $stateForLookup, $gross) : 0;
        $lwf = $lwfAmount;
        $total = round($pf + $esi + $pt + $lwf, 2);
        return ['pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'lwf' => $lwf, 'total' => $total];
    };

    // ── Step 3: Iterative solver ──
    // Initial gross estimate: target_net + ~20% headroom for deductions
    $grossEstimate = $netSalary * 1.25;

    $basicDa       = 0;
    $bonus         = 0;
    $leave         = 0;
    $hra           = 0;
    $actualGross   = 0;
    $pfDed         = 0;
    $esiDed        = 0;
    $ptAmount      = 0;
    $calculatedNet = 0;
    $warnings      = [];
    $levelReached  = 0;
    $matched       = false;
    $errorCode     = null;
    $errorMsg      = null;
    $basicDaPrev   = -1;

    for ($iter = 0; $iter < $MAX_ITER; $iter++) {

        // ── Apply 50% rule: Basic+DA = MAX(min_wage, 50% of gross_estimate) ──
        $basicDa = max($minWage, round(0.5 * $grossEstimate, 2));
        $levelReached = 0;

        // ── Level 0: Basic+DA only ──
        $gross0 = $basicDa;
        $ded0   = $computeDeductions($gross0, $basicDa);
        $net0   = round($gross0 - $ded0['total'], 2);

        if (abs($net0 - $netSalary) < $TOLERANCE) {
            // ✅ Target reached with just Basic+DA — no need for bonus/leave/HRA
            $bonus = 0; $leave = 0; $hra = 0;
            $actualGross = $gross0;
            $pfDed = $ded0['pf']; $esiDed = $ded0['esi']; $ptAmount = $ded0['pt'];
            $calculatedNet = $net0;
            $matched = true;
            $warnings[] = "✓ Target reached with Basic+DA only (Level 0) — no Bonus, Leave, or HRA added.";
            break;
        }

        if ($net0 > $netSalary) {
            // ⚠️ Basic+DA alone overshoots target
            if ($minWage > 0 && abs($basicDa - $minWage) < 0.01) {
                // At min_wage floor — cannot reduce further → ERROR
                $errorCode = 'TARGET_BELOW_MIN_WAGE';
                $errorMsg  = "Target net salary ₹" . number_format($netSalary, 2)
                           . " is below the minimum wage floor. With Basic+DA = ₹"
                           . number_format($minWage, 2) . " (statutory minimum for {$workerCategory} in {$stateForLookup}"
                           . ($zone ? " / {$zone}" : '') . "), the calculated net is ₹"
                           . number_format($net0, 2) . " which is ₹"
                           . number_format($net0 - $netSalary, 2)
                           . " HIGHER than the target. Please increase the target net salary.";
                break;
            }
            // basic_da was driven up by 50% rule — reduce gross estimate and retry
            $grossEstimate = ($netSalary + $ded0['total']) / max(0.001, 1 - ($esiApplicable && $gross0 <= $ESI_WAGE_LIMIT ? $ESI_RATE : 0));
            continue;
        }

        // ── Level 1: + Bonus (8.33% of basic_da) ──
        // Q3=C: only add if bonus ≤ gap_to_target; else skip bonus
        $levelReached = 1;
        $bonusFull = round($basicDa * $BONUS_PCT / 100, 2);
        $gapAfterBasic = round($netSalary - $net0, 2);

        if (!$bonusApplicable) {
            $bonus = 0;
            $warnings[] = "Bonus skipped — not applicable for this template/employee.";
        } elseif ($bonusFull > $gapAfterBasic) {
            // Bonus would overshoot — skip and go to leave (Q3=C)
            $bonus = 0;
            $warnings[] = "Bonus (8.33% = ₹" . number_format($bonusFull, 2) . ") skipped — would overshoot target by ₹"
                        . number_format($bonusFull - $gapAfterBasic, 2) . ". Proceeding to Leave.";
        } else {
            $bonus = $bonusFull;
        }

        $gross1 = round($basicDa + $bonus, 2);
        $ded1   = $computeDeductions($gross1, $basicDa);
        $net1   = round($gross1 - $ded1['total'], 2);

        if (abs($net1 - $netSalary) < $TOLERANCE) {
            $leave = 0; $hra = 0;
            $actualGross = $gross1;
            $pfDed = $ded1['pf']; $esiDed = $ded1['esi']; $ptAmount = $ded1['pt'];
            $calculatedNet = $net1;
            $matched = true;
            $warnings[] = "✓ Target reached at Level 1 (Basic+DA + Bonus).";
            break;
        }

        // ── Level 2: + Leave Encashment (5% → 11.23% of basic_da, progressive) ──
        $levelReached = 2;

        // Check max leave first — is even max leave enough?
        $leaveMax = round($basicDa * $LEAVE_MAX_PCT / 100, 2);
        $grossMax = round($basicDa + $bonus + $leaveMax, 2);
        $dedMax   = $computeDeductions($grossMax, $basicDa);
        $netMax   = round($grossMax - $dedMax['total'], 2);

        $leaveMatched = false;
        $leave        = 0;
        $gross2       = $gross1;
        $ded2         = $ded1;
        $net2         = $net1;

        if (abs($netMax - $netSalary) < $TOLERANCE) {
            // Max leave (11.23%) hits target exactly
            $leave = $leaveMax;
            $gross2 = $grossMax;
            $ded2 = $dedMax;
            $net2 = $netMax;
            $leaveMatched = true;
        } elseif ($netMax > $netSalary) {
            // Some leave% between 5% and 11.23% hits target — binary search
            // First check 5% boundary
            $leave5  = round($basicDa * $LEAVE_MIN_PCT / 100, 2);
            $gross5  = round($basicDa + $bonus + $leave5, 2);
            $ded5    = $computeDeductions($gross5, $basicDa);
            $net5    = round($gross5 - $ded5['total'], 2);

            if (abs($net5 - $netSalary) < $TOLERANCE) {
                $leave = $leave5;
                $gross2 = $gross5;
                $ded2 = $ded5;
                $net2 = $net5;
                $leaveMatched = true;
            } elseif ($net5 >= $netSalary) {
                // Even 5% overshoots — skip leave entirely (spec: leave starts at 5%)
                $leave = 0;
                $warnings[] = "Leave skipped — even minimum 5% (₹" . number_format($leave5, 2) . ") would overshoot target.";
                $gross2 = $gross1;
                $ded2 = $ded1;
                $net2 = $net1;
            } else {
                // Binary search between 5% and 11.23%
                $loPct = $LEAVE_MIN_PCT;
                $hiPct = $LEAVE_MAX_PCT;
                for ($bs = 0; $bs < 30; $bs++) {
                    $midPct = ($loPct + $hiPct) / 2;
                    $leaveTry = round($basicDa * $midPct / 100, 2);
                    $grossTry = round($basicDa + $bonus + $leaveTry, 2);
                    $dedTry   = $computeDeductions($grossTry, $basicDa);
                    $netTry   = round($grossTry - $dedTry['total'], 2);

                    if (abs($netTry - $netSalary) < $TOLERANCE) {
                        $leave = $leaveTry;
                        $gross2 = $grossTry;
                        $ded2 = $dedTry;
                        $net2 = $netTry;
                        $leaveMatched = true;
                        break;
                    }
                    if ($netTry < $netSalary) {
                        $loPct = $midPct;
                    } else {
                        $hiPct = $midPct;
                    }
                }
                if (!$leaveMatched) {
                    // Use max leave as the best approximation
                    $leave = $leaveMax;
                    $gross2 = $grossMax;
                    $ded2 = $dedMax;
                    $net2 = $netMax;
                }
            }
        } else {
            // Max leave (11.23%) still not enough — use max and continue to HRA
            $leave = $leaveMax;
            $gross2 = $grossMax;
            $ded2 = $dedMax;
            $net2 = $netMax;
            $warnings[] = "Leave at maximum 11.23% (₹" . number_format($leaveMax, 2) . ") — still short, adding HRA.";
        }

        if ($leaveMatched && abs($net2 - $netSalary) < $TOLERANCE) {
            $hra = 0;
            $actualGross = $gross2;
            $pfDed = $ded2['pf']; $esiDed = $ded2['esi']; $ptAmount = $ded2['pt'];
            $calculatedNet = $net2;
            $matched = true;
            $warnings[] = "✓ Target reached at Level 2 (Basic+DA + Bonus + Leave @ " . round(($leave / max($basicDa, 1)) * 100, 2) . "%).";
            break;
        }

        // ── Level 3: + HRA (remaining balance, up to 40% of basic_da) ──
        $levelReached = 3;
        $maxHra = round($basicDa * $HRA_MAX_PCT / 100, 2);
        $gap = round($netSalary - $net2, 2);

        if ($gap <= 0) {
            // Already at or above target — no HRA needed
            $hra = 0;
            $actualGross = $gross2;
            $pfDed = $ded2['pf']; $esiDed = $ded2['esi']; $ptAmount = $ded2['pt'];
            $calculatedNet = $net2;
            $matched = true;
            break;
        }

        // Initial HRA guess = min(max_hra, gap)
        $hra = min($maxHra, $gap);

        // Fine-tune HRA — deductions change slightly with gross
        $hraMatched = false;
        for ($adj = 0; $adj < 8; $adj++) {
            $grossTry = round($basicDa + $bonus + $leave + $hra, 2);
            $dedTry   = $computeDeductions($grossTry, $basicDa);
            $netTry   = round($grossTry - $dedTry['total'], 2);

            if (abs($netTry - $netSalary) < $TOLERANCE) {
                $actualGross = $grossTry;
                $pfDed = $dedTry['pf']; $esiDed = $dedTry['esi']; $ptAmount = $dedTry['pt'];
                $calculatedNet = $netTry;
                $hraMatched = true;
                $matched = true;
                $warnings[] = "✓ Target reached at Level 3 (Basic+DA + Bonus + Leave + HRA).";
                break;
            }

            $diff = $netSalary - $netTry;
            $hra = round($hra + $diff, 2);

            if ($hra > $maxHra) {
                $hra = $maxHra;
                $grossTry = round($basicDa + $bonus + $leave + $hra, 2);
                $dedTry   = $computeDeductions($grossTry, $basicDa);
                $netTry   = round($grossTry - $dedTry['total'], 2);
                $actualGross = $grossTry;
                $pfDed = $dedTry['pf']; $esiDed = $dedTry['esi']; $ptAmount = $dedTry['pt'];
                $calculatedNet = $netTry;
                break;
            }
            if ($hra < 0) {
                $hra = 0;
                break;
            }
        }

        if ($hraMatched) break;

        // Check if matched after HRA adjustment
        if (abs($calculatedNet - $netSalary) < $TOLERANCE) {
            $matched = true;
            break;
        }

        // ── HRA capped and still not matched ──
        // Per Q4=A: increase gross_estimate → 50% rule raises basic_da → retry
        if ($hra >= $maxHra) {
            // Avoid infinite loop: if basic_da didn't change between iterations, give up
            if (abs($basicDa - $basicDaPrev) < 0.01 && $iter > 0) {
                $errorCode = 'TARGET_NOT_ACHIEVABLE';
                $totalDeductionsNow = $pfDed + $esiDed + $ptAmount + $lwfAmount;
                $maxGrossPossible = round($basicDa * (1 + $BONUS_PCT/100 + $LEAVE_MAX_PCT/100 + $HRA_MAX_PCT/100), 2);
                $maxNetPossible = round($maxGrossPossible - $totalDeductionsNow, 2);
                $errorMsg = "Target net salary ₹" . number_format($netSalary, 2)
                          . " cannot be achieved with the current salary component limits. "
                          . "Maximum achievable with Basic+DA=₹" . number_format($basicDa, 2)
                          . ", Bonus=8.33%, Leave=11.23%, HRA=40% is ₹" . number_format($maxNetPossible, 2)
                          . " (shortfall of ₹" . number_format($netSalary - $maxNetPossible, 2) . "). "
                          . "Please increase the target net salary or adjust the unit's worker category.";
                break;
            }
            $basicDaPrev = $basicDa;

            // Estimate required basic_da so that max_gross - deductions = target
            // max_gross ≈ basic_da * 1.5956
            $totalDeductionsNow = $pfDed + $esiDed + $ptAmount + $lwfAmount;
            $requiredBasic = ($netSalary + $totalDeductionsNow) / 1.5956;
            if ($requiredBasic <= $minWage) {
                // Required basic is at or below min_wage — target should be achievable
                // at min_wage level. We shouldn't be here — break to avoid infinite loop.
                $errorCode = 'TARGET_NOT_ACHIEVABLE';
                $errorMsg = "Convergence issue: target ₹" . number_format($netSalary, 2)
                          . " could not be reached after {$MAX_ITER} iterations. "
                          . "Last calculated net: ₹" . number_format($calculatedNet, 2) . ".";
                break;
            }
            // Set gross estimate so that 50% of it ≈ required_basic
            $grossEstimate = $requiredBasic * 2;
            $warnings[] = "Iter {$iter}: HRA capped at ₹" . number_format($maxHra, 2)
                        . " — raising Basic+DA from ₹" . number_format($basicDa, 2)
                        . " to ~₹" . number_format($requiredBasic, 2) . " via 50% rule.";
            continue;
        }

        // No change in basic_da and HRA not capped — break to avoid infinite loop
        if (abs($basicDa - $basicDaPrev) < 0.01 && $iter > 0) {
            break;
        }
        $basicDaPrev = $basicDa;
    }

    // ── Final computations ──
    $actualGross = round($basicDa + $bonus + $leave + $hra, 2);
    $totalDeductions = round($pfDed + $esiDed + $ptAmount + $lwfAmount, 2);
    $calculatedNet = round($actualGross - $totalDeductions, 2);
    $matched = abs($calculatedNet - $netSalary) < $TOLERANCE;

    if (!$matched && !$errorCode) {
        $warnings[] = "⚠️ Could not exactly match target net ₹" . number_format($netSalary, 2)
                    . ". Best achievable: ₹" . number_format($calculatedNet, 2)
                    . " (off by ₹" . number_format(abs($netSalary - $calculatedNet), 2) . ").";
    }

    // ── Build result ──
    $result = [
        'success'           => !$errorCode,
        'error'             => $errorCode,
        'error_message'     => $errorMsg,
        'net_salary'        => round($netSalary, 2),
        'basic_da'          => round($basicDa, 2),
        'hra'               => round($hra, 2),
        'leave_encashment'  => round($leave, 2),
        'bonus_encashment'  => round($bonus, 2),
        'washing_allowance' => 0,
        'gross_salary'      => $actualGross,
        'bonus_percent'     => $basicDa > 0 ? round(($bonus / $basicDa) * 100, 2) : 0,
        'leave_percent'     => $basicDa > 0 ? round(($leave / $basicDa) * 100, 2) : 0,
        'deductions'        => [
            'pf'  => round($pfDed, 2),
            'esi' => round($esiDed, 2),
            'pt'  => round($ptAmount, 2),
            'lwf' => round($lwfAmount, 2),
        ],
        'total_deductions'  => $totalDeductions,
        'calculated_net'    => $calculatedNet,
        'fifty_fifty_ok'    => $actualGross > 0 ? ($basicDa >= $actualGross * 0.50 - 0.01) : false,
        'basic_percent'     => $actualGross > 0 ? round(($basicDa / $actualGross) * 100, 2) : 0,
        'min_wage'          => round($minWage, 2),
        'min_wage_warning'  => ($minWage > 0 && $basicDa < $minWage - 0.01) ? "Basic+DA below minimum wage" : null,
        'hra_capped'        => ($hra > 0 && abs($hra - round($basicDa * $HRA_MAX_PCT / 100, 2)) < 0.01),
        'level_reached'     => $levelReached,
        'level_label'       => ['Level 0: Basic+DA only', 'Level 1: + Bonus', 'Level 2: + Leave', 'Level 3: + HRA'][$levelReached] ?? "Level {$levelReached}",
        'warnings'          => $warnings,
        'matched'           => $matched,
        'iterations'        => $iter + 1,
    ];

    return $result;
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
        "SELECT e.unit_id, e.worker_category, u.state, u.zone
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
    // Try with template_id + applied_month first, fall back to without them
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
        // Fallback: columns template_id/applied_month may not exist yet
        error_log("[applyTemplate] template_id insert failed, trying without: " . $e->getMessage());
        try {
            $db->query(
                "INSERT INTO employee_salary_structures
                 (employee_id, effective_from,
                  basic_da, hra, leave_encashment, bonus_encashment, washing_allowance,
                  gross_salary, pf_applicable, esi_applicable, pt_applicable, lwf_applicable,
                  overtime_applicable, bonus_applicable, gratuity_applicable, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $empId, $effectiveFrom,
                    $matched['basic_da'], $matched['hra'], $matched['leave_encashment'],
                    $matched['bonus_encashment'], 0,
                    $matched['gross_salary'], $matched['pf_applicable'], $matched['esi_applicable'],
                    $matched['pt_applicable'], $matched['lwf_applicable'],
                    $matched['overtime_applicable'], $matched['bonus_applicable'],
                    $matched['gratuity_applicable'],
                    $_SESSION['user_id'] ?? null,
                ]
            );
        } catch (\Throwable $e2) {
            error_log("[applyTemplate] Failed for emp {$empId}: " . $e2->getMessage());
            return false;
        }
    }
    return true;
}

// ════════════════════════════════════════════════════════════
// Internal helpers
// ════════════════════════════════════════════════════════════

/**
 * Look up Professional Tax for a state + salary slab.
 */
function _lookupPT($db, string $state, float $salaryEstimate): float {
    if (!$state || !$salaryEstimate) return 0;
    try {
        $row = $db->fetch(
            "SELECT ptr.pt_amount FROM professional_tax_rates ptr
             JOIN states s ON ptr.state_id = s.id
             WHERE (s.state_code = ? OR s.state_name = ?)
             AND ptr.salary_from <= ?
             AND (ptr.salary_to IS NULL OR ptr.salary_to >= ?)
             AND ptr.is_active = 1
             ORDER BY ptr.salary_from DESC LIMIT 1",
            [$state, $state, $salaryEstimate, $salaryEstimate]
        );
        return floatval($row['pt_amount'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Look up LWF employee contribution for a state.
 */
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

/**
 * Look up minimum wage for a worker category in a state + zone.
 *
 * Lookup priority:
 *   1. Exact match: state + zone + category + effective_from <= date
 *   2. State-wide:  state + zone IS NULL + category + effective_from <= date
 *   3. All-India:   state='All' + category + effective_from <= date
 *
 * Returns 0 if not found.
 */
function _lookupMinWage($db, string $state, string $zone, string $category, string $date): float {
    if (!$category) return 0;
    $effDate = $date ?: date('Y-m-d');

    try {
        // Priority 1: state + zone + category
        if ($zone) {
            $row = $db->fetch(
                "SELECT minimum_wage FROM minimum_wages
                 WHERE state = ? AND zone = ?
                 AND designation LIKE CONCAT('%', ?, '%')
                 AND effective_from <= ?
                 ORDER BY effective_from DESC LIMIT 1",
                [$state, $zone, $category, $effDate]
            );
            if ($row && floatval($row['minimum_wage']) > 0) {
                return floatval($row['minimum_wage']);
            }
        }

        // Priority 2: state + zone IS NULL (state-wide rate for category)
        $row = $db->fetch(
            "SELECT minimum_wage FROM minimum_wages
             WHERE state = ? AND (zone IS NULL OR zone = '')
             AND designation LIKE CONCAT('%', ?, '%')
             AND effective_from <= ?
             ORDER BY effective_from DESC LIMIT 1",
            [$state, $category, $effDate]
        );
        if ($row && floatval($row['minimum_wage']) > 0) {
            return floatval($row['minimum_wage']);
        }

        // Priority 3: state='All' + category (all-India fallback)
        $row = $db->fetch(
            "SELECT minimum_wage FROM minimum_wages
             WHERE (state = 'All' OR state = '')
             AND designation LIKE CONCAT('%', ?, '%')
             AND effective_from <= ?
             ORDER BY effective_from DESC LIMIT 1",
            [$category, $effDate]
        );
        if ($row && floatval($row['minimum_wage']) > 0) {
            return floatval($row['minimum_wage']);
        }
    } catch (\Throwable $e) {
        // Fall through to return 0
    }
    return 0;
}

/**
 * Legacy min wage check helper — kept for backward compatibility.
 */
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
        'success'           => false,
        'error'             => 'NET_ZERO',
        'error_message'     => 'Net salary must be greater than 0',
        'net_salary'        => round($netSalary, 2),
        'basic_da'          => 0,
        'hra'               => 0,
        'leave_encashment'  => 0,
        'bonus_encashment'  => 0,
        'washing_allowance' => 0,
        'gross_salary'      => 0,
        'bonus_percent'     => 0,
        'leave_percent'     => 0,
        'deductions'        => ['pf' => 0, 'esi' => 0, 'pt' => 0, 'lwf' => 0],
        'total_deductions'  => 0,
        'calculated_net'    => 0,
        'fifty_fifty_ok'    => false,
        'basic_percent'     => 0,
        'min_wage'          => 0,
        'min_wage_warning'  => null,
        'hra_capped'        => false,
        'level_reached'     => 0,
        'level_label'       => 'N/A',
        'warnings'          => [],
        'matched'           => false,
        'iterations'        => 0,
    ];
}

}
