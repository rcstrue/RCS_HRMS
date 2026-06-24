<?php
/**
 * RCS HRMS Pro - Gumastadhara Overtime Register (Form 4)
 * Register of Overtime Work under the Bombay Shops and Establishments Act, 1948
 * As required by Maharashtra Gumastadhara Inspector
 */

$pageTitle = 'Gumastadhara Overtime Register - Form 4';

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);

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

// Build query for overtime data from payroll
$where = "pp.month = :month AND pp.year = :year AND (p.overtime_hours > 0 OR p.overtime_amount > 0)";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}
if ($unitFilter) {
    $where .= " AND e.unit_id = :uid";
    $params[':uid'] = $unitFilter;
}

// Main payroll OT summary
$sql = "SELECT
            e.employee_code, e.full_name, e.designation,
            c.name AS client_name, u.name AS unit_name,
            ess.basic_da AS basic_wages, ess.gross_salary AS monthly_wages,
            p.overtime_hours, p.overtime_amount,
            p.paid_days, p.total_days,
            pp.start_date, pp.end_date
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

// Try to get daily OT detail if table exists
$dailyOT = [];
try {
    $dailyStmt = $db->prepare(
        "SELECT da.employee_id, DATE(da.att_date) AS ot_date, da.overtime_hours AS daily_ot_hours
         FROM daily_attendance da
         JOIN employees e ON da.employee_id = e.id
         JOIN payroll p ON p.employee_id = e.employee_code
         JOIN payroll_periods pp ON p.payroll_period_id = pp.id
         WHERE pp.month = :month AND pp.year = :year
           AND da.overtime_hours > 0"
    );
    $dailyStmt->execute([':month' => $month, ':year' => $year]);
    $allDailyOT = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by employee
    foreach ($allDailyOT as $d) {
        $empId = $d['employee_id'];
        if (!isset($dailyOT[$empId])) {
            $dailyOT[$empId] = [];
        }
        $dailyOT[$empId][] = $d;
    }
} catch (Exception $e) {
    // daily_attendance table may not have overtime_hours column
}

// Get employee id to code mapping for daily OT lookup
$empIdMap = [];
foreach ($data as $row) {
    // We need to find employee id from the code
    $empIdMap[] = $row['employee_code'];
}

// Try to get employee IDs from codes
$codeToId = [];
if (!empty($empIdMap)) {
    try {
        $placeholders = implode(',', array_fill(0, count($empIdMap), '?'));
        $idRows = $db->fetchAll(
            "SELECT id, employee_code FROM employees WHERE employee_code IN ($placeholders)",
            $empIdMap
        );
        foreach ($idRows as $ir) {
            $codeToId[$ir['employee_code']] = $ir['id'];
        }
    } catch (Exception $e) {}
}

// Calculate OT rate per hour (standard: Basic/Total_Days * 2 for double OT)
// As per Bombay S&E Act, OT is paid at double the ordinary rate
function calculateOTRate($basicWages, $totalDays) {
    if ($totalDays <= 0 || $basicWages <= 0) return 0;
    $dailyRate = $basicWages / $totalDays;
    $hourlyRate = $dailyRate / 9; // 9 hours standard working day
    return round($hourlyRate * 2, 2); // Double rate
}

// Calculate totals
$totalOTHours = 0;
$totalOTAmount = 0;

foreach ($data as $row) {
    $totalOTHours += floatval($row['overtime_hours'] ?? 0);
    $totalOTAmount += floatval($row['overtime_amount'] ?? 0);
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'gumastadhara_ot_register_' . date('M_Y', mktime(0, 0, 0, $month, 1, $year)) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['REGISTER OF OVERTIME WORK UNDER THE BOMBAY SHOPS AND ESTABLISHMENTS ACT, 1948 (FORM 4)']);
    fputcsv($output, ['Period: ' . $monthName . ' ' . $year]);
    fputcsv($output, []);

    $headers = [
        '#', 'Emp Code', 'Employee Name', 'Designation',
        'Date(s) of OT', 'OT From Time', 'OT To Time',
        'Total OT Hours', 'Rate of OT per Hour (Rs.)', 'OT Amount (Rs.)', 'Authorized By'
    ];
    fputcsv($output, $headers);

    foreach ($data as $i => $r) {
        $otRate = calculateOTRate(floatval($r['basic_wages'] ?? 0), intval($r['total_days'] ?? 26));

        // Build OT dates string
        $empId = $codeToId[$r['employee_code']] ?? null;
        $otDatesStr = '';
        if ($empId && isset($dailyOT[$empId])) {
            $dates = [];
            foreach ($dailyOT[$empId] as $dot) {
                $dates[] = formatDate($dot['ot_date']) . ' (' . number_format(floatval($dot['daily_ot_hours']), 1) . 'h)';
            }
            $otDatesStr = implode(', ', $dates);
        } else {
            $otDatesStr = $monthName . ' ' . $year . ' (Monthly)';
        }

        fputcsv($output, [
            $i + 1,
            $r['employee_code'],
            $r['full_name'],
            $r['designation'],
            $otDatesStr,
            '', // OT From Time - filled manually or from daily
            '', // OT To Time
            number_format(floatval($r['overtime_hours'] ?? 0), 2),
            number_format($otRate, 2),
            number_format(floatval($r['overtime_amount'] ?? 0), 2),
            '' // Authorized By
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, [
        '', 'TOTAL', count($data) . ' Employees', '', '', '', '',
        number_format($totalOTHours, 2), '', number_format($totalOTAmount, 2), ''
    ]);

    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Overtime Register (Form 4)</h4>
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
                    <input type="hidden" name="page" value="report/gumastadhara-ot-register">
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
                        <small class="text-muted">Employees with OT</small>
                        <div class="h5 mb-0"><?php echo count($data); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total OT Hours</small>
                        <div class="h5 mb-0 text-primary"><?php echo number_format($totalOTHours, 1); ?> hrs</div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total OT Amount</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($totalOTAmount); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legal Header -->
        <div class="card mb-3">
            <div class="card-body text-center py-2">
                <h5 class="mb-1 fw-bold" style="text-transform:uppercase; letter-spacing:0.5px;">
                    Register of Overtime Work Under the Bombay Shops and Establishments Act, 1948 (Form 4)
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
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- OT Register Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:30px;">#</th>
                                <th rowspan="2" style="width:50px;">Emp Code</th>
                                <th rowspan="2" style="min-width:100px;">Employee Name</th>
                                <th rowspan="2" style="min-width:80px;">Designation</th>
                                <th rowspan="2" style="min-width:200px;">Date(s) of Overtime</th>
                                <th rowspan="2" style="width:65px;">OT From Time</th>
                                <th rowspan="2" style="width:65px;">OT To Time</th>
                                <th rowspan="2" class="text-center" style="width:55px;">Total OT Hours</th>
                                <th rowspan="2" class="text-end" style="width:70px;">Rate per Hour (&#8377;)</th>
                                <th rowspan="2" class="text-end" style="width:65px;">OT Amount (&#8377;)</th>
                                <th rowspan="2" style="width:80px;">Authorized By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-circle me-1"></i>
                                    No overtime data found for the selected period.
                                </td>
                            </tr>
                            <?php else: $i = 0;
                            foreach ($data as $row):
                                $i++;
                                $otRate = calculateOTRate(
                                    floatval($row['basic_wages'] ?? 0),
                                    intval($row['total_days'] ?? 26)
                                );

                                // Build OT dates detail
                                $empId = $codeToId[$row['employee_code']] ?? null;
                                $hasDailyDetail = ($empId && isset($dailyOT[$empId]) && count($dailyOT[$empId]) > 0);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($row['designation']); ?></td>
                                <td>
                                    <?php if ($hasDailyDetail): ?>
                                    <?php foreach ($dailyOT[$empId] as $dot): ?>
                                    <span class="badge bg-light border text-dark me-1 mb-1" style="font-size:0.65rem;">
                                        <?php echo formatDate($dot['ot_date']); ?>
                                        <strong><?php echo number_format(floatval($dot['daily_ot_hours']), 1); ?>h</strong>
                                    </span>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <span class="text-muted small"><?php echo $monthName . ' ' . $year; ?> (Monthly)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center fw-bold" style="background:#e8f5e9;">
                                    <?php echo number_format(floatval($row['overtime_hours'] ?? 0), 1); ?>
                                </td>
                                <td class="text-end text-muted">
                                    <?php echo $otRate > 0 ? number_format($otRate, 2) : '—'; ?>
                                </td>
                                <td class="text-end fw-bold" style="background:#bbdefb;">
                                    <?php echo number_format(floatval($row['overtime_amount'] ?? 0), 0); ?>
                                </td>
                                <td></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($data)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="7" class="text-end">
                                    <strong>TOTAL (<?php echo count($data); ?> Employees)</strong>
                                </td>
                                <td class="text-center">
                                    <strong><?php echo number_format($totalOTHours, 1); ?></strong>
                                </td>
                                <td></td>
                                <td class="text-end">
                                    <strong><?php echo formatCurrency($totalOTAmount); ?></strong>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Remarks Section -->
        <?php if (!empty($data)): ?>
        <div class="card mt-3">
            <div class="card-body py-2">
                <div class="row small">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Remarks:</strong></p>
                        <ul class="mb-0 small text-muted">
                            <li>As per Section 30 of the Bombay Shops & Establishments Act, 1948, overtime is paid at double the ordinary rate of wages.</li>
                            <li>No employee shall be required or allowed to work overtime for more than 125 hours in any quarter.</li>
                            <li>OT hours shown are as per payroll records. For daily OT breakdown, refer to daily attendance registers.</li>
                        </ul>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-1"><strong>Certification</strong></p>
                        <p class="small text-muted mb-0">
                            Certified that the overtime work recorded above was necessary and authorized.
                        </p>
                        <div style="border-bottom:1px solid #000; width:200px; margin-left:auto; height:30px; margin-top:8px;"></div>
                        <p class="small text-muted mb-0 mt-1">Signature of Employer: ________________</p>
                        <p class="small text-muted mb-0">Date: ________________</p>
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
    .table { font-size: 7.5pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: 1px solid #000 !important; page-break-inside: avoid; }
    .badge { border: 1px solid #666 !important; }
}
</style>
