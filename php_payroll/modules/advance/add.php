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
        `employee_id` varchar(36) NOT NULL,
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
    
    // Get existing advances if any
    foreach ($employees as &$emp) {
        try {
            $stmt = $db->prepare("
                SELECT adv1, adv2, office_advance, dress_advance
                FROM employee_advances
                WHERE employee_id = ? AND month = ? AND year = ?
            ");
            $stmt->execute([$emp['id'], $selectedMonth, $selectedYear]);
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
        foreach ($employeeIds as $empId) {
            $adv1 = isset($_POST['adv1'][$empId]) ? (float)$_POST['adv1'][$empId] : 0;
            $adv2 = isset($_POST['adv2'][$empId]) ? (float)$_POST['adv2'][$empId] : 0;
            $officeAdv = isset($_POST['office_advance'][$empId]) ? (float)$_POST['office_advance'][$empId] : 0;
            $dressAdv = isset($_POST['dress_advance'][$empId]) ? (float)$_POST['dress_advance'][$empId] : 0;
            
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
        
        setFlash('success', "Advances saved successfully! {$savedCount} employees updated.");

        // Redirect to same page with filters
        // Note: Using redirect() helper instead of header() to handle "headers already sent" case
        redirect("index.php?page=advance/add&client_id={$clientId}&unit_id={$unitId}&month={$month}&year={$year}&load=1");
        
    } catch (Exception $e) {
        setFlash('error', 'Error saving advances: ' . $e->getMessage());
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
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0" style="font-size: 13px;">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th style="width: 100px;">Emp Code</th>
                                    <th style="width: 180px;">Employee Name</th>
                                    <th style="width: 120px;">Designation</th>
                                    <th style="width: 100px;">Category</th>
                                    <th style="width: 100px;" class="text-center bg-primary text-white">Adv 1<br><small>(Rs)</small></th>
                                    <th style="width: 100px;" class="text-center bg-primary text-white">Adv 2<br><small>(Rs)</small></th>
                                    <th style="width: 100px;" class="text-center bg-warning text-dark">Office Adv<br><small>(Rs)</small></th>
                                    <th style="width: 100px;" class="text-center bg-info text-white">Dress Adv<br><small>(Rs)</small></th>
                                    <th style="width: 100px;" class="text-center bg-success text-white">Total<br><small>(Rs)</small></th>
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
                                        <code><?php echo $emp['employee_code']; ?></code>
                                    </td>
                                    <td><?php echo sanitize($emp['full_name']); ?></td>
                                    <td><?php echo sanitize($emp['designation']); ?></td>
                                    <td><span class="badge bg-light text-dark"><?php echo sanitize($emp['worker_category']); ?></span></td>
                                    <td>
                                        <input type="number" name="adv1[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['adv1']; ?>" 
                                               class="form-control form-control-sm text-end advance-input" 
                                               min="0" step="1" data-row="<?php echo $emp['id']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" name="adv2[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['adv2']; ?>" 
                                               class="form-control form-control-sm text-end advance-input" 
                                               min="0" step="1" data-row="<?php echo $emp['id']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" name="office_advance[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['office_advance']; ?>" 
                                               class="form-control form-control-sm text-end advance-input" 
                                               min="0" step="1" data-row="<?php echo $emp['id']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" name="dress_advance[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['dress_advance']; ?>" 
                                               class="form-control form-control-sm text-end advance-input" 
                                               min="0" step="1" data-row="<?php echo $emp['id']; ?>">
                                    </td>
                                    <td>
                                        <span class="fw-bold row-total" data-row="<?php echo $emp['id']; ?>">0</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL</td>
                                    <td class="text-end" id="total-adv1">0</td>
                                    <td class="text-end" id="total-adv2">0</td>
                                    <td class="text-end" id="total-office">0</td>
                                    <td class="text-end" id="total-dress">0</td>
                                    <td class="text-end" id="grand-total">0</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                <small><i class="bi bi-info-circle me-1"></i>Adv 1/2: Salary Advances | Office Adv: Office Advance | Dress Adv: Dress/Uniform Advance</small>
                            </div>
                            <div>
                                <button type="submit" name="save_advance" class="btn btn-success">
                                    <i class="bi bi-check-lg me-1"></i>Save Advances
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

// Calculate row totals
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
    
    document.querySelectorAll('[name^="adv1["]').forEach(input => {
        totalAdv1 += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="adv2["]').forEach(input => {
        totalAdv2 += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="office_advance["]').forEach(input => {
        totalOffice += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="dress_advance["]').forEach(input => {
        totalDress += parseFloat(input.value) || 0;
    });
    
    document.getElementById('total-adv1').textContent = totalAdv1.toFixed(0);
    document.getElementById('total-adv2').textContent = totalAdv2.toFixed(0);
    document.getElementById('total-office').textContent = totalOffice.toFixed(0);
    document.getElementById('total-dress').textContent = totalDress.toFixed(0);
    document.getElementById('grand-total').textContent = (totalAdv1 + totalAdv2 + totalOffice + totalDress).toFixed(0);
}

// Add event listeners
document.querySelectorAll('.advance-input').forEach(input => {
    input.addEventListener('input', function() {
        const rowId = this.dataset.row;
        calculateRowTotal(rowId);
        calculateColumnTotals();
    });
});

// Initial calculation
document.addEventListener('DOMContentLoaded', function() {
    calculateColumnTotals();
    document.querySelectorAll('.advance-input').forEach(input => {
        calculateRowTotal(input.dataset.row);
    });
});
</script>
JS;
?>
