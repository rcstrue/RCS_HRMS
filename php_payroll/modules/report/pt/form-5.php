<?php
$pageTitle = 'PT Form 5 - Monthly Return';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthName = $monthNames[$month] ?? '';
$stateFilter = sanitize($_GET['state'] ?? '');

$company = null;
$period = null;
$rows = [];
$stateGroups = [];

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

// Get available states
$availableStates = [];
try {
    $availableStates = $db->fetchAll("
        SELECT DISTINCT e.state FROM employees e
        JOIN payroll p ON p.employee_id = e.employee_code
        JOIN payroll_periods pp ON pp.id = p.payroll_period_id
        WHERE pp.month = ? AND pp.year = ? AND e.status = 'active'
        ORDER BY e.state
    ", [$month, $year]);
} catch (Exception $e) {
    $availableStates = [];
}

if ($period) {
    $params = [$period['id']];
    $stateWhere = '';
    if ($stateFilter) {
        $stateWhere = " AND e.state = ?";
        $params[] = $stateFilter;
    }

    try {
        $rows = $db->fetchAll("
            SELECT e.employee_code, e.full_name, e.designation, e.state,
                   p.gross_earnings, p.professional_tax
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            WHERE p.payroll_period_id = ? AND e.status = 'active' $stateWhere
            ORDER BY e.state, e.employee_code
        ", $params);

        foreach ($rows as $row) {
            $state = $row['state'] ?: 'Unknown';
            if (!isset($stateGroups[$state])) {
                $stateGroups[$state] = ['rows' => [], 'total' => 0, 'count' => 0];
            }
            $stateGroups[$state]['rows'][] = $row;
            $stateGroups[$state]['total'] += $row['professional_tax'];
            $stateGroups[$state]['count']++;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$grandTotal = array_sum(array_map(fn($g) => $g['total'], $stateGroups));
$grandCount = count($rows);
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
    .page-break { page-break-before: always; }
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/pt/form-5">
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
            <label class="form-label">State</label>
            <select name="state" class="form-select form-select-sm">
                <option value="">All States</option>
                <?php foreach ($availableStates as $s): ?>
                    <option value="<?= sanitize($s['state']) ?>" <?= $stateFilter === $s['state'] ? 'selected' : '' ?>><?= sanitize($s['state']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php elseif (!$period): ?>
        <div class="alert alert-warning">No payroll period found for <?= sanitize($monthName) ?> <?= $year ?>.</div>
    <?php else: ?>

    <?php foreach ($stateGroups as $state => $group): ?>
    <div class="card mb-4 <?= ($stateFilter || count($stateGroups) === 1) ? '' : 'page-break' ?>">
        <!-- State Header -->
        <div class="card-header bg-dark text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h5 class="mb-0">FORM 5 — Professional Tax Monthly Return</h5>
                    <small><?= sanitize($state) ?> State</small>
                </div>
                <div class="col text-end">
                    <strong>Period: <?= sanitize($monthName) . ' ' . $year ?></strong>
                </div>
            </div>
        </div>
        <div class="card-body p-3">
            <!-- Employer Details -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="35%"><strong>Employer Name:</strong></td><td><?= $company ? sanitize($company['company_name']) : 'N/A' ?></td></tr>
                        <tr><td><strong>PAN:</strong></td><td><?= $company ? sanitize($company['pan_number'] ?? 'N/A') : 'N/A' ?></td></tr>
                        <tr><td><strong>Address:</strong></td><td><?= $company ? sanitize($company['address'] ?? '') : '' ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="35%"><strong>PT Registration No:</strong></td><td>_______________</td></tr>
                        <tr><td><strong>Month/Year:</strong></td><td><?= sanitize($monthName) . ' ' . $year ?></td></tr>
                        <tr><td><strong>State:</strong></td><td><?= sanitize($state) ?></td></tr>
                    </table>
                </div>
            </div>

            <hr>

            <!-- Employee Details Table -->
            <h6 class="mb-2">A. Employee-wise PT Details</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Emp Code</th>
                            <th>Employee Name</th>
                            <th>Designation</th>
                            <th class="text-end">Gross Salary (₹)</th>
                            <th class="text-end">PT Deducted (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($group['rows'] as $row): ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['designation']) ?></td>
                            <td class="text-end"><?= formatCurrency($row['gross_earnings']) ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency($row['professional_tax']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <td colspan="5" class="text-end fw-bold">Total (<?= $group['count'] ?> Employees)</td>
                            <td class="text-end fw-bold"><?= formatCurrency($group['total']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <hr>

            <!-- Category-wise Breakdown -->
            <h6 class="mb-2">B. Category-wise Breakdown</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th class="text-center">No. of Employees</th>
                            <th class="text-end">Total PT (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $catZero = 0; $catLow = 0; $catMid = 0; $catHigh = 0;
                        $catZeroPT = 0; $catLowPT = 0; $catMidPT = 0; $catHighPT = 0;
                        foreach ($group['rows'] as $row) {
                            if ($row['professional_tax'] == 0) { $catZero++; $catZeroPT += 0; }
                            elseif ($row['professional_tax'] <= 150) { $catLow++; $catLowPT += $row['professional_tax']; }
                            elseif ($row['professional_tax'] <= 300) { $catMid++; $catMidPT += $row['professional_tax']; }
                            else { $catHigh++; $catHighPT += $row['professional_tax']; }
                        }
                        ?>
                        <tr><td>PT = ₹0 (Below threshold)</td><td class="text-center"><?= $catZero ?></td><td class="text-end"><?= formatCurrency($catZeroPT) ?></td></tr>
                        <tr><td>PT ≤ ₹150</td><td class="text-center"><?= $catLow ?></td><td class="text-end"><?= formatCurrency($catLowPT) ?></td></tr>
                        <tr><td>PT ₹151 – ₹300</td><td class="text-center"><?= $catMid ?></td><td class="text-end"><?= formatCurrency($catMidPT) ?></td></tr>
                        <tr><td>PT > ₹300</td><td class="text-center"><?= $catHigh ?></td><td class="text-end"><?= formatCurrency($catHighPT) ?></td></tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <td class="fw-bold">Total</td>
                            <td class="text-center fw-bold"><?= $group['count'] ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency($group['total']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <hr>

            <!-- Payment Details -->
            <h6 class="mb-2">C. Payment Details</h6>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="40%"><strong>Challan No:</strong></td><td>_______________</td></tr>
                        <tr><td><strong>Payment Date:</strong></td><td>_______________</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td width="40%"><strong>Bank Name:</strong></td><td>_______________</td></tr>
                        <tr><td><strong>Amount Paid:</strong></td><td><strong><?= formatCurrency($group['total']) ?></strong></td></tr>
                    </table>
                </div>
            </div>

            <!-- Signature -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <p class="small text-muted mb-0">Date: _______________</p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="small mb-0">Signature of Employer / Authorized Person</p>
                    <div class="border-bottom mt-1" style="width: 250px; margin-left: auto;"></div>
                    <p class="small text-muted mb-0">Name: _______________</p>
                    <p class="small text-muted mb-0">Designation: _______________</p>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>
