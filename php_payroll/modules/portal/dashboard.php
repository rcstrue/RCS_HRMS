<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal Dashboard
 * Main dashboard for employees after login
 */

session_start();

// Check if logged in
if (!isset($_SESSION['employee_portal']) || !$_SESSION['employee_portal']['logged_in']) {
    header('Location: index.php?page=portal/login');
    exit;
}

$pageTitle = 'My Dashboard';
$page = 'portal/dashboard';

require_once '../../config/config.php';
require_once '../../includes/database.php';

$db = Database::getInstance();
$employeeId = $_SESSION['employee_portal']['employee_id'];

// Get employee details
$employee = $db->fetch(
    "SELECT e.*, 
            c.name as client_name,
            u.name as unit_name,
            ess.basic_da, ess.hra, ess.gross_salary
     FROM employees e
     LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
        AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     WHERE e.id = :id",
    ['id' => $employeeId]
);

// Get current month attendance summary
$currentMonth = prev_month_num();
$currentYear = date('Y');
$attendance = $db->fetch(
    "SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status IN ('weekly_off', 'holiday') THEN 1 END) as off_days,
        COUNT(CASE WHEN status LIKE '%leave%' THEN 1 END) as leave_days,
        SUM(overtime_hours) as overtime_hours
     FROM attendance 
     WHERE employee_id = :id 
        AND MONTH(attendance_date) = :month 
        AND YEAR(attendance_date) = :year",
    ['id' => $employeeId, 'month' => $currentMonth, 'year' => $currentYear]
);

// Get recent payslips
$payslips = $db->fetchAll(
    "SELECT p.*, pp.period_name, pp.month, pp.year
     FROM payroll p
     JOIN payroll_periods pp ON p.payroll_period_id = pp.id
     WHERE p.employee_id = :id
     ORDER BY pp.year DESC, pp.month DESC
     LIMIT 6",
    ['id' => $employeeId]
);

// Get leave balance
$leaveBalance = $db->fetchAll(
    "SELECT lt.leave_name, lb.balance, lt.is_encashable
     FROM employee_leave_balance lb
     JOIN leave_types lt ON lb.leave_type_id = lt.id
     WHERE lb.employee_id = :id AND lb.year = :year
     ORDER BY lt.id",
    ['id' => $employeeId, 'year' => $currentYear]
);

// Get announcements
$announcements = $db->fetchAll(
    "SELECT * FROM announcements 
     WHERE is_active = 1 
        AND (publish_to = 'all' OR publish_to = 'employees')
        AND (expiry_date IS NULL OR expiry_date >= CURDATE())
     ORDER BY created_at DESC 
     LIMIT 5"
);

include '../../templates/header.php';
?>

<style>
.portal-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.08);
    transition: transform 0.2s;
}
.portal-card:hover {
    transform: translateY(-3px);
}
.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 20px;
}
.stat-card.success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.stat-card.warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
.stat-card.info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.profile-img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid #667eea;
    object-fit: cover;
}
.quick-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 15px;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
}
.quick-action:hover {
    background: #667eea;
    color: white;
    transform: scale(1.05);
}
.quick-action i {
    font-size: 2rem;
    margin-bottom: 10px;
}
.announcement-item {
    border-left: 4px solid #667eea;
    padding-left: 15px;
    margin-bottom: 15px;
}
</style>

<div class="row g-4">
    <!-- Welcome Section -->
    <div class="col-12">
        <div class="portal-card card">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <?php if (!empty($employee['photo_path'])): ?>
                        <img src="<?php echo sanitize($employee['photo_path']); ?>" class="profile-img" alt="Profile">
                        <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($employee['full_name']); ?>&size=120&background=667eea&color=fff" 
                             class="profile-img" alt="Profile">
                        <?php endif; ?>
                    </div>
                    <div class="col">
                        <h3 class="mb-1">Welcome, <?php echo sanitize($employee['full_name']); ?>!</h3>
                        <p class="text-muted mb-2">
                            <i class="bi bi-badge-ad me-1"></i>Emp Code: <strong><?php echo sanitize($employee['employee_code']); ?></strong>
                            &nbsp;|&nbsp;
                            <i class="bi bi-briefcase me-1"></i><?php echo sanitize($employee['designation'] ?? 'N/A'); ?>
                        </p>
                        <div class="row g-3">
                            <div class="col-auto">
                                <span class="badge bg-primary">
                                    <i class="bi bi-building me-1"></i><?php echo sanitize($employee['client_name'] ?? 'N/A'); ?>
                                </span>
                            </div>
                            <div class="col-auto">
                                <span class="badge bg-info">
                                    <i class="bi bi-geo-alt me-1"></i><?php echo sanitize($employee['unit_name'] ?? 'N/A'); ?>
                                </span>
                            </div>
                            <div class="col-auto">
                                <span class="badge bg-success">
                                    <i class="bi bi-calendar me-1"></i>DOJ: <?php echo formatDate($employee['date_of_joining']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <a href="index.php?page=portal/profile" class="btn btn-outline-primary">
                            <i class="bi bi-person me-1"></i>My Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Present Days</div>
                    <div class="h2 mb-0"><?php echo intval($attendance['present_days'] ?? 0); ?></div>
                    <div class="small">This Month</div>
                </div>
                <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Gross Salary</div>
                    <div class="h2 mb-0"><?php echo formatCurrency($employee['gross_salary'] ?? 0); ?></div>
                    <div class="small">Per Month</div>
                </div>
                <i class="bi bi-currency-rupee fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Leave Balance</div>
                    <div class="h2 mb-0">
                        <?php 
                        $elBalance = 0;
                        foreach ($leaveBalance as $lb) {
                            if (strpos($lb['leave_name'], 'Earned') !== false || strpos($lb['leave_name'], 'EL') !== false) {
                                $elBalance = $lb['balance'];
                                break;
                            }
                        }
                        echo $elBalance;
                        ?>
                    </div>
                    <div class="small">Earned Leave</div>
                </div>
                <i class="bi bi-calendar-check fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Overtime</div>
                    <div class="h2 mb-0"><?php echo number_format($attendance['overtime_hours'] ?? 0, 1); ?>h</div>
                    <div class="small">This Month</div>
                </div>
                <i class="bi bi-clock-history fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="col-12">
        <div class="portal-card card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-2">
                        <a href="index.php?page=portal/payslips" class="quick-action">
                            <i class="bi bi-file-earmark-text"></i>
                            <span>My Payslips</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="index.php?page=portal/attendance" class="quick-action">
                            <i class="bi bi-calendar3"></i>
                            <span>Attendance</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="index.php?page=portal/leave" class="quick-action">
                            <i class="bi bi-calendar-plus"></i>
                            <span>Apply Leave</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="index.php?page=portal/documents" class="quick-action">
                            <i class="bi bi-folder"></i>
                            <span>Documents</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="index.php?page=portal/statutory" class="quick-action">
                            <i class="bi bi-shield-check"></i>
                            <span>PF/ESI</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="index.php?page=portal/help" class="quick-action">
                            <i class="bi bi-question-circle"></i>
                            <span>Help</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <!-- Recent Payslips -->
        <div class="portal-card card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-receipt me-2"></i>Recent Payslips</h5>
                <a href="index.php?page=portal/payslips" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payslips)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-1"></i>
                    <p>No payslips available yet</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Month/Year</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net Pay</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payslips as $p): ?>
                            <tr>
                                <td><?php echo date('F Y', mktime(0,0,0,$p['month'],1,$p['year'])); ?></td>
                                <td class="text-end"><?php echo formatCurrency($p['basic_da'] + $p['hra'] + ($p['leave_encashment'] ?? 0) + ($p['bonus_encashment'] ?? 0) + ($p['washing_allowance'] ?? 0)); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($p['pf_employee'] + $p['esi_employee'] + $p['pt_employee']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($p['net_salary']); ?></strong></td>
                                <td>
                                    <a href="index.php?page=portal/payslip_view&period=<?php echo $p['payroll_period_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Announcements -->
        <div class="portal-card card h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-megaphone me-2"></i>Announcements</h5>
            </div>
            <div class="card-body">
                <?php if (empty($announcements)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-megaphone fs-1"></i>
                    <p>No announcements</p>
                </div>
                <?php else: ?>
                <?php foreach ($announcements as $a): ?>
                <div class="announcement-item">
                    <h6 class="mb-1"><?php echo sanitize($a['title']); ?></h6>
                    <p class="small text-muted mb-1"><?php echo substr(sanitize($a['content']), 0, 100); ?>...</p>
                    <small class="text-muted"><?php echo formatDate($a['created_at']); ?></small>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Statutory Information -->
    <div class="col-md-6">
        <div class="portal-card card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-shield-check me-2"></i>Statutory Information</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="text-muted small">UAN Number</label>
                        <div class="fw-bold"><?php echo sanitize($employee['uan_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">ESI Number</label>
                        <div class="fw-bold"><?php echo sanitize($employee['esi_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">PF Applicable</label>
                        <div class="fw-bold">
                            <?php if ($employee['is_pf_applicable'] ?? $employee['is_pf_applicable'] ?? 0): ?>
                            <span class="badge bg-success">Yes</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">ESI Applicable</label>
                        <div class="fw-bold">
                            <?php if ($employee['is_esi_applicable'] ?? $employee['is_esi_applicable'] ?? 0): ?>
                            <span class="badge bg-success">Yes</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Leave Balance -->
    <div class="col-md-6">
        <div class="portal-card card">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Leave Balance (<?php echo $currentYear; ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($leaveBalance)): ?>
                <div class="text-center py-3 text-muted">
                    <p class="mb-0">No leave balance data available</p>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($leaveBalance as $lb): ?>
                    <div class="col-4">
                        <div class="text-center p-3 bg-light rounded">
                            <div class="text-muted small"><?php echo sanitize($lb['leave_name']); ?></div>
                            <div class="h4 mb-0 text-primary"><?php echo number_format($lb['balance'], 1); ?></div>
                            <div class="small text-muted">days</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../templates/footer.php'; ?>
