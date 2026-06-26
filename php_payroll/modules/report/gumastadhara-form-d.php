<?php
/**
 * RCS HRMS Pro - Gumastadhara Form D - Leave with Wages Register
 * Register of Leave with Wages (Form D) under the Bombay Shops and Establishments Act, 1948
 * As required by Maharashtra Gumastadhara Inspector
 */

$pageTitle = 'Gumastadhara Form D - Leave with Wages Register';

$month = (int)($_GET['month'] ?? 0); // 0 = full year
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);

$monthName = $month > 0 ? date('F', mktime(0, 0, 0, $month, 1, $year)) : 'Full Year';
$displayPeriod = $month > 0 ? $monthName . ' ' . $year : 'Year ' . $year;

// Try to ensure leave_applications table reference exists
// (This is a reference - actual table structure depends on the application)
// Expected columns: id, employee_id, leave_type, start_date, end_date, total_days, status, wages_paid

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

// Fetch leave applications data
$leaveData = [];
try {
    $where = "YEAR(la.start_date) = :year AND la.status = 'approved'";
    $params = [':year' => $year];

    if ($month > 0) {
        $where .= " AND MONTH(la.start_date) = :month";
        $params[':month'] = $month;
    }
    if ($clientFilter) {
        $where .= " AND e.client_id = :cid";
        $params[':cid'] = $clientFilter;
    }
    if ($unitFilter) {
        $where .= " AND e.unit_id = :uid";
        $params[':uid'] = $unitFilter;
    }

    $stmt = $db->prepare(
        "SELECT la.id, la.employee_id, la.leave_type, la.start_date, la.end_date,
                la.total_days AS leave_days, la.status, la.wages_paid,
                e.employee_code, e.full_name, e.designation,
                c.name AS client_name, u.name AS unit_name,
                ats.total_paid_days, ats.total_present, ats.total_wo, ats.month, ats.year
         FROM leave_applications la
         JOIN employees e ON la.employee_id = e.id
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN attendance_summary ats ON ats.employee_id = e.id
             AND ats.month = MONTH(la.start_date) AND ats.year = YEAR(la.start_date)
         WHERE $where
         ORDER BY e.employee_code, la.start_date"
    );
    $stmt->execute($params);
    $leaveData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // leave_applications table may not exist
    $leaveData = [];
}

// Group leave data by employee
$employeeLeaves = [];
$uniqueEmployees = [];

foreach ($leaveData as $ld) {
    $empId = $ld['employee_id'];
    $leaveMonth = (int)date('n', strtotime($ld['start_date']));

    if (!isset($employeeLeaves[$empId])) {
        $employeeLeaves[$empId] = [
            'employee_code' => $ld['employee_code'],
            'full_name' => $ld['full_name'],
            'designation' => $ld['designation'],
            'client_name' => $ld['client_name'] ?? '',
            'unit_name' => $ld['unit_name'] ?? '',
            'total_paid_days' => $ld['total_paid_days'] ?? 0,
            'total_present' => $ld['total_present'] ?? 0,
            'total_wo' => $ld['total_wo'] ?? 0,
            'leave_month' => $leaveMonth,
            'CL' => 0, 'PL' => 0, 'SL' => 0, 'EL' => 0, 'CO' => 0, 'OTHER' => 0,
            'total_leave_days' => 0,
            'leave_applications' => [],
            'wages_paid' => 0
        ];
    }

    $leaveType = strtoupper($ld['leave_type'] ?? 'OTHER');
    $days = floatval($ld['leave_days'] ?? 0);

    // Map leave types
    if (in_array($leaveType, ['CL', 'CASUAL_LEAVE'])) {
        $employeeLeaves[$empId]['CL'] += $days;
    } elseif (in_array($leaveType, ['PL', 'PLANNED_LEAVE', 'AL', 'ANNUAL_LEAVE'])) {
        $employeeLeaves[$empId]['PL'] += $days;
    } elseif (in_array($leaveType, ['SL', 'SICK_LEAVE'])) {
        $employeeLeaves[$empId]['SL'] += $days;
    } elseif (in_array($leaveType, ['EL', 'EARNED_LEAVE', 'EL_LEAVE'])) {
        $employeeLeaves[$empId]['EL'] += $days;
    } elseif (in_array($leaveType, ['CO', 'COMP_OFF', 'COMPENSATORY_OFF'])) {
        $employeeLeaves[$empId]['CO'] += $days;
    } else {
        $employeeLeaves[$empId]['OTHER'] += $days;
    }

    $employeeLeaves[$empId]['total_leave_days'] += $days;
    $employeeLeaves[$empId]['wages_paid'] += floatval($ld['wages_paid'] ?? 0);
    $employeeLeaves[$empId]['leave_applications'][] = $ld;

    $uniqueEmployees[$empId] = true;
}

// Monthly summary for annual view
$monthlySummary = [];
foreach ($employeeLeaves as $empId => $emp) {
    $m = $emp['leave_month'];
    if (!isset($monthlySummary[$m])) {
        $monthlySummary[$m] = [
            'employees' => 0, 'CL' => 0, 'PL' => 0, 'SL' => 0, 'EL' => 0, 'CO' => 0,
            'total_days' => 0, 'wages' => 0
        ];
    }
    $monthlySummary[$m]['employees']++;
    $monthlySummary[$m]['CL'] += $emp['CL'];
    $monthlySummary[$m]['PL'] += $emp['PL'];
    $monthlySummary[$m]['SL'] += $emp['SL'];
    $monthlySummary[$m]['EL'] += $emp['EL'];
    $monthlySummary[$m]['CO'] += $emp['CO'];
    $monthlySummary[$m]['total_days'] += $emp['total_leave_days'];
    $monthlySummary[$m]['wages'] += $emp['wages_paid'];
}

// Grand totals
$grandTotals = ['CL' => 0, 'PL' => 0, 'SL' => 0, 'EL' => 0, 'CO' => 0, 'OTHER' => 0, 'total_days' => 0, 'wages' => 0];
foreach ($employeeLeaves as $emp) {
    $grandTotals['CL'] += $emp['CL'];
    $grandTotals['PL'] += $emp['PL'];
    $grandTotals['SL'] += $emp['SL'];
    $grandTotals['EL'] += $emp['EL'];
    $grandTotals['CO'] += $emp['CO'];
    $grandTotals['OTHER'] += $emp['OTHER'];
    $grandTotals['total_days'] += $emp['total_leave_days'];
    $grandTotals['wages'] += $emp['wages_paid'];
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'gumastadhara_form_d_' . ($month > 0 ? date('M', mktime(0,0,0,$month,1)) . '_' : '') . $year . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['REGISTER OF LEAVE WITH WAGES (FORM D) UNDER THE BOMBAY SHOPS AND ESTABLISHMENTS ACT, 1948']);
    fputcsv($output, ['Period: ' . $displayPeriod]);
    fputcsv($output, []);

    $headers = [
        '#', 'Emp Code', 'Employee Name', 'Designation',
        'Total Working Days in Month', 'Casual Leave', 'Planned Leave',
        'Sick Leave', 'Earned Leave', 'Comp Off', 'Total Leave Days',
        'Wages for Leave Period (Rs.)', 'Date of Return', 'Signature'
    ];
    fputcsv($output, $headers);

    $i = 0;
    foreach ($employeeLeaves as $empId => $emp) {
        $i++;
        // Get return date (end_date of last leave application + 1 day)
        $returnDate = '';
        if (!empty($emp['leave_applications'])) {
            $lastApp = end($emp['leave_applications']);
            $returnDate = date('d-m-Y', strtotime($lastApp['end_date'] . ' +1 day'));
        }

        fputcsv($output, [
            $i,
            $emp['employee_code'],
            $emp['full_name'],
            $emp['designation'],
            $emp['total_present'] ?? '',
            $emp['CL'] > 0 ? $emp['CL'] : '',
            $emp['PL'] > 0 ? $emp['PL'] : '',
            $emp['SL'] > 0 ? $emp['SL'] : '',
            $emp['EL'] > 0 ? $emp['EL'] : '',
            $emp['CO'] > 0 ? $emp['CO'] : '',
            $emp['total_leave_days'],
            number_format($emp['wages_paid'], 2),
            $returnDate,
            ''
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, [
        '', 'ANNUAL TOTAL', count($employeeLeaves) . ' Employees', '',
        '', $grandTotals['CL'], $grandTotals['PL'], $grandTotals['SL'],
        $grandTotals['EL'], $grandTotals['CO'], $grandTotals['total_days'],
        number_format($grandTotals['wages'], 2), '', ''
    ]);

    // Monthly breakdown
    fputcsv($output, []);
    fputcsv($output, ['MONTHLY BREAKDOWN - ' . $year]);
    fputcsv($output, ['Month', 'Employees', 'CL', 'PL', 'SL', 'EL', 'Comp Off', 'Total Days', 'Wages']);
    for ($m = 1; $m <= 12; $m++) {
        if (isset($monthlySummary[$m])) {
            $ms = $monthlySummary[$m];
            fputcsv($output, [
                date('F', mktime(0,0,0,$m,1)), $ms['employees'], $ms['CL'], $ms['PL'],
                $ms['SL'], $ms['EL'], $ms['CO'], $ms['total_days'],
                number_format($ms['wages'], 2)
            ]);
        }
    }

    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Form D - Leave with Wages Register</h4>
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
                    <input type="hidden" name="page" value="report/gumastadhara-form-d">
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
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="0" <?php echo $month === 0 ? 'selected' : ''; ?>>Full Year</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
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
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Employees on Leave</small>
                        <div class="h5 mb-0"><?php echo count($employeeLeaves); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Leave Days</small>
                        <div class="h5 mb-0 text-primary"><?php echo number_format($grandTotals['total_days'], 1); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Wages Paid for Leave</small>
                        <div class="h5 mb-0 text-success"><?php echo formatCurrency($grandTotals['wages']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">CL / PL / SL / EL</small>
                        <div class="h5 mb-0 text-info">
                            <?php echo $grandTotals['CL']; ?> / <?php echo $grandTotals['PL']; ?> / <?php echo $grandTotals['SL']; ?> / <?php echo $grandTotals['EL']; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legal Header -->
        <div class="card mb-3">
            <div class="card-body text-center py-2">
                <h5 class="mb-1 fw-bold" style="text-transform:uppercase; letter-spacing:0.5px;">
                    Register of Leave with Wages (Form D) Under the Bombay Shops and Establishments Act, 1948
                </h5>
                <div class="row text-start small" style="font-size:0.8rem;">
                    <div class="col-md-3">
                        <strong>Period:</strong> <?php echo $displayPeriod; ?>
                    </div>
                    <?php if ($clientFilter && !empty($leaveData)): ?>
                    <div class="col-md-3">
                        <strong>Client:</strong> <?php echo sanitize($leaveData[0]['client_name'] ?? ''); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Unit:</strong> <?php echo sanitize($leaveData[0]['unit_name'] ?? 'All'); ?>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3 text-end">
                        <strong>Total Employees:</strong> <?php echo count($employeeLeaves); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Leave Register Table -->
        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:25px;">#</th>
                                <th rowspan="2" style="width:50px;">Emp Code</th>
                                <th rowspan="2" style="min-width:100px;">Employee Name</th>
                                <th rowspan="2" style="min-width:80px;">Designation</th>
                                <th rowspan="2" class="text-center" style="width:50px;">Total Working Days</th>
                                <th colspan="5" class="text-center" style="background:#6f42c1;">Leave Taken (Days)</th>
                                <th rowspan="2" class="text-center" style="width:45px;">Total Leave</th>
                                <th rowspan="2" class="text-end" style="width:70px;">Wages for Leave (&#8377;)</th>
                                <th rowspan="2" style="width:70px;">Date of Return</th>
                                <th rowspan="2" style="width:50px;">Signature</th>
                            </tr>
                            <tr>
                                <th class="text-center" style="background:#6f42c1;width:35px;">CL</th>
                                <th class="text-center" style="background:#6f42c1;width:35px;">PL</th>
                                <th class="text-center" style="background:#6f42c1;width:35px;">SL</th>
                                <th class="text-center" style="background:#6f42c1;width:35px;">EL</th>
                                <th class="text-center" style="background:#6f42c1;width:35px;">Comp Off</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employeeLeaves)): ?>
                            <tr>
                                <td colspan="13" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-circle me-1"></i>
                                    No leave data found for the selected period.
                                    <?php if (empty($leaveData) && !isset($leaveAppException)): ?>
                                    <br><small>Ensure leave_applications table exists with columns: id, employee_id, leave_type, start_date, end_date, total_days, status, wages_paid</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php else: $i = 0;
                            foreach ($employeeLeaves as $empId => $emp):
                                $i++;
                                // Calculate return date from last leave application
                                $returnDate = '';
                                if (!empty($emp['leave_applications'])) {
                                    $lastApp = end($emp['leave_applications']);
                                    $returnDate = date('d-m-Y', strtotime($lastApp['end_date'] . ' +1 day'));
                                }
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $i; ?></td>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <td class="text-center">
                                    <?php echo $emp['total_present'] ?: ($emp['total_paid_days'] ?? '—'); ?>
                                </td>
                                <td class="text-center" style="background:#f3e5f5;">
                                    <?php echo $emp['CL'] > 0 ? $emp['CL'] : '—'; ?>
                                </td>
                                <td class="text-center" style="background:#f3e5f5;">
                                    <?php echo $emp['PL'] > 0 ? $emp['PL'] : '—'; ?>
                                </td>
                                <td class="text-center" style="background:#f3e5f5;">
                                    <?php echo $emp['SL'] > 0 ? $emp['SL'] : '—'; ?>
                                </td>
                                <td class="text-center" style="background:#f3e5f5;">
                                    <?php echo $emp['EL'] > 0 ? $emp['EL'] : '—'; ?>
                                </td>
                                <td class="text-center" style="background:#f3e5f5;">
                                    <?php echo $emp['CO'] > 0 ? $emp['CO'] : '—'; ?>
                                </td>
                                <td class="text-center fw-bold" style="background:#e8eaf6;">
                                    <?php echo $emp['total_leave_days']; ?>
                                </td>
                                <td class="text-end" style="background:#e8f5e9;">
                                    <?php echo $emp['wages_paid'] > 0 ? number_format($emp['wages_paid'], 0) : '—'; ?>
                                </td>
                                <td class="text-center small"><?php echo $returnDate; ?></td>
                                <td></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($employeeLeaves)): ?>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="4">
                                    <strong>ANNUAL TOTAL (<?php echo count($employeeLeaves); ?> Employees)</strong>
                                </td>
                                <td></td>
                                <td class="text-center"><strong><?php echo $grandTotals['CL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['PL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['SL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['EL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['CO']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['total_days']; ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['wages']); ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Breakdown (shown in full year mode) -->
        <?php if ($month === 0 && !empty($monthlySummary)): ?>
        <div class="card mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="bi bi-bar-chart me-1"></i>Monthly Breakdown - <?php echo $year; ?></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Month</th>
                                <th class="text-center">Employees</th>
                                <th class="text-center">CL</th>
                                <th class="text-center">PL</th>
                                <th class="text-center">SL</th>
                                <th class="text-center">EL</th>
                                <th class="text-center">Comp Off</th>
                                <th class="text-center">Total Days</th>
                                <th class="text-end">Wages (&#8377;)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($m = 1; $m <= 12; $m++):
                                if (!isset($monthlySummary[$m])) continue;
                                $ms = $monthlySummary[$m];
                            ?>
                            <tr>
                                <td><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></td>
                                <td class="text-center"><?php echo $ms['employees']; ?></td>
                                <td class="text-center"><?php echo $ms['CL']; ?></td>
                                <td class="text-center"><?php echo $ms['PL']; ?></td>
                                <td class="text-center"><?php echo $ms['SL']; ?></td>
                                <td class="text-center"><?php echo $ms['EL']; ?></td>
                                <td class="text-center"><?php echo $ms['CO']; ?></td>
                                <td class="text-center fw-bold"><?php echo $ms['total_days']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($ms['wages']); ?></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td><strong>Year Total</strong></td>
                                <td class="text-center"><strong><?php echo count($employeeLeaves); ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['CL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['PL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['SL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['EL']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['CO']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $grandTotals['total_days']; ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotals['wages']); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Leave Entitlement Summary (Legal Reference) -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1 small"><strong>Leave Entitlement Reference (Bombay S&E Act, 1948):</strong></p>
                        <ul class="mb-0 small text-muted" style="font-size:0.75rem;">
                            <li><strong>Casual Leave:</strong> As per establishment rules</li>
                            <li><strong>Sick Leave:</strong> As per establishment rules</li>
                            <li><strong>Leave with Wages:</strong> 5 days for every 60 days of work performed (Section 36)</li>
                            <li>Leave can be accumulated up to 30 days in the 3rd and subsequent years</li>
                            <li>Leave wages = Ordinary rate of wages (daily wage calculation)</li>
                            <li>Carry forward allowed as per Section 36 of the Act</li>
                        </ul>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-1 small"><strong>Certification</strong></p>
                        <p class="small text-muted mb-0">
                            I certify that the above leave register is correct and complete
                            for the period <?php echo $displayPeriod; ?>.
                        </p>
                        <div style="border-bottom:1px solid #000; width:200px; margin-left:auto; height:40px; margin-top:8px;"></div>
                        <p class="small text-muted mb-0 mt-1">Signature of Employer: ________________</p>
                        <p class="small text-muted mb-0">Date: ________________ Seal: ________________</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header .btn { display: none !important; }
    body { font-size: 9pt; }
    .table { font-size: 7.5pt; }
    .table td, .table th { padding: 2px 4px !important; }
    .card { border: 1px solid #000 !important; page-break-inside: avoid; }
    .card-body { padding: 6px !important; }
}
</style>
