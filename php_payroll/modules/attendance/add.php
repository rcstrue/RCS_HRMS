<?php
/**
 * RCS HRMS Pro - Add Attendance (Manual Entry)
 * Manual attendance entry like Excel sheet
 */

$pageTitle = 'Add Attendance';

// Get clients
$clients = [];
try {
    $stmt = $db->query("SELECT id, name, client_code FROM clients WHERE is_active = 1 ORDER BY name");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist
}

// Get selected filters - default to previous month
$previousMonth = prev_month_num();
$previousYear = prev_month_year();
$selectedClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$selectedUnit = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $previousMonth;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $previousYear;

// Ensure attendance_summary table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `attendance_summary` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `unit_id` int(11) DEFAULT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `total_present` decimal(5,2) DEFAULT 0.00,
        `total_extra` decimal(5,2) DEFAULT 0.00,
        `overtime_hours` decimal(6,2) DEFAULT 0.00,
        `total_wo` decimal(5,2) DEFAULT 0.00,
        `total_paid_days` decimal(5,2) DEFAULT 0.00,
        `source` enum('Manual','Excel Upload') DEFAULT 'Manual',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_emp_unit_month_year` (`employee_id`, `unit_id`, `month`, `year`),
        KEY `idx_unit_month_year` (`unit_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add total_paid_days column if it doesn't exist (for existing databases)
    $checkCol = $db->fetch("SHOW COLUMNS FROM attendance_summary LIKE 'total_paid_days'");
    if (!$checkCol) {
        $db->exec("ALTER TABLE attendance_summary ADD COLUMN `total_paid_days` decimal(5,2) DEFAULT 0.00 AFTER `total_wo`");
    }
    
    // Fix old unique key if still exists as (employee_id, month, year) without unit_id
    // This allows same employee to have attendance in different units per month
} catch (Exception $e) {
    // Table creation/alter failed
}

// Get units based on selected client
$units = [];
if ($selectedClient) {
    try {
        $stmt = $db->prepare("SELECT id, name, unit_code FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$selectedClient]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table doesn't exist
    }
}

// Get employees and their attendance when unit is selected
$employees = [];
if ($selectedUnit && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['load'])) {
    // Get employees for this unit
    $stmt = $db->prepare("
        SELECT e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
               ess.basic_da, ess.gross_salary
        FROM employees e
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND ess.effective_to IS NULL
        WHERE e.unit_id = ? AND e.status = 'approved'
        ORDER BY e.employee_code
    ");
    $stmt->execute([$selectedUnit]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get existing attendance summary if any
    foreach ($employees as &$emp) {
        try {
            $stmt = $db->prepare("
                SELECT total_present, total_extra, overtime_hours, total_wo
                FROM attendance_summary
                WHERE employee_id = ? AND unit_id = ? AND month = ? AND year = ?
            ");
            $stmt->execute([$emp['id'], $selectedUnit, $selectedMonth, $selectedYear]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $emp['total_present'] = $existing['total_present'];
                $emp['total_extra'] = $existing['total_extra'];
                $emp['overtime_hours'] = $existing['overtime_hours'];
                $emp['total_wo'] = $existing['total_wo'];
            } else {
                $emp['total_present'] = '';
                $emp['total_extra'] = '';
                $emp['overtime_hours'] = '';
                $emp['total_wo'] = '';
            }
        } catch (Exception $e) {
            $emp['total_present'] = '';
            $emp['total_extra'] = '';
            $emp['overtime_hours'] = '';
            $emp['total_wo'] = '';
        }
    }
    unset($emp);
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $unitId = (int)$_POST['unit_id'];
    $month = (int)$_POST['month'];
    $year = (int)$_POST['year'];
    $employeeIds = $_POST['employee_id'] ?? [];
    
    // Get client_id from unit
    $stmt = $db->prepare("SELECT client_id FROM units WHERE id = ?");
    $stmt->execute([$unitId]);
    $unitData = $stmt->fetch(PDO::FETCH_ASSOC);
    $clientId = $unitData ? $unitData['client_id'] : 0;
    
    $savedCount = 0;
    $errors = [];
    
    try {
        foreach ($employeeIds as $empId) {
            $totalPresent = isset($_POST['total_present'][$empId]) ? (float)$_POST['total_present'][$empId] : 0;
            $totalExtra = isset($_POST['total_extra'][$empId]) ? (float)$_POST['total_extra'][$empId] : 0;
            $otHours = isset($_POST['overtime_hours'][$empId]) ? (float)$_POST['overtime_hours'][$empId] : 0;
            $totalWO = isset($_POST['total_wo'][$empId]) ? (int)$_POST['total_wo'][$empId] : 0;
            
            // Auto-calculate total_paid_days = present + WO + extra
            $totalPaidDays = round($totalPresent + $totalWO + $totalExtra, 2);
            
            // Insert or update using ON DUPLICATE KEY
            $stmt = $db->prepare("
                INSERT INTO attendance_summary 
                (employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, total_paid_days, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual')
                ON DUPLICATE KEY UPDATE 
                    total_present = VALUES(total_present),
                    total_extra = VALUES(total_extra),
                    overtime_hours = VALUES(overtime_hours),
                    total_wo = VALUES(total_wo),
                    total_paid_days = VALUES(total_paid_days),
                    source = 'Manual',
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$empId, $unitId, $month, $year, $totalPresent, $totalExtra, $otHours, $totalWO, $totalPaidDays]);
            $savedCount++;
        }
        
        setFlash('success', "Attendance saved successfully! {$savedCount} employees updated.");

        // Redirect to same page with filters
        // Note: Using redirect() helper instead of header() to handle "headers already sent" case
        redirect("index.php?page=attendance/add&client_id={$clientId}&unit_id={$unitId}&month={$month}&year={$year}&load=1");
        
    } catch (Exception $e) {
        setFlash('error', 'Error saving attendance: ' . $e->getMessage());
    }
}

// Days in selected month
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-table me-2"></i>Add Attendance (Manual Entry)</h5>
            </div>
            <div class="card-body">
                <!-- Filters Form -->
                <form method="GET" class="row g-3 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="attendance/add">
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selectedClient == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect" required>
                            <option value="">Select Unit</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnit == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php 
                            $currentYear = date('Y');
                            for ($y = $currentYear; $y >= $currentYear - 2; $y--):
                            ?>
                                <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="load" value="1" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Load
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($selectedUnit && isset($_GET['load'])): ?>
<!-- Attendance Entry Grid (Jspreadsheet) -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-info"><?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?></span>
                    <span class="badge bg-secondary ms-2">Total Days: <?php echo $daysInMonth; ?></span>
                    <span class="badge bg-primary ms-2"><?php echo count($employees); ?> Employees</span>
                </div>
                <div>
                    <a href="index.php?page=attendance/add" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg me-1"></i>Clear
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($employees)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-people fs-1"></i>
                    <p class="mt-2">No employees found for this unit.</p>
                </div>
                <?php else: ?>
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="save_attendance" value="1">
                    <input type="hidden" name="unit_id" value="<?php echo $selectedUnit; ?>">
                    <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                    <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                    <div id="spreadsheet" style="width:100%;"></div>
                    
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                <small><i class="bi bi-info-circle me-1"></i>Present: Total present days | Extra: Additional working days | OT: Overtime hours | WO: Weekly off days</small>
                            </div>
                            <div>
                                <button type="button" id="saveBtn" class="btn btn-success">
                                    <i class="bi bi-check-lg me-1"></i>Save Attendance
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Build JS data array and empIds JSON from PHP employees (for jspreadsheet)
$jssEmpIds = '[]';
$jssData = '[]';
if (!empty($employees)) {
    $empIdMap = [];
    $dataRows = [];
    $sr = 1;
    foreach ($employees as $emp) {
        $empIdMap[] = (int)$emp['id'];
        $present = ($emp['total_present'] !== '' && $emp['total_present'] !== null) ? (float)$emp['total_present'] : '';
        $extra = ($emp['total_extra'] !== '' && $emp['total_extra'] !== null) ? (float)$emp['total_extra'] : '';
        $ot = ($emp['overtime_hours'] !== '' && $emp['overtime_hours'] !== null) ? (float)$emp['overtime_hours'] : '';
        $wo = ($emp['total_wo'] !== '' && $emp['total_wo'] !== null) ? (float)$emp['total_wo'] : '';
        $code = addslashes($emp['employee_code']);
        $name = addslashes($emp['full_name']);
        $desig = addslashes($emp['designation']);
        $cat = addslashes($emp['worker_category']);
        $dataRows[] = "['{$sr}','{$code}','{$name}','{$desig}','{$cat}',{$present},{$extra},{$ot},{$wo}]";
        $sr++;
    }
    $jssEmpIds = json_encode($empIdMap);
    $jssData = '[' . implode(',', $dataRows) . ']';
}

$extraJS = <<<JS
<script>
// Load units when client changes
document.getElementById('clientSelect').addEventListener('change', function() {
    var clientId = this.value;
    var unitSelect = document.getElementById('unitSelect');
    
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!clientId) {
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        return;
    }
    
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            unitSelect.innerHTML = '<option value="">Select Unit</option>';
            if (data.units) {
                data.units.forEach(function(unit) {
                    var option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
        })
        .catch(function() {
            unitSelect.innerHTML = '<option value="">Select Unit</option>';
        });
});

// Jspreadsheet initialization
document.addEventListener('DOMContentLoaded', function() {
    var sheetEl = document.getElementById('spreadsheet');
    if (!sheetEl) return;

    // Employee IDs and data rows passed from PHP
    var empIds = {$jssEmpIds};
    var data = {$jssData};

    var jspreadsheetInstance = jspreadsheet(sheetEl, {
        data: data,
        columns: [
            { title: '#', type: 'text', width: 36, readOnly: true },
            { title: 'Code', type: 'text', width: 75, readOnly: true },
            { title: 'Name', type: 'text', width: 150, readOnly: true },
            { title: 'Desig', type: 'text', width: 100, readOnly: true },
            { title: 'Cat', type: 'text', width: 55, readOnly: true },
            { title: 'Present', type: 'numeric', width: 70, readOnly: false, mask: '#,##0.0' },
            { title: 'Extra', type: 'numeric', width: 70, readOnly: false, mask: '#,##0.0' },
            { title: 'OT Hrs', type: 'numeric', width: 70, readOnly: false, mask: '#,##0.0' },
            { title: 'WO', type: 'numeric', width: 60, readOnly: false, mask: '#,##0' }
        ],
        tableOverflow: true,
        tableHeight: '70vh',
        columnResize: true,
        allowExport: false,
        contextMenu: false,
        pagination: false,
        search: false,
        rowDrag: false,
        allowInsertRow: false,
        allowDeleteRow: false,
        allowRenameColumn: false,
        allowComments: false,
        style: 'jss_default',
        defaultColWidth: 70,
        minDimensions: [9, 0],
        noHyperlinks: true
    });

    // Save handler: collect jspreadsheet data and build POST arrays
    document.getElementById('saveBtn').addEventListener('click', function(e) {
        e.preventDefault();

        var form = document.getElementById('attendanceForm');
        var sheetData = jspreadsheetInstance.getData();

        // Remove old hidden inputs (except unit_id, month, year)
        var oldInputs = form.querySelectorAll('input[name^="employee_id"], input[name^="total_present"], input[name^="total_extra"], input[name^="overtime_hours"], input[name^="total_wo"]');
        oldInputs.forEach(function(inp) { inp.remove(); });

        // Build hidden inputs for each employee row
        for (var i = 0; i < sheetData.length; i++) {
            var row = sheetData[i];
            var empId = empIds[i];

            // employee_id[]
            var inpId = document.createElement('input');
            inpId.type = 'hidden';
            inpId.name = 'employee_id[]';
            inpId.value = empId;
            form.appendChild(inpId);

            // total_present[empId]
            var inpPresent = document.createElement('input');
            inpPresent.type = 'hidden';
            inpPresent.name = 'total_present[' + empId + ']';
            inpPresent.value = (row[5] !== '' && row[5] !== null) ? row[5] : 0;
            form.appendChild(inpPresent);

            // total_extra[empId]
            var inpExtra = document.createElement('input');
            inpExtra.type = 'hidden';
            inpExtra.name = 'total_extra[' + empId + ']';
            inpExtra.value = (row[6] !== '' && row[6] !== null) ? row[6] : 0;
            form.appendChild(inpExtra);

            // overtime_hours[empId]
            var inpOT = document.createElement('input');
            inpOT.type = 'hidden';
            inpOT.name = 'overtime_hours[' + empId + ']';
            inpOT.value = (row[7] !== '' && row[7] !== null) ? row[7] : 0;
            form.appendChild(inpOT);

            // total_wo[empId]
            var inpWO = document.createElement('input');
            inpWO.type = 'hidden';
            inpWO.name = 'total_wo[' + empId + ']';
            inpWO.value = (row[8] !== '' && row[8] !== null) ? row[8] : 0;
            form.appendChild(inpWO);
        }

        form.submit();
    });
});
</script>
JS;
?>
