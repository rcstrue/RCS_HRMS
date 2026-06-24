<?php
/**
 * RCS HRMS Pro - Gumastadhara Muster Roll
 * Muster Roll under the Bombay Shops and Establishments Act, 1948
 * As required by Maharashtra Gumastadhara Inspector
 */

$pageTitle = 'Gumastadhara Muster Roll';

// Ensure daily_data column exists in attendance_summary
try {
    $db->query("SELECT daily_data FROM attendance_summary LIMIT 1");
} catch (Exception $e) {
    $db->exec("ALTER TABLE attendance_summary ADD COLUMN daily_data LONGTEXT DEFAULT NULL AFTER total_paid_days");
}

$month = (int)($_GET['month'] ?? prev_month_num());
$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$mode = sanitize($_GET['mode'] ?? 'filled'); // filled or blank

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

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

// Get holidays for the month
$holidays = [];
try {
    $holidayRows = $db->fetchAll(
        "SELECT holiday_date, holiday_name FROM holidays WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ?",
        [$month, $year]
    );
    foreach ($holidayRows as $h) {
        $holidays[(int)date('j', strtotime($h['holiday_date']))] = $h['holiday_name'];
    }
} catch (Exception $e) {
    $holidays = [];
}

// Fetch attendance data for filled mode
$attendanceData = [];
if ($mode === 'filled') {
    try {
        $where = "e.status IN ('approved', 'active')";
        $params = [$month, $year];
        if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
        if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

        $attendanceData = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.designation,
                    c.name AS client_name, u.name AS unit_name,
                    ats.total_present, ats.total_wo, ats.total_extra, ats.overtime_hours,
                    ats.total_paid_days, ats.daily_data
             FROM employees e
             LEFT JOIN clients c ON e.client_id = c.id
             LEFT JOIN units u ON e.unit_id = u.id
             LEFT JOIN attendance_summary ats ON ats.employee_id = e.id AND ats.month = ? AND ats.year = ?
             WHERE $where
             ORDER BY c.name, u.name, e.employee_code",
            $params
        );
    } catch (Exception $e) {
        $attendanceData = [];
    }
}

// Daily attendance helper
function getDailyAttendance($dailyData, $day, $db, $empId, $month, $year) {
    if ($dailyData) {
        $decoded = json_decode($dailyData, true);
        if (is_array($decoded) && isset($decoded[$day])) {
            return $decoded[$day];
        }
    }
    try {
        $rec = $db->fetch(
            "SELECT status FROM daily_attendance WHERE employee_id = ? AND DATE(att_date) = ?",
            [$empId, sprintf('%04d-%02d-%02d', $year, $month, $day)]
        );
        if ($rec) return $rec['status'];
    } catch (Exception $e) {}
    return '';
}

// Status label helper
function getStatusLabel($status, $day, $holidays) {
    if (isset($holidays[$day])) return 'H';
    if (empty($status)) return '';
    return strtoupper($status);
}

function getStatusColor($status, $day, $holidays) {
    if (isset($holidays[$day])) return 'background:#fff3cd;'; // Holiday - yellow
    switch (strtoupper($status)) {
        case 'P': case 'PR': return 'background:#c8e6c9;'; // Present - green
        case 'WO': return 'background:#e3f2fd;';           // Weekly Off - blue
        case 'A': return 'background:#f8d7da;';            // Absent - red
        case 'HD': return 'background:#d1ecf1;';           // Half Day - cyan
        case 'EL': case 'CL': case 'PL': case 'SL': return 'background:#e2d5f1;'; // Leave - purple
        default: return '';
    }
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="gumastadhara_muster_roll_' . $monthName . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['MUSTER ROLL UNDER THE BOMBAY SHOPS AND ESTABLISHMENTS ACT, 1948']);
    fputcsv($output, ['Period: ' . $monthName . ' ' . $year]);

    $header = ['#', 'Emp Code', 'Name', 'Designation'];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $header[] = $d . ' ' . date('D', mktime(0, 0, 0, $month, $d, $year));
    }
    $header[] = 'Total Present';
    $header[] = 'Weekly Off';
    $header[] = 'Paid Days';
    fputcsv($output, $header);

    foreach ($attendanceData as $i => $r) {
        $row = [$i + 1, $r['employee_code'], $r['full_name'], $r['designation']];
        $presentCount = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $status = getStatusLabel(
                getDailyAttendance($r['daily_data'] ?? null, $d, $db, $r['id'], $month, $year),
                $d, $holidays
            );
            $row[] = $status;
            if (strtoupper($status) === 'P' || strtoupper($status) === 'PR') $presentCount++;
        }
        $row[] = $r['total_present'] ?? $presentCount;
        $row[] = $r['total_wo'] ?? 0;
        $row[] = $r['total_paid_days'] ?? 0;
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Gumastadhara Muster Roll</h4>
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
                    <input type="hidden" name="page" value="report/gumastadhara-muster-roll">
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
                    <div class="col-md-2">
                        <label class="form-label small">Mode</label>
                        <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="filled" <?php echo $mode === 'filled' ? 'selected' : ''; ?>>Filled (Data)</option>
                            <option value="blank" <?php echo $mode === 'blank' ? 'selected' : ''; ?>>Blank (Manual)</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Legal Header -->
        <div class="card mb-3">
            <div class="card-body text-center py-2">
                <h5 class="mb-1 fw-bold" style="text-transform:uppercase; letter-spacing:0.5px;">
                    Muster Roll Under the Bombay Shops and Establishments Act, 1948
                </h5>
                <div class="row text-start small" style="font-size:0.8rem;">
                    <div class="col-md-4">
                        <strong>Period:</strong> <?php echo $monthName . ' ' . $year; ?>
                        (<?php echo $daysInMonth; ?> Days)
                    </div>
                    <?php if ($clientFilter && !empty($attendanceData)): ?>
                    <div class="col-md-4">
                        <strong>Client:</strong> <?php echo sanitize($attendanceData[0]['client_name'] ?? ''); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Unit:</strong> <?php echo sanitize($attendanceData[0]['unit_name'] ?? 'All'); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="card mb-2">
            <div class="card-body py-1 px-3">
                <small class="text-muted">
                    <strong>Legend:</strong>
                    <span class="badge bg-success-subtle text-success border">P</span> Present
                    <span class="badge bg-danger-subtle text-danger border">A</span> Absent
                    <span class="badge bg-warning-subtle text-warning border">WO</span> Weekly Off
                    <span class="badge bg-warning-subtle text-dark border">H</span> Holiday
                    <span class="badge bg-info-subtle text-info border">HD</span> Half Day
                    <span class="badge bg-primary-subtle text-primary border">CL/PL/SL</span> Leave
                    <span class="text-danger fw-bold ms-2">Red Header</span> = Sunday
                </small>
            </div>
        </div>

        <!-- Muster Roll Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0" style="font-size:0.6rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" style="width:18px;">#</th>
                                <th rowspan="2" style="width:40px;">Emp Code</th>
                                <th rowspan="2" style="min-width:90px;">Name</th>
                                <th rowspan="2" style="min-width:55px;">Designation</th>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                                    $isSunday = ($dow === 7);
                                    $isHoliday = isset($holidays[$d]);
                                    $color = '';
                                    if ($isSunday) $color = 'background:#dc3545; color:#fff;';
                                    elseif ($isHoliday) $color = 'background:#ffc107; color:#000;';
                                ?>
                                <th class="text-center" style="min-width:16px;<?php echo $color; ?>"
                                    title="<?php echo date('l j F Y', mktime(0, 0, 0, $month, $d, $year)); ?><?php echo $isHoliday ? ' - ' . $holidays[$d] : ''; ?>">
                                    <?php echo $d; ?>
                                </th>
                                <?php endfor; ?>
                                <th rowspan="2" class="text-center" style="background:#198754;min-width:22px;">P</th>
                                <th rowspan="2" class="text-center" style="background:#fd7e14;min-width:20px;">W/O</th>
                                <th rowspan="2" class="text-center" style="background:#6f42c1;min-width:22px;">PD</th>
                            </tr>
                            <tr>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                                    $isSunday = ($dow === 7);
                                    $isHoliday = isset($holidays[$d]);
                                    $color = $isSunday ? 'background:#dc3545;' : ($isHoliday ? 'background:#ffc107;' : '');
                                ?>
                                <th class="text-center" style="font-size:0.5rem;<?php echo $color; ?>">
                                    <?php echo $isHoliday ? 'H' : substr($dayNames[$dow - 1], 0, 1); ?>
                                </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mode === 'blank'): ?>
                            <!-- Blank rows for manual filling -->
                            <?php for ($i = 1; $i <= 30; $i++): ?>
                            <tr style="height:20px;">
                                <td class="text-center"><?php echo $i; ?></td>
                                <td></td><td></td><td></td>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                                <td></td>
                                <?php endfor; ?>
                                <td></td><td></td><td></td>
                            </tr>
                            <?php endfor; ?>
                            <?php else: ?>
                            <?php if (empty($attendanceData)): ?>
                            <tr>
                                <td colspan="<?php echo 7 + $daysInMonth; ?>" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-circle me-1"></i>
                                    No attendance data found for the selected period. Upload attendance first.
                                </td>
                            </tr>
                            <?php else: foreach ($attendanceData as $i => $r): ?>
                            <tr style="height:20px;">
                                <td class="text-center"><?php echo $i + 1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td class="text-truncate" style="max-width:90px;" title="<?php echo htmlspecialchars($r['full_name']); ?>">
                                    <?php echo sanitize($r['full_name']); ?>
                                </td>
                                <td class="text-muted text-truncate" style="max-width:55px;" title="<?php echo htmlspecialchars($r['designation'] ?? ''); ?>">
                                    <?php echo sanitize($r['designation'] ?? ''); ?>
                                </td>
                                <?php
                                for ($d = 1; $d <= $daysInMonth; $d++):
                                    $rawStatus = getDailyAttendance($r['daily_data'] ?? null, $d, $db, $r['id'], $month, $year);
                                    $status = getStatusLabel($rawStatus, $d, $holidays);
                                    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                                    $isSunday = ($dow === 7);
                                    $isHoliday = isset($holidays[$d]);

                                    if ($isSunday && empty($status)) {
                                        $displayStatus = 'WO';
                                        $bgColor = 'background:#e3f2fd;';
                                    } elseif ($isHoliday && empty($status)) {
                                        $displayStatus = 'H';
                                        $bgColor = 'background:#fff3cd;';
                                    } else {
                                        $displayStatus = $status;
                                        $bgColor = getStatusColor($rawStatus, $d, $holidays);
                                    }
                                ?>
                                <td class="text-center" style="<?php echo $bgColor; ?>font-size:0.55rem;">
                                    <?php echo $displayStatus; ?>
                                </td>
                                <?php endfor; ?>
                                <td class="text-center fw-bold" style="background:#e8f5e9;">
                                    <?php echo $r['total_present'] ?? 0; ?>
                                </td>
                                <td class="text-center" style="background:#fff8e1;">
                                    <?php echo $r['total_wo'] ?? 0; ?>
                                </td>
                                <td class="text-center fw-bold" style="background:#f3e5f5;">
                                    <?php echo $r['total_paid_days'] ?? 0; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Employer Signature Block -->
        <div class="card mt-3">
            <div class="card-body py-3">
                <div class="row">
                    <div class="col-md-6">
                        <p class="small text-muted mb-1">
                            <strong>Holidays in <?php echo $monthName . ' ' . $year; ?>:</strong>
                            <?php if (empty($holidays)): ?>
                            <em>No holidays recorded.</em>
                            <?php else: ?>
                            <?php foreach ($holidays as $day => $name): ?>
                            <?php echo $day . ' ' . $name; ?>;
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="small mb-1"><strong>Signature of Employer / Manager</strong></p>
                        <div style="border-bottom:1px solid #000; width:200px; margin-left:auto; height:40px;"></div>
                        <p class="small text-muted mb-0 mt-1">Name: ________________ Designation: ________________</p>
                        <p class="small text-muted mb-0">Date: ________________ Seal: ________________</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header { display: none !important; }
    body { font-size: 7pt; }
    .table { font-size: 5.5pt; }
    .table td, .table th { padding: 0.5px 1.5px !important; }
    .card { border: 1px solid #000 !important; page-break-inside: avoid; }
    .card-body { padding: 4px !important; }
    .badge { display: none !important; }
    @page {
        size: A3 landscape;
        margin: 8mm;
    }
}
</style>
