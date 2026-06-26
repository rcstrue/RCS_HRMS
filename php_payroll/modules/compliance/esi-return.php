<?php
/**
 * RCS HRMS Pro - ESI Return Generator
 * Generate ESI Return and challan for ESIC submission
 * 
 * Features:
 * - Monthly ESI contribution calculation
 * - ESI Return generation
 * - Track ESI payments
 * - Employee ESI details
 */

$pageTitle = 'ESI Return Generator';

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive', 'manager'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

// Get company details
$company = $db->fetch("SELECT * FROM companies LIMIT 1");
$esiEstId = $company['esi_establishment_id'] ?? '';

// Get ESI rates from settings
$esiEeRate = floatval($db->fetchColumn("SELECT setting_value FROM settings WHERE setting_key = 'esi_rate_employee'") ?: 0.75);
$esiErRate = floatval($db->fetchColumn("SELECT setting_value FROM settings WHERE setting_key = 'esi_rate_employer'") ?: 3.25);
$esiWageCeiling = floatval($db->fetchColumn("SELECT setting_value FROM settings WHERE setting_key = 'esi_wage_ceiling'") ?: 21000);

// Handle ESI calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'calculate') {
        $month = intval($_POST['month']);
        $year = intval($_POST['year']);
        
        // Get employees with ESI applicable
        $employees = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.esi_number, e.gender,
                    e.date_of_joining, e.date_of_leaving,
                    c.name as client_name,
                    p.gross_salary, p.net_salary, p.esi_employee, p.esi_employer,
                    p.present_days, p.paid_days, p.total_working_days
             FROM payroll p
             JOIN employees e ON p.employee_id = e.id
             LEFT JOIN clients c ON e.client_id = c.id
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE pp.month = :month AND pp.year = :year
                AND e.is_esi_applicable = 1
                AND p.gross_salary <= :ceiling
             ORDER BY e.full_name",
            ['month' => $month, 'year' => $year, 'ceiling' => $esiWageCeiling]
        );
        
        $esiData = [];
        $totalWages = 0;
        $totalEE = 0;
        $totalER = 0;
        $maleCount = 0;
        $femaleCount = 0;
        
        foreach ($employees as $emp) {
            $gross = floatval($emp['gross_salary'] ?? 0);
            
            // Calculate ESI contributions
            $eeContribution = round($gross * $esiEeRate / 100, 2);
            $erContribution = round($gross * $esiErRate / 100, 2);
            
            if ($eeContribution > 0 || $erContribution > 0) {
                $esiData[] = [
                    'employee_id' => $emp['id'],
                    'employee_code' => $emp['employee_code'],
                    'full_name' => $emp['full_name'],
                    'esi_number' => $emp['esi_number'],
                    'gender' => $emp['gender'],
                    'client_name' => $emp['client_name'],
                    'gross_salary' => $gross,
                    'ee_contribution' => $eeContribution,
                    'er_contribution' => $erContribution,
                    'total_contribution' => $eeContribution + $erContribution
                ];
                
                $totalWages += $gross;
                $totalEE += $eeContribution;
                $totalER += $erContribution;
                
                if ($emp['gender'] == 'male') {
                    $maleCount++;
                } else {
                    $femaleCount++;
                }
            }
        }
        
        // Store in session
        $_SESSION['esi_preview'] = [
            'month' => $month,
            'year' => $year,
            'employees' => $esiData,
            'total_wages' => $totalWages,
            'total_ee' => $totalEE,
            'total_er' => $totalER,
            'total_contribution' => $totalEE + $totalER,
            'male_count' => $maleCount,
            'female_count' => $femaleCount
        ];
        
        setFlash('success', "ESI calculated for " . count($esiData) . " employees. Total: ₹" . number_format($totalEE + $totalER, 2));
        redirect('index.php?page=compliance/esi-return');
    }
    
    if ($_POST['action'] === 'save_return') {
        $preview = $_SESSION['esi_preview'] ?? null;
        
        if (!$preview) {
            setFlash('error', 'No ESI data to save');
            redirect('index.php?page=compliance/esi-return');
        }
        
        $returnNo = 'ESI' . date('Ymd') . rand(1000, 9999);
        $dueDate = date('Y-m-15', strtotime('+1 month'));
        
        try {
            $returnId = $db->insert('esi_returns', [
                'return_number' => $returnNo,
                'month' => $preview['month'],
                'year' => $preview['year'],
                'total_employees' => count($preview['employees']),
                'male_count' => $preview['male_count'],
                'female_count' => $preview['female_count'],
                'total_wages' => $preview['total_wages'],
                'ee_contribution' => $preview['total_ee'],
                'er_contribution' => $preview['total_er'],
                'total_contribution' => $preview['total_contribution'],
                'due_date' => $dueDate,
                'status' => 'pending',
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Save employee-wise details
            foreach ($preview['employees'] as $emp) {
                $db->insert('esi_return_details', [
                    'return_id' => $returnId,
                    'employee_id' => $emp['employee_id'],
                    'esi_number' => $emp['esi_number'],
                    'gross_wages' => $emp['gross_salary'],
                    'ee_contribution' => $emp['ee_contribution'],
                    'er_contribution' => $emp['er_contribution']
                ]);
            }
            
            unset($_SESSION['esi_preview']);
            
            setFlash('success', "ESI Return generated: $returnNo");
            redirect('index.php?page=compliance/esi-return');
        } catch (Exception $e) {
            setFlash('error', 'Error generating return: ' . $e->getMessage());
        }
    }
    
    if ($_POST['action'] === 'mark_paid' && isset($_POST['return_id'])) {
        $returnId = intval($_POST['return_id']);
        $paymentDate = sanitize($_POST['payment_date']);
        $paymentRef = sanitize($_POST['payment_reference'] ?? '');
        
        $db->update('esi_returns', [
            'status' => 'paid',
            'payment_date' => $paymentDate,
            'payment_reference' => $paymentRef,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $returnId]);
        
        setFlash('success', 'ESI Return marked as paid');
        redirect('index.php?page=compliance/esi-return');
    }
}

// Get existing returns
$returns = $db->fetchAll(
    "SELECT * FROM esi_returns ORDER BY created_at DESC"
);

// Get preview
$preview = $_SESSION['esi_preview'] ?? null;
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-hospital me-2"></i>ESI Return Generator
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#calculateESIModal">
                    <i class="bi bi-calculator me-1"></i>Calculate ESI
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($returns) && !$preview): ?>
                <div class="text-center py-5">
                    <i class="bi bi-hospital text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">No ESI Returns</h5>
                    <p class="text-muted">Click "Calculate ESI" to generate ESI return for eligible employees.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="esiTable">
                        <thead class="table-light">
                            <tr>
                                <th>Return No</th>
                                <th>Period</th>
                                <th class="text-center">Employees</th>
                                <th class="text-end">Total Wages</th>
                                <th class="text-end">EE Share</th>
                                <th class="text-end">ER Share</th>
                                <th class="text-end">Total</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returns as $r): ?>
                            <tr>
                                <td><code><?php echo sanitize($r['return_number']); ?></code></td>
                                <td><?php echo date('F Y', mktime(0,0,0,$r['month'],1,$r['year'])); ?></td>
                                <td class="text-center">
                                    <?php echo $r['total_employees']; ?>
                                    <small class="text-muted">(M: <?php echo $r['male_count']; ?>, F: <?php echo $r['female_count']; ?>)</small>
                                </td>
                                <td class="text-end"><?php echo '₹' . number_format($r['total_wages'], 2); ?></td>
                                <td class="text-end text-primary"><?php echo '₹' . number_format($r['ee_contribution'], 2); ?></td>
                                <td class="text-end text-danger"><?php echo '₹' . number_format($r['er_contribution'], 2); ?></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($r['total_contribution'], 2); ?></strong></td>
                                <td><?php echo formatDate($r['due_date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $r['status'] == 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($r['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['status'] == 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                            onclick='markPaid(<?php echo htmlspecialchars(json_encode($r)); ?>)'>
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick='viewReturn(<?php echo $r['id']; ?>)'>
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="index.php?page=compliance/esi-return-print&id=<?php echo $r['id']; ?>" 
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
                    <i class="bi bi-eye me-2"></i>ESI Preview - 
                    <?php echo date('F Y', mktime(0,0,0,$preview['month'],1,$preview['year'])); ?>
                </h5>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="save_return">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-check-lg me-1"></i>Generate Return
                        </button>
                    </form>
                    <a href="index.php?page=compliance/esi-return&clear=1" class="btn btn-outline-secondary btn-sm ms-2">
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
                                <th>ESI No</th>
                                <th class="text-end">Gross Wages</th>
                                <th class="text-end">EE (<?php echo $esiEeRate; ?>%)</th>
                                <th class="text-end">ER (<?php echo $esiErRate; ?>%)</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['employees'] as $e): ?>
                            <tr>
                                <td>
                                    <div><?php echo sanitize($e['full_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($e['employee_code']); ?></small>
                                </td>
                                <td><code><?php echo sanitize($e['esi_number'] ?: 'N/A'); ?></code></td>
                                <td class="text-end"><?php echo '₹' . number_format($e['gross_salary'], 2); ?></td>
                                <td class="text-end text-primary"><?php echo '₹' . number_format($e['ee_contribution'], 2); ?></td>
                                <td class="text-end text-danger"><?php echo '₹' . number_format($e['er_contribution'], 2); ?></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($e['total_contribution'], 2); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2">Total (<?php echo count($preview['employees']); ?> employees)</th>
                                <th class="text-end"><?php echo '₹' . number_format($preview['total_wages'], 2); ?></th>
                                <th class="text-end text-primary"><?php echo '₹' . number_format($preview['total_ee'], 2); ?></th>
                                <th class="text-end text-danger"><?php echo '₹' . number_format($preview['total_er'], 2); ?></th>
                                <th class="text-end text-success"><?php echo '₹' . number_format($preview['total_contribution'], 2); ?></th>
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
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>ESI Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <strong>ESI Establishment ID:</strong> 
                    <span class="text-muted"><?php echo sanitize($esiEstId ?: 'Not configured'); ?></span>
                </div>
                <ul class="text-muted small mb-3">
                    <li>Eligibility: Gross salary ≤ ₹<?php echo number_format($esiWageCeiling); ?>/month</li>
                    <li>Employee Contribution: <?php echo $esiEeRate; ?>%</li>
                    <li>Employer Contribution: <?php echo $esiErRate; ?>%</li>
                    <li>Due Date: 15th of following month</li>
                </ul>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="p-2 bg-primary bg-opacity-25 rounded text-center">
                            <div class="small text-muted">EE Rate</div>
                            <div class="h5 mb-0"><?php echo $esiEeRate; ?>%</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 bg-danger bg-opacity-25 rounded text-center">
                            <div class="small text-muted">ER Rate</div>
                            <div class="h5 mb-0"><?php echo $esiErRate; ?>%</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-bar-chart me-2"></i>Summary</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-warning bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Pending Returns</div>
                            <div class="h4 mb-0">
                                <?php echo count(array_filter($returns, fn($r) => $r['status'] == 'pending')); ?>
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
                                        fn($r) => $r['status'] == 'pending' ? $r['total_contribution'] : 0, 
                                        $returns
                                    )),
                                    2
                                );
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-success bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Paid Returns</div>
                            <div class="h4 mb-0">
                                <?php echo count(array_filter($returns, fn($r) => $r['status'] == 'paid')); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-info bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Total Paid</div>
                            <div class="h4 mb-0">
                                <?php 
                                echo '₹' . number_format(
                                    array_sum(array_map(
                                        fn($r) => $r['status'] == 'paid' ? $r['total_contribution'] : 0, 
                                        $returns
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
    
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-event me-2"></i>Important Dates</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="d-flex align-items-center mb-3">
                        <span class="badge bg-danger me-2">15th</span>
                        <span>Monthly contribution due</span>
                    </li>
                    <li class="d-flex align-items-center mb-3">
                        <span class="badge bg-warning me-2">11th</span>
                        <span>Monthly return filing</span>
                    </li>
                    <li class="d-flex align-items-center mb-3">
                        <span class="badge bg-info me-2">Apr 30</span>
                        <span>Annual return (Form 01A)</span>
                    </li>
                    <li class="d-flex align-items-center">
                        <span class="badge bg-primary me-2">Jan 31</span>
                        <span>Half-yearly return</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Calculate ESI Modal -->
<div class="modal fade" id="calculateESIModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="calculate">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Calculate ESI</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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
                        ESI will be calculated for employees with:
                        <ul class="mb-0 mt-2">
                            <li>ESI Applicable = Yes</li>
                            <li>Gross Salary ≤ ₹<?php echo number_format($esiWageCeiling); ?></li>
                            <li>Valid ESI Number</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-1"></i>Calculate ESI
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
                <input type="hidden" name="return_id" id="paid_return_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Mark as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Total Amount</label>
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
    $('#esiTable').DataTable({
        responsive: true,
        order: [[7, 'asc']]
    });
});

function markPaid(data) {
    $('#paid_return_id').val(data.id);
    $('#paid_amount').val('₹' + parseFloat(data.total_contribution).toLocaleString('en-IN', {minimumFractionDigits: 2}));
    new bootstrap.Modal('#markPaidModal').show();
}

function viewReturn(id) {
    window.location.href = 'index.php?page=compliance/esi-return-print&id=' + id;
}

// Clear preview if requested
<?php if (isset($_GET['clear'])): ?>
delete $_SESSION['esi_preview'];
window.location.href = 'index.php?page=compliance/esi-return';
<?php endif; ?>
</script>
