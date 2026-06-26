<?php
/**
 * RCS HRMS Pro - Muster Entry (Blank)
 * Generate blank muster roll for manual data entry (print and fill by hand)
 * Also supports pre-filled mode with attendance data
 */

$pageTitle = 'Muster Entry';

// Ensure daily_data column exists in attendance_summary
try {
    $db->query("SELECT daily_data FROM attendance_summary LIMIT 1");
} catch (Exception $e) {
    $db->exec("ALTER TABLE attendance_summary ADD COLUMN daily_data LONGTEXT DEFAULT NULL AFTER total_paid_days");
}

// Get filter values
$currentMonth = prev_month_num();
$currentYear = date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$mode = sanitize($_GET['mode'] ?? 'blank'); // blank or filled
$filterPressed = isset($_GET['filter']) || $clientFilter > 0;

// Get clients and units
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$allUnits = $db->fetchAll("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name");
$units = [];
if ($clientFilter) {
    foreach ($allUnits as $u) {
        if ($u['client_id'] == $clientFilter) {
            $units[] = $u;
        }
    }
}

// Month info
$monthName = date('F', mktime(0, 0, 0, $monthFilter, 1, $yearFilter));
$daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $monthFilter, $yearFilter);
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Handle POST save muster data (when in filled mode with manual entry)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_muster'])) {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $month = (int)($_POST['month'] ?? $monthFilter);
    $year = (int)($_POST['year'] ?? $yearFilter);
    $employeeIds = $_POST['employee_id'] ?? [];
    $savedCount = 0;

    try {
        $db->beginTransaction();

        foreach ($employeeIds as $empId) {
            $empId = (int)$empId;
            $present = 0;
            $wo = 0;
            $extra = 0;
            $halfDay = 0;
            $absent = 0;
            $dailyData = [];

            // Process each day
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dayVal = sanitize($_POST['day_' . $empId . '_' . $d] ?? '');
                
                // Day of week check
                $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
                $isSunday = ($dow === 7);

                if (empty($dayVal)) {
                    $dailyData[$d] = $isSunday ? 'WO' : '';
                } else {
                    $dailyData[$d] = strtoupper($dayVal);
                    switch (strtoupper($dayVal)) {
                        case 'P': case 'PR': $present++; break;
                        case 'WO': $wo++; break;
                        case 'HD': $halfDay++; $present += 0.5; break;
                        case 'A': $absent++; break;
                        case 'EL': $extra++; break;
                        case 'H': $present++; break; // Holiday = Present
                    }
                }
            }

            $totalPaidDays = $present + $wo + $extra;

            // Save to attendance_summary
            $existing = $db->fetch(
                "SELECT id FROM attendance_summary WHERE employee_id = ? AND month = ? AND year = ?",
                [$empId, $month, $year]
            );

            $dailyDataJson = json_encode($dailyData);

            if ($existing) {
                $db->update('attendance_summary', [
                    'total_present' => $present,
                    'total_extra' => $extra,
                    'total_wo' => $wo,
                    'total_paid_days' => $totalPaidDays,
                    'source' => 'Muster Entry'
                ], 'id = :id', ['id' => $existing['id']]);

                // Try to update daily_data if column exists
                try {
                    $db->query(
                        "UPDATE attendance_summary SET daily_data = ? WHERE id = ?",
                        [$dailyDataJson, $existing['id']]
                    );
                } catch (Exception $e) {
                    // Column might not exist
                }
            } else {
                $db->query(
                    "INSERT INTO attendance_summary 
                     (employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, total_paid_days, source)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'Muster Entry')",
                    [$empId, $unitId, $month, $year, $present, $extra, $wo, $totalPaidDays]
                );

                // Try to add daily_data
                try {
                    $db->query(
                        "UPDATE attendance_summary SET daily_data = ? WHERE employee_id = ? AND month = ? AND year = ?",
                        [$dailyDataJson, $empId, $month, $year]
                    );
                } catch (Exception $e) {}
            }

            $savedCount++;
        }

        $db->commit();
        setFlash('success', "Muster data saved for {$savedCount} employees.");
        redirect('index.php?page=entry/muster-entry&month=' . $month . '&year=' . $year . 
                '&client_id=' . $clientFilter . '&unit_id=' . $unitFilter . '&mode=filled&filter=1');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error saving muster: ' . $e->getMessage());
    }
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="muster_entry_' . $monthName . '_' . $yearFilter . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Muster Roll - ' . $monthName . ' ' . $yearFilter]);
    $header = ['Code', 'Name', 'Designation', 'Unit'];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $header[] = $d . ' (' . substr($dayNames[(int)date('N', mktime(0,0,0,$monthFilter,$d,$yearFilter)) - 1], 0, 2) . ')';
    }
    $header[] = 'Present';
    $header[] = 'WO';
    $header[] = 'Extra';
    $header[] = 'Total';
    fputcsv($output, $header);

    // Get employee data for export
    if ($clientFilter) {
        $where = "e.status = 'approved'";
        $params = [];
        if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
        if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

        $data = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.designation, u.name as unit_name,
                    ats.total_present, ats.total_wo, ats.total_extra, ats.total_paid_days,
                    ats.daily_data
             FROM employees e
             LEFT JOIN units u ON e.unit_id = u.id
             LEFT JOIN attendance_summary ats ON ats.employee_id = e.id 
                AND ats.month = ? AND ats.year = ?
             WHERE $where
             ORDER BY e.employee_code",
            array_merge([$monthFilter, $yearFilter], $params)
        );

        foreach ($data as $row) {
            $line = [$row['employee_code'], $row['full_name'], $row['designation'], $row['unit_name']];
            $dailyData = json_decode($row['daily_data'] ?? '{}', true);
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dow = (int)date('N', mktime(0,0,0,$monthFilter,$d,$yearFilter));
                $val = $dailyData[$d] ?? '';
                if (empty($val) && $dow === 7) $val = 'WO';
                $line[] = $val;
            }
            $line[] = $row['total_present'] ?? 0;
            $line[] = $row['total_wo'] ?? 0;
            $line[] = $row['total_extra'] ?? 0;
            $line[] = $row['total_paid_days'] ?? 0;
            fputcsv($output, $line);
        }
    }

    fclose($output);
    exit;
}

// Get employees data
$employees = [];
if ($filterPressed && $clientFilter) {
    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.designation,
                u.name as unit_name, c.name as client_name,
                ats.total_present, ats.total_wo, ats.total_extra, ats.total_paid_days,
                ats.overtime_hours, ats.daily_data
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN attendance_summary ats ON ats.employee_id = e.id 
            AND ats.month = ? AND ats.year = ?
         WHERE $where
         ORDER BY c.name, u.name, e.employee_code",
        array_merge([$monthFilter, $yearFilter], $params)
    );

    // Parse daily_data for each employee
    foreach ($employees as &$emp) {
        $emp['parsed_daily'] = json_decode($emp['daily_data'] ?? '{}', true);
        if (!is_array($emp['parsed_daily'])) {
            $emp['parsed_daily'] = [];
        }
    }
    unset($emp);
}

// Holiday lookup
$holidays = [];
try {
    $holidayRecords = $db->fetchAll(
        "SELECT holiday_date, holiday_name FROM holidays 
         WHERE YEAR(holiday_date) = ? AND MONTH(holiday_date) = ?",
        [$yearFilter, $monthFilter]
    );
    foreach ($holidayRecords as $h) {
        $day = (int)date('j', strtotime($h['holiday_date']));
        $holidays[$day] = $h['holiday_name'];
    }
} catch (Exception $e) {}

// Month names
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<div class="row">
    <div class="col-12">
        <!-- Header -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-calendar-check me-2"></i>Muster Entry — 
                    <span class="text-primary"><?php echo $monthName . ' ' . $yearFilter; ?></span>
                </h5>
                <div class="d-flex gap-1">
                    <?php if (!empty($employees)): ?>
                    <a href="index.php?page=entry/muster-entry&<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download me-1"></i>CSV
                    </a>
                    <?php endif; ?>
                    <button class="btn btn-outline-info btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-2">
                    <input type="hidden" name="page" value="entry/muster-entry">
                    
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select class="form-select form-select-sm" name="month">
                            <?php foreach ($months as $num => $name): ?>
                            <option value="<?php echo $num; ?>" <?php echo $monthFilter == $num ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select class="form-select form-select-sm" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $yearFilter == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Client <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="client_id" id="clientSelect">
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select class="form-select form-select-sm" name="unit_id" id="unitSelect">
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
                        <select class="form-select form-select-sm" name="mode">
                            <option value="blank" <?php echo $mode === 'blank' ? 'selected' : ''; ?>>Blank (Print)</option>
                            <option value="filled" <?php echo $mode === 'filled' ? 'selected' : ''; ?>>Filled (Edit)</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end gap-1">
                        <button type="submit" name="filter" value="1" class="btn btn-primary btn-sm flex-grow-1">
                            <i class="bi bi-search me-1"></i>Load
                        </button>
                        <a href="index.php?page=entry/muster-entry" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                </form>
                
                <!-- Legend -->
                <div class="mt-2 d-flex flex-wrap gap-2 small">
                    <span class="badge bg-success">P = Present</span>
                    <span class="badge bg-secondary">A = Absent</span>
                    <span class="badge bg-warning text-dark">HD = Half Day</span>
                    <span class="badge bg-info">WO = Weekly Off</span>
                    <span class="badge bg-danger">H = Holiday</span>
                    <span class="badge bg-primary">EL = Extra</span>
                </div>
            </div>
        </div>
        
        <?php if (!$filterPressed || !$clientFilter): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-calendar-range fs-1"></i>
                <p class="mt-3">Select a <strong>Client</strong> and click <strong>Load</strong> to generate muster roll.</p>
                <small>Use <strong>Blank</strong> mode to print a blank muster, or <strong>Filled</strong> mode to edit attendance data.</small>
            </div>
        </div>
        
        <?php elseif (empty($employees)): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-people fs-1"></i>
                <p class="mt-3">No approved employees found for selected filters.</p>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Muster Roll -->
        <?php if ($mode === 'filled'): ?>
        <form method="POST" id="musterForm">
            <input type="hidden" name="month" value="<?php echo $monthFilter; ?>">
            <input type="hidden" name="year" value="<?php echo $yearFilter; ?>">
            <input type="hidden" name="unit_id" value="<?php echo $unitFilter; ?>">
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <div>
                    <span class="badge bg-primary"><?php echo count($employees); ?> Employees</span>
                    <span class="badge bg-dark"><?php echo $daysInMonth; ?> Days</span>
                    <span class="badge bg-<?php echo $mode === 'blank' ? 'secondary' : 'success'; ?>">
                        <?php echo $mode === 'blank' ? 'Blank' : 'Editable'; ?>
                    </span>
                </div>
                <?php if ($mode === 'filled'): ?>
                <button type="submit" name="save_muster" class="btn btn-success btn-sm"
                        onclick="return confirm('Save muster data for all employees?')">
                    <i class="bi bi-floppy me-1"></i>Save Muster
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0" style="font-size:0.7rem;" id="musterTable">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" style="width:25px;">#</th>
                                <th rowspan="2" style="width:55px;">Code</th>
                                <th rowspan="2" style="min-width:120px;">Employee Name</th>
                                <th rowspan="2" style="min-width:70px;">Designation</th>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                                    $dow = (int)date('N', mktime(0, 0, 0, $monthFilter, $d, $yearFilter));
                                    $isSunday = ($dow === 7);
                                    $isHoliday = isset($holidays[$d]);
                                    $bgStyle = '';
                                    if ($isSunday) $bgStyle = 'background:#dc3545;';
                                    elseif ($isHoliday) $bgStyle = 'background:#0d6efd;';
                                ?>
                                <th class="text-center" style="min-width:22px;<?php echo $bgStyle; ?>" 
                                    title="<?php echo date('l j M Y', mktime(0, 0, 0, $monthFilter, $d, $yearFilter)); 
                                    echo $isHoliday ? ' - ' . $holidays[$d] : ''; ?>">
                                    <?php echo $d; ?>
                                </th>
                                <?php endfor; ?>
                                <th rowspan="2" class="text-center" style="background:#198754;min-width:28px;">P</th>
                                <th rowspan="2" class="text-center" style="background:#fd7e14;min-width:24px;">WO</th>
                                <th rowspan="2" class="text-center" style="background:#0dcaf0;min-width:24px;">Ex</th>
                                <th rowspan="2" class="text-center" style="background:#6f42c1;min-width:28px;">PD</th>
                            </tr>
                            <tr>
                                <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                                    $dow = (int)date('N', mktime(0, 0, 0, $monthFilter, $d, $yearFilter));
                                    $isSunday = ($dow === 7);
                                    $bgStyle = $isSunday ? 'background:#b52a37;' : '';
                                ?>
                                <th class="text-center" style="font-size:0.55rem;<?php echo $bgStyle; ?>">
                                    <?php echo substr($dayNames[$dow - 1], 0, 2); ?>
                                </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $empCount = 0;
                            foreach ($employees as $idx => $emp):
                                $empCount++;
                                $dailyData = $emp['parsed_daily'];
                            ?>
                            <tr style="height:28px;">
                                <td class="text-center text-muted"><?php echo $idx + 1; ?></td>
                                <td>
                                    <?php if ($mode === 'filled'): ?>
                                    <input type="hidden" name="employee_id[]" value="<?php echo $emp['id']; ?>">
                                    <?php endif; ?>
                                    <code><?php echo sanitize($emp['employee_code']); ?></code>
                                </td>
                                <td class="text-truncate" style="max-width:140px;" title="<?php echo htmlspecialchars($emp['full_name']); ?>">
                                    <?php echo sanitize($emp['full_name']); ?>
                                </td>
                                <td class="text-muted text-truncate" style="max-width:80px;" title="<?php echo htmlspecialchars($emp['designation'] ?? ''); ?>">
                                    <?php echo sanitize($emp['designation'] ?? ''); ?>
                                </td>
                                <?php 
                                $rowPresent = 0; $rowWO = 0; $rowExtra = 0;
                                for ($d = 1; $d <= $daysInMonth; $d++):
                                    $dow = (int)date('N', mktime(0, 0, 0, $monthFilter, $d, $yearFilter));
                                    $isSunday = ($dow === 7);
                                    $isHoliday = isset($holidays[$d]);
                                    
                                    // Get existing value or default
                                    $val = $dailyData[$d] ?? '';
                                    if (empty($val)) {
                                        if ($isSunday) $val = 'WO';
                                        elseif ($isHoliday) $val = 'H';
                                    }
                                    
                                    // Count for totals
                                    switch (strtoupper($val)) {
                                        case 'P': case 'PR': case 'H': $rowPresent++; break;
                                        case 'WO': $rowWO++; break;
                                        case 'EL': $rowExtra++; break;
                                        case 'HD': $rowPresent += 0.5; break;
                                    }
                                    
                                    // Cell background
                                    $cellBg = '';
                                    switch (strtoupper($val)) {
                                        case 'P': case 'PR': $cellBg = 'background:#c8e6c9;'; break;
                                        case 'WO': $cellBg = 'background:#fff3cd;'; break;
                                        case 'A': $cellBg = 'background:#f8d7da;'; break;
                                        case 'HD': $cellBg = 'background:#d1ecf1;'; break;
                                        case 'EL': $cellBg = 'background:#d1c4e9;'; break;
                                        case 'H': $cellBg = 'background:#bbdefb;'; break;
                                        default: 
                                            if ($isSunday) $cellBg = 'background:#ffebee;';
                                            elseif ($isHoliday) $cellBg = 'background:#e3f2fd;';
                                    }
                                ?>
                                <td class="text-center muster-cell" style="<?php echo $cellBg; ?>">
                                    <?php if ($mode === 'filled'): ?>
                                    <select name="day_<?php echo $emp['id']; ?>_<?php echo $d; ?>" 
                                            class="form-select form-select-sm py-0 px-0 muster-select"
                                            data-emp-id="<?php echo $emp['id']; ?>" data-day="<?php echo $d; ?>"
                                            style="font-size:0.6rem;border:none;<?php echo $isSunday ? 'color:#dc3545;' : ''; ?>">
                                        <option value="">-</option>
                                        <option value="P" <?php echo strtoupper($val) === 'P' || strtoupper($val) === 'PR' ? 'selected' : ''; ?>>P</option>
                                        <option value="A" <?php echo strtoupper($val) === 'A' ? 'selected' : ''; ?>>A</option>
                                        <option value="HD" <?php echo strtoupper($val) === 'HD' ? 'selected' : ''; ?>>HD</option>
                                        <option value="WO" <?php echo strtoupper($val) === 'WO' ? 'selected' : ''; ?>>WO</option>
                                        <option value="H" <?php echo strtoupper($val) === 'H' ? 'selected' : ''; ?>>H</option>
                                        <option value="EL" <?php echo strtoupper($val) === 'EL' ? 'selected' : ''; ?>>EL</option>
                                    </select>
                                    <?php else: ?>
                                    <?php echo $val; ?>
                                    <?php endif; ?>
                                </td>
                                <?php endfor; ?>
                                
                                <?php $totalPD = $rowPresent + $rowWO + $rowExtra; ?>
                                <td class="text-center fw-bold" style="background:#e8f5e9;">
                                    <?php echo $rowPresent; ?>
                                </td>
                                <td class="text-center" style="background:#fff8e1;">
                                    <?php echo $rowWO; ?>
                                </td>
                                <td class="text-center" style="background:#e1f5fe;">
                                    <?php echo $rowExtra; ?>
                                </td>
                                <td class="text-center fw-bold" style="background:#f3e5f5;">
                                    <?php echo $totalPD; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if ($mode === 'filled'): ?>
            <div class="card-footer">
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Select attendance status for each day. Sundays (red) and Holidays (blue) are pre-marked.
                        Changes will be saved to attendance_summary.
                    </small>
                    <button type="submit" name="save_muster" class="btn btn-success btn-sm"
                            onclick="return confirm('Save muster data for all <?php echo count($employees); ?> employees?')">
                        <i class="bi bi-floppy me-1"></i>Save Muster Data
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($mode === 'filled'): ?>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header, .card-footer { display: none !important; }
    body { font-size: 8pt; }
    .table td, .table th { padding: 1px 2px !important; }
    .table { font-size: 6.5pt; }
    .muster-select { 
        appearance: none !important; 
        -webkit-appearance: none !important;
        border: none !important;
        background: transparent !important;
        padding: 0 !important;
    }
}

/* Custom scrollbar for mustering */
.table-responsive {
    scrollbar-width: thin;
}

.muster-select:focus {
    box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.5) !important;
}

/* Color coding for select options */
.muster-select option[value="P"] { background: #c8e6c9; }
.muster-select option[value="A"] { background: #f8d7da; }
.muster-select option[value="HD"] { background: #d1ecf1; }
.muster-select option[value="WO"] { background: #fff3cd; }
.muster-select option[value="H"] { background: #bbdefb; }
.muster-select option[value="EL"] { background: #d1c4e9; }
</style>

<script>
// Load units dynamically
document.getElementById('clientSelect')?.addEventListener('change', function() {
    const clientId = this.value;
    const unitSelect = document.getElementById('unitSelect');
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!clientId) {
        unitSelect.innerHTML = '<option value="">All Units</option>';
        return;
    }
    
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(r => r.json())
        .then(data => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data.units) {
                data.units.forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u.id;
                    opt.textContent = u.name;
                    unitSelect.appendChild(opt);
                });
            }
        })
        .catch(() => { unitSelect.innerHTML = '<option value="">All Units</option>'; });
});

// Color-code cells when selects change
document.querySelectorAll('.muster-select').forEach(select => {
    select.addEventListener('change', function() {
        const cell = this.closest('td');
        cell.style.background = '';
        
        switch (this.value) {
            case 'P': cell.style.background = '#c8e6c9'; break;
            case 'A': cell.style.background = '#f8d7da'; break;
            case 'HD': cell.style.background = '#d1ecf1'; break;
            case 'WO': cell.style.background = '#fff3cd'; break;
            case 'H': cell.style.background = '#bbdefb'; break;
            case 'EL': cell.style.background = '#d1c4e9'; break;
            default: cell.style.background = '';
        }
    });
});

// Quick-fill keyboard shortcuts
document.getElementById('musterTable')?.addEventListener('keydown', function(e) {
    if (!e.target.classList.contains('muster-select')) return;
    
    const select = e.target;
    
    switch (e.key) {
        case 'p': select.value = 'P'; select.dispatchEvent(new Event('change')); break;
        case 'a': select.value = 'A'; select.dispatchEvent(new Event('change')); break;
        case 'h': select.value = 'HD'; select.dispatchEvent(new Event('change')); break;
        case 'w': select.value = 'WO'; select.dispatchEvent(new Event('change')); break;
        case 'l': select.value = 'H'; select.dispatchEvent(new Event('change')); break;
        case 'e': select.value = 'EL'; select.dispatchEvent(new Event('change')); break;
        case 'ArrowRight':
        case 'Tab':
            e.preventDefault();
            const allSelects = Array.from(document.querySelectorAll('.muster-select'));
            const idx = allSelects.indexOf(select);
            if (idx < allSelects.length - 1) allSelects[idx + 1].focus();
            break;
        case 'ArrowDown':
            e.preventDefault();
            const allSelectsDown = Array.from(document.querySelectorAll('.muster-select'));
            const idxD = allSelectsDown.indexOf(select);
            const daysInMonth = <?php echo $daysInMonth; ?>;
            if (idxD + daysInMonth < allSelectsDown.length) allSelectsDown[idxD + daysInMonth].focus();
            break;
    }
});
</script>
