<?php
/**
 * PF Dues & Remitted Report
 * Monthly PF dues vs actual remitted comparison with annual summary
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Dues & Remitted Report';

// CSV Export
if (isset($_GET['export'])) {
    $year = sanitize($_GET['year'] ?? date('Y'));
    $clientId = sanitize($_GET['client_id'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Dues_Remitted_' . $year . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Dues & Remitted Report — Year ' . $year]);
    fputcsv($output, ['Generated: ' . date('d/m/Y H:i')]);
    fputcsv($output, []);

    $headers = ['Month', 'No. of Employees', 'Total Wages', 'Total Dues (EPF+EPS+EDLI+Admin)',
                'Amount Remitted', 'Challan No.', 'Challan Date', 'Difference', 'Status'];
    fputcsv($output, $headers);

    try {
        for ($m = 1; $m <= 12; $m++) {
            $period = $db->fetch(
                "SELECT pp.id FROM payroll_periods pp
                 WHERE pp.month = :month AND pp.year = :year AND pp.status = 'Processed'
                 LIMIT 1",
                ['month' => $m, 'year' => $year]
            );

            if (!$period) {
                $monthName = date('F', mktime(0, 0, 0, $m, 1));
                fputcsv($output, [$monthName, 0, 0, 0, 0, '', '', 0, 'Not Processed']);
                continue;
            }

            $summary = $db->fetch(
                "SELECT COUNT(DISTINCT p.employee_id) as total_employees,
                        SUM(p.basic_da) as total_wages,
                        SUM(p.pf_employee) as total_epf_ee,
                        SUM(p.pf_employer) as total_epf_er,
                        SUM(p.eps_employer) as total_eps,
                        SUM(p.edlis_employer) as total_edli,
                        SUM(p.epf_admin_charges) as total_admin
                 FROM payroll p
                 WHERE p.payroll_period_id = :periodId",
                ['periodId' => $period['id']]
            );

            $totalDues = ($summary['total_epf_ee'] ?? 0) + ($summary['total_epf_er'] ?? 0) +
                         ($summary['total_eps'] ?? 0) + ($summary['total_edli'] ?? 0) +
                         ($summary['total_admin'] ?? 0);

            // Try to find challan info from pf_challans or pf_remissions table
            $challanNo = '';
            $challanDate = '';
            $amountRemitted = 0;
            $status = 'Pending';

            try {
                $challan = $db->fetch(
                    "SELECT challan_no, challan_date, amount_paid, status
                     FROM pf_remissions
                     WHERE payroll_period_id = :periodId
                     LIMIT 1",
                    ['periodId' => $period['id']]
                );
                if ($challan) {
                    $challanNo = $challan['challan_no'] ?? '';
                    $challanDate = $challan['challan_date'] ? formatDate($challan['challan_date']) : '';
                    $amountRemitted = $challan['amount_paid'] ?? 0;
                    $status = $challan['status'] ?? 'Pending';
                }
            } catch (Exception $e) {
                // Table may not exist
            }

            $difference = round($totalDues - $amountRemitted, 2);

            $monthName = date('F', mktime(0, 0, 0, $m, 1));
            fputcsv($output, [
                $monthName,
                $summary['total_employees'] ?? 0,
                formatCurrency($summary['total_wages'] ?? 0),
                formatCurrency($totalDues),
                formatCurrency($amountRemitted),
                $challanNo,
                $challanDate,
                formatCurrency($difference),
                $status
            ]);
        }
    } catch (Exception $e) {
        fputcsv($output, ['Error', $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Get filter values
$year = sanitize($_GET['year'] ?? date('Y'));
$clientId = sanitize($_GET['client_id'] ?? '');

// Fetch clients for filter
try {
    $clients = $db->fetchAll("SELECT id, name FROM clients WHERE status = 1 ORDER BY name");
} catch (Exception $e) {
    $clients = [];
}

// Fetch monthly data
$monthlyData = [];
$annualTotals = [
    'total_employees_count' => 0,
    'total_wages' => 0,
    'total_dues' => 0,
    'total_remitted' => 0,
    'total_difference' => 0,
    'months_paid' => 0,
    'months_pending' => 0,
];

try {
    for ($m = 1; $m <= 12; $m++) {
        $entry = [
            'month' => $m,
            'month_name' => date('F', mktime(0, 0, 0, $m, 1)),
            'total_employees' => 0,
            'total_wages' => 0,
            'total_dues' => 0,
            'amount_remittted' => 0,
            'challan_no' => '',
            'challan_date' => '',
            'difference' => 0,
            'status' => 'Not Processed',
            'period_id' => null,
        ];

        $period = $db->fetch(
            "SELECT pp.id FROM payroll_periods pp
             WHERE pp.month = :month AND pp.year = :year AND pp.status = 'Processed'
             LIMIT 1",
            ['month' => $m, 'year' => $year]
        );

        if ($period) {
            $entry['period_id'] = $period['id'];

            $summary = $db->fetch(
                "SELECT COUNT(DISTINCT p.employee_id) as total_employees,
                        SUM(p.basic_da) as total_wages,
                        SUM(p.pf_employee) as total_epf_ee,
                        SUM(p.pf_employer) as total_epf_er,
                        SUM(p.eps_employer) as total_eps,
                        SUM(p.edlis_employer) as total_edli,
                        SUM(p.epf_admin_charges) as total_admin
                 FROM payroll p
                 WHERE p.payroll_period_id = :periodId",
                ['periodId' => $period['id']]
            );

            $entry['total_employees'] = $summary['total_employees'] ?? 0;
            $entry['total_wages'] = $summary['total_wages'] ?? 0;
            $entry['total_dues'] = ($summary['total_epf_ee'] ?? 0) + ($summary['total_epf_er'] ?? 0) +
                                   ($summary['total_eps'] ?? 0) + ($summary['total_edli'] ?? 0) +
                                   ($summary['total_admin'] ?? 0);
            $entry['status'] = 'Dues Calculated';

            // Check for remittance record
            try {
                $challan = $db->fetch(
                    "SELECT challan_no, challan_date, amount_paid, status
                     FROM pf_remissions
                     WHERE payroll_period_id = :periodId
                     LIMIT 1",
                    ['periodId' => $period['id']]
                );
                if ($challan) {
                    $entry['challan_no'] = $challan['challan_no'] ?? '';
                    $entry['challan_date'] = $challan['challan_date'] ?? '';
                    $entry['amount_remittted'] = $challan['amount_paid'] ?? 0;
                    $entry['status'] = $challan['status'] ?? 'Pending';
                }
            } catch (Exception $e) {
                // Table may not exist — that's fine
            }

            $entry['difference'] = round($entry['total_dues'] - $entry['amount_remittted'], 2);
        }

        // Accumulate annual totals
        $annualTotals['total_employees_count'] += $entry['total_employees'];
        $annualTotals['total_wages'] += $entry['total_wages'];
        $annualTotals['total_dues'] += $entry['total_dues'];
        $annualTotals['total_remitted'] += $entry['amount_remittted'];
        $annualTotals['total_difference'] += $entry['difference'];

        if (in_array($entry['status'], ['Paid', 'Remitted'])) {
            $annualTotals['months_paid']++;
        } elseif ($entry['status'] !== 'Not Processed') {
            $annualTotals['months_pending']++;
        }

        $monthlyData[] = $entry;
    }
} catch (Exception $e) {
    $errorMsg = 'Error fetching monthly data: ' . $e->getMessage();
}

// Compliance percentage
$compliancePct = $annualTotals['total_dues'] > 0
    ? round(($annualTotals['total_remitted'] / $annualTotals['total_dues']) * 100, 1)
    : 0;
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { font-size: 10px; }
    body { font-size: 12px; }
}
.dues-header {
    background: linear-gradient(135deg, #e65100 0%, #ef6c00 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.status-paid { color: #2e7d32; }
.status-pending { color: #e65100; }
.status-not-processed { color: #9e9e9e; }
.dues-card {
    border-left: 4px solid #e65100;
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="dues-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-cash-coin me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">Monthly PF dues vs. remitted comparison — Compliance tracking</p>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
            </div>
        </div>
    </div>

    <!-- Print Header -->
    <div class="d-none d-print-block mb-3">
        <h5 class="text-center fw-bold mb-0">PF DUES & REMITTED REPORT</h5>
        <p class="text-center mb-0">Financial Year: <?= $year ?></p>
        <p class="text-center mb-0 small">Generated on: <?= date('d/m/Y') ?></p>
        <hr>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <input type="hidden" name="page" value="report/pf/dues-remitted">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>View</button>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <a href="?page=report/pf/dues-remitted&year=<?= $year ?>&client_id=<?= $clientId ?>&export=1" class="btn btn-success btn-sm flex-fill"><i class="bi bi-download me-1"></i>Export CSV</a>
                            <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Annual Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3 dues-card">
                <div class="text-muted small mb-1">Total Dues</div>
                <div class="fs-5 fw-bold text-danger"><?= formatCurrency($annualTotals['total_dues']) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3" style="border-left:4px solid #2e7d32">
                <div class="text-muted small mb-1">Total Remitted</div>
                <div class="fs-5 fw-bold text-success"><?= formatCurrency($annualTotals['total_remitted']) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3" style="border-left:4px solid #c62828">
                <div class="text-muted small mb-1">Outstanding</div>
                <div class="fs-5 fw-bold text-danger"><?= formatCurrency($annualTotals['total_difference']) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3" style="border-left:4px solid #1565c0">
                <div class="text-muted small mb-1">Compliance %</div>
                <div class="fs-5 fw-bold <?= $compliancePct >= 90 ? 'text-success' : ($compliancePct >= 70 ? 'text-warning' : 'text-danger') ?>">
                    <?= $compliancePct ?>%
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3" style="border-left:4px solid #2e7d32">
                <div class="text-muted small mb-1">Months Paid</div>
                <div class="fs-5 fw-bold text-success"><?= $annualTotals['months_paid'] ?>/12</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3" style="border-left:4px solid #e65100">
                <div class="text-muted small mb-1">Total Wages</div>
                <div class="fs-5 fw-bold"><?= formatCurrency($annualTotals['total_wages']) ?></div>
            </div>
        </div>
    </div>

    <!-- Monthly Breakdown Table -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>Monthly PF Dues vs. Remitted — <?= $year ?></h6>
            <span class="badge bg-light text-dark">Compliance: <?= $compliancePct ?>%</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:110px">Month</th>
                            <th class="text-center" style="width:50px">Emp.</th>
                            <th class="text-end" style="width:110px">Total Wages</th>
                            <th class="text-end" style="width:120px">Total Dues</th>
                            <th class="text-end" style="width:120px">Amount Remitted</th>
                            <th style="width:120px">Challan No.</th>
                            <th class="text-center" style="width:95px">Challan Date</th>
                            <th class="text-end" style="width:110px">Difference</th>
                            <th class="text-center" style="width:110px">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyData as $row): ?>
                            <?php
                            $statusClass = 'status-not-processed';
                            $rowClass = '';
                            if ($row['status'] === 'Paid' || $row['status'] === 'Remitted') {
                                $statusClass = 'status-paid';
                            } elseif ($row['status'] !== 'Not Processed') {
                                $statusClass = 'status-pending';
                                $rowClass = 'table-warning';
                            }
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td class="fw-bold"><?= $row['month_name'] ?></td>
                                <td class="text-center"><?= $row['total_employees'] > 0 ? $row['total_employees'] : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end"><?= $row['total_wages'] > 0 ? formatCurrency($row['total_wages']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end fw-bold"><?= $row['total_dues'] > 0 ? formatCurrency($row['total_dues']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end"><?= $row['amount_remittted'] > 0 ? formatCurrency($row['amount_remittted']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= htmlspecialchars($row['challan_no'] ?: '—') ?></td>
                                <td class="text-center"><?= $row['challan_date'] ? formatDate($row['challan_date']) : '—' ?></td>
                                <td class="text-end">
                                    <?php if ($row['total_dues'] > 0): ?>
                                        <span class="<?= abs($row['difference']) > 0.01 ? 'text-danger fw-bold' : 'text-success' ?>">
                                            <?= formatCurrency($row['difference']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $badgeClass = 'bg-secondary';
                                    $statusLabel = $row['status'];
                                    if ($row['status'] === 'Paid' || $row['status'] === 'Remitted') {
                                        $badgeClass = 'bg-success';
                                    } elseif ($row['status'] === 'Dues Calculated') {
                                        $badgeClass = 'bg-warning text-dark';
                                    } elseif ($row['status'] === 'Pending') {
                                        $badgeClass = 'bg-danger';
                                    }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th>ANNUAL TOTAL</th>
                            <th class="text-center"><?= $annualTotals['total_employees_count'] ?></th>
                            <th class="text-end"><?= formatCurrency($annualTotals['total_wages']) ?></th>
                            <th class="text-end"><?= formatCurrency($annualTotals['total_dues']) ?></th>
                            <th class="text-end"><?= formatCurrency($annualTotals['total_remitted']) ?></th>
                            <th colspan="2"></th>
                            <th class="text-end <?= abs($annualTotals['total_difference']) > 0.01 ? 'text-danger' : 'text-success' ?>">
                                <?= formatCurrency($annualTotals['total_difference']) ?>
                            </th>
                            <th class="text-center"><?= $annualTotals['months_paid'] ?> Paid</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Annual Summary Section -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Annual Summary — <?= $year ?></h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:220px">Total Employees (avg per month)</td>
                            <td class="text-end fw-bold"><?= $annualTotals['total_employees_count'] > 0 ? round($annualTotals['total_employees_count'] / 12) : 0 ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total Wages for PF</td>
                            <td class="text-end fw-bold"><?= formatCurrency($annualTotals['total_wages']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total PF Dues (All Components)</td>
                            <td class="text-end fw-bold"><?= formatCurrency($annualTotals['total_dues']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total Amount Remitted</td>
                            <td class="text-end fw-bold text-success"><?= formatCurrency($annualTotals['total_remitted']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:220px">Outstanding Balance</td>
                            <td class="text-end fw-bold <?= $annualTotals['total_difference'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= formatCurrency(abs($annualTotals['total_difference'])) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Months Fully Remitted</td>
                            <td class="text-end fw-bold text-success"><?= $annualTotals['months_paid'] ?> of 12</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Months Pending</td>
                            <td class="text-end fw-bold text-warning"><?= $annualTotals['months_pending'] ?></td>
                        </tr>
                        <tr class="border-top">
                            <td class="fw-bold">Compliance Rate</td>
                            <td class="text-end fw-bold">
                                <span class="badge <?= $compliancePct >= 90 ? 'bg-success' : ($compliancePct >= 70 ? 'bg-warning text-dark' : 'bg-danger') ?> fs-6">
                                    <?= $compliancePct ?>%
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Compliance Progress -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Compliance Progress</h6>
        </div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 28px;">
                <div class="progress-bar bg-success" role="progressbar"
                     style="width: <?= $annualTotals['months_paid'] / 12 * 100 ?>%"
                     aria-valuenow="<?= $annualTotals['months_paid'] ?>"
                     aria-valuemin="0" aria-valuemax="12">
                    <?= $annualTotals['months_paid'] ?> Months Paid
                </div>
                <?php if ($annualTotals['months_pending'] > 0): ?>
                <div class="progress-bar bg-warning text-dark" role="progressbar"
                     style="width: <?= $annualTotals['months_pending'] / 12 * 100 ?>%"
                     aria-valuenow="<?= $annualTotals['months_pending'] ?>"
                     aria-valuemin="0" aria-valuemax="12">
                    <?= $annualTotals['months_pending'] ?> Pending
                </div>
                <?php endif; ?>
            </div>
            <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                <?php
                $unprocessed = 12 - $annualTotals['months_paid'] - $annualTotals['months_pending'];
                if ($unprocessed > 0) {
                    echo $unprocessed . ' month(s) not yet processed for ' . $year . '.';
                } elseif ($compliancePct >= 90) {
                    echo 'Excellent compliance for ' . $year . '. Keep up the good work!';
                } elseif ($compliancePct >= 70) {
                    echo 'Moderate compliance. Consider prioritizing pending remittances.';
                } else {
                    echo 'Low compliance. Immediate attention required for pending PF dues.';
                }
                ?>
            </p>
        </div>
    </div>

    <!-- Print Footer -->
    <div class="d-none d-print-block mt-4">
        <hr>
        <div class="row">
            <div class="col-6">
                <p class="mb-0 fw-bold">Prepared By</p>
                <div style="height:40px;border-bottom:1px solid #333;"></div>
                <p class="mb-0 small">Name: _______________ Date: _______________</p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-0 fw-bold">Authorized Signatory</p>
                <div style="height:40px;border-bottom:1px solid #333;"></div>
                <p class="mb-0 small">Name: _______________ Date: _______________</p>
                <p class="mb-0 small">Office Seal</p>
            </div>
        </div>
    </div>
</div>
