<?php
/**
 * RCS HRMS Pro - Add Advance (Manual Entry)
 * Manual advance entry like Excel sheet
 */

$pageTitle = 'Add Advance';

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

// Get employees and their advances when unit is selected
$employees = [];
if ($selectedUnit && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['load'])) {
    // Get employees for this unit with attendance summary
    $stmt = $db->prepare("
        SELECT e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
               ess.basic_da, ess.gross_salary,
               att.total_present, att.total_wo, att.total_extra, att.overtime_hours, att.total_paid_days
        FROM employees e
        LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id AND ess.effective_to IS NULL
        LEFT JOIN attendance_summary att ON e.id = att.employee_id AND att.month = ? AND att.year = ?
        WHERE e.unit_id = ? AND e.status = 'approved'
        ORDER BY e.employee_code
    ");
    $stmt->execute([$selectedMonth, $selectedYear, $selectedUnit]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get existing advances if any
    foreach ($employees as &$emp) {
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
            } else {
                $emp['adv1'] = '';
                $emp['adv2'] = '';
                $emp['office_advance'] = '';
                $emp['dress_advance'] = '';
            }
        } catch (Exception $e) {
            $emp['adv1'] = '';
            $emp['adv2'] = '';
            $emp['office_advance'] = '';
            $emp['dress_advance'] = '';
        }
    }
    unset($emp);
}

// Handle save
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

            // Upsert attendance_summary
            $stmt = $db->prepare("
                INSERT INTO attendance_summary
                (employee_id, unit_id, month, year, total_present, total_wo, total_extra, overtime_hours, total_paid_days, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    total_present = VALUES(total_present),
                    total_wo = VALUES(total_wo),
                    total_extra = VALUES(total_extra),
                    overtime_hours = VALUES(overtime_hours),
                    total_paid_days = VALUES(total_paid_days),
                    updated_at = NOW()
            ");
            $stmt->execute([$empId, $unitId, $month, $year, $attPresent, $attWO, $attExtra, $otHours, $paidDays]);

            // ── Advances ──
            $adv1 = isset($_POST['adv1'][$empId]) ? (float)$_POST['adv1'][$empId] : 0;
            $adv2 = isset($_POST['adv2'][$empId]) ? (float)$_POST['adv2'][$empId] : 0;
            $officeAdv = isset($_POST['office_advance'][$empId]) ? (float)$_POST['office_advance'][$empId] : 0;
            $dressAdv = isset($_POST['dress_advance'][$empId]) ? (float)$_POST['dress_advance'][$empId] : 0;
            
            // Validate: no negative advances
            if ($adv1 < 0 || $adv2 < 0 || $officeAdv < 0 || $dressAdv < 0) {
                $db->exec("ROLLBACK");
                setFlash('error', 'Advance amounts cannot be negative.');
                redirect("index.php?page=advance/add&client_id={$clientId}&unit_id={$unitId}&month={$month}&year={$year}&load=1");
                exit;
            }
            
            // Insert or update using ON DUPLICATE KEY
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

        // Redirect to same page with filters
        // Note: Using redirect() helper instead of header() to handle "headers already sent" case
        redirect("index.php?page=advance/add&client_id={$clientId}&unit_id={$unitId}&month={$month}&year={$year}&load=1");
        
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
                <h5 class="card-title mb-0"><i class="bi bi-cash me-2"></i>Add Advance (Manual Entry)</h5>
            </div>
            <div class="card-body">
                <!-- Filters Form -->
                <form method="GET" class="row g-3 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="advance/add">
                    
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
<!-- Advance Entry Grid -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-info"><?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?></span>
                    <span class="badge bg-primary ms-2"><?php echo count($employees); ?> Employees</span>
                </div>
                <div>
                    <a href="index.php?page=advance/add" class="btn btn-outline-secondary btn-sm">
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
                    
                    <style>
                        .excel-grid { border-collapse: collapse; font-size: 12px; width: 100%; }
                        .excel-grid th, .excel-grid td { border: 1px solid #ccc; padding: 0; height: 28px; vertical-align: middle; }
                        .excel-grid th { background: #4472C4; color: #fff; padding: 4px 6px; font-weight: 600; text-align: center; white-space: nowrap; font-size: 11px; }
                        .excel-grid th.th-att { background: #5b9bd5; }
                        .excel-grid th.th-adv { background: #4472C4; }
                        .excel-grid th.th-total { background: #548235; }
                        .excel-grid td { padding: 0 4px; }
                        .excel-grid tbody tr:nth-child(even) td { background: #f2f7fb; }
                        .excel-grid tbody tr:hover td { background: #d9e8f7 !important; }
                        .excel-grid .cell-input { width: 100%; border: none; background: transparent; text-align: center; height: 28px; padding: 0 4px; font-size: 12px; outline: none; -moz-appearance: textfield; }
                        .excel-grid .cell-input::-webkit-outer-spin-button,
                        .excel-grid .cell-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
                        .excel-grid .cell-input:focus { background: #fffde7; box-shadow: inset 0 0 0 2px #4472C4; }
                        .excel-grid .cell-input.text-end-cell { text-align: right; }
                        .excel-grid .cell-calc { display: block; padding: 0 4px; height: 28px; line-height: 28px; text-align: center; font-weight: 600; }
                        .excel-grid .cell-calc.text-end-cell { text-align: right; }
                        .excel-grid .paid-cell { background: #e2efda !important; }
                        .excel-grid .total-cell { background: #d9e2f3 !important; font-weight: 700; }
                        .excel-grid tfoot td { background: #d9e2f3; font-weight: 700; padding: 0 4px; height: 26px; }
                        .excel-grid .emp-code { font-family: monospace; font-size: 11px; color: #333; }
                        .excel-grid .emp-name { font-size: 12px; }
                        .excel-grid .badge-cat { font-size: 10px; padding: 1px 6px; }
                    </style>
                    <div class="table-responsive">
                        <table class="excel-grid mb-0">
                            <thead>
                                <tr>
                                    <th style="width:36px;">#</th>
                                    <th style="width:75px;">Code</th>
                                    <th style="width:150px;">Name</th>
                                    <th style="width:100px;">Designation</th>
                                    <th style="width:60px;">Cat</th>
                                    <th class="th-att" style="width:55px;">Prs</th>
                                    <th class="th-att" style="width:45px;">WO</th>
                                    <th class="th-att" style="width:50px;">Ext</th>
                                    <th class="th-att" style="width:50px;">OT</th>
                                    <th class="th-att" style="width:50px;">Paid</th>
                                    <th class="th-adv" style="width:80px;">Adv 1</th>
                                    <th class="th-adv" style="width:80px;">Adv 2</th>
                                    <th class="th-adv" style="width:80px;">Office</th>
                                    <th class="th-adv" style="width:80px;">Dress</th>
                                    <th class="th-total" style="width:75px;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sr = 1;
                                foreach ($employees as $emp): 
                                ?>
                                    <tr>
                                    <td class="text-center"><?php echo $sr++; ?></td>
                                    <td>
                                        <input type="hidden" name="employee_id[]" value="<?php echo $emp['id']; ?>">
                                        <span class="emp-code"><?php echo $emp['employee_code']; ?></span>
                                    </td>
                                    <td class="emp-name"><?php echo sanitize($emp['full_name']); ?></td>
                                    <td style="font-size:11px;"><?php echo sanitize($emp['designation']); ?></td>
                                    <td class="text-center"><span class="badge-cat"><?php echo sanitize($emp['worker_category']); ?></span></td>
                                    <td><input type="number" name="att_present[<?php echo $emp['id']; ?>]" value="<?php echo (float)($emp['total_present'] ?? 0) ?: ''; ?>" class="cell-input att-input" min="0" max="31" step="0.5" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td><input type="number" name="att_wo[<?php echo $emp['id']; ?>]" value="<?php echo (float)($emp['total_wo'] ?? 0) ?: ''; ?>" class="cell-input att-input" min="0" max="31" step="0.5" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td><input type="number" name="att_extra[<?php echo $emp['id']; ?>]" value="<?php echo (float)($emp['total_extra'] ?? 0) ?: ''; ?>" class="cell-input att-input" min="0" max="31" step="0.5" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td><input type="number" name="ot_hours[<?php echo $emp['id']; ?>]" value="<?php echo (float)($emp['overtime_hours'] ?? 0) ?: ''; ?>" class="cell-input att-input" min="0" max="500" step="0.5" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td class="paid-cell"><span class="cell-calc paid-days" data-row="<?php echo $emp['id']; ?>"><?php echo (float)($emp['total_paid_days'] ?? 0) ?: '0'; ?></span></td>
                                    <td><input type="number" name="adv1[<?php echo $emp['id']; ?>]" value="<?php echo $emp['adv1']; ?>" class="cell-input text-end-cell advance-input" min="0" step="1" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td><input type="number" name="adv2[<?php echo $emp['id']; ?>]" value="<?php echo $emp['adv2']; ?>" class="cell-input text-end-cell advance-input" min="0" step="1" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td><input type="number" name="office_advance[<?php echo $emp['id']; ?>]" value="<?php echo $emp['office_advance']; ?>" class="cell-input text-end-cell advance-input" min="0" step="1" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td><input type="number" name="dress_advance[<?php echo $emp['id']; ?>]" value="<?php echo $emp['dress_advance']; ?>" class="cell-input text-end-cell advance-input" min="0" step="1" data-row="<?php echo $emp['id']; ?>"></td>
                                    <td class="total-cell"><span class="cell-calc text-end-cell row-total" data-row="<?php echo $emp['id']; ?>">0</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" style="text-align:right;">TOTAL</td>
                                    <td style="text-align:right;" id="total-present">0</td>
                                    <td style="text-align:right;" id="total-wo">0</td>
                                    <td style="text-align:right;" id="total-extra">0</td>
                                    <td style="text-align:right;" id="total-ot">0</td>
                                    <td style="text-align:right;" id="total-paid">0</td>
                                    <td style="text-align:right;" id="total-adv1">0</td>
                                    <td style="text-align:right;" id="total-adv2">0</td>
                                    <td style="text-align:right;" id="total-office">0</td>
                                    <td style="text-align:right;" id="total-dress">0</td>
                                    <td style="text-align:right;" id="grand-total">0</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                <small><i class="bi bi-info-circle me-1"></i>Attendance (Present/WO/Extra/OT) + Advances saved together to attendance_summary & employee_advances</small>
                            </div>
                            <div>
                                <button type="submit" name="save_advance" class="btn btn-success">
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
$extraJS = <<<'JS'
<script>
// Load units when client changes
document.getElementById('clientSelect').addEventListener('change', function() {
    const clientId = this.value;
    const unitSelect = document.getElementById('unitSelect');
    
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!clientId) {
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        return;
    }
    
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(response => response.json())
        .then(data => {
            unitSelect.innerHTML = '<option value="">Select Unit</option>';
            if (data.units) {
                data.units.forEach(unit => {
                    const option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
        })
        .catch(() => {
            unitSelect.innerHTML = '<option value="">Select Unit</option>';
        });
});

// Auto-calculate Paid Days = Present + WO + Extra
function updatePaidDays(rowId) {
    const p = parseFloat(document.querySelector('[name="att_present[' + rowId + ']"]').value) || 0;
    const w = parseFloat(document.querySelector('[name="att_wo[' + rowId + ']"]').value) || 0;
    const e = parseFloat(document.querySelector('[name="att_extra[' + rowId + ']"]').value) || 0;
    document.querySelector('.paid-days[data-row="' + rowId + '"]').textContent = (p + w + e).toFixed(1).replace(/\.0$/, '');
}

// Calculate advance row totals
function calculateRowTotal(rowId) {
    const inputs = document.querySelectorAll('.advance-input[data-row="' + rowId + '"]');
    let total = 0;
    inputs.forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.querySelector('.row-total[data-row="' + rowId + '"]').textContent = total.toFixed(0);
}

// Calculate column totals
function calculateColumnTotals() {
    let totalAdv1 = 0, totalAdv2 = 0, totalOffice = 0, totalDress = 0;
    let totalPresent = 0, totalWO = 0, totalExtra = 0, totalOT = 0, totalPaid = 0;
    
    document.querySelectorAll('[name^="adv1["]').forEach(input => { totalAdv1 += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="adv2["]').forEach(input => { totalAdv2 += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="office_advance["]').forEach(input => { totalOffice += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="dress_advance["]').forEach(input => { totalDress += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="att_present["]').forEach(input => { totalPresent += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="att_wo["]').forEach(input => { totalWO += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="att_extra["]').forEach(input => { totalExtra += parseFloat(input.value) || 0; });
    document.querySelectorAll('[name^="ot_hours["]').forEach(input => { totalOT += parseFloat(input.value) || 0; });
    totalPaid = totalPresent + totalWO + totalExtra;

    document.getElementById('total-present').textContent = totalPresent.toFixed(1).replace(/\.0$/, '');
    document.getElementById('total-wo').textContent = totalWO.toFixed(1).replace(/\.0$/, '');
    document.getElementById('total-extra').textContent = totalExtra.toFixed(1).replace(/\.0$/, '');
    document.getElementById('total-ot').textContent = totalOT.toFixed(1).replace(/\.0$/, '');
    document.getElementById('total-paid').textContent = totalPaid.toFixed(1).replace(/\.0$/, '');
    document.getElementById('total-adv1').textContent = totalAdv1.toFixed(0);
    document.getElementById('total-adv2').textContent = totalAdv2.toFixed(0);
    document.getElementById('total-office').textContent = totalOffice.toFixed(0);
    document.getElementById('total-dress').textContent = totalDress.toFixed(0);
    document.getElementById('grand-total').textContent = (totalAdv1 + totalAdv2 + totalOffice + totalDress).toFixed(0);
}

// Add event listeners
document.querySelectorAll('.att-input').forEach(input => {
    input.addEventListener('input', function() {
        const rowId = this.dataset.row;
        updatePaidDays(rowId);
        calculateColumnTotals();
    });
});
document.querySelectorAll('.advance-input').forEach(input => {
    input.addEventListener('input', function() {
        const rowId = this.dataset.row;
        calculateRowTotal(rowId);
        calculateColumnTotals();
    });
});

// Initial calculation
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.att-input').forEach(input => {
        updatePaidDays(input.dataset.row);
    });
    calculateColumnTotals();
    document.querySelectorAll('.advance-input').forEach(input => {
        calculateRowTotal(input.dataset.row);
    });

    // Excel-like Tab/Enter navigation between cells
    document.querySelector('.excel-grid').addEventListener('keydown', function(e) {
        if (e.key !== 'Tab' && e.key !== 'Enter') return;
        var inputs = Array.from(this.querySelectorAll('.cell-input'));
        var idx = inputs.indexOf(e.target);
        if (idx === -1) return;
        e.preventDefault();
        var next = e.shiftKey ? idx - 1 : idx + 1;
        if (next >= 0 && next < inputs.length) inputs[next].focus().select();
    });
});
</script>
JS;
?>
