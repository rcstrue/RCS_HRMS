<?php
/**
 * RCS HRMS Pro - Summary Reports
 * Annual Salary Summary, Department-wise, Company Monthly & Annual
 */

$pageTitle = 'Summary Reports';

$tab = sanitize($_GET['tab'] ?? 'annual');
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? 0);
$clientFilter = (int)($_GET['client_id'] ?? 0);

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ========== TAB: Annual Salary Summary ==========
$annualData = [];
if ($tab === 'annual') {
    $where = "pp.year = :year";
    $params = [':year' => $year];
    if ($clientFilter) { $where .= " AND e.client_id = :cid"; $params[':cid'] = $clientFilter; }
    
    // Employee-wise annual summary
    $annualData = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.designation, c.name as client_name,
                SUM(p.gross_earnings) as total_gross,
                SUM(p.total_deductions) as total_deductions,
                SUM(p.net_pay) as total_net,
                SUM(p.pf_employee) as total_pf_ee,
                SUM(p.pf_employer) as total_pf_er,
                SUM(p.esi_employee) as total_esi_ee,
                SUM(p.esi_employer) as total_esi_er,
                SUM(p.professional_tax) as total_pt,
                SUM(p.ctc) as total_ctc,
                COUNT(DISTINCT pp.id) as months_worked
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN clients c ON e.client_id = c.id
         WHERE $where
         GROUP BY e.employee_code, e.full_name, e.designation, c.name
         ORDER BY total_net DESC",
        $params
    );
}

// ========== TAB: Department-wise Summary ==========
$deptData = [];
if ($tab === 'department') {
    $where = "pp.year = :year";
    $params = [':year' => $year];
    if ($month) { $where .= " AND pp.month = :month"; $params[':month'] = $month; }
    
    $deptData = $db->fetchAll(
        "SELECT COALESCE(d.name, 'No Department') as department_name,
                COUNT(DISTINCT e.employee_code) as employee_count,
                SUM(p.gross_earnings) as total_gross,
                SUM(p.total_deductions) as total_deductions,
                SUM(p.net_pay) as total_net,
                SUM(p.ctc) as total_ctc,
                AVG(p.net_pay) as avg_net
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN departments d ON e.department_id = d.id
         WHERE $where
         GROUP BY d.name
         ORDER BY total_net DESC",
        $params
    );
}

// ========== TAB: Company Monthly & Annual ==========
$companyMonthly = [];
if ($tab === 'company') {
    $companyMonthly = $db->fetchAll(
        "SELECT pp.month, pp.year,
                COUNT(DISTINCT p.employee_id) as employee_count,
                SUM(p.gross_earnings) as total_gross,
                SUM(p.total_deductions) as total_deductions,
                SUM(p.net_pay) as total_net,
                SUM(p.ctc) as total_ctc
         FROM payroll p
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         JOIN employees e ON p.employee_id = e.employee_code
         WHERE pp.year = :year
         GROUP BY pp.month, pp.year
         ORDER BY pp.month",
        [':year' => $year]
    );
}

// Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="summary_' . $tab . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Summary Report - ' . ucfirst($tab) . ' - ' . $year]);
    $rows = $tab === 'annual' ? $annualData : ($tab === 'department' ? $deptData : $companyMonthly);
    if (!empty($rows)) {
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($output, $r);
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Summary Reports</h4>
            <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'"><i class="bi bi-download me-1"></i>Export CSV</button>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?php echo $tab==='annual'?'active':''; ?>" href="?page=report/summary-reports&tab=annual&year=<?php echo $year; ?>">Employee Annual</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='department'?'active':''; ?>" href="?page=report/summary-reports&tab=department&year=<?php echo $year; ?>">Department-wise</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='company'?'active':''; ?>" href="?page=report/summary-reports&tab=company&year=<?php echo $year; ?>">Company Monthly/Annual</a></li>
        </ul>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/summary-reports">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                    <div class="col-md-2">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y=date('Y');$y>=date('Y')-3;$y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y==$year?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php if ($tab === 'department'): ?>
                    <div class="col-md-2">
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Full Year</option>
                            <?php for ($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month==$m?'selected':''; ?>><?php echo date('F',mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($tab === 'annual'): ?>
        <!-- Annual Salary Summary -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Employee Annual Salary Summary - <?php echo $year; ?></h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover" id="annualTable" style="font-size:0.8rem;">
                        <thead class="table-dark">
                            <tr><th>#</th><th>Code</th><th>Name</th><th>Designation</th><th>Client</th><th class="text-end">Months</th><th class="text-end">Total Gross</th><th class="text-end">Total Ded</th><th class="text-end" style="background:#198754;"><strong>Total Net</strong></th><th class="text-end">CTC</th></tr>
                        </thead>
                        <tbody>
                            <?php $grandNet = 0; $grandCTC = 0; foreach ($annualData as $i => $r): $grandNet += $r['total_net']; $grandCTC += $r['total_ctc']; ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($r['designation']); ?></td>
                                <td><?php echo sanitize($r['client_name']); ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?php echo $r['months_worked']; ?></span></td>
                                <td class="text-end"><?php echo formatCurrency($r['total_gross']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($r['total_deductions']); ?></td>
                                <td class="text-end" style="background:#e8f5e9;"><strong><?php echo formatCurrency($r['total_net']); ?></strong></td>
                                <td class="text-end"><?php echo formatCurrency($r['total_ctc']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($annualData)): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <td colspan="5"><strong>TOTAL (<?php echo count($annualData); ?> employees)</strong></td>
                                <td></td>
                                <td></td><td></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandNet); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandCTC); ?></strong></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'department'): ?>
        <!-- Department-wise Summary -->
        <div class="row g-3">
            <?php $grandNet = 0; foreach ($deptData as $d): $grandNet += $d['total_net']; ?>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between">
                        <h6 class="mb-0"><?php echo sanitize($d['department_name']); ?></h6>
                        <span class="badge bg-primary"><?php echo $d['employee_count']; ?> Emp</span>
                    </div>
                    <div class="card-body">
                        <div class="row small">
                            <div class="col-6 text-muted">Total Gross:</div><div class="col-6 text-end"><?php echo formatCurrency($d['total_gross']); ?></div>
                            <div class="col-6 text-muted">Total Deductions:</div><div class="col-6 text-end text-danger"><?php echo formatCurrency($d['total_deductions']); ?></div>
                            <div class="col-6 text-muted">Avg Net Pay:</div><div class="col-6 text-end"><?php echo formatCurrency($d['avg_net']); ?></div>
                            <div class="col-6 text-muted fw-bold">Total Net:</div><div class="col-6 text-end fw-bold text-success"><?php echo formatCurrency($d['total_net']); ?></div>
                            <div class="col-6 text-muted">Total CTC:</div><div class="col-6 text-end"><?php echo formatCurrency($d['total_ctc']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif ($tab === 'company'): ?>
        <!-- Company Monthly Summary with Chart -->
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Monthly Summary - <?php echo $year; ?></h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered" id="monthlyTable" style="font-size:0.8rem;">
                                <thead class="table-dark">
                                    <tr><th>Month</th><th class="text-center">Employees</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net Pay</th><th class="text-end">CTC</th></tr>
                                </thead>
                                <tbody>
                                    <?php $annGross = 0; $annDed = 0; $annNet = 0; $annCTC = 0;
                                    foreach ($companyMonthly as $r):
                                        $annGross += $r['total_gross']; $annDed += $r['total_deductions'];
                                        $annNet += $r['total_net']; $annCTC += $r['total_ctc'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo date('F', mktime(0,0,0,$r['month'],1)); ?></strong></td>
                                        <td class="text-center"><span class="badge bg-secondary"><?php echo $r['employee_count']; ?></span></td>
                                        <td class="text-end"><?php echo formatCurrency($r['total_gross']); ?></td>
                                        <td class="text-end text-danger"><?php echo formatCurrency($r['total_deductions']); ?></td>
                                        <td class="text-end text-success"><strong><?php echo formatCurrency($r['total_net']); ?></strong></td>
                                        <td class="text-end"><?php echo formatCurrency($r['total_ctc']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-success">
                                    <tr>
                                        <td><strong>ANNUAL TOTAL</strong></td>
                                        <td></td>
                                        <td class="text-end"><strong><?php echo formatCurrency($annGross); ?></strong></td>
                                        <td class="text-end"><strong><?php echo formatCurrency($annDed); ?></strong></td>
                                        <td class="text-end"><strong><?php echo formatCurrency($annNet); ?></strong></td>
                                        <td class="text-end"><strong><?php echo formatCurrency($annCTC); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Annual Overview - <?php echo $year; ?></h6></div>
                    <div class="card-body">
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Total Payroll Cost</span>
                                <strong class="h5 text-primary"><?php echo formatCurrency($annNet); ?></strong>
                            </div>
                        </div>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Total CTC</span>
                                <strong class="h5 text-info"><?php echo formatCurrency($annCTC); ?></strong>
                            </div>
                        </div>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Employer Contributions</span>
                                <strong class="h5 text-warning"><?php echo formatCurrency($annCTC - $annGross); ?></strong>
                            </div>
                        </div>
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Avg Monthly Payroll</span>
                                <strong class="h5 text-success"><?php echo formatCurrency($annNet / max(1, count($companyMonthly))); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#annualTable, #monthlyTable').DataTable({ responsive: true, pageLength: 50, ordering: false });
});
</script>
