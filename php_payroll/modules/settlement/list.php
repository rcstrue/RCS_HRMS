<?php
/**
 * RCS HRMS Pro - Full & Final Settlement
 * Calculate all dues when employee leaves
 * Includes: Salary, Gratuity, Leave Encashment, PF withdrawal, Deductions
 */

$pageTitle = 'Full & Final Settlement';

$tablesExist = true;
$settlements = [];
$resigningEmployees = [];

// Check if required tables exist, auto-create if missing
try {
    $tablesExist = $db->tableExists('employee_settlements');
    if (!$tablesExist) {
        $db->exec("CREATE TABLE IF NOT EXISTS employee_settlements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT(10) UNSIGNED NOT NULL,
            last_working_day DATE NOT NULL,
            leaving_reason VARCHAR(50) NOT NULL DEFAULT 'Resignation',
            service_years DECIMAL(6,2) DEFAULT 0,
            salary_days INT DEFAULT 0,
            salary_amount DECIMAL(12,2) DEFAULT 0,
            leave_encashment_days DECIMAL(6,2) DEFAULT 0,
            leave_encashment_amount DECIMAL(12,2) DEFAULT 0,
            gratuity_years INT DEFAULT 0,
            gratuity_amount DECIMAL(12,2) DEFAULT 0,
            bonus_amount DECIMAL(12,2) DEFAULT 0,
            notice_shortfall INT DEFAULT 0,
            notice_recovery DECIMAL(12,2) DEFAULT 0,
            advance_recovery DECIMAL(12,2) DEFAULT 0,
            total_earnings DECIMAL(12,2) DEFAULT 0,
            total_deductions DECIMAL(12,2) DEFAULT 0,
            net_payable DECIMAL(12,2) DEFAULT 0,
            status ENUM('pending','approved','paid','on_hold','rejected') DEFAULT 'pending',
            payment_date DATE NULL,
            payment_mode VARCHAR(50) NULL,
            payment_reference VARCHAR(100) NULL,
            created_by INT NULL,
            approved_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            INDEX idx_employee (employee_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $tablesExist = true;
    }
} catch (Exception $e) {
    $tablesExist = false;
}

// Handle new settlement calculation
if ($tablesExist && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'calculate') {
        $employeeId = sanitize($_POST['employee_id']);
        $lastWorkingDay = sanitize($_POST['last_working_day']);
        $leavingReason = sanitize($_POST['leaving_reason']);
        $noticePeriodServed = (int)($_POST['notice_period_served'] ?? 0);
        
        // Get employee details
        $emp = $db->fetch(
            "SELECT e.*, ess.basic_da, ess.gross_salary, ess.pf_applicable, ess.esi_applicable, ess.bonus_applicable,
                    c.name as client_name
             FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
             LEFT JOIN clients c ON e.client_id = c.id
             WHERE e.id = :id OR e.employee_code = :code",
            ['id' => $employeeId, 'code' => $employeeId]
        );
        
        if (!$emp) {
            setFlash('error', 'Employee not found');
            redirect('index.php?page=settlement/list');
        }
        
        // Calculate F&F components
        $lwd = new DateTime($lastWorkingDay);
        $doj = new DateTime($emp['date_of_joining']);
        $serviceYears = $doj->diff($lwd)->y + ($doj->diff($lwd)->m / 12);
        $serviceMonths = ($doj->diff($lwd)->y * 12) + $doj->diff($lwd)->m;
        
        $basic = floatval($emp['basic_da'] ?? $emp['basic_salary'] ?? 0);
        $gross = floatval($emp['gross_salary'] ?? $basic * 1.4);
        
        // 1. Salary for days worked in last month
        $lastMonth = $lwd->format('n');
        $lastYear = $lwd->format('Y');
        $daysInMonth = $lwd->format('t');
        $daysWorked = $lwd->format('j');
        
        $salaryDays = $daysWorked;
        $salaryPayable = ($gross / $daysInMonth) * $daysWorked;
        
        // 2. Leave Encashment
        $earnedLeaveBalance = 0;
        try {
            $leaveBalance = $db->fetch(
                "SELECT balance FROM employee_leave_balance 
                 WHERE employee_id = :id AND year = :year AND leave_type_id = 
                    (SELECT id FROM leave_types WHERE leave_code = 'EL' LIMIT 1)",
                ['id' => $emp['id'], 'year' => $lastYear]
            );
            $earnedLeaveBalance = floatval($leaveBalance['balance'] ?? 0);
        } catch (Exception $e) {
            // leave_balance table may not exist
            $earnedLeaveBalance = 0;
        }
        $leaveEncashment = ($basic / 26) * $earnedLeaveBalance;
        
        // 3. Gratuity (if eligible - 5+ years)
        $gratuity = 0;
        if ($serviceYears >= 5 && ($emp['gratuity_applicable'] ?? false)) {
            $gratuity = ($basic * 15 * floor($serviceYears)) / 26;
            $gratuity = min($gratuity, 2000000);
        }
        
        // 4. Bonus (if applicable)
        $bonusPayable = 0;
        if (($emp['bonus_applicable'] ?? false) && $basic <= 21000) {
            $bonusPayable = min($basic, 7000) * (8.33 / 100) * $serviceMonths;
        }
        
        // 5. Notice Period Recovery / Pay
        $noticePeriod = intval($emp['notice_period'] ?? 30);
        $noticeShortfall = max(0, $noticePeriod - $noticePeriodServed);
        $noticeRecovery = ($gross / 30) * $noticeShortfall;
        
        // 6. Advance/Pending Loan Recovery
        $pendingAdvance = 0;
        try {
            $pendingAdvance = $db->fetchColumn(
                "SELECT SUM(amount - recovered) FROM employee_advance 
                 WHERE employee_id = :id AND status = 'approved' AND recovered < amount",
                ['id' => $emp['id']]
            ) ?: 0;
        } catch (Exception $e) {
            // employee_advance table may not exist
        }
        
        // 7. Other Dues
        $otherDues = 0;
        $otherRecoveries = 0;
        
        // Calculate totals
        $totalEarnings = $salaryPayable + $leaveEncashment + $gratuity + $bonusPayable + $otherDues;
        $totalDeductions = $noticeRecovery + $pendingAdvance + $otherRecoveries;
        $netPayable = $totalEarnings - $totalDeductions;
        
        // PF Withdrawal Info
        $pfWithdrawal = [
            'uan' => $emp['uan_number'],
            'pf_applicable' => $emp['pf_applicable'] ?? false,
            'can_withdraw' => true,
            'note' => $serviceYears >= 5 ? 'Can withdraw full PF + EPS' : 'Can withdraw PF only (EPS requires 10 years service)'
        ];
        
        // Save settlement to database
        $settlementData = [
            'employee_id' => $emp['id'],
            'last_working_day' => $lastWorkingDay,
            'leaving_reason' => $leavingReason,
            'service_years' => round($serviceYears, 2),
            'salary_days' => $daysWorked,
            'salary_amount' => $salaryPayable,
            'leave_encashment_days' => $earnedLeaveBalance,
            'leave_encashment_amount' => $leaveEncashment,
            'gratuity_years' => floor($serviceYears),
            'gratuity_amount' => $gratuity,
            'bonus_amount' => $bonusPayable,
            'notice_shortfall' => $noticeShortfall,
            'notice_recovery' => $noticeRecovery,
            'advance_recovery' => $pendingAdvance,
            'total_earnings' => $totalEarnings,
            'total_deductions' => $totalDeductions,
            'net_payable' => $netPayable,
            'status' => 'pending',
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            $settlementId = $db->insert('employee_settlements', $settlementData);
            
            // Update employee status
            $db->update('employees', [
                'status' => 'inactive',
                'date_of_leaving' => $lastWorkingDay,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $emp['id']]);
            
            setFlash('success', 'F&F Settlement calculated successfully. Settlement ID: ' . $settlementId);
            redirect('index.php?page=settlement/view&id=' . $settlementId);
        } catch (Exception $e) {
            setFlash('error', 'Error saving settlement: ' . $e->getMessage());
        }
    }
    
    if ($_POST['action'] === 'approve' && isset($_POST['settlement_id'])) {
        $settlementId = (int)$_POST['settlement_id'];
        $paymentDate = sanitize($_POST['payment_date']);
        $paymentMode = sanitize($_POST['payment_mode']);
        $paymentRef = sanitize($_POST['payment_reference'] ?? '');
        
        $db->update('employee_settlements', [
            'status' => 'paid',
            'payment_date' => $paymentDate,
            'payment_mode' => $paymentMode,
            'payment_reference' => $paymentRef,
            'approved_by' => $_SESSION['user_id'],
            'approved_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $settlementId]);
        
        setFlash('success', 'Settlement marked as paid');
        redirect('index.php?page=settlement/list');
    }
}

// Get employees who are leaving or have left
// employees.status enum: 'approved','pending_hr_verification','inactive','terminated','removed'
if ($tablesExist) {
    try {
        $resigningEmployees = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.date_of_joining, e.date_of_leaving,
                    c.name as client_name,
                    ess.gross_salary,
                    (SELECT COUNT(*) FROM employee_settlements WHERE employee_id = e.id) as has_settlement
             FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
             LEFT JOIN clients c ON e.client_id = c.id
             WHERE e.status IN ('inactive', 'terminated', 'removed')
                OR (e.date_of_leaving IS NOT NULL AND e.date_of_leaving <= CURDATE())
             ORDER BY e.date_of_leaving DESC"
        );
    } catch (Exception $e) {
        $resigningEmployees = [];
    }

    // Get existing settlements
    try {
        $settlements = $db->fetchAll(
            "SELECT s.*, e.employee_code, e.full_name, 
                    c.name as client_name
             FROM employee_settlements s
             JOIN employees e ON s.employee_id = e.id
             LEFT JOIN clients c ON e.client_id = c.id
             ORDER BY s.created_at DESC"
        );
    } catch (Exception $e) {
        $settlements = [];
    }
}

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-cash-coin me-2"></i>Full & Final Settlement
            </h1>
            <p class="text-muted">Calculate and process employee exit settlements</p>
        </div>
        <?php if ($tablesExist): ?>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newSettlementModal">
                <i class="bi bi-plus-lg me-1"></i>New Settlement
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Settlement Module Not Configured</strong><br>
    The required database table (<code>employee_settlements</code>) has not been created yet.
    Please run the settlement migration SQL to set up this module.
</div>
<?php else: ?>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-black-50 small">Pending Settlements</div>
                        <div class="h3 mb-0">
                            <?php 
                            echo count(array_filter($settlements, fn($s) => $s['status'] == 'pending'));
                            ?>
                        </div>
                    </div>
                    <i class="bi bi-hourglass-split fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Paid Settlements</div>
                        <div class="h3 mb-0">
                            <?php 
                            echo count(array_filter($settlements, fn($s) => $s['status'] == 'paid'));
                            ?>
                        </div>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Ex-Employees Pending</div>
                        <div class="h3 mb-0"><?php echo count($resigningEmployees); ?></div>
                    </div>
                    <i class="bi bi-person-x fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total Settlements</div>
                        <div class="h3 mb-0"><?php echo number_format(count($settlements)); ?></div>
                    </div>
                    <i class="bi bi-receipt fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
</div>

<!-- Settlements Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-table me-2"></i>Settlement Records</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="settlementTable">
                        <thead class="table-light">
                            <tr>
                                <th>Ref #</th>
                                <th>Employee</th>
                                <th>Client</th>
                                <th>Last Working Day</th>
                                <th>Service (Yrs)</th>
                                <th class="text-end">Total Earnings</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net Payable</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($settlements)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    No settlements found. Click "New Settlement" to calculate F&F.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($settlements as $s): ?>
                            <tr>
                                <td><code>FNF-<?php echo str_pad($s['id'], 5, '0', STR_PAD_LEFT); ?></code></td>
                                <td>
                                    <div><?php echo sanitize($s['full_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($s['employee_code']); ?></small>
                                </td>
                                <td><?php echo sanitize($s['client_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($s['last_working_day']); ?></td>
                                <td><?php echo number_format($s['service_years'], 1); ?></td>
                                <td class="text-end"><?php echo formatCurrency($s['total_earnings']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($s['total_deductions']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($s['net_payable']); ?></strong></td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'pending' => 'warning',
                                        'approved' => 'info',
                                        'paid' => 'success',
                                        'on_hold' => 'danger'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$s['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($s['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="index.php?page=settlement/view&id=<?php echo $s['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($s['status'] == 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                            onclick="approveSettlement(<?php echo htmlspecialchars(json_encode($s)); ?>)" title="Mark as Paid">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <?php endif; ?>
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

<!-- New Settlement Modal -->
<div class="modal fade" id="newSettlementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="calculate">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>New F&F Settlement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Employee</label>
                            <select class="form-select select2" name="employee_id" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($resigningEmployees as $e): ?>
                                <option value="<?php echo $e['id']; ?>">
                                    <?php echo sanitize($e['employee_code'] . ' - ' . $e['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Or search any active employee</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Last Working Day</label>
                            <input type="date" class="form-control" name="last_working_day" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Reason for Leaving</label>
                            <select class="form-select" name="leaving_reason" required>
                                <option value="Resignation">Resignation</option>
                                <option value="Termination">Termination</option>
                                <option value="Retirement">Retirement</option>
                                <option value="Contract End">Contract End</option>
                                <option value="Absconding">Absconding</option>
                                <option value="Death">Death</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Notice Period Served (Days)</label>
                            <input type="number" class="form-control" name="notice_period_served" value="30" min="0">
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>F&F will calculate:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Salary for days worked in last month</li>
                            <li>Leave encashment (earned leave balance)</li>
                            <li>Gratuity (if eligible - 5+ years service)</li>
                            <li>Bonus (if applicable)</li>
                            <li>Notice period recovery</li>
                            <li>Advance/loan recovery</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-1"></i>Calculate F&F
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Settlement Modal -->
<div class="modal fade" id="approveSettlementModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="settlement_id" id="approve_settlement_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Mark as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Net Payable Amount</label>
                        <input type="text" class="form-control" id="approve_net_payable" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Payment Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Payment Mode</label>
                        <select class="form-select" name="payment_mode" required>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Cash">Cash</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Reference</label>
                        <input type="text" class="form-control" name="payment_reference" placeholder="UTR/Cheque No.">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveSettlement(data) {
    $('#approve_settlement_id').val(data.id);
    $('#approve_net_payable').val(formatCurrency(data.net_payable));
    new bootstrap.Modal('#approveSettlementModal').show();
}

function formatCurrency(amount) {
    return '₹' + parseFloat(amount).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}

$(document).ready(function() {
    $('#settlementTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[3, 'desc']]
    });
    
    // Initialize select2 for employee search
    $('.select2').select2({
        theme: 'bootstrap-5',
        placeholder: 'Search employee...',
        ajax: {
            url: 'index.php?page=api/employees',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { search: params.term };
            },
            processResults: function(data) {
                return { results: data };
            }
        }
    });
});
</script>

<?php endif; // end tablesExist check ?>
