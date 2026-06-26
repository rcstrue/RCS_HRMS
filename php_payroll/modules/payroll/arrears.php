<?php
/**
 * RCS HRMS Pro - Arrear Calculation Module
 * Calculate and process salary arrears for employees
 * 
 * Features:
 * - Salary revision arrears (increment/decrement)
 * - Minimum wage revision arrears
 * - Correction arrears (mistakes in previous payroll)
 * - Missing payment arrears
 * 
 * Routed through index.php - no need to re-include config/database
 */

$pageTitle = 'Arrear Calculation';

// Page URL constant
define('ARREARS_PAGE_URL', 'index.php?page=payroll/arrears');

// Handle arrear calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'calculate') {
        $employeeId = sanitize($_POST['employee_id']);
        $arrearType = sanitize($_POST['arrear_type']);
        $fromMonth = intval($_POST['from_month']);
        $fromYear = intval($_POST['from_year']);
        $toMonth = intval($_POST['to_month']);
        $toYear = intval($_POST['to_year']);
        $reason = sanitize($_POST['reason'] ?? '');
        
        // Get employee details
        $employee = $db->fetch(
            "SELECT e.*, ess.basic_da, ess.hra, ess.gross_salary, ess.effective_from
             FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
             WHERE e.id = :id",
            ['id' => $employeeId]
        );
        
        if (!$employee) {
            setFlash('error', 'Employee not found');
            redirect(ARREARS_PAGE_URL);
        }
        
        // Calculate months between from and to
        $startDate = new DateTime("$fromYear-$fromMonth-01");
        $endDate = new DateTime("$toYear-$toMonth-01");
        $interval = $startDate->diff($endDate);
        $monthsCount = ($interval->y * 12) + $interval->m + 1;
        
        // Get old and new salary based on arrear type
        $oldBasicDa = 0;
        $newBasicDa = floatval($employee['basic_da'] ?? $employee['basic_salary'] ?? 0);
        $oldHRA = 0;
        $newHRA = floatval($employee['hra'] ?? 0);
        $difference = 0;
        
        if ($arrearType === 'salary_revision') {
            // Get previous salary structure
            $prevSalary = $db->fetch(
                "SELECT * FROM employee_salary_structures 
                 WHERE employee_id = :id AND effective_to IS NOT NULL 
                 ORDER BY effective_to DESC LIMIT 1",
                ['id' => $employeeId]
            );
            
            if ($prevSalary) {
                $oldBasicDa = floatval($prevSalary['basic_da']);
                $oldHRA = floatval($prevSalary['hra']);
            }
            
            $difference = (($newBasicDa - $oldBasicDa) + ($newHRA - $oldHRA)) * $monthsCount;
        } elseif ($arrearType === 'minimum_wage') {
            // Get minimum wage revision difference
            $oldWage = floatval($_POST['old_wage'] ?? 0);
            $newWage = floatval($_POST['new_wage'] ?? $newBasicDa);
            $difference = ($newWage - $oldWage) * $monthsCount;
        } elseif ($arrearType === 'correction') {
            // Direct arrear amount
            $difference = floatval($_POST['arrear_amount'] ?? 0);
            $monthsCount = 1;
        }
        
        // Calculate statutory deductions on arrear
        $pfArrear = 0;
        $esiArrear = 0;
        $ptArrear = 0;
        
        if ($employee['is_pf_applicable'] && $difference > 0) {
            $pfArrear = $difference * 0.12; // 12% PF
        }
        
        if ($employee['is_esi_applicable'] && ($employee['gross_salary'] ?? 0) <= 21000) {
            $esiArrear = $difference * 0.0075; // 0.75% ESI
        }
        
        // PT on arrear (typically not applicable on arrears)
        $ptArrear = 0;
        
        $netArrear = $difference - $pfArrear - $esiArrear - $ptArrear;
        
        // Save arrear record
        $arrearData = [
            'employee_id' => $employeeId,
            'arrear_type' => $arrearType,
            'from_month' => $fromMonth,
            'from_year' => $fromYear,
            'to_month' => $toMonth,
            'to_year' => $toYear,
            'months_count' => $monthsCount,
            'old_basic' => $oldBasicDa,
            'new_basic' => $newBasicDa,
            'old_hra' => $oldHRA,
            'new_hra' => $newHRA,
            'gross_arrear' => $difference,
            'pf_arrear' => $pfArrear,
            'esi_arrear' => $esiArrear,
            'pt_arrear' => $ptArrear,
            'net_arrear' => $netArrear,
            'reason' => $reason,
            'status' => 'pending',
            'created_by' => $_SESSION['user_id'],
            'created_at' => date(DATETIME_FORMAT_DB)
        ];
        
        try {
            $arrearId = $db->insert('employee_arrears', $arrearData);
            setFlash('success', "Arrear calculated successfully. Net Arrear: ₹" . number_format($netArrear, 2));
            redirect(ARREARS_PAGE_URL);
        } catch (Exception $e) {
            setFlash('error', 'Error saving arrear: ' . $e->getMessage());
        }
    }
    
    if ($_POST['action'] === 'approve' && isset($_POST['arrear_id'])) {
        $arrearId = intval($_POST['arrear_id']);
        $paymentMonth = intval($_POST['payment_month']);
        $paymentYear = intval($_POST['payment_year']);
        
        // Get arrear details
        $arrear = $db->fetch(
            "SELECT * FROM employee_arrears WHERE id = :id",
            ['id' => $arrearId]
        );
        
        if (!$arrear) {
            setFlash('error', 'Arrear not found');
            redirect(ARREARS_PAGE_URL);
        }
        
        // Get or create payroll period
        $period = $db->fetch(
            "SELECT * FROM payroll_periods WHERE month = :month AND year = :year",
            ['month' => $paymentMonth, 'year' => $paymentYear]
        );
        
        if (!$period) {
            // Create payroll period
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
        
        // Add arrear to payroll
        $existingPayroll = $db->fetch(
            "SELECT * FROM payroll WHERE payroll_period_id = :period_id AND employee_id = :emp_id",
            ['period_id' => $periodId, 'emp_id' => $arrear['employee_id']]
        );
        
        if ($existingPayroll) {
            // Update existing payroll
            $db->update('payroll', [
                'arrears' => $existingPayroll['arrears'] + $arrear['gross_arrear'],
                'pf_employee' => $existingPayroll['pf_employee'] + $arrear['pf_arrear'],
                'esi_employee' => $existingPayroll['esi_employee'] + $arrear['esi_arrear'],
                'net_salary' => $existingPayroll['net_salary'] + $arrear['net_arrear'],
                'updated_at' => date(DATETIME_FORMAT_DB)
            ], 'id = :id', ['id' => $existingPayroll['id']]);
        } else {
            // Create new payroll record with arrear
            $db->insert('payroll', [
                'payroll_period_id' => $periodId,
                'employee_id' => $arrear['employee_id'],
                'arrears' => $arrear['gross_arrear'],
                'pf_employee' => $arrear['pf_arrear'],
                'esi_employee' => $arrear['esi_arrear'],
                'net_salary' => $arrear['net_arrear'],
                'payment_status' => 'pending',
                'created_at' => date(DATETIME_FORMAT_DB)
            ]);
        }
        
        // Update arrear status
        $db->update('employee_arrears', [
            'status' => 'approved',
            'approved_by' => $_SESSION['user_id'],
            'approved_at' => date(DATETIME_FORMAT_DB),
            'payment_period_id' => $periodId
        ], 'id = :id', ['id' => $arrearId]);
        
        setFlash('success', 'Arrear approved and added to payroll');
        redirect(ARREARS_PAGE_URL);
    }
}

// Get existing arrears
$arrears = $db->fetchAll(
    "SELECT a.*, e.employee_code, e.full_name, 
            c.name as client_name
     FROM employee_arrears a
     JOIN employees e ON a.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     ORDER BY a.created_at DESC"
);

// Get employees for dropdown
$employees = $db->fetchAll(
    "SELECT e.id, e.employee_code, e.full_name, e.designation,
            c.name as client_name
     FROM employees e
     LEFT JOIN clients c ON e.client_id = c.id
     WHERE e.status = 'active'
     ORDER BY e.full_name"
);

?>
<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-cash-stack me-2"></i>Arrear Calculation
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newArrearModal">
                    <i class="bi bi-plus-lg me-1"></i>Calculate Arrear
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($arrears)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-cash-stack text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">No Arrears</h5>
                    <p class="text-muted">Click "Calculate Arrear" to create a new arrear entry.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="arrearTable">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PF</th>
                                <th class="text-end">ESI</th>
                                <th class="text-end">Net</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($arrears as $a): ?>
                            <tr>
                                <td>
                                    <div><?php echo sanitize($a['full_name']); ?></div>
                                    <small class="text-muted"><?php echo sanitize($a['employee_code']); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $typeLabels = [
                                        'salary_revision' => '<span class="badge bg-primary">Salary Revision</span>',
                                        'minimum_wage' => '<span class="badge bg-info">Min Wage Revision</span>',
                                        'correction' => '<span class="badge bg-warning text-dark">Correction</span>'
                                    ];
                                    echo $typeLabels[$a['arrear_type']] ?? $a['arrear_type'];
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    echo date('M Y', mktime(0,0,0,$a['from_month'],1,$a['from_year']));
                                    if ($a['months_count'] > 1) {
                                        echo ' - ' . date('M Y', mktime(0,0,0,$a['to_month'],1,$a['to_year']));
                                    }
                                    ?>
                                    <br><small class="text-muted"><?php echo $a['months_count']; ?> month(s)</small>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($a['gross_arrear']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($a['pf_arrear']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($a['esi_arrear']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($a['net_arrear']); ?></strong></td>
                                <td>
                                    <?php
                                    $statusColors = ['pending' => 'warning', 'approved' => 'success', 'paid' => 'primary'];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$a['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($a['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($a['status'] == 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                            onclick='approveArrear(<?php echo htmlspecialchars(json_encode($a)); ?>)'>
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick='viewArrear(<?php echo htmlspecialchars(json_encode($a)); ?>)'>
                                        <i class="bi bi-eye"></i>
                                    </button>
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
    
    <!-- Info Card -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Arrear Types</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6><span class="badge bg-primary">Salary Revision</span></h6>
                    <p class="text-muted small mb-0">
                        Arrear due to salary increment/decrement. System calculates difference between old and new salary.
                    </p>
                </div>
                <div class="mb-3">
                    <h6><span class="badge bg-info">Minimum Wage Revision</span></h6>
                    <p class="text-muted small mb-0">
                        Arrear due to minimum wage notification by government. Applicable when new rates are effective from past date.
                    </p>
                </div>
                <div class="mb-0">
                    <h6><span class="badge bg-warning text-dark">Correction</span></h6>
                    <p class="text-muted small mb-0">
                        Manual arrear entry for corrections in previous payroll or missing payments.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Summary -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Summary</h5>
            </div>
            <div class="card-body">
                <?php
                $pendingArrears = array_filter($arrears, fn($a) => $a['status'] == 'pending');
                $totalPending = array_sum(array_column($pendingArrears, 'net_arrear'));
                $totalApproved = array_sum(array_map(fn($a) => $a['status'] == 'approved' ? $a['net_arrear'] : 0, $arrears));
                ?>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-warning bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Pending Arrears</div>
                            <div class="h4 mb-0"><?php echo count($pendingArrears); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-danger bg-opacity-25 rounded text-center">
                            <div class="text-muted small">Pending Amount</div>
                            <div class="h4 mb-0"><?php echo formatCurrency($totalPending); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Arrear Modal -->
<div class="modal fade" id="newArrearModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="calculate">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>Calculate Arrear</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Employee</label>
                            <select class="form-select" name="employee_id" required id="arrear_employee">
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $e): ?>
                                <option value="<?php echo $e['id']; ?>">
                                    <?php echo sanitize($e['employee_code'] . ' - ' . $e['full_name']); ?>
                                    (<?php echo sanitize($e['client_name'] ?? 'No Client'); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Arrear Type</label>
                            <select class="form-select" name="arrear_type" required id="arrear_type">
                                <option value="salary_revision">Salary Revision</option>
                                <option value="minimum_wage">Minimum Wage Revision</option>
                                <option value="correction">Correction</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">From Month</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select class="form-select" name="from_month" required>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select class="form-select" name="from_year" required>
                                        <?php for ($y = date('Y'); $y >= date('Y')-2; $y--): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">To Month</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select class="form-select" name="to_month" required>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select class="form-select" name="to_year" required>
                                        <?php for ($y = date('Y'); $y >= date('Y')-2; $y--): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Salary Revision Fields -->
                        <div class="col-12 salary-revision-fields">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                System will automatically calculate difference between previous and current salary structure.
                            </div>
                        </div>
                        
                        <!-- Minimum Wage Fields -->
                        <div class="col-md-6 minimum-wage-fields" style="display:none;">
                            <label class="form-label">Old Daily Wage</label>
                            <input type="number" class="form-control" name="old_wage" step="0.01">
                        </div>
                        <div class="col-md-6 minimum-wage-fields" style="display:none;">
                            <label class="form-label">New Daily Wage</label>
                            <input type="number" class="form-control" name="new_wage" step="0.01">
                        </div>
                        
                        <!-- Correction Fields -->
                        <div class="col-md-6 correction-fields" style="display:none;">
                            <label class="form-label">Arrear Amount</label>
                            <input type="number" class="form-control" name="arrear_amount" step="0.01">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Reason/Remarks</label>
                            <textarea class="form-control" name="reason" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-1"></i>Calculate Arrear
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Arrear Modal -->
<div class="modal fade" id="approveArrearModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="arrear_id" id="approve_arrear_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approve Arrear</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Net Arrear Amount</label>
                        <input type="text" class="form-control" id="approve_net_arrear" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Pay in Month</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <select class="form-select" name="payment_month" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
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
                        Arrear will be added to the selected month's payroll.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Approve & Add to Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Arrear Modal -->
<div class="modal fade" id="viewArrearModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Arrear Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewArrearContent">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#arrearTable').DataTable({
        responsive: true,
        order: [[0, 'asc']]
    });
    
    // Toggle fields based on arrear type
    $('#arrear_type').change(function() {
        var type = $(this).val();
        $('.salary-revision-fields, .minimum-wage-fields, .correction-fields').hide();
        
        if (type === 'salary_revision') {
            $('.salary-revision-fields').show();
        } else if (type === 'minimum_wage') {
            $('.minimum-wage-fields').show();
        } else if (type === 'correction') {
            $('.correction-fields').show();
        }
    });
});

function approveArrear(data) {
    $('#approve_arrear_id').val(data.id);
    $('#approve_net_arrear').val('₹' + parseFloat(data.net_arrear).toLocaleString('en-IN', {minimumFractionDigits: 2}));
    new bootstrap.Modal('#approveArrearModal').show();
}

function viewArrear(data) {
    var content = `
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr><th>Employee:</th><td>${data.full_name} (${data.employee_code})</td></tr>
                    <tr><th>Type:</th><td>${data.arrear_type}</td></tr>
                    <tr><th>Period:</th><td>${data.months_count} month(s)</td></tr>
                    <tr><th>Status:</th><td>${data.status}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr><th>Gross Arrear:</th><td class="text-end">₹${parseFloat(data.gross_arrear).toLocaleString('en-IN', {minimumFractionDigits: 2})}</td></tr>
                    <tr><th>PF Deduction:</th><td class="text-end text-danger">₹${parseFloat(data.pf_arrear).toLocaleString('en-IN', {minimumFractionDigits: 2})}</td></tr>
                    <tr><th>ESI Deduction:</th><td class="text-end text-danger">₹${parseFloat(data.esi_arrear).toLocaleString('en-IN', {minimumFractionDigits: 2})}</td></tr>
                    <tr class="table-primary"><th>Net Arrear:</th><td class="text-end"><strong>₹${parseFloat(data.net_arrear).toLocaleString('en-IN', {minimumFractionDigits: 2})}</strong></td></tr>
                </table>
            </div>
        </div>
        ${data.reason ? '<div class="mt-3"><strong>Reason:</strong><p class="text-muted">' + data.reason + '</p></div>' : ''}
    `;
    
    $('#viewArrearContent').html(content);
    new bootstrap.Modal('#viewArrearModal').show();
}
</script>
