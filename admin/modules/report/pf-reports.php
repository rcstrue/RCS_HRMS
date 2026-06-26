<?php
/**
 * RCS HRMS Pro - PF Reports Hub
 * Form 3A, Form 6A, Form 12A, Account Register, Summary, Challan
 */

$pageTitle = 'PF Reports';

$tab = sanitize($_GET['tab'] ?? 'account_register');
$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Build base WHERE
$baseWhere = "pp.month = :month AND pp.year = :year AND ess.pf_applicable = 1";
$baseParams = [':month' => $month, ':year' => $year];
if ($clientFilter) { $baseWhere .= " AND e.client_id = :cid"; $baseParams[':cid'] = $clientFilter; }

$monthName = date('F', mktime(0,0,0,$month,1,$year));

// ========== TAB: Account Register ==========
$accountRegister = [];
if ($tab === 'account_register') {
    $accountRegister = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.father_name, e.gender, e.date_of_joining,
                e.date_of_leaving, e.uan_number, e.pf_number,
                ess.basic_da as monthly_wages,
                p.basic_da as epf_wages,
                p.pf_employee as ee_pf, p.pf_employer as er_pf,
                p.eps_employer as er_eps,
                c.name as client_name
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         LEFT JOIN clients c ON e.client_id = c.id
         WHERE $baseWhere
         ORDER BY e.employee_code",
        $baseParams
    );
}

// ========== TAB: Summary ==========
$pfSummary = [];
if ($tab === 'summary') {
    $pfSummary = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.uan_number, e.pf_number,
                e.date_of_joining, e.gender,
                p.basic_da, p.pf_employee, p.pf_employer, p.eps_employer,
                p.gross_salary, c.name as client_name
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         LEFT JOIN clients c ON e.client_id = c.id
         WHERE $baseWhere
         ORDER BY c.name, e.employee_code",
        $baseParams
    );
}

$pfTotals = ['wages' => 0, 'ee_pf' => 0, 'er_pf' => 0, 'er_eps' => 0, 'total' => 0];
foreach ($tab === 'summary' ? $pfSummary : $accountRegister as $r) {
    $pfTotals['wages'] += floatval($r['basic_da'] ?? $r['epf_wages'] ?? 0);
    $pfTotals['ee_pf'] += floatval($r['pf_employee'] ?? $r['ee_pf'] ?? 0);
    $pfTotals['er_pf'] += floatval($r['pf_employer'] ?? $r['er_pf'] ?? 0);
    $pfTotals['er_eps'] += floatval($r['eps_employer'] ?? $r['er_eps'] ?? 0);
}
$pfTotals['total'] = $pfTotals['ee_pf'] + $pfTotals['er_pf'] + $pfTotals['er_eps'];

// ========== TAB: Form 12A (Monthly Challan) ==========
$form12aData = [];
if ($tab === 'form12a') {
    $company = $db->fetch("SELECT * FROM companies LIMIT 1");
    
    // A: Total number of employees
    $totalEmployees = $db->fetchColumn(
        "SELECT COUNT(DISTINCT p.employee_id) FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $baseWhere", $baseParams
    ) ?: 0;
    
    // B: Employees who contributed (wages <= 15000 or PF applicable)
    $contributing = $db->fetchColumn(
        "SELECT COUNT(DISTINCT p.employee_id) FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $baseWhere AND p.pf_employee > 0", $baseParams
    ) ?: 0;
    
    // C: Total wages
    $totalWages = $db->fetchColumn(
        "SELECT SUM(p.basic_da) FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $baseWhere", $baseParams
    ) ?: 0;
    
    $form12aData = [
        'company' => $company,
        'total_employees' => $totalEmployees,
        'contributing' => $contributing,
        'total_wages' => $totalWages,
        'ee_share' => $pfTotals['ee_pf'],
        'er_pf_share' => $pfTotals['er_pf'],
        'er_eps_share' => $pfTotals['er_eps'],
        'admin_charges' => round($totalWages * 0.5 / 100, 2),
        'edli_charges' => round($totalWages * 0.5 / 100, 2),
        'total_remittance' => $pfTotals['total'] + round($totalWages * 1.0 / 100, 2)
    ];
}

// ========== TAB: Form 3A / 6A ==========
$form3aData = [];
if ($tab === 'form3a') {
    $form3aData = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.father_name, e.gender,
                e.date_of_joining, e.date_of_leaving, e.uan_number,
                ess.basic_da as monthly_basic,
                p.paid_days, p.basic_da, p.pf_employee, p.pf_employer, p.eps_employer,
                p.overtime_amount, c.name as client_name
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         LEFT JOIN clients c ON e.client_id = c.id
         WHERE $baseWhere
         ORDER BY e.employee_code",
        $baseParams
    );
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pf_report_' . $tab . '_' . $monthName . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['PF Report - ' . $tab . ' - ' . $monthName . ' ' . $year]);
    $rows = $tab === 'summary' ? $pfSummary : $accountRegister;
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
            <h4 class="mb-0"><i class="bi bi-piggy-bank me-2"></i>PF Reports</h4>
            <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'"><i class="bi bi-download me-1"></i>Export CSV</button>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/pf-reports">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                    <div class="col-md-2">
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m==$month?'selected':''; ?>><?php echo date('M',mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y=date('Y');$y>=date('Y')-3;$y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y==$year?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
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

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?php echo $tab==='account_register'?'active':''; ?>" href="?page=report/pf-reports&tab=account_register&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Account Register</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='summary'?'active':''; ?>" href="?page=report/pf-reports&tab=summary&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Summary (EE+ER)</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='form12a'?'active':''; ?>" href="?page=report/pf-reports&tab=form12a&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Form 12A (Challan)</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='form3a'?'active':''; ?>" href="?page=report/pf-reports&tab=form3a&month=<?php echo $month; ?>&year=<?php echo $year; ?>">Form 3A / 6A</a></li>
        </ul>

        <?php if ($tab === 'account_register'): ?>
        <!-- Account Register -->
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0">PF Account Register - <?php echo $monthName . ' ' . $year; ?></h6>
                <button class="btn btn-sm btn-outline-info" onclick="window.print()"><i class="bi bi-printer"></i></button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered" id="pfRegTable" style="font-size:0.8rem;">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th><th>Emp Code</th><th>Name</th><th>UAN</th><th>DOJ</th><th>DOL</th>
                                <th class="text-end">EPF Wages</th><th class="text-end">EE PF</th><th class="text-end">ER PF</th><th class="text-end">EPS</th><th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accountRegister as $i => $r): $total = floatval($r['ee_pf']??0) + floatval($r['er_pf']??0) + floatval($r['er_eps']??0); ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td><small><?php echo sanitize($r['uan_number'] ?? '-'); ?></small></td>
                                <td><?php echo formatDate($r['date_of_joining']); ?></td>
                                <td><?php echo !empty($r['date_of_leaving']) ? formatDate($r['date_of_leaving']) : '-'; ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['epf_wages'] ?? $r['basic_da']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['ee_pf'] ?? $r['pf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['er_pf'] ?? $r['pf_employer']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['er_eps'] ?? $r['eps_employer']),0); ?></td>
                                <td class="text-end"><strong><?php echo number_format($total,0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <td colspan="6"><strong>TOTAL (<?php echo count($accountRegister); ?> members)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($pfTotals['wages'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($pfTotals['ee_pf'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($pfTotals['er_pf'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($pfTotals['er_eps'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($pfTotals['total'],0); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'summary'): ?>
        <!-- PF Summary Employee-wise + Company-wise -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Employee-wise PF Summary</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-bordered" id="pfSumTable" style="font-size:0.8rem;">
                                <thead class="table-light">
                                    <tr><th>#</th><th>Code</th><th>Name</th><th>UAN</th><th class="text-end">Wages</th><th class="text-end">EE Share</th><th class="text-end">ER PF</th><th class="text-end">ER EPS</th><th class="text-end">Total</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pfSummary as $i => $r): $tot = floatval($r['pf_employee']??0) + floatval($r['pf_employer']??0) + floatval($r['eps_employer']??0); ?>
                                    <tr>
                                        <td><?php echo $i+1; ?></td>
                                        <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                        <td><?php echo sanitize($r['full_name']); ?></td>
                                        <td><small><?php echo sanitize($r['uan_number'] ?? '-'); ?></small></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['basic_da']),0); ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['pf_employee']),0); ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['pf_employer']),0); ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($r['eps_employer']),0); ?></td>
                                        <td class="text-end"><strong><?php echo number_format($tot,0); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <td colspan="4"><strong>TOTAL</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($pfTotals['wages'],0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($pfTotals['ee_pf'],0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($pfTotals['er_pf'],0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($pfTotals['er_eps'],0); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($pfTotals['total'],0); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Company-wise Summary</h6></div>
                    <div class="card-body">
                        <?php
                        $companyWise = [];
                        foreach ($pfSummary as $r) {
                            $cn = $r['client_name'] ?? 'Unknown';
                            if (!isset($companyWise[$cn])) $companyWise[$cn] = ['count'=>0,'ee'=>0,'er_pf'=>0,'er_eps'=>0,'wages'=>0];
                            $companyWise[$cn]['count']++;
                            $companyWise[$cn]['wages'] += floatval($r['basic_da']);
                            $companyWise[$cn]['ee'] += floatval($r['pf_employee']);
                            $companyWise[$cn]['er_pf'] += floatval($r['pf_employer']);
                            $companyWise[$cn]['er_eps'] += floatval($r['eps_employer']);
                        }
                        foreach ($companyWise as $cn => $cd): $cdTotal = $cd['ee'] + $cd['er_pf'] + $cd['er_eps']; ?>
                        <div class="border rounded p-2 mb-2">
                            <div class="d-flex justify-content-between"><strong><?php echo sanitize($cn); ?></strong><span class="badge bg-primary"><?php echo $cd['count']; ?> members</span></div>
                            <div class="row small mt-1">
                                <div class="col-6 text-muted">Wages:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['wages']); ?></div>
                                <div class="col-6 text-muted">EE Share:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['ee']); ?></div>
                                <div class="col-6 text-muted">ER PF:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['er_pf']); ?></div>
                                <div class="col-6 text-muted">ER EPS:</div><div class="col-6 text-end"><?php echo formatCurrency($cd['er_eps']); ?></div>
                                <div class="col-6 text-muted fw-bold">Total:</div><div class="col-6 text-end fw-bold"><?php echo formatCurrency($cdTotal); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'form12a'): ?>
        <!-- Form 12A -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Form 12A - Monthly Return of Contributions - <?php echo $monthName . ' ' . $year; ?></h6></div>
            <div class="card-body">
                <div class="border p-3 mb-3">
                    <h6 class="mb-2">Establishment: <?php echo sanitize($form12aData['company']['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></h6>
                    <small class="text-muted">PF Estb ID: <code><?php echo sanitize($form12aData['company']['pf_establishment_id'] ?? 'N/A'); ?></code> | Period: <?php echo $monthName . ' ' . $year; ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td class="fw-bold" width="60%">A. Total number of employees employed</td><td class="text-center h5"><?php echo $form12aData['total_employees']; ?></td></tr>
                            <tr><td class="fw-bold">B. Number of employees who contributed to PF</td><td class="text-center h5"><?php echo $form12aData['contributing']; ?></td></tr>
                            <tr><td class="fw-bold">C. Total amount of wages</td><td class="text-end h5"><?php echo formatCurrency($form12aData['total_wages']); ?></td></tr>
                            <tr class="table-light"><td class="fw-bold">D. Amount of contribution remitted</td><td></td></tr>
                            <tr><td class="ps-4">a) Employee's Share (EE 12%)</td><td class="text-end"><?php echo formatCurrency($form12aData['ee_share']); ?></td></tr>
                            <tr><td class="ps-4">b) Employer's Share - PF (3.67%)</td><td class="text-end"><?php echo formatCurrency($form12aData['er_pf_share']); ?></td></tr>
                            <tr><td class="ps-4">c) Employer's Share - EPS (8.33%)</td><td class="text-end"><?php echo formatCurrency($form12aData['er_eps_share']); ?></td></tr>
                            <tr><td class="ps-4">d) A/c Admin Charges (0.5%)</td><td class="text-end"><?php echo formatCurrency($form12aData['admin_charges']); ?></td></tr>
                            <tr><td class="ps-4">e) EDLI Charges (0.5%)</td><td class="text-end"><?php echo formatCurrency($form12aData['edli_charges']); ?></td></tr>
                            <tr class="table-dark"><td class="fw-bold">TOTAL REMITTANCE</td><td class="text-end h4"><?php echo formatCurrency($form12aData['total_remittance']); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($tab === 'form3a'): ?>
        <!-- Form 3A / Form 6A -->
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0">Form 3A (Individual) / Form 6A (Consolidated) - <?php echo $monthName . ' ' . $year; ?></h6>
                <button class="btn btn-sm btn-outline-info" onclick="window.print()"><i class="bi bi-printer"></i></button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="form3aTable" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2">#</th>
                                <th rowspan="2">Code</th>
                                <th rowspan="2">Name</th>
                                <th rowspan="2">Father</th>
                                <th rowspan="2">Gender</th>
                                <th rowspan="2">DOJ</th>
                                <th rowspan="2">UAN</th>
                                <th class="text-center" colspan="2">Wages (₹)</th>
                                <th class="text-center" colspan="3">Contribution (₹)</th>
                            </tr>
                            <tr>
                                <th class="text-center" style="background:#198754;">Actual</th>
                                <th class="text-center" style="background:#198754;">OT</th>
                                <th class="text-center" style="background:#0d6efd;">EE PF</th>
                                <th class="text-center" style="background:#fd7e14;">ER PF</th>
                                <th class="text-center" style="background:#dc3545;">EPS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($form3aData as $i => $r): ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td><?php echo sanitize($r['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($r['father_name'] ?? '-'); ?></td>
                                <td><?php echo strtoupper(substr($r['gender'] ?? 'M', 0, 1)); ?></td>
                                <td><?php echo formatDate($r['date_of_joining']); ?></td>
                                <td><small><?php echo sanitize($r['uan_number'] ?? '-'); ?></small></td>
                                <td class="text-end"><?php echo number_format(floatval($r['basic_da']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['overtime_amount']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['pf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['pf_employer']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($r['eps_employer']),0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#pfRegTable, #pfSumTable, #form3aTable').DataTable({ responsive: true, pageLength: 50, ordering: false });
});
@media print {
    .btn, form, .nav-tabs, .nav { display: none !important; }
    .table { font-size: 7pt; }
}
</script>
