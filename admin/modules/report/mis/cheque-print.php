<?php
$pageTitle = 'Cheque Print';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthName = $monthNames[$month] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? 0);
$bankFilter = sanitize($_GET['bank'] ?? '');

$clients = [];
$units = [];
$banks = [];
try {
    $clients = $db->fetchAll("SELECT id, name FROM clients ORDER BY name");
} catch (Exception $e) {
    $clients = [];
}
if ($clientId > 0) {
    try {
        $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? ORDER BY name", [$clientId]);
    } catch (Exception $e) {
        $units = [];
    }
}

$period = null;
$allRows = [];
$bankGroups = [];

try {
    $period = $db->fetch("SELECT * FROM payroll_periods WHERE month = ? AND year = ?", [$month, $year]);
} catch (Exception $e) {
    $period = null;
}

if ($period) {
    $params = [$period['id']];
    $where = ["e.status = 'active'"];

    if ($clientId > 0) {
        $where[] = "e.client_id = ?";
        $params[] = $clientId;
    }
    if ($unitId > 0) {
        $where[] = "e.unit_id = ?";
        $params[] = $unitId;
    }
    if ($bankFilter) {
        $where[] = "e.bank_name = ?";
        $params[] = $bankFilter;
    }

    $whereClause = implode(' AND ', $where);

    try {
        $allRows = $db->fetchAll("
            SELECT e.employee_code, e.full_name, e.bank_name, e.account_number, e.ifsc_code,
                   p.net_pay
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            WHERE p.payroll_period_id = ? AND {$whereClause}
            ORDER BY e.bank_name, e.employee_code
        ", $params);

        // Group by bank
        foreach ($allRows as $row) {
            $bank = $row['bank_name'] ?: 'Unknown Bank';
            if (!isset($bankGroups[$bank])) {
                $bankGroups[$bank] = [];
            }
            $bankGroups[$bank][] = $row;
        }

        // Get unique banks for filter
        $banks = array_unique(array_column($allRows, 'bank_name'));
        sort($banks);
    } catch (Exception $e) {
        $error = $e->getMessage();
        $allRows = [];
        $bankGroups = [];
    }
}

$grandTotal = array_sum(array_map(fn($r) => $r['net_pay'], $allRows));

// Amount to words function (Indian numbering)
function amountToWordsIndian($num) {
    if ($num == 0) return 'Zero Rupees Only';
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
        $words .= _convert2d($crores, $ones, $tens) . ' Crore ';
        $intPart %= 10000000;
    }
    if ($intPart >= 100000) {
        $lakhs = (int)($intPart / 100000);
        $words .= _convert2d($lakhs, $ones, $tens) . ' Lakh ';
        $intPart %= 100000;
    }
    if ($intPart >= 1000) {
        $thousands = (int)($intPart / 1000);
        $words .= _convert2d($thousands, $ones, $tens) . ' Thousand ';
        $intPart %= 1000;
    }
    if ($intPart >= 100) {
        $hundreds = (int)($intPart / 100);
        $words .= _convert2d($hundreds, $ones, $tens) . ' Hundred ';
        $intPart %= 100;
    }
    if ($intPart > 0) {
        $words .= _convert2d($intPart, $ones, $tens) . ' ';
    }

    $words = trim($words);
    if ($words) $words .= ' Rupees';
    if ($decPart > 0) {
        $words .= ' and ' . _convert2d($decPart, $ones, $tens) . ' Paise';
    }
    $words .= ' Only';

    return trim($words);
}

function _convert2d($n, $ones, $tens) {
    if ($n < 20) return $ones[$n];
    return $tens[(int)($n / 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .cheque-card {
        border: 2px solid #000 !important;
        page-break-inside: avoid;
        margin-bottom: 15px !important;
    }
    .cheque-no-input {
        border: none !important;
        border-bottom: 1px solid #999 !important;
        background: transparent !important;
        width: 120px;
    }
    .table { font-size: 10px; }
    .page-break { page-break-before: always; }
}
.cheque-card {
    border: 1px solid #dee2e6;
    page-break-inside: avoid;
    margin-bottom: 15px;
}
.cheque-no-input {
    border: none;
    border-bottom: 1px solid #ccc;
    background: #fff;
    width: 120px;
    padding: 2px 5px;
    font-size: 12px;
}
.amount-words {
    font-style: italic;
    color: #333;
    font-size: 11px;
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/mis/cheque-print">
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
        <div class="col-auto">
            <label class="form-label">Client</label>
            <select name="client_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">Unit</label>
            <select name="unit_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($units as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= sanitize($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">Bank</label>
            <select name="bank" class="form-select form-select-sm">
                <option value="">All Banks</option>
                <?php foreach ($banks as $b): ?>
                    <option value="<?= sanitize($b) ?>" <?= $bankFilter === $b ? 'selected' : '' ?>><?= sanitize($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print Cheques</button>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php elseif (!$period): ?>
        <div class="alert alert-warning">No payroll period found for <?= sanitize($monthName) ?> <?= $year ?>.</div>
    <?php elseif (empty($allRows)): ?>
        <div class="alert alert-info">No payroll records found for this period.</div>
    <?php else: ?>

    <!-- Summary -->
    <div class="alert alert-info mb-3">
        <strong>Period:</strong> <?= sanitize($monthName) ?> <?= $year ?> |
        <strong>Total Cheques:</strong> <?= count($allRows) ?> |
        <strong>Banks:</strong> <?= count($bankGroups) ?> |
        <strong>Grand Total:</strong> <strong><?= formatCurrency($grandTotal) ?></strong>
        <span class="small">(<?= amountToWordsIndian($grandTotal) ?>)</span>
    </div>

    <?php foreach ($bankGroups as $bank => $rows): 
        $bankTotal = array_sum(array_map(fn($r) => $r['net_pay'], $rows));
    ?>
    <div class="card mb-3 page-break">
        <div class="card-header bg-dark text-white">
            <div class="row align-items-center">
                <div class="col">
                    <strong><i class="bi bi-bank me-1"></i> <?= sanitize($bank) ?></strong>
                </div>
                <div class="col text-end">
                    <span class="badge bg-light text-dark"><?= count($rows) ?> Cheques</span>
                    <span class="badge bg-success ms-1">Total: <?= formatCurrency($bankTotal) ?></span>
                </div>
            </div>
        </div>
        <div class="card-body p-2">
            <!-- Table View -->
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Emp Code</th>
                            <th>Employee Name</th>
                            <th>Account No</th>
                            <th>IFSC</th>
                            <th class="text-end">Amount (₹)</th>
                            <th class="text-end">Amount in Words</th>
                            <th class="no-print">Cheque No</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($rows as $row): ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td class="fw-bold"><?= sanitize($row['full_name']) ?></td>
                            <td class="font-monospace"><?= sanitize($row['account_number']) ?></td>
                            <td class="font-monospace small"><?= sanitize($row['ifsc_code']) ?></td>
                            <td class="text-end fw-bold text-danger"><?= formatCurrency($row['net_pay']) ?></td>
                            <td class="amount-words"><?= amountToWordsIndian($row['net_pay']) ?></td>
                            <td class="no-print">
                                <input type="text" class="cheque-no-input" name="cheque_no_<?= $row['employee_code'] ?>" placeholder="Cheque #">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Bank Total (<?= count($rows) ?> Cheques)</td>
                            <td class="text-end fw-bold"><?= formatCurrency($bankTotal) ?></td>
                            <td class="amount-words"><?= amountToWordsIndian($bankTotal) ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Individual Cheque Cards (Print View) -->
            <h6 class="no-print small text-muted mb-2"><i class="bi bi-printer"></i> Individual Cheque Slips (visible in print):</h6>
            <?php foreach ($rows as $row): ?>
            <div class="cheque-card p-3">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Pay to:</strong> <span class="fs-5"><?= sanitize($row['full_name']) ?></span></p>
                        <p class="mb-1"><strong>A/c No:</strong> <span class="font-monospace"><?= sanitize($row['account_number']) ?></span></p>
                        <p class="mb-1"><strong>IFSC:</strong> <span class="font-monospace"><?= sanitize($row['ifsc_code']) ?></span></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-1"><strong>Bank:</strong> <?= sanitize($bank) ?></p>
                        <p class="mb-1"><strong>Date:</strong> ___/___/________</p>
                        <p class="mb-1"><strong>Cheque No:</strong> <span class="cheque-inline">______________</span></p>
                    </div>
                </div>
                <hr class="my-2">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Amount (₹):</strong> <span class="fs-5 fw-bold"><?= formatCurrency($row['net_pay']) ?></span></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1 amount-words"><strong>Words:</strong> <?= amountToWordsIndian($row['net_pay']) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>
