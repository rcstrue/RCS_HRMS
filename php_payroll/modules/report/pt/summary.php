<?php
$pageTitle = 'Professional Tax Summary';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$year = (int)($_GET['year'] ?? date('Y'));

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pt_summary_' . sanitize($_GET['year'] ?? date('Y')) . '.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Professional Tax Summary - ' . $year]);
    fputcsv($output, []);
    fputcsv($output, ['Month', 'Employee Count', 'Total PT Deducted', 'Challan No', 'Payment Date', 'Status']);

    if (isset($monthlySummary)) {
        foreach ($monthlySummary as $ms) {
            fputcsv($output, [
                $ms['month_name'], $ms['emp_count'], $ms['total_pt'],
                $ms['challan_no'] ?? '', $ms['payment_date'] ?? '', $ms['status'] ?? 'Pending'
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['ANNUAL TOTAL', $annualTotal['emp_count'], $annualTotal['total_pt']]);
    }

    fclose($output);
    exit;
}

// Get monthly PT summary
$monthlySummary = [];
$annualEmpCount = 0;
$annualTotalPT = 0;

try {
    $periods = $db->fetchAll("SELECT * FROM payroll_periods WHERE year = ? ORDER BY month", [$year]);
} catch (Exception $e) {
    $periods = [];
}

foreach ($periods as $period) {
    try {
        $stats = $db->fetch("
            SELECT COUNT(DISTINCT p.employee_id) as emp_count,
                   COALESCE(SUM(p.professional_tax), 0) as total_pt
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            WHERE p.payroll_period_id = ? AND e.status = 'active'
        ", [$period['id']]);

        // Try to get challan info
        $challan = null;
        try {
            $challan = $db->fetch("
                SELECT challan_no, payment_date, status FROM pt_challans
                WHERE payroll_period_id = ? LIMIT 1
            ", [$period['id']]);
        } catch (Exception $e) {
            $challan = null;
        }

        $monthlySummary[] = [
            'month' => $period['month'],
            'month_name' => $monthNames[$period['month']] ?? '',
            'emp_count' => $stats['emp_count'],
            'total_pt' => $stats['total_pt'],
            'challan_no' => $challan['challan_no'] ?? '-',
            'payment_date' => $challan['payment_date'] ?? '-',
            'status' => $challan['status'] ?? ($stats['total_pt'] > 0 ? 'Pending' : 'N/A')
        ];

        $annualEmpCount += $stats['emp_count'];
        $annualTotalPT += $stats['total_pt'];
    } catch (Exception $e) {
        $monthlySummary[] = [
            'month' => $period['month'],
            'month_name' => $monthNames[$period['month']] ?? '',
            'emp_count' => 0, 'total_pt' => 0,
            'challan_no' => '-', 'payment_date' => '-', 'status' => 'Error'
        ];
    }
}

$annualTotal = ['emp_count' => $annualEmpCount, 'total_pt' => $annualTotalPT];

// State-wise summary for selected year
$stateSummary = [];
try {
    $stateSummary = $db->fetchAll("
        SELECT e.state,
               COUNT(DISTINCT p.employee_id) as emp_count,
               COALESCE(SUM(p.professional_tax), 0) as total_pt,
               COUNT(DISTINCT p.payroll_period_id) as months_active
        FROM payroll p
        JOIN employees e ON e.employee_code = p.employee_id
        JOIN payroll_periods pp ON pp.id = p.payroll_period_id
        WHERE pp.year = ? AND e.status = 'active'
        GROUP BY e.state
        ORDER BY total_pt DESC
    ", [$year]);
} catch (Exception $e) {
    $stateSummary = [];
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

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/pt/summary">
        <div class="col-auto">
            <label class="form-label">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2020" max="2030">
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <a href="?page=report/pt/summary&year=<?= $year ?>&export=1" class="btn btn-sm btn-success me-1"><i class="bi bi-download"></i> CSV</a>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <!-- Annual Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card p-3 text-center bg-primary bg-opacity-10">
                <h6 class="text-muted mb-1">Financial Year</h6>
                <h4 class="text-primary mb-0"><?= $year ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-success bg-opacity-10">
                <h6 class="text-muted mb-1">Months Processed</h6>
                <h4 class="text-success mb-0"><?= count(array_filter($monthlySummary, fn($m) => $m['total_pt'] > 0)) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-info bg-opacity-10">
                <h6 class="text-muted mb-1">Total Employees (Cumulative)</h6>
                <h4 class="text-info mb-0"><?= $annualEmpCount ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-danger bg-opacity-10">
                <h6 class="text-muted mb-1">Annual PT Total</h6>
                <h4 class="text-danger mb-0"><?= formatCurrency($annualTotalPT) ?></h4>
            </div>
        </div>
    </div>

    <!-- Monthly Trend Table -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <strong>Monthly PT Trend — <?= $year ?></strong>
        </div>
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-center">Employee Count</th>
                            <th class="text-end">Total PT Deducted (₹)</th>
                            <th>Challan No</th>
                            <th>Payment Date</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlySummary as $ms): 
                            $statusBadge = 'secondary';
                            if ($ms['status'] === 'Paid') $statusBadge = 'success';
                            elseif ($ms['status'] === 'Pending') $statusBadge = 'warning';
                            elseif ($ms['status'] === 'Overdue') $statusBadge = 'danger';
                        ?>
                        <tr class="<?= $ms['total_pt'] == 0 ? 'table-secondary' : '' ?>">
                            <td class="fw-bold"><?= sanitize($ms['month_name']) ?></td>
                            <td class="text-center"><?= $ms['emp_count'] ?: '-' ?></td>
                            <td class="text-end fw-bold"><?= $ms['total_pt'] > 0 ? formatCurrency($ms['total_pt']) : '-' ?></td>
                            <td><?= sanitize($ms['challan_no']) ?></td>
                            <td><?= $ms['payment_date'] != '-' ? formatDate($ms['payment_date']) : '-' ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $statusBadge ?>"><?= sanitize($ms['status']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th>Annual Total</th>
                            <th class="text-center"><?= $annualEmpCount ?></th>
                            <th class="text-end"><?= formatCurrency($annualTotalPT) ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- State-wise Summary -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white">
            <strong>State-wise PT Summary — <?= $year ?></strong>
        </div>
        <div class="card-body p-3">
            <?php if (empty($stateSummary)): ?>
                <p class="text-muted text-center mb-0">No PT data available for <?= $year ?>.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>State</th>
                            <th class="text-center">Total Employees</th>
                            <th class="text-center">Active Months</th>
                            <th class="text-end">Total PT Deducted (₹)</th>
                            <th class="text-end">Monthly Avg (₹)</th>
                            <th class="text-end">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($stateSummary as $ss): 
                            $avgPT = $ss['months_active'] > 0 ? round($ss['total_pt'] / $ss['months_active'], 2) : 0;
                            $pct = $annualTotalPT > 0 ? round(($ss['total_pt'] / $annualTotalPT) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td class="fw-bold"><?= sanitize($ss['state'] ?: 'Unknown') ?></td>
                            <td class="text-center"><?= $ss['emp_count'] ?></td>
                            <td class="text-center"><?= $ss['months_active'] ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency($ss['total_pt']) ?></td>
                            <td class="text-end"><?= formatCurrency($avgPT) ?></td>
                            <td class="text-end"><?= $pct ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="2"><strong>Grand Total</strong></td>
                            <td class="text-center"><?= array_sum(array_column($stateSummary, 'emp_count')) ?></td>
                            <td></td>
                            <td class="text-end fw-bold"><?= formatCurrency($annualTotalPT) ?></td>
                            <td></td>
                            <td class="text-end">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
