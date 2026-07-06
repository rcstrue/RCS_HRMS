<?php
/**
 * RCS HRMS Pro - Overtime Entry
 * Enter overtime hours for employees manually (in addition to attendance upload)
 */

$pageTitle = 'Overtime Entry';

// Get filter values
$currentMonth = prev_month_num();
$currentYear = date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
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

// Handle POST save overtime
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ot'])) {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $month = (int)($_POST['month'] ?? $monthFilter);
    $year = (int)($_POST['year'] ?? $yearFilter);
    $employeeIds = $_POST['employee_id'] ?? [];
    $savedCount = 0;

    try {
        $db->beginTransaction();

        foreach ($employeeIds as $empId) {
            $empId = (int)$empId;
            $otHours = isset($_POST['overtime_hours'][$empId]) ? (float)$_POST['overtime_hours'][$empId] : 0;

            // Check if attendance_summary record exists
            $existing = $db->fetch(
                "SELECT id, overtime_hours FROM attendance_summary 
                 WHERE employee_id = ? AND month = ? AND year = ?",
                [$empId, $month, $year]
            );

            if ($existing) {
                $db->update('attendance_summary', [
                    'overtime_hours' => $otHours
                ], 'id = :id', ['id' => $existing['id']]);
            } else {
                // Create a minimal attendance_summary record
                $db->query(
                    "INSERT INTO attendance_summary 
                     (employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, total_paid_days, source)
                     VALUES (?, ?, ?, ?, 0, 0, ?, 0, 0, 'Manual OT Entry')
                     ON DUPLICATE KEY UPDATE overtime_hours = VALUES(overtime_hours), source = 'Manual OT Entry'",
                    [$empId, $unitId, $month, $year, $otHours]
                );
            }
            $savedCount++;
        }

        $db->commit();
        setFlash('success', "Overtime hours saved for {$savedCount} employees.");
        redirect('index.php?page=entry/overtime-entry&month=' . $month . '&year=' . $year . 
                '&client_id=' . $clientFilter . '&unit_id=' . $unitFilter . '&filter=1');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error saving overtime: ' . $e->getMessage());
    }
}

// Handle AJAX single-row save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_ot_save'])) {
    header('Content-Type: application/json');
    $empId = (int)$_POST['emp_id'];
    $otHours = (float)($_POST['overtime_hours'] ?? 0);
    $month = (int)($_POST['month'] ?? $monthFilter);
    $year = (int)($_POST['year'] ?? $yearFilter);
    $unitId = (int)($_POST['unit_id'] ?? 0);

    try {
        $existing = $db->fetch(
            "SELECT id FROM attendance_summary WHERE employee_id = ? AND month = ? AND year = ?",
            [$empId, $month, $year]
        );

        if ($existing) {
            $db->update('attendance_summary', [
                'overtime_hours' => $otHours
            ], 'id = :id', ['id' => $existing['id']]);
        } else {
            $db->query(
                "INSERT INTO attendance_summary 
                 (employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, total_paid_days, source)
                 VALUES (?, ?, ?, ?, 0, 0, ?, 0, 0, 'Manual OT Entry')",
                [$empId, $unitId, $month, $year, $otHours]
            );
        }

        // Get OT rate for estimated amount
        $salaryInfo = $db->fetch(
            "SELECT ess.basic_da, ess.gross_salary, ess.overtime_applicable
             FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
             WHERE e.id = ?",
            [$empId]
        );
        
        // Standard OT calculation: (Basic/26/8)*2 * OT hours
        $basicDA = floatval($salaryInfo['basic_da'] ?? 0);
        $otRate = round(($basicDA / 26 / 8) * 2, 2);
        $otAmount = round($otRate * $otHours, 2);

        echo json_encode(['success' => true, 'ot_hours' => $otHours, 'ot_rate' => $otRate, 'ot_amount' => $otAmount]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="overtime_entry_' . $monthFilter . '_' . $yearFilter . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

    $data = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.designation,
                COALESCE(ats.overtime_hours, 0) as overtime_hours,
                COALESCE(ess.basic_da, 0) as basic_da,
                ess.overtime_applicable,
                ROUND((COALESCE(ess.basic_da,0)/26/8)*2, 2) as ot_rate
         FROM employees e
         LEFT JOIN attendance_summary ats ON ats.employee_id = e.id 
            AND ats.month = ? AND ats.year = ?
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $where
         ORDER BY e.employee_code",
        array_merge([$monthFilter, $yearFilter], $params)
    );

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Code', 'Name', 'Designation', 'OT Hours', 'OT Rate', 'OT Amount', 'OT Applicable']);
    foreach ($data as $row) {
        $otRate = floatval($row['ot_rate']);
        $otHours = floatval($row['overtime_hours']);
        fputcsv($output, [
            $row['employee_code'], $row['full_name'], $row['designation'],
            $otHours, $otRate, round($otRate * $otHours, 2),
            $row['overtime_applicable'] ? 'Yes' : 'No'
        ]);
    }
    fclose($output);
    exit;
}

// Get employees with OT data
$employees = [];
$summaryTotals = ['total_employees' => 0, 'total_ot_hours' => 0, 'total_est_amount' => 0];

if ($filterPressed && $clientFilter) {
    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
                u.name as unit_name,
                COALESCE(ats.overtime_hours, 0) as current_ot_hours,
                COALESCE(ess.basic_da, 0) as basic_da,
                COALESCE(ess.gross_salary, 0) as gross_salary,
                ess.overtime_applicable,
                ROUND((COALESCE(ess.basic_da,0)/26/8)*2, 2) as ot_rate,
                COALESCE(ats.total_present, 0) as total_present
         FROM employees e
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN attendance_summary ats ON ats.employee_id = e.id 
            AND ats.month = ? AND ats.year = ?
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $where
         ORDER BY u.name, e.employee_code",
        array_merge([$monthFilter, $yearFilter], $params)
    );

    // Calculate summary
    $summaryTotals['total_employees'] = count($employees);
    $totalOT = 0;
    $totalEstAmount = 0;
    foreach ($employees as $emp) {
        $totalOT += floatval($emp['current_ot_hours']);
        $totalEstAmount += floatval($emp['ot_rate']) * floatval($emp['current_ot_hours']);
    }
    $summaryTotals['total_ot_hours'] = $totalOT;
    $summaryTotals['total_est_amount'] = $totalEstAmount;
}

// Get OT rate formula from unit_salary_formulas if exists
$otFormula = null;
try {
    if ($unitFilter) {
        $otFormula = $db->fetch(
            "SELECT * FROM unit_salary_formulas WHERE unit_id = ? AND formula_type = 'overtime' LIMIT 1",
            [$unitFilter]
        );
    }
} catch (Exception $e) {
    // Table might not exist
}

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
                    <i class="bi bi-clock-history me-2"></i>Overtime Entry
                </h5>
                <?php if (!empty($employees)): ?>
                <a href="index.php?page=entry/overtime-entry&<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                   class="btn btn-outline-success btn-sm">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-2">
                    <input type="hidden" name="page" value="entry/overtime-entry">
                    
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
                    
                    <div class="col-md-3">
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
                    
                    <div class="col-md-3 d-flex align-items-end gap-1">
                        <button type="submit" name="filter" value="1" class="btn btn-primary btn-sm flex-grow-1">
                            <i class="bi bi-search me-1"></i>Load
                        </button>
                        <a href="index.php?page=entry/overtime-entry" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (!$filterPressed || !$clientFilter): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-clock fs-1"></i>
                <p class="mt-3">Select a <strong>Client</strong> and click <strong>Load</strong> to enter overtime hours.</p>
            </div>
        </div>
        
        <?php elseif (empty($employees)): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mt-3">No approved employees found for selected filters.</p>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <div class="card bg-light">
                    <div class="card-body py-2 text-center">
                        <small class="text-muted">Total Employees</small>
                        <h5 class="mb-0"><?php echo number_format($summaryTotals['total_employees']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning bg-opacity-10">
                    <div class="card-body py-2 text-center">
                        <small class="text-warning">Total OT Hours</small>
                        <h5 class="mb-0 text-warning"><?php echo number_format($summaryTotals['total_ot_hours'], 1); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success bg-opacity-10">
                    <div class="card-body py-2 text-center">
                        <small class="text-success">Estimated OT Amount</small>
                        <h5 class="mb-0 text-success"><?php echo formatCurrency($summaryTotals['total_est_amount']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info bg-opacity-10">
                    <div class="card-body py-2 text-center">
                        <small class="text-info">OT Rate Formula</small>
                        <h5 class="mb-0 text-info" style="font-size: 0.85rem;">
                            <?php if ($otFormula): ?>
                            <?php echo sanitize($otFormula['formula_name'] ?? 'Custom'); ?>
                            <?php else: ?>
                            (Basic/26/8) × 2
                            <?php endif; ?>
                        </h5>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- OT Entry Form -->
        <form method="POST" id="otForm">
            <input type="hidden" name="month" value="<?php echo $monthFilter; ?>">
            <input type="hidden" name="year" value="<?php echo $yearFilter; ?>">
            <input type="hidden" name="unit_id" value="<?php echo $unitFilter; ?>">
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <div>
                        <span class="badge bg-primary"><?php echo count($employees); ?> Employees</span>
                        <span class="badge bg-dark"><?php echo $months[$monthFilter] . ' ' . $yearFilter; ?></span>
                    </div>
                    <button type="button" id="saveBtn" class="btn btn-success btn-sm"
                            onclick="return confirm('Save overtime hours for all employees?')">
                        <i class="bi bi-floppy me-1"></i>Save OT Hours
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="overtimeSpreadsheet"></div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            OT Rate = (Basic+DA ÷ 26 ÷ 8) × 2 per hour.
                            <?php if ($otFormula): ?>
                            Custom formula: <?php echo sanitize($otFormula['formula_description'] ?? 'N/A'); ?>
                            <?php endif; ?>
                        </small>
                        <button type="button" id="saveBtnFooter" class="btn btn-success"
                                onclick="return confirm('Save overtime hours for all employees?')">
                            <i class="bi bi-floppy me-1"></i>Save OT Hours
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form { display: none !important; }
    body { font-size: 8pt; }
}
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

<?php if (!empty($employees)): ?>
// Build employee IDs array for save
var empIds = [
    <?php foreach ($employees as $emp): ?>
    <?php echo (int)$emp['id']; ?>,
    <?php endforeach; ?>
];

// Build jspreadsheet data
var data = [
    <?php
    $sr = 0;
    foreach ($employees as $emp):
        $sr++;
        $otRate = floatval($emp['ot_rate']);
        $currentOT = floatval($emp['current_ot_hours']);
        $estAmount = $otRate * $currentOT;
    ?>
    ['<?php echo $sr; ?>', '<?php echo addslashes($emp['employee_code']); ?>', '<?php echo addslashes($emp['full_name']); ?>', '<?php echo addslashes($emp['designation']); ?>', '<?php echo addslashes($emp['worker_category'] ?? ''); ?>', '<?php echo number_format($otRate, 2); ?>', <?php echo $currentOT > 0 ? $currentOT : "''"; ?>, '<?php echo formatCurrency($estAmount); ?>'],
    <?php endforeach; ?>
];

// Currency formatter
function fmtCurrency(v) {
    return '₹' + Math.round(v).toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

var sheetEl = document.getElementById('overtimeSpreadsheet');

var jspreadsheetInstance = jspreadsheet(sheetEl, {
    data: data,
    columns: [
        { title: '#', type: 'text', width: 36, readOnly: true },
        { title: 'Code', type: 'text', width: 75, readOnly: true },
        { title: 'Name', type: 'text', width: 150, readOnly: true },
        { title: 'Desig', type: 'text', width: 100, readOnly: true },
        { title: 'Category', type: 'text', width: 60, readOnly: true },
        { title: 'OT Rate', type: 'text', width: 70, readOnly: true },
        { title: 'OT Hours', type: 'numeric', width: 80, readOnly: false, mask: '#,##0.0' },
        { title: 'Est. Amount', type: 'text', width: 90, readOnly: true }
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
    minDimensions: [8, 0],
    noHyperlinks: true,
    onchange: function(instance, cell, x, y, value) {
        if (x === 6) {
            // OT Hours changed — recalculate Est. Amount
            var sheetData = instance.getData();
            var otRate = parseFloat(sheetData[y][5]) || 0;
            var otHours = parseFloat(value) || 0;
            var amount = otRate * otHours;
            instance.setValueFromCoords(7, y, fmtCurrency(amount));
        }
    }
});

// Save handler: collect jspreadsheet data and build POST arrays
function saveOvertime() {
    var form = document.getElementById('otForm');
    var sheetData = jspreadsheetInstance.getData();

    // Remove old hidden inputs (except unit_id, month, year)
    var oldInputs = form.querySelectorAll('input[name^="employee_id"], input[name^="overtime_hours"]');
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

        // overtime_hours[empId]
        var inpOT = document.createElement('input');
        inpOT.type = 'hidden';
        inpOT.name = 'overtime_hours[' + empId + ']';
        inpOT.value = (row[6] !== '' && row[6] !== null) ? row[6] : 0;
        form.appendChild(inpOT);
    }

    // Add save_ot flag and submit
    var saveFlag = document.createElement('input');
    saveFlag.type = 'hidden';
    saveFlag.name = 'save_ot';
    saveFlag.value = '1';
    form.appendChild(saveFlag);

    form.submit();
}

document.getElementById('saveBtn').addEventListener('click', function(e) {
    e.preventDefault();
    saveOvertime();
});

document.getElementById('saveBtnFooter').addEventListener('click', function(e) {
    e.preventDefault();
    saveOvertime();
});
<?php endif; ?>
</script>