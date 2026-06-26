<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal Payslips
 */

session_start();

if (!isset($_SESSION['employee_portal']) || !$_SESSION['employee_portal']['logged_in']) {
    header('Location: index.php?page=portal/login');
    exit;
}

$pageTitle = 'My Payslips';
$page = 'portal/payslips';

require_once '../../config/config.php';
require_once '../../includes/database.php';

$db = Database::getInstance();
$employeeId = $_SESSION['employee_portal']['employee_id'];

// Get all payslips
$payslips = $db->fetchAll(
    "SELECT p.*, pp.period_name, pp.month, pp.year, pp.status as period_status,
            c.name as client_name
     FROM payroll p
     JOIN payroll_periods pp ON p.payroll_period_id = pp.id
     LEFT JOIN clients c ON p.client_id = c.id
     WHERE p.employee_id = :id
     ORDER BY pp.year DESC, pp.month DESC",
    ['id' => $employeeId]
);

include '../../templates/header.php';
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-receipt me-2"></i>My Payslips
                </h5>
                <a href="index.php?page=portal/dashboard" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payslips)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">No Payslips Available</h5>
                    <p class="text-muted">Your payslips will appear here once payroll is processed.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Month/Year</th>
                                <th class="text-center">Days Worked</th>
                                <th class="text-end">Gross Salary</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net Pay</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payslips as $p): 
                                $gross = $p['basic'] + $p['da'] + $p['hra'] + ($p['leave_encashment'] ?? 0) + ($p['bonus_encashment'] ?? 0) + ($p['washing_allowance'] ?? 0) + $p['overtime_amount'] + $p['bonus'] + $p['incentive'] + $p['arrears'];
                                $deductions = $p['pf_employee'] + $p['esi_employee'] + $p['pt_employee'] + $p['lwf_employee'] + $p['tds'] + $p['advance_deduction'] + $p['other_deductions'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('F Y', mktime(0,0,0,$p['month'],1,$p['year'])); ?></strong>
                                    <br><small class="text-muted"><?php echo sanitize($p['period_name'] ?? ''); ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark">
                                        <?php echo number_format($p['paid_days'], 1); ?> / <?php echo number_format($p['total_working_days'], 0); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($gross); ?></td>
                                <td class="text-end text-danger">-<?php echo formatCurrency($deductions); ?></td>
                                <td class="text-end"><strong class="text-primary"><?php echo formatCurrency($p['net_salary']); ?></strong></td>
                                <td>
                                    <?php 
                                    $statusColors = [
                                        'pending' => 'warning',
                                        'paid' => 'success',
                                        'processing' => 'info',
                                        'on_hold' => 'danger'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$p['payment_status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $p['payment_status'])); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="index.php?page=portal/payslip_view&period=<?php echo $p['payroll_period_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="View Payslip">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="index.php?page=portal/payslip_print&period=<?php echo $p['payroll_period_id']; ?>" 
                                       class="btn btn-sm btn-outline-success" title="Print Payslip" target="_blank">
                                        <i class="bi bi-printer"></i>
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
    
    <!-- Salary Summary -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Salary Summary (Current FY)</h5>
            </div>
            <div class="card-body">
                <?php
                $currentFY = date('n') >= 4 ? date('Y') : date('Y') - 1;
                $fyPayslips = array_filter($payslips, function($p) use ($currentFY) {
                    $pFY = $p['month'] >= 4 ? $p['year'] : $p['year'] - 1;
                    return $pFY == $currentFY;
                });
                
                $totalGross = 0;
                $totalDeductions = 0;
                $totalNet = 0;
                $totalPF = 0;
                $totalESI = 0;
                
                foreach ($fyPayslips as $p) {
                    $totalGross += $p['basic'] + $p['da'] + $p['hra'] + ($p['leave_encashment'] ?? 0) + ($p['bonus_encashment'] ?? 0) + ($p['washing_allowance'] ?? 0);
                    $totalDeductions += $p['pf_employee'] + $p['esi_employee'] + $p['pt_employee'];
                    $totalNet += $p['net_salary'];
                    $totalPF += $p['pf_employee'];
                    $totalESI += $p['esi_employee'];
                }
                ?>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small">Total Gross</div>
                            <div class="h5 mb-0 text-success"><?php echo formatCurrency($totalGross); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small">Total Deductions</div>
                            <div class="h5 mb-0 text-danger"><?php echo formatCurrency($totalDeductions); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small">Total Net Pay</div>
                            <div class="h5 mb-0 text-primary"><?php echo formatCurrency($totalNet); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small">PF Contribution</div>
                            <div class="h5 mb-0"><?php echo formatCurrency($totalPF); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Info -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Payslip Information</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h6><i class="bi bi-lightbulb me-2"></i>Understanding Your Payslip</h6>
                    <hr>
                    <ul class="mb-0 small">
                        <li><strong>Gross Salary:</strong> Basic + DA + HRA + Allowances</li>
                        <li><strong>PF:</strong> 12% of Basic+DA (if applicable)</li>
                        <li><strong>ESI:</strong> 0.75% of Gross (if salary ≤ ₹21,000)</li>
                        <li><strong>PT:</strong> Professional Tax as per state rules</li>
                        <li><strong>Net Pay:</strong> Gross - All Deductions</li>
                    </ul>
                </div>
                <div class="mt-3">
                    <p class="text-muted small mb-2">
                        <i class="bi bi-question-circle me-1"></i>For any discrepancies in your payslip, please contact HR.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../templates/footer.php'; ?>
