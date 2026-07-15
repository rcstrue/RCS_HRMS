<?php
/**
 * RCS HRMS Pro - Quick Salary Entry (Bulk)
 * Quickly enter/edit salary for multiple employees in a compact grid format
 */

$pageTitle = 'Quick Salary Entry';

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

// Handle POST save (bulk)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_save'])) {
    $employeeIds = $_POST['employee_id'] ?? [];
    $savedCount = 0;

    try {
        $db->beginTransaction();

        foreach ($employeeIds as $empId) {
            $empId = (int)$empId;
            $basicDA = floatval($_POST['basic_da'][$empId] ?? 0);
            $hra = floatval($_POST['hra'][$empId] ?? 0);
            $washing = floatval($_POST['washing_allowance'][$empId] ?? 0);
            $otApplicable = isset($_POST['overtime_applicable'][$empId]) ? 1 : 0;
            $grossSalary = $basicDA + $hra + $washing;

            $effectiveFrom = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT) . '-01';

            // Check for existing active salary structure
            $existing = $db->fetch(
                "SELECT id, basic_da, hra, washing_allowance, gross_salary, overtime_applicable
                 FROM employee_salary_structures 
                 WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())
                 ORDER BY effective_from DESC LIMIT 1",
                [$empId]
            );

            if ($existing) {
                // Only update if values changed
                $oldBasic = floatval($existing['basic_da']);
                $oldHra = floatval($existing['hra']);
                $oldWash = floatval($existing['washing_allowance']);
                $oldGross = floatval($existing['gross_salary']);
                $oldOT = $existing['overtime_applicable'];

                if ($oldBasic != $basicDA || $oldHra != $hra || $oldWash != $washing || 
                    $oldGross != $grossSalary || $oldOT != $otApplicable) {
                    
                    $db->update('employee_salary_structures', [
                        'basic_da' => $basicDA,
                        'hra' => $hra,
                        'washing_allowance' => $washing,
                        'gross_salary' => $grossSalary,
                        'overtime_applicable' => $otApplicable
                    ], 'id = :id', ['id' => $existing['id']]);
                    $savedCount++;
                }
            } else {
                // Close previous
                $prevStructures = $db->fetchAll(
                    "SELECT id FROM employee_salary_structures WHERE employee_id = ? AND effective_to IS NULL",
                    [$empId]
                );
                foreach ($prevStructures as $prev) {
                    $db->update('employee_salary_structures', [
                        'effective_to' => date('Y-m-d', strtotime($effectiveFrom . ' -1 day'))
                    ], 'id = :id', ['id' => $prev['id']]);
                }

                $db->insert('employee_salary_structures', [
                    'employee_id' => $empId,
                    'effective_from' => $effectiveFrom,
                    'basic_da' => $basicDA,
                    'hra' => $hra,
                    'washing_allowance' => $washing,
                    'gross_salary' => $grossSalary,
                    'overtime_applicable' => $otApplicable,
                    'pf_applicable' => 1,
                    'esi_applicable' => 1,
                    'pt_applicable' => 1,
                    'created_by' => $_SESSION['user_id'] ?? null
                ]);
                $savedCount++;
            }
        }

        $db->commit();
        
        if ($savedCount > 0) {
            setFlash('success', "Quick salary updated for {$savedCount} employees.");
        } else {
            setFlash('info', 'No changes detected. All values are the same.');
        }
        redirect('index.php?page=entry/quick-salary&month=' . $monthFilter . '&year=' . $yearFilter . 
                '&client_id=' . $clientFilter . '&unit_id=' . $unitFilter . '&filter=1');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error: ' . $e->getMessage());
    }
}

// Handle AJAX single-row save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    $empId = (int)$_POST['emp_id'];
    $basicDA = floatval($_POST['basic_da'] ?? 0);
    $hra = floatval($_POST['hra'] ?? 0);
    $washing = floatval($_POST['washing_allowance'] ?? 0);
    $otApplicable = isset($_POST['overtime_applicable']) ? 1 : 0;
    $grossSalary = $basicDA + $hra + $washing;

    try {
        $effectiveFrom = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT) . '-01';
        $existing = $db->fetch(
            "SELECT id FROM employee_salary_structures 
             WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC LIMIT 1",
            [$empId]
        );

        if ($existing) {
            $db->update('employee_salary_structures', [
                'basic_da' => $basicDA, 'hra' => $hra, 'washing_allowance' => $washing,
                'gross_salary' => $grossSalary, 'overtime_applicable' => $otApplicable
            ], 'id = :id', ['id' => $existing['id']]);
        } else {
            $db->insert('employee_salary_structures', [
                'employee_id' => $empId, 'effective_from' => $effectiveFrom,
                'basic_da' => $basicDA, 'hra' => $hra, 'washing_allowance' => $washing,
                'gross_salary' => $grossSalary, 'overtime_applicable' => $otApplicable,
                'pf_applicable' => 1, 'esi_applicable' => 1, 'pt_applicable' => 1,
                'created_by' => $_SESSION['user_id'] ?? null
            ]);
        }

        echo json_encode(['success' => true, 'gross' => $grossSalary]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="quick_salary_' . $monthFilter . '_' . $yearFilter . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

    $data = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.designation,
                COALESCE(ess.basic_da,0) as basic_da, COALESCE(ess.hra,0) as hra,
                COALESCE(ess.washing_allowance,0) as washing_allowance,
                COALESCE(ess.gross_salary,0) as gross_salary, ess.overtime_applicable
         FROM employees e
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $where ORDER BY e.employee_code",
        $params
    );

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Code', 'Name', 'Designation', 'Basic+DA', 'HRA', 'Washing', 'Gross', 'OT Applicable']);
    foreach ($data as $row) {
        fputcsv($output, [
            $row['employee_code'], $row['full_name'], $row['designation'],
            $row['basic_da'], $row['hra'], $row['washing_allowance'],
            $row['gross_salary'], $row['overtime_applicable'] ? 'Yes' : 'No'
        ]);
    }
    fclose($output);
    exit;
}

// Get employees
$employees = [];
if ($filterPressed && $clientFilter) {
    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }

    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.designation,
                u.name as unit_name,
                ess.id as salary_id,
                COALESCE(ess.basic_da, 0) as basic_da,
                COALESCE(ess.hra, 0) as hra,
                COALESCE(ess.washing_allowance, 0) as washing_allowance,
                COALESCE(ess.gross_salary, 0) as gross_salary,
                ess.overtime_applicable
         FROM employees e
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $where
         ORDER BY u.name, e.employee_code",
        $params
    );
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
                    <i class="bi bi-lightning me-2"></i>Quick Salary Entry
                    <small class="text-muted ms-2">(Bulk Edit)</small>
                </h5>
                <?php if (!empty($employees)): ?>
                <div class="d-flex gap-1">
                    <a href="index.php?page=entry/quick-salary&<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download me-1"></i>CSV
                    </a>
                    <button class="btn btn-outline-info btn-sm" onclick="window.print()">
                        <i class="bi bi-printer"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-2">
                    <input type="hidden" name="page" value="entry/quick-salary">
                    
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
                        <a href="index.php?page=entry/quick-salary" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (!$filterPressed || !$clientFilter): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-lightning-charge fs-1"></i>
                <p class="mt-3">Select a <strong>Client</strong> and click <strong>Load</strong> to open the quick edit grid.</p>
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
        <!-- Quick Edit Grid -->
        <form method="POST" id="quickSalaryForm">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <div>
                        <span class="badge bg-primary"><?php echo count($employees); ?> Employees</span>
                        <span class="badge bg-dark"><?php echo $months[$monthFilter] . ' ' . $yearFilter; ?></span>
                    </div>
                    <button type="submit" name="quick_save" class="btn btn-success btn-sm"
                            onclick="return confirm('Save all salary changes?')">
                        <i class="bi bi-floppy me-1"></i>Save All
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover mb-0" style="font-size:0.8rem;">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th class="text-center" style="width:35px;">#</th>
                                    <th style="width:70px;">Code</th>
                                    <th style="min-width:170px;">Employee Name</th>
                                    <th>Designation</th>
                                    <th class="text-end" style="width:100px;background:#2b8a3e;">Current Basic+DA</th>
                                    <th class="text-end" style="width:100px;background:#2b8a3e;">Current HRA</th>
                                    <th class="text-end" style="width:100px;background:#2b8a3e;">Current Washing</th>
                                    <th class="text-center" style="width:50px;background:#2b8a3e;">OT</th>
                                    <th class="text-end" style="width:100px;border-left:2px solid #6c757d;background:#198754;">
                                        <strong>Gross</strong>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $idx => $emp): ?>
                                <tr data-emp-id="<?php echo $emp['id']; ?>">
                                    <td class="text-center text-muted"><?php echo $idx + 1; ?></td>
                                    <td>
                                        <input type="hidden" name="employee_id[]" value="<?php echo $emp['id']; ?>">
                                        <code><?php echo sanitize($emp['employee_code']); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo sanitize($emp['full_name']); ?></strong>
                                        <?php if (!$emp['salary_id']): ?>
                                        <span class="badge bg-warning text-dark" style="font-size:0.6rem;">NEW</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?php echo sanitize($emp['designation']); ?></td>
                                    <td>
                                        <input type="number" name="basic_da[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['basic_da']; ?>" 
                                               class="form-control form-control-sm text-end quick-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>"
                                               min="0" step="1" placeholder="0">
                                    </td>
                                    <td>
                                        <input type="number" name="hra[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['hra']; ?>" 
                                               class="form-control form-control-sm text-end quick-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>"
                                               min="0" step="1" placeholder="0">
                                    </td>
                                    <td>
                                        <input type="number" name="washing_allowance[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['washing_allowance']; ?>" 
                                               class="form-control form-control-sm text-end quick-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>"
                                               min="0" step="1" placeholder="0">
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="overtime_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['overtime_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-end fw-bold" style="border-left:2px solid #dee2e6;background:#f8f9fa;">
                                        <span class="row-gross" data-emp-id="<?php echo $emp['id']; ?>">
                                            <?php echo formatCurrency($emp['gross_salary']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">
                                        <span class="text-primary">TOTAL</span>
                                    </td>
                                    <td class="text-end" id="q_total_basic">0</td>
                                    <td class="text-end" id="q_total_hra">0</td>
                                    <td class="text-end" id="q_total_wash">0</td>
                                    <td></td>
                                    <td class="text-end" style="border-left:2px solid #dee2e6;" id="q_total_gross">0</td>
                                </tr>
                            </tfoot>
                        </table>
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
    .table td input[type="checkbox"] { 
        display: none !important; 
    }
    body { font-size: 8pt; }
}
.quick-input:focus {
    background-color: #fff3cd !important;
    box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.5) !important;
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

// Auto-calculate row gross and totals
document.querySelectorAll('.quick-input').forEach(input => {
    input.addEventListener('input', function() {
        const empId = this.dataset.empId;
        const row = this.closest('tr');
        const basic = parseFloat(row.querySelector('[name^="basic_da["]')?.value) || 0;
        const hra = parseFloat(row.querySelector('[name^="hra["]')?.value) || 0;
        const wash = parseFloat(row.querySelector('[name^="washing_allowance["]')?.value) || 0;
        const gross = basic + hra + wash;
        
        row.querySelector('.row-gross').textContent = '₹' + gross.toLocaleString('en-IN');
        updateQuickTotals();
    });
});

function updateQuickTotals() {
    let tBasic = 0, tHra = 0, tWash = 0, tGross = 0;
    
    document.querySelectorAll('[name^="basic_da["]').forEach(i => tBasic += parseFloat(i.value) || 0);
    document.querySelectorAll('[name^="hra["]').forEach(i => tHra += parseFloat(i.value) || 0);
    document.querySelectorAll('[name^="washing_allowance["]').forEach(i => tWash += parseFloat(i.value) || 0);
    
    tGross = tBasic + tHra + tWash;
    const fmt = v => '₹' + v.toLocaleString('en-IN');
    
    document.getElementById('q_total_basic').textContent = fmt(tBasic);
    document.getElementById('q_total_hra').textContent = fmt(tHra);
    document.getElementById('q_total_wash').textContent = fmt(tWash);
    document.getElementById('q_total_gross').textContent = fmt(tGross);
}

// Keyboard navigation (Tab between inputs, Enter moves to next row)
document.getElementById('quickSalaryForm')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const inputs = Array.from(document.querySelectorAll('.quick-input'));
        const currentIdx = inputs.indexOf(document.activeElement);
        if (currentIdx < inputs.length - 1) {
            inputs[currentIdx + 1].focus();
            inputs[currentIdx + 1].select();
        }
    }
});
</script>
