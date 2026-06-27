<?php
/**
 * RCS HRMS Pro - Stipend Register
 * Employees without PF/ESI (pf_applicable=0 AND esi_applicable=0)
 */

$pageTitle = 'Stipend Register';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);

// Get filter options
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

$units = [];
if ($clientFilter) {
    try {
        $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$clientFilter]);
    } catch (Exception $e) {
        $units = [];
    }
}

// Build query - only employees without PF and ESI
$where = "pp.month = :month AND pp.year = :year AND ess.pf_applicable = 0 AND ess.esi_applicable = 0";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}
if ($unitFilter) {
    $where .= " AND e.unit_id = :uid";
    $params[':uid'] = $unitFilter;
}

$sql = "SELECT 
            e.employee_code, e.full_name, e.designation, e.date_of_joining,
            u.name as unit_name,
            p.paid_days, p.basic_da, p.hra, p.washing_allowance, p.overtime_amount,
            p.gross_earnings, p.gross_salary,
            p.professional_tax, p.lwf_employee, p.salary_advance, p.total_deductions, p.net_pay
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE $where
        ORDER BY u.name, e.employee_code";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $data = [];
    $error = $e->getMessage();
}

// Calculate totals
$totals = [
    'basic_da' => 0, 'hra' => 0, 'washing' => 0, 'ot' => 0, 'gross' => 0,
    'pt' => 0, 'lwf' => 0, 'adv' => 0, 'tot_ded' => 0, 'net_pay' => 0
];
foreach ($data as $row) {
    $totals['basic_da'] += floatval($row['basic_da']);
    $totals['hra'] += floatval($row['hra']);
    $totals['washing'] += floatval($row['washing_allowance']);
    $totals['ot'] += floatval($row['overtime_amount']);
    $totals['gross'] += floatval($row['gross_salary'] ?? $row['gross_earnings']);
    $totals['pt'] += floatval($row['professional_tax']);
    $totals['lwf'] += floatval($row['lwf_employee']);
    $totals['adv'] += floatval($row['salary_advance']);
    $totals['tot_ded'] += floatval($row['total_deductions']);
    $totals['net_pay'] += floatval($row['net_pay']);
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'stipend_register_' . date('M_Y', mktime(0,0,0,$month,1,$year)) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Stipend Register (Without PF/ESI) - ' . date('F Y', mktime(0,0,0,$month,1,$year))]);
    fputcsv($output, ['#','Emp Code','Name','Designation','Unit','DOJ','Basic+DA','HRA','Wash','OT','Gross','PT','LWF','Adv','Net Pay']);
    foreach ($data as $i => $r) {
        fputcsv($output, [
            $i+1, $r['employee_code'], $r['full_name'], $r['designation'], $r['unit_name'],
            $r['date_of_joining'], $r['basic_da'], $r['hra'], $r['washing_allowance'],
            $r['overtime_amount'], $r['gross_salary'] ?? $r['gross_earnings'],
            $r['professional_tax'], $r['lwf_employee'], $r['salary_advance'], $r['net_pay']
        ]);
    }
    fputcsv($output, ['','TOTAL','','','','', $totals['basic_da'], $totals['hra'], $totals['washing'],
        $totals['ot'], $totals['gross'], $totals['pt'], $totals['lwf'], $totals['adv'], $totals['net_pay']]);
    fclose($output);
    exit;
}

$monthName = date('F', mktime(0,0,0,$month,1,$year));
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Stipend Register</h4>
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
                    <input type="hidden" name="page" value="report/stipend-register">
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
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter==$u['id']?'selected':''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Generate</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <!-- Info Banner -->
        <div class="alert alert-info py-2 mb-3">
            <small><i class="bi bi-info-circle me-1"></i>This register shows employees <strong>without PF and ESI</strong> (Stipend / Trainee / Intern employees).</small>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Stipend Employees</small>
                        <div class="h5 mb-0"><?php echo count($data); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Gross Stipend</small>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($totals['gross']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Deductions</small>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($totals['tot_ded']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Net Stipend</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($totals['net_pay']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">STIPEND REGISTER (WITHOUT PF/ESI)</h5>
            <small class="text-muted"><?php echo strtoupper($monthName) . ' ' . $year; ?></small>
        </div>

        <!-- Data Table -->
        <?php if (empty($data)): ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle me-2"></i>No stipend employees found for selected period.
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:30px;">#</th>
                                <th rowspan="2">Emp Code</th>
                                <th rowspan="2">Name</th>
                                <th rowspan="2">Designation</th>
                                <th rowspan="2">Unit</th>
                                <th rowspan="2">DOJ</th>
                                <th colspan="5" class="text-center" style="background:#198754;">EARNINGS (₹)</th>
                                <th colspan="3" class="text-center" style="background:#dc3545;">DEDUCTIONS (₹)</th>
                                <th rowspan="2" class="text-end" style="background:#0d6efd;">Net Pay</th>
                            </tr>
                            <tr>
                                <th class="text-end" style="background:#198754;">Basic+DA</th>
                                <th class="text-end" style="background:#198754;">HRA</th>
                                <th class="text-end" style="background:#198754;">Wash</th>
                                <th class="text-end" style="background:#198754;">OT</th>
                                <th class="text-end" style="background:#198754;">Gross</th>
                                <th class="text-end" style="background:#dc3545;">PT</th>
                                <th class="text-end" style="background:#dc3545;">LWF</th>
                                <th class="text-end" style="background:#dc3545;">Adv</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $i => $row):
                                $gross = floatval($row['gross_salary'] ?? $row['gross_earnings']);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i+1; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['designation']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['unit_name']); ?></td>
                                <td class="text-center"><?php echo $row['date_of_joining'] ? formatDate($row['date_of_joining']) : '-'; ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['basic_da']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['hra']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['washing_allowance']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['overtime_amount']),0); ?></td>
                                <td class="text-end fw-bold" style="background:#e8f5e9;"><?php echo number_format($gross,0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['professional_tax']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['lwf_employee']),0); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['salary_advance']),0); ?></td>
                                <td class="text-end fw-bold" style="background:#bbdefb;"><?php echo number_format(floatval($row['net_pay']),0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="6"><strong>TOTAL (<?php echo count($data); ?> Employees)</strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['basic_da'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['hra'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['washing'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['ot'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['gross'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['pt'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['lwf'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['adv'],0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['net_pay'],0); ?></strong></td>
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
    .btn, form, .alert-info { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 8pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>
