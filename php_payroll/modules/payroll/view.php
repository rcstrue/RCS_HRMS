<?php
/**
 * RCS HRMS Pro - View Payroll
 * Version: 2.2.0 - Enhanced with detail drill-down
 * 
 * IMPORTANT NOTES:
 * - AADHAAR NUMBER SHOULD NEVER BE HIDDEN IN INTERNAL VIEWS
 * - Use client_id and unit_id for filtering
 * - clients table uses 'name' column
 */

$pageTitle = 'Payroll';

// Handle AJAX detail request
if (isset($_GET['action']) && $_GET['action'] === 'detail') {
    header('Content-Type: text/html');
    $periodId = (int)($_GET['period_id'] ?? 0);
    $employeeCode = sanitize($_GET['employee_id'] ?? '');
    
    if ($periodId && $employeeCode) {
        $detail = $payroll->getPayrollDetail($periodId, $employeeCode);
        if ($detail):
?>
<div class="row">
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Employee Information</h6>
        <table class="table table-sm table-borderless">
            <tr><td class="text-muted" style="width: 120px;">Employee:</td>
                <td><strong><?php echo sanitize($detail['full_name']); ?></strong></td></tr>
            <tr><td class="text-muted">Code:</td>
                <td><code><?php echo sanitize($detail['employee_code']); ?></code></td></tr>
            <tr><td class="text-muted">Designation:</td>
                <td><?php echo sanitize($detail['designation'] ?? '-'); ?></td></tr>
            <tr><td class="text-muted">Client:</td>
                <td><?php echo sanitize($detail['client_name'] ?? '-'); ?></td></tr>
            <tr><td class="text-muted">Unit:</td>
                <td><?php echo sanitize($detail['unit_name'] ?? '-'); ?></td></tr>
            <tr><td class="text-muted">DOJ:</td>
                <td><?php echo formatDate($detail['date_of_joining']); ?></td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Attendance Summary</h6>
        <table class="table table-sm table-borderless">
            <tr><td class="text-muted" style="width: 120px;">Total Days:</td>
                <td><?php echo $detail['total_days'] ?? 30; ?></td></tr>
            <tr><td class="text-muted">Paid Days:</td>
                <td><strong class="text-success"><?php echo $detail['paid_days'] ?? 0; ?></strong></td></tr>
            <tr><td class="text-muted">Unpaid Days:</td>
                <td class="text-danger"><?php echo $detail['unpaid_days'] ?? 0; ?></td></tr>
            <?php if (($detail['overtime_hours'] ?? 0) > 0): ?>
            <tr><td class="text-muted">Overtime:</td>
                <td><?php echo $detail['overtime_hours']; ?> hrs</td></tr>
            <?php endif; ?>
        </table>
        
        <h6 class="text-muted mb-2 mt-3">Bank Details</h6>
        <table class="table table-sm table-borderless">
            <tr><td class="text-muted" style="width: 120px;">Bank:</td>
                <td><?php echo sanitize($detail['bank_name'] ?? '-'); ?></td></tr>
            <tr><td class="text-muted">A/C No:</td>
                <td><?php echo sanitize($detail['account_number'] ?? '-'); ?></td></tr>
            <tr><td class="text-muted">IFSC:</td>
                <td><?php echo sanitize($detail['ifsc_code'] ?? '-'); ?></td></tr>
        </table>
    </div>
</div>

<hr>

<div class="row">
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Earnings</h6>
        <table class="table table-sm">
            <tr><td>Basic</td><td class="text-end"><?php echo formatCurrency($detail['basic'] ?? 0); ?></td></tr>
            <tr><td>DA</td><td class="text-end"><?php echo formatCurrency($detail['da'] ?? 0); ?></td></tr>
            <tr><td>HRA</td><td class="text-end"><?php echo formatCurrency($detail['hra'] ?? 0); ?></td></tr>
            <tr><td>Washing Allowance</td><td class="text-end"><?php echo formatCurrency($detail['washing_allowance'] ?? 0); ?></td></tr>
            <tr><td>Leave Encashment</td><td class="text-end"><?php echo formatCurrency($detail['leave_encashment'] ?? 0); ?></td></tr>
            <tr><td>Bonus Encashment</td><td class="text-end"><?php echo formatCurrency($detail['bonus_encashment'] ?? 0); ?></td></tr>
            <?php if (($detail['overtime_amount'] ?? 0) > 0): ?>
            <tr><td>Overtime</td><td class="text-end"><?php echo formatCurrency($detail['overtime_amount']); ?></td></tr>
            <?php endif; ?>
            <tr class="table-success">
                <td><strong>Gross Earnings</strong></td>
                <td class="text-end"><strong><?php echo formatCurrency($detail['gross_earnings'] ?? 0); ?></strong></td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Deductions</h6>
        <table class="table table-sm">
            <tr><td>PF (Employee)</td><td class="text-end"><?php echo formatCurrency($detail['pf_employee'] ?? 0); ?></td></tr>
            <tr><td>ESI (Employee)</td><td class="text-end"><?php echo formatCurrency($detail['esi_employee'] ?? 0); ?></td></tr>
            <tr><td>Professional Tax</td><td class="text-end"><?php echo formatCurrency($detail['professional_tax'] ?? 0); ?></td></tr>
            <tr><td>LWF (Employee)</td><td class="text-end"><?php echo formatCurrency($detail['lwf_employee'] ?? 0); ?></td></tr>
            <tr><td>Salary Advance</td><td class="text-end"><?php echo formatCurrency($detail['salary_advance'] ?? 0); ?></td></tr>
            <?php if (($detail['loan_emi'] ?? 0) > 0): ?>
            <tr><td class="text-danger"><i class="bi bi-bank me-1"></i>Loan EMI Deduction</td><td class="text-end text-danger fw-bold"><?php echo formatCurrency($detail['loan_emi']); ?></td></tr>
            <?php endif; ?>
            <tr><td>Other Deductions</td><td class="text-end"><?php echo formatCurrency($detail['other_deduction'] ?? 0); ?></td></tr>
            <tr class="table-danger">
                <td><strong>Total Deductions</strong></td>
                <td class="text-end"><strong><?php echo formatCurrency($detail['total_deductions'] ?? 0); ?></strong></td>
            </tr>
        </table>
    </div>
</div>

<hr>

<div class="row">
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Employer Contributions</h6>
        <table class="table table-sm">
            <tr><td>PF (Employer)</td><td class="text-end"><?php echo formatCurrency($detail['pf_employer'] ?? 0); ?></td></tr>
            <tr><td>EPS (Employer)</td><td class="text-end"><?php echo formatCurrency($detail['eps_employer'] ?? 0); ?></td></tr>
            <tr><td>EDLIS</td><td class="text-end"><?php echo formatCurrency($detail['edlis_employer'] ?? 0); ?></td></tr>
            <tr><td>EPF Admin</td><td class="text-end"><?php echo formatCurrency($detail['epf_admin_charges'] ?? 0); ?></td></tr>
            <tr><td>ESI (Employer)</td><td class="text-end"><?php echo formatCurrency($detail['esi_employer'] ?? 0); ?></td></tr>
            <tr><td>Bonus Provision</td><td class="text-end"><?php echo formatCurrency($detail['bonus_provision'] ?? 0); ?></td></tr>
            <tr><td>Gratuity Provision</td><td class="text-end"><?php echo formatCurrency($detail['gratuity_provision'] ?? 0); ?></td></tr>
            <tr class="table-info">
                <td><strong>Total Employer Contribution</strong></td>
                <td class="text-end"><strong><?php echo formatCurrency($detail['total_employer_contribution'] ?? 0); ?></strong></td>
            </tr>
        </table>
    </div>
    <div class="col-md-6">
        <div class="card bg-success bg-opacity-10">
            <div class="card-body text-center py-4">
                <div class="text-muted">NET PAY</div>
                <h2 class="text-success mb-0"><?php echo formatCurrency($detail['net_pay'] ?? 0); ?></h2>
                <div class="mt-2">
                    <small class="text-muted">CTC: <?php echo formatCurrency($detail['ctc'] ?? 0); ?></small>
                </div>
            </div>
        </div>
        
        <div class="mt-3">
            <div class="d-flex justify-content-between">
                <span>Status:</span>
                <span class="badge bg-<?php 
                    echo $detail['status'] === 'Paid' ? 'success' : 
                        ($detail['status'] === 'Approved' ? 'primary' : 
                        ($detail['salary_hold'] ? 'warning' : 'secondary')); 
                ?>"><?php 
                    echo $detail['salary_hold'] ? 'Hold' : sanitize($detail['status']); 
                ?></span>
            </div>
            <?php if ($detail['salary_hold'] && $detail['hold_reason']): ?>
            <div class="text-warning small mt-1">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?php echo sanitize($detail['hold_reason']); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
        else:
            echo '<div class="alert alert-warning">Payroll details not found.</div>';
        endif;
    } else {
        echo '<div class="alert alert-danger">Invalid request.</div>';
    }
    exit;
}

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientFilter = isset($_GET['client']) ? sanitize($_GET['client']) : '';
$unitFilter = isset($_GET['unit']) ? sanitize($_GET['unit']) : '';
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : '';

// Get clients for filter
$clients = $db->query("SELECT DISTINCT c.name as client_name FROM employees e LEFT JOIN clients c ON e.client_id = c.id WHERE c.name IS NOT NULL AND c.name != '' ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$where = "pp.month = :month AND pp.year = :year";
$params = [':month' => $month, ':year' => $year];

if ($clientFilter) {
    $where .= " AND c.name = :client";
    $params[':client'] = $clientFilter;
}

if ($unitFilter) {
    $where .= " AND u.name = :unit";
    $params[':unit'] = $unitFilter;
}

if ($statusFilter) {
    $where .= " AND p.status = :status";
    $params[':status'] = $statusFilter;
}

// Get payroll records
$sql = "SELECT p.*, e.full_name, e.employee_code, e.designation,
        c.name as client_name,
        u.name as unit_name,
        pp.period_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        LEFT JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        WHERE {$where}
        ORDER BY client_name, unit_name, e.full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payrollData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'gross' => 0,
    'deductions' => 0,
    'net' => 0,
    'pf' => 0,
    'esi' => 0,
    'pt' => 0,
    'count' => count($payrollData)
];

foreach ($payrollData as $row) {
    $totals['gross'] += $row['gross_earnings'] ?? 0;
    $totals['deductions'] += $row['total_deductions'] ?? 0;
    $totals['net'] += $row['net_pay'] ?? 0;
    $totals['pf'] += ($row['pf_employee'] ?? 0) + ($row['pf_employer'] ?? 0);
    $totals['esi'] += ($row['esi_employee'] ?? 0) + ($row['esi_employer'] ?? 0);
    $totals['pt'] += $row['professional_tax'] ?? 0;
}

// Get units for filter
$units = [];
if ($clientFilter) {
    $stmt = $db->prepare("SELECT DISTINCT u.name as unit_name FROM employees e LEFT JOIN units u ON e.unit_id = u.id LEFT JOIN clients c ON e.client_id = c.id WHERE c.name = ? AND u.name IS NOT NULL ORDER BY unit_name");
    $stmt->execute([$clientFilter]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-cash-stack me-2"></i>Payroll Records</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-2 mb-4">
                    <input type="hidden" name="page" value="payroll/view">
                    
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select class="form-select form-select-sm" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select class="form-select form-select-sm" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Client</label>
                        <select class="form-select form-select-sm" name="client" id="clientFilter">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo sanitize($c['client_name']); ?>" <?php echo $clientFilter == $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select class="form-select form-select-sm" name="unit" id="unitFilter">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo sanitize($u['unit_name']); ?>" <?php echo $unitFilter == $u['unit_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['unit_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="">All Status</option>
                            <option value="Draft" <?php echo $statusFilter == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="Processed" <?php echo $statusFilter == 'Processed' ? 'selected' : ''; ?>>Processed</option>
                            <option value="Approved" <?php echo $statusFilter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Paid" <?php echo $statusFilter == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm me-1">
                            <i class="bi bi-search"></i>
                        </button>
                        <a href="index.php?page=payroll/view" class="btn btn-secondary btn-sm">Clear</a>
                    </div>
                </form>
                
                <!-- Summary Cards -->
                <div class="row g-2 mb-4">
                    <div class="col-md-2">
                        <div class="border rounded p-2 text-center">
                            <small class="text-muted">Employees</small>
                            <h5 class="mb-0"><?php echo number_format($totals['count']); ?></h5>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="border rounded p-2 text-center bg-success bg-opacity-10">
                            <small class="text-success">Gross</small>
                            <h6 class="mb-0 text-success"><?php echo formatCurrency($totals['gross']); ?></h6>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="border rounded p-2 text-center bg-danger bg-opacity-10">
                            <small class="text-danger">Deductions</small>
                            <h6 class="mb-0 text-danger"><?php echo formatCurrency($totals['deductions']); ?></h6>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="border rounded p-2 text-center bg-primary bg-opacity-10">
                            <small class="text-primary">Net Pay</small>
                            <h6 class="mb-0 text-primary"><?php echo formatCurrency($totals['net']); ?></h6>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="border rounded p-2 text-center">
                            <small class="text-muted">PF (E+E)</small>
                            <h6 class="mb-0"><?php echo formatCurrency($totals['pf']); ?></h6>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="border rounded p-2 text-center">
                            <small class="text-muted">ESI (E+E)</small>
                            <h6 class="mb-0"><?php echo formatCurrency($totals['esi']); ?></h6>
                        </div>
                    </div>
                </div>
                
                <!-- Payroll Table -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm" id="payrollTable">
                        <thead class="table-light">
                            <tr>
                                <th>Emp Code</th>
                                <th>Employee</th>
                                <th>Client/Unit</th>
                                <th class="text-center">Paid Days</th>
                                <th class="text-end">Basic</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">PF</th>
                                <th class="text-end">ESI</th>
                                <th class="text-end">PT</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net Pay</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($payrollData)): ?>
                                <?php foreach ($payrollData as $p): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo sanitize($p['employee_code']); ?></span></td>
                                    <td>
                                        <?php echo sanitize($p['full_name']); ?>
                                        <div class="small text-muted"><?php echo sanitize($p['designation']); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo sanitize($p['client_name'] ?? '-'); ?></div>
                                        <div class="small text-muted"><?php echo sanitize($p['unit_name'] ?? '-'); ?></div>
                                    </td>
                                    <td class="text-center"><?php echo $p['paid_days'] ?? 0; ?></td>
                                    <td class="text-end"><?php echo formatCurrency($p['basic'] ?? 0); ?></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($p['gross_earnings'] ?? 0); ?></strong></td>
                                    <td class="text-end"><?php echo formatCurrency($p['pf_employee'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($p['esi_employee'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($p['professional_tax'] ?? 0); ?></td>
                                    <td class="text-end text-danger"><?php echo formatCurrency($p['total_deductions'] ?? 0); ?></td>
                                    <td class="text-end text-success"><strong><?php echo formatCurrency($p['net_pay'] ?? 0); ?></strong></td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'Draft' => 'secondary',
                                            'Processed' => 'info',
                                            'Approved' => 'primary',
                                            'Paid' => 'success',
                                            'Hold' => 'warning'
                                        ];
                                        ?>
                                        <span class="badge bg-<?php echo $statusColors[$p['status']] ?? 'secondary'; ?>">
                                            <?php echo sanitize($p['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="index.php?page=payroll/print_payslip&id=<?php echo $p['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank" title="Print Payslip">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Totals Row -->
                                <tr class="table-dark">
                                    <td colspan="3"><strong>TOTAL</strong></td>
                                    <td class="text-center"><strong><?php echo $totals['count']; ?></strong></td>
                                    <td class="text-end">-</td>
                                    <td class="text-end"><strong><?php echo formatCurrency($totals['gross']); ?></strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($totals['pf']); ?></strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($totals['esi']); ?></strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($totals['pt']); ?></strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($totals['deductions']); ?></strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($totals['net']); ?></strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-4">
                                        No payroll records found for the selected period.
                                        <br><a href="index.php?page=payroll/process">Process payroll</a> first.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#payrollTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[2, 'asc']],
        searching: false
    });
});

// Load units when client changes
$('#clientFilter').change(function() {
    var client = $(this).val();
    if (client) {
        $.get('index.php?page=api/units', {client_name: client}, function(data) {
            var options = '<option value="">All Units</option>';
            data.forEach(function(u) {
                options += '<option value="' + (u.unit_name || u.name) + '">' + (u.unit_name || u.name) + '</option>';
            });
            $('#unitFilter').html(options);
        }, 'json');
    } else {
        $('#unitFilter').html('<option value="">All Units</option>');
    }
});
</script>
