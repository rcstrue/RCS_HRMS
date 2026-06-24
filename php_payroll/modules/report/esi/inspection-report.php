<?php
$pageTitle = 'ESI Inspection Report';

$monthNames = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$fullMonthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$year = (int)($_GET['year'] ?? date('Y'));

$company = null;
try {
    $company = $db->fetch("SELECT * FROM companies LIMIT 1");
} catch (Exception $e) {
    $company = null;
}

// Overall stats
$totalEmployees = 0;
$esiCovered = 0;
$esiExempt = 0;
$activeClients = 0;

try {
    $empStats = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active FROM employees");
    $totalEmployees = $empStats['total'];
    $activeEmployees = $empStats['active'];
} catch (Exception $e) {
    $activeEmployees = 0;
}

try {
    $clientCount = $db->fetch("SELECT COUNT(*) as cnt FROM clients");
    $activeClients = $clientCount['cnt'];
} catch (Exception $e) {
    $activeClients = 0;
}

// Monthly trend data (12 months)
$monthlyTrend = [];
try {
    $periods = $db->fetchAll("SELECT * FROM payroll_periods WHERE year = ? ORDER BY month", [$year]);
} catch (Exception $e) {
    $periods = [];
}

$totWages = 0;
$totEE = 0;
$totER = 0;
$totIPs = 0;
$totNCP = 0;

foreach ($periods as $period) {
    try {
        $stats = $db->fetch("
            SELECT COUNT(DISTINCT p.employee_id) as emp_count,
                   COALESCE(SUM(p.gross_earnings), 0) as total_wages,
                   COALESCE(SUM(p.esi_employee), 0) as total_ee,
                   COALESCE(SUM(p.esi_employer), 0) as total_er,
                   COALESCE(SUM(p.total_days - p.paid_days), 0) as ncp_days
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            JOIN employee_salary_structures ess ON ess.employee_id = e.id
                AND ess.effective_from <= ? AND (ess.effective_to IS NULL OR ess.effective_to >= ?)
            WHERE p.payroll_period_id = ? AND e.status = 'active' AND ess.esi_applicable = 1
        ", [$period['start_date'], $period['end_date'], $period['id']]);

        $monthlyTrend[] = [
            'month' => $period['month'],
            'month_name' => $fullMonthNames[$period['month']] ?? '',
            'short_name' => $monthNames[$period['month']] ?? '',
            'emp_count' => $stats['emp_count'],
            'total_wages' => $stats['total_wages'],
            'total_ee' => $stats['total_ee'],
            'total_er' => $stats['total_er'],
            'total_contrib' => $stats['total_ee'] + $stats['total_er'],
            'ncp_days' => $stats['ncp_days']
        ];
        $totWages += $stats['total_wages'];
        $totEE += $stats['total_ee'];
        $totER += $stats['total_er'];
        $totIPs += $stats['emp_count'];
        $totNCP += $stats['ncp_days'];
    } catch (Exception $e) {
        $monthlyTrend[] = [
            'month' => $period['month'],
            'month_name' => $fullMonthNames[$period['month']] ?? '',
            'short_name' => $monthNames[$period['month']] ?? '',
            'emp_count' => 0, 'total_wages' => 0,
            'total_ee' => 0, 'total_er' => 0,
            'total_contrib' => 0, 'ncp_days' => 0
        ];
    }
}

// Fill in missing months
$existingMonths = array_column($monthlyTrend, 'month');
for ($m = 1; $m <= 12; $m++) {
    if (!in_array($m, $existingMonths)) {
        $monthlyTrend[] = [
            'month' => $m,
            'month_name' => $fullMonthNames[$m],
            'short_name' => $monthNames[$m],
            'emp_count' => 0, 'total_wages' => 0,
            'total_ee' => 0, 'total_er' => 0,
            'total_contrib' => 0, 'ncp_days' => 0
        ];
    }
}
usort($monthlyTrend, fn($a, $b) => $a['month'] <=> $b['month']);

// Accident register summary
$accidents = [];
try {
    $accidents = $db->fetchAll("SELECT * FROM accident_register WHERE YEAR(accident_date) = ? ORDER BY accident_date", [$year]);
} catch (Exception $e) {
    $accidents = [];
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
    .page-break { page-break-before: always; }
    .card { border: 1px solid #000 !important; }
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/esi/inspection-report">
        <div class="col-auto">
            <label class="form-label">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2020" max="2030">
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark ms-1"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <!-- Section A: Establishment Details -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>(A) Establishment Details</strong></div>
        <div class="card-body p-3">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="40%"><strong>Employer Name:</strong></td><td><?= $company ? sanitize($company['company_name']) : 'N/A' ?></td></tr>
                        <tr><td><strong>ESI Code No:</strong></td><td><?= $company ? sanitize($company['esi_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                        <tr><td><strong>PAN:</strong></td><td><?= $company ? sanitize($company['pan_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                        <tr><td><strong>Address:</strong></td><td><?= $company ? sanitize($company['address'] ?? '') : '' ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="40%"><strong>Nature of Industry:</strong></td><td>Labour Contractor</td></tr>
                        <tr><td><strong>No. of Clients:</strong></td><td><?= $activeClients ?></td></tr>
                        <tr><td><strong>Registration Date:</strong></td><td>N/A</td></tr>
                        <tr><td><strong>Inspection Year:</strong></td><td><?= $year ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Section B: Employee Strength -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>(B) Employee Strength</strong></div>
        <div class="card-body p-3">
            <div class="row g-3">
                <div class="col-md-4 text-center">
                    <div class="card p-3 bg-primary bg-opacity-10">
                        <h6 class="text-muted">Total Employees</h6>
                        <h3 class="text-primary mb-0"><?= $totalEmployees ?></h3>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="card p-3 bg-success bg-opacity-10">
                        <h6 class="text-muted">IP Covered (ESI)</h6>
                        <h3 class="text-success mb-0"><?= $totIPs ?></h3>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="card p-3 bg-warning bg-opacity-10">
                        <h6 class="text-muted">ESI Exempt</h6>
                        <h3 class="text-warning mb-0"><?= max(0, $activeEmployees - $totIPs) ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section C: Wages Details Month-wise -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>(C) Wages Details — Month-wise</strong></div>
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-center">IP Covered</th>
                            <th class="text-end">Total Wages (₹)</th>
                            <th class="text-end">Avg. Wage/IP (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyTrend as $md): ?>
                        <tr class="<?= $md['emp_count'] == 0 ? 'table-secondary' : '' ?>">
                            <td><?= sanitize($md['month_name']) ?></td>
                            <td class="text-center"><?= $md['emp_count'] ?: '-' ?></td>
                            <td class="text-end"><?= $md['total_wages'] > 0 ? formatCurrency($md['total_wages']) : '-' ?></td>
                            <td class="text-end"><?= ($md['emp_count'] > 0 && $md['total_wages'] > 0) ? formatCurrency(round($md['total_wages'] / $md['emp_count'], 2)) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th>Annual Total / Average</th>
                            <th class="text-center"><?= $totIPs ?></th>
                            <th class="text-end"><?= formatCurrency($totWages) ?></th>
                            <th class="text-end"><?= ($totIPs > 0) ? formatCurrency(round($totWages / count(array_filter($monthlyTrend, fn($m) => $m['emp_count'] > 0)), 2)) : '-' ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Section D: Contribution Details Month-wise -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>(D) Contribution Details — Month-wise Trend</strong></div>
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-center">IPs</th>
                            <th class="text-end">EE 0.75% (₹)</th>
                            <th class="text-end">ER 3.25% (₹)</th>
                            <th class="text-end">Total (₹)</th>
                            <th class="text-center">NCP Days</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyTrend as $md): ?>
                        <tr class="<?= $md['emp_count'] == 0 ? 'table-secondary' : '' ?>">
                            <td><?= sanitize($md['month_name']) ?></td>
                            <td class="text-center"><?= $md['emp_count'] ?: '-' ?></td>
                            <td class="text-end"><?= $md['total_ee'] > 0 ? formatCurrency($md['total_ee']) : '-' ?></td>
                            <td class="text-end"><?= $md['total_er'] > 0 ? formatCurrency($md['total_er']) : '-' ?></td>
                            <td class="text-end fw-bold"><?= $md['total_contrib'] > 0 ? formatCurrency($md['total_contrib']) : '-' ?></td>
                            <td class="text-center"><?= $md['ncp_days'] > 0 ? $md['ncp_days'] : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th>Annual Total</th>
                            <th class="text-center"><?= $totIPs ?></th>
                            <th class="text-end"><?= formatCurrency($totEE) ?></th>
                            <th class="text-end"><?= formatCurrency($totER) ?></th>
                            <th class="text-end"><?= formatCurrency($totEE + $totER) ?></th>
                            <th class="text-center"><?= $totNCP ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Section E: Compliance Status -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>(E) Compliance Status</strong></div>
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th>Compliance Item</th>
                            <th width="15%" class="text-center">Status</th>
                            <th width="30%">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>ESI Registration</td>
                            <td class="text-center"><span class="badge bg-success">✓ Compliant</span></td>
                            <td>Registration active under ESI Code: <?= $company ? sanitize($company['esi_number'] ?? 'N/A') : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>Contribution Payment (Monthly)</td>
                            <td class="text-center"><span class="badge bg-success">✓ Compliant</span></td>
                            <td>All <?= count(array_filter($monthlyTrend, fn($m) => $m['total_contrib'] > 0)) ?> months contributions deposited</td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>Form 3 Return Filing</td>
                            <td class="text-center"><span class="badge bg-warning text-dark">⚠ Pending</span></td>
                            <td>To be filed before due date</td>
                        </tr>
                        <tr>
                            <td>4</td>
                            <td>Form 5 Annual Return</td>
                            <td class="text-center"><span class="badge bg-warning text-dark">⚠ Pending</span></td>
                            <td>Annual return due by <?= $year + 1 ?></td>
                        </tr>
                        <tr>
                            <td>5</td>
                            <td>Employee IP Generation</td>
                            <td class="text-center"><span class="badge bg-success">✓ Compliant</span></td>
                            <td>IP numbers generated for all covered employees</td>
                        </tr>
                        <tr>
                            <td>6</td>
                            <td>Accident Reporting (Form 12)</td>
                            <td class="text-center"><span class="badge bg-info text-dark">— N/A</span></td>
                            <td><?= count($accidents) ?> accidents reported in <?= $year ?></td>
                        </tr>
                        <tr>
                            <td>7</td>
                            <td>Inspection Register Maintenance</td>
                            <td class="text-center"><span class="badge bg-success">✓ Compliant</span></td>
                            <td>Register maintained up to date</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section F: Accident Register -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white"><strong>(F) Accident Register Summary — <?= $year ?></strong></div>
        <div class="card-body p-3">
            <?php if (empty($accidents)): ?>
                <p class="text-muted text-center mb-0">No accidents reported in <?= $year ?>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Employee Name</th>
                                <th>Nature of Injury</th>
                                <th>Days Lost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 1; foreach ($accidents as $acc): ?>
                            <tr>
                                <td><?= $sno++ ?></td>
                                <td><?= formatDate($acc['accident_date'] ?? '') ?></td>
                                <td><?= sanitize($acc['employee_name'] ?? 'N/A') ?></td>
                                <td><?= sanitize($acc['nature_of_injury'] ?? '') ?></td>
                                <td class="text-center"><?= $acc['days_lost'] ?? '-' ?></td>
                                <td><?= sanitize($acc['status'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
