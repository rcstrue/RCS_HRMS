<?php
/**
 * RCS HRMS Pro - Leave Entry (Admin)
 * Admin can manually enter leave records for employees
 */

$pageTitle = 'Leave Entry';

// Ensure leave_applications table exists
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

// Leave types
$leaveTypes = [
    'CL' => 'Casual Leave',
    'PL' => 'Privilege Leave',
    'SL' => 'Sick Leave',
    'EL' => 'Earned Leave',
    'CO' => 'Compensatory Off',
    'ML' => 'Medical Leave'
];

$statusColors = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];

// Handle POST - Add Leave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_leave'])) {
    $empId = (int)($_POST['employee_id'] ?? 0);
    $leaveType = sanitize($_POST['leave_type'] ?? '');
    $fromDate = sanitize($_POST['from_date'] ?? '');
    $toDate = sanitize($_POST['to_date'] ?? '');
    $totalDays = floatval($_POST['total_days'] ?? 1);
    $reason = sanitize($_POST['reason'] ?? '');
    $autoApprove = isset($_POST['auto_approve']);

    if (!$empId || !$leaveType || !$fromDate || !$toDate) {
        setFlash('error', 'Please fill all required fields (Employee, Leave Type, From Date, To Date).');
    } elseif (!in_array($leaveType, array_keys($leaveTypes))) {
        setFlash('error', 'Invalid leave type selected.');
    } elseif (strtotime($toDate) < strtotime($fromDate)) {
        setFlash('error', 'To Date cannot be before From Date.');
    } else {
        try {
            $status = $autoApprove ? 'approved' : 'pending';
            $approvedBy = $autoApprove ? ($_SESSION['user_id'] ?? null) : null;
            $approvedAt = $autoApprove ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare(
                "INSERT INTO leave_applications 
                 (employee_id, leave_type, from_date, to_date, total_days, reason, status, approved_by, approved_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$empId, $leaveType, $fromDate, $toDate, $totalDays, $reason, $status, $approvedBy, $approvedAt]);

            // If approved, update leave balance
            if ($autoApprove && $leaveType !== 'LWP') {
                $year = date('Y', strtotime($fromDate));
                try {
                    $bal = $db->fetch(
                        "SELECT id, used, closing_balance FROM leave_balances 
                         WHERE employee_id = ? AND leave_type = ? AND year = ?",
                        [$empId, $leaveType, $year]
                    );
                    if ($bal) {
                        $newUsed = floatval($bal['used']) + $totalDays;
                        $newClosing = floatval($bal['closing_balance']) - $totalDays;
                        $db->query(
                            "UPDATE leave_balances SET used = ?, closing_balance = ? WHERE id = ?",
                            [$newUsed, $newClosing, $bal['id']]
                        );
                    }
                } catch (Exception $e) {
                    // leave_balances table might not have a record for this type/year
                }
            }

            $statusText = $autoApprove ? 'approved' : 'submitted';
            setFlash('success', "Leave {$statusText} successfully! {$totalDays} day(s) of {$leaveType}.");
        } catch (Exception $e) {
            setFlash('error', 'Error saving leave: ' . $e->getMessage());
        }
        redirect('index.php?page=entry/leave-entry');
    }
}

// Handle delete
if (isset($_POST['delete_leave']) && isset($_POST['leave_id'])) {
    $leaveId = (int)$_POST['leave_id'];
    try {
        // If it was approved, restore balance
        $leave = $db->fetch(
            "SELECT * FROM leave_applications WHERE id = ? AND status = 'approved'",
            [$leaveId]
        );
        if ($leave && $leave['leave_type'] !== 'LWP') {
            $year = date('Y', strtotime($leave['from_date']));
            try {
                $bal = $db->fetch(
                    "SELECT id, used, closing_balance FROM leave_balances 
                     WHERE employee_id = ? AND leave_type = ? AND year = ?",
                    [$leave['employee_id'], $leave['leave_type'], $year]
                );
                if ($bal) {
                    $newUsed = max(0, floatval($bal['used']) - floatval($leave['total_days']));
                    $newClosing = floatval($bal['closing_balance']) + floatval($leave['total_days']);
                    $db->query(
                        "UPDATE leave_balances SET used = ?, closing_balance = ? WHERE id = ?",
                        [$newUsed, $newClosing, $bal['id']]
                    );
                }
            } catch (Exception $e) {}
        }

        $db->query("DELETE FROM leave_applications WHERE id = ?", [$leaveId]);
        setFlash('success', 'Leave entry deleted successfully.');
    } catch (Exception $e) {
        setFlash('error', 'Error deleting leave: ' . $e->getMessage());
    }
    redirect('index.php?page=entry/leave-entry');
}

// Get filters for recent entries
$filterStatus = sanitize($_GET['status'] ?? '');
$filterType = sanitize($_GET['leave_type'] ?? '');
$filterClient = (int)($_GET['client_id'] ?? 0);
$filterMonth = (int)($_GET['month'] ?? 0);
$filterYear = (int)($_GET['year'] ?? date('Y'));

// Get clients for filter dropdown
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");

// Build query for recent entries
$where = "1=1";
$params = [];
if ($filterStatus) { $where .= " AND la.status = :status"; $params[':status'] = $filterStatus; }
if ($filterType) { $where .= " AND la.leave_type = :ltype"; $params[':ltype'] = $filterType; }
if ($filterClient) { $where .= " AND e.client_id = :cid"; $params[':cid'] = $filterClient; }
if ($filterMonth) { $where .= " AND MONTH(la.from_date) = :month"; $params[':month'] = $filterMonth; }
if ($filterYear) { $where .= " AND YEAR(la.from_date) = :year"; $params[':year'] = $filterYear; }

$recentEntries = $db->fetchAll(
    "SELECT la.*, e.employee_code, e.full_name, 
            c.name as client_name, u.name as unit_name
     FROM leave_applications la
     JOIN employees e ON la.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     WHERE $where
     ORDER BY la.created_at DESC
     LIMIT 100",
    $params
);

// Get employees for dropdown
$allEmployees = $db->fetchAll(
    "SELECT e.id, e.employee_code, e.full_name, c.name as client_name, u.name as unit_name
     FROM employees e
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     WHERE e.status = 'approved'
     ORDER BY c.name, u.name, e.full_name"
);

// Summary stats
$todayEntries = $db->fetchColumn(
    "SELECT COUNT(*) FROM leave_applications WHERE DATE(created_at) = CURDATE()"
) ?: 0;
$totalApproved = $db->fetchColumn(
    "SELECT COUNT(*) FROM leave_applications WHERE status = 'approved' AND YEAR(created_at) = YEAR(CURDATE())"
) ?: 0;
$totalPending = $db->fetchColumn(
    "SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'"
) ?: 0;

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<div class="row">
    <div class="col-12">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-calendar-plus me-2"></i>Leave Entry</h4>
            <div class="d-flex gap-1">
                <?php if (isset($_GET['export']) && $_GET['export'] === 'csv'): ?>
                <?php
                // CSV Export inline
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="leave_entries_' . date('Ymd') . '.csv"');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Emp Code', 'Employee', 'Client', 'Unit', 'Type', 'From', 'To', 'Days', 'Status', 'Reason', 'Created']);
                foreach ($recentEntries as $r) {
                    fputcsv($output, [
                        $r['employee_code'], $r['full_name'], $r['client_name'] ?? '',
                        $r['unit_name'] ?? '', $r['leave_type'], $r['from_date'], $r['to_date'],
                        $r['total_days'], $r['status'], $r['reason'] ?? '', $r['created_at']
                    ]);
                }
                fclose($output);
                exit;
                ?>
                <?php endif; ?>
                <a href="index.php?page=entry/leave-entry&<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                   class="btn btn-outline-success btn-sm">
                    <i class="bi bi-download me-1"></i>Export
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Add Leave Form -->
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Leave Entry</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="leaveForm">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                                    <select name="employee_id" class="form-select form-select-sm" required id="empSelect">
                                        <option value="">Search & Select Employee...</option>
                                        <?php 
                                        $lastClient = '';
                                        foreach ($allEmployees as $ae):
                                            $label = $ae['client_name'] . ' › ' . $ae['unit_name'];
                                            if ($label !== $lastClient):
                                                if ($lastClient) echo '</optgroup>';
                                                echo '<optgroup label="' . sanitize($label) . '">';
                                                $lastClient = $label;
                                            endif;
                                        ?>
                                        <option value="<?php echo $ae['id']; ?>">
                                            <?php echo sanitize($ae['employee_code'] . ' - ' . $ae['full_name']); ?>
                                        </option>
                                        <?php endforeach; if ($lastClient) echo '</optgroup>'; ?>
                                    </select>
                                </div>
                                
                                <div class="col-6">
                                    <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                                    <select name="leave_type" class="form-select form-select-sm" required>
                                        <option value="">Select</option>
                                        <?php foreach ($leaveTypes as $lt => $ln): ?>
                                        <option value="<?php echo $lt; ?>"><?php echo $lt . ' - ' . $ln; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-6">
                                    <label class="form-label">Total Days</label>
                                    <input type="number" name="total_days" class="form-control form-control-sm" 
                                           id="totalDays" step="0.5" min="0.5" value="1" required>
                                </div>
                                
                                <div class="col-6">
                                    <label class="form-label">From Date <span class="text-danger">*</span></label>
                                    <input type="date" name="from_date" class="form-control form-control-sm" 
                                           id="fromDate" required>
                                </div>
                                
                                <div class="col-6">
                                    <label class="form-label">To Date <span class="text-danger">*</span></label>
                                    <input type="date" name="to_date" class="form-control form-control-sm" 
                                           id="toDate" required>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Reason</label>
                                    <textarea name="reason" class="form-control form-control-sm" rows="2" 
                                              placeholder="Reason for leave..."></textarea>
                                </div>
                                
                                <div class="col-12">
                                    <div class="form-check">
                                        <input type="checkbox" name="auto_approve" class="form-check-input" id="autoApprove" checked>
                                        <label class="form-check-label small" for="autoApprove">
                                            Auto-approve (Admin entry)
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" name="add_leave" class="btn btn-primary w-100">
                                        <i class="bi bi-check-lg me-1"></i>Add Leave
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Quick Stats</h6>
                        <div class="d-flex flex-column gap-2">
                            <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                <small class="text-muted">Today's Entries</small>
                                <span class="badge bg-primary"><?php echo $todayEntries; ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center p-2 bg-success bg-opacity-10 rounded">
                                <small class="text-success">Approved (This Year)</small>
                                <span class="badge bg-success"><?php echo $totalApproved; ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center p-2 bg-warning bg-opacity-10 rounded">
                                <small class="text-warning">Pending Approval</small>
                                <span class="badge bg-warning text-dark"><?php echo $totalPending; ?></span>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-2">Leave Types</h6>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($leaveTypes as $lt => $ln): ?>
                            <span class="badge bg-info"><?php echo $lt; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Entries -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header py-2">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Recent Leave Entries</h6>
                    </div>
                    <!-- Filter row -->
                    <div class="card-body py-2 border-bottom">
                        <form method="GET" class="row g-2">
                            <input type="hidden" name="page" value="entry/leave-entry">
                            <div class="col-md-2">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <?php foreach ($statusColors as $s => $c): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($s); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="leave_type" class="form-select form-select-sm">
                                    <option value="">All Types</option>
                                    <?php foreach ($leaveTypes as $lt => $ln): ?>
                                    <option value="<?php echo $lt; ?>" <?php echo $filterType === $lt ? 'selected' : ''; ?>>
                                        <?php echo $lt . ' - ' . $ln; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="client_id" class="form-select form-select-sm">
                                    <option value="">All Clients</option>
                                    <?php foreach ($clients as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filterClient == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($c['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="month" class="form-select form-select-sm">
                                    <option value="">All Months</option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $filterMonth == $m ? 'selected' : ''; ?>>
                                        <?php echo date('M', mktime(0,0,0,$m,1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="year" class="form-select form-select-sm">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary btn-sm w-100">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-sm table-bordered table-hover mb-0" style="font-size:0.82rem;">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>#</th>
                                        <th>Emp Code</th>
                                        <th>Employee</th>
                                        <th>Client/Unit</th>
                                        <th>Type</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th class="text-center">Days</th>
                                        <th>Status</th>
                                        <th>Reason</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentEntries)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                            No leave entries found. Add one using the form.
                                        </td>
                                    </tr>
                                    <?php else: foreach ($recentEntries as $i => $entry): ?>
                                    <tr>
                                        <td class="text-muted"><?php echo $i + 1; ?></td>
                                        <td><code><?php echo sanitize($entry['employee_code']); ?></code></td>
                                        <td>
                                            <strong><?php echo sanitize($entry['full_name']); ?></strong>
                                        </td>
                                        <td class="small text-muted">
                                            <?php echo sanitize($entry['client_name'] ?? '-'); ?>
                                            <?php if ($entry['unit_name']): ?>
                                            / <?php echo sanitize($entry['unit_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $entry['leave_type']; ?></span>
                                        </td>
                                        <td><?php echo date('d-M-Y', strtotime($entry['from_date'])); ?></td>
                                        <td><?php echo date('d-M-Y', strtotime($entry['to_date'])); ?></td>
                                        <td class="text-center fw-bold"><?php echo $entry['total_days']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $statusColors[$entry['status']] ?? 'secondary'; ?>">
                                                <?php echo ucfirst($entry['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-truncate" style="max-width:120px;" 
                                            title="<?php echo htmlspecialchars($entry['reason'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(substr($entry['reason'] ?? '', 0, 30)) ?: '-'; ?>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" class="d-inline" 
                                                  onsubmit="return confirm('Delete this leave entry?')">
                                                <input type="hidden" name="leave_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" name="delete_leave" class="btn btn-outline-danger btn-sm py-0 px-1" 
                                                        title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php if (!empty($recentEntries)): ?>
                    <div class="card-footer py-2">
                        <small class="text-muted">
                            Showing latest <?php echo count($recentEntries); ?> entries.
                            <i class="bi bi-info-circle me-1"></i>
                            Admin entries are auto-approved by default.
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, form, .card-header { display: none !important; }
    body { font-size: 8pt; }
}
</style>

<script>
// Auto-calculate days from dates
document.getElementById('fromDate')?.addEventListener('change', calcDays);
document.getElementById('toDate')?.addEventListener('change', calcDays);

function calcDays() {
    const from = document.getElementById('fromDate').value;
    const to = document.getElementById('toDate').value;
    if (from && to) {
        const d1 = new Date(from), d2 = new Date(to);
        const diff = Math.ceil((d2 - d1) / (1000 * 60 * 60 * 24)) + 1;
        if (diff > 0) {
            document.getElementById('totalDays').value = diff;
        }
    }
}

// Initialize Select2 for employee search
$(document).ready(function() {
    $('#empSelect').select2({
        placeholder: 'Search employee by name or code...',
        width: '100%',
        allowClear: true,
        matcher: function(params, data) {
            if ($.trim(params.term) === '') return data;
            const term = params.term.toLowerCase();
            const text = data.text.toLowerCase();
            return text.indexOf(term) > -1 ? data : null;
        }
    });
});
</script>
