<?php
/**
 * RCS HRMS Pro - Professional Tax (PT) Returns
 * Manage PT challans and returns for different states
 */

$pageTitle = 'Professional Tax Returns';

// Check if professional_tax_rates table exists
$ptTableExists = false;
try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'professional_tax_rates'");
    $ptTableExists = $checkStmt->rowCount() > 0;
} catch (Exception $e) {}

// Get states for filter
$states = [];
try {
    $statesStmt = $db->query("SELECT DISTINCT state FROM units WHERE state IS NOT NULL AND state != '' ORDER BY state");
    $states = $statesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get selected month/year
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedState = sanitize($_GET['state'] ?? '');

// Handle form submission for PT challan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);
    
    if ($action === 'save_challan') {
        $challanData = [
            'state' => sanitize($_POST['state']),
            'month' => (int)$_POST['month'],
            'year' => (int)$_POST['year'],
            'challan_number' => sanitize($_POST['challan_number']),
            'challan_date' => sanitize($_POST['challan_date']),
            'amount' => (float)$_POST['amount'],
            'total_employees' => (int)$_POST['total_employees'],
            'remarks' => sanitize($_POST['remarks'] ?? ''),
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            // Check if pt_challans table exists
            $db->query("CREATE TABLE IF NOT EXISTS pt_challans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                state VARCHAR(100) NOT NULL,
                month INT NOT NULL,
                year INT NOT NULL,
                challan_number VARCHAR(100),
                challan_date DATE,
                amount DECIMAL(12,2),
                total_employees INT DEFAULT 0,
                remarks TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_state_month_year (state, month, year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $insertStmt = $db->prepare("INSERT INTO pt_challans (state, month, year, challan_number, challan_date, amount, total_employees, remarks, created_by, created_at) 
                                        VALUES (:state, :month, :year, :challan_number, :challan_date, :amount, :total_employees, :remarks, :created_by, :created_at)");
            $insertStmt->execute($challanData);
            
            setFlash('success', 'PT Challan saved successfully');
            redirect("index.php?page=compliance/pt&month=$selectedMonth&year=$selectedYear");
        } catch (Exception $e) {
            setFlash('error', 'Error saving challan: ' . $e->getMessage());
        }
    }
}

// Get PT challans for selected period
$challans = [];
try {
    $challansStmt = $db->prepare("SELECT * FROM pt_challans WHERE month = ? AND year = ? ORDER BY state");
    $challansStmt->execute([$selectedMonth, $selectedYear]);
    $challans = $challansStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Calculate PT liability by state
$ptLiability = [];
try {
    // Get employees grouped by state with their PT deductions
    $ptStmt = $db->prepare("SELECT 
        u.state,
        COUNT(DISTINCT e.id) as employee_count,
        SUM(COALESCE(p.professional_tax, 0)) as total_pt
        FROM employees e
        JOIN units u ON e.unit_id = u.id
        LEFT JOIN payroll p ON e.employee_code = p.employee_id
        LEFT JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        WHERE e.status = 'approved'
        AND pp.month = ? AND pp.year = ?
        AND u.state IS NOT NULL AND u.state != ''
        GROUP BY u.state
        ORDER BY u.state");
    $ptStmt->execute([$selectedMonth, $selectedYear]);
    $ptLiability = $ptStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-receipt me-2"></i>Professional Tax Returns
                </h5>
            </div>
            
            <!-- Period Selection -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="compliance/pt">
                    
                    <div class="col-md-3">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">State</label>
                        <select class="form-select" name="state">
                            <option value="">All States</option>
                            <?php foreach ($states as $s): ?>
                            <option value="<?php echo sanitize($s['state']); ?>" <?php echo $selectedState === $s['state'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($s['state']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>View
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- PT Liability Summary -->
            <div class="card-body">
                <h6 class="mb-3">PT Liability Summary - <?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?></h6>
                
                <?php if (empty($ptLiability)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    No PT liability data found for the selected period. Please ensure payroll has been processed.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>State</th>
                                <th class="text-center">Employees</th>
                                <th class="text-end">PT Deducted (₹)</th>
                                <th class="text-end">Challan Amount (₹)</th>
                                <th class="text-end">Balance (₹)</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalEmployees = 0;
                            $totalPT = 0;
                            $totalChallan = 0;
                            
                            foreach ($ptLiability as $pt): 
                                // Find matching challan
                                $challanAmount = 0;
                                $challan = null;
                                foreach ($challans as $ch) {
                                    if ($ch['state'] === $pt['state']) {
                                        $challanAmount = (float)$ch['amount'];
                                        $challan = $ch;
                                        break;
                                    }
                                }
                                
                                $balance = (float)$pt['total_pt'] - $challanAmount;
                                $totalEmployees += $pt['employee_count'];
                                $totalPT += (float)$pt['total_pt'];
                                $totalChallan += $challanAmount;
                                
                                $status = 'Pending';
                                $statusClass = 'warning';
                                if ($challanAmount > 0 && $balance == 0) {
                                    $status = 'Paid';
                                    $statusClass = 'success';
                                } elseif ($challanAmount > 0) {
                                    $status = 'Partial';
                                    $statusClass = 'info';
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo sanitize($pt['state']); ?></strong></td>
                                <td class="text-center"><?php echo number_format($pt['employee_count']); ?></td>
                                <td class="text-end"><?php echo number_format($pt['total_pt'], 2); ?></td>
                                <td class="text-end">
                                    <?php if ($challan): ?>
                                    <?php echo number_format($challanAmount, 2); ?>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo number_format($balance, 2); ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $statusClass; ?>-soft"><?php echo $status; ?></span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="openChallanModal('<?php echo sanitize($pt['state']); ?>', <?php echo $pt['employee_count']; ?>, <?php echo $pt['total_pt']; ?>)">
                                        <i class="bi bi-plus-circle me-1"></i>Add Challan
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td>Total</td>
                                <td class="text-center"><?php echo number_format($totalEmployees); ?></td>
                                <td class="text-end"><?php echo number_format($totalPT, 2); ?></td>
                                <td class="text-end"><?php echo number_format($totalChallan, 2); ?></td>
                                <td class="text-end <?php echo ($totalPT - $totalChallan) > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo number_format($totalPT - $totalChallan, 2); ?>
                                </td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Challans -->
            <?php if (!empty($challans)): ?>
            <div class="card-body border-top">
                <h6 class="mb-3">Recent PT Challans</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>State</th>
                                <th>Challan No.</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Employees</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($challans as $ch): ?>
                            <tr>
                                <td><?php echo sanitize($ch['state']); ?></td>
                                <td><?php echo sanitize($ch['challan_number']); ?></td>
                                <td><?php echo formatDate($ch['challan_date']); ?></td>
                                <td>₹<?php echo number_format($ch['amount'], 2); ?></td>
                                <td><?php echo $ch['total_employees']; ?></td>
                                <td><small><?php echo sanitize($ch['remarks']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- PT Challan Modal -->
<div class="modal fade" id="challanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="save_challan">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add PT Challan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">State</label>
                        <input type="text" class="form-control" name="state" id="challan_state" required readonly>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">Employees</label>
                            <input type="text" class="form-control" id="challan_employees" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label">PT Liability (₹)</label>
                            <input type="text" class="form-control" id="challan_liability" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Challan Number</label>
                        <input type="text" class="form-control" name="challan_number" required placeholder="Enter challan number">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Challan Date</label>
                        <input type="date" class="form-control" name="challan_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required">Amount (₹)</label>
                        <input type="number" class="form-control" name="amount" step="0.01" required placeholder="Enter challan amount">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Total Employees (as per challan)</label>
                        <input type="number" class="form-control" name="total_employees" id="challan_total_employees" placeholder="Number of employees">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Challan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let challanModal;

document.addEventListener('DOMContentLoaded', function() {
    challanModal = new bootstrap.Modal(document.getElementById('challanModal'));
});

function openChallanModal(state, employees, liability) {
    document.getElementById('challan_state').value = state;
    document.getElementById('challan_employees').value = employees;
    document.getElementById('challan_liability').value = '₹' + liability.toLocaleString('en-IN', {minimumFractionDigits: 2});
    document.getElementById('challan_total_employees').value = employees;
    document.querySelector('input[name="amount"]').value = liability.toFixed(2);
    challanModal.show();
}
</script>
