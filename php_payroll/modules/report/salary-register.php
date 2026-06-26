<?php
/**
 * RCS HRMS Pro - Salary Register Report
 * Portrait / Landscape / Legal styles, Department-wise, Location-wise, Without PF/ESI
 */

$pageTitle = 'Salary Register';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$reportStyle = sanitize($_GET['style'] ?? 'portrait');
$reportType = sanitize($_GET['type'] ?? 'all');
$search = sanitize($_GET['search'] ?? '');

// Build query
$where = "pp.month = :month AND pp.year = :year";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) { $where .= " AND e.client_id = :cid"; $params[':cid'] = $clientFilter; }
if ($unitFilter) { $where .= " AND e.unit_id = :uid"; $params[':uid'] = $unitFilter; }
if ($search) { $where .= " AND (e.full_name LIKE :search1 OR e.employee_code LIKE :search2)"; $params[':search1'] = '%' . $search . '%'; $params[':search2'] = '%' . $search . '%'; }

// Report type filters
if ($reportType === 'without_pf_esi') {
    $where .= " AND ess.pf_applicable = 0 AND ess.esi_applicable = 0";
}

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$units = [];
if ($clientFilter) {
    $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$clientFilter]);
}

// Get salary data
$sql = "SELECT 
            e.employee_code, e.full_name, e.designation, e.father_name, e.date_of_joining,
            c.name as client_name, u.name as unit_name,
            ess.pf_applicable, ess.esi_applicable,
            p.basic_da, p.hra, p.washing_allowance, p.leave_encashment, p.bonus_encashment,
            p.overtime_amount, p.gross_earnings, p.gross_salary,
            p.pf_employee, p.esi_employee, p.professional_tax, p.lwf_employee,
            p.salary_advance, p.office_deduction, p.trust_deduction, p.total_deductions,
            p.net_pay, p.paid_days, p.total_days, p.overtime_hours,
            p.pf_employer, p.eps_employer, p.esi_employer, p.total_employer_contribution,
            p.ctc
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        LEFT JOIN (
            SELECT employee_id, pf_applicable, esi_applicable
            FROM employee_salary_structures
            WHERE effective_to IS NULL OR effective_to >= CURDATE()
            GROUP BY employee_id
        ) ess ON e.id = ess.employee_id
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE $where
        GROUP BY e.id
        ORDER BY c.name, u.name, e.employee_code";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'basic_da' => 0, 'hra' => 0, 'washing' => 0, 'leave_enc' => 0, 'bonus_enc' => 0,
    'ot' => 0, 'gross' => 0, 'pf_emp' => 0, 'esi_emp' => 0, 'pt' => 0, 'lwf' => 0,
    'advance' => 0, 'office_ded' => 0, 'trust_ded' => 0, 'total_ded' => 0, 'net_pay' => 0,
    'pf_empr' => 0, 'eps_empr' => 0, 'esi_empr' => 0, 'employer_contrib' => 0, 'ctc' => 0
];
foreach ($data as $row) {
    $totals['basic_da'] += floatval($row['basic_da']);
    $totals['hra'] += floatval($row['hra']);
    $totals['washing'] += floatval($row['washing_allowance']);
    $totals['leave_enc'] += floatval($row['leave_encashment']);
    $totals['bonus_enc'] += floatval($row['bonus_encashment']);
    $totals['ot'] += floatval($row['overtime_amount']);
    $totals['gross'] += floatval($row['gross_salary'] ?? $row['gross_earnings']);
    $totals['pf_emp'] += floatval($row['pf_employee']);
    $totals['esi_emp'] += floatval($row['esi_employee']);
    $totals['pt'] += floatval($row['professional_tax']);
    $totals['lwf'] += floatval($row['lwf_employee']);
    $totals['advance'] += floatval($row['salary_advance']);
    $totals['office_ded'] += floatval($row['office_deduction'] ?? 0);
    $totals['trust_ded'] += floatval($row['trust_deduction'] ?? 0);
    $totals['total_ded'] += floatval($row['total_deductions']);
    $totals['net_pay'] += floatval($row['net_pay']);
    $totals['pf_empr'] += floatval($row['pf_employer']);
    $totals['eps_empr'] += floatval($row['eps_employer']);
    $totals['esi_empr'] += floatval($row['esi_employer']);
    $totals['employer_contrib'] += floatval($row['total_employer_contribution']);
    $totals['ctc'] += floatval($row['ctc']);
}

// Export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'salary_register_' . date('M_Y', mktime(0,0,0,$month,1,$year)) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Salary Register - ' . date('F Y', mktime(0,0,0,$month,1,$year))]);
    fputcsv($output, ['#','Code','Name','Designation','Unit','PD','Basic+DA','HRA','Wash','L.Enc','B.Enc','OT','Gross','PF(EE)','ESI(EE)','PT','LWF','Adv','Tot Ded','Net Pay']);
    foreach ($data as $i => $r) {
        fputcsv($output, [$i+1, $r['employee_code'], $r['full_name'], $r['designation'], $r['unit_name'],
            $r['paid_days'], $r['basic_da'], $r['hra'], $r['washing_allowance'], $r['leave_encashment'],
            $r['bonus_encashment'], $r['overtime_amount'], $r['gross_salary'] ?? $r['gross_earnings'],
            $r['pf_employee'], $r['esi_employee'], $r['professional_tax'], $r['lwf_employee'],
            $r['salary_advance'], $r['total_deductions'], $r['net_pay']]);
    }
    fputcsv($output, ['','TOTAL','','','','', $totals['basic_da'], $totals['hra'], $totals['washing'], $totals['leave_enc'],
        $totals['bonus_enc'], $totals['ot'], $totals['gross'], $totals['pf_emp'], $totals['esi_emp'],
        $totals['pt'], $totals['lwf'], $totals['advance'], $totals['total_ded'], $totals['net_pay']]);
    fclose($output);
    exit;
}

$monthName = date('F', mktime(0,0,0,$month,1,$year));
$styleLabels = ['portrait'=>'Portrait (A4)','landscape'=>'Landscape (A4)','legal'=>'Legal (A3)'];
$typeLabels = ['all'=>'All Employees','without_pf_esi'=>'Without PF/ESI (Stipend)'];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Salary Register</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
                <button class="btn btn-outline-info" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/salary-register">
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select name="month" class="form-select form-select-sm">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>><?php echo date('M', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter==$u['id']?'selected':''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">Style</label>
                        <select name="style" class="form-select form-select-sm">
                            <option value="portrait" <?php echo $reportStyle==='portrait'?'selected':''; ?>>Portrait</option>
                            <option value="landscape" <?php echo $reportStyle==='landscape'?'selected':''; ?>>Landscape</option>
                            <option value="legal" <?php echo $reportStyle==='legal'?'selected':''; ?>>Legal</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Type</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="all" <?php echo $reportType==='all'?'selected':''; ?>>All</option>
                            <option value="without_pf_esi" <?php echo $reportType==='without_pf_esi'?'selected':''; ?>>Without PF/ESI</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto"><div class="card bg-light border"><div class="card-body py-2 px-3 text-center"><small class="text-muted">Employees</small><div class="h5 mb-0"><?php echo count($data); ?></div></div></div></div>
            <div class="col-auto"><div class="card bg-light border"><div class="card-body py-2 px-3 text-center"><small class="text-muted">Gross</small><div class="h5 mb-0 text-primary"><?php echo formatCurrency($totals['gross']); ?></div></div></div></div>
            <div class="col-auto"><div class="card bg-light border"><div class="card-body py-2 px-3 text-center"><small class="text-muted">Total Ded</small><div class="h5 mb-0 text-danger"><?php echo formatCurrency($totals['total_ded']); ?></div></div></div></div>
            <div class="col-auto"><div class="card bg-light border"><div class="card-body py-2 px-3 text-center"><small class="text-muted">Net Pay</small><div class="h5 mb-0 text-success"><?php echo formatCurrency($totals['net_pay']); ?></div></div></div></div>
            <div class="col-auto"><div class="card bg-light border"><div class="card-body py-2 px-3 text-center"><small class="text-muted">CTC</small><div class="h5 mb-0 text-info"><?php echo formatCurrency($totals['ctc']); ?></div></div></div></div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">SALARY REGISTER - <?php echo strtoupper($monthName) . ' ' . $year; ?></h5>
            <small class="text-muted"><?php echo $styleLabels[$reportStyle]; ?> | <?php echo $typeLabels[$reportType]; ?>
            <?php if ($clientFilter): echo ' | Client: ' . sanitize($data[0]['client_name'] ?? ''); endif; ?>
            <?php if ($unitFilter): echo ' | Unit: ' . sanitize($data[0]['unit_name'] ?? ''); endif; ?>
            </small>
        </div>

        <!-- Salary Register Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:28px;">#</th>
                                <th rowspan="2">Emp Code</th>
                                <th rowspan="2">Employee Name</th>
                                <th rowspan="2">Designation</th>
                                <th rowspan="2">Unit</th>
                                <th rowspan="2" class="text-center">PD</th>
                                <th colspan="6" class="text-center" style="background:#198754;">EARNINGS (₹)</th>
                                <th rowspan="2" class="text-end" style="background:#20c997;"><strong>Gross</strong></th>
                                <th colspan="7" class="text-center" style="background:#dc3545;">DEDUCTIONS (₹)</th>
                                <th rowspan="2" class="text-end" style="background:#fd7e14;"><strong>Tot Ded</strong></th>
                                <th rowspan="2" class="text-end" style="background:#0d6efd;"><strong>Net Pay</strong></th>
                            </tr>
                            <tr>
                                <th class="text-end" style="background:#198754;">Basic+DA</th>
                                <th class="text-end" style="background:#198754;">HRA</th>
                                <th class="text-end" style="background:#198754;">Wash</th>
                                <th class="text-end" style="background:#198754;">L.Enc</th>
                                <th class="text-end" style="background:#198754;">B.Enc</th>
                                <th class="text-end" style="background:#198754;">OT</th>
                                <th class="text-end" style="background:#dc3545;">PF</th>
                                <th class="text-end" style="background:#dc3545;">ESI</th>
                                <th class="text-end" style="background:#dc3545;">PT</th>
                                <th class="text-end" style="background:#dc3545;">LWF</th>
                                <th class="text-end" style="background:#dc3545;">Adv</th>
                                <th class="text-end" style="background:#dc3545;">Off</th>
                                <th class="text-end" style="background:#dc3545;">Tr</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                            <tr><td colspan="21" class="text-center py-4 text-muted">No payroll data found for selected period.</td></tr>
                            <?php else: $i = 0;
                            foreach ($data as $row):
                                $i++;
                                $gross = floatval($row['gross_salary'] ?? $row['gross_earnings']);
                                $offDed = floatval($row['office_deduction'] ?? 0);
                                $trDed = floatval($row['trust_deduction'] ?? 0);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['designation']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['unit_name']); ?></td>
                                <td class="text-center"><?php echo $row['paid_days']; ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['basic_da']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['hra']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['washing_allowance']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['leave_encashment']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['bonus_encashment']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['overtime_amount']),0); ?></td>
                                <td class="text-end" style="background:#e8f5e9;"><strong><?php echo number_format($gross,0); ?></strong></td>
                                <td class="text-end"><?php echo number_format(floatval($row['pf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['esi_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['professional_tax']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['lwf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['salary_advance']),0); ?></td>
                                <td class="text-end"><?php echo number_format($offDed,0); ?></td>
                                <td class="text-end"><?php echo number_format($trDed,0); ?></td>
                                <td class="text-end" style="background:#ffe0b2;"><strong><?php echo number_format(floatval($row['total_deductions']),0); ?></strong></td>
                                <td class="text-end" style="background:#bbdefb;"><strong><?php echo number_format(floatval($row['net_pay']),0); ?></strong></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($data)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="5"><strong>TOTAL (<?php echo count($data); ?> Employees)</strong></td>
                                <td></td>
                                <td class="text-end"><strong><?php echo number_format($totals['basic_da'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['hra'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['washing'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['leave_enc'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['bonus_enc'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['ot'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['gross'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['pf_emp'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['esi_emp'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['pt'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['lwf'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['advance'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['office_ded'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['trust_ded'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['total_ded'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['net_pay'],0); ?></strong></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Employer Contribution Summary -->
        <?php if (!empty($data)): ?>
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">Employer Contribution Summary</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;">
                        <thead class="table-light">
                            <tr>
                                <th>PF (ER)</th><th>EPS (ER)</th><th>ESI (ER)</th><th>Total Employer</th><th>CTC (Gross + ER)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-end"><?php echo formatCurrency($totals['pf_empr']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['eps_empr']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($totals['esi_empr']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totals['employer_contrib']); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totals['ctc']); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header .btn { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 8pt; }
    .table td, .table th { padding: 2px 4px !important; }
}
</style>
