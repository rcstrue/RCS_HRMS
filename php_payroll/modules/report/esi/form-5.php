<?php
$pageTitle = 'ESI Form 5 - Return of Contributions with CA Certificate';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$year = (int)($_GET['year'] ?? date('Y'));

$company = null;
try {
    $company = $db->fetch("SELECT * FROM companies LIMIT 1");
} catch (Exception $e) {
    $company = null;
}

// Build monthly contribution data
$monthlyData = [];
$totalEE = 0;
$totalER = 0;
$grandEE = 0;
$grandER = 0;
$grandEmployees = 0;
$grandWages = 0;

try {
    $periods = $db->fetchAll("SELECT * FROM payroll_periods WHERE year = ? ORDER BY month", [$year]);
} catch (Exception $e) {
    $periods = [];
}

foreach ($periods as $period) {
    try {
        $stats = $db->fetch("
            SELECT COUNT(*) as emp_count,
                   COALESCE(SUM(p.gross_earnings), 0) as total_wages,
                   COALESCE(SUM(p.esi_employee), 0) as total_ee,
                   COALESCE(SUM(p.esi_employer), 0) as total_er
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            JOIN employee_salary_structures ess ON ess.employee_id = e.id
                AND ess.effective_from <= ? AND (ess.effective_to IS NULL OR ess.effective_to >= ?)
            WHERE p.payroll_period_id = ? AND e.status = 'active' AND ess.esi_applicable = 1
        ", [$period['start_date'], $period['end_date'], $period['id']]);

        $monthlyData[] = [
            'month' => $period['month'],
            'month_name' => $monthNames[$period['month']] ?? '',
            'emp_count' => $stats['emp_count'],
            'total_wages' => $stats['total_wages'],
            'total_ee' => $stats['total_ee'],
            'total_er' => $stats['total_er'],
            'total_contrib' => $stats['total_ee'] + $stats['total_er']
        ];

        $grandEE += $stats['total_ee'];
        $grandER += $stats['total_er'];
        $grandEmployees += $stats['emp_count'];
        $grandWages += $stats['total_wages'];
    } catch (Exception $e) {
        $monthlyData[] = [
            'month' => $period['month'],
            'month_name' => $monthNames[$period['month']] ?? '',
            'emp_count' => 0, 'total_wages' => 0,
            'total_ee' => 0, 'total_er' => 0, 'total_contrib' => 0
        ];
    }
}

// Fill in months with no payroll period
$existingMonths = array_column($monthlyData, 'month');
for ($m = 1; $m <= 12; $m++) {
    if (!in_array($m, $existingMonths)) {
        $monthlyData[] = [
            'month' => $m,
            'month_name' => $monthNames[$m],
            'emp_count' => 0, 'total_wages' => 0,
            'total_ee' => 0, 'total_er' => 0, 'total_contrib' => 0
        ];
    }
}
usort($monthlyData, fn($a, $b) => $a['month'] <=> $b['month']);

$grandTotal = $grandEE + $grandER;
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
    .page-break { page-break-before: always; }
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter Form -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/esi/form-5">
        <div class="col-auto">
            <label class="form-label">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2020" max="2030">
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark ms-1"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <!-- Section A: Employer Details -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white">
            <strong>Section A — Employer Details</strong>
        </div>
        <div class="card-body p-3">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="40%"><strong>Employer Name:</strong></td><td><?= $company ? sanitize($company['company_name']) : 'N/A' ?></td></tr>
                        <tr><td><strong>ESI Code No:</strong></td><td><?= $company ? sanitize($company['esi_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                        <tr><td><strong>PAN No:</strong></td><td><?= $company ? sanitize($company['pan_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="40%"><strong>Address:</strong></td><td><?= $company ? sanitize($company['address'] ?? '') : '' ?></td></tr>
                        <tr><td><strong>Contribution Period:</strong></td><td>April <?= $year ?> - March <?= $year + 1 ?></td></tr>
                        <tr><td><strong>Wage Ceiling:</strong></td><td>₹21,000 / Month</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Section B: Monthly Contribution Table -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white">
            <strong>Section B — Monthly Contribution Statement (<?= $year ?>-<?= $year + 1 ?>)</strong>
        </div>
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-center">No. of IPs</th>
                            <th class="text-end">Total Wages (₹)</th>
                            <th class="text-end">EE Share 0.75% (₹)</th>
                            <th class="text-end">ER Share 3.25% (₹)</th>
                            <th class="text-end">Total Contribution (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyData as $md): ?>
                        <tr>
                            <td><?= sanitize($md['month_name']) ?></td>
                            <td class="text-center"><?= $md['emp_count'] ?: '-' ?></td>
                            <td class="text-end"><?= $md['total_wages'] > 0 ? formatCurrency($md['total_wages']) : '-' ?></td>
                            <td class="text-end"><?= $md['total_ee'] > 0 ? formatCurrency($md['total_ee']) : '-' ?></td>
                            <td class="text-end"><?= $md['total_er'] > 0 ? formatCurrency($md['total_er']) : '-' ?></td>
                            <td class="text-end fw-bold"><?= $md['total_contrib'] > 0 ? formatCurrency($md['total_contrib']) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th>Grand Total</th>
                            <th class="text-center"><?= $grandEmployees ?></th>
                            <th class="text-end"><?= formatCurrency($grandWages) ?></th>
                            <th class="text-end"><?= formatCurrency($grandEE) ?></th>
                            <th class="text-end"><?= formatCurrency($grandER) ?></th>
                            <th class="text-end"><?= formatCurrency($grandTotal) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Section C: CA Declaration -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white">
            <strong>Section C — Chartered Accountant Certificate</strong>
        </div>
        <div class="card-body p-4">
            <div class="mb-3">
                <p class="fw-bold mb-2">Certificate of Compliance</p>
                <p class="fst-italic">I/We hereby certify that the above statement of contributions under the ESI Act, 1948 for the contribution period from April <?= $year ?> to March <?= $year + 1 ?> is correct and complete in all respects and the contributions have been duly paid as required under the Act.</p>
            </div>

            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label"><strong>Name of Chartered Accountant:</strong></label>
                        <div class="p-2 border border-dashed rounded bg-light">_________________________________</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><strong>Membership No. (ICAI):</strong></label>
                        <div class="p-2 border border-dashed rounded bg-light">_________________________________</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label"><strong>Place:</strong></label>
                        <div class="p-2 border border-dashed rounded bg-light">_________________________________</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><strong>Date:</strong></label>
                        <div class="p-2 border border-dashed rounded bg-light">_________________________________</div>
                    </div>
                </div>
            </div>

            <div class="row mt-5">
                <div class="col-md-6"></div>
                <div class="col-md-6 text-center">
                    <div class="p-3 border border-dashed rounded bg-light mb-2" style="min-height: 80px;">
                        <small class="text-muted">Signature & Seal of Chartered Accountant</small>
                    </div>
                    <p class="mb-0"><strong>Chartered Accountant</strong></p>
                    <p class="small text-muted"> Firm Name: _________________</p>
                    <p class="small text-muted"> FRN: _________________</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row">
        <div class="col-md-3">
            <div class="card p-3 text-center">
                <h6 class="text-muted">Grand Total IPs</h6>
                <h4 class="text-primary mb-0"><?= $grandEmployees ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center">
                <h6 class="text-muted">Total Wages</h6>
                <h4 class="text-success mb-0"><?= formatCurrency($grandWages) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center">
                <h6 class="text-muted">Total EE Contribution</h6>
                <h4 class="text-warning mb-0"><?= formatCurrency($grandEE) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center">
                <h6 class="text-muted">Total ER Contribution</h6>
                <h4 class="text-danger mb-0"><?= formatCurrency($grandER) ?></h4>
            </div>
        </div>
    </div>
</div>
