<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal - Payslip View
 */

session_start();

if (!isset($_SESSION['employee_portal']) || !$_SESSION['employee_portal']['logged_in']) {
    header('Location: index.php?page=portal/login');
    exit;
}

$pageTitle = 'View Payslip';
$page = 'portal/payslips';

require_once '../../config/config.php';
require_once '../../includes/database.php';

$db = Database::getInstance();
$employeeId = $_SESSION['employee_portal']['employee_id'];
$periodId = intval($_GET['period'] ?? 0);

if (!$periodId) {
    setFlash('error', 'Invalid payslip period');
    header('Location: index.php?page=portal/payslips');
    exit;
}

// Get payslip details
$payslip = $db->fetch(
    "SELECT p.*, pp.period_name, pp.month, pp.year, pp.start_date, pp.end_date,
            e.employee_code, e.full_name, e.father_name, e.designation, e.department,
            e.date_of_joining, e.uan_number, e.esi_number, e.pan_number,
            e.bank_name, e.bank_account_number, e.bank_ifsc_code,
            c.name as client_name,
            u.name as unit_name
     FROM payroll p
     JOIN payroll_periods pp ON p.payroll_period_id = pp.id
     JOIN employees e ON p.employee_id = e.id
     LEFT JOIN clients c ON p.client_id = c.id
     LEFT JOIN units u ON p.unit_id = u.id
     WHERE p.payroll_period_id = :period_id AND p.employee_id = :emp_id",
    ['period_id' => $periodId, 'emp_id' => $employeeId]
);

if (!$payslip) {
    setFlash('error', 'Payslip not found');
    header('Location: index.php?page=portal/payslips');
    exit;
}

// Calculate totals
$earnings = [
    'Basic' => $payslip['basic'],
    'Dearness Allowance' => $payslip['da'],
    'HRA' => $payslip['hra'],
    'Leave Encashment' => $payslip['leave_encashment'] ?? 0,
    'Bonus Encashment' => $payslip['bonus_encashment'] ?? 0,
    'Washing Allowance' => $payslip['washing_allowance'] ?? 0,
    'Overtime' => $payslip['overtime_amount'],
    'Bonus' => $payslip['bonus'],
    'Incentive' => $payslip['incentive'],
    'Arrears' => $payslip['arrears']
];

$deductions = [
    'PF (Employee)' => $payslip['pf_employee'],
    'ESI (Employee)' => $payslip['esi_employee'],
    'Professional Tax' => $payslip['pt_employee'],
    'LWF (Employee)' => $payslip['lwf_employee'],
    'TDS' => $payslip['tds'],
    'Advance Recovery' => $payslip['advance_deduction'],
    'Other Deductions' => $payslip['other_deductions']
];

// Filter out zero values
$earnings = array_filter($earnings, fn($v) => $v > 0);
$deductions = array_filter($deductions, fn($v) => $v > 0);

$totalEarnings = array_sum($earnings);
$totalDeductions = array_sum($deductions);

include '../../templates/header.php';
?>

<style>
.payslip-container {
    max-width: 900px;
    margin: 0 auto;
}
.payslip {
    background: white;
    border: 2px solid #333;
    padding: 0;
}
.payslip-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.payslip-header img {
    height: 60px;
    background: white;
    padding: 5px;
    border-radius: 5px;
}
.payslip-body {
    padding: 20px;
}
.section-title {
    background: #f8f9fa;
    padding: 8px 15px;
    font-weight: 600;
    border-left: 4px solid #667eea;
    margin-bottom: 15px;
}
.amount-cell {
    text-align: right;
    font-family: 'Courier New', monospace;
}
.total-row {
    background: #f8f9fa;
    font-weight: 700;
}
.total-row td {
    border-top: 2px solid #333 !important;
}
</style>

<div class="payslip-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=portal/payslips" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Payslips
        </a>
        <div>
            <a href="index.php?page=portal/payslip_print&period=<?php echo $periodId; ?>" 
               class="btn btn-primary" target="_blank">
                <i class="bi bi-printer me-1"></i>Print Payslip
            </a>
        </div>
    </div>
    
    <div class="payslip">
        <!-- Header -->
        <div class="payslip-header">
            <div>
                <h4 class="mb-0">PAYSLIP</h4>
                <small>Salary for <?php echo date('F Y', mktime(0,0,0,$payslip['month'],1,$payslip['year'])); ?></small>
            </div>
            <img src="assets/images/logo.png" alt="Company Logo" onerror="this.style.display='none'">
        </div>
        
        <div class="payslip-body">
            <!-- Employee Details -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="text-muted" width="35%">Employee Code</td>
                            <td><strong><?php echo sanitize($payslip['employee_code']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Employee Name</td>
                            <td><strong><?php echo sanitize($payslip['full_name']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Father's Name</td>
                            <td><?php echo sanitize($payslip['father_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Designation</td>
                            <td><?php echo sanitize($payslip['designation'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Department</td>
                            <td><?php echo sanitize($payslip['department'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="text-muted" width="35%">Client</td>
                            <td><?php echo sanitize($payslip['client_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Location/Unit</td>
                            <td><?php echo sanitize($payslip['unit_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Date of Joining</td>
                            <td><?php echo formatDate($payslip['date_of_joining']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">UAN</td>
                            <td><?php echo sanitize($payslip['uan_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">ESI No</td>
                            <td><?php echo sanitize($payslip['esi_number'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Attendance Summary -->
            <div class="section-title">Attendance Summary</div>
            <div class="row mb-4">
                <div class="col-md-12">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr class="text-center">
                                <th>Working Days</th>
                                <th>Paid Days</th>
                                <th>Absent</th>
                                <th>Weekly Off</th>
                                <th>Holidays</th>
                                <th>Leaves</th>
                                <th>OT Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-center">
                                <td><?php echo number_format($payslip['total_working_days'], 0); ?></td>
                                <td><strong><?php echo number_format($payslip['paid_days'], 1); ?></strong></td>
                                <td><?php echo number_format($payslip['absent_days'], 0); ?></td>
                                <td><?php echo number_format($payslip['weekly_offs'], 0); ?></td>
                                <td><?php echo number_format($payslip['holidays'], 0); ?></td>
                                <td><?php echo number_format($payslip['paid_leaves'] + $payslip['unpaid_leaves'], 0); ?></td>
                                <td><?php echo number_format($payslip['overtime_hours'], 1); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Earnings & Deductions -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="section-title text-success"><i class="bi bi-plus-circle me-2"></i>Earnings</div>
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Description</th>
                                <th class="amount-cell">Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($earnings as $name => $amount): ?>
                            <tr>
                                <td><?php echo $name; ?></td>
                                <td class="amount-cell"><?php echo number_format($amount, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total Earnings</strong></td>
                                <td class="amount-cell"><strong><?php echo number_format($totalEarnings, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <div class="section-title text-danger"><i class="bi bi-dash-circle me-2"></i>Deductions</div>
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Description</th>
                                <th class="amount-cell">Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deductions as $name => $amount): ?>
                            <tr>
                                <td><?php echo $name; ?></td>
                                <td class="amount-cell"><?php echo number_format($amount, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($deductions)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted">No Deductions</td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td><strong>Total Deductions</strong></td>
                                <td class="amount-cell"><strong><?php echo number_format($totalDeductions, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Net Pay -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card bg-primary text-white">
                        <div class="card-body py-3">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <h5 class="mb-0">NET PAY</h5>
                                </div>
                                <div class="col-md-4 text-center">
                                    <small>
                                        <?php 
                                        $number = $payslip['net_salary'];
                                        $no = floor($number);
                                        $point = round($number - $no, 2) * 100;
                                        $hundred = null;
                                        $digits_1 = strlen($no);
                                        $i = 0;
                                        $str = array();
                                        $words = array('0' => '', '1' => 'One', '2' => 'Two',
                                        '3' => 'Three', '4' => 'Four', '5' => 'Five', '6' => 'Six',
                                        '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
                                        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve',
                                        '13' => 'Thirteen', '14' => 'Fourteen', '15' => 'Fifteen',
                                        '16' => 'Sixteen', '17' => 'Seventeen', '18' => 'Eighteen',
                                        '19' => 'Nineteen', '20' => 'Twenty', '30' => 'Thirty',
                                        '40' => 'Forty', '50' => 'Fifty', '60' => 'Sixty',
                                        '70' => 'Seventy', '80' => 'Eighty', '90' => 'Ninety');
                                        $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
                                        while ($i < $digits_1) {
                                            $divider = ($i == 2) ? 10 : 100;
                                            $number = floor($no % $divider);
                                            $no = floor($no / $divider);
                                            $i += ($divider == 10) ? 1 : 2;
                                            if ($number) {
                                                $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
                                                $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                                                $str [] = ($number < 21) ? $words[$number] .
                                                    " " . $digits[$counter] . $plural . " " . $hundred
                                                    : $words[floor($number / 10) * 10]
                                                    . " " . $words[$number % 10] . " "
                                                    . $digits[$counter] . $plural . " " . $hundred;
                                            } else {
                                            $str[] = null;
                                        }
                                        }
                                        $str = array_reverse($str);
                                        $result = implode('', $str);
                                        $points = ($point) ? " and " . $words[$point / 10] . " " . $words[$point = $point % 10] . ' Paise' : '';
                                        echo ucfirst($result) . $points . " Rupees Only";
                                        ?>
                                    </small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <h3 class="mb-0">₹ <?php echo number_format($payslip['net_salary'], 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Bank Details -->
            <div class="section-title">Payment Details</div>
            <div class="row mb-4">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="text-muted" width="35%">Bank Name</td>
                            <td><?php echo sanitize($payslip['bank_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Account Number</td>
                            <td><?php echo sanitize($payslip['bank_account_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">IFSC Code</td>
                            <td><?php echo sanitize($payslip['bank_ifsc_code'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="text-muted" width="35%">Payment Status</td>
                            <td>
                                <span class="badge bg-<?php echo $payslip['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($payslip['payment_status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php if ($payslip['payment_date']): ?>
                        <tr>
                            <td class="text-muted">Payment Date</td>
                            <td><?php echo formatDate($payslip['payment_date']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($payslip['payment_reference']): ?>
                        <tr>
                            <td class="text-muted">Reference</td>
                            <td><?php echo sanitize($payslip['payment_reference']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="row mt-4 pt-3 border-top">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        This is a computer-generated payslip and does not require signature.
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        Generated on: <?php echo date('d/m/Y H:i'); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../templates/footer.php'; ?>
