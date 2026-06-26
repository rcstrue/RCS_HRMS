<?php
/**
 * RCS HRMS Pro - Monthly Salary Entry
 * View/edit salary structures for individual employees month-wise
 */

$pageTitle = 'Salary Entry';

// Get filter values
$currentMonth = prev_month_num();
$currentYear = date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$searchTerm = sanitize($_GET['search'] ?? '');
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

// Handle POST save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_salary'])) {
    $employeeIds = $_POST['employee_id'] ?? [];
    $savedCount = 0;
    $errors = [];

    try {
        $db->beginTransaction();

        foreach ($employeeIds as $empId) {
            $empId = (int)$empId;
            $basicDA = floatval($_POST['basic_da'][$empId] ?? 0);
            $hra = floatval($_POST['hra'][$empId] ?? 0);
            $leaveEnc = floatval($_POST['leave_encashment'][$empId] ?? 0);
            $bonusEnc = floatval($_POST['bonus_encashment'][$empId] ?? 0);
            $washing = floatval($_POST['washing_allowance'][$empId] ?? 0);
            $grossSalary = $basicDA + $hra + $leaveEnc + $bonusEnc + $washing;

            $pfApplicable = isset($_POST['pf_applicable'][$empId]) ? 1 : 0;
            $esiApplicable = isset($_POST['esi_applicable'][$empId]) ? 1 : 0;
            $ptApplicable = isset($_POST['pt_applicable'][$empId]) ? 1 : 0;
            $lwfApplicable = isset($_POST['lwf_applicable'][$empId]) ? 1 : 0;
            $otApplicable = isset($_POST['overtime_applicable'][$empId]) ? 1 : 0;
            $bonusApplicable = isset($_POST['bonus_applicable'][$empId]) ? 1 : 0;
            $gratuityApplicable = isset($_POST['gratuity_applicable'][$empId]) ? 1 : 0;

            $effectiveFrom = $yearFilter . '-' . str_pad($monthFilter, 2, '0', STR_PAD_LEFT) . '-01';

            // Check if salary structure exists for this employee (active one)
            $existing = $db->fetch(
                "SELECT id FROM employee_salary_structures 
                 WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())
                 ORDER BY effective_from DESC LIMIT 1",
                [$empId]
            );

            if ($existing) {
                // Update existing record
                $db->update('employee_salary_structures', [
                    'basic_da' => $basicDA,
                    'hra' => $hra,
                    'leave_encashment' => $leaveEnc,
                    'bonus_encashment' => $bonusEnc,
                    'washing_allowance' => $washing,
                    'gross_salary' => $grossSalary,
                    'pf_applicable' => $pfApplicable,
                    'esi_applicable' => $esiApplicable,
                    'pt_applicable' => $ptApplicable,
                    'lwf_applicable' => $lwfApplicable,
                    'overtime_applicable' => $otApplicable,
                    'bonus_applicable' => $bonusApplicable,
                    'gratuity_applicable' => $gratuityApplicable
                ], 'id = :id', ['id' => $existing['id']]);
            } else {
                // Close any previous structures
                $prevStructures = $db->fetchAll(
                    "SELECT id FROM employee_salary_structures WHERE employee_id = ? AND effective_to IS NULL",
                    [$empId]
                );
                foreach ($prevStructures as $prev) {
                    $db->update('employee_salary_structures', [
                        'effective_to' => date('Y-m-d', strtotime($effectiveFrom . ' -1 day'))
                    ], 'id = :id', ['id' => $prev['id']]);
                }

                // Insert new structure
                $db->insert('employee_salary_structures', [
                    'employee_id' => $empId,
                    'effective_from' => $effectiveFrom,
                    'basic_da' => $basicDA,
                    'hra' => $hra,
                    'leave_encashment' => $leaveEnc,
                    'bonus_encashment' => $bonusEnc,
                    'washing_allowance' => $washing,
                    'gross_salary' => $grossSalary,
                    'pf_applicable' => $pfApplicable,
                    'esi_applicable' => $esiApplicable,
                    'pt_applicable' => $ptApplicable,
                    'lwf_applicable' => $lwfApplicable,
                    'overtime_applicable' => $otApplicable,
                    'bonus_applicable' => $bonusApplicable,
                    'gratuity_applicable' => $gratuityApplicable,
                    'created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
            $savedCount++;
        }

        $db->commit();
        setFlash('success', "Salary updated for {$savedCount} employees.");
        redirect('index.php?page=entry/salary-entry&month=' . $monthFilter . '&year=' . $yearFilter . 
                '&client_id=' . $clientFilter . '&unit_id=' . $unitFilter . 
                ($searchTerm ? '&search=' . urlencode($searchTerm) : '') . '&filter=1');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error saving salary: ' . $e->getMessage());
    }
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="salary_entry_' . $monthFilter . '_' . $yearFilter . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }
    if ($searchTerm) {
        $where .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)";
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
    }

    $data = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.designation, c.name as client_name, u.name as unit_name,
                ess.basic_da, ess.hra, ess.leave_encashment, ess.bonus_encashment, ess.washing_allowance,
                ess.gross_salary, ess.pf_applicable, ess.esi_applicable, ess.pt_applicable, ess.lwf_applicable
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $where
         ORDER BY c.name, u.name, e.employee_code",
        $params
    );

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Code', 'Name', 'Designation', 'Client', 'Unit', 'Basic+DA', 'HRA', 
                       'Leave Encashment', 'Bonus Encashment', 'Washing', 'Gross', 'PF', 'ESI', 'PT', 'LWF']);
    foreach ($data as $row) {
        fputcsv($output, [
            $row['employee_code'], $row['full_name'], $row['designation'],
            $row['client_name'], $row['unit_name'],
            $row['basic_da'] ?? 0, $row['hra'] ?? 0, $row['leave_encashment'] ?? 0,
            $row['bonus_encashment'] ?? 0, $row['washing_allowance'] ?? 0,
            $row['gross_salary'] ?? 0,
            $row['pf_applicable'] ? 'Yes' : 'No',
            $row['esi_applicable'] ? 'Yes' : 'No',
            $row['pt_applicable'] ? 'Yes' : 'No',
            $row['lwf_applicable'] ? 'Yes' : 'No'
        ]);
    }
    fclose($output);
    exit;
}

// Get employees with salary data
$employees = [];
$summaryData = ['total_employees' => 0, 'total_gross' => 0, 'avg_gross' => 0];

if ($filterPressed && $clientFilter) {
    $where = "e.status = 'approved'";
    $params = [];
    if ($clientFilter) { $where .= " AND e.client_id = ?"; $params[] = $clientFilter; }
    if ($unitFilter) { $where .= " AND e.unit_id = ?"; $params[] = $unitFilter; }
    if ($searchTerm) {
        $where .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)";
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
    }

    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.designation,
                c.name as client_name, u.name as unit_name,
                ess.id as salary_id,
                COALESCE(ess.basic_da, 0) as basic_da,
                COALESCE(ess.hra, 0) as hra,
                COALESCE(ess.leave_encashment, 0) as leave_encashment,
                COALESCE(ess.bonus_encashment, 0) as bonus_encashment,
                COALESCE(ess.washing_allowance, 0) as washing_allowance,
                COALESCE(ess.gross_salary, 0) as gross_salary,
                ess.pf_applicable, ess.esi_applicable, ess.pt_applicable,
                ess.lwf_applicable, ess.overtime_applicable, ess.bonus_applicable, ess.gratuity_applicable,
                ess.effective_from, ess.effective_to
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
         WHERE $where
         ORDER BY c.name, u.name, e.employee_code",
        $params
    );

    // Summary
    $summaryData['total_employees'] = count($employees);
    $totalGross = 0;
    foreach ($employees as $emp) {
        $totalGross += floatval($emp['gross_salary']);
    }
    $summaryData['total_gross'] = $totalGross;
    $summaryData['avg_gross'] = count($employees) > 0 ? $totalGross / count($employees) : 0;
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
                    <i class="bi bi-cash-coin me-2"></i>Salary Entry
                </h5>
                <?php if (!empty($employees)): ?>
                <a href="index.php?page=entry/salary-entry&<?php echo http_build_query($_GET); ?>&export=csv" 
                   class="btn btn-outline-success btn-sm">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-2 mb-3" id="filterForm">
                    <input type="hidden" name="page" value="entry/salary-entry">
                    
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
                        <label class="form-label small">Search</label>
                        <input type="text" class="form-control form-control-sm" name="search" 
                               placeholder="Name or code..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end gap-1">
                        <button type="submit" name="filter" value="1" class="btn btn-primary btn-sm flex-grow-1">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                        <a href="index.php?page=entry/salary-entry" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (!$filterPressed || !$clientFilter): ?>
        <!-- No filter message -->
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-funnel fs-1"></i>
                <p class="mt-3">Select a <strong>Client</strong> and click <strong>Filter</strong> to view employees and their salary structures.</p>
            </div>
        </div>
        
        <?php elseif (empty($employees)): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-people fs-1"></i>
                <p class="mt-3">No approved employees found for the selected filters.</p>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-body py-2 text-center">
                        <small class="text-muted">Total Employees</small>
                        <h5 class="mb-0"><?php echo number_format($summaryData['total_employees']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success bg-opacity-10">
                    <div class="card-body py-2 text-center">
                        <small class="text-success">Total Gross Salary</small>
                        <h5 class="mb-0 text-success"><?php echo formatCurrency($summaryData['total_gross']); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info bg-opacity-10">
                    <div class="card-body py-2 text-center">
                        <small class="text-info">Average Gross</small>
                        <h5 class="mb-0 text-info"><?php echo formatCurrency($summaryData['avg_gross']); ?></h5>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Salary Entry Form -->
        <form method="POST" id="salaryForm">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-pencil-square me-2"></i>
                        <?php echo $months[$monthFilter] . ' ' . $yearFilter; ?> — 
                        <?php echo count($employees); ?> Employees
                    </h6>
                    <div class="d-flex gap-2">
                        <button type="submit" name="save_salary" class="btn btn-success btn-sm"
                                onclick="return confirm('Save salary changes for all listed employees?')">
                            <i class="bi bi-check-lg me-1"></i>Save All
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover mb-0" style="font-size: 0.82rem;">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th rowspan="2" class="text-center" style="width:35px;">#</th>
                                    <th rowspan="2" style="width:70px;">Code</th>
                                    <th rowspan="2">Employee Name</th>
                                    <th rowspan="2">Designation</th>
                                    <th rowspan="2">Unit</th>
                                    <th colspan="5" class="text-center" style="background:#1a7431;">Earnings (₹)</th>
                                    <th rowspan="2" class="text-end" style="border-left:2px solid #6c757d;background:#2b8a3e;">
                                        <strong>Gross</strong>
                                    </th>
                                    <th colspan="4" class="text-center" style="background:#c92a2a;">Applicable</th>
                                    <th colspan="3" class="text-center" style="background:#5c3d8f;">Other Applicable</th>
                                </tr>
                                <tr>
                                    <th class="text-end" style="background:#2b8a3e;">Basic+DA</th>
                                    <th class="text-end" style="background:#2b8a3e;">HRA</th>
                                    <th class="text-end" style="background:#2b8a3e;">L.Enc</th>
                                    <th class="text-end" style="background:#2b8a3e;">B.Enc</th>
                                    <th class="text-end" style="background:#2b8a3e;">Wash</th>
                                    <th class="text-center" style="background:#a71d2a;">PF</th>
                                    <th class="text-center" style="background:#a71d2a;">ESI</th>
                                    <th class="text-center" style="background:#a71d2a;">PT</th>
                                    <th class="text-center" style="background:#a71d2a;">LWF</th>
                                    <th class="text-center" style="background:#6741d9;">OT</th>
                                    <th class="text-center" style="background:#6741d9;">Bonus</th>
                                    <th class="text-center" style="background:#6741d9;">Gratuity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $idx => $emp): ?>
                                <tr>
                                    <td class="text-center text-muted"><?php echo $idx + 1; ?></td>
                                    <td>
                                        <input type="hidden" name="employee_id[]" value="<?php echo $emp['id']; ?>">
                                        <code><?php echo sanitize($emp['employee_code']); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo sanitize($emp['full_name']); ?></strong>
                                        <?php if ($emp['salary_id']): ?>
                                        <i class="bi bi-check-circle-fill text-success" title="Salary structure exists"></i>
                                        <?php else: ?>
                                        <i class="bi bi-exclamation-circle text-warning" title="No salary structure"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                    <td class="text-muted small"><?php echo sanitize($emp['unit_name']); ?></td>
                                    
                                    <!-- Earnings Inputs -->
                                    <td>
                                        <input type="number" name="basic_da[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['basic_da']; ?>" 
                                               class="form-control form-control-sm text-end salary-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>" data-field="basic_da"
                                               min="0" step="1" style="width:90px;">
                                    </td>
                                    <td>
                                        <input type="number" name="hra[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['hra']; ?>" 
                                               class="form-control form-control-sm text-end salary-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>" data-field="hra"
                                               min="0" step="1" style="width:80px;">
                                    </td>
                                    <td>
                                        <input type="number" name="leave_encashment[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['leave_encashment']; ?>" 
                                               class="form-control form-control-sm text-end salary-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>" data-field="leave_encashment"
                                               min="0" step="1" style="width:80px;">
                                    </td>
                                    <td>
                                        <input type="number" name="bonus_encashment[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['bonus_encashment']; ?>" 
                                               class="form-control form-control-sm text-end salary-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>" data-field="bonus_encashment"
                                               min="0" step="1" style="width:80px;">
                                    </td>
                                    <td>
                                        <input type="number" name="washing_allowance[<?php echo $emp['id']; ?>]" 
                                               value="<?php echo $emp['washing_allowance']; ?>" 
                                               class="form-control form-control-sm text-end salary-input" 
                                               data-emp-id="<?php echo $emp['id']; ?>" data-field="washing_allowance"
                                               min="0" step="1" style="width:80px;">
                                    </td>
                                    
                                    <!-- Gross (auto-calculated) -->
                                    <td class="text-end fw-bold" style="border-left:2px solid #dee2e6; background:#f8f9fa;">
                                        <span id="gross_<?php echo $emp['id']; ?>">
                                            <?php echo formatCurrency($emp['gross_salary']); ?>
                                        </span>
                                    </td>
                                    
                                    <!-- Checkboxes -->
                                    <td class="text-center">
                                        <input type="checkbox" name="pf_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['pf_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="esi_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['esi_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="pt_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['pt_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="lwf_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['lwf_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="overtime_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['overtime_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="bonus_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['bonus_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="gratuity_applicable[<?php echo $emp['id']; ?>]" 
                                               value="1" <?php echo $emp['gratuity_applicable'] ? 'checked' : ''; ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">
                                        <span class="text-primary">TOTAL (<?php echo count($employees); ?> Employees)</span>
                                    </td>
                                    <td class="text-end" id="total_basic_da">0</td>
                                    <td class="text-end" id="total_hra">0</td>
                                    <td class="text-end" id="total_leave_enc">0</td>
                                    <td class="text-end" id="total_bonus_enc">0</td>
                                    <td class="text-end" id="total_washing">0</td>
                                    <td class="text-end" style="border-left:2px solid #dee2e6;" id="total_gross_col">
                                        0
                                    </td>
                                    <td colspan="7"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Gross is auto-calculated as Basic+DA + HRA + Leave Enc + Bonus Enc + Washing.
                            <span class="badge bg-success"><i class="bi bi-check-circle-fill"></i></span> = Has salary structure &nbsp;
                            <span class="badge bg-warning"><i class="bi bi-exclamation-circle"></i></span> = No structure yet
                        </small>
                        <button type="submit" name="save_salary" class="btn btn-success"
                                onclick="return confirm('Save salary changes for all listed employees?')">
                            <i class="bi bi-check-lg me-1"></i>Save All Changes
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
    .table td input { border: none !important; background: transparent !important; }
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
        .then(response => response.json())
        .then(data => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
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
            unitSelect.innerHTML = '<option value="">All Units</option>';
        });
});

// Auto-calculate gross and totals
document.querySelectorAll('.salary-input').forEach(input => {
    input.addEventListener('input', calculateRowGross);
});

function calculateRowGross(e) {
    const empId = e?.target?.dataset?.empId;
    if (empId) {
        const form = document.getElementById('salaryForm');
        const basic = parseFloat(form.querySelector(`[name="basic_da[${empId}]"]`)?.value) || 0;
        const hra = parseFloat(form.querySelector(`[name="hra[${empId}]"]`)?.value) || 0;
        const leaveEnc = parseFloat(form.querySelector(`[name="leave_encashment[${empId}]"]`)?.value) || 0;
        const bonusEnc = parseFloat(form.querySelector(`[name="bonus_encashment[${empId}]"]`)?.value) || 0;
        const washing = parseFloat(form.querySelector(`[name="washing_allowance[${empId}]"]`)?.value) || 0;
        const gross = basic + hra + leaveEnc + bonusEnc + washing;
        
        const grossEl = document.getElementById('gross_' + empId);
        if (grossEl) grossEl.textContent = '₹' + gross.toLocaleString('en-IN');
    }
    calculateTotals();
}

function calculateTotals() {
    let totalBasic = 0, totalHra = 0, totalLeaveEnc = 0, totalBonusEnc = 0, totalWashing = 0, totalGross = 0;
    
    document.querySelectorAll('[name^="basic_da["]').forEach(input => {
        totalBasic += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="hra["]').forEach(input => {
        totalHra += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="leave_encashment["]').forEach(input => {
        totalLeaveEnc += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="bonus_encashment["]').forEach(input => {
        totalBonusEnc += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('[name^="washing_allowance["]').forEach(input => {
        totalWashing += parseFloat(input.value) || 0;
    });
    
    totalGross = totalBasic + totalHra + totalLeaveEnc + totalBonusEnc + totalWashing;
    
    const fmt = (v) => '₹' + v.toLocaleString('en-IN');
    document.getElementById('total_basic_da').textContent = fmt(totalBasic);
    document.getElementById('total_hra').textContent = fmt(totalHra);
    document.getElementById('total_leave_enc').textContent = fmt(totalLeaveEnc);
    document.getElementById('total_bonus_enc').textContent = fmt(totalBonusEnc);
    document.getElementById('total_washing').textContent = fmt(totalWashing);
    document.getElementById('total_gross_col').textContent = fmt(totalGross);
}

// Initialize totals on load
document.addEventListener('DOMContentLoaded', calculateTotals);
</script>
