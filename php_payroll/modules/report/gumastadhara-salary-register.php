<?php
/**
 * RCS HRMS Pro - Gumastadhara Salary Register
 * Register of Wages under the Bombay Shops and Establishments Act, 1948
 * As required by Maharashtra Gumastadhara (Shop & Establishment) Inspector
 */

$pageTitle = 'Gumastadhara Salary Register';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

// Fetch filter dropdowns
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

// Build query for salary register
$where = "pp.month = :month AND pp.year = :year";
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
            e.employee_code, e.full_name, e.father_name, e.designation, e.department,
            e.date_of_joining, e.date_of_leaving, e.state,
            c.name AS client_name, u.name AS unit_name, u.state AS unit_state,
            ess.basic_da AS wages_rate, ess.gross_salary AS monthly_wages,
            p.basic_da, p.hra, p.washing_allowance, p.overtime_amount,
            p.gross_earnings, p.gross_salary,
            p.pf_employee, p.esi_employee, p.professional_tax, p.salary_advance,
            p.total_deductions, p.net_pay, p.paid_days, p.total_days, p.overtime_hours,
            pp.start_date, pp.end_date, pp.pay_days,
            ess.pf_applicable, ess.esi_applicable
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE $where
        ORDER BY c.name, u.name, e.employee_code";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $data = [];
}

// Calculate totals
$totals = [
    'wages' => 0, 'ot_amount' => 0, 'gross' => 0,
    'pf' => 0, 'esi' => 0, 'pt' => 0, 'advance' => 0, 'total_ded' => 0, 'net_pay' => 0
];

foreach ($data as $row) {
    $totals['wages'] += floatval($row['wages_rate'] ?? 0);
    $totals['ot_amount'] += floatval($row['overtime_amount'] ?? 0);
    $totals['gross'] += floatval($row['gross_salary'] ?? $row['gross_earnings'] ?? 0);
    $totals['pf'] += floatval($row['pf_employee'] ?? 0);
    $totals['esi'] += floatval($row['esi_employee'] ?? 0);
    $totals['pt'] += floatval($row['professional_tax'] ?? 0);
    $totals['advance'] += floatval($row['salary_advance'] ?? 0);
    $totals['total_ded'] += floatval($row['total_deductions'] ?? 0);
    $totals['net_pay'] += floatval($row['net_pay'] ?? 0);
}

// Payment date from payroll period
$paymentDate = '';
if (!empty($data)) {
    $paymentDate = formatDate($data[0]['end_date'] ?? '');
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'gumastadhara_salary_register_' . date('M_Y', mktime(0, 0, 0, $month, 1, $year)) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['REGISTER OF WAGES UNDER THE BOMBAY SHOPS AND ESTABLISHMENTS ACT, 1948']);
    fputcsv($output, ['Period: ' . $monthName . ' ' . $year]);
    if ($clientFilter && !empty($data)) {
        fputcsv($output, ['Client: ' . $data[0]['client_name'], 'Unit: ' . ($data[0]['unit_name'] ?? 'All')]);
    }
    fputcsv($output, []);

    $headers = [
        '#', 'Emp Code', 'Employee Name', "Father's Name", 'Designation',
        'Nature of Work', 'Date of Joining', 'Date of Leaving',
        'Total Days', 'Days Worked', 'Wages Rate (Basic)',
        'OT Hours', 'OT Amount', 'Gross Wages',
        'PF Deduction', 'ESI Deduction', 'Prof. Tax', 'Advance',
        'Total Deductions', 'Net Wages Paid', 'Payment Date'
    ];
    fputcsv($output, $headers);

    foreach ($data as $i => $r) {
        $gross = floatval($r['gross_salary'] ?? $r['gross_earnings'] ?? 0);
        $totalDed = floatval($r['pf_employee'] ?? 0) + floatval($r['esi_employee'] ?? 0)
                  + floatval($r['professional_tax'] ?? 0) + floatval($r['salary_advance'] ?? 0);

        fputcsv($output, [
            $i + 1,
            $r['employee_code'],
            $r['full_name'],
            $r['father_name'] ?? '',
            $r['designation'],
            $r['department'] ?? '',
            formatDate($r['date_of_joining'] ?? ''),
            formatDate($r['date_of_leaving'] ?? ''),
            $r['total_days'],
            $r['paid_days'],
            number_format(floatval($r['wages_rate'] ?? 0), 2),
            number_format(floatval($r['overtime_hours'] ?? 0), 2),
            number_format(floatval($r['overtime_amount'] ?? 0), 2),
            number_format($gross, 2),
            number_format(floatval($r['pf_employee'] ?? 0), 2),
            number_format(floatval($r['esi_employee'] ?? 0), 2),
            number_format(floatval($r['professional_tax'] ?? 0), 2),
            number_format(floatval($r['salary_advance'] ?? 0), 2),
            number_format(floatval($r['total_deductions'] ?? 0), 2),
            number_format(floatval($r['net_pay'] ?? 0), 2),
            $paymentDate
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, [
        '', 'TOTAL', count($data) . ' Employees', '', '', '', '', '',
        '', '', '',
        '', number_format($totals['ot_amount'], 2), number_format($totals['gross'], 2),
        number_format($totals['pf'], 2), number_format($totals['esi'], 2),
        number_format($totals['pt'], 2), number_format($totals['advance'], 2),
        number_format($totals['total_ded'], 2), number_format($totals['net_pay'], 2), ''
    ]);

    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>Gumastadhara Salary Register</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
                <button class="btn btn-outline-info" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/gumastadhara-salary-register">
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('M', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
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
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Employees</small>
                        <div class="h5 mb-0"><?php echo count($data); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Wages</small>
                        <div class="h5 mb-0 text-primary"><?php echo formatCurrency($totals['gross']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Deductions</small>
                        <div class="h5 mb-0 text-danger"><?php echo formatCurrency($totals['total_ded']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Net Paid</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($totals['net_pay']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legal Header -->
        <div class="card mb-3">
            <div class="card-body text-center py-2">
                <h5 class="mb-1 fw-bold" style="text-transform:uppercase; letter-spacing:0.5px;">
                    Register of Wages Under the Bombay Shops and Establishments Act, 1948
                </h5>
                <div class="row text-start small" style="font-size:0.8rem;">
                    <div class="col-md-4">
                        <strong>Period:</strong> <?php echo $monthName . ' ' . $year; ?>
                    </div>
                    <?php if ($clientFilter && !empty($data)): ?>
                    <div class="col-md-4">
                        <strong>Client:</strong> <?php echo sanitize($data[0]['client_name'] ?? ''); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Unit:</strong> <?php echo sanitize($data[0]['unit_name'] ?? 'All'); ?>
                        <?php if (!empty($data[0]['unit_state'])): ?>
                        | <strong>State:</strong> <?php echo sanitize($data[0]['unit_state']); ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Salary Register Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.7rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:25px;">#</th>
                                <th rowspan="2" style="width:50px;">Emp Code</th>
                                <th rowspan="2" style="min-width:100px;">Employee Name</th>
                                <th rowspan="2" style="min-width:90px;">Father's Name</th>
                                <th rowspan="2" style="min-width:70px;">Designation</th>
                                <th rowspan="2" style="min-width:70px;">Nature of Work</th>
                                <th rowspan="2" style="width:60px;">Date of Joining</th>
                                <th rowspan="2" style="width:60px;">Date of Leaving</th>
                                <th rowspan="2" class="text-center" style="width:35px;">Total Days</th>
                                <th rowspan="2" class="text-center" style="width:40px;">Days Worked</th>
                                <th rowspan="2" class="text-end" style="width:55px;">Wages Rate (Basic) &#8377;</th>
                                <th rowspan="2" class="text-center" style="width:35px;">OT Hours</th>
                                <th rowspan="2" class="text-end" style="width:45px;">OT Amt &#8377;</th>
                                <th rowspan="2" class="text-end" style="width:55px;">Gross Wages &#8377;</th>
                                <th colspan="4" class="text-center" style="background:#842029;">Deductions (&#8377;)</th>
                                <th rowspan="2" class="text-end" style="background:#842029;width:55px;">Total Ded &#8377;</th>
                                <th rowspan="2" class="text-end" style="background:#0b5ed7;width:60px;">Net Paid &#8377;</th>
                                <th rowspan="2" style="width:55px;">Payment Date</th>
                                <th rowspan="2" style="width:40px;">Signature</th>
                            </tr>
                            <tr>
                                <th class="text-end" style="background:#842029;width:45px;">PF</th>
                                <th class="text-end" style="background:#842029;width:45px;">ESI</th>
                                <th class="text-end" style="background:#842029;width:40px;">P.Tax</th>
                                <th class="text-end" style="background:#842029;width:45px;">Advance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                            <tr>
                                <td colspan="21" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-circle me-1"></i>
                                    No payroll data found for the selected period.
                                </td>
                            </tr>
                            <?php else: $i = 0;
                            foreach ($data as $row):
                                $i++;
                                $gross = floatval($row['gross_salary'] ?? $row['gross_earnings'] ?? 0);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['father_name'] ?? ''); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['designation']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['department'] ?? ''); ?></td>
                                <td class="text-center"><?php echo formatDate($row['date_of_joining'] ?? ''); ?></td>
                                <td class="text-center"><?php echo formatDate($row['date_of_leaving'] ?? ''); ?></td>
                                <td class="text-center"><?php echo $row['total_days']; ?></td>
                                <td class="text-center fw-bold"><?php echo $row['paid_days']; ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['wages_rate'] ?? 0), 0); ?></td>
                                <td class="text-center"><?php echo number_format(floatval($row['overtime_hours'] ?? 0), 1); ?></td>
                                <td class="text-end"><?php echo number_format(floatval($row['overtime_amount'] ?? 0), 0); ?></td>
                                <td class="text-end fw-bold" style="background:#e8f5e9;"><?php echo number_format($gross, 0); ?></td>
                                <td class="text-end" style="background:#fde8e8;"><?php echo number_format(floatval($row['pf_employee'] ?? 0), 0); ?></td>
                                <td class="text-end" style="background:#fde8e8;"><?php echo number_format(floatval($row['esi_employee'] ?? 0), 0); ?></td>
                                <td class="text-end" style="background:#fde8e8;"><?php echo number_format(floatval($row['professional_tax'] ?? 0), 0); ?></td>
                                <td class="text-end" style="background:#fde8e8;"><?php echo number_format(floatval($row['salary_advance'] ?? 0), 0); ?></td>
                                <td class="text-end fw-bold" style="background:#fde8e8;"><?php echo number_format(floatval($row['total_deductions'] ?? 0), 0); ?></td>
                                <td class="text-end fw-bold" style="background:#bbdefb;"><?php echo number_format(floatval($row['net_pay'] ?? 0), 0); ?></td>
                                <td class="text-center small"><?php echo $paymentDate; ?></td>
                                <td></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($data)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="2"><strong>TOTAL</strong></td>
                                <td colspan="8"><strong><?php echo count($data); ?> Employees</strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['wages'], 0); ?></strong></td>
                                <td></td>
                                <td class="text-end"><strong><?php echo number_format($totals['ot_amount'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['gross'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['pf'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['esi'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['pt'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['advance'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['total_ded'], 0); ?></strong></td>
                                <td class="text-end"><strong><?php echo number_format($totals['net_pay'], 0); ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Employer Declaration -->
        <?php if (!empty($data)): ?>
        <div class="card mt-3">
            <div class="card-body py-3">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1 small"><strong>Certification:</strong></p>
                        <p class="small text-muted mb-0">
                            I hereby certify that the above register of wages is correct and complete
                            in all respects for the period of <?php echo $monthName . ' ' . $year; ?>.
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-1"><strong>Signature of Employer / Authorized Signatory</strong></p>
                        <p class="text-muted small mb-0">Name: _______________________________</p>
                        <p class="text-muted small mb-0">Designation: ___________________________</p>
                        <p class="text-muted small mb-0">Date: ________________________________</p>
                        <p class="text-muted small mb-0">Seal / Stamp: ________________________</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header .btn { display: none !important; }
    body { font-size: 9pt; }
    .table { font-size: 7pt; }
    .table td, .table th { padding: 1px 3px !important; }
    .card { border: 1px solid #000 !important; }
    .card-body { padding: 6px !important; }
    @page {
        size: A3 landscape;
        margin: 10mm;
    }
}
</style>
