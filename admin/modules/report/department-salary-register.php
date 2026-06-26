<?php
/**
 * RCS HRMS Pro - Department-wise Salary Register
 * Groups employees by department with subtotals per department and grand total
 */

$pageTitle = 'Department Salary Register';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);

// Get filter options
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

// Build query
$where = "pp.month = :month AND pp.year = :year AND e.status = 1";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}

// Get salary data grouped by department
$sql = "SELECT 
            COALESCE(e.department, 'Unassigned') as department,
            e.employee_code, e.full_name, e.designation,
            e.department as department_raw,
            p.paid_days, p.basic_da, p.hra, p.washing_allowance, 
            p.leave_encashment, p.bonus_encashment, p.overtime_amount,
            p.gross_earnings, p.gross_salary,
            p.pf_employee, p.esi_employee, p.professional_tax, p.lwf_employee,
            p.salary_advance, p.total_deductions, p.net_pay
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        LEFT JOIN clients c ON e.client_id = c.id
        WHERE $where
        ORDER BY e.department, e.employee_code";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $data = [];
    $error = $e->getMessage();
}

// Group data by department
$departments = [];
$deptTotals = [];
$grandTotals = [
    'paid_days' => 0, 'basic_da' => 0, 'hra' => 0, 'washing' => 0,
    'leave_enc' => 0, 'bonus_enc' => 0, 'ot' => 0, 'gross' => 0,
    'pf' => 0, 'esi' => 0, 'pt' => 0, 'lwf' => 0, 'adv' => 0,
    'tot_ded' => 0, 'net_pay' => 0, 'count' => 0
];

foreach ($data as $row) {
    $dept = $row['department'];
    if (!isset($departments[$dept])) {
        $departments[$dept] = [];
        $deptTotals[$dept] = [
            'paid_days' => 0, 'basic_da' => 0, 'hra' => 0, 'washing' => 0,
            'leave_enc' => 0, 'bonus_enc' => 0, 'ot' => 0, 'gross' => 0,
            'pf' => 0, 'esi' => 0, 'pt' => 0, 'lwf' => 0, 'adv' => 0,
            'tot_ded' => 0, 'net_pay' => 0, 'count' => 0
        ];
    }
    $departments[$dept][] = $row;

    $vals = [
        'paid_days' => floatval($row['paid_days']),
        'basic_da' => floatval($row['basic_da']),
        'hra' => floatval($row['hra']),
        'washing' => floatval($row['washing_allowance']),
        'leave_enc' => floatval($row['leave_encashment']),
        'bonus_enc' => floatval($row['bonus_encashment']),
        'ot' => floatval($row['overtime_amount']),
        'gross' => floatval($row['gross_salary'] ?? $row['gross_earnings']),
        'pf' => floatval($row['pf_employee']),
        'esi' => floatval($row['esi_employee']),
        'pt' => floatval($row['professional_tax']),
        'lwf' => floatval($row['lwf_employee']),
        'adv' => floatval($row['salary_advance']),
        'tot_ded' => floatval($row['total_deductions']),
        'net_pay' => floatval($row['net_pay']),
    ];

    foreach ($vals as $k => $v) {
        $deptTotals[$dept][$k] += $v;
        $grandTotals[$k] += $v;
    }
    $deptTotals[$dept]['count']++;
    $grandTotals['count']++;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'dept_salary_register_' . date('M_Y', mktime(0,0,0,$month,1,$year)) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Department Salary Register - ' . date('F Y', mktime(0,0,0,$month,1,$year))]);
    fputcsv($output, ['#','Emp Code','Name','Designation','Department','PD','Basic+DA','HRA','Wash','L.Enc','B.Enc','OT','Gross','PF','ESI','PT','LWF','Adv','Tot Ded','Net Pay']);
    
    foreach ($departments as $dept => $rows) {
        $i = 0;
        foreach ($rows as $row) {
            $i++;
            fputcsv($output, [
                $i, $row['employee_code'], $row['full_name'], $row['designation'], $dept,
                $row['paid_days'], $row['basic_da'], $row['hra'], $row['washing_allowance'],
                $row['leave_encashment'], $row['bonus_encashment'], $row['overtime_amount'],
                $row['gross_salary'] ?? $row['gross_earnings'], $row['pf_employee'],
                $row['esi_employee'], $row['professional_tax'], $row['lwf_employee'],
                $row['salary_advance'], $row['total_deductions'], $row['net_pay']
            ]);
        }
        fputcsv($output, ['', '', '', '', 'Subtotal: ' . $dept, '', 
            $deptTotals[$dept]['basic_da'], $deptTotals[$dept]['hra'], $deptTotals[$dept]['washing'],
            $deptTotals[$dept]['leave_enc'], $deptTotals[$dept]['bonus_enc'], $deptTotals[$dept]['ot'],
            $deptTotals[$dept]['gross'], $deptTotals[$dept]['pf'], $deptTotals[$dept]['esi'],
            $deptTotals[$dept]['pt'], $deptTotals[$dept]['lwf'], $deptTotals[$dept]['adv'],
            $deptTotals[$dept]['tot_ded'], $deptTotals[$dept]['net_pay']
        ]);
        fputcsv($output, []);
    }
    
    fputcsv($output, ['', '', '', '', 'GRAND TOTAL', '', 
        $grandTotals['basic_da'], $grandTotals['hra'], $grandTotals['washing'],
        $grandTotals['leave_enc'], $grandTotals['bonus_enc'], $grandTotals['ot'],
        $grandTotals['gross'], $grandTotals['pf'], $grandTotals['esi'],
        $grandTotals['pt'], $grandTotals['lwf'], $grandTotals['adv'],
        $grandTotals['tot_ded'], $grandTotals['net_pay']
    ]);
    fclose($output);
    exit;
}

$monthName = date('F', mktime(0,0,0,$month,1,$year));
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-building me-2"></i>Department Salary Register</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" onclick="window.location.href+='&export=csv'">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
                <button class="btn btn-outline-info btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/department-salary-register">
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
                            <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Departments</small>
                        <div class="h5 mb-0"><?php echo count($departments); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Employees</small>
                        <div class="h5 mb-0"><?php echo $grandTotals['count']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Gross Salary</small>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($grandTotals['gross']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Deductions</small>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($grandTotals['tot_ded']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Net Pay</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($grandTotals['net_pay']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">DEPARTMENT-WISE SALARY REGISTER</h5>
            <small class="text-muted"><?php echo strtoupper($monthName) . ' ' . $year; ?></small>
        </div>

        <!-- Data Table -->
        <?php if (empty($data)): ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle me-2"></i>No payroll data found for selected period.
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.72rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:30px;">#</th>
                                <th rowspan="2">Emp Code</th>
                                <th rowspan="2">Name</th>
                                <th rowspan="2">Designation</th>
                                <th rowspan="2" class="text-center">PD</th>
                                <th colspan="6" class="text-center" style="background:#198754;">EARNINGS (₹)</th>
                                <th rowspan="2" class="text-end" style="background:#20c997;">Gross</th>
                                <th colspan="7" class="text-center" style="background:#dc3545;">DEDUCTIONS (₹)</th>
                                <th rowspan="2" class="text-end" style="background:#fd7e14;">Tot Ded</th>
                                <th rowspan="2" class="text-end" style="background:#0d6efd;">Net Pay</th>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php $globalIdx = 0; ?>
                            <?php foreach ($departments as $dept => $rows): ?>
                            <!-- Department Header -->
                            <tr class="table-secondary">
                                <td colspan="18" class="fw-bold">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo sanitize($dept); ?> 
                                    <span class="text-muted">(<?php echo $deptTotals[$dept]['count']; ?> Employees)</span>
                                </td>
                            </tr>
                            <?php $deptIdx = 0; ?>
                            <?php foreach ($rows as $row):
                                $globalIdx++;
                                $deptIdx++;
                                $gross = floatval($row['gross_salary'] ?? $row['gross_earnings']);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $deptIdx; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['designation']); ?></td>
                                <td class="text-center"><?php echo $row['paid_days']; ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['basic_da']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['hra']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['washing_allowance']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['leave_encashment']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['bonus_encashment']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['overtime_amount']),0); ?></td>
                                <td class="text-end fw-bold" style="background:#e8f5e9;"><?php echo number_format($gross,0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['pf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['esi_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['professional_tax']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['lwf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['salary_advance']),0); ?></td>
                                <td class="text-end fw-bold" style="background:#ffe0b2;"><?php echo number_format(floatval($row['total_deductions']),0); ?></td>
                                <td class="text-end fw-bold" style="background:#bbdefb;"><?php echo number_format(floatval($row['net_pay']),0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Department Subtotal -->
                            <tr class="table-warning">
                                <td colspan="4" class="text-end fw-bold">Subtotal - <?php echo sanitize($dept); ?></td>
                                <td class="text-center"><?php echo $deptTotals[$dept]['count']; ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['basic_da'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['hra'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['washing'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['leave_enc'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['bonus_enc'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['ot'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['gross'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['pf'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['esi'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['pt'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['lwf'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['adv'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['tot_ded'],0); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($deptTotals[$dept]['net_pay'],0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <!-- Grand Total -->
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="4"><strong>GRAND TOTAL (<?php echo $grandTotals['count']; ?> Employees)</strong></td>
                                <td></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['basic_da'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['hra'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['washing'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['leave_enc'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['bonus_enc'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['ot'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['gross'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['pf'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['esi'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['pt'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['lwf'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['adv'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['tot_ded'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($grandTotals['net_pay'],0); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 7pt; }
    .table td, .table th { padding: 1px 3px !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>
