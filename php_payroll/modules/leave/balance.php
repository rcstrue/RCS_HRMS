<?php
/**
 * RCS HRMS Pro - Leave Balance Management
 * Updated to use INT employee_id
 */
$pageTitle = 'Leave Balance';
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientFilter = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$search = sanitize($_GET['search'] ?? '');

// Create table if not exists (with INT employee_id)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS leave_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT(10) UNSIGNED NOT NULL,
        leave_type ENUM('CL','PL','SL','EL','CO','ML') NOT NULL,
        year INT NOT NULL,
        opening_balance DECIMAL(5,2) DEFAULT 0,
        accrued DECIMAL(5,2) DEFAULT 0,
        used DECIMAL(5,2) DEFAULT 0,
        closing_balance DECIMAL(5,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_emp_leave_year (employee_id, leave_type, year)
    )");
} catch (Exception $e) {
    // Table might already exist
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['employee_id'])) {
    try {
        $employeeId = (int)$_POST['employee_id'];
        $leaveType = sanitize($_POST['leave_type']);
        $openingBalance = floatval($_POST['opening_balance']);
        $accrued = floatval($_POST['accrued']);
        $used = floatval($_POST['used']);
        $closingBalance = floatval($_POST['closing_balance']);
        
        $stmt = $db->prepare("INSERT INTO leave_balances (employee_id, leave_type, year, opening_balance, accrued, used, closing_balance) 
            VALUES (?,?,?,?,?,?,?) 
            ON DUPLICATE KEY UPDATE 
                opening_balance=VALUES(opening_balance), 
                accrued=VALUES(accrued), 
                used=VALUES(used), 
                closing_balance=VALUES(closing_balance)");
        $stmt->execute([$employeeId, $leaveType, $yearFilter, $openingBalance, $accrued, $used, $closingBalance]);
        setFlash('success', 'Leave balance updated!');
        redirect('index.php?page=leave/balance');
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
}

$where = "WHERE e.status = 'approved'";
$params = [];
if ($clientFilter) { 
    $where .= " AND e.client_id = ?"; 
    $params[] = $clientFilter; 
}
if ($search) { 
    $where .= " AND (e.employee_code LIKE ? OR e.full_name LIKE ?)"; 
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$stmt = $db->prepare("SELECT e.id, e.employee_code, e.full_name, c.name as client_name,
    u.name as unit_name
    FROM employees e LEFT JOIN clients c ON e.client_id = c.id
    LEFT JOIN units u ON e.unit_id = u.id
    $where ORDER BY e.employee_code");
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clients = [];
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}
$leaveTypes = ['CL'=>'Casual Leave','PL'=>'Privilege Leave','SL'=>'Sick Leave','EL'=>'Earned Leave','CO'=>'Compensatory Off','ML'=>'Medical Leave'];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-calendar-x me-2"></i>Leave Balance - <?php echo htmlspecialchars($yearFilter, ENT_QUOTES, 'UTF-8'); ?></h4>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBalanceModal"><i class="bi bi-plus-lg me-1"></i>Add Balance</button>
        </div>
        
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="leave/balance">
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?><option value="<?php echo $y; ?>" <?php echo $y == $yearFilter ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $clientFilter == $c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Emp Code</th>
                                <th>Name</th>
                                <th>Client</th>
                                <?php foreach ($leaveTypes as $lt => $ln): ?><th class="text-center"><?php echo $lt; ?></th><?php endforeach; ?>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees)): ?>
                            <tr><td colspan="<?php echo 6 + count($leaveTypes); ?>" class="text-center py-4">No employees found</td></tr>
                            <?php else: ?>
                            <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?php echo sanitize($emp['employee_code']); ?></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td><?php echo sanitize($emp['client_name']); ?></td>
                                <?php foreach ($leaveTypes as $lt): 
                                    $stmt = $db->prepare("SELECT closing_balance FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = ?");
                                    $stmt->execute([$emp['id'], $lt, $yearFilter]);
                                    $bal = $stmt->fetchColumn() ?: '-';
                                ?>
                                <td class="text-center"><span class="badge bg-<?php echo $bal != '-' ? 'success' : 'secondary'; ?>"><?php echo $bal; ?></span></td>
                                <?php endforeach; ?>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="editBalance('<?php echo $emp['id']; ?>')"><i class="bi bi-pencil"></i></button>
                                </td>
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

<div class="modal fade" id="addBalanceModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Add/Update Leave Balance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="employee_id" id="modalEmployeeId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Leave Type</label>
                        <select name="leave_type" class="form-select">
                            <?php foreach ($leaveTypes as $lt => $ln): ?><option value="<?php echo $lt; ?>"><?php echo $ln; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Year</label>
                        <input type="number" name="year" class="form-control" value="<?php echo htmlspecialchars($yearFilter, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Opening</label>
                        <input type="number" name="opening_balance" class="form-control" step="0.5" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Accrued</label>
                        <input type="number" name="accrued" class="form-control" step="0.5" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Used</label>
                        <input type="number" name="used" class="form-control" step="0.5" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Closing</label>
                        <input type="number" name="closing_balance" class="form-control" step="0.5" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function editBalance(empId) {
    document.getElementById('modalEmployeeId').value = empId;
    new bootstrap.Modal(document.getElementById('addBalanceModal')).show();
}
</script>
