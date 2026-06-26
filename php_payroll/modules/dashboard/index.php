<?php
/**
 * RCS HRMS Pro - Dashboard Page
 * Updated for new database schema
 */

$pageTitle = 'Dashboard';

// Get statistics
$employeeCounts = $employee->getCounts();

// Get payroll summary for current month
$currentMonth = prev_month_num();
$currentYear = date('Y');

$stmt = $db->prepare("SELECT id FROM payroll_periods WHERE month = ? AND year = ?");
$stmt->execute([$currentMonth, $currentYear]);
$currentPeriod = $stmt->fetch(PDO::FETCH_ASSOC);

$payrollSummary = null;
if ($currentPeriod) {
    $payrollSummary = $payroll->getPayrollTotals($currentPeriod['id']);
}

// Get compliance alerts
$complianceAlerts = $compliance->checkDeadlineAlerts();

// Get recent employees - use JOINs since client_name/unit_name columns removed from employees table
$stmt = $db->query("SELECT e.employee_code, e.full_name, e.designation, e.status,
                           u.name as unit_name, c.name as client_name
                    FROM employees e 
                    LEFT JOIN units u ON e.unit_id = u.id
                    LEFT JOIN clients c ON e.client_id = c.id
                    WHERE e.status = 'approved'
                    ORDER BY e.created_at DESC LIMIT 5");
$recentEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unit-wise employee count - use JOIN with units table
$stmt = $db->query("SELECT u.name as unit_name, COUNT(e.id) as count 
                    FROM employees e 
                    INNER JOIN units u ON e.unit_id = u.id
                    WHERE e.status = 'approved'
                    GROUP BY u.id, u.name
                    ORDER BY count DESC
                    LIMIT 10");
$unitWiseCount = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attendance summary for current month
$attendanceSummary = $attendance->getSummary($currentMonth, $currentYear);

// Get client-wise employee count - use JOIN with clients table
$stmt = $db->query("SELECT c.name as client_name, COUNT(e.id) as count 
                    FROM employees e 
                    INNER JOIN clients c ON e.client_id = c.id
                    WHERE e.status = 'approved'
                    GROUP BY c.id, c.name
                    ORDER BY count DESC
                    LIMIT 5");
$clientWiseCount = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get compliance summary
$complianceSummary = $compliance->getSummary();
?>

<div class="row">
    <!-- Stats Cards -->
    <div class="col-12">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="bi bi-people"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Employees</div>
                    <div class="stat-value"><?php echo number_format($employeeCounts['total']); ?></div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Active: <?php echo number_format($employeeCounts['active']); ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="bi bi-building"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Active Units</div>
                    <?php
                    $stmt = $db->query("SELECT COUNT(*) as count FROM units WHERE is_active = 1");
                    $unitCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    ?>
                    <div class="stat-value"><?php echo number_format($unitCount); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Pending Approval</div>
                    <div class="stat-value"><?php echo number_format($employeeCounts['pending'] ?? 0); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Pending Compliance</div>
                    <div class="stat-value"><?php echo $complianceSummary['pending_returns'] ?? 0; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions -->
    <div class="col-lg-12 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 pt-4 pb-0 px-4">
                <h5 class="card-title mb-0"><i class="bi bi-lightning-fill text-warning me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body px-4 pb-4">
                <div class="row g-3">
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                        <a href="index.php?page=employee/add" class="text-decoration-none">
                            <div class="quick-action-card" style="--qa-color: #2563eb; --qa-bg: #eff6ff; --qa-hover: #dbeafe;">
                                <div class="qa-icon"><i class="bi bi-person-plus-fill"></i></div>
                                <div class="qa-label">Add Employee</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                        <a href="index.php?page=attendance/upload" class="text-decoration-none">
                            <div class="quick-action-card" style="--qa-color: #059669; --qa-bg: #ecfdf5; --qa-hover: #d1fae5;">
                                <div class="qa-icon"><i class="bi bi-cloud-arrow-up-fill"></i></div>
                                <div class="qa-label">Upload Attendance</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                        <a href="index.php?page=payroll/process" class="text-decoration-none">
                            <div class="quick-action-card" style="--qa-color: #d97706; --qa-bg: #fffbeb; --qa-hover: #fef3c7;">
                                <div class="qa-icon"><i class="bi bi-calculator-fill"></i></div>
                                <div class="qa-label">Process Payroll</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                        <a href="index.php?page=compliance/dashboard" class="text-decoration-none">
                            <div class="quick-action-card" style="--qa-color: #0891b2; --qa-bg: #ecfeff; --qa-hover: #cffafe;">
                                <div class="qa-icon"><i class="bi bi-shield-fill-check"></i></div>
                                <div class="qa-label">Compliance</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                        <a href="index.php?page=forms/index" class="text-decoration-none">
                            <div class="quick-action-card" style="--qa-color: #7c3aed; --qa-bg: #f5f3ff; --qa-hover: #ede9fe;">
                                <div class="qa-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                                <div class="qa-label">Forms</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-6">
                        <a href="index.php?page=report/index" class="text-decoration-none">
                            <div class="quick-action-card" style="--qa-color: #dc2626; --qa-bg: #fef2f2; --qa-hover: #fee2e2;">
                                <div class="qa-icon"><i class="bi bi-bar-chart-fill"></i></div>
                                <div class="qa-label">Reports</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Unit Distribution -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-pie-chart me-2"></i>Unit Distribution</h5>
            </div>
            <div class="card-body">
                <?php if (empty($unitWiseCount)): ?>
                <div class="text-center py-4 text-muted">No units configured</div>
                <?php else: ?>
                <canvas id="unitChart" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Compliance Alerts -->
<?php if (!empty($complianceAlerts)): ?>
<div class="row">
    <div class="col-12 mb-4">
        <?php foreach ($complianceAlerts as $alert): ?>
        <div class="alert alert-<?php echo $alert['type'] === 'danger' ? 'danger' : 'warning'; ?> alert-dismissible fade show" role="alert">
            <strong><?php echo sanitize($alert['title']); ?></strong> - <?php echo sanitize($alert['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- Payroll Summary -->
    <?php if ($payrollSummary): ?>
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-cash-stack me-2"></i>Current Month Payroll</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Employees</span>
                    <strong><?php echo number_format($payrollSummary['total_employees'] ?? 0); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Gross Earnings</span>
                    <strong><?php echo formatCurrency($payrollSummary['total_gross'] ?? 0); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Deductions</span>
                    <strong><?php echo formatCurrency($payrollSummary['total_deductions'] ?? 0); ?></strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between mb-2">
                    <span>Net Pay</span>
                    <strong class="text-success fs-5"><?php echo formatCurrency($payrollSummary['total_net'] ?? 0); ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Employer Contribution</span>
                    <strong><?php echo formatCurrency($payrollSummary['total_employer_contribution'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Attendance Summary -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance This Month</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Present</span>
                    <strong class="text-success"><?php echo number_format($attendanceSummary['total_present']); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Absent</span>
                    <strong class="text-danger"><?php echo number_format($attendanceSummary['total_absent']); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Weekly Offs</span>
                    <strong><?php echo number_format($attendanceSummary['total_weekly_offs']); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Holidays</span>
                    <strong><?php echo number_format($attendanceSummary['total_holidays']); ?></strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span>Overtime Hours</span>
                    <strong><?php echo number_format($attendanceSummary['total_overtime_hours'], 1); ?> hrs</strong>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Compliance Summary -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-shield-check me-2"></i>Compliance Overview</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>PF Members</span>
                    <strong><?php echo number_format($complianceSummary['pf_members']); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>ESI Members</span>
                    <strong><?php echo number_format($complianceSummary['esi_members']); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Pending Returns</span>
                    <strong class="<?php echo $complianceSummary['pending_returns'] > 0 ? 'text-warning' : 'text-success'; ?>">
                        <?php echo number_format($complianceSummary['pending_returns']); ?>
                    </strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Overdue Filings</span>
                    <strong class="<?php echo $complianceSummary['overdue_filings'] > 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo number_format($complianceSummary['overdue_filings']); ?>
                    </strong>
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <a href="index.php?page=compliance/dashboard" class="btn btn-sm btn-outline-primary w-100">
                    View Compliance Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Employees -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recent Employees</h5>
                <a href="index.php?page=employee/list" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>Unit</th>
                                <th>Client</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentEmployees)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No employees found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recentEmployees as $emp): ?>
                            <tr>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name'] ?? '-'); ?></td>
                                <td><?php echo sanitize($emp['designation'] ?? '-'); ?></td>
                                <td><?php echo sanitize($emp['unit_name'] ?? '-'); ?></td>
                                <td><?php echo sanitize($emp['client_name'] ?? '-'); ?></td>
                                <td><span class="badge bg-success-soft"><?php echo sanitize($emp['status']); ?></span></td>
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

<!-- Client-wise Distribution -->
<?php if (!empty($clientWiseCount)): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-bar-chart me-2"></i>Client-wise Distribution</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($clientWiseCount as $client): ?>
                    <div class="col-md-4 mb-3">
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><?php echo sanitize($client['client_name']); ?></span>
                                <span class="badge bg-primary rounded-pill"><?php echo number_format($client['count']); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Pass PHP data to JavaScript BEFORE the inlineJS heredoc
$unitWiseCountJson = json_encode($unitWiseCount);

$inlineJS = <<<'JS'
// Unit Distribution Chart
const unitWiseCount = UNIT_WISE_COUNT_DATA; // Replaced with actual data below

if (document.getElementById('unitChart') && typeof Chart !== 'undefined') {
    const unitCtx = document.getElementById('unitChart').getContext('2d');
    new Chart(unitCtx, {
        type: 'doughnut',
        data: {
            labels: unitWiseCount.map(u => u.unit_name),
            datasets: [{
                data: unitWiseCount.map(u => parseInt(u.count)),
                backgroundColor: [
                    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
                    '#858796', '#5a5c69', '#2e59d9', '#17a673', '#2c9faf'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    });
}
JS;

// Now replace the placeholder with actual data
$inlineJS = str_replace('UNIT_WISE_COUNT_DATA', $unitWiseCountJson, $inlineJS);
?>
