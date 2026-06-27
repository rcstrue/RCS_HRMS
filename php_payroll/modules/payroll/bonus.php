<?php
/**
 * RCS HRMS Pro - Bonus Calculation Module
 * Calculate and disburse annual bonus as per Payment of Bonus Act, 1965
 * 
 * Features:
 * - Eligibility check (salary ≤ ₹21,000, worked ≥ 30 days)
 * - Calculate bonus @ 8.33% to 20% of wages
 * - Set on/adjust in payroll
 * - Bonus register
 * 
 * Routed through index.php - no need to re-include config/database
 */

$pageTitle = 'Bonus Calculation';

// Get settings
$bonusMinRate = floatval($db->fetchColumn("SELECT setting_value FROM settings WHERE setting_key = 'bonus_minimum'") ?: 8.33);
$bonusMaxRate = floatval($db->fetchColumn("SELECT setting_value FROM settings WHERE setting_key = 'bonus_maximum'") ?: 20);
$bonusWageCeiling = floatval($db->fetchColumn("SELECT setting_value FROM settings WHERE setting_key = 'bonus_wage_ceiling'") ?: 7000);

// Handle bonus calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'calculate_all') {
        $financialYear = sanitize($_POST['financial_year']); // Format: 2023-2024
        
        // Get all eligible employees
        $eligibleEmployees = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.date_of_joining, e.date_of_leaving,
                    e.is_bonus_applicable, c.name as client_name,
                    ess.basic_da, ess.gross_salary
             FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
             LEFT JOIN clients c ON e.client_id = c.id
             WHERE e.status IN ('active', 'resigned', 'terminated')
                AND e.is_bonus_applicable = 1
                AND (ess.basic_da <= 21000 OR ess.gross_salary <= 21000)
             ORDER BY e.full_name"
        );
        
        // Parse financial year
        $fyParts = explode('-', $financialYear);
        $fyStartYear = intval($fyParts[0]);
        $fyEndYear = $fyStartYear + 1;
        
        $fyStart = "$fyStartYear-04-01";
        $fyEnd = "$fyEndYear-03-31";
        
        $bonusRecords = [];
        $totalBonus = 0;
        
        foreach ($eligibleEmployees as $emp) {
            // Calculate days worked in FY
            $doj = new DateTime($emp['date_of_joining']);
            $dol = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : new DateTime($fyEnd);
            $fyStartDate = new DateTime($fyStart);
            $fyEndDate = new DateTime($fyEnd);
            
            // Adjust start date if joined after FY start
            if ($doj > $fyStartDate) {
                $fyStartDate = $doj;
            }
            
            // Adjust end date if left before FY end
            if ($dol < $fyEndDate) {
                $fyEndDate = $dol;
            }
            
            // Calculate months worked
            $interval = $fyStartDate->diff($fyEndDate);
            $monthsWorked = ($interval->y * 12) + $interval->m + ($interval->d > 0 ? 1 : 0);
            
            // Minimum 30 days worked required
            if ($monthsWorked < 1 && $interval->d < 30) {
                continue;
            }
            
            // Calculate bonus
            $basicDa = floatval($emp['basic_da'] ?? 0);
            $wageForBonus = min($basicDa, $bonusWageCeiling); // Ceiling of ₹7,000 or actual
            
            // Calculate payable bonus (pro-rata for partial year)
            $annualWage = $wageForBonus * 12;
            $proRataFactor = min($monthsWorked, 12) / 12;
            
            // Default bonus rate (can be adjusted based on profits)
            $bonusRate = floatval($_POST['bonus_rate'] ?? $bonusMinRate);
            
            $bonusAmount = ($annualWage * $bonusRate / 100) * $proRataFactor;
            
            $bonusRecords[] = [
                'employee_id' => $emp['id'],
                'employee_code' => $emp['employee_code'],
                'full_name' => $emp['full_name'],
                'client_name' => $emp['client_name'],
                'basic_da' => $basicDa,
                'months_worked' => min($monthsWorked, 12),
                'bonus_rate' => $bonusRate,
                'bonus_amount' => $bonusAmount
            ];
            
            $totalBonus += $bonusAmount;
        }
        
        // Store in session for preview
        $_SESSION['bonus_preview'] = [
            'financial_year' => $financialYear,
            'records' => $bonusRecords,
            'total' => $totalBonus,
            'rate' => $bonusRate
        ];
        
        setFlash('success', "Bonus calculated for " . count($bonusRecords) . " employees. Total: ₹" . number_format($totalBonus, 2));
        redirect(BONUS_PAGE_URL);
    }
    
    if ($_POST['action'] === 'save_bonus') {
        $preview = $_SESSION['bonus_preview'] ?? null;
        
        if (!$preview) {
            setFlash('error', 'No bonus data to save');
            redirect(BONUS_PAGE_URL);
        }
        
        try {
            $db->beginTransaction();
            
            foreach ($preview['records'] as $record) {
                // Check if already exists
                $existing = $db->fetch(
                    "SELECT id FROM employee_bonus 
                     WHERE employee_id = :emp_id AND financial_year = :fy",
                    ['emp_id' => $record['employee_id'], 'fy' => $preview['financial_year']]
                );
                
                if ($existing) {
                    // Update
                    $db->update('employee_bonus', [
                        'basic_da' => $record['basic_da'],
                        'months_worked' => $record['months_worked'],
                        'bonus_rate' => $record['bonus_rate'],
                        'bonus_amount' => $record['bonus_amount'],
                        'status' => 'calculated',
                        'updated_at' => date(DATETIME_FORMAT_DB)
                    ], SQL_WHERE_ID, ['id' => $existing['id']]);
                } else {
                    // Insert
                    $db->insert('employee_bonus', [
                        'employee_id' => $record['employee_id'],
                        'financial_year' => $preview['financial_year'],
                        'basic_da' => $record['basic_da'],
                        'months_worked' => $record['months_worked'],
                        'bonus_rate' => $record['bonus_rate'],
                        'bonus_amount' => $record['bonus_amount'],
                        'status' => 'calculated',
                        'created_by' => $_SESSION['user_id'],
                        'created_at' => date(DATETIME_FORMAT_DB)
                    ]);
                }
            }
            
            $db->commit();
            unset($_SESSION['bonus_preview']);
            
            setFlash('success', 'Bonus records saved successfully');
            redirect(BONUS_PAGE_URL);
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Error saving bonus: ' . $e->getMessage());
        }
    }
    
    if ($_POST['action'] === 'disburse' && isset($_POST['bonus_id'])) {
        $bonusId = intval($_POST['bonus_id']);
        $paymentMonth = intval($_POST['payment_month']);
        $paymentYear = intval($_POST['payment_year']);
        
        $bonus = $db->fetch("SELECT * FROM employee_bonus WHERE id = :id", ['id' => $bonusId]);
        
        if (!$bonus) {
            setFlash('error', 'Bonus record not found');
            redirect(BONUS_PAGE_URL);
        }
        
        // Get or create payroll period
        $period = $db->fetch(
            "SELECT * FROM payroll_periods WHERE month = :month AND year = :year",
            ['month' => $paymentMonth, 'year' => $paymentYear]
        );
        
        if (!$period) {
            $startDate = date('Y-m-01', strtotime("$paymentYear-$paymentMonth-01"));
            $endDate = date('Y-m-t', strtotime("$paymentYear-$paymentMonth-01"));
            
            $periodId = $db->insert('payroll_periods', [
                'period_name' => date('F Y', strtotime("$paymentYear-$paymentMonth-01")),
                'month' => $paymentMonth,
                'year' => $paymentYear,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'draft',
                'created_by' => $_SESSION['user_id'],
                'created_at' => date(DATETIME_FORMAT_DB)
            ]);
        } else {
            $periodId = $period['id'];
        }
        
        // Add bonus to payroll
        $existingPayroll = $db->fetch(
            "SELECT * FROM payroll WHERE payroll_period_id = :period_id AND employee_id = :emp_id",
            ['period_id' => $periodId, 'emp_id' => $bonus['employee_id']]
        );
        
        if ($existingPayroll) {
            $db->update('payroll', [
                'bonus' => $existingPayroll['bonus'] + $bonus['bonus_amount'],
                'net_salary' => $existingPayroll['net_salary'] + $bonus['bonus_amount'],
                'updated_at' => date(DATETIME_FORMAT_DB)
            ], SQL_WHERE_ID, ['id' => $existingPayroll['id']]);
        } else {
            $db->insert('payroll', [
                'payroll_period_id' => $periodId,
                'employee_id' => $bonus['employee_id'],
                'bonus' => $bonus['bonus_amount'],
                'net_salary' => $bonus['bonus_amount'],
                'payment_status' => 'pending',
                'created_at' => date(DATETIME_FORMAT_DB)
            ]);
        }
        
        // Update bonus status
        $db->update('employee_bonus', [
            'status' => 'disbursed',
            'disbursed_at' => date(DATETIME_FORMAT_DB),
            'payment_period_id' => $periodId
        ], SQL_WHERE_ID, ['id' => $bonusId]);
        
        setFlash('success', 'Bonus added to payroll for disbursement');
        redirect(BONUS_PAGE_URL);
    }
}

// Get existing bonus records
$bonusRecords = $db->fetchAll(
    "SELECT b.*, e.employee_code, e.full_name, 
            c.name as client_name
     FROM employee_bonus b
     JOIN employees e ON b.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     ORDER BY b.financial_year DESC, e.full_name"
);

// Get preview data
$preview = $_SESSION['bonus_preview'] ?? null;
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-gift me-2"></i>Bonus Calculation
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#calculateBonusModal">
                    <i class="bi bi-calculator me-1"></i>Calculate Bonus
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($bonusRecords) && !$preview): ?>
                <div class="text-center py-5">
                    <i class="bi bi-gift text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">No Bonus Records</h5>
                    <p class="text-muted">Click "Calculate Bonus" to calculate annual bonus for eligible employees.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="bonusTable">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>FY</th>
                                <th class="text-center">Months</th>
                                <th class="text-end">Basic/Wage</th>
                                <th class="text-center">Rate</th>
                                <th class="text-end">Bonus Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bonusRecords as $b): ?>
                            <tr>
                                <td>
                                    <div><?php echo sanitize($b['full_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($b['employee_code']); ?></small>
                                </td>
                                <td><?php echo sanitize($b['financial_year']); ?></td>
                                <td class="text-center"><?php echo $b['months_worked']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($b['basic_da']); ?></td>
                                <td class="text-center"><?php echo $b['bonus_rate']; ?>%</td>
                                <td class="text-end"><strong><?php echo formatCurrency($b['bonus_amount']); ?></strong></td>
                                <td>
                                    <?php
                                    $statusColors = ['calculated' => 'warning', 'approved' => 'info', 'disbursed' => 'success'];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$b['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($b['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($b['status'] == 'calculated'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                            onclick='disburseBonus(<?php echo htmlspecialchars(json_encode($b)); ?>)'>
                                        <i class="bi bi-cash"></i> Disburse
                                    </button>
                                    <?php endif; ?>
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
    
    <!-- Preview Section -->
    <?php if ($preview): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm border-warning">
            <div class="card-header bg-warning bg-opacity-25 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-eye me-2"></i>Bonus Preview - FY <?php echo $preview['financial_year']; ?>
                </h5>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="save_bonus">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-check-lg me-1"></i>Save Bonus Records
                        </button>
                    </form>
                    <a href="index.php?page=payroll/bonus&clear_preview=1" class="btn btn-outline-secondary btn-sm ms-2">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Client</th>
                                <th class="text-center">Months Worked</th>
                                <th class="text-end">Wage (Capped)</th>
                                <th class="text-center">Rate</th>
                                <th class="text-end">Bonus Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['records'] as $r): ?>
                            <tr>
                                <td>
                                    <div><?php echo sanitize($r['full_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($r['employee_code']); ?></small>
                                </td>
                                <td><?php echo sanitize($r['client_name'] ?? '-'); ?></td>
                                <td class="text-center"><?php echo $r['months_worked']; ?></td>
                                <td class="text-end"><?php echo formatCurrency(min($r['basic_da'], $bonusWageCeiling)); ?></td>
                                <td class="text-center"><?php echo $r['bonus_rate']; ?>%</td>
                                <td class="text-end"><strong><?php echo formatCurrency($r['bonus_amount']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5">Total Bonus</th>
                                <th class="text-end text-success"><?php echo formatCurrency($preview['total']); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Info Cards -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Bonus Act Rules</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Eligibility: Salary ≤ ₹21,000/month</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Min. working: 30 days in a year</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Wage ceiling: ₹7,000 or minimum wage</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Bonus rate: 8.33% to 20%</li>
                    <li class="mb-0"><i class="bi bi-check-circle text-success me-2"></i>Payment: Within 8 months of FY end</li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-bar-chart me-2"></i>Current Settings</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted small">Minimum Rate</div>
                        <div class="h5 mb-0"><?php echo $bonusMinRate; ?>%</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Maximum Rate</div>
                        <div class="h5 mb-0"><?php echo $bonusMaxRate; ?>%</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Wage Ceiling</div>
                        <div class="h5 mb-0">₹<?php echo number_format($bonusWageCeiling); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Eligibility Limit</div>
                        <div class="h5 mb-0">₹21,000</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 bg-primary text-white">
            <div class="card-body">
                <h6>Pending Bonus</h6>
                <?php
                $pendingBonus = array_filter($bonusRecords, fn($b) => $b['status'] == 'calculated');
                $pendingTotal = array_sum(array_column($pendingBonus, 'bonus_amount'));
                ?>
                <div class="h2 mb-0"><?php echo formatCurrency($pendingTotal); ?></div>
                <small><?php echo count($pendingBonus); ?> employees pending</small>
            </div>
        </div>
    </div>
</div>

<!-- Calculate Bonus Modal -->
<div class="modal fade" id="calculateBonusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="calculate_all">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Calculate Annual Bonus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Financial Year</label>
                        <select class="form-select" name="financial_year" required>
                            <?php 
                            $currentFY = date('n') >= 4 ? date('Y') : date('Y') - 1;
                            for ($fy = $currentFY; $fy >= $currentFY - 3; $fy--): 
                            ?>
                                <option value="<?php echo $fy; ?>-<?php echo $fy+1; ?>">
                                    FY <?php echo $fy; ?>-<?php echo substr($fy+1, -2); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Bonus Rate (%)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="bonus_rate" 
                                   min="<?php echo $bonusMinRate; ?>" max="<?php echo $bonusMaxRate; ?>" 
                                   step="0.01" value="<?php echo $bonusMinRate; ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">
                            Min: <?php echo $bonusMinRate; ?>% | Max: <?php echo $bonusMaxRate; ?>%
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Eligibility Criteria:</strong>
                        <ul class="mb-0 mt-2 small">
                            <li>Employees with salary ≤ ₹21,000/month</li>
                            <li>Worked minimum 30 days in the financial year</li>
                            <li>Marked as "Bonus Applicable" in profile</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-1"></i>Calculate Bonus
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disburse Bonus Modal -->
<div class="modal fade" id="disburseBonusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="disburse">
                <input type="hidden" name="bonus_id" id="disburse_bonus_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cash me-2"></i>Disburse Bonus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bonus Amount</label>
                        <input type="text" class="form-control" id="disburse_amount" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Pay in Month</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <select class="form-select" name="payment_month" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select class="form-select" name="payment_year" required>
                                    <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                                    <option value="<?php echo date('Y')+1; ?>"><?php echo date('Y')+1; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Bonus will be added to the selected month's payroll.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-cash me-1"></i>Add to Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#bonusTable').DataTable({
        responsive: true,
        order: [[1, 'desc']]
    });
});

function disburseBonus(data) {
    $('#disburse_bonus_id').val(data.id);
    $('#disburse_amount').val('₹' + parseFloat(data.bonus_amount).toLocaleString('en-IN', {minimumFractionDigits: 2}));
    new bootstrap.Modal('#disburseBonusModal').show();
}

// Clear preview if requested
<?php if (isset($_GET['clear_preview'])): ?>
delete $_SESSION['bonus_preview'];
window.location.href = 'index.php?page=payroll/bonus';
<?php endif; ?>
</script>
