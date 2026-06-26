<?php
$pageTitle = 'Form 16 - Income Tax TDS Certificate';

$employees = [];
try {
    $employees = $db->fetchAll("SELECT id, employee_code, full_name, designation FROM employees WHERE status = 'active' ORDER BY full_name");
} catch (Exception $e) {
    $employees = [];
}

$employeeId = (int)($_GET['employee_id'] ?? 0);
$fy = sanitize($_GET['fy'] ?? '');
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');

// Default FY
if (!$fy) {
    $fy = ($currentMonth >= 4) ? ($currentYear . '-' . ($currentYear + 1)) : (($currentYear - 1) . '-' . $currentYear);
}

$fyParts = explode('-', $fy);
$fyStart = (int)$fyParts[0];
$fyEnd = (int)$fyParts[1];
$assessmentYear = $fyEnd + 1;

$employee = null;
$company = null;
$payrollRecords = [];

if ($employeeId > 0) {
    try {
        $employee = $db->fetch("SELECT * FROM employees WHERE id = ?", [$employeeId]);
    } catch (Exception $e) {
        $employee = null;
    }

    try {
        $company = $db->fetch("SELECT * FROM companies LIMIT 1");
    } catch (Exception $e) {
        $company = null;
    }

    if ($employee) {
        try {
            $payrollRecords = $db->fetchAll("
                SELECT p.*, pp.month, pp.year
                FROM payroll p
                JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                WHERE p.employee_id = ?
                    AND ((pp.year = ? AND pp.month >= 4) OR (pp.year = ? AND pp.month <= 3))
                ORDER BY pp.year, pp.month
            ", [$employee['employee_code'], $fyStart, $fyEnd]);
        } catch (Exception $e) {
            $payrollRecords = [];
        }
    }
}

// Calculate annual totals
$totalGross = 0;
$totalPF = 0;
$totalESI = 0;
$totalPT = 0;
$totalTDSDeducted = 0;
$totalNetPay = 0;
$totalBasicDA = 0;
$totalHRA = 0;
$totalOT = 0;
$totalCTC = 0;
$totalAdvance = 0;

foreach ($payrollRecords as $pr) {
    $totalGross += $pr['gross_earnings'];
    $totalPF += $pr['pf_employee'];
    $totalESI += $pr['esi_employee'];
    $totalPT += $pr['professional_tax'];
    $totalNetPay += $pr['net_pay'];
    $totalBasicDA += $pr['basic_da'];
    $totalHRA += $pr['hra'];
    $totalOT += $pr['overtime_amount'];
    $totalCTC += ($pr['ctc'] ?? $pr['gross_earnings']);
    $totalAdvance += ($pr['salary_advance'] ?? 0);
}

$totalDeductions = $totalPF + $totalESI + $totalPT + $totalAdvance;
$taxableIncome = $totalGross - $totalDeductions;

// Simplified tax calculation (Old regime, FY 2024-25 onwards)
$taxCalculated = calculateTax($taxableIncome);

function calculateTax($income) {
    if ($income <= 250000) return 0;
    if ($income <= 500000) return ($income - 250000) * 0.05;
    if ($income <= 1000000) return 12500 + ($income - 500000) * 0.20;
    return 12500 + 100000 + ($income - 1000000) * 0.30;
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 10px; }
    .form16-box { border: 2px solid #000 !important; }
    .page-break { page-break-before: always; }
}
.form16-box {
    border: 2px solid #dee2e6;
    padding: 20px;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/mis/form-16">
        <div class="col-md-3">
            <label class="form-label">Employee</label>
            <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- Choose --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $employeeId == $emp['id'] ? 'selected' : '' ?>>
                        <?= sanitize($emp['employee_code']) ?> - <?= sanitize($emp['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">Financial Year</label>
            <select name="fy" class="form-select form-select-sm">
                <?php for ($y = $currentYear - 3; $y <= $currentYear + 1; $y++): ?>
                    <option value="<?= $y ?>-<?= $y + 1 ?>" <?= $fy === ($y . '-' . ($y + 1)) ? 'selected' : '' ?>>
                        <?= $y ?>-<?= $y + 1 ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> Generate</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <?php if (!$employeeId): ?>
        <div class="alert alert-info">Please select an employee to generate Form 16.</div>
    <?php elseif (!$employee): ?>
        <div class="alert alert-danger">Employee not found.</div>
    <?php else: ?>

    <div class="form16-box">
        <!-- GOI Header -->
        <div class="text-center mb-3">
            <p class="small mb-0"><strong>GOVERNMENT OF INDIA</strong></p>
            <p class="small mb-0"><strong>INCOME TAX DEPARTMENT</strong></p>
        </div>

        <h5 class="text-center mb-1 text-decoration-underline">FORM 16</h5>
        <p class="text-center small mb-3">
            [See section 203 of the Income-tax Act, 1961]<br>
            <strong>Certificate under section 203 of the Income-tax Act, 1961 for Tax Deducted at Source</strong><br>
            <strong>from income chargeable under the head "Salaries"</strong>
        </p>

        <!-- PART A -->
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <strong>PART A</strong>
            </div>
            <div class="card-body p-2">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%" class="fw-bold">1. Name and address of the Employer</td>
                                <td><?= $company ? sanitize($company['company_name']) : 'N/A' ?><br>
                                    <small><?= $company ? sanitize($company['address'] ?? '') : '' ?></small></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">2. TAN of the Employer</td>
                                <td>________________</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">3. PAN of the Employer</td>
                                <td><?= $company ? sanitize($company['pan_number'] ?? 'N/A') : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">4. TDS Circle/Ward</td>
                                <td>________________</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td width="40%" class="fw-bold">5. Name and designation of the Employee</td>
                                <td><?= sanitize($employee['full_name']) ?><br>
                                    <small><?= sanitize($employee['designation']) ?></small></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">6. PAN of the Employee</td>
                                <td><?= sanitize($employee['pan_number'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">7. Aadhaar No.</td>
                                <td><?= sanitize($employee['aadhaar_number'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">8. Period</td>
                                <td>01/04/<?= $fyStart ?> to 31/03/<?= $fyEnd ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <hr>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Quarter</th>
                                <th class="text-end">Receipt Nos. of Form 24Q</th>
                                <th class="text-end">Total Salary Paid (₹)</th>
                                <th class="text-end">TDS Deducted (₹)</th>
                                <th class="text-end">TDS Deposited (₹)</th>
                                <th>Date of Deposit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Apr - Jun <?= $fyStart ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td>-</td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Jul - Sep <?= $fyStart ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td>-</td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td>Oct - Dec <?= $fyStart ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td>-</td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td>Jan - Mar <?= $fyEnd ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                                <td>-</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="3" class="text-end fw-bold">Total</td>
                                <td class="text-end fw-bold"><?= formatCurrency($totalGross) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($taxCalculated) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($taxCalculated) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="small mt-2 mb-0">
                    <strong>Summary:</strong> Total TDS: <strong>₹<?= number_format($taxCalculated, 2) ?></strong> |
                    Assessment Year: <strong><?= $fyEnd ?>-<?= $assessmentYear ?></strong>
                </p>
            </div>
        </div>

        <!-- PART B -->
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <strong>PART B — Details of Salary Paid and Tax Deducted</strong>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <tbody>
                            <tr>
                                <td colspan="2" class="fw-bold bg-light">1. Gross Salary</td>
                            </tr>
                            <tr>
                                <td width="70%">a) Basic + DA</td>
                                <td class="text-end"><?= formatCurrency($totalBasicDA) ?></td>
                            </tr>
                            <tr>
                                <td>b) HRA</td>
                                <td class="text-end"><?= formatCurrency($totalHRA) ?></td>
                            </tr>
                            <tr>
                                <td>c) Overtime / Other Allowances</td>
                                <td class="text-end"><?= formatCurrency($totalOT) ?></td>
                            </tr>
                            <tr class="table-primary">
                                <td class="fw-bold">d) Gross Salary (a + b + c)</td>
                                <td class="text-end fw-bold"><?= formatCurrency($totalGross) ?></td>
                            </tr>

                            <tr>
                                <td colspan="2" class="fw-bold bg-light">2. Deductions (Chapter VI-A / Others)</td>
                            </tr>
                            <tr>
                                <td>a) Employee's PF Contribution</td>
                                <td class="text-end"><?= formatCurrency($totalPF) ?></td>
                            </tr>
                            <tr>
                                <td>b) ESI Contribution</td>
                                <td class="text-end"><?= formatCurrency($totalESI) ?></td>
                            </tr>
                            <tr>
                                <td>c) Professional Tax</td>
                                <td class="text-end"><?= formatCurrency($totalPT) ?></td>
                            </tr>
                            <tr>
                                <td>d) Salary Advance Recovery</td>
                                <td class="text-end"><?= formatCurrency($totalAdvance) ?></td>
                            </tr>
                            <tr class="table-danger">
                                <td class="fw-bold">Total Deductions</td>
                                <td class="text-end fw-bold"><?= formatCurrency($totalDeductions) ?></td>
                            </tr>

                            <tr class="table-info">
                                <td class="fw-bold">3. Taxable Income (1d - 2 Total)</td>
                                <td class="text-end fw-bold"><?= formatCurrency($taxableIncome) ?></td>
                            </tr>

                            <tr>
                                <td>4. Tax on Total Income</td>
                                <td class="text-end"><?= formatCurrency($taxCalculated) ?></td>
                            </tr>
                            <tr>
                                <td>5. Add: Health & Education Cess (4%)</td>
                                <td class="text-end"><?= formatCurrency(round($taxCalculated * 0.04, 2)) ?></td>
                            </tr>
                            <tr class="table-warning">
                                <td class="fw-bold">6. Total Tax Payable (4 + 5)</td>
                                <td class="text-end fw-bold"><?= formatCurrency(round($taxCalculated * 1.04, 2)) ?></td>
                            </tr>

                            <tr>
                                <td>7. Less: Relief under section 89</td>
                                <td class="text-end"><?= formatCurrency(0) ?></td>
                            </tr>
                            <tr class="table-dark">
                                <td class="fw-bold text-white">8. Tax Deducted at Source (TDS)</td>
                                <td class="text-end fw-bold text-white"><?= formatCurrency(round($taxCalculated * 1.04, 2)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Breakdown -->
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <strong>Annexure — Monthly Salary Breakdown (FY <?= $fy ?>)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Month</th>
                                <th class="text-end">Basic+DA</th>
                                <th class="text-end">HRA</th>
                                <th class="text-end">OT</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PF</th>
                                <th class="text-end">ESI</th>
                                <th class="text-end">PT</th>
                                <th class="text-end">Net Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payrollRecords as $pr): 
                                $monthNames = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                            ?>
                            <tr>
                                <td><?= $monthNames[$pr['month']] ?? '' ?> <?= $pr['year'] ?></td>
                                <td class="text-end"><?= formatCurrency($pr['basic_da']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['hra']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['overtime_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['gross_earnings']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['pf_employee']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['esi_employee']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['professional_tax']) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($pr['net_pay']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th>Annual Total</th>
                                <th class="text-end"><?= formatCurrency($totalBasicDA) ?></th>
                                <th class="text-end"><?= formatCurrency($totalHRA) ?></th>
                                <th class="text-end"><?= formatCurrency($totalOT) ?></th>
                                <th class="text-end"><?= formatCurrency($totalGross) ?></th>
                                <th class="text-end"><?= formatCurrency($totalPF) ?></th>
                                <th class="text-end"><?= formatCurrency($totalESI) ?></th>
                                <th class="text-end"><?= formatCurrency($totalPT) ?></th>
                                <th class="text-end"><?= formatCurrency($totalNetPay) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Signature -->
        <div class="row mt-4">
            <div class="col-md-6">
                <p class="small mb-0"><strong>Place:</strong> _______________</p>
                <p class="small mb-0"><strong>Date:</strong> _______________</p>
            </div>
            <div class="col-md-6 text-end">
                <div class="border-bottom" style="width: 250px; margin-left: auto;"></div>
                <p class="small mt-1 mb-0"><strong>Signature of the Employer</strong></p>
                <p class="small text-muted mb-0">Name: <?= $company ? sanitize($company['company_name']) : 'N/A' ?></p>
                <p class="small text-muted mb-0">Designation: Authorized Signatory</p>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
