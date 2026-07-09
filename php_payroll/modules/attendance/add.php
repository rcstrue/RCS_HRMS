<?php
/**
 * RCS HRMS Pro - Add Attendance & Advances (Manual Entry)
 * Combined attendance + advance entry like Excel sheet
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
} catch (Exception $e) {
    // Table creation/alter failed
}

// Ensure employee_advances table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `employee_advances` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `unit_id` int(11) DEFAULT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `adv1` decimal(10,2) DEFAULT 0.00,
        `adv2` decimal(10,2) DEFAULT 0.00,
        `office_advance` decimal(10,2) DEFAULT 0.00,
        `dress_advance` decimal(10,2) DEFAULT 0.00,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_emp_month_year` (`employee_id`, `month`, `year`),
        KEY `idx_unit_month_year` (`unit_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Fix employee_id column type if it was created as VARCHAR
    $colInfo = $db->fetch("SHOW COLUMNS FROM employee_advances LIKE 'employee_id'");
    if ($colInfo && strpos(strtoupper($colInfo['Type'] ?? ''), 'VARCHAR') !== false) {
        $db->exec("ALTER TABLE employee_advances MODIFY COLUMN employee_id INT(11) NOT NULL");
    }
} catch (Exception $e) {
    // Table creation failed
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

// Get employees with attendance and advances when unit is selected
$employees = [];
if ($selectedUnit && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['load'])) {
    $stmt = $db->prepare("
        SELECT e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
               ess.basic_da, ess.gross_salary,
               att.total_present, att.total_wo, att.total_extra, att.overtime_hours, att.total_paid_days
        FROM employees e
        LEFT JOIN (SELECT employee_id, MAX(basic_da) AS basic_da, MAX(gross_salary) AS gross_salary
                    FROM employee_salary_structures WHERE effective_to IS NULL GROUP BY employee_id) ess
            ON e.id = ess.employee_id
        LEFT JOIN attendance_summary att ON e.id = att.employee_id AND att.month = ? AND att.year = ?
        WHERE e.unit_id = ? AND e.status IN ('approved', 'active')
        ORDER BY e.employee_code
    ");
    $stmt->execute([$selectedMonth, $selectedYear, $selectedUnit]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get existing advances
    foreach ($employees as &$emp) {
        $emp['adv1'] = '';
        $emp['adv2'] = '';
        $emp['office_advance'] = '';
        $emp['dress_advance'] = '';
        try {
            $stmt = $db->prepare("
                SELECT adv1, adv2, office_advance, dress_advance
                FROM employee_advances
                WHERE employee_id = ? AND unit_id = ? AND month = ? AND year = ?
            ");
            $stmt->execute([$emp['id'], $selectedUnit, $selectedMonth, $selectedYear]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $emp['adv1'] = $existing['adv1'];
                $emp['adv2'] = $existing['adv2'];
                $emp['office_advance'] = $existing['office_advance'];
                $emp['dress_advance'] = $existing['dress_advance'];
            }
        } catch (Exception $e) {}
    }
    unset($emp);
}

// Handle save (attendance + advances in one transaction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_advance'])) {
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

    try {
        $db->exec("START TRANSACTION");
        foreach ($employeeIds as $empId) {
            // ── Attendance ──
            $attPresent = isset($_POST['att_present'][$empId]) ? (float)$_POST['att_present'][$empId] : 0;
            $attWO      = isset($_POST['att_wo'][$empId]) ? (float)$_POST['att_wo'][$empId] : 0;
            $attExtra   = isset($_POST['att_extra'][$empId]) ? (float)$_POST['att_extra'][$empId] : 0;
            $otHours    = isset($_POST['ot_hours'][$empId]) ? (float)$_POST['ot_hours'][$empId] : 0;
            $paidDays   = round($attPresent + $attWO + $attExtra, 1);

            $stmt = $db->prepare("
                INSERT INTO attendance_summary
                (employee_id, unit_id, month, year, total_present, total_wo, total_extra, overtime_hours, total_paid_days, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manual')
                ON DUPLICATE KEY UPDATE
                    total_present = VALUES(total_present),
                    total_wo = VALUES(total_wo),
                    total_extra = VALUES(total_extra),
                    overtime_hours = VALUES(overtime_hours),
                    total_paid_days = VALUES(total_paid_days),
                    source = 'Manual',
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$empId, $unitId, $month, $year, $attPresent, $attWO, $attExtra, $otHours, $paidDays]);

            // ── Advances ──
            $adv1 = isset($_POST['adv1'][$empId]) ? (float)$_POST['adv1'][$empId] : 0;
            $adv2 = isset($_POST['adv2'][$empId]) ? (float)$_POST['adv2'][$empId] : 0;
            $officeAdv = isset($_POST['office_advance'][$empId]) ? (float)$_POST['office_advance'][$empId] : 0;
            $dressAdv = isset($_POST['dress_advance'][$empId]) ? (float)$_POST['dress_advance'][$empId] : 0;

            if ($adv1 < 0 || $adv2 < 0 || $officeAdv < 0 || $dressAdv < 0) {
                $db->exec("ROLLBACK");
                setFlash('error', 'Advance amounts cannot be negative.');
                redirect("index.php?page=attendance/add&client_id={$clientId}&unit_id={$unitId}&month={$month}&year={$year}&load=1");
                exit;
            }

            $stmt = $db->prepare("
                INSERT INTO employee_advances
                (employee_id, unit_id, month, year, adv1, adv2, office_advance, dress_advance)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    adv1 = VALUES(adv1),
                    adv2 = VALUES(adv2),
                    office_advance = VALUES(office_advance),
                    dress_advance = VALUES(dress_advance),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$empId, $unitId, $month, $year, $adv1, $adv2, $officeAdv, $dressAdv]);
            $savedCount++;
        }
        $db->exec("COMMIT");

        setFlash('success', "Attendance & advances saved! {$savedCount} employees updated.");
        redirect("index.php?page=attendance/add&client_id={$clientId}&unit_id={$unitId}&month={$month}&year={$year}&load=1");

    } catch (Exception $e) {
        try { $db->exec("ROLLBACK"); } catch (Exception $re) {}
        setFlash('error', 'Error saving: ' . $e->getMessage());
    }
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-table me-2"></i>Add Attendance</h5>
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

<?php
// ── Build jspreadsheet data & column config ──
$jssData = [];
$jssColumns = [
    ['title' => '#',      'type' => 'text',    'width' => 36,  'readOnly' => true,  'align' => 'center'],
    ['title' => 'Code',   'type' => 'text',    'width' => 75,  'readOnly' => true,  'align' => 'center'],
    ['title' => 'Name',   'type' => 'text',    'width' => 150, 'readOnly' => true],
    ['title' => 'Desig',  'type' => 'text',    'width' => 100, 'readOnly' => true],
    ['title' => 'Cat',    'type' => 'text',    'width' => 55,  'readOnly' => true,  'align' => 'center'],
    ['title' => 'Prs',    'type' => 'numeric', 'width' => 55,  'align' => 'center'],
    ['title' => 'WO',     'type' => 'numeric', 'width' => 45,  'align' => 'center'],
    ['title' => 'Ext',    'type' => 'numeric', 'width' => 50,  'align' => 'center'],
    ['title' => 'OT',     'type' => 'numeric', 'width' => 50,  'align' => 'center'],
    ['title' => 'Paid',   'type' => 'numeric', 'width' => 50,  'readOnly' => true,  'align' => 'center'],
    ['title' => 'Adv 1',  'type' => 'numeric', 'width' => 80,  'align' => 'right'],
    ['title' => 'Adv 2',  'type' => 'numeric', 'width' => 80,  'align' => 'right'],
    ['title' => 'Office', 'type' => 'numeric', 'width' => 80,  'align' => 'right'],
    ['title' => 'Dress',  'type' => 'numeric', 'width' => 80,  'align' => 'right'],
    ['title' => 'Total',  'type' => 'numeric', 'width' => 75,  'readOnly' => true,  'align' => 'right'],
];

if (!empty($employees)) {
    $sr = 1;
    foreach ($employees as $emp) {
        $prs = (float)($emp['total_present'] ?? 0);
        $wo  = (float)($emp['total_wo'] ?? 0);
        $ext = (float)($emp['total_extra'] ?? 0);
        $ot  = (float)($emp['overtime_hours'] ?? 0);
        $paid = round($prs + $wo + $ext, 1);

        $a1 = ($emp['adv1'] !== '' && $emp['adv1'] !== null) ? (float)$emp['adv1'] : '';
        $a2 = ($emp['adv2'] !== '' && $emp['adv2'] !== null) ? (float)$emp['adv2'] : '';
        $ao = ($emp['office_advance'] !== '' && $emp['office_advance'] !== null) ? (float)$emp['office_advance'] : '';
        $ad = ($emp['dress_advance'] !== '' && $emp['dress_advance'] !== null) ? (float)$emp['dress_advance'] : '';
        $advTotal = (float)(($a1 ?: 0) + ($a2 ?: 0) + ($ao ?: 0) + ($ad ?: 0));

        $jssData[] = [
            $sr++,
            $emp['employee_code'],
            $emp['full_name'],
            $emp['designation'],
            $emp['worker_category'],
            $prs != 0 ? $prs : '',
            $wo  != 0 ? $wo  : '',
            $ext != 0 ? $ext : '',
            $ot  != 0 ? $ot  : '',
            $paid ?: 0,
            $a1,
            $a2,
            $ao,
            $ad,
            $advTotal,
        ];
    }
}
?>

<?php if ($selectedUnit && isset($_GET['load'])): ?>
<!-- Attendance & Advance Entry Grid -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-info"><?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?></span>
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
                <form method="POST" id="advanceForm">
                    <input type="hidden" name="unit_id" value="<?php echo $selectedUnit; ?>">
                    <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                    <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                    <input type="hidden" name="save_advance" value="1">

                    <div id="spreadsheet" style="width:100%;"></div>

                    <!-- Totals bar -->
                    <div id="totalsBar" style="display:flex; flex-wrap:wrap; gap:6px 14px; padding:6px 12px; background:#d9e2f3; font-size:12px; font-weight:600; border-top:2px solid #4472C4; align-items:center;">
                        <span style="margin-right:auto; font-weight:700;">TOTAL</span>
                        <span>Prs: <b id="tot-prs">0</b></span>
                        <span>WO: <b id="tot-wo">0</b></span>
                        <span>Ext: <b id="tot-ext">0</b></span>
                        <span>OT: <b id="tot-ot">0</b></span>
                        <span>Paid: <b id="tot-paid">0</b></span>
                        <span style="border-left:2px solid #8faadc; padding-left:14px;">Adv1: <b id="tot-a1">0</b></span>
                        <span>Adv2: <b id="tot-a2">0</b></span>
                        <span>Office: <b id="tot-ao">0</b></span>
                        <span>Dress: <b id="tot-ad">0</b></span>
                        <span style="background:#548235; color:#fff; padding:2px 10px; border-radius:3px;">Grand Total: <b id="tot-gtotal">0</b></span>
                    </div>

                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                <small><i class="bi bi-info-circle me-1"></i>Attendance (Present/WO/Extra/OT) + Advances saved together</small>
                            </div>
                            <div>
                                <button type="button" onclick="saveData()" class="btn btn-success">
                                    <i class="bi bi-check-lg me-1"></i>Save Attendance & Advances
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
// ── Custom CSS for jspreadsheet ──
$extraCSS = '<style>
#spreadsheet .jss { font-size: 12px; }
#spreadsheet .jss thead td,
#spreadsheet .jss thead th {
    padding: 4px 6px !important;
    font-weight: 600;
    font-size: 11px;
    text-align: center;
    white-space: nowrap;
}
#spreadsheet .jss tbody td {
    padding: 0 4px !important;
    height: 26px !important;
}
#spreadsheet .jss tbody td input {
    font-size: 12px;
    padding: 0 2px;
    height: 24px;
}
/* Highlight read-only calculated columns */
#spreadsheet .jss tbody td[data-x="9"] { background: #e2efda !important; font-weight: 600; }
#spreadsheet .jss tbody td[data-x="14"] { background: #d9e2f3 !important; font-weight: 700; }
/* Zebra striping */
#spreadsheet .jss tbody tr:nth-child(even) td { background: #f2f7fb; }
#spreadsheet .jss tbody tr:nth-child(even) td[data-x="9"] { background: #e2efda !important; }
#spreadsheet .jss tbody tr:nth-child(even) td[data-x="14"] { background: #d9e2f3 !important; }
#spreadsheet .jss tbody tr:hover td { background: #d9e8f7 !important; }
#spreadsheet .jss tbody tr:hover td[data-x="9"] { background: #c5e0b4 !important; }
#spreadsheet .jss tbody tr:hover td[data-x="14"] { background: #b4c7e7 !important; }
</style>';

// ── JavaScript ──
ob_start();
?>
<script>
// ── Client select: load units via AJAX ──
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

<?php if (!empty($employees)): ?>
// ── Jspreadsheet initialization ──
var empIds = <?php echo json_encode(array_column($employees, 'id')); ?>;
var jssData = <?php echo json_encode($jssData); ?>;
var jssCols = <?php echo json_encode($jssColumns); ?>;

var _recalc = false;

var jss = jspreadsheet(document.getElementById('spreadsheet'), {
    data: jssData,
    columns: jssCols,
    columnResize: true,
    allowExport: true,
    tableOverflow: true,
    tableHeight: '70vh',
    defaultColWidth: 80,
    onchange: function(el, cell, x, y, value) {
        // NOTE: In jspreadsheet-ce 4.9.2, 1st arg is the DOM element, not the instance
        if (_recalc) return;
        _recalc = true;

        // Recalculate Paid (col 9) when Prs/WO/Ext (5/6/7) change
        if (x === 5 || x === 6 || x === 7) {
            var p = parseFloat(jss.getValueFromCoords(5, y)) || 0;
            var w = parseFloat(jss.getValueFromCoords(6, y)) || 0;
            var e = parseFloat(jss.getValueFromCoords(7, y)) || 0;
            jss.setValueFromCoords(9, y, parseFloat((p + w + e).toFixed(1)));
        }

        // Recalculate Total (col 14) when any advance col (10-13) changes
        if (x >= 10 && x <= 13) {
            var v1 = parseFloat(jss.getValueFromCoords(10, y)) || 0;
            var v2 = parseFloat(jss.getValueFromCoords(11, y)) || 0;
            var v3 = parseFloat(jss.getValueFromCoords(12, y)) || 0;
            var v4 = parseFloat(jss.getValueFromCoords(13, y)) || 0;
            jss.setValueFromCoords(14, y, v1 + v2 + v3 + v4);
        }

        updateTotals();
        _recalc = false;
    }
});

// ── Update totals bar ──
function fmtNum(v, decimals) {
    if (decimals === undefined) decimals = 0;
    var n = parseFloat(v) || 0;
    return decimals > 0 ? n.toFixed(decimals).replace(/\.0$/, '') : n.toFixed(decimals);
}

function updateTotals() {
    var data = jss.getData();
    var tP = 0, tW = 0, tE = 0, tOT = 0, tPaid = 0;
    var tA1 = 0, tA2 = 0, tAO = 0, tAD = 0;

    for (var i = 0; i < data.length; i++) {
        tP   += parseFloat(data[i][5])  || 0;
        tW   += parseFloat(data[i][6])  || 0;
        tE   += parseFloat(data[i][7])  || 0;
        tOT  += parseFloat(data[i][8])  || 0;
        tPaid += parseFloat(data[i][9])  || 0;
        tA1  += parseFloat(data[i][10]) || 0;
        tA2  += parseFloat(data[i][11]) || 0;
        tAO  += parseFloat(data[i][12]) || 0;
        tAD  += parseFloat(data[i][13]) || 0;
    }

    document.getElementById('tot-prs').textContent  = fmtNum(tP, 1);
    document.getElementById('tot-wo').textContent   = fmtNum(tW, 1);
    document.getElementById('tot-ext').textContent  = fmtNum(tE, 1);
    document.getElementById('tot-ot').textContent   = fmtNum(tOT, 1);
    document.getElementById('tot-paid').textContent = fmtNum(tPaid, 1);
    document.getElementById('tot-a1').textContent   = fmtNum(tA1);
    document.getElementById('tot-a2').textContent   = fmtNum(tA2);
    document.getElementById('tot-ao').textContent   = fmtNum(tAO);
    document.getElementById('tot-ad').textContent   = fmtNum(tAD);
    document.getElementById('tot-gtotal').textContent = fmtNum(tA1 + tA2 + tAO + tAD);
}

updateTotals();

// ── Save: collect jss data and POST ──
function addHiddenField(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.className = 'jss-dyn';
    form.appendChild(input);
}

function saveData() {
    var data = jss.getData();
    var form = document.getElementById('advanceForm');

    form.querySelectorAll('.jss-dyn').forEach(function(el) { el.remove(); });

    for (var i = 0; i < data.length; i++) {
        var eid = empIds[i];
        addHiddenField(form, 'employee_id[]', eid);
        addHiddenField(form, 'att_present[' + eid + ']',  parseFloat(data[i][5])  || 0);
        addHiddenField(form, 'att_wo[' + eid + ']',       parseFloat(data[i][6])  || 0);
        addHiddenField(form, 'att_extra[' + eid + ']',    parseFloat(data[i][7])  || 0);
        addHiddenField(form, 'ot_hours[' + eid + ']',     parseFloat(data[i][8])  || 0);
        addHiddenField(form, 'adv1[' + eid + ']',         parseFloat(data[i][10]) || 0);
        addHiddenField(form, 'adv2[' + eid + ']',         parseFloat(data[i][11]) || 0);
        addHiddenField(form, 'office_advance[' + eid + ']', parseFloat(data[i][12]) || 0);
        addHiddenField(form, 'dress_advance[' + eid + ']',  parseFloat(data[i][13]) || 0);
    }

    form.submit();
}
<?php endif; ?>
</script>
<?php
$extraJS = ob_get_clean();
?>