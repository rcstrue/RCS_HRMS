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
    // Deduplicate: same employee may appear multiple times
    $employeeIds = array_unique(array_map('intval', $employeeIds));
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

                // Also delete any duplicate active structures for this employee
                $db->query(
                    "DELETE FROM employee_salary_structures WHERE employee_id = ? AND effective_to IS NULL AND id != ?",
                    [$empId, $existing['id']]
                );
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
         LEFT JOIN (SELECT employee_id, MAX(id) AS id,
                    MAX(basic_da) AS basic_da, MAX(hra) AS hra,
                    MAX(leave_encashment) AS leave_encashment, MAX(bonus_encashment) AS bonus_encashment,
                    MAX(washing_allowance) AS washing_allowance, MAX(gross_salary) AS gross_salary,
                    MAX(pf_applicable) AS pf_applicable, MAX(esi_applicable) AS esi_applicable,
                    MAX(pt_applicable) AS pt_applicable, MAX(lwf_applicable) AS lwf_applicable
                    FROM employee_salary_structures
                    WHERE effective_to IS NULL OR effective_to >= CURDATE()
                    GROUP BY employee_id) ess ON e.id = ess.employee_id
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
         LEFT JOIN (SELECT employee_id, MAX(id) AS id,
                    MAX(basic_da) AS basic_da, MAX(hra) AS hra,
                    MAX(leave_encashment) AS leave_encashment, MAX(bonus_encashment) AS bonus_encashment,
                    MAX(washing_allowance) AS washing_allowance, MAX(gross_salary) AS gross_salary,
                    MAX(pf_applicable) AS pf_applicable, MAX(esi_applicable) AS esi_applicable,
                    MAX(pt_applicable) AS pt_applicable, MAX(lwf_applicable) AS lwf_applicable,
                    MAX(overtime_applicable) AS overtime_applicable, MAX(bonus_applicable) AS bonus_applicable,
                    MAX(gratuity_applicable) AS gratuity_applicable,
                    MAX(effective_from) AS effective_from, MAX(effective_to) AS effective_to
                    FROM employee_salary_structures
                    WHERE effective_to IS NULL OR effective_to >= CURDATE()
                    GROUP BY employee_id) ess ON e.id = ess.employee_id
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

        <!-- Jspreadsheet data (populated by PHP) -->
        <script>
        var employeeIds = [<?php echo implode(',', array_map(function($e) { return (int)$e['id']; }, $employees)); ?>];
        var jssData = [
        <?php
        $firstRow = true;
        foreach ($employees as $idx => $emp):
            if (!$firstRow) echo ",\n";
            $firstRow = false;
        ?>
            [
                '<?php echo $idx + 1; ?>',
                '<?php echo addslashes($emp['employee_code']); ?>',
                '<?php echo addslashes($emp['full_name']); ?>',
                '<?php echo addslashes($emp['designation'] ?? ''); ?>',
                '<?php echo addslashes($emp['unit_name'] ?? ''); ?>',
                <?php echo floatval($emp['basic_da']); ?>,
                <?php echo floatval($emp['hra']); ?>,
                <?php echo floatval($emp['leave_encashment']); ?>,
                <?php echo floatval($emp['bonus_encashment']); ?>,
                <?php echo floatval($emp['washing_allowance']); ?>,
                <?php echo floatval($emp['gross_salary']); ?>,
                <?php echo $emp['pf_applicable'] ? 1 : 0; ?>,
                <?php echo $emp['esi_applicable'] ? 1 : 0; ?>,
                <?php echo $emp['pt_applicable'] ? 1 : 0; ?>,
                <?php echo $emp['lwf_applicable'] ? 1 : 0; ?>,
                <?php echo $emp['overtime_applicable'] ? 1 : 0; ?>,
                <?php echo $emp['bonus_applicable'] ? 1 : 0; ?>,
                <?php echo $emp['gratuity_applicable'] ? 1 : 0; ?>
            ]
        <?php endforeach; ?>
        ];
        </script>

        <!-- Salary Entry Form -->
        <form method="POST" id="salaryForm">
            <div id="hiddenFields" style="display:none;"></div>
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
                    <div id="salary-spreadsheet"></div>
                    <!-- Totals bar -->
                    <div id="totalsBar" class="d-flex align-items-center small fw-bold px-2 py-1 border-top"
                         style="background:#f8f9fa; font-size:0.82rem; min-height:30px;">
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Gross is auto-calculated as Basic+DA + HRA + Leave Enc + Bonus Enc + Washing.
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
/* Jspreadsheet overrides for tighter fit */
#salary-spreadsheet .jss_container {
    width: 100% !important;
}
#salary-spreadsheet table {
    font-size: 0.82rem;
}
#salary-spreadsheet thead td {
    font-weight: 600;
    background: #343a40 !important;
    color: #fff !important;
    padding: 4px 6px !important;
    text-align: center;
    white-space: nowrap;
}
#salary-spreadsheet thead td:nth-child(6),
#salary-spreadsheet thead td:nth-child(7),
#salary-spreadsheet thead td:nth-child(8),
#salary-spreadsheet thead td:nth-child(9),
#salary-spreadsheet thead td:nth-child(10) {
    background: #1a7431 !important;
}
#salary-spreadsheet thead td:nth-child(11) {
    background: #2b8a3e !important;
    border-left: 2px solid #6c757d !important;
}
#salary-spreadsheet thead td:nth-child(12),
#salary-spreadsheet thead td:nth-child(13),
#salary-spreadsheet thead td:nth-child(14),
#salary-spreadsheet thead td:nth-child(15) {
    background: #c92a2a !important;
}
#salary-spreadsheet thead td:nth-child(16),
#salary-spreadsheet thead td:nth-child(17),
#salary-spreadsheet thead td:nth-child(18) {
    background: #5c3d8f !important;
}
#salary-spreadsheet tbody td {
    padding: 2px 4px !important;
}
#salary-spreadsheet tbody td:nth-child(11) {
    background: #f8f9fa !important;
    font-weight: 700 !important;
    border-left: 2px solid #dee2e6 !important;
    text-align: right !important;
}
/* Center numeric earnings columns */
#salary-spreadsheet tbody td:nth-child(6),
#salary-spreadsheet tbody td:nth-child(7),
#salary-spreadsheet tbody td:nth-child(8),
#salary-spreadsheet tbody td:nth-child(9),
#salary-spreadsheet tbody td:nth-child(10) {
    text-align: right !important;
}
/* Center checkbox columns */
#salary-spreadsheet tbody td:nth-child(12),
#salary-spreadsheet tbody td:nth-child(13),
#salary-spreadsheet tbody td:nth-child(14),
#salary-spreadsheet tbody td:nth-child(15),
#salary-spreadsheet tbody td:nth-child(16),
#salary-spreadsheet tbody td:nth-child(17),
#salary-spreadsheet tbody td:nth-child(18) {
    text-align: center !important;
}
/* Totals bar styling */
#totalsBar .t-label {
    color: #0d6efd;
    flex-shrink: 0;
    margin-right: auto;
}
#totalsBar .t-val {
    text-align: right;
    padding: 0 6px;
    flex-shrink: 0;
}

@media print {
    .btn, form#filterForm { display: none !important; }
    .jss_container { overflow: visible !important; }
    body { font-size: 8pt; }
    #salary-spreadsheet .jss_container { box-shadow: none !important; }
}
</style>

<?php
ob_start();
?>
<script>
// Load units dynamically (kept from original)
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

// Initialize Jspreadsheet if data exists
if (typeof jssData !== 'undefined' && jssData.length > 0) {
    var jssInstance = jspreadsheet(document.getElementById('salary-spreadsheet'), {
        data: jssData,
        columns: [
            { type: 'text', title: '#',          width: 36,  readOnly: true },
            { type: 'text', title: 'Code',       width: 75,  readOnly: true },
            { type: 'text', title: 'Name',       width: 150, readOnly: true },
            { type: 'text', title: 'Desig',      width: 100, readOnly: true },
            { type: 'text', title: 'Unit',       width: 100, readOnly: true },
            { type: 'numeric', title: 'Basic+DA', width: 90,  readOnly: false, decimal: 2 },
            { type: 'numeric', title: 'HRA',      width: 80,  readOnly: false, decimal: 2 },
            { type: 'numeric', title: 'L.Enc',    width: 75,  readOnly: false, decimal: 2 },
            { type: 'numeric', title: 'B.Enc',    width: 75,  readOnly: false, decimal: 2 },
            { type: 'numeric', title: 'Wash',     width: 75,  readOnly: false, decimal: 2 },
            { type: 'numeric', title: 'Gross',    width: 100, readOnly: true,  decimal: 2 },
            { type: 'checkbox', title: 'PF',      width: 40,  readOnly: false },
            { type: 'checkbox', title: 'ESI',     width: 40,  readOnly: false },
            { type: 'checkbox', title: 'PT',      width: 40,  readOnly: false },
            { type: 'checkbox', title: 'LWF',     width: 40,  readOnly: false },
            { type: 'checkbox', title: 'OT',      width: 40,  readOnly: false },
            { type: 'checkbox', title: 'Bonus',   width: 50,  readOnly: false },
            { type: 'checkbox', title: 'Gratuity',width: 55,  readOnly: false }
        ],
        tableOverflow: true,
        tableHeight: '600px',
        columnResize: true,
        allowExport: false,
        onchange: function(instance, cell, col, row, value, oldValue) {
            // Auto-calculate Gross (col 10) when any earning col (5-9) changes
            if (col >= 5 && col <= 9) {
                var basicDa  = parseFloat(instance.getValueFromCoords(5, row)) || 0;
                var hra      = parseFloat(instance.getValueFromCoords(6, row)) || 0;
                var leaveEnc = parseFloat(instance.getValueFromCoords(7, row)) || 0;
                var bonusEnc = parseFloat(instance.getValueFromCoords(8, row)) || 0;
                var washing  = parseFloat(instance.getValueFromCoords(9, row)) || 0;
                var gross    = basicDa + hra + leaveEnc + bonusEnc + washing;
                instance.setValueFromCoords(10, row, gross);
            }
            updateTotals();
        },
        oneditionend: function(instance, cell, col, row, value) {
            // Also recalculate when edition ends (ensures correct value after blur)
            if (col >= 5 && col <= 9) {
                var basicDa  = parseFloat(instance.getValueFromCoords(5, row)) || 0;
                var hra      = parseFloat(instance.getValueFromCoords(6, row)) || 0;
                var leaveEnc = parseFloat(instance.getValueFromCoords(7, row)) || 0;
                var bonusEnc = parseFloat(instance.getValueFromCoords(8, row)) || 0;
                var washing  = parseFloat(instance.getValueFromCoords(9, row)) || 0;
                var gross    = basicDa + hra + leaveEnc + bonusEnc + washing;
                instance.setValueFromCoords(10, row, gross);
            }
            updateTotals();
        }
    });

    // Format helper
    function fmtINR(v) {
        return '\u20B9' + Number(v).toLocaleString('en-IN');
    }

    // Update totals bar below spreadsheet
    function updateTotals() {
        var data = jssInstance.getData();
        var tB = 0, tH = 0, tLE = 0, tBE = 0, tW = 0, tG = 0;
        for (var i = 0; i < data.length; i++) {
            tB  += parseFloat(data[i][5]) || 0;
            tH  += parseFloat(data[i][6]) || 0;
            tLE += parseFloat(data[i][7]) || 0;
            tBE += parseFloat(data[i][8]) || 0;
            tW  += parseFloat(data[i][9]) || 0;
            tG  += parseFloat(data[i][10]) || 0;
        }
        var bar = document.getElementById('totalsBar');
        bar.innerHTML =
            '<span class="t-label">TOTAL (' + data.length + ' Employees)</span>' +
            '<span class="t-val">' + fmtINR(tB) + '</span>' +
            '<span class="t-val">' + fmtINR(tH) + '</span>' +
            '<span class="t-val">' + fmtINR(tLE) + '</span>' +
            '<span class="t-val">' + fmtINR(tBE) + '</span>' +
            '<span class="t-val">' + fmtINR(tW) + '</span>' +
            '<span class="t-val" style="border-left:2px solid #dee2e6;padding-left:8px;font-weight:700;">' + fmtINR(tG) + '</span>';
    }
    // Initial totals
    updateTotals();

    // Helper to add hidden field
    function addHiddenField(name, value) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = value;
        document.getElementById('hiddenFields').appendChild(inp);
    }

    // Intercept form submit — collect jspreadsheet data into hidden fields
    document.getElementById('salaryForm').addEventListener('submit', function(e) {
        var container = document.getElementById('hiddenFields');
        container.innerHTML = '';

        var data = jssInstance.getData();

        for (var i = 0; i < data.length; i++) {
            var empId = employeeIds[i];

            addHiddenField('employee_id[]', empId);
            addHiddenField('basic_da[' + empId + ']', data[i][5] || 0);
            addHiddenField('hra[' + empId + ']', data[i][6] || 0);
            addHiddenField('leave_encashment[' + empId + ']', data[i][7] || 0);
            addHiddenField('bonus_encashment[' + empId + ']', data[i][8] || 0);
            addHiddenField('washing_allowance[' + empId + ']', data[i][9] || 0);

            // Checkboxes: only send when checked (mimics HTML checkbox behaviour for PHP isset check)
            if (data[i][11] == 1 || data[i][11] === true) addHiddenField('pf_applicable[' + empId + ']', 1);
            if (data[i][12] == 1 || data[i][12] === true) addHiddenField('esi_applicable[' + empId + ']', 1);
            if (data[i][13] == 1 || data[i][13] === true) addHiddenField('pt_applicable[' + empId + ']', 1);
            if (data[i][14] == 1 || data[i][14] === true) addHiddenField('lwf_applicable[' + empId + ']', 1);
            if (data[i][15] == 1 || data[i][15] === true) addHiddenField('overtime_applicable[' + empId + ']', 1);
            if (data[i][16] == 1 || data[i][16] === true) addHiddenField('bonus_applicable[' + empId + ']', 1);
            if (data[i][17] == 1 || data[i][17] === true) addHiddenField('gratuity_applicable[' + empId + ']', 1);
        }
        // Allow the form to submit normally with hidden fields
    });
}
</script>
<?php
$extraJS = ob_get_clean();
?>