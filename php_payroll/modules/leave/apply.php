<?php
/**
 * RCS HRMS Pro - Leave Application Module
 * Apply, Approve, Reject leave with balance tracking
 */

$pageTitle = 'Leave Management';

// Create leave_applications table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS leave_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT(10) UNSIGNED NOT NULL,
        leave_type ENUM('CL','PL','SL','EL','CO','ML','LWP') NOT NULL,
        from_date DATE NOT NULL,
        to_date DATE NOT NULL,
        total_days DECIMAL(5,1) DEFAULT 0.5,
        reason TEXT,
        status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
        approved_by INT DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        rejection_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_employee (employee_id),
        KEY idx_status (status)
    )");
} catch (Exception $e) {}

// Ensure leave_balances exists
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
} catch (Exception $e) {}

$activeTab = $_GET['tab'] ?? 'applications';
$leaveTypes = ['CL'=>'Casual Leave','PL'=>'Privilege Leave','SL'=>'Sick Leave','EL'=>'Earned Leave','CO'=>'Compensatory Off','ML'=>'Medical Leave','LWP'=>'Leave Without Pay'];
$statusColors = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'];
$currentYear = date('Y');

// Handle Apply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply') {
    $empId = (int)$_POST['employee_id'];
    $leaveType = sanitize($_POST['leave_type']);
    $fromDate = sanitize($_POST['from_date']);
    $toDate = sanitize($_POST['to_date']);
    $totalDays = floatval($_POST['total_days']);
    $reason = sanitize($_POST['reason'] ?? '');

    if (!$empId || !$fromDate || !$toDate) {
        setFlash('error', 'Please fill all required fields.');
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO leave_applications (employee_id, leave_type, from_date, to_date, total_days, reason, status) VALUES (?,?,?,?,?,?,'pending')");
            $stmt->execute([$empId, $leaveType, $fromDate, $toDate, $totalDays, $reason]);
            setFlash('success', 'Leave application submitted successfully!');
        } catch (Exception $e) {
            setFlash('error', 'Error: ' . $e->getMessage());
        }
    }
    redirect('index.php?page=leave/apply&tab=applications');
}

// Handle Approve/Reject
if (isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
    $appId = (int)$_POST['application_id'];
    $action = $_POST['action'];

    try {
        $app = $db->fetch("SELECT la.*, e.employee_code, e.full_name FROM leave_applications la JOIN employees e ON la.employee_id = e.id WHERE la.id = ?", [$appId]);
        if ($app) {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $rejectionReason = $action === 'reject' ? sanitize($_POST['rejection_reason'] ?? '') : null;

            $db->query(
                "UPDATE leave_applications SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?",
                [$newStatus, $_SESSION['user_id'], $rejectionReason, $appId]
            );

            // If approved, update leave balance
            if ($action === 'approve' && $app['leave_type'] !== 'LWP') {
                $year = date('Y', strtotime($app['from_date']));
                $bal = $db->fetch("SELECT id, used, closing_balance FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = ?", [$app['employee_id'], $app['leave_type'], $year]);

                if ($bal) {
                    $newUsed = floatval($bal['used']) + floatval($app['total_days']);
                    $newClosing = floatval($bal['closing_balance']) - floatval($app['total_days']);
                    $db->query("UPDATE leave_balances SET used = ?, closing_balance = ? WHERE id = ?", [$newUsed, $newClosing, $bal['id']]);
                }
            }

            // If rejected and was previously approved, restore balance
            if ($action === 'reject' && $app['status'] === 'approved' && $app['leave_type'] !== 'LWP') {
                $year = date('Y', strtotime($app['from_date']));
                $bal = $db->fetch("SELECT id, used, closing_balance FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = ?", [$app['employee_id'], $app['leave_type'], $year]);
                if ($bal) {
                    $newUsed = max(0, floatval($bal['used']) - floatval($app['total_days']));
                    $newClosing = floatval($bal['closing_balance']) + floatval($app['total_days']);
                    $db->query("UPDATE leave_balances SET used = ?, closing_balance = ? WHERE id = ?", [$newUsed, $newClosing, $bal['id']]);
                }
            }

            setFlash('success', 'Leave ' . $newStatus . '!');
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    redirect('index.php?page=leave/apply&tab=' . $activeTab);
}

// Handle Cancel
if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $appId = (int)$_POST['application_id'];
    try {
        $app = $db->fetch("SELECT * FROM leave_applications WHERE id = ?", [$appId]);
        if ($app && $app['status'] === 'pending') {
            $db->query("UPDATE leave_applications SET status = 'cancelled' WHERE id = ?", [$appId]);
            setFlash('success', 'Leave application cancelled.');
        }
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    redirect('index.php?page=leave/apply&tab=' . $activeTab);
}

// Get employees for apply form
$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch applications with filters
$filterStatus = sanitize($_GET['status'] ?? '');
$filterClient = (int)($_GET['client_id'] ?? 0);
$filterMonth = (int)($_GET['month'] ?? 0);
$filterYear = (int)($_GET['year'] ?? date('Y'));

$where = "1=1";
$params = [];
if ($filterStatus) { $where .= " AND la.status = :status"; $params[':status'] = $filterStatus; }
if ($filterClient) { $where .= " AND e.client_id = :cid"; $params[':cid'] = $filterClient; }
if ($filterMonth) { $where .= " AND MONTH(la.from_date) = :month"; $params[':month'] = $filterMonth; }
if ($filterYear) { $where .= " AND YEAR(la.from_date) = :year"; $params[':year'] = $filterYear; }

$applications = $db->fetchAll(
    "SELECT la.*, e.employee_code, e.full_name, c.name as client_name, u.name as unit_name,
            COALESCE(CONCAT(u2.first_name, ' ', u2.last_name), u2.username) AS approver_name
     FROM leave_applications la
     JOIN employees e ON la.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     LEFT JOIN users u2 ON la.approved_by = u2.id
     WHERE $where
     ORDER BY la.created_at DESC
     LIMIT 200",
    $params
);

$pendingCount = $db->fetchColumn("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'") ?: 0;
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-calendar-x me-2"></i>Leave Management</h4>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#applyModal">
                <i class="bi bi-plus-lg me-1"></i>Apply Leave
            </button>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'applications' ? 'active' : ''; ?>" href="?page=leave/apply&tab=applications">
                    Applications <?php if ($pendingCount > 0): ?><span class="badge bg-danger ms-1"><?php echo $pendingCount; ?></span><?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'balance' ? 'active' : ''; ?>" href="?page=leave/apply&tab=balance">Leave Balance</a>
            </li>
        </ul>

        <?php if ($activeTab === 'balance'): ?>
        <!-- Leave Balance Tab -->
        <div class="card">
            <div class="card-header">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="leave/apply">
                    <input type="hidden" name="tab" value="balance">
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" id="balanceTable">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Employee</th>
                                <th>Unit</th>
                                <?php foreach (['CL','PL','SL','EL'] as $lt): ?>
                                <th class="text-center"><?php echo $lt; ?></th>
                                <?php endforeach; ?>
                                <th class="text-center">Total Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $balYear = (int)($_GET['year'] ?? date('Y'));
                            $balClient = (int)($_GET['client_id'] ?? 0);
                            $balWhere = "e.status = 'approved'";
                            $balParams = [];
                            if ($balClient) { $balWhere .= " AND e.client_id = ?"; $balParams[] = $balClient; }
                            $emps = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, u.name as unit_name FROM employees e LEFT JOIN units u ON e.unit_id = u.id WHERE $balWhere ORDER BY e.employee_code LIMIT 200", $balParams);
                            foreach ($emps as $emp):
                                $bals = $db->fetchAll("SELECT leave_type, opening_balance, accrued, used, closing_balance FROM leave_balances WHERE employee_id = ? AND year = ?", [$emp['id'], $balYear]);
                                $balMap = [];
                                $totalUsed = 0;
                                foreach ($bals as $b) { $balMap[$b['leave_type']] = $b; $totalUsed += floatval($b['used']); }
                            ?>
                            <tr>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['unit_name']); ?></td>
                                <?php foreach (['CL','PL','SL','EL'] as $lt):
                                    $b = $balMap[$lt] ?? null;
                                    $closing = $b ? floatval($b['closing_balance']) : 0;
                                    $used = $b ? floatval($b['used']) : 0;
                                ?>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $closing > 0 ? 'success' : 'secondary'; ?>" title="Opening: <?php echo $b ? $b['opening_balance'] : 0; ?> | Used: <?php echo $used; ?>">
                                        <?php echo $closing; ?>
                                    </span>
                                </td>
                                <?php endforeach; ?>
                                <td class="text-center"><strong><?php echo $totalUsed; ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Applications Tab -->
        <div class="card">
            <div class="card-header py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="leave/apply">
                    <input type="hidden" name="tab" value="applications">
                    <div class="col-auto">
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $filterStatus==='pending'?'selected':''; ?>>Pending</option>
                            <option value="approved" <?php echo $filterStatus==='approved'?'selected':''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filterStatus==='rejected'?'selected':''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filterClient==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $filterMonth==$m?'selected':''; ?>><?php echo date('M', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y = date('Y'); $y >= date('Y')-2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $filterYear==$y?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" id="leaveAppTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Emp Code</th>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>From</th>
                                <th>To</th>
                                <th class="text-center">Days</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-muted">No leave applications found</td></tr>
                            <?php else: foreach ($applications as $i => $app): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><code><?php echo sanitize($app['employee_code']); ?></code></td>
                                <td><?php echo sanitize($app['full_name']); ?></td>
                                <td><span class="badge bg-info"><?php echo $app['leave_type']; ?></span></td>
                                <td><?php echo date('d-M-Y', strtotime($app['from_date'])); ?></td>
                                <td><?php echo date('d-M-Y', strtotime($app['to_date'])); ?></td>
                                <td class="text-center"><strong><?php echo $app['total_days']; ?></strong></td>
                                <td class="text-truncate" style="max-width:150px;" title="<?php echo htmlspecialchars($app['reason'] ?? ''); ?>"><?php echo htmlspecialchars(substr($app['reason'] ?? '', 0, 40)); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $statusColors[$app['status']]; ?>">
                                        <?php echo ucfirst($app['status']); ?>
                                    </span>
                                    <?php if ($app['approved_at']): ?>
                                    <br><small class="text-muted"><?php echo date('d-M h:i A', strtotime($app['approved_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($app['status'] === 'pending'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Approve this leave?')">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                            <button class="btn btn-success" title="Approve"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                        <button class="btn btn-danger" title="Reject" data-bs-toggle="modal" data-bs-target="#rejectModal" onclick="setRejectId(<?php echo $app['id']; ?>)"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Apply Leave Modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Apply Leave</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="apply">
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required id="empSelect">
                            <option value="">Select Employee</option>
                            <?php
                            $allEmps = $db->query("SELECT e.id, e.employee_code, e.full_name, c.name as client_name
                                FROM employees e LEFT JOIN clients c ON e.client_id = c.id WHERE e.status = 'approved' ORDER BY c.name, e.full_name")->fetchAll(PDO::FETCH_ASSOC);
                            $lastClient = '';
                            foreach ($allEmps as $ae):
                                if ($ae['client_name'] !== $lastClient) {
                                    if ($lastClient) echo '</optgroup>';
                                    echo '<optgroup label="' . sanitize($ae['client_name']) . '">';
                                    $lastClient = $ae['client_name'];
                                }
                            ?>
                            <option value="<?php echo $ae['id']; ?>"><?php echo sanitize($ae['employee_code'] . ' - ' . $ae['full_name']); ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                        <select name="leave_type" class="form-select" required>
                            <?php foreach ($leaveTypes as $lt => $ln): ?>
                            <option value="<?php echo $lt; ?>"><?php echo $ln; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Total Days</label>
                        <input type="number" name="total_days" class="form-control" step="0.5" min="0.5" value="1" id="totalDays">
                    </div>
                    <div class="col-6">
                        <label class="form-label">From Date <span class="text-danger">*</span></label>
                        <input type="date" name="from_date" class="form-control" required id="fromDate">
                    </div>
                    <div class="col-6">
                        <label class="form-label">To Date <span class="text-danger">*</span></label>
                        <input type="date" name="to_date" class="form-control" required id="toDate">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Reason for leave"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="application_id" id="rejectAppId">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reject Leave</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Rejection Reason</label>
                    <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Enter reason for rejection"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Reject</button>
            </div>
        </form>
    </div></div>
</div>

<script>
function setRejectId(id) {
    document.getElementById('rejectAppId').value = id;
}

// Auto-calculate days
document.getElementById('fromDate')?.addEventListener('change', calcDays);
document.getElementById('toDate')?.addEventListener('change', calcDays);

function calcDays() {
    const from = document.getElementById('fromDate').value;
    const to = document.getElementById('toDate').value;
    if (from && to) {
        const d1 = new Date(from), d2 = new Date(to);
        const diff = Math.ceil((d2 - d1) / (1000*60*60*24)) + 1;
        document.getElementById('totalDays').value = Math.max(0.5, diff);
    }
}

$(document).ready(function() {
    $('#leaveAppTable, #balanceTable').DataTable({ responsive: true, pageLength: 25, order: [[0,'desc']] });
    $('#empSelect').select2({ placeholder: 'Search employee...', width: '100%' });
});
</script>
