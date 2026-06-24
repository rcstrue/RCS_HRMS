<?php
/**
 * RCS HRMS Pro - Print Payslip Page
 * Standalone page for printing individual payslips
 * Updated for new database schema
 */

// Only define constants if not already defined (in case included from index.php)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
if (!defined('RCS_HRMS')) {
    define('RCS_HRMS', true);
}

// Only load config if not already loaded
if (!class_exists('Database')) {
    require_once APP_ROOT . '/config/config.php';
    require_once APP_ROOT . '/includes/database.php';
}

// Initialize classes
$payrollObj = new Payroll();

$payrollId = $_GET['id'] ?? null;

if (!$payrollId) {
    die('Payslip ID required');
}

$data = $payrollObj->getPayslipById($payrollId);

if (!$data) {
    die('Payslip not found');
}

// Get period info
$period = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$period->execute([$data['payroll_period_id'] ?? 0]);
$periodData = $period->fetch(PDO::FETCH_ASSOC);

// Number to words function (Indian numbering)
function numberToWords($number) {
    if ($number == 0) return 'Zero Rupees Only';
    $no = floor($number);
    $point = round($number - $no, 2) * 100;
    $hundred = null;
    $digits_1 = strlen($no);
    $i = 0;
    $str = array();
    $words = array('0' => '', '1' => 'One', '2' => 'Two', '3' => 'Three', '4' => 'Four',
        '5' => 'Five', '6' => 'Six', '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve', '13' => 'Thirteen',
        '14' => 'Fourteen', '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen',
        '18' => 'Eighteen', '19' => 'Nineteen', '20' => 'Twenty', '30' => 'Thirty',
        '40' => 'Forty', '50' => 'Fifty', '60' => 'Sixty', '70' => 'Seventy',
        '80' => 'Eighty', '90' => 'Ninety');
    $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
    while ($i < $digits_1) {
        $divider = ($i == 2) ? 10 : 100;
        $number_word = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number_word) {
            $plural = (($counter = count($str)) && $number_word > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str[] = ($number_word < 21) ? $words[$number_word] . " " . $digits[$counter] . $plural . " " . $hundred
                : $words[floor($number_word / 10) * 10] . " " . $words[$number_word % 10] . " " . $digits[$counter] . $plural . " " . $hundred;
        } else {
            $str[] = null;
        }
    }
    $str = array_reverse($str);
    $result = implode('', $str);
    $points = ($point) ? " and " . $words[floor($point / 10) * 10] . " " . $words[$point % 10] . ' Paise' : '';
    return ucfirst(trim($result)) . $points . " Rupees Only";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?php echo sanitize($data['full_name'] ?? 'Employee'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            padding: 20px;
            background: #fff;
        }

        .payslip-container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #000;
        }

        .payslip-header {
            background: #2563eb;
            color: #fff;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-logo {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: #fff;
            padding: 4px;
            flex-shrink: 0;
            object-fit: contain;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
        }

        .company-address {
            font-size: 10px;
            opacity: 0.85;
        }

        .payslip-period {
            text-align: right;
        }

        .payslip-period .label {
            font-size: 10px;
            opacity: 0.85;
        }

        .payslip-period .value {
            font-size: 16px;
            font-weight: bold;
        }

        /* Employee name prominent */
        .employee-name-banner {
            padding: 12px 20px;
            background: #f0f7ff;
            border-bottom: 1px solid #ddd;
        }

        .employee-name-banner .emp-name {
            font-size: 18px;
            font-weight: bold;
            color: #1e293b;
        }

        .employee-name-banner .emp-name-mobile {
            font-size: 11px;
            color: #64748b;
            margin-left: 10px;
            font-weight: normal;
        }

        .employee-info {
            padding: 12px 20px;
            background: #f8f9fa;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            border-bottom: 1px solid #ddd;
        }

        .info-item .label {
            font-size: 10px;
            color: #666;
        }

        .info-item .value {
            font-weight: 500;
        }

        .payslip-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        .payslip-section {
            padding: 15px 20px;
        }

        .payslip-section:first-child {
            border-right: 1px solid #ddd;
        }

        .section-title {
            font-weight: bold;
            font-size: 11px;
            color: #333;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }

        .payslip-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }

        .payslip-row.total {
            border-top: 1px solid #000;
            margin-top: 10px;
            padding-top: 10px;
            font-weight: bold;
        }

        .payslip-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #ddd;
        }

        .net-pay-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .net-pay-label {
            font-size: 10px;
            color: #666;
        }

        .net-pay-value {
            font-size: 20px;
            font-weight: bold;
            color: #10b981;
        }

        .net-pay-words {
            font-size: 10px;
            color: #64748b;
            font-style: italic;
            margin-top: 4px;
        }

        .bank-details {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .bank-info-text .small {
            font-size: 10px;
            color: #64748b;
        }

        /* QR code positioned right side under bank details */
        .qr-code-wrapper {
            display: inline-block;
            padding: 4px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
        }

        .qr-code-wrapper img,
        .qr-code-wrapper canvas {
            display: block !important;
        }

        .footer-stamp {
            padding: 10px 20px;
            text-align: center;
            border-top: 1px solid #ddd;
        }

        .footer-stamp .stamp-text {
            font-size: 9px;
            color: #999;
            font-style: italic;
        }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print mb-3">
        <button onclick="window.print()" class="btn btn-primary">
            Print
        </button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>

    <div class="payslip-container">
        <!-- Header -->
        <div class="payslip-header">
            <div class="header-left">
                <img src="assets/images/logo.png" alt="RCS Logo" class="header-logo" onerror="this.style.display='none'">
                <div>
                    <div class="company-name">RCS TRUE FACILITIES PVT LTD</div>
                    <div class="company-address">110, Someswar Square, Vesu, Surat - 395007, Gujarat</div>
                    <div class="company-address">GST: 24AAICR1390M1Z3 | PAN: AAICR1390M</div>
                </div>
            </div>
            <div class="payslip-period">
                <div class="label">PAYSLIP FOR</div>
                <div class="value"><?php echo sanitize($periodData['period_name'] ?? date('F Y')); ?></div>
            </div>
        </div>

        <!-- Employee Name Banner -->
        <div class="employee-name-banner">
            <span class="emp-name"><?php echo strtoupper(sanitize($data['full_name'] ?? '-')); ?></span>
            <?php if (!empty($data['mobile_number'])): ?>
            <span class="emp-name-mobile">Mob: <?php echo sanitize($data['mobile_number']); ?></span>
            <?php endif; ?>
        </div>

        <!-- Employee Info -->
        <div class="employee-info">
            <div class="info-item">
                <div class="label">Employee Code</div>
                <div class="value"><?php echo sanitize($data['employee_code'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Department</div>
                <div class="value"><?php echo sanitize($data['department'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Designation</div>
                <div class="value"><?php echo sanitize($data['designation'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Client / Unit</div>
                <div class="value"><?php echo sanitize(($data['client_name'] ?? '-') . ' / ' . ($data['unit_name'] ?? '-')); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Paid Days</div>
                <div class="value"><?php echo $data['paid_days'] ?? 0; ?> / <?php echo $data['total_days'] ?? 30; ?></div>
            </div>
            <div class="info-item">
                <div class="label">UAN Number</div>
                <div class="value"><?php echo sanitize($data['uan_number'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">ESIC Number</div>
                <div class="value"><?php echo sanitize($data['esic_number'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Date of Joining</div>
                <div class="value"><?php echo !empty($data['date_of_joining']) ? date('d-m-Y', strtotime($data['date_of_joining'])) : '-'; ?></div>
            </div>
        </div>

        <!-- Body -->
        <div class="payslip-body">
            <!-- Earnings -->
            <div class="payslip-section">
                <div class="section-title">EARNINGS</div>
                <div class="payslip-row">
                    <span>Basic + DA</span>
                    <span><?php echo formatCurrency($data['basic_da'] ?? 0); ?></span>
                </div>
                <div class="payslip-row">
                    <span>House Rent Allowance</span>
                    <span><?php echo formatCurrency($data['hra'] ?? 0); ?></span>
                </div>
                <?php if (($data['washing_allowance'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Washing Allowance</span>
                    <span><?php echo formatCurrency($data['washing_allowance']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['leave_encashment'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Leave Encashment</span>
                    <span><?php echo formatCurrency($data['leave_encashment']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['bonus_encashment'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Bonus Encashment</span>
                    <span><?php echo formatCurrency($data['bonus_encashment']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['overtime_amount'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Overtime (<?php echo $data['overtime_hours'] ?? 0; ?> hrs)</span>
                    <span><?php echo formatCurrency($data['overtime_amount']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['extra_days_amount'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Extra Days Payment</span>
                    <span><?php echo formatCurrency($data['extra_days_amount']); ?></span>
                </div>
                <?php endif; ?>
                <div class="payslip-row total">
                    <span>GROSS EARNINGS</span>
                    <span><?php echo formatCurrency($data['gross_earnings'] ?? 0); ?></span>
                </div>
            </div>

            <!-- Deductions -->
            <div class="payslip-section">
                <div class="section-title">DEDUCTIONS</div>
                <?php if (($data['pf_employee'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Provident Fund</span>
                    <span><?php echo formatCurrency($data['pf_employee']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['esi_employee'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>ESI Contribution</span>
                    <span><?php echo formatCurrency($data['esi_employee']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['professional_tax'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Professional Tax</span>
                    <span><?php echo formatCurrency($data['professional_tax']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['lwf_employee'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Labour Welfare Fund</span>
                    <span><?php echo formatCurrency($data['lwf_employee']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['salary_advance'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Salary Advance</span>
                    <span><?php echo formatCurrency($data['salary_advance']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['loan_emi'] ?? 0) > 0): ?>
                <div class="payslip-row" style="color:#d32f2f;font-weight:bold;">
                    <span>Loan EMI Deduction</span>
                    <span><?php echo formatCurrency($data['loan_emi']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['office_deduction'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Office Deduction</span>
                    <span><?php echo formatCurrency($data['office_deduction']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (($data['trust_deduction'] ?? 0) > 0): ?>
                <div class="payslip-row">
                    <span>Trust Deduction</span>
                    <span><?php echo formatCurrency($data['trust_deduction']); ?></span>
                </div>
                <?php endif; ?>
                <div class="payslip-row total">
                    <span>TOTAL DEDUCTIONS</span>
                    <span><?php echo formatCurrency($data['total_deductions'] ?? 0); ?></span>
                </div>
            </div>
        </div>

        <!-- Footer: Net Pay + Bank + QR -->
        <div class="payslip-footer">
            <div>
                <div class="net-pay-label">NET PAY</div>
                <div class="net-pay-value"><?php echo formatCurrency($data['net_pay'] ?? 0); ?></div>
                <div class="net-pay-words"><?php echo numberToWords($data['net_pay'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Bank Details + QR Code -->
        <div class="bank-details" style="padding: 0 20px 15px;">
            <div class="bank-info-text">
                <div class="small">
                    Bank: <?php echo sanitize($data['bank_name'] ?? '-'); ?><br>
                    A/C: <?php echo sanitize($data['account_number'] ?? '-'); ?><br>
                    IFSC: <?php echo sanitize($data['ifsc_code'] ?? '-'); ?>
                </div>
            </div>
            <div style="text-align: right;">
                <div class="qr-code-wrapper" id="qrcode"></div>
                <div style="font-size:8px;color:#999;margin-top:3px;">Scan to verify</div>
            </div>
        </div>

        <!-- Footer Stamp -->
        <div class="footer-stamp">
            <div class="stamp-text">This is a computer generated payslip. Stamp / Sign not required.</div>
        </div>
    </div>

    <script>
        (function() {
            var qrData = 'RCS TRUE FACILITIES PVT LTD\nPayroll ID: <?php echo (int)$payrollId; ?>\nEmp: <?php echo sanitize($data["employee_code"] ?? "-"); ?>\nPeriod: <?php echo sanitize($periodData["period_name"] ?? ""); ?>\nNet Pay: Rs. <?php echo number_format($data["net_pay"] ?? 0, 2); ?>';
            var el = document.getElementById('qrcode');
            if (el) {
                // Use Google Charts QR API as fallback — always works, no JS library needed
                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' + encodeURIComponent(qrData);
                el.innerHTML = '<img src="' + qrUrl + '" width="80" height="80" alt="QR Code" style="display:block;">';
            }
        })();
    </script>
</body>
</html>