<?php
/**
 * RCS HRMS Pro - Create Timesheet
 * Create and manage employee timesheets
 */

$pageTitle = 'Create Timesheet';

// Check if timesheets table exists, create if not
try {
    $db->query("CREATE TABLE IF NOT EXISTS timesheets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timesheet_code VARCHAR(50) UNIQUE,
        client_id INT,
        unit_id INT,
        month INT NOT NULL,
        year INT NOT NULL,
        start_date DATE,
        end_date DATE,
        total_employees INT DEFAULT 0,
        total_hours DECIMAL(10,2) DEFAULT 0,
        total_overtime_hours DECIMAL(10,2) DEFAULT 0,
        status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
        remarks TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        approved_by INT,
        approved_at TIMESTAMP NULL,
        INDEX idx_client_month_year (client_id, month, year),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $db->query("CREATE TABLE IF NOT EXISTS timesheet_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timesheet_id INT NOT NULL,
        employee_id INT NOT NULL,
        employee_code VARCHAR(50),
        date DATE NOT NULL,
        shift_start TIME,
        shift_end TIME,
        total_hours DECIMAL(5,2) DEFAULT 0,
        overtime_hours DECIMAL(5,2) DEFAULT 0,
        is_present TINYINT(1) DEFAULT 1,
        remarks VARCHAR(255),
        INDEX idx_timesheet_employee (timesheet_id, employee_id),
        INDEX idx_date (date),
        FOREIGN KEY (timesheet_id) REFERENCES timesheets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Ignore table creation errors
}

// Get filters
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedClient = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$selectedUnit = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;

// Get clients for dropdown
$clients = [];
try {
    $clientsStmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get units based on selected client
$units = [];
if ($selectedClient) {
    try {
        $unitsStmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
        $unitsStmt->execute([$selectedClient]);
        $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);
    
    if ($action === 'create_timesheet') {
        $clientId = (int)$_POST['client_id'];
        $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
        $month = (int)$_POST['month'];
        $year = (int)$_POST['year'];
        $startDate = sanitize($_POST['start_date']);
        $endDate = sanitize($_POST['end_date']);
        $remarks = sanitize($_POST['remarks'] ?? '');
        
        // Generate timesheet code
        $timesheetCode = sprintf('TS-%s-%s-%04d', $year, str_pad($month, 2, '0', STR_PAD_LEFT), rand(1000, 9999));
        
        try {
            $db->beginTransaction();
            
            // Create timesheet header
            $insertStmt = $db->prepare("INSERT INTO timesheets (timesheet_code, client_id, unit_id, month, year, start_date, end_date, remarks, created_by) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([$timesheetCode, $clientId, $unitId, $month, $year, $startDate, $endDate, $remarks, $_SESSION['user_id']]);
            $timesheetId = $db->lastInsertId();
            
            // Get employees for the client/unit
            $empQuery = "SELECT id, employee_code, full_name FROM employees WHERE status = 'approved' AND client_id = ?";
            $empParams = [$clientId];
            if ($unitId) {
                $empQuery .= " AND unit_id = ?";
                $empParams[] = $unitId;
            }
            
            $empStmt = $db->prepare($empQuery);
            $empStmt->execute($empParams);
            $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create timesheet entries for each employee for each day in the period
            $entryStmt = $db->prepare("INSERT INTO timesheet_entries (timesheet_id, employee_id, employee_code, date, total_hours, is_present) 
                                       VALUES (?, ?, ?, ?, 8.00, 1)");
            
            $currentDate = strtotime($startDate);
            $endDateTime = strtotime($endDate);
            $totalEmployees = count($employees);
            $totalHours = 0;
            
            while ($currentDate <= $endDateTime) {
                $dateStr = date('Y-m-d', $currentDate);
                $dayOfWeek = date('N', $currentDate); // 1 = Monday, 7 = Sunday
                
                // Skip Sundays (optional - remove if you want to include Sundays)
                if ($dayOfWeek < 7) {
                    foreach ($employees as $emp) {
                        $entryStmt->execute([$timesheetId, $emp['id'], $emp['employee_code'], $dateStr, 8.00]);
                        $totalHours += 8;
                    }
                }
                
                $currentDate = strtotime('+1 day', $currentDate);
            }
            
            // Update timesheet totals
            $updateStmt = $db->prepare("UPDATE timesheets SET total_employees = ?, total_hours = ? WHERE id = ?");
            $updateStmt->execute([$totalEmployees, $totalHours, $timesheetId]);
            
            $db->commit();
            
            logActivity('timesheet_created', "Created timesheet $timesheetCode");
            setFlash('success', "Timesheet created successfully. Code: $timesheetCode");
            redirect("index.php?page=timesheet/list");
            
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Error creating timesheet: ' . $e->getMessage());
        }
    }
}

// Calculate date range for the selected month
$startDate = date('Y-m-01', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
$endDate = date('Y-m-t', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
$workingDays = 0;
$currentDate = strtotime($startDate);
$endDateTime = strtotime($endDate);
while ($currentDate <= $endDateTime) {
    if (date('N', $currentDate) < 7) { // Not Sunday
        $workingDays++;
    }
    $currentDate = strtotime('+1 day', $currentDate);
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-calendar-plus me-2"></i>Create Timesheet
                </h5>
            </div>
            
            <div class="card-body">
                <form method="POST" id="timesheetForm">
                    <input type="hidden" name="action" value="create_timesheet">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Month</label>
                            <select class="form-select" name="month" id="monthSelect" required onchange="updateDateRange()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Year</label>
                            <select class="form-select" name="year" id="yearSelect" required onchange="updateDateRange()">
                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Client</label>
                            <select class="form-select" name="client_id" id="clientSelect" required onchange="loadUnits()">
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $selectedClient == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Unit (Optional)</label>
                            <select class="form-select" name="unit_id" id="unitSelect">
                                <option value="">All Units</option>
                                <?php foreach ($units as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnit == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($u['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="startDate" value="<?php echo $startDate; ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">End Date</label>
                            <input type="date" class="form-control" name="end_date" id="endDate" value="<?php echo $endDate; ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes about this timesheet"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i>Create Timesheet
                        </button>
                        <a href="index.php?page=timesheet/list" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Preview Card -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Timesheet Preview</h6>
            </div>
            <div class="card-body">
                <div id="previewContent">
                    <p class="text-muted mb-2">Period: <strong id="previewPeriod"><?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?></strong></p>
                    <p class="text-muted mb-2">Working Days: <strong id="previewDays"><?php echo $workingDays; ?> days</strong></p>
                    <p class="text-muted mb-2">Employees: <strong id="previewEmployees">-</strong></p>
                    <hr>
                    <p class="text-muted mb-2">Estimated Hours: <strong id="previewHours">-</strong></p>
                </div>
                
                <div class="alert alert-info mt-3 mb-0">
                    <i class="bi bi-lightbulb me-2"></i>
                    <small>After creating the timesheet, you can edit individual entries to add actual work hours and overtime.</small>
                </div>
            </div>
        </div>
        
        <!-- Instructions -->
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Instructions</h6>
            </div>
            <div class="card-body">
                <ol class="small mb-0">
                    <li>Select the month and year for the timesheet</li>
                    <li>Choose the client (and optionally a specific unit)</li>
                    <li>Adjust the start and end dates if needed</li>
                    <li>Click "Create Timesheet" to generate</li>
                    <li>Edit individual entries to add actual hours</li>
                    <li>Submit the timesheet for approval</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function updateDateRange() {
    const month = document.getElementById('monthSelect').value;
    const year = document.getElementById('yearSelect').value;
    
    // Calculate first and last day of month
    const startDate = new Date(year, month - 1, 1);
    const endDate = new Date(year, month, 0);
    
    const formatDate = (date) => date.toISOString().split('T')[0];
    
    document.getElementById('startDate').value = formatDate(startDate);
    document.getElementById('endDate').value = formatDate(endDate);
    
    // Update preview
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
    document.getElementById('previewPeriod').textContent = monthNames[month - 1] + ' ' + year;
    
    // Calculate working days (excluding Sundays)
    let workingDays = 0;
    let currentDate = new Date(startDate);
    while (currentDate <= endDate) {
        if (currentDate.getDay() !== 0) { // Not Sunday
            workingDays++;
        }
        currentDate.setDate(currentDate.getDate() + 1);
    }
    document.getElementById('previewDays').textContent = workingDays + ' days';
    
    updateEmployeePreview();
}

function loadUnits() {
    const clientId = document.getElementById('clientSelect').value;
    const unitSelect = document.getElementById('unitSelect');
    
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!clientId) {
        unitSelect.innerHTML = '<option value="">All Units</option>';
        document.getElementById('previewEmployees').textContent = '-';
        document.getElementById('previewHours').textContent = '-';
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
            updateEmployeePreview();
        })
        .catch(() => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
        });
}

function updateEmployeePreview() {
    const clientId = document.getElementById('clientSelect').value;
    if (!clientId) {
        document.getElementById('previewEmployees').textContent = '-';
        document.getElementById('previewHours').textContent = '-';
        return;
    }
    
    // Fetch employee count
    fetch('index.php?page=api/employees&client_id=' + clientId + '&count_only=1')
        .then(response => response.json())
        .then(data => {
            if (data.count !== undefined) {
                const empCount = data.count;
                const workingDays = parseInt(document.getElementById('previewDays').textContent);
                document.getElementById('previewEmployees').textContent = empCount;
                document.getElementById('previewHours').textContent = (empCount * workingDays * 8).toLocaleString() + ' hrs';
            }
        })
        .catch(() => {
            document.getElementById('previewEmployees').textContent = '-';
            document.getElementById('previewHours').textContent = '-';
        });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('clientSelect').addEventListener('change', updateEmployeePreview);
    document.getElementById('unitSelect').addEventListener('change', updateEmployeePreview);
});
</script>
