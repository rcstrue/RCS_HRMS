<?php
$pageTitle = 'ESI RCC Report';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthName = $monthNames[$month] ?? '';

$company = null;
$period = null;
$rccData = null;
$rows = [];

try {
    $company = $db->fetch("SELECT * FROM companies LIMIT 1");
} catch (Exception $e) {
    $company = null;
}

try {
    $period = $db->fetch("SELECT * FROM payroll_periods WHERE month = ? AND year = ?", [$month, $year]);
} catch (Exception $e) {
    $period = null;
}

$totalEE = 0;
$totalER = 0;
$totalWages = 0;
$empCount = 0;

if ($period) {
    try {
        $stats = $db->fetch("
            SELECT COUNT(DISTINCT p.employee_id) as emp_count,
                   COALESCE(SUM(p.gross_earnings), 0) as total_wages,
                   COALESCE(SUM(p.esi_employee), 0) as total_ee,
                   COALESCE(SUM(p.esi_employer), 0) as total_er
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            JOIN employee_salary_structures ess ON ess.employee_id = e.id
                AND ess.effective_from <= ? AND (ess.effective_to IS NULL OR ess.effective_to >= ?)
            WHERE p.payroll_period_id = ? AND e.status = 'active' AND ess.esi_applicable = 1
        ", [$period['start_date'], $period['end_date'], $period['id']]);

        $empCount = $stats['emp_count'];
        $totalWages = $stats['total_wages'];
        $totalEE = $stats['total_ee'];
        $totalER = $stats['total_er'];
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$totalContrib = $totalEE + $totalER;
$certificateNo = 'RCC/' . $year . '/' . str_pad($month, 2, '0', STR_PAD_LEFT) . '/001';
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .page-break { page-break-before: always; }
}
.certificate-border {
    border: 3px double #1a1a2e;
    padding: 30px;
    margin: 20px 0;
    position: relative;
}
.certificate-border::before {
    content: '';
    position: absolute;
    inset: 8px;
    border: 1px solid #1a1a2e;
    pointer-events: none;
}
.certificate-title {
    text-align: center;
    font-size: 22px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 3px;
    margin-bottom: 5px;
}
.certificate-subtitle {
    text-align: center;
    font-size: 14px;
    color: #555;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/esi/rcc-report">
        <div class="col-auto">
            <label class="form-label">Month</label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?= $i ?>" <?= $i === $month ? 'selected' : '' ?>><?= $monthNames[$i] ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2020" max="2030">
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <!-- Certificate Format -->
    <div class="certificate-border">
        <div class="certificate-title">Employees' State Insurance Corporation</div>
        <div class="certificate-subtitle">Revenue Contribution Certificate (RCC)</div>

        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td width="40%"><strong>Certificate No:</strong></td><td><?= sanitize($certificateNo) ?></td></tr>
                    <tr><td><strong>Period:</strong></td><td><?= sanitize($monthName) . ' ' . $year ?></td></tr>
                    <tr><td><strong>Employer Name:</strong></td><td><?= $company ? sanitize($company['company_name']) : 'N/A' ?></td></tr>
                    <tr><td><strong>ESI Code No:</strong></td><td><?= $company ? sanitize($company['esi_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td width="40%"><strong>Address:</strong></td><td><?= $company ? sanitize($company['address'] ?? '') : '' ?></td></tr>
                    <tr><td><strong>PAN:</strong></td><td><?= $company ? sanitize($company['pan_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                    <tr><td><strong>Date of Issue:</strong></td><td><?= date('d-m-Y') ?></td></tr>
                </table>
            </div>
        </div>

        <hr>

        <p class="mb-3">This is to certify that the following contributions under the ESI Act, 1948 have been received from <strong><?= $company ? sanitize($company['company_name']) : 'N/A' ?></strong> for the contribution period of <strong><?= sanitize($monthName) . ' ' . $year ?></strong>.</p>

        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th width="5%">#</th>
                        <th>Description</th>
                        <th class="text-end" width="20%">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>Number of Insured Persons (IPs)</td>
                        <td class="text-end fw-bold"><?= $empCount ?></td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>Total Wages Paid (for ESI computation)</td>
                        <td class="text-end"><?= formatCurrency($totalWages) ?></td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>Employee's Share (0.75% of wages)</td>
                        <td class="text-end"><?= formatCurrency($totalEE) ?></td>
                    </tr>
                    <tr>
                        <td>4</td>
                        <td>Employer's Share (3.25% of wages)</td>
                        <td class="text-end"><?= formatCurrency($totalER) ?></td>
                    </tr>
                    <tr class="table-primary">
                        <td colspan="2" class="text-end fw-bold">Total Contribution (A)</td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalContrib) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="table-responsive mt-4">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Challan No</th>
                        <th>Bank Name</th>
                        <th>Branch</th>
                        <th>Payment Date</th>
                        <th class="text-end">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5" class="text-center text-muted">To be filled from challan records</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="row mt-5">
            <div class="col-md-4">
                <p class="mb-0"><strong>Prepared By:</strong></p>
                <div class="border-bottom mt-4 mb-1"></div>
                <p class="small text-muted">Name & Designation</p>
            </div>
            <div class="col-md-4">
                <p class="mb-0"><strong>Checked By:</strong></p>
                <div class="border-bottom mt-4 mb-1"></div>
                <p class="small text-muted">Name & Designation</p>
            </div>
            <div class="col-md-4">
                <p class="mb-0"><strong>Authorized Signatory:</strong></p>
                <div class="border-bottom mt-4 mb-1"></div>
                <p class="small text-muted">Name & Designation</p>
            </div>
        </div>
    </div>

    <!-- Amount in Words -->
    <div class="alert alert-info mt-3">
        <strong>Total Amount in Words:</strong> Rupees <?= numberToWords($totalContrib) ?> Only
    </div>
</div>

<?php
// Helper function for amount in words
function numberToWords($num) {
    if ($num == 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $num = round($num, 2);
    $parts = explode('.', (string)$num);
    $intPart = (int)$parts[0];
    $decPart = isset($parts[1]) ? (int)substr($parts[1] . '00', 0, 2) : 0;

    $words = '';

    if ($intPart >= 10000000) {
        $crores = (int)($intPart / 10000000);
        $words .= convertTwoDigit($crores, $ones, $tens) . ' Crore ';
        $intPart %= 10000000;
    }
    if ($intPart >= 100000) {
        $lakhs = (int)($intPart / 100000);
        $words .= convertTwoDigit($lakhs, $ones, $tens) . ' Lakh ';
        $intPart %= 100000;
    }
    if ($intPart >= 1000) {
        $thousands = (int)($intPart / 1000);
        $words .= convertTwoDigit($thousands, $ones, $tens) . ' Thousand ';
        $intPart %= 1000;
    }
    if ($intPart >= 100) {
        $hundreds = (int)($intPart / 100);
        $words .= convertTwoDigit($hundreds, $ones, $tens) . ' Hundred ';
        $intPart %= 100;
    }
    if ($intPart > 0) {
        $words .= convertTwoDigit($intPart, $ones, $tens) . ' ';
    }

    if ($decPart > 0) {
        $words .= 'and ' . convertTwoDigit($decPart, $ones, $tens) . ' Paise';
    }

    return trim($words);
}

function convertTwoDigit($n, $ones, $tens) {
    if ($n < 20) return $ones[$n];
    return $tens[(int)($n / 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
}
?>
