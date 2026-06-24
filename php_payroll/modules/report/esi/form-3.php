<?php
$pageTitle = 'ESI Form 3 - Return of Contributions';

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="esi_form_3_' . sanitize($_GET['month'] ?? '0') . '_' . sanitize($_GET['year'] ?? date('Y')) . '.csv"');
    $output = fopen('php://output', 'w');

    $month = (int)($_GET['month'] ?? date('m'));
    $year = (int)($_GET['year'] ?? date('Y'));

    try {
        $period = $db->fetch("SELECT * FROM payroll_periods WHERE month = ? AND year = ?", [$month, $year]);
    } catch (Exception $e) {
        $period = null;
    }

    fputcsv($output, ['ESI Form 3 - Return of Contributions']);
    fputcsv($output, ['Month:', $month, 'Year:', $year]);
    fputcsv($output, []);

    $headers = ['S.No', 'IP No', 'ESI No', 'Employee Name', 'Father Name', 'Gender', 'Total Wages', 'Wages for ESI', 'EE Contribution (0.75%)', 'ER Contribution (3.25%)', 'Total Contribution', 'NCP Days'];
    fputcsv($output, $headers);

    if ($period) {
        try {
            $rows = $db->fetchAll("
                SELECT e.employee_code, e.full_name, e.father_name, e.gender, e.esic_number,
                       p.gross_earnings, p.esi_employee, p.esi_employer, p.total_days, p.paid_days
                FROM payroll p
                JOIN employees e ON e.employee_code = p.employee_id
                JOIN employee_salary_structures ess ON ess.employee_id = e.id
                    AND ess.effective_from <= ? AND (ess.effective_to IS NULL OR ess.effective_to >= ?)
                WHERE p.payroll_period_id = ? AND e.status = 'active' AND ess.esi_applicable = 1
                ORDER BY e.employee_code
            ", [$period['start_date'], $period['end_date'], $period['id']]);

            $sno = 1;
            $totalWages = 0;
            $totalEE = 0;
            $totalER = 0;
            $totalContrib = 0;

            foreach ($rows as $row) {
                $ncpDays = $row['total_days'] - $row['paid_days'];
                $eeContrib = $row['esi_employee'];
                $erContrib = $row['esi_employer'];
                $total = $eeContrib + $erContrib;
                $totalWages += $row['gross_earnings'];
                $totalEE += $eeContrib;
                $totalER += $erContrib;
                $totalContrib += $total;

                fputcsv($output, [
                    $sno++,
                    $row['employee_code'],
                    $row['esic_number'],
                    $row['full_name'],
                    $row['father_name'],
                    $row['gender'],
                    $row['gross_earnings'],
                    $row['gross_earnings'],
                    round($eeContrib, 2),
                    round($erContrib, 2),
                    round($total, 2),
                    $ncpDays
                ]);
            }

            fputcsv($output, []);
            fputcsv($output, ['', '', '', '', '', 'TOTAL', round($totalWages, 2), round($totalWages, 2), round($totalEE, 2), round($totalER, 2), round($totalContrib, 2), '']);
        } catch (Exception $e) {
            fputcsv($output, ['Error: ' . $e->getMessage()]);
        }
    }

    fclose($output);
    exit;
}

$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$period = null;
$rows = [];
$company = null;

try {
    $period = $db->fetch("SELECT * FROM payroll_periods WHERE month = ? AND year = ?", [$month, $year]);
} catch (Exception $e) {
    $period = null;
}

try {
    $company = $db->fetch("SELECT * FROM companies LIMIT 1");
} catch (Exception $e) {
    $company = null;
}

if ($period) {
    try {
        $rows = $db->fetchAll("
            SELECT e.employee_code, e.full_name, e.father_name, e.gender, e.esic_number,
                   p.gross_earnings, p.esi_employee, p.esi_employer, p.total_days, p.paid_days,
                   ess.gross_salary
            FROM payroll p
            JOIN employees e ON e.employee_code = p.employee_id
            JOIN employee_salary_structures ess ON ess.employee_id = e.id
                AND ess.effective_from <= ? AND (ess.effective_to IS NULL OR ess.effective_to >= ?)
            WHERE p.payroll_period_id = ? AND e.status = 'active' AND ess.esi_applicable = 1
            ORDER BY e.employee_code
        ", [$period['start_date'], $period['end_date'], $period['id']]);
    } catch (Exception $e) {
        $rows = [];
        $error = $e->getMessage();
    }
}

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$monthName = $monthNames[$month] ?? '';

$totalEmployees = count($rows);
$totalWages = 0;
$totalEE = 0;
$totalER = 0;
$totalContrib = 0;

foreach ($rows as $row) {
    $totalWages += $row['gross_earnings'];
    $totalEE += $row['esi_employee'];
    $totalER += $row['esi_employer'];
    $totalContrib += ($row['esi_employee'] + $row['esi_employer']);
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter Form -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/esi/form-3">
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
            <a href="?page=report/esi/form-3&month=<?= $month ?>&year=<?= $year ?>&export=1" class="btn btn-sm btn-success"><i class="bi bi-download"></i> CSV</a>
        </div>
    </form>

    <!-- Employer Header -->
    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="mb-1">Form 3 — Return of Contributions</h5>
                    <p class="mb-1"><strong>Employer Code:</strong> <?= $company ? sanitize($company['esi_number'] ?? 'N/A') : 'N/A' ?></p>
                    <p class="mb-1"><strong>Employer Name:</strong> <?= $company ? sanitize($company['company_name']) : 'N/A' ?></p>
                    <p class="mb-0"><strong>Address:</strong> <?= $company ? sanitize($company['address'] ?? '') : '' ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-1"><strong>Contribution Period:</strong> <?= sanitize($monthName) . ' ' . $year ?></p>
                    <p class="mb-1"><strong>Wage Ceiling:</strong> ₹21,000</p>
                    <p class="mb-0"><strong>EE Rate:</strong> 0.75% | <strong>ER Rate:</strong> 3.25%</p>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php elseif (!$period): ?>
        <div class="alert alert-warning">No payroll period found for <?= sanitize($monthName) ?> <?= $year ?>.</div>
    <?php elseif (empty($rows)): ?>
        <div class="alert alert-info">No ESI-applicable employees found for this period.</div>
    <?php else: ?>
        <!-- Data Table -->
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>IP No</th>
                        <th>ESI No</th>
                        <th>Employee Name</th>
                        <th>Father Name</th>
                        <th>Gender</th>
                        <th class="text-end">Total Wages</th>
                        <th class="text-end">Wages for ESI</th>
                        <th class="text-end">EE Cont. (0.75%)</th>
                        <th class="text-end">ER Cont. (3.25%)</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">NCP Days</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sno = 1; foreach ($rows as $row):
                        $ncpDays = $row['total_days'] - $row['paid_days'];
                        $eeC = $row['esi_employee'];
                        $erC = $row['esi_employer'];
                        $total = $eeC + $erC;
                    ?>
                    <tr>
                        <td><?= $sno++ ?></td>
                        <td><?= sanitize($row['employee_code']) ?></td>
                        <td><?= sanitize($row['esic_number']) ?></td>
                        <td><?= sanitize($row['full_name']) ?></td>
                        <td><?= sanitize($row['father_name']) ?></td>
                        <td><?= sanitize($row['gender']) ?></td>
                        <td class="text-end"><?= formatCurrency($row['gross_earnings']) ?></td>
                        <td class="text-end"><?= formatCurrency($row['gross_earnings']) ?></td>
                        <td class="text-end"><?= formatCurrency($eeC) ?></td>
                        <td class="text-end"><?= formatCurrency($erC) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($total) ?></td>
                        <td class="text-center"><?= max(0, $ncpDays) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="6" class="text-end fw-bold">TOTAL</td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalWages) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalWages) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalEE) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalER) ?></td>
                        <td class="text-end fw-bold"><?= formatCurrency($totalContrib) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Summary -->
        <div class="row mt-3">
            <div class="col-md-4">
                <div class="card p-3 text-center">
                    <h6>Total Employees</h6>
                    <h3 class="text-primary mb-0"><?= $totalEmployees ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-3 text-center">
                    <h6>Total Wages</h6>
                    <h3 class="text-success mb-0"><?= formatCurrency($totalWages) ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-3 text-center">
                    <h6>Total Contributions</h6>
                    <h3 class="text-danger mb-0"><?= formatCurrency($totalContrib) ?></h3>
                </div>
            </div>
        </div>

        <!-- Certification -->
        <div class="card mt-3">
            <div class="card-body">
                <p class="mb-1"><strong>Certified that the above particulars are true and correct.</strong></p>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <p class="mb-0">Date: _______________</p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-0">Signature of Employer</p>
                        <p class="mb-0"><strong>Name:</strong> ___________________</p>
                        <p class="mb-0"><strong>Designation:</strong> ___________________</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
