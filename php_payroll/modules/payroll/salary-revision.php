<?php
/**
 * RCS HRMS Pro - Salary Revision Module
 * Redesigned with Excel-like editable grid
 * 
 * Features:
 * - Client/Unit filter for Single Revision
 * - Month selection for effective date
 * - Excel-like editable salary grid
 * - Auto-calculation of Gross, Deductions, Net
 * - Bulk revision with Excel template
 */

$pageTitle = 'Salary Revision';

// Check permissions
if (!in_array($_SESSION['role_code'] ?? '', ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

// Get filters
$filterClientId = (int)($_GET['client_id'] ?? 0);
$filterUnitId = (int)($_GET['unit_id'] ?? 0);
$filterMonth = (int)($_GET['month'] ?? prev_month_num());
$filterYear = (int)($_GET['year'] ?? date('Y'));

// Get clients
$clients = $db->fetchAll(
    "SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name"
);

// Get units based on client
$units = [];
if ($filterClientId) {
    $units = $db->fetchAll(
        "SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name",
        [$filterClientId]
    );
}

// Get employees with salary data
$employees = [];
if ($filterUnitId) {
    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.designation, e.worker_category,
                e.client_id, e.unit_id,
                c.name as client_name, u.name as unit_name,
                ess.id as salary_id, ess.basic_da, ess.hra,
                ess.leave_encashment, ess.bonus_encashment, ess.washing_allowance,
                ess.gross_salary,
                ess.pf_applicable, ess.esi_applicable, ess.pt_applicable, ess.lwf_applicable,
                ess.bonus_applicable, ess.gratuity_applicable, ess.overtime_applicable
         FROM employees e
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.unit_id = ? AND e.status = 'approved'
         ORDER BY e.employee_code",
        [$filterUnitId]
    );
}

// Handle Single Revision Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_single_revision'])) {
    $effectiveMonth = (int)$_POST['effective_month'];
    $effectiveYear = (int)$_POST['effective_year'];
    $effectiveFrom = "{$effectiveYear}-{$effectiveMonth}-01";
    $employeeIds = $_POST['employee_id'] ?? [];
    
    $savedCount = 0;
    $errors = [];
    
    try {
        $db->beginTransaction();
        
        foreach ($employeeIds as $empId) {
            $basicDa = (float)($_POST['basic_da'][$empId] ?? 0);
            $hra = (float)($_POST['hra'][$empId] ?? 0);
            $leaveEncashment = (float)($_POST['leave_encashment'][$empId] ?? 0);
            $bonusEncashment = (float)($_POST['bonus_encashment'][$empId] ?? 0);
            $washingAllowance = (float)($_POST['washing_allowance'][$empId] ?? 0);
            
            // Calculate gross
            $grossSalary = $basicDa + $hra + $leaveEncashment + $bonusEncashment + $washingAllowance;
            
            // Statutory checkboxes
            $pfApplicable = isset($_POST['pf_applicable'][$empId]) ? 1 : 0;
            $esiApplicable = isset($_POST['esi_applicable'][$empId]) ? 1 : 0;
            $ptApplicable = isset($_POST['pt_applicable'][$empId]) ? 1 : 0;
            $lwfApplicable = isset($_POST['lwf_applicable'][$empId]) ? 1 : 0;
            $bonusApplicable = isset($_POST['bonus_applicable'][$empId]) ? 1 : 0;
            $gratuityApplicable = isset($_POST['gratuity_applicable'][$empId]) ? 1 : 0;
            $overtimeApplicable = isset($_POST['overtime_applicable'][$empId]) ? 1 : 0;
            
            // Check if salary structure exists
            $existingSalary = $db->fetch(
                "SELECT id, gross_salary FROM employee_salary_structures 
                 WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())",
                [$empId]
            );
            
            if ($existingSalary) {
                // Close existing structure
                $db->update('employee_salary_structures', [
                    'effective_to' => date('Y-m-d', strtotime($effectiveFrom . ' -1 day'))
                ], 'id = :id', ['id' => $existingSalary['id']]);
                
                // Log revision if gross changed
                if ($grossSalary != $existingSalary['gross_salary']) {
                    $db->insert('salary_revisions', [
                        'employee_id' => $empId,
                        'old_basic_da' => $existingSalary['basic_da'] ?? 0,
                        'new_basic_da' => $basicDa,
                        'old_gross' => $existingSalary['gross_salary'] ?? 0,
                        'new_gross' => $grossSalary,
                        'revision_type' => 'fixed',
                        'effective_from' => $effectiveFrom,
                        'reason' => 'Manual revision via Salary Revision page',
                        'revision_by' => $_SESSION['user_id'] ?? 1,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            // Insert new salary structure
            $db->insert('employee_salary_structures', [
                'employee_id' => $empId,
                'effective_from' => $effectiveFrom,
                'basic_da' => $basicDa,
                'hra' => $hra,
                'leave_encashment' => $leaveEncashment,
                'bonus_encashment' => $bonusEncashment,
                'washing_allowance' => $washingAllowance,
                'gross_salary' => $grossSalary,
                'pf_applicable' => $pfApplicable,
                'esi_applicable' => $esiApplicable,
                'pt_applicable' => $ptApplicable,
                'lwf_applicable' => $lwfApplicable,
                'bonus_applicable' => $bonusApplicable,
                'gratuity_applicable' => $gratuityApplicable,
                'overtime_applicable' => $overtimeApplicable,
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            $savedCount++;
        }
        
        $db->commit();
        setFlash('success', "Salary revised successfully! {$savedCount} employees updated.");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    
    redirect("index.php?page=payroll/salary-revision&client_id={$filterClientId}&unit_id={$filterUnitId}&month={$filterMonth}&year={$filterYear}");
}

// Handle Bulk Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $effectiveMonth = (int)$_POST['bulk_month'];
    $effectiveYear = (int)$_POST['bulk_year'];
    $effectiveFrom = "{$effectiveYear}-{$effectiveMonth}-01";
    
    if (isset($_FILES['salary_file']) && $_FILES['salary_file']['error'] === UPLOAD_ERR_OK) {
        $filePath = $_FILES['salary_file']['tmp_name'];
        $handle = fopen($filePath, 'r');
        
        if ($handle) {
            $header = fgetcsv($handle); // Skip header row
            $savedCount = 0;
            $errors = [];
            $rowNum = 1;
            
            try {
                $db->beginTransaction();
                
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    
                    if (count($row) < 8) {
                        $errors[] = "Row {$rowNum}: Insufficient columns";
                        continue;
                    }
                    
                    $employeeCode = trim($row[0]);
                    
                    // Find employee by code
                    $emp = $db->fetch(
                        "SELECT id FROM employees WHERE employee_code = ? AND status = 'approved'",
                        [$employeeCode]
                    );
                    
                    if (!$emp) {
                        $errors[] = "Row {$rowNum}: Employee code '{$employeeCode}' not found";
                        continue;
                    }
                    
                    $basicDa = (float)($row[1] ?? 0);
                    $hra = (float)($row[2] ?? 0);
                    $leaveEncashment = (float)($row[3] ?? 0);
                    $bonusEncashment = (float)($row[4] ?? 0);
                    $washingAllowance = (float)($row[5] ?? 0);
                    $pfApplicable = (int)($row[6] ?? 1);
                    $esiApplicable = (int)($row[7] ?? 1);
                    $ptApplicable = (int)($row[8] ?? 1);
                    $lwfApplicable = (int)($row[9] ?? 1);
                    
                    $grossSalary = $basicDa + $hra + $leaveEncashment + $bonusEncashment + $washingAllowance;
                    
                    // Close existing salary
                    $existingSalary = $db->fetch(
                        "SELECT id, gross_salary FROM employee_salary_structures 
                         WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())",
                        [$emp['id']]
                    );
                    
                    if ($existingSalary) {
                        $db->update('employee_salary_structures', [
                            'effective_to' => date('Y-m-d', strtotime($effectiveFrom . ' -1 day'))
                        ], 'id = :id', ['id' => $existingSalary['id']]);
                    }
                    
                    // Insert new
                    $db->insert('employee_salary_structures', [
                        'employee_id' => $emp['id'],
                        'effective_from' => $effectiveFrom,
                        'basic_da' => $basicDa,
                        'hra' => $hra,
                        'leave_encashment' => $leaveEncashment,
                        'bonus_encashment' => $bonusEncashment,
                        'washing_allowance' => $washingAllowance,
                        'gross_salary' => $grossSalary,
                        'pf_applicable' => $pfApplicable,
                        'esi_applicable' => $esiApplicable,
                        'pt_applicable' => $ptApplicable,
                        'lwf_applicable' => $lwfApplicable,
                        'created_by' => $_SESSION['user_id'] ?? null,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    // Log revision
                    $db->insert('salary_revisions', [
                        'employee_id' => $emp['id'],
                        'old_gross' => $existingSalary['gross_salary'] ?? 0,
                        'new_gross' => $grossSalary,
                        'revision_type' => 'bulk_update',
                        'effective_from' => $effectiveFrom,
                        'reason' => 'Bulk upload via Excel',
                        'revision_by' => $_SESSION['user_id'] ?? 1,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $savedCount++;
                }
                
                $db->commit();
                
                if ($savedCount > 0) {
                    setFlash('success', "Bulk upload successful! {$savedCount} employees updated.");
                }
                if (!empty($errors)) {
                    setFlash('warning', "Some rows had errors: " . implode('; ', array_slice($errors, 0, 5)));
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('error', 'Upload failed: ' . $e->getMessage());
            }
            
            fclose($handle);
        } else {
            setFlash('error', 'Could not read uploaded file');
        }
    } else {
        setFlash('error', 'Please select a file to upload');
    }
    
    redirect('index.php?page=payroll/salary-revision');
}

// Get revision history
$revisions = $db->fetchAll(
    "SELECT sr.*, e.employee_code, e.full_name, c.name as client_name, u.name as unit_name
     FROM salary_revisions sr
     JOIN employees e ON sr.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     ORDER BY sr.created_at DESC
     LIMIT 100"
);

// PF/ESI rates for display
$pfRates = $db->fetch("SELECT * FROM pf_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
$esiRates = $db->fetch("SELECT * FROM esi_rates WHERE is_active = 1 ORDER BY effective_from DESC LIMIT 1");
?>

<div class="row">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-graph-up-arrow me-2"></i>Salary Revision
                </h5>
                <p class="text-muted mb-0 small">Update employee salary structures - changes will be effective from selected month</p>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="revisionTabs">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#singleRevision">
                    <i class="bi bi-table me-1"></i>Single Revision (Grid)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#bulkRevision">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Bulk Upload (Excel)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#history">
                    <i class="bi bi-clock-history me-1"></i>History
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Single Revision Tab -->
            <div class="tab-pane fade show active" id="singleRevision">
                <!-- Filters -->
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-3" id="filterForm">
                            <input type="hidden" name="page" value="payroll/salary-revision">
                            
                            <div class="col-md-3">
                                <label class="form-label">Client</label>
                                <select class="form-select" name="client_id" id="clientSelect" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filterClientId == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($c['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Unit</label>
                                <select class="form-select" name="unit_id" id="unitSelect">
                                    <option value="">Select Unit</option>
                                    <?php foreach ($units as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $filterUnitId == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($u['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Effective Month</label>
                                <select class="form-select" name="month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $filterMonth == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Effective Year</label>
                                <select class="form-select" name="year">
                                    <?php for ($y = date('Y'); $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search me-1"></i>Load
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if ($filterUnitId && !empty($employees)): ?>
                <!-- Current PF/ESI Rates Info -->
                <div class="alert alert-info py-2 mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>PF Rate:</strong> <?php echo $pfRates['employee_share'] ?? 12; ?>% (Employee) | 
                            Ceiling: ₹<?php echo number_format($pfRates['wage_ceiling'] ?? 15000); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>ESI Rate:</strong> <?php echo $esiRates['employee_share'] ?? 0.75; ?>% (Employee) | 
                            Ceiling: ₹<?php echo number_format($esiRates['wage_ceiling'] ?? 21000); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Professional Tax:</strong> As per state rules
                        </div>
                    </div>
                </div>
                
                <!-- Salary Grid -->
                <form method="POST" id="salaryForm">
                    <input type="hidden" name="save_single_revision" value="1">
                    <input type="hidden" name="effective_month" value="<?php echo $filterMonth; ?>">
                    <input type="hidden" name="effective_year" value="<?php echo $filterYear; ?>">
                    
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="bi bi-table me-2"></i>Salary Structure - <?php echo date('F Y', mktime(0, 0, 0, $filterMonth, 1, $filterYear)); ?>
                            </h6>
                            <span class="badge bg-primary"><?php echo count($employees); ?> Employees</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm mb-0" style="font-size: 12px;">
                                    <thead class="table-dark">
                                        <tr>
                                            <th rowspan="2" style="vertical-align: middle;">#</th>
                                            <th rowspan="2" style="vertical-align: middle;">Emp Code</th>
                                            <th rowspan="2" style="vertical-align: middle;">Employee Name</th>
                                            <th rowspan="2" style="vertical-align: middle;">Category</th>
                                            <th colspan="5" class="text-center bg-success">EARNINGS (Editable)</th>
                                            <th rowspan="2" class="text-center bg-info" style="vertical-align: middle;">GROSS<br><small>(Auto)</small></th>
                                            <th colspan="4" class="text-center bg-warning">STATUTORY (Toggle)</th>
                                            <th rowspan="2" class="text-center bg-danger" style="vertical-align: middle;">PF<br><small>(Auto)</small></th>
                                            <th rowspan="2" class="text-center bg-danger" style="vertical-align: middle;">ESI<br><small>(Auto)</small></th>
                                            <th rowspan="2" class="text-center bg-danger" style="vertical-align: middle;">Total<br>Ded<br><small>(Auto)</small></th>
                                            <th rowspan="2" class="text-center bg-success" style="vertical-align: middle;">NET<br>SALARY<br><small>(Auto)</small></th>
                                        </tr>
                                        <tr class="table-secondary">
                                            <th class="text-center">Basic + DA</th>
                                            <th class="text-center">HRA</th>
                                            <th class="text-center">Leave Encash.</th>
                                            <th class="text-center">Bonus Encash.</th>
                                            <th class="text-center">Washing</th>
                                            <th class="text-center">PF</th>
                                            <th class="text-center">ESI</th>
                                            <th class="text-center">PT</th>
                                            <th class="text-center">LWF</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $sr = 1;
                                        foreach ($employees as $emp): 
                                            $basicDa = (float)($emp['basic_da'] ?? 0);
                                            $hra = (float)($emp['hra'] ?? 0);
                                            $leaveEncashment = (float)($emp['leave_encashment'] ?? 0);
                                            $bonusEncashment = (float)($emp['bonus_encashment'] ?? 0);
                                            $washingAllowance = (float)($emp['washing_allowance'] ?? 0);
                                            $gross = $basicDa + $hra + $leaveEncashment + $bonusEncashment + $washingAllowance;
                                            
                                            // Calculate PF (12% of Basic+DA, max on 15000)
                                            $pfBase = min($basicDa, 15000);
                                            $pf = ($emp['pf_applicable'] ?? 0) ? round($pfBase * 0.12, 2) : 0;
                                            
                                            // Calculate ESI (0.75% of Gross if gross <= 21000)
                                            $esi = ($emp['esi_applicable'] ?? 0) && $gross <= 21000 ? round($gross * 0.0075, 2) : 0;
                                            
                                            // PT (simplified - could be state-specific)
                                            $pt = ($emp['pt_applicable'] ?? 0) ? ($gross > 15000 ? 200 : 0) : 0;
                                            
                                            $totalDed = $pf + $esi + $pt;
                                            $netSalary = $gross - $totalDed;
                                        ?>
                                        <tr data-row="<?php echo $sr; ?>">
                                            <td class="text-center"><?php echo $sr++; ?></td>
                                            <td>
                                                <input type="hidden" name="employee_id[]" value="<?php echo $emp['id']; ?>">
                                                <code><?php echo $emp['employee_code']; ?></code>
                                            </td>
                                            <td><?php echo sanitize($emp['full_name']); ?></td>
                                            <td><span class="badge bg-light text-dark"><?php echo sanitize($emp['worker_category']); ?></span></td>
                                            
                                            <!-- Earnings -->
                                            <td>
                                                <input type="number" name="basic_da[<?php echo $emp['id']; ?>]" 
                                                       value="<?php echo $basicDa; ?>" 
                                                       class="form-control form-control-sm text-end salary-input" 
                                                       data-type="earning" step="0.01" min="0" onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                            </td>
                                            <td>
                                                <input type="number" name="hra[<?php echo $emp['id']; ?>]" 
                                                       value="<?php echo $hra; ?>" 
                                                       class="form-control form-control-sm text-end salary-input" 
                                                       data-type="earning" step="0.01" min="0" onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                            </td>
                                            <td>
                                                <input type="number" name="leave_encashment[<?php echo $emp['id']; ?>]" 
                                                       value="<?php echo $leaveEncashment; ?>" 
                                                       class="form-control form-control-sm text-end salary-input" 
                                                       data-type="earning" step="0.01" min="0" onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                            </td>
                                            <td>
                                                <input type="number" name="bonus_encashment[<?php echo $emp['id']; ?>]" 
                                                       value="<?php echo $bonusEncashment; ?>" 
                                                       class="form-control form-control-sm text-end salary-input" 
                                                       data-type="earning" step="0.01" min="0" onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                            </td>
                                            <td>
                                                <input type="number" name="washing_allowance[<?php echo $emp['id']; ?>]" 
                                                       value="<?php echo $washingAllowance; ?>" 
                                                       class="form-control form-control-sm text-end salary-input" 
                                                       data-type="earning" step="0.01" min="0" onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                            </td>
                                            
                                            <!-- Gross (Auto) -->
                                            <td class="text-end fw-bold text-primary gross-<?php echo $emp['id']; ?>">
                                                <?php echo number_format($gross, 2); ?>
                                            </td>
                                            
                                            <!-- Statutory Toggles -->
                                            <td class="text-center">
                                                <div class="form-check">
                                                    <input type="checkbox" name="pf_applicable[<?php echo $emp['id']; ?>]" 
                                                           value="1" class="form-check-input statutory-check"
                                                           <?php echo ($emp['pf_applicable'] ?? 0) ? 'checked' : ''; ?>
                                                           onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check">
                                                    <input type="checkbox" name="esi_applicable[<?php echo $emp['id']; ?>]" 
                                                           value="1" class="form-check-input statutory-check"
                                                           <?php echo ($emp['esi_applicable'] ?? 0) ? 'checked' : ''; ?>
                                                           onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check">
                                                    <input type="checkbox" name="pt_applicable[<?php echo $emp['id']; ?>]" 
                                                           value="1" class="form-check-input statutory-check"
                                                           <?php echo ($emp['pt_applicable'] ?? 0) ? 'checked' : ''; ?>
                                                           onchange="calculateRow(<?php echo $emp['id']; ?>)">
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check">
                                                    <input type="checkbox" name="lwf_applicable[<?php echo $emp['id']; ?>]" 
                                                           value="1" class="form-check-input"
                                                           <?php echo ($emp['lwf_applicable'] ?? 0) ? 'checked' : ''; ?>>
                                                </div>
                                            </td>
                                            
                                            <!-- Deductions (Auto) -->
                                            <td class="text-end text-danger pf-<?php echo $emp['id']; ?>"><?php echo number_format($pf, 2); ?></td>
                                            <td class="text-end text-danger esi-<?php echo $emp['id']; ?>"><?php echo number_format($esi, 2); ?></td>
                                            <td class="text-end text-danger fw-bold totalded-<?php echo $emp['id']; ?>"><?php echo number_format($totalDed, 2); ?></td>
                                            
                                            <!-- Net Salary (Auto) -->
                                            <td class="text-end fw-bold text-success netsalary-<?php echo $emp['id']; ?>"><?php echo number_format($netSalary, 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="4" class="text-end">TOTALS:</td>
                                            <td class="text-end" id="total_basic_da"><?php echo number_format(array_sum(array_column($employees, 'basic_da')), 2); ?></td>
                                            <td class="text-end" id="total_hra"><?php echo number_format(array_sum(array_column($employees, 'hra')), 2); ?></td>
                                            <td class="text-end" id="total_leave"><?php echo number_format(array_sum(array_column($employees, 'leave_encashment')), 2); ?></td>
                                            <td class="text-end" id="total_bonus"><?php echo number_format(array_sum(array_column($employees, 'bonus_encashment')), 2); ?></td>
                                            <td class="text-end" id="total_washing"><?php echo number_format(array_sum(array_column($employees, 'washing_allowance')), 2); ?></td>
                                            <td class="text-end text-primary" id="total_gross"><?php echo number_format(array_sum(array_map(function($e) { return ($e['basic_da']??0)+($e['hra']??0)+($e['leave_encashment']??0)+($e['bonus_encashment']??0)+($e['washing_allowance']??0); }, $employees)), 2); ?></td>
                                            <td colspan="4"></td>
                                            <td class="text-end text-danger" id="total_pf">-</td>
                                            <td class="text-end text-danger" id="total_esi">-</td>
                                            <td class="text-end text-danger" id="total_ded">-</td>
                                            <td class="text-end text-success" id="total_net">-</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted">
                                    <small><i class="bi bi-info-circle me-1"></i>Gross, Total Deductions, and Net Salary are calculated automatically. 
                                    Toggle PF/ESI/PT/LWF as per employee's statutory status.</small>
                                </div>
                                <button type="submit" class="btn btn-success" onclick="return confirm('Save salary revision for all employees?')">
                                    <i class="bi bi-check-lg me-1"></i>Save All Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php elseif ($filterUnitId): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    No employees found for the selected unit. Please select a different unit or add employees first.
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Please select a <strong>Client</strong> and <strong>Unit</strong> to load employees for salary revision.
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Bulk Revision Tab -->
            <div class="tab-pane fade" id="bulkRevision">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-download me-2"></i>Step 1: Download Template</h6>
                            </div>
                            <div class="card-body">
                                <p>Download the Excel template with all active employees pre-filled:</p>
                                <a href="download_salary_template.php" class="btn btn-primary">
                                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download Excel Template
                                </a>
                                <hr>
                                <h6>Template Columns:</h6>
                                <ol class="small">
                                    <li><strong>Employee Code</strong> - Must match system</li>
                                    <li><strong>Basic + DA</strong> - Monthly basic with DA</li>
                                    <li><strong>HRA</strong> - House Rent Allowance</li>
                                    <li><strong>Leave Encashment</strong> - Leave encashment amount</li>
                                    <li><strong>Bonus Encashment</strong> - Bonus encashment amount</li>
                                    <li><strong>Washing Allowance</strong> - Washing allowance</li>
                                    <li><strong>PF Applicable</strong> - 1=Yes, 0=No</li>
                                    <li><strong>ESI Applicable</strong> - 1=Yes, 0=No</li>
                                    <li><strong>PT Applicable</strong> - 1=Yes, 0=No</li>
                                    <li><strong>LWF Applicable</strong> - 1=Yes, 0=No</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-upload me-2"></i>Step 2: Upload Filled Template</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Effective From Month</label>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <select class="form-select" name="bulk_month" required>
                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                    </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <select class="form-select" name="bulk_year" required>
                                                    <?php for ($y = date('Y'); $y <= date('Y') + 1; $y++): ?>
                                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Select CSV File</label>
                                        <input type="file" name="salary_file" class="form-control" accept=".csv" required>
                                        <small class="text-muted">Upload the filled CSV template</small>
                                    </div>
                                    
                                    <button type="submit" name="bulk_upload" class="btn btn-success w-100"
                                            onclick="return confirm('Upload and apply salary changes?')">
                                        <i class="bi bi-cloud-upload me-1"></i>Upload & Apply
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- History Tab -->
            <div class="tab-pane fade" id="history">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Revision History</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Employee</th>
                                        <th>Client/Unit</th>
                                        <th>Type</th>
                                        <th class="text-end">Old Gross</th>
                                        <th class="text-end">New Gross</th>
                                        <th>Difference</th>
                                        <th>Effective</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($revisions)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No revisions yet</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($revisions as $r): ?>
                                    <tr>
                                        <td><?php echo formatDate($r['created_at']); ?></td>
                                        <td>
                                            <div><?php echo sanitize($r['full_name']); ?></div>
                                            <small class="text-muted"><?php echo sanitize($r['employee_code']); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo sanitize($r['client_name'] ?? ''); ?></div>
                                            <small class="text-muted"><?php echo sanitize($r['unit_name'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['percentage'])): ?>
                                            <span class="badge bg-primary">+<?php echo $r['percentage']; ?>%</span>
                                            <?php elseif ($r['revision_type'] === 'bulk_upload'): ?>
                                            <span class="badge bg-info">Bulk Upload</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">Manual</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo formatCurrency($r['old_gross'] ?? 0); ?></td>
                                        <td class="text-end"><strong><?php echo formatCurrency($r['new_gross'] ?? 0); ?></strong></td>
                                        <td class="<?php echo ($r['new_gross'] ?? 0) > ($r['old_gross'] ?? 0) ? 'text-success' : 'text-danger'; ?>">
                                            <?php 
                                            $diff = ($r['new_gross'] ?? 0) - ($r['old_gross'] ?? 0);
                                            echo ($diff >= 0 ? '+' : '') . formatCurrency($diff);
                                            ?>
                                        </td>
                                        <td><small><?php echo formatDate($r['effective_from']); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Load units when client changes
document.getElementById('clientSelect')?.addEventListener('change', function() {
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

// Calculate row values
function calculateRow(empId) {
    // Get earnings
    const basicDa = parseFloat(document.querySelector(`[name="basic_da[${empId}]"]`)?.value) || 0;
    const hra = parseFloat(document.querySelector(`[name="hra[${empId}]"]`)?.value) || 0;
    const leaveEncashment = parseFloat(document.querySelector(`[name="leave_encashment[${empId}]"]`)?.value) || 0;
    const bonusEncashment = parseFloat(document.querySelector(`[name="bonus_encashment[${empId}]"]`)?.value) || 0;
    const washingAllowance = parseFloat(document.querySelector(`[name="washing_allowance[${empId}]"]`)?.value) || 0;
    
    const gross = basicDa + hra + leaveEncashment + bonusEncashment + washingAllowance;
    
    // Get statutory checkboxes
    const pfApplicable = document.querySelector(`[name="pf_applicable[${empId}]"]`)?.checked || false;
    const esiApplicable = document.querySelector(`[name="esi_applicable[${empId}]"]`)?.checked || false;
    const ptApplicable = document.querySelector(`[name="pt_applicable[${empId}]"]`)?.checked || false;
    
    // Calculate PF (12% of Basic+DA, max on 15000)
    const pfBase = Math.min(basicDa, 15000);
    const pf = pfApplicable ? Math.round(pfBase * 0.12 * 100) / 100 : 0;
    
    // Calculate ESI (0.75% of Gross if gross <= 21000)
    const esi = esiApplicable && gross <= 21000 ? Math.round(gross * 0.0075 * 100) / 100 : 0;
    
    // PT (simplified)
    const pt = ptApplicable ? (gross > 15000 ? 200 : 0) : 0;
    
    const totalDed = pf + esi + pt;
    const netSalary = gross - totalDed;
    
    // Update display
    const grossEl = document.querySelector(`.gross-${empId}`);
    const pfEl = document.querySelector(`.pf-${empId}`);
    const esiEl = document.querySelector(`.esi-${empId}`);
    const totalDedEl = document.querySelector(`.totalded-${empId}`);
    const netEl = document.querySelector(`.netsalary-${empId}`);
    
    if (grossEl) grossEl.textContent = gross.toFixed(2);
    if (pfEl) pfEl.textContent = pf.toFixed(2);
    if (esiEl) esiEl.textContent = esi.toFixed(2);
    if (totalDedEl) totalDedEl.textContent = totalDed.toFixed(2);
    if (netEl) netEl.textContent = netSalary.toFixed(2);
    
    // Update totals
    calculateTotals();
}

// Calculate all totals
function calculateTotals() {
    let totalBasicDa = 0, totalHRA = 0, totalLeave = 0, totalBonus = 0, totalWashing = 0;
    let totalGross = 0, totalPF = 0, totalESI = 0, totalDed = 0, totalNet = 0;
    
    document.querySelectorAll('[name="employee_id[]"]').forEach(input => {
        const empId = input.value;
        const basicDa = parseFloat(document.querySelector(`[name="basic_da[${empId}]"]`)?.value) || 0;
        const hra = parseFloat(document.querySelector(`[name="hra[${empId}]"]`)?.value) || 0;
        const leaveEncashment = parseFloat(document.querySelector(`[name="leave_encashment[${empId}]"]`)?.value) || 0;
        const bonusEncashment = parseFloat(document.querySelector(`[name="bonus_encashment[${empId}]"]`)?.value) || 0;
        const washingAllowance = parseFloat(document.querySelector(`[name="washing_allowance[${empId}]"]`)?.value) || 0;
        const gross = basicDa + hra + leaveEncashment + bonusEncashment + washingAllowance;
        
        const pfApplicable = document.querySelector(`[name="pf_applicable[${empId}]"]`)?.checked || false;
        const esiApplicable = document.querySelector(`[name="esi_applicable[${empId}]"]`)?.checked || false;
        const ptApplicable = document.querySelector(`[name="pt_applicable[${empId}]"]`)?.checked || false;
        
        const pfBase = Math.min(basicDa, 15000);
        const pf = pfApplicable ? Math.round(pfBase * 0.12 * 100) / 100 : 0;
        const esi = esiApplicable && gross <= 21000 ? Math.round(gross * 0.0075 * 100) / 100 : 0;
        const pt = ptApplicable ? (gross > 15000 ? 200 : 0) : 0;
        
        const totalDedRow = pf + esi + pt;
        const netSalary = gross - totalDedRow;
        
        totalBasicDa += basicDa;
        totalHRA += hra;
        totalLeave += leaveEncashment;
        totalBonus += bonusEncashment;
        totalWashing += washingAllowance;
        totalGross += gross;
        totalPF += pf;
        totalESI += esi;
        totalDed += totalDedRow;
        totalNet += netSalary;
    });
    
    document.getElementById('total_basic_da').textContent = totalBasicDa.toFixed(2);
    document.getElementById('total_hra').textContent = totalHRA.toFixed(2);
    document.getElementById('total_leave').textContent = totalLeave.toFixed(2);
    document.getElementById('total_bonus').textContent = totalBonus.toFixed(2);
    document.getElementById('total_washing').textContent = totalWashing.toFixed(2);
    document.getElementById('total_gross').textContent = totalGross.toFixed(2);
    document.getElementById('total_pf').textContent = totalPF.toFixed(2);
    document.getElementById('total_esi').textContent = totalESI.toFixed(2);
    document.getElementById('total_ded').textContent = totalDed.toFixed(2);
    document.getElementById('total_net').textContent = totalNet.toFixed(2);
}
</script>
