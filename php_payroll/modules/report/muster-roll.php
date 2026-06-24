<?php
/**
 * RCS HRMS Pro - Muster Roll Register
 * Blank + Filled formats with attendance data
 */

$pageTitle = 'Muster Roll';

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

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$units = [];
if ($clientFilter) {
    $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$clientFilter]);
}

$monthName = date('F', mktime(0,0,0,$month,1,$year));
$daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
$dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// Get attendance data for filled mode
$attendanceData = [];
if ($mode === 'filled') {
    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

    $attendanceData = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.designation,
                c.name as client_name, u.name as unit_name,
                ats.total_present, ats.total_wo, ats.total_extra, ats.overtime_hours,
                ats.total_paid_days, ats.daily_data
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN attendance_summary ats ON ats.employee_id = e.id AND ats.month = ? AND ats.year = ?
         WHERE $where
         ORDER BY c.name, u.name, e.employee_code",
        array_merge([$month, $year], $params)
    );
}

// Daily attendance detail
function getDailyAttendance($dailyData, $day, $db, $empId, $month, $year) {
    // Check if daily_data JSON has this day
    if ($dailyData) {
        $decoded = json_decode($dailyData, true);
        if (is_array($decoded) && isset($decoded[$day])) {
            return $decoded[$day]; // P, WO, A, HD, etc.
        }
    }
    // Fallback: check daily_attendance table
    try {
        $rec = $db->fetch("SELECT status FROM daily_attendance WHERE employee_id = ? AND DATE(att_date) = ?", 
            [$empId, sprintf('%04d-%02d-%02d', $year, $month, $day)]);
        if ($rec) return $rec['status'];
    } catch (Exception $e) {}
    return '';
}

// Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="muster_roll_' . $monthName . '_' . $year . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Muster Roll - ' . $monthName . ' ' . $year]);
    $header = ['Code','Name','Designation','Unit','PD','WO','Extra','OT Hrs'];
    for ($d = 1; $d <= $daysInMonth; $d++) $header[] = $d;
    $header[] = 'Total';
    fputcsv($output, $header);
    foreach ($attendanceData as $r) {
        $row = [$r['employee_code'], $r['full_name'], $r['designation'], $r['unit_name'],
            $r['total_present'] ?? 0, $r['total_wo'] ?? 0, $r['total_extra'] ?? 0, $r['overtime_hours'] ?? 0];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $row[] = getDailyAttendance($r['daily_data'] ?? null, $d, $db, $r['id'], $month, $year);
        }
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
            <h4 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Muster Roll - <?php echo $monthName . ' ' . $year; ?></h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success" onclick="window.location.href+='&export=csv'"><i class="bi bi-download me-1"></i>Export</button>
                <button class="btn btn-outline-info" onclick="window.print()"><i class="bi bi-printer"></i></button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/muster-roll">
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
                    <div class="col-md-2">
                        <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter==$u['id']?'selected':''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="filled" <?php echo $mode==='filled'?'selected':''; ?>>Filled</option>
                            <option value="blank" <?php echo $mode==='blank'?'selected':''; ?>>Blank</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Muster Roll Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0" style="font-size:0.65rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" style="width:20px;">#</th>
                                <th rowspan="2" style="width:50px;">Code</th>
                                <th rowspan="2" style="min-width:100px;">Name</th>
                                <th rowspan="2" style="min-width:60px;">Designation</th>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                                    $dow = (int)date('N', mktime(0,0,0,$month,$d,$year));
                                    $isSunday = ($dow === 7);
                                    $color = $isSunday ? 'background:#dc3545;' : '';
                                ?>
                                <th class="text-center" style="min-width:18px;<?php echo $color; ?>" title="<?php echo date('l j M', mktime(0,0,0,$month,$d,$year)); ?>">
                                    <?php echo $d; ?>
                                </th>
                                <?php endfor; ?>
                                <th rowspan="2" class="text-center" style="background:#198754;min-width:30px;">P</th>
                                <th rowspan="2" class="text-center" style="background:#fd7e14;min-width:25px;">W/O</th>
                                <th rowspan="2" class="text-center" style="background:#0dcaf0;min-width:25px;">Ex</th>
                                <th rowspan="2" class="text-center" style="background:#6f42c1;min-width:30px;">PD</th>
                                <th rowspan="2" class="text-center" style="background:#dc3545;min-width:30px;">OT</th>
                            </tr>
                            <tr>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                    $dow = (int)date('N', mktime(0,0,0,$month,$d,$year));
                                ?>
                                <th class="text-center" style="font-size:0.55rem;"><?php echo substr($dayNames[$dow - 1] ?? '', 0, 1); ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mode === 'blank'): ?>
                            <!-- Blank rows for manual fill -->
                            <?php for ($i = 1; $i <= 30; $i++): ?>
                            <tr style="height:22px;">
                                <td class="text-center"><?php echo $i; ?></td>
                                <td></td><td></td><td></td>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                                <td></td>
                                <?php endfor; ?>
                                <td></td><td></td><td></td><td></td><td></td>
                            </tr>
                            <?php endfor; ?>
                            <?php else: ?>
                            <?php if (empty($attendanceData)): ?>
                            <tr><td colspan="<?php echo 8 + $daysInMonth; ?>" class="text-center py-4 text-muted">No attendance data found. Upload attendance first.</td></tr>
                            <?php else: foreach ($attendanceData as $i => $r): ?>
                            <tr style="height:22px;">
                                <td class="text-center"><?php echo $i + 1; ?></td>
                                <td><code><?php echo sanitize($r['employee_code']); ?></code></td>
                                <td class="text-truncate" style="max-width:100px;" title="<?php echo htmlspecialchars($r['full_name']); ?>"><?php echo sanitize($r['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($r['designation'] ?? ''); ?></td>
                                <?php 
                                for ($d = 1; $d <= $daysInMonth; $d++):
                                    $status = getDailyAttendance($r['daily_data'] ?? null, $d, $db, $r['id'], $month, $year);
                                    $dow = (int)date('N', mktime(0,0,0,$month,$d,$year));
                                    $isSunday = ($dow === 7);
                                    $bgColor = '';
                                    switch (strtoupper($status)) {
                                        case 'P': case 'PR': $bgColor = 'background:#c8e6c9;'; break;
                                        case 'WO': $bgColor = 'background:#fff3cd;'; break;
                                        case 'A': $bgColor = 'background:#f8d7da;'; break;
                                        case 'HD': $bgColor = 'background:#d1ecf1;'; break;
                                        case 'EL': $bgColor = 'background:#e2d5f1;'; break;
                                        default: if ($isSunday) $bgColor = 'background:#ffebee;';
                                    }
                                ?>
                                <td class="text-center" style="<?php echo $bgColor; ?>font-size:0.6rem;"><?php echo $status ?: ($isSunday ? 'W' : ''); ?></td>
                                <?php endfor; ?>
                                <td class="text-center fw-bold" style="background:#e8f5e9;"><?php echo $r['total_present'] ?? 0; ?></td>
                                <td class="text-center" style="background:#fff8e1;"><?php echo $r['total_wo'] ?? 0; ?></td>
                                <td class="text-center" style="background:#e1f5fe;"><?php echo $r['total_extra'] ?? 0; ?></td>
                                <td class="text-center fw-bold" style="background:#f3e5f5;"><?php echo $r['total_paid_days'] ?? 0; ?></td>
                                <td class="text-center" style="background:#fce4ec;"><?php echo $r['overtime_hours'] ?? 0; ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header { display: none !important; }
    body { font-size: 8pt; }
    .table td, .table th { padding: 1px 2px !important; }
    .table { font-size: 6pt; }
}
</style>
