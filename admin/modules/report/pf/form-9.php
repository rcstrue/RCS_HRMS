<?php
/**
 * PF Form 9 - Return of Contributions
 * Monthly return showing each employee's contribution details
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 9 - Return of Contributions';

// CSV Export
if (isset($_GET['export'])) {
    $periodId = sanitize($_GET['payroll_period_id'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Form_9_' . date('dmY') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    try {
        $period = $db->fetch("SELECT * FROM payroll_periods WHERE id = :id", ['id' => $periodId]);
        fputcsv($output, ['PF Form 9 - Return of Contributions']);
        fputcsv($output, ['Month: ' . str_pad($period['month'] ?? '', 2, '0', STR_PAD_LEFT) . '/' . ($period['year'] ?? '')]);
        fputcsv($output, []);

        $headers = ['Sr No', 'PF Acct No', 'UAN', 'Name', 'Wages for PF', 'EPF (EE)', 'EPF (ER)', 'EPS', 'EDLI', 'Admin Charges', 'Total', 'NCP Days', 'Refund'];
        fputcsv($output, $headers);

        $contributions = $db->fetchAll(
            "SELECT p.*, e.full_name, e.uan_number, e.esic_number, e.employee_code
             FROM payroll p
             JOIN employees e ON e.employee_code = p.employee_id
             WHERE p.payroll_period_id = :periodId
             ORDER BY e.employee_code",
            ['periodId' => $periodId]);

        $sr = 1;
        $totalWages = 0; $totalEpfEe = 0; $totalEpfEr = 0; $totalEps = 0;
        $totalEdli = 0; $totalAdmin = 0; $grandTotal = 0;

        foreach ($contributions as $c) {
            $rowTotal = ($c['pf_employee'] ?? 0) + ($c['pf_employer'] ?? 0) +
                         ($c['eps_employer'] ?? 0) + ($c['edlis_employer'] ?? 0) + ($c['epf_admin_charges'] ?? 0);
            $totalWages += $c['basic_da'] ?? 0;
            $totalEpfEe += $c['pf_employee'] ?? 0;
            $totalEpfEr += $c['pf_employer'] ?? 0;
            $totalEps += $c['eps_employer'] ?? 0;
            $totalEdli += $c['edlis_employer'] ?? 0;
            $totalAdmin += $c['epf_admin_charges'] ?? 0;
            $grandTotal += $rowTotal;

            $ncpDays = ($c['total_days'] ?? 0) - ($c['paid_days'] ?? 0);

            fputcsv($output, [
                $sr++,
                $c['esic_number'] ?? '',
                $c['uan_number'] ?? '',
                $c['full_name'],
                formatCurrency($c['basic_da'] ?? 0),
                formatCurrency($c['pf_employee'] ?? 0),
                formatCurrency($c['pf_employer'] ?? 0),
                formatCurrency($c['eps_employer'] ?? 0),
                formatCurrency($c['edlis_employer'] ?? 0),
                formatCurrency($c['epf_admin_charges'] ?? 0),
                formatCurrency($rowTotal),
                max(0, $ncpDays),
                0
            ]);
        }

        fputcsv($output, []);
        fputcsv($output, ['TOTAL', '', '', count($contributions) . ' Employees', formatCurrency($totalWages), formatCurrency($totalEpfEe), formatCurrency($totalEpfEr), formatCurrency($totalEps), formatCurrency($totalEdli), formatCurrency($totalAdmin), formatCurrency($grandTotal)]);
    } catch (Exception $e) {
        fputcsv($output, ['Error', $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Get filter values
$periodId = sanitize($_GET['payroll_period_id'] ?? '');
$clientId = sanitize($_GET['client_id'] ?? '');

// Fetch payroll periods
$payrollPeriods = [];
try {
    $payrollPeriods = $db->fetchAll("SELECT pp.*, COUNT(p.id) as emp_count
                                     FROM payroll_periods pp
                                     LEFT JOIN payroll p ON p.payroll_period_id = pp.id
                                     WHERE pp.status = 'Processed'
                                     GROUP BY pp.id
                                     ORDER BY pp.year DESC, pp.month DESC
                                     LIMIT 24");
} catch (Exception $e) {
    $errorMsg = 'Error fetching periods: ' . $e->getMessage();
}

// Fetch clients
try {
    $clients = $db->fetchAll("SELECT id, name FROM clients WHERE status = 1 ORDER BY name");
} catch (Exception $e) {
    $clients = [];
}

// Fetch contributions
$contributions = [];
$summaryTotals = [];
if (!empty($periodId)) {
    try {
        $contributions = $db->fetchAll(
            "SELECT p.employee_id, p.basic_da, p.pf_employee, p.pf_employer,
                    p.eps_employer, p.edlis_employer, p.epf_admin_charges,
                    p.paid_days, p.total_days, p.gross_earnings,
                    e.employee_code, e.full_name, e.father_name, e.uan_number,
                    e.esic_number, e.department, e.client_id,
                    c.name as client_name
             FROM payroll p
             JOIN employees e ON e.employee_code = p.employee_id
             LEFT JOIN clients c ON c.id = e.client_id
             WHERE p.payroll_period_id = :periodId",
            ['periodId' => $periodId]);

        // Apply client filter
        if (!empty($clientId)) {
            $contributions = array_filter($contributions, function ($c) use ($clientId) {
                return $c['client_id'] == $clientId;
            });
            $contributions = array_values($contributions);
        }

        // Calculate summary
        $summaryTotals = [
            'total_employees' => count($contributions),
            'total_wages' => 0,
            'total_epf_ee' => 0,
            'total_epf_er' => 0,
            'total_eps' => 0,
            'total_edli' => 0,
            'total_admin' => 0,
            'total_ncp' => 0,
        ];

        foreach ($contributions as $c) {
            $summaryTotals['total_wages'] += $c['basic_da'] ?? 0;
            $summaryTotals['total_epf_ee'] += $c['pf_employee'] ?? 0;
            $summaryTotals['total_epf_er'] += $c['pf_employer'] ?? 0;
            $summaryTotals['total_eps'] += $c['eps_employer'] ?? 0;
            $summaryTotals['total_edli'] += $c['edlis_employer'] ?? 0;
            $summaryTotals['total_admin'] += $c['epf_admin_charges'] ?? 0;
            $summaryTotals['total_ncp'] += max(0, ($c['total_days'] ?? 0) - ($c['paid_days'] ?? 0));
        }

        $summaryTotals['grand_total'] = $summaryTotals['total_epf_ee'] + $summaryTotals['total_epf_er'] +
                                         $summaryTotals['total_eps'] + $summaryTotals['total_edli'] + $summaryTotals['total_admin'];
    } catch (Exception $e) {
        $errorMsg = 'Error fetching contributions: ' . $e->getMessage();
    }
}

// Get period info
$period = null;
if (!empty($periodId)) {
    try {
        $period = $db->fetch("SELECT * FROM payroll_periods WHERE id = :id", ['id' => $periodId]);
    } catch (Exception $e) {}
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { font-size: 10px; }
    body { font-size: 11px; }
}
.form-9-header {
    background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="form-9-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">Monthly return of contributions to the Provident Fund</p>
            </div>
            <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <!-- Print Header -->
    <div class="d-none d-print-block mb-3">
        <h4 class="text-center fw-bold mb-0">FORM 9</h4>
        <p class="text-center mb-0"><strong>[See Regulation 22(1)]</strong></p>
        <p class="text-center mb-0 small">Return of Contributions — Month: <?= str_pad($period['month'] ?? '', 2, '0', STR_PAD_LEFT) ?>/<?= $period['year'] ?? '' ?></p>
        <hr>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET">
                <input type="hidden" name="page" value="report/pf/form-9">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Payroll Period</label>
                        <select name="payroll_period_id" class="form-select form-select-sm">
                            <option value="">-- Select Period --</option>
                            <?php foreach ($payrollPeriods as $pp): ?>
                                <option value="<?= $pp['id'] ?>" <?= $periodId == $pp['id'] ? 'selected' : '' ?>>
                                    <?= str_pad($pp['month'], 2, '0', STR_PAD_LEFT) ?>/<?= $pp['year'] ?>
                                    (<?= $pp['emp_count'] ?> employees)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>View</button>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex gap-2">
                            <?php if ($periodId): ?>
                                <a href="?page=report/pf/form-9&payroll_period_id=<?= $periodId ?>&client_id=<?= $clientId ?>&export=1" class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>CSV</a>
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

    <?php if (!empty($periodId) && $period): ?>
    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3">
                <div class="text-muted small">Employees</div>
                <div class="fs-4 fw-bold text-primary"><?= $summaryTotals['total_employees'] ?? 0 ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3">
                <div class="text-muted small">Total Wages</div>
                <div class="fs-4 fw-bold"><?= formatCurrency($summaryTotals['total_wages'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3">
                <div class="text-muted small">EPF (EE+ER)</div>
                <div class="fs-4 fw-bold text-success"><?= formatCurrency(($summaryTotals['total_epf_ee'] ?? 0) + ($summaryTotals['total_epf_er'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3">
                <div class="text-muted small">EPS</div>
                <div class="fs-4 fw-bold text-info"><?= formatCurrency($summaryTotals['total_eps'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3">
                <div class="text-muted small">EDLI + Admin</div>
                <div class="fs-4 fw-bold text-warning"><?= formatCurrency(($summaryTotals['total_edli'] ?? 0) + ($summaryTotals['total_admin'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center p-3 border-primary">
                <div class="text-muted small">Grand Total</div>
                <div class="fs-4 fw-bold text-primary"><?= formatCurrency($summaryTotals['grand_total'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <!-- Main Table -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>Return of Contributions — <?= str_pad($period['month'], 2, '0', STR_PAD_LEFT) ?>/<?= $period['year'] ?></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">Sr</th>
                            <th style="width:110px">PF Acct No.</th>
                            <th style="width:120px">UAN</th>
                            <th>Name</th>
                            <th class="text-end" style="width:100px">Wages for PF</th>
                            <th class="text-end" style="width:90px">EPF (EE)</th>
                            <th class="text-end" style="width:90px">EPF (ER)</th>
                            <th class="text-end" style="width:80px">EPS</th>
                            <th class="text-end" style="width:80px">EDLI</th>
                            <th class="text-end" style="width:90px">Admin</th>
                            <th class="text-end" style="width:100px">Total</th>
                            <th class="text-center" style="width:70px">NCP Days</th>
                            <th class="text-end" style="width:80px">Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contributions)): ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No contribution data found for this period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($contributions as $c):
                                $rowTotal = ($c['pf_employee'] ?? 0) + ($c['pf_employer'] ?? 0) +
                                             ($c['eps_employer'] ?? 0) + ($c['edlis_employer'] ?? 0) + ($c['epf_admin_charges'] ?? 0);
                                $ncpDays = max(0, ($c['total_days'] ?? 0) - ($c['paid_days'] ?? 0));
                            ?>
                                <tr <?= $ncpDays > 0 ? 'class="table-warning"' : '' ?>>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($c['esic_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($c['uan_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($c['full_name']) ?></td>
                                    <td class="text-end"><?= formatCurrency($c['basic_da'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($c['pf_employee'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($c['pf_employer'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($c['eps_employer'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($c['edlis_employer'] ?? 0) ?></td>
                                    <td class="text-end"><?= formatCurrency($c['epf_admin_charges'] ?? 0) ?></td>
                                    <td class="text-end fw-bold"><?= formatCurrency($rowTotal) ?></td>
                                    <td class="text-center"><?= $ncpDays ?></td>
                                    <td class="text-end">0.00</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($contributions)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="4" class="text-end"><?= $summaryTotals['total_employees'] ?> Employees</th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['total_wages']) ?></th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['total_epf_ee']) ?></th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['total_epf_er']) ?></th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['total_eps']) ?></th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['total_edli']) ?></th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['total_admin']) ?></th>
                                <th class="text-end"><?= formatCurrency($summaryTotals['grand_total']) ?></th>
                                <th class="text-center"><?= $summaryTotals['total_ncp'] ?></th>
                                <th class="text-end">0.00</th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Summary Section -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>Contribution Summary</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted">Number of Employees</td><td class="text-end fw-bold"><?= $summaryTotals['total_employees'] ?></td></tr>
                        <tr><td class="text-muted">Total Wages for PF (Basic+DA)</td><td class="text-end fw-bold"><?= formatCurrency($summaryTotals['total_wages']) ?></td></tr>
                        <tr><td class="text-muted">Total Non-Contribution Period Days</td><td class="text-end fw-bold"><?= $summaryTotals['total_ncp'] ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted">Total EPF Employee Share</td><td class="text-end fw-bold"><?= formatCurrency($summaryTotals['total_epf_ee']) ?></td></tr>
                        <tr><td class="text-muted">Total EPF Employer Share</td><td class="text-end fw-bold"><?= formatCurrency($summaryTotals['total_epf_er']) ?></td></tr>
                        <tr><td class="text-muted">Total EPS</td><td class="text-end fw-bold"><?= formatCurrency($summaryTotals['total_eps']) ?></td></tr>
                        <tr><td class="text-muted">Total EDLI</td><td class="text-end fw-bold"><?= formatCurrency($summaryTotals['total_edli']) ?></td></tr>
                        <tr><td class="text-muted">Total Admin Charges</td><td class="text-end fw-bold"><?= formatCurrency($summaryTotals['total_admin']) ?></td></tr>
                        <tr class="border-top"><td class="fw-bold">Grand Total Remittance</td><td class="text-end fw-bold text-primary"><?= formatCurrency($summaryTotals['grand_total']) ?></td></tr>
                    </table>
                </div>
            </div>
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

    <?php else: ?>
    <div class="text-center py-5">
        <i class="bi bi-file-earmark-text text-muted" style="font-size:48px;"></i>
        <p class="text-muted mt-3">Select a payroll period to view Form 9.</p>
    </div>
    <?php endif; ?>
</div>
