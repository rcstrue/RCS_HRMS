<?php
/**
 * PF Form 12A - Monthly Contribution Challan
 * Monthly PF contribution summary in official Form 12A format
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 12A - Monthly Contribution Challan';

// CSV Export
if (isset($_GET['export'])) {
    $periodId = sanitize($_GET['payroll_period_id'] ?? '');
    $month = sanitize($_GET['month'] ?? date('m'));
    $year = sanitize($_GET['year'] ?? date('Y'));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Form_12A_' . $month . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Form 12A - Monthly Contribution Challan']);
    fputcsv($output, ['Month: ' . $month . '/' . $year]);
    fputcsv($output, []);

    try {
        $rows = $db->fetchAll("SELECT COUNT(DISTINCT p.employee_id) as total_employees,
                                       SUM(p.basic_da) as total_wages,
                                       SUM(p.pf_employee) as total_epf_ee,
                                       SUM(p.pf_employer) as total_epf_er,
                                       SUM(p.eps_employer) as total_eps,
                                       SUM(p.edlis_employer) as total_edli,
                                       SUM(p.epf_admin_charges) as total_admin
                                FROM payroll p
                                WHERE p.payroll_period_id = :periodId",
            ['periodId' => $periodId]);
        $row = $rows[0] ?? [];

        $totalEpf = ($row['total_epf_ee'] ?? 0) + ($row['total_epf_er'] ?? 0);
        $totalAmount = $totalEpf + ($row['total_eps'] ?? 0) + ($row['total_edli'] ?? 0) + ($row['total_admin'] ?? 0);

        fputcsv($output, ['Particulars', 'Amount (INR)']);
        fputcsv($output, ['No. of Employees', $row['total_employees'] ?? 0]);
        fputcsv($output, ['Total Wages (Basic+DA)', formatCurrency($row['total_wages'] ?? 0)]);
        fputcsv($output, ['EPF - Employee Share (12%)', formatCurrency($row['total_epf_ee'] ?? 0)]);
        fputcsv($output, ['EPF - Employer Share (3.67%)', formatCurrency($row['total_epf_er'] ?? 0)]);
        fputcsv($output, ['Total EPF (EE+ER)', formatCurrency($totalEpf)]);
        fputcsv($output, ['EPS (8.33%)', formatCurrency($row['total_eps'] ?? 0)]);
        fputcsv($output, ['EDLI (0.5%)', formatCurrency($row['total_edli'] ?? 0)]);
        fputcsv($output, ['EPF Admin Charges (0.5%)', formatCurrency($row['total_admin'] ?? 0)]);
        fputcsv($output, ['Grand Total', formatCurrency($totalAmount)]);
    } catch (Exception $e) {
        fputcsv($output, ['Error', $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Get filter values
$month = sanitize($_GET['month'] ?? date('m'));
$year = sanitize($_GET['year'] ?? date('Y'));
$periodId = sanitize($_GET['payroll_period_id'] ?? '');

// Fetch payroll periods
$payrollPeriods = [];
try {
    $payrollPeriods = $db->fetchAll("SELECT pp.*, COUNT(p.id) as employee_count
                                     FROM payroll_periods pp
                                     LEFT JOIN payroll p ON p.payroll_period_id = pp.id
                                     WHERE pp.status = 'Processed'
                                     GROUP BY pp.id
                                     ORDER BY pp.year DESC, pp.month DESC
                                     LIMIT 24");
} catch (Exception $e) {
    $errorMsg = 'Error fetching payroll periods: ' . $e->getMessage();
}

// If period selected, fetch data
$summary = null;
$employeeContributions = [];
if (!empty($periodId)) {
    try {
        $period = $db->fetch("SELECT * FROM payroll_periods WHERE id = :id", ['id' => $periodId]);
        if ($period) {
            $month = $period['month'];
            $year = $period['year'];
        }

        // Summary totals
        $summary = $db->fetch("SELECT COUNT(DISTINCT p.employee_id) as total_employees,
                                      SUM(p.basic_da) as total_wages,
                                      SUM(p.pf_employee) as total_epf_ee,
                                      SUM(p.pf_employer) as total_epf_er,
                                      SUM(p.eps_employer) as total_eps,
                                      SUM(p.edlis_employer) as total_edli,
                                      SUM(p.epf_admin_charges) as total_admin,
                                      SUM(p.gross_earnings) as total_gross,
                                      SUM(p.paid_days) as total_paid_days
                               FROM payroll p
                               WHERE p.payroll_period_id = :periodId",
            ['periodId' => $periodId]);

        // Employee-wise contributions
        $employeeContributions = $db->fetchAll(
            "SELECT p.employee_id, p.basic_da, p.pf_employee, p.pf_employer,
                    p.eps_employer, p.edlis_employer, p.epf_admin_charges, p.paid_days, p.total_days,
                    e.employee_code, e.full_name, e.father_name, e.uan_number, e.esic_number
             FROM payroll p
             JOIN employees e ON e.employee_code = p.employee_id
             WHERE p.payroll_period_id = :periodId
             ORDER BY e.employee_code ASC",
            ['periodId' => $periodId]
        );
    } catch (Exception $e) {
        $errorMsg = 'Error fetching contribution data: ' . $e->getMessage();
    }
}

// Fetch PF rates
try {
    $pfRates = $db->fetch("SELECT * FROM pf_rates ORDER BY id DESC LIMIT 1");
} catch (Exception $e) {
    $pfRates = ['employee_share' => 12, 'employer_share_pf' => 3.67, 'employer_share_eps' => 8.33, 'edlis_employer' => 0.5, 'epf_admin_charges' => 0.5, 'wage_ceiling' => 15000];
}

$totalEpf = 0;
$totalEps = 0;
$totalEdli = 0;
$totalAdmin = 0;
$grandTotal = 0;
if ($summary) {
    $totalEpf = ($summary['total_epf_ee'] ?? 0) + ($summary['total_epf_er'] ?? 0);
    $totalEps = $summary['total_eps'] ?? 0;
    $totalEdli = $summary['total_edli'] ?? 0;
    $totalAdmin = $summary['total_admin'] ?? 0;
    $grandTotal = $totalEpf + $totalEps + $totalEdli + $totalAdmin;
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { font-size: 10px; }
    body { font-size: 12px; }
}
.challan-box {
    border: 3px double #1a237e;
    border-radius: 10px;
    padding: 24px;
    margin-bottom: 20px;
    background: #fdfdff;
}
.challan-title {
    text-align: center;
    margin-bottom: 15px;
}
.challan-section {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    margin-bottom: 15px;
    overflow: hidden;
}
.challan-section-header {
    background: #e8eaf6;
    padding: 8px 15px;
    font-weight: 700;
    color: #1a237e;
    font-size: 13px;
}
.challan-row {
    display: flex;
    border-bottom: 1px solid #f0f0f0;
    padding: 6px 15px;
}
.challan-row:last-child {
    border-bottom: none;
}
.challan-label {
    width: 55%;
    color: #495057;
}
.challan-value {
    width: 45%;
    font-weight: 600;
    text-align: right;
}
.challan-total-row {
    display: flex;
    padding: 10px 15px;
    background: #1a237e;
    color: white;
    font-weight: 700;
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h5>
        <span class="badge bg-secondary"><?= date('d/m/Y') ?></span>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Select Payroll Period</h6>
        </div>
        <div class="card-body">
            <form method="GET">
                <input type="hidden" name="page" value="report/pf/form-12a">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">Payroll Period</label>
                        <select name="payroll_period_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">-- Select Period --</option>
                            <?php foreach ($payrollPeriods as $pp): ?>
                                <option value="<?= $pp['id'] ?>" <?= $periodId == $pp['id'] ? 'selected' : '' ?>>
                                    <?= str_pad($pp['month'], 2, '0', STR_PAD_LEFT) ?>/<?= $pp['year'] ?>
                                    (<?= $pp['employee_count'] ?> employees)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>View</button>
                            <?php if ($summary): ?>
                                <a href="?page=report/pf/form-12a&payroll_period_id=<?= $periodId ?>&month=<?= $month ?>&year=<?= $year ?>&export=1" class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>CSV</a>
                                <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger no-print">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($summary && $periodId): ?>
    <!-- Print Header -->
    <div class="d-none d-print-block mb-3">
        <h4 class="text-center fw-bold mb-0">FORM 12A</h4>
        <p class="text-center mb-0"><strong>[See Regulation 15(2)]</strong></p>
        <p class="text-center mb-0 small">Contribution Card for the month of <?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?></p>
        <hr>
    </div>

    <!-- Challan Box -->
    <div class="challan-box">
        <div class="challan-title">
            <h5 class="fw-bold mb-1">PROVIDENT FUND CHALLAN</h5>
            <p class="text-muted mb-0">Contribution for the Wage Month: <strong><?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?></strong></p>
        </div>

        <!-- Section A: Establishment Details -->
        <div class="challan-section">
            <div class="challan-section-header">A — Establishment Details</div>
            <div class="challan-row">
                <span class="challan-label">Establishment Code Number</span>
                <span class="challan-value">—</span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Name of Establishment</span>
                <span class="challan-value">RCS HRMS Pro</span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Wage Month</span>
                <span class="challan-value"><?= str_pad($month, 2, '0', STR_PAD_LEFT) ?>/<?= $year ?></span>
            </div>
        </div>

        <!-- Section B: Contribution Details -->
        <div class="challan-section">
            <div class="challan-section-header">B — Contribution Details</div>
            <div class="challan-row">
                <span class="challan-label">Number of Employees Contributing</span>
                <span class="challan-value"><?= number_format($summary['total_employees'] ?? 0) ?></span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Total Wages (Basic + DA) for PF</span>
                <span class="challan-value"><?= formatCurrency($summary['total_wages'] ?? 0) ?></span>
            </div>
            <div class="challan-row" style="background:#f8f9fa">
                <span class="challan-label">A. EPF — Employee Share (<?= $pfRates['employee_share'] ?>%)</span>
                <span class="challan-value"><?= formatCurrency($summary['total_epf_ee'] ?? 0) ?></span>
            </div>
            <div class="challan-row" style="background:#f8f9fa">
                <span class="challan-label">B. EPF — Employer Share (<?= $pfRates['employer_share_pf'] ?>%)</span>
                <span class="challan-value"><?= formatCurrency($summary['total_epf_er'] ?? 0) ?></span>
            </div>
            <div class="challan-row fw-bold" style="background:#e8eaf6">
                <span class="challan-label">Total EPF (A + B)</span>
                <span class="challan-value"><?= formatCurrency($totalEpf) ?></span>
            </div>
            <div class="challan-row" style="background:#f8f9fa">
                <span class="challan-label">C. EPS — Pension Fund (<?= $pfRates['employer_share_eps'] ?>%)</span>
                <span class="challan-value"><?= formatCurrency($totalEps) ?></span>
            </div>
            <div class="challan-row" style="background:#f8f9fa">
                <span class="challan-label">D. EDLI — Employee Deposit Linked Insurance (<?= $pfRates['edlis_employer'] ?>%)</span>
                <span class="challan-value"><?= formatCurrency($totalEdli) ?></span>
            </div>
            <div class="challan-row" style="background:#f8f9fa">
                <span class="challan-label">E. EPF Administrative Charges (<?= $pfRates['epf_admin_charges'] ?>%)</span>
                <span class="challan-value"><?= formatCurrency($totalAdmin) ?></span>
            </div>
            <div class="challan-total-row">
                <span>GRAND TOTAL (A + B + C + D + E)</span>
                <span class="text-end"><?= formatCurrency($grandTotal) ?></span>
            </div>
        </div>

        <!-- Section C: Challan Details -->
        <div class="challan-section">
            <div class="challan-section-header">C — Challan Details</div>
            <div class="challan-row">
                <span class="challan-label">Challan Number</span>
                <span class="challan-value">—</span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Challan Date</span>
                <span class="challan-value">—</span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Amount Paid (₹)</span>
                <span class="challan-value"><?= formatCurrency($grandTotal) ?></span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Payment Through</span>
                <span class="challan-value">Online / NEFT / RTGS</span>
            </div>
            <div class="challan-row">
                <span class="challan-label">Bank Name & Branch</span>
                <span class="challan-value">—</span>
            </div>
        </div>

        <!-- Signature -->
        <div class="row mt-4">
            <div class="col-6">
                <p class="mb-0 fw-bold">Checked By:</p>
                <div style="height:40px;border-bottom:1px solid #333;"></div>
                <p class="mb-0 small">Name: _______________ Date: _______________</p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-0 fw-bold">Authorized Signatory:</p>
                <div style="height:40px;border-bottom:1px solid #333;"></div>
                <p class="mb-0 small">Name: _______________ Date: _______________</p>
            </div>
        </div>
    </div>

    <!-- Employee-wise Contribution Table -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Employee-wise Contribution Details</h6>
            <span class="badge bg-primary"><?= count($employeeContributions) ?> Employees</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">Sr</th>
                            <th>PF Acct No.</th>
                            <th>UAN</th>
                            <th>Name</th>
                            <th class="text-end">Paid Days</th>
                            <th class="text-end">Wages (B+DA)</th>
                            <th class="text-end">EPF (EE)</th>
                            <th class="text-end">EPF (ER)</th>
                            <th class="text-end">EPS</th>
                            <th class="text-end">EDLI</th>
                            <th class="text-end">Admin</th>
                            <th class="text-end fw-bold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employeeContributions)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-3">No contribution data found.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $sr = 1;
                            $sumEpfEe = 0; $sumEpfEr = 0; $sumEps = 0; $sumEdli = 0; $sumAdmin = 0;
                            foreach ($employeeContributions as $ec):
                                $rowTotal = ($ec['pf_employee'] ?? 0) + ($ec['pf_employer'] ?? 0) +
                                             ($ec['eps_employer'] ?? 0) + ($ec['edlis_employer'] ?? 0) +
                                             ($ec['epf_admin_charges'] ?? 0);
                                $sumEpfEe += $ec['pf_employee'] ?? 0;
                                $sumEpfEr += $ec['pf_employer'] ?? 0;
                                $sumEps += $ec['eps_employer'] ?? 0;
                                $sumEdli += $ec['edlis_employer'] ?? 0;
                                $sumAdmin += $ec['epf_admin_charges'] ?? 0;
                            ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($ec['esic_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($ec['uan_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($ec['full_name']) ?></td>
                                    <td class="text-end"><?= $ec['paid_days'] ?? 0 ?>/<?= $ec['total_days'] ?? 30 ?></td>
                                    <td class="text-end"><?= formatCurrency($ec['basic_da'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($ec['pf_employee'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($ec['pf_employer'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($ec['eps_employer'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($ec['edlis_employer'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($ec['epf_admin_charges'] ?? 0) ?></td>
                                    <td class="text-end fw-bold"><?= formatCurrency($rowTotal) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($employeeContributions)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="5" class="text-end">TOTAL</th>
                                <th class="text-end"><?= formatCurrency($summary['total_wages'] ?? 0) ?></th>
                                <th class="text-end"><?= formatCurrency($sumEpfEe) ?></th>
                                <th class="text-end"><?= formatCurrency($sumEpfEr) ?></th>
                                <th class="text-end"><?= formatCurrency($sumEps) ?></th>
                                <th class="text-end"><?= formatCurrency($sumEdli) ?></th>
                                <th class="text-end"><?= formatCurrency($sumAdmin) ?></th>
                                <th class="text-end"><?= formatCurrency($grandTotal) ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- No Period Selected -->
    <div class="text-center py-5">
        <i class="bi bi-file-earmark-text text-muted" style="font-size:48px;"></i>
        <p class="text-muted mt-3">Select a payroll period to view the Form 12A challan.</p>
    </div>
    <?php endif; ?>
</div>
