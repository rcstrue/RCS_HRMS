<?php
/**
 * RCS HRMS Pro - PT Challan Generator
 * Generate Professional Tax Challan for state-wise submission
 * 
 * Features:
 * - State-wise PT calculation
 * - Generate PT challan
 * - Track PT payments
 */

$pageTitle = 'PT Challan Generator';

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive', 'manager'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

// Get company details
$company = $db->fetch("SELECT * FROM companies LIMIT 1");
$ptRegNo = $company['pt_registration_number'] ?? '';

// Handle PT calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'calculate') {
        $state = sanitize($_POST['state']);
        $month = intval($_POST['month']);
        $year = intval($_POST['year']);
        
        // Get employees with PT applicable in the selected state
        $employees = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.gender,
                    COALESCE(u.state, e.current_state, e.permanent_state) as emp_state,
                    p.gross_salary, p.net_salary, p.pt_employee,
                    c.name as client_name
             FROM payroll p
             JOIN employees e ON p.employee_id = e.id
             LEFT JOIN clients c ON e.client_id = c.id
             LEFT JOIN units u ON e.unit_id = u.id
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE pp.month = :month AND pp.year = :year
                AND e.is_pt_applicable = 1
                AND (u.state = :state OR e.current_state = :state OR e.permanent_state = :state)
             ORDER BY e.full_name",
            ['month' => $month, 'year' => $year, 'state' => $state]
        );
        
        // Calculate PT based on state slabs
        $ptSlabs = $db->fetchAll(
            "SELECT * FROM professional_tax_slabs 
             WHERE state = :state AND is_active = 1
             ORDER BY salary_from",
            ['state' => $state]
        );
        
        $ptData = [];
        $totalPT = 0;
        $maleCount = 0;
        $femaleCount = 0;
        
        foreach ($employees as $emp) {
            $gross = floatval($emp['net_salary'] ?? 0); // PT calculated on net/gross
            
            // Find applicable PT slab
            $ptAmount = 0;
            foreach ($ptSlabs as $slab) {
                if ($gross >= $slab['salary_from'] && ($slab['salary_to'] === null || $gross <= $slab['salary_to'])) {
                    $ptAmount = floatval($slab['pt_amount']);
                    break;
                }
            }
            
            // Some states exempt female employees from PT
            // Currently applying PT to all, can be configured per state
            
            if ($ptAmount > 0) {
                $ptData[] = [
                    'employee_id' => $emp['id'],
                    'employee_code' => $emp['employee_code'],
                    'full_name' => $emp['full_name'],
                    'gender' => $emp['gender'],
                    'client_name' => $emp['client_name'],
                    'gross_salary' => $gross,
                    'pt_amount' => $ptAmount
                ];
                
                $totalPT += $ptAmount;
                if ($emp['gender'] == 'male') {
                    $maleCount++;
                } else {
                    $femaleCount++;
                }
            }
        }
        
        // Store in session for preview
        $_SESSION['pt_preview'] = [
            'state' => $state,
            'month' => $month,
            'year' => $year,
            'employees' => $ptData,
            'total_pt' => $totalPT,
            'male_count' => $maleCount,
            'female_count' => $femaleCount
        ];
        
        setFlash('success', "PT calculated for " . count($ptData) . " employees. Total: ₹" . number_format($totalPT, 2));
        redirect('index.php?page=compliance/pt-challan');
    }
    
    if ($_POST['action'] === 'save_challan') {
        $preview = $_SESSION['pt_preview'] ?? null;
        
        if (!$preview) {
            setFlash('error', 'No PT data to save');
            redirect('index.php?page=compliance/pt-challan');
        }
        
        $challanNo = 'PT' . date('Ymd') . rand(1000, 9999);
        $dueDate = date('Y-m-15', strtotime('+1 month')); // 15th of next month
        
        try {
            $challanId = $db->insert('pt_challans', [
                'challan_number' => $challanNo,
                'state' => $preview['state'],
                'month' => $preview['month'],
                'year' => $preview['year'],
                'total_employees' => count($preview['employees']),
                'male_count' => $preview['male_count'],
                'female_count' => $preview['female_count'],
                'total_amount' => $preview['total_pt'],
                'due_date' => $dueDate,
                'status' => 'pending',
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Save employee-wise PT
            foreach ($preview['employees'] as $emp) {
                $db->insert('pt_challan_details', [
                    'challan_id' => $challanId,
                    'employee_id' => $emp['employee_id'],
                    'gross_salary' => $emp['gross_salary'],
                    'pt_amount' => $emp['pt_amount']
                ]);
            }
            
            unset($_SESSION['pt_preview']);
            
            setFlash('success', "PT Challan generated: $challanNo");
            redirect('index.php?page=compliance/pt-challan');
        } catch (Exception $e) {
            setFlash('error', 'Error generating challan: ' . $e->getMessage());
        }
    }
    
    if ($_POST['action'] === 'mark_paid' && isset($_POST['challan_id'])) {
        $challanId = intval($_POST['challan_id']);
        $paymentDate = sanitize($_POST['payment_date']);
        $paymentRef = sanitize($_POST['payment_reference'] ?? '');
        
        $db->update('pt_challans', [
            'status' => 'paid',
            'payment_date' => $paymentDate,
            'payment_reference' => $paymentRef,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $challanId]);
        
        setFlash('success', 'PT Challan marked as paid');
        redirect('index.php?page=compliance/pt-challan');
    }
}

// Get existing challans
$challans = $db->fetchAll(
    "SELECT * FROM pt_challans ORDER BY created_at DESC"
);

// Get preview
$preview = $_SESSION['pt_preview'] ?? null;

// Get states with PT slabs
$states = $db->fetchAll(
    "SELECT DISTINCT state FROM professional_tax_slabs WHERE is_active = 1 ORDER BY state"
);
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-receipt me-2"></i>PT Challan Generator
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#calculatePTModal">
                    <i class="bi bi-calculator me-1"></i>Calculate PT
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($challans) && !$preview): ?>
                <div class="text-center py-5">
                    <i class="bi bi-receipt text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">No PT Challans</h5>
                    <p class="text-muted">Click "Calculate PT" to generate Professional Tax challan.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="challanTable">
                        <thead class="table-light">
                            <tr>
                                <th>Challan No</th>
                                <th>State</th>
                                <th>Period</th>
                                <th class="text-center">Employees</th>
                                <th class="text-end">Amount</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($challans as $c): ?>
                            <tr>
                                <td><code><?php echo sanitize($c['challan_number']); ?></code></td>
                                <td><?php echo sanitize($c['state']); ?></td>
                                <td><?php echo date('F Y', mktime(0,0,0,$c['month'],1,$c['year'])); ?></td>
                                <td class="text-center">
                                    <?php echo $c['total_employees']; ?>
                                    <small class="text-muted">(M: <?php echo $c['male_count']; ?>, F: <?php echo $c['female_count']; ?>)</small>
                                </td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($c['total_amount'], 2); ?></strong></td>
                                <td><?php echo formatDate($c['due_date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $c['status'] == 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($c['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($c['status'] == 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                            onclick='markPaid(<?php echo htmlspecialchars(json_encode($c)); ?>)'>
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick='viewChallan(<?php echo $c['id']; ?>)'>
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="index.php?page=compliance/pt-challan-print&id=<?php echo $c['id']; ?>" 
                                       class="btn btn-sm btn-outline-secondary" target="_blank">
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
    
    <!-- Preview Section -->
    <?php if ($preview): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm border-warning">
            <div class="card-header bg-warning bg-opacity-25 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-eye me-2"></i>PT Preview - <?php echo $preview['state']; ?> - 
                    <?php echo date('F Y', mktime(0,0,0,$preview['month'],1,$preview['year'])); ?>
                </h5>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="save_challan">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-check-lg me-1"></i>Generate Challan
                        </button>
                    </form>
                    <a href="index.php?page=compliance/pt-challan&clear=1" class="btn btn-outline-secondary btn-sm ms-2">
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
                                <th>Gender</th>
                                <th class="text-end">Salary</th>
                                <th class="text-end">PT Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['employees'] as $e): ?>
                            <tr>
                                <td>
                                    <div><?php echo sanitize($e['full_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($e['employee_code']); ?></small>
                                </td>
                                <td><?php echo ucfirst($e['gender']); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($e['gross_salary'], 2); ?></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($e['pt_amount'], 2); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3">Total PT (<?php echo count($preview['employees']); ?> employees)</th>
                                <th class="text-end text-primary"><?php echo '₹' . number_format($preview['total_pt'], 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- State PT Slabs -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-table me-2"></i>PT Slabs by State</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>State</th>
                                <th>Salary Range</th>
                                <th class="text-end">PT/Month</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $allSlabs = $db->fetchAll(
                                "SELECT * FROM professional_tax_slabs WHERE is_active = 1 ORDER BY state, salary_from"
                            );
                            $current = '';
                            foreach ($allSlabs as $s): 
                            ?>
                                <tr>
                                <td><?php echo $current != $s['state'] ? sanitize($s['state']) : ''; ?></td>
                                <td>
                                    <?php 
                                    echo '₹' . number_format($s['salary_from'], 2);
                                    echo $s['salary_to'] ? ' - ' . '₹' . number_format($s['salary_to'], 2) : '+';
                                    ?>
                                </td>
                                <td class="text-end"><?php echo '₹' . number_format($s['pt_amount'], 2); ?></td>
                            </tr>
                            <?php $current = $s['state']; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Summary -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>PT Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <strong>PT Registration No:</strong> 
                    <span class="text-muted"><?php echo sanitize($ptRegNo ?: 'Not configured'); ?></span>
                </div>
                <ul class="text-muted small mb-3">
                    <li>PT is deducted from employee's salary</li>
                    <li>Due date: Usually 15th of following month</li>
                    <li>Some states exempt female employees</li>
                    <li>Annual return required in some states</li>
                </ul>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-warning bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Pending Challans</div>
                            <div class="h4 mb-0">
                                <?php echo count(array_filter($challans, fn($c) => $c['status'] == 'pending')); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-danger bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Pending Amount</div>
                            <div class="h4 mb-0">
                                <?php 
                                echo '₹' . number_format(
                                    array_sum(array_map(
                                        fn($c) => $c['status'] == 'pending' ? $c['total_amount'] : 0, 
                                        $challans
                                    )),
                                    2
                                );
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Calculate PT Modal -->
<div class="modal fade" id="calculatePTModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="calculate">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Calculate Professional Tax</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">State</label>
                        <select class="form-select" name="state" required>
                            <option value="">Select State</option>
                            <?php foreach ($states as $s): ?>
                            <option value="<?php echo sanitize($s['state']); ?>">
                                <?php echo sanitize($s['state']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Month/Year</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <select class="form-select" name="month" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select class="form-select" name="year" required>
                                    <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                                    <option value="<?php echo date('Y')-1; ?>"><?php echo date('Y')-1; ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        PT will be calculated based on state-wise slabs for employees in that state.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-1"></i>Calculate PT
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Paid Modal -->
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="challan_id" id="paid_challan_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Mark as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="text" class="form-control" id="paid_amount" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Payment Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Reference</label>
                        <input type="text" class="form-control" name="payment_reference" placeholder="Challan/Transaction ID">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#challanTable').DataTable({
        responsive: true,
        order: [[5, 'asc']]
    });
});

function markPaid(data) {
    $('#paid_challan_id').val(data.id);
    $('#paid_amount').val('₹' + parseFloat(data.total_amount).toLocaleString('en-IN', {minimumFractionDigits: 2}));
    new bootstrap.Modal('#markPaidModal').show();
}

function viewChallan(id) {
    // Load challan details via AJAX and show in modal
    window.location.href = 'index.php?page=compliance/pt-challan-print&id=' + id;
}

// Clear preview if requested
<?php if (isset($_GET['clear'])): ?>
delete $_SESSION['pt_preview'];
window.location.href = 'index.php?page=compliance/pt-challan';
<?php endif; ?>
</script>
