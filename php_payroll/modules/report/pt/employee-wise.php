<?php
$pageTitle = 'Professional Tax - Employee Wise';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthName = $monthNames[$month] ?? '';

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pt_employee_wise_' . sanitize($_GET['month'] ?? '0') . '_' . sanitize($_GET['year'] ?? date('Y')) . '.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Professional Tax - Employee Wise Report']);
    fputcsv($output, ['Period:', $monthName, $year]);
    fputcsv($output, []);
    fputcsv($output, ['#', 'Emp Code', 'Name', 'Designation', 'Unit', 'State', 'Gross Salary', 'PT Slab', 'PT Amount']);

    // Load data for CSV
    try {
        $csvPeriod = $db->fetch("SELECT id FROM payroll_periods WHERE month = ? AND year = ?", [$month, $year]);
        if ($csvPeriod) {
            $csvRows = $db->fetchAll("
                SELECT e.employee_code, e.full_name, e.designation, e.state,
                       u.name as unit_name, p.gross_earnings, p.professional_tax
                FROM payroll p
                JOIN employees e ON e.employee_code = p.employee_id
                LEFT JOIN units u ON u.id = e.unit_id
                WHERE p.payroll_period_id = ? AND e.status = 'active'
                ORDER BY e.state, e.employee_code
            ", [$csvPeriod['id']]);

            $sno = 1;
            foreach ($csvRows as $r) {
                fputcsv($output, [
                    $sno++, $r['employee_code'], $r['full_name'], $r['designation'],
                    $r['unit_name'] ?? '', $r['state'] ?? '', $r['gross_earnings'],
                    getPTSlab($r['state'], $r['gross_earnings']),
                    $r['professional_tax']
                ]);
            }
        }
    } catch (Exception $e) {
        fputcsv($output, ['Error:', $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Fetch payroll period and data
$period = null;
$allRows = [];
$stateGroups = [];

try {
    $period = $db->fetch("SELECT * FROM payroll_periods WHERE month = ? AND year = ?", [$month, $year]);
} catch (Exception $e) {
    $period = null;
}

if ($period) {
    try {
        $allRows = $db->fetchAll("
            SELECT e.employee_code, e.full_name, e.designation, e.state, e.unit_id,
                   u.name as unit_name, p.gross_earnings, p.professional_tax
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            LEFT JOIN units u ON u.id = e.unit_id
            WHERE p.payroll_period_id = ? AND e.status = 'active'
            ORDER BY e.state, e.employee_code
        ", [$period['id']]);

        // Group by state
        foreach ($allRows as $row) {
            $state = $row['state'] ?: 'Unknown';
            if (!isset($stateGroups[$state])) {
                $stateGroups[$state] = [];
            }
            $stateGroups[$state][] = $row;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        $allRows = [];
        $stateGroups = [];
    }
}

// Total calculations
$grandTotalPT = 0;
foreach ($allRows as $r) {
    $grandTotalPT += $r['professional_tax'];
}

function getPTSlab($state, $grossSalary) {
    if (!$state) return 'N/A';
    // Common Indian PT slabs
    $ptSlabs = [
        'Maharashtra' => [
            ['min' => 0, 'max' => 7500, 'pt' => 0],
            ['min' => 7501, 'max' => 10000, 'pt' => 175],
        ],
        'Karnataka' => [
            ['min' => 0, 'max' => 10000, 'pt' => 0],
            ['min' => 10001, 'max' => 15000, 'pt' => 150],
        ],
        'Tamil Nadu' => [
            ['min' => 0, 'max' => 21000, 'pt' => 0],
            ['min' => 21001, 'max' => 30000, 'pt' => 135],
        ],
        'Gujarat' => [
            ['min' => 0, 'max' => 6000, 'pt' => 0],
            ['min' => 6001, 'max' => 12000, 'pt' => 80],
        ],
        'Telangana' => [
            ['min' => 0, 'max' => 15000, 'pt' => 0],
            ['min' => 15001, 'max' => 20000, 'pt' => 150],
        ],
        'Andhra Pradesh' => [
            ['min' => 0, 'max' => 15000, 'pt' => 0],
            ['min' => 15001, 'max' => 20000, 'pt' => 150],
        ],
        'West Bengal' => [
            ['min' => 0, 'max' => 8500, 'pt' => 0],
            ['min' => 8501, 'max' => 10000, 'pt' => 90],
        ],
        'Rajasthan' => [
            ['min' => 0, 'max' => 7500, 'pt' => 0],
            ['min' => 7501, 'max' => 10000, 'pt' => 175],
        ],
    ];

    // Default: Rs. 200 for salary > 15000
    if (isset($ptSlabs[$state])) {
        foreach (array_reverse($ptSlabs[$state]) as $slab) {
            if ($grossSalary >= $slab['min']) {
                return '₹' . $slab['pt'];
            }
        }
    }
    return $grossSalary > 15000 ? '₹200' : '₹0';
}
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

    <!-- Filter Form -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/pt/employee-wise">
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
            <a href="?page=report/pt/employee-wise&month=<?= $month ?>&year=<?= $year ?>&export=1" class="btn btn-sm btn-success me-1"><i class="bi bi-download"></i> CSV</a>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php elseif (!$period): ?>
        <div class="alert alert-warning">No payroll period found for <?= sanitize($monthName) ?> <?= $year ?>.</div>
    <?php elseif (empty($allRows)): ?>
        <div class="alert alert-info">No employee records found with PT deductions for this period.</div>
    <?php else: ?>

    <!-- Grand Summary -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 text-center bg-primary bg-opacity-10">
                <h6 class="text-muted mb-1">Total Employees</h6>
                <h3 class="text-primary mb-0"><?= count($allRows) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 text-center bg-success bg-opacity-10">
                <h6 class="text-muted mb-1">States Covered</h6>
                <h3 class="text-success mb-0"><?= count($stateGroups) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 text-center bg-danger bg-opacity-10">
                <h6 class="text-muted mb-1">Total PT Deducted</h6>
                <h3 class="text-danger mb-0"><?= formatCurrency($grandTotalPT) ?></h3>
            </div>
        </div>
    </div>

    <!-- Tables grouped by state -->
    <?php foreach ($stateGroups as $state => $rows): 
        $stateTotal = 0;
        foreach ($rows as $r) $stateTotal += $r['professional_tax'];
    ?>
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <strong><?= sanitize($state) ?></strong>
                <span class="float-end"><?= count($rows) ?> Employees | Total PT: <?= formatCurrency($stateTotal) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Emp Code</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>Unit</th>
                                <th class="text-end">Gross Salary</th>
                                <th class="text-end">PT Slab</th>
                                <th class="text-end fw-bold">PT Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 1; foreach ($rows as $row): ?>
                            <tr>
                                <td><?= $sno++ ?></td>
                                <td><?= sanitize($row['employee_code']) ?></td>
                                <td><?= sanitize($row['full_name']) ?></td>
                                <td><?= sanitize($row['designation']) ?></td>
                                <td><?= sanitize($row['unit_name'] ?? '-') ?></td>
                                <td class="text-end"><?= formatCurrency($row['gross_earnings']) ?></td>
                                <td class="text-end"><?= getPTSlab($row['state'], $row['gross_earnings']) ?></td>
                                <td class="text-end fw-bold <?= $row['professional_tax'] > 0 ? 'text-danger' : '' ?>"><?= formatCurrency($row['professional_tax']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="7" class="text-end fw-bold">State Subtotal</td>
                                <td class="text-end fw-bold"><?= formatCurrency($stateTotal) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Grand Total Footer -->
    <div class="card">
        <div class="card-body p-3">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Period:</strong> <?= sanitize($monthName) . ' ' . $year ?></p>
                    <p class="mb-0"><strong>Total States:</strong> <?= count($stateGroups) ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-1"><strong>Total Employees:</strong> <?= count($allRows) ?></p>
                    <p class="mb-0"><strong>Grand Total PT Deducted:</strong> <span class="fs-5 text-danger fw-bold"><?= formatCurrency($grandTotalPT) ?></span></p>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
