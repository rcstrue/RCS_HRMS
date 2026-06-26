<?php
/**
 * RCS HRMS Pro — Form XIX: Annual Return (CLRA)
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Annual return submitted by principal employer / contractor
 */
$pageTitle = 'Form XIX - Annual Return (CLRA)';

// ── Fetch filter options ────────────────────────────────────────────
$years   = [];
$clients = [];
$company = null;

try {
    global $db;

    $stmt = $db->query("SELECT DISTINCT year FROM payroll_periods ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($years)) $years = [date('Y')];

    $stmt = $db->query("SELECT id, name FROM clients ORDER BY name ASC");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT * FROM companies LIMIT 1");
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $years = [date('Y')];
}

// ── Apply filters ───────────────────────────────────────────────────
$filterYear   = intval($_GET['year'] ?? ($years[0] ?? date('Y')));
$filterClient = intval($_GET['client_id'] ?? 0);

// ── Gather annual return data ───────────────────────────────────────
$data = [
    'total_employees'          => 0,
    'male_workers'             => 0,
    'female_workers'           => 0,
    'contractors'              => [],
    'contractor_count'         => 0,
    'total_mandays'            => 0,
    'total_wages_paid'         => 0,
    'min_wage_paid'            => 0,
    'max_wage_paid'            => 0,
    'pf_contributions'         => 0,
    'esi_contributions'        => 0,
    'bonus_paid'               => 0,
    'welfare_expenditure'      => 0,
    'accidents_count'          => 0,
    'fatal_accidents'          => 0,
    'total_ot_hours'           => 0,
    'ot_amount'                => 0,
    'penalties_paid'           => 0,
    'prosecutions_count'       => 0,
    'avg_daily_employment'     => 0,
    'periods'                  => [],
];

try {
    // ── Employees ──
    $empSql = "SELECT e.*, ess.gross_salary
               FROM employees e
               LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
               WHERE 1=1";
    $empParams = [];

    if ($filterYear > 0) {
        $empSql .= " AND (YEAR(e.date_of_joining) <= ? OR e.date_of_joining IS NULL)";
        $empParams[] = $filterYear;
        $empSql .= " AND (e.date_of_leaving IS NULL OR YEAR(e.date_of_leaving) >= ?)";
        $empParams[] = $filterYear;
    }
    if ($filterClient > 0) {
        $empSql .= " AND e.client_id = ?";
        $empParams[] = $filterClient;
    }

    $stmt = $db->prepare($empSql);
    $stmt->execute($empParams);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data['total_employees'] = count($employees);
    $data['male_workers'] = count(array_filter($employees, fn($e) => ($e['gender'] ?? '') !== 'female'));
    $data['female_workers'] = count(array_filter($employees, fn($e) => ($e['gender'] ?? '') === 'female'));

    $grossSalaries = array_column(array_filter($employees, fn($e) => floatval($e['gross_salary'] ?? 0) > 0), 'gross_salary');
    if (!empty($grossSalaries)) {
        $data['min_wage_paid'] = min(array_map('floatval', $grossSalaries));
        $data['max_wage_paid'] = max(array_map('floatval', $grossSalaries));
    }

    // ── Payroll summary ──
    $empIds = array_column($employees, 'id');
    if (!empty($empIds) && $filterYear > 0) {
        $placeholders = implode(',', array_fill(0, count($empIds), '?'));

        $stmt = $db->prepare("
            SELECT SUM(p.paid_days) as total_mandays,
                   SUM(p.gross_earnings) as total_wages,
                   SUM(p.pf_employee) as pf_total,
                   SUM(p.esi_employee) as esi_total,
                   SUM(p.overtime_hours) as total_ot,
                   COUNT(DISTINCT p.payroll_period_id) as period_count
            FROM payroll p
            JOIN payroll_periods pp ON pp.id = p.payroll_period_id
            WHERE p.employee_id IN ($placeholders) AND pp.year = ?
        ");
        $stmt->execute(array_merge($empIds, [$filterYear]));
        $payrollSummary = $stmt->fetch(PDO::FETCH_ASSOC);

        $data['total_mandays']     = intval($payrollSummary['total_mandays'] ?? 0);
        $data['total_wages_paid']  = floatval($payrollSummary['total_wages'] ?? 0);
        $data['pf_contributions']  = floatval($payrollSummary['pf_total'] ?? 0);
        $data['esi_contributions'] = floatval($payrollSummary['esi_total'] ?? 0);
        $data['total_ot_hours']    = floatval($payrollSummary['total_ot'] ?? 0);
        $data['ot_amount']         = round($data['total_ot_hours'] * 50, 2); // approx OT rate

        $periodCount = intval($payrollSummary['period_count'] ?? 12);
        $data['avg_daily_employment'] = $periodCount > 0 && $data['total_mandays'] > 0
            ? round($data['total_mandays'] / $periodCount, 0) : $data['total_employees'];

        // Monthly breakdown
        $stmt = $db->prepare("
            SELECT pp.month, pp.year,
                   COUNT(DISTINCT p.employee_id) as emp_count,
                   SUM(p.paid_days) as total_days,
                   SUM(p.gross_earnings) as gross,
                   SUM(p.overtime_hours) as ot_hrs
            FROM payroll p
            JOIN payroll_periods pp ON pp.id = p.payroll_period_id
            WHERE p.employee_id IN ($placeholders) AND pp.year = ?
            GROUP BY pp.id ORDER BY pp.month ASC
        ");
        $stmt->execute(array_merge($empIds, [$filterYear]));
        $data['periods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Contractors ──
    try {
        $stmt = $db->query("SELECT * FROM contractors_register WHERE status = 'active' ORDER BY id ASC");
        $data['contractors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data['contractor_count'] = count($data['contractors']);
    } catch (Exception $e) {
        // contractors_register table may not exist in non-labour module context
    }

} catch (Exception $e) {
    $error = 'Error fetching data: ' . $e->getMessage();
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'Form_XIX_Annual_Return_CLRA_' . $filterYear . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['FORM XIX - ANNUAL RETURN (CLRA) - Year ' . $filterYear]);
    fputcsv($output, []);
    fputcsv($output, ['SECTION A - ESTABLISHMENT DETAILS']);
    fputcsv($output, ['Total Employees', $data['total_employees']]);
    fputcsv($output, ['Male Workers', $data['male_workers']]);
    fputcsv($output, ['Female Workers', $data['female_workers']]);
    fputcsv($output, ['Average Daily Employment', $data['avg_daily_employment']]);
    fputcsv($output, ['Number of Contractors', $data['contractor_count']]);
    fputcsv($output, []);
    fputcsv($output, ['SECTION B - WAGES & DEDUCTIONS']);
    fputcsv($output, ['Total Man Days', $data['total_mandays']]);
    fputcsv($output, ['Total Wages Paid', formatCurrency($data['total_wages_paid'])]);
    fputcsv($output, ['Min Wage Paid', formatCurrency($data['min_wage_paid'])]);
    fputcsv($output, ['Max Wage Paid', formatCurrency($data['max_wage_paid'])]);
    fputcsv($output, ['PF Contributions', formatCurrency($data['pf_contributions'])]);
    fputcsv($output, ['ESI Contributions', formatCurrency($data['esi_contributions'])]);
    fputcsv($output, []);
    fputcsv($output, ['SECTION C - OVERTIME & SAFETY']);
    fputcsv($output, ['Total OT Hours', $data['total_ot_hours']]);
    fputcsv($output, ['Accidents', $data['accidents_count']]);
    fputcsv($output, ['Fatal Accidents', $data['fatal_accidents']]);
    fputcsv($output, ['Penalties Paid', formatCurrency($data['penalties_paid'])]);

    fclose($output);
    exit;
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-bar-graph me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Annual Return</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?page=forms/labour/form-xix&year=<?= $filterYear ?>&client_id=<?= $filterClient ?>&export=csv"
               class="btn btn-outline-success btn-sm">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Filter -->
    <div class="card mb-3 no-print">
        <div class="card-body py-2">
            <form method="GET" action="" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="forms/labour/form-xix">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Year</label>
                    <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Client</label>
                    <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Return Header -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white text-center py-2">
            <h5 class="mb-0" style="letter-spacing:1px;">FORM XIX — ANNUAL RETURN</h5>
            <small>Under Contract Labour (Regulation & Abolition) Act, 1970 — For the Year <?= $filterYear ?></small>
        </div>
        <div class="card-body">
            <?php if ($company): ?>
            <table class="table table-sm table-bordered mb-3">
                <tbody>
                    <tr>
                        <td style="width:200px" class="fw-semibold bg-light">Name of Establishment</td>
                        <td colspan="3"><?= htmlspecialchars($company['company_name'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-semibold bg-light">CIN / GSTIN</td>
                        <td><?= htmlspecialchars($company['cin'] ?? '') ?> / <?= htmlspecialchars($company['gstin'] ?? '') ?></td>
                        <td class="fw-semibold bg-light">PAN</td>
                        <td><?= htmlspecialchars($company['pan_number'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-semibold bg-light">PF Number</td>
                        <td><?= htmlspecialchars($company['pf_number'] ?? '') ?></td>
                        <td class="fw-semibold bg-light">ESI Number</td>
                        <td><?= htmlspecialchars($company['esi_number'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-semibold bg-light">PT Number</td>
                        <td><?= htmlspecialchars($company['pt_number'] ?? '') ?></td>
                        <td class="fw-semibold bg-light">Address</td>
                        <td><?= htmlspecialchars($company['address'] ?? '') ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Section A: Manning -->
            <h6 class="border-bottom border-2 pb-1 mb-3 mt-4 text-primary">
                <i class="bi bi-people me-1"></i>Section A — Manning & Employment
            </h6>
            <table class="table table-sm table-bordered">
                <tbody>
                    <tr>
                        <td style="width:350px" class="bg-light fw-semibold">Total number of workmen employed during the year</td>
                        <td class="text-center fw-bold fs-6"><?= $data['total_employees'] ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Number of male workers</td>
                        <td class="text-center"><?= $data['male_workers'] ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Number of female workers</td>
                        <td class="text-center"><?= $data['female_workers'] ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Average daily number of workmen employed</td>
                        <td class="text-center"><?= $data['avg_daily_employment'] ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Number of contractors employed</td>
                        <td class="text-center"><?= $data['contractor_count'] ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Contractor Details -->
            <?php if (!empty($data['contractors'])): ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mt-2">
                    <thead class="table-light">
                        <tr>
                            <th>Sl No</th><th>Name</th><th>Registration No</th><th>Nature of Work</th><th>Workers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sl = 1; foreach ($data['contractors'] as $con): ?>
                        <tr>
                            <td><?= $sl++ ?></td>
                            <td><?= htmlspecialchars($con['contractor_name']) ?></td>
                            <td><?= htmlspecialchars($con['registration_number']) ?></td>
                            <td><?= htmlspecialchars($con['nature_of_work']) ?></td>
                            <td class="text-center"><?= intval($con['total_workers']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Section B: Wages -->
            <h6 class="border-bottom border-2 pb-1 mb-3 mt-4 text-primary">
                <i class="bi bi-cash-stack me-1"></i>Section B — Wages & Deductions
            </h6>
            <table class="table table-sm table-bordered">
                <tbody>
                    <tr>
                        <td style="width:350px" class="bg-light fw-semibold">Total man-days worked during the year</td>
                        <td class="text-center fw-bold"><?= number_format($data['total_mandays']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Total wages paid (gross earnings)</td>
                        <td class="text-end fw-bold"><?= formatCurrency($data['total_wages_paid']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Minimum wages paid (lowest)</td>
                        <td class="text-end"><?= formatCurrency($data['min_wage_paid']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Maximum wages paid (highest)</td>
                        <td class="text-end"><?= formatCurrency($data['max_wage_paid']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Total PF contribution (employee share)</td>
                        <td class="text-end"><?= formatCurrency($data['pf_contributions']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Total ESI contribution (employee share)</td>
                        <td class="text-end"><?= formatCurrency($data['esi_contributions']) ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Section C: Welfare -->
            <h6 class="border-bottom border-2 pb-1 mb-3 mt-4 text-primary">
                <i class="bi bi-heart-pulse me-1"></i>Section C — Welfare, Safety & Accidents
            </h6>
            <table class="table table-sm table-bordered">
                <tbody>
                    <tr>
                        <td style="width:350px" class="bg-light fw-semibold">Welfare facilities provided (canteen, rest rooms, etc.)</td>
                        <td><em class="text-muted">As per establishment records</em></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Number of accidents during the year</td>
                        <td class="text-center"><?= $data['accidents_count'] ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Number of fatal accidents</td>
                        <td class="text-center"><?= $data['fatal_accidents'] ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">First-aid appliances provided</td>
                        <td><em class="text-muted">Available at site</em></td>
                    </tr>
                </tbody>
            </table>

            <!-- Section D: Overtime -->
            <h6 class="border-bottom border-2 pb-1 mb-3 mt-4 text-primary">
                <i class="bi bi-clock-history me-1"></i>Section D — Overtime & Penalties
            </h6>
            <table class="table table-sm table-bordered">
                <tbody>
                    <tr>
                        <td style="width:350px" class="bg-light fw-semibold">Total overtime hours worked</td>
                        <td class="text-center"><?= number_format($data['total_ot_hours'], 2) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Total overtime wages paid (approx.)</td>
                        <td class="text-end"><?= formatCurrency($data['ot_amount']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Penalties / fines imposed during the year</td>
                        <td class="text-end"><?= formatCurrency($data['penalties_paid']) ?></td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-semibold">Prosecutions launched / convictions secured</td>
                        <td class="text-center"><?= $data['prosecutions_count'] ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Section E: Monthly Breakdown -->
            <?php if (!empty($data['periods'])): ?>
            <h6 class="border-bottom border-2 pb-1 mb-3 mt-4 text-primary">
                <i class="bi bi-calendar3 me-1"></i>Section E — Monthly Breakdown (<?= $filterYear ?>)
            </h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-center">Employees</th>
                            <th class="text-center">Man Days</th>
                            <th class="text-end">Gross Wages</th>
                            <th class="text-center">OT Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                                       7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                        foreach ($data['periods'] as $p):
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($monthNames[intval($p['month'])] ?? 'Month ' . $p['month']) ?></td>
                            <td class="text-center"><?= intval($p['emp_count']) ?></td>
                            <td class="text-center"><?= number_format(intval($p['total_days'])) ?></td>
                            <td class="text-end"><?= formatCurrency($p['gross']) ?></td>
                            <td class="text-center"><?= number_format(floatval($p['ot_hrs']), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Declaration -->
            <div class="mt-4 p-3 border rounded bg-light">
                <p class="mb-2 fw-semibold">Declaration:</p>
                <p class="mb-2 small">
                    We hereby declare that the above return is true and correct to the best of our knowledge and belief,
                    and that no workman whose name is required to be entered in the register under Rule 76 has been
                    employed by us during the year <?= $filterYear ?> except those mentioned in the return.
                </p>
                <div class="row mt-4 pt-3">
                    <div class="col-4 text-center">
                        <hr class="mt-4">
                        <small>Signature of Contractor</small>
                    </div>
                    <div class="col-4 text-center">
                        <hr class="mt-4">
                        <small>Date & Place</small>
                    </div>
                    <div class="col-4 text-center">
                        <hr class="mt-4">
                        <small>Designation & Seal</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header.bg-dark { background: #222 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bg-light { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .text-primary { color: #333 !important; }
    body { font-size: 10px; }
    .table { font-size: 9px; }
    .container-fluid { padding: 0 !important; }
}
</style>
