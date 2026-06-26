<?php
/**
 * RCS HRMS Pro - Custom Report Builder
 * 
 * IMPORTANT: employees table does NOT have client_name or unit_name columns.
 * Always use JOIN with clients and units tables to get client/unit names.
 * 
 * Database Schema:
 * - employees.client_id → clients.id (use JOIN to get clients.name AS client_name)
 * - employees.unit_id → units.id (use JOIN to get units.name AS unit_name)
 */

$pageTitle = 'Custom Report Builder';

// Get available tables and columns
$tables = [
    'employees' => [
        'label' => 'Employees',
        'columns' => [
            'employee_code' => 'Employee Code',
            'full_name' => 'Full Name',
            'gender' => 'Gender',
            'date_of_birth' => 'Date of Birth',
            'date_of_joining' => 'Date of Joining',
            'designation' => 'Designation',
            'department' => 'Department',
            'client_name' => 'Client Name',
            'unit_name' => 'Unit Name',
            'status' => 'Status',
            'pf_number' => 'PF Number',
            'esi_number' => 'ESI Number',
            'bank_name' => 'Bank Name',
            'bank_account_number' => 'Bank Account',
            'state' => 'State',
            'city' => 'City'
        ]
    ],
    'attendance' => [
        'label' => 'Attendance',
        'columns' => [
            'month' => 'Month',
            'year' => 'Year',
            'days_present' => 'Days Present',
            'days_absent' => 'Days Absent',
            'half_days' => 'Half Days',
            'overtime_hours' => 'OT Hours',
            'ot_amount' => 'OT Amount'
        ]
    ],
    'payroll' => [
        'label' => 'Payroll',
        'columns' => [
            'basic' => 'Basic',
            'da' => 'DA',
            'hra' => 'HRA',
            'other_allowances' => 'Other Allowances',
            'gross_earnings' => 'Gross Earnings',
            'pf_employee' => 'PF Employee',
            'pf_employer' => 'PF Employer',
            'esi_employee' => 'ESI Employee',
            'esi_employer' => 'ESI Employer',
            'professional_tax' => 'Professional Tax',
            'total_deductions' => 'Total Deductions',
            'net_salary' => 'Net Salary'
        ]
    ],
    'salary_structure' => [
        'label' => 'Salary Structure',
        'columns' => [
            'gross_salary' => 'Gross Salary',
            'basic_salary' => 'Basic Salary',
            'pf_applicable' => 'PF Applicable',
            'esi_applicable' => 'ESI Applicable'
        ]
    ]
];

// Get saved reports (with error handling for missing table)
$savedReports = [];
try {
    $savedReports = $db->query(
        "SELECT * FROM saved_reports WHERE created_by = " . (int)($_SESSION['user_id'] ?? 0) . " ORDER BY created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist yet
    $savedReports = [];
}

// Handle form submission
$reportData = null;
$selectedColumns = [];
$filters = [];
$reportTitle = 'Custom Report';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'generate';
    
    if ($action === 'generate') {
        $selectedTables = $_POST['tables'] ?? [];
        $selectedColumns = $_POST['columns'] ?? [];
        $filters = $_POST['filters'] ?? [];
        $reportTitle = sanitize($_POST['report_title'] ?? 'Custom Report');
        
        if (!empty($selectedColumns)) {
            // Build query
            $selectParts = [];
            $joinParts = [];
            
            foreach ($selectedColumns as $col) {
                list($table, $column) = explode('.', $col);
                $alias = $table . '_' . $column;
                $selectParts[] = "$table.$column as $alias";
            }
            
            // Build base query with joins
            $sql = "SELECT " . implode(', ', $selectParts) . " FROM employees e";
            
            // IMPORTANT: Always JOIN clients and units to get client_name and unit_name
            $sql .= " LEFT JOIN clients c ON e.client_id = c.id";
            $sql .= " LEFT JOIN units u ON e.unit_id = u.id";
            
            if (in_array('attendance', $selectedTables)) {
                // Note: attendance_summary.employee_id stores employees.id (auto-increment), not employee_code
                $sql .= " LEFT JOIN attendance_summary a ON e.id = a.employee_id";
            }
            
            if (in_array('payroll', $selectedTables)) {
                $sql .= " LEFT JOIN payroll p ON e.employee_code = p.employee_id";
            }
            
            if (in_array('salary_structure', $selectedTables)) {
                $sql .= " LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id";
            }
            
            // Add filters
            $whereParts = [];
            $params = [];
            
            if (!empty($filters['client_name'])) {
                $whereParts[] = "c.name = :client_name";
                $params['client_name'] = sanitize($filters['client_name']);
            }
            
            if (!empty($filters['status'])) {
                $whereParts[] = "e.status = :status";
                $params['status'] = sanitize($filters['status']);
            }
            
            if (!empty($filters['month'])) {
                $whereParts[] = "a.month = :month";
                $params['month'] = (int)$filters['month'];
            }
            
            if (!empty($filters['year'])) {
                $whereParts[] = "a.year = :year";
                $params['year'] = (int)$filters['year'];
            }
            
            if (!empty($whereParts)) {
                $sql .= " WHERE " . implode(' AND ', $whereParts);
            }
            
            $sql .= " LIMIT 1000";
            
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                setFlash('error', 'Error generating report: ' . $e->getMessage());
            }
        }
    }
    
    if ($action === 'save' && !empty($_POST['report_name'])) {
        $reportConfig = [
            'tables' => $_POST['tables'] ?? [],
            'columns' => $_POST['columns'] ?? [],
            'filters' => $_POST['filters'] ?? []
        ];
        
        $stmt = $db->prepare(
            "INSERT INTO saved_reports (report_name, report_config, created_by, created_at)
             VALUES (:name, :config, :user, NOW())"
        );
        $stmt->execute([
            'name' => sanitize($_POST['report_name']),
            'config' => json_encode($reportConfig),
            'user' => $_SESSION['user_id']
        ]);
        
        setFlash('success', 'Report saved successfully!');
        redirect('index.php?page=report/custom');
    }
}

// Get clients for filter - use clients table, not employees.client_name
$clients = $db->query("SELECT DISTINCT c.name as client_name FROM clients c WHERE c.is_active = 1 ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-sliders me-2"></i>Report Builder</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="generate">
                    
                    <div class="mb-3">
                        <label class="form-label">Report Title</label>
                        <input type="text" class="form-control" name="report_title" value="<?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Tables</label>
                        <?php foreach ($tables as $tableKey => $tableInfo): ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input table-select" 
                                   name="tables[]" value="<?php echo $tableKey; ?>" 
                                   id="table_<?php echo $tableKey; ?>"
                                   <?php echo in_array($tableKey, ($_POST['tables'] ?? [])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="table_<?php echo $tableKey; ?>">
                                <?php echo $tableInfo['label']; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Columns</label>
                        <div id="columnSelection">
                            <?php foreach ($tables as $tableKey => $tableInfo): ?>
                            <div class="column-group mb-2" data-table="<?php echo $tableKey; ?>">
                                <strong class="small text-muted"><?php echo $tableInfo['label']; ?></strong>
                                <?php foreach ($tableInfo['columns'] as $colKey => $colLabel): ?>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" 
                                           name="columns[]" value="<?php echo $tableKey; ?>.<?php echo $colKey; ?>"
                                           id="col_<?php echo $tableKey; ?>_<?php echo $colKey; ?>"
                                           <?php echo in_array("$tableKey.$colKey", ($_POST['columns'] ?? [])) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="col_<?php echo $tableKey; ?>_<?php echo $colKey; ?>">
                                        <?php echo $colLabel; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <hr>
                    <h6>Filters</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Client</label>
                        <select class="form-select form-select-sm" name="filters[client_name]">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['client_name']); ?>"
                                    <?php echo ($filters['client_name'] ?? '') === $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select form-select-sm" name="filters[status]">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($filters['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="terminated" <?php echo ($filters['status'] ?? '') === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">Month</label>
                            <select class="form-select form-select-sm" name="filters[month]">
                                <option value="">All</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($filters['month'] ?? '') == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Year</label>
                            <select class="form-select form-select-sm" name="filters[year]">
                                <option value="">All</option>
                                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filters['year'] ?? '') == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-play-fill me-1"></i>Generate Report
                        </button>
                    </div>
                    
                    <?php if (!empty($reportData)): ?>
                    <div class="mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm" name="report_name" placeholder="Report name">
                            <input type="hidden" name="action" value="save">
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-bookmark"></i> Save
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Saved Reports -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-bookmark me-2"></i>Saved Reports</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($savedReports)): ?>
                    <div class="list-group-item text-muted text-center">No saved reports</div>
                    <?php else: ?>
                    <?php foreach ($savedReports as $r): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?php echo sanitize($r['report_name']); ?></strong>
                            <br><small class="text-muted"><?php echo formatDate($r['created_at']); ?></small>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" onclick="loadReport(<?php echo $r['id']; ?>)">
                                <i class="bi bi-play"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="exportReport(<?php echo $r['id']; ?>)">
                                <i class="bi bi-download"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-table me-2"></i>
                    <?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($reportData)): ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($reportData); ?> records</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($reportData)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-table fs-1"></i>
                    <p class="mt-3">Select tables and columns to generate a custom report</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($reportData[0]) as $col): ?>
                                <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $col)), ENT_QUOTES, 'UTF-8'); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <?php foreach ($row as $value): ?>
                                <td><?php echo sanitize($value ?? ''); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function loadReport(reportId) {
    // Load saved report configuration and submit form
    $.get('index.php?page=api/reports&id=' + reportId, function(data) {
        if (data.config) {
            // Populate form with saved configuration
            console.log('Loading report:', data);
        }
    });
}

function exportReport(reportId) {
    window.location.href = 'index.php?page=report/custom&export=excel&report_id=' + reportId;
}
</script>
