<?php
/**
 * RCS HRMS Pro — Form XX: Register of Wages
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Monthly wage register maintained by the contractor
 */
$pageTitle = 'Form XX - Register of Wages (CLRA)';

// ── Fetch filter options ────────────────────────────────────────────
$years       = [];
$months      = [];
$clients     = [];
$units       = [];
$company     = null;
$monthNames  = [1=>'January','February','March','April','May','June',
                'July','August','September','October','November','December'];

try {
    global $db;

    $stmt = $db->query("SELECT DISTINCT year FROM payroll_periods ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($years)) $years = [date('Y')];

    $stmt = $db->query("SELECT id, name FROM clients ORDER BY name ASC");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT * FROM companies LIMIT 1");
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get months for selected year
    $selYear = intval($_GET['year'] ?? ($years[0] ?? date('Y')));
    $stmt = $db->prepare("SELECT DISTINCT month FROM payroll_periods WHERE year = ? ORDER BY month ASC");
    $stmt->execute([$selYear]);
    $months = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($months)) $months = range(1, 12);
} catch (Exception $e) {
    $years = [date('Y')];
    $months = range(1, 12);
}

// ── Apply filters ───────────────────────────────────────────────────
$filterMonth = intval($_GET['month'] ?? (prev_month_num() > 1 ? prev_month_num() : 1));
$filterYear  = intval($_GET['year'] ?? ($years[0] ?? date('Y')));
$filterClient = intval($_GET['client_id'] ?? 0);
$filterUnit   = intval($_GET['unit_id'] ?? 0);

// ── Fetch units for selected client ─────────────────────────────────
$filteredUnits = [];
if ($filterClient > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? ORDER BY name ASC");
        $stmt->execute([$filterClient]);
        $filteredUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
}

// ── Fetch payroll data ──────────────────────────────────────────────
$wageData = [];
$periodInfo = null;
$totals = [
    'total_days' => 0, 'total_gross' => 0, 'total_pf' => 0,
    'total_esi' => 0, 'total_pt' => 0, 'total_deductions' => 0, 'total_net' => 0
];

try {
    // Find payroll period
    $stmt = $db->prepare("SELECT * FROM payroll_periods WHERE month = ? AND year = ? LIMIT 1");
    $stmt->execute([$filterMonth, $filterYear]);
    $periodInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($periodInfo) {
        $sql = "SELECT p.*, e.employee_code, e.full_name, e.father_name, e.designation,
                       ess.basic_da as salary_basic_da
                FROM payroll p
                JOIN employees e ON e.id = p.employee_id
                LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                WHERE p.payroll_period_id = ?";

        $params = [$periodInfo['id']];

        if ($filterClient > 0) {
            $sql .= " AND e.client_id = ?";
            $params[] = $filterClient;
        }
        if ($filterUnit > 0) {
            $sql .= " AND e.unit_id = ?";
            $params[] = $filterUnit;
        }

        $sql .= " ORDER BY e.employee_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $wageData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate totals
        foreach ($wageData as $row) {
            $totals['total_days']       += intval($row['paid_days'] ?? 0);
            $totals['total_gross']       += floatval($row['gross_earnings'] ?? 0);
            $totals['total_pf']          += floatval($row['pf_employee'] ?? 0);
            $totals['total_esi']         += floatval($row['esi_employee'] ?? 0);
            $totals['total_pt']          += floatval($row['professional_tax'] ?? 0);
            $totals['total_deductions']  += floatval($row['total_deductions'] ?? 0);
            $totals['total_net']         += floatval($row['net_pay'] ?? 0);
        }
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'Form_XX_Register_of_Wages_' . $monthNames[$filterMonth] . '_' . $filterYear . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['FORM XX - REGISTER OF WAGES (CLRA)']);
    fputcsv($output, ['Period', $monthNames[$filterMonth] . ' ' . $filterYear]);
    fputcsv($output, []);
    fputcsv($output, ['Sl No', 'Emp Code', 'Name', 'Designation', 'Attendance Days',
        'Basic', 'DA', 'OT', 'Gross', 'PF', 'ESI', 'PT', 'Advance', 'Total Ded', 'Net Pay', 'Payment Date']);

    $sl = 1;
    foreach ($wageData as $row) {
        fputcsv($output, [$sl++, $row['employee_code'], $row['full_name'], $row['designation'],
            intval($row['paid_days'] ?? 0), formatCurrency($row['basic_da'] ?? 0),
            '-', // DA separate not available in payroll; included in basic_da
            '-', // OT amount not separately tracked
            formatCurrency($row['gross_earnings']),
            formatCurrency($row['pf_employee']), formatCurrency($row['esi_employee']),
            formatCurrency($row['professional_tax']), '-', // Advance
            formatCurrency($row['total_deductions']), formatCurrency($row['net_pay']),
            formatDate(date('Y-m-d')) // Approx payment date
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['', '', '', 'TOTALS', $totals['total_days'],
        '', '', '', formatCurrency($totals['total_gross']),
        formatCurrency($totals['total_pf']), formatCurrency($totals['total_esi']),
        formatCurrency($totals['total_pt']), '',
        formatCurrency($totals['total_deductions']), formatCurrency($totals['total_net']), '']);

    fclose($output);
    exit;
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-cash-coin me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Rule 79</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?page=forms/labour/form-xx&month=<?= $filterMonth ?>&year=<?= $filterYear ?>&client_id=<?= $filterClient ?>&unit_id=<?= $filterUnit ?>&export=csv"
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

    <!-- Filter Form -->
    <div class="card mb-3 no-print">
        <div class="card-body py-2">
            <form method="GET" action="" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="forms/labour/form-xx">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Month</label>
                    <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($months as $m): ?>
                            <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>>
                                <?= $monthNames[intval($m)] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <label class="form-label form-label-sm">Unit</label>
                    <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Units</option>
                        <?php foreach ($filteredUnits as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filterUnit == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-funnel"></i>
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="?page=forms/labour/form-xx" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Period Header -->
    <div class="alert alert-light border d-flex justify-content-between align-items-center">
        <span>
            <strong>Period:</strong> <?= htmlspecialchars($monthNames[$filterMonth] . ' ' . $filterYear) ?>
            <?php if ($company): ?>
                &nbsp;|&nbsp; <strong>Contractor:</strong> <?= htmlspecialchars($company['company_name']) ?>
            <?php endif; ?>
        </span>
        <span class="badge bg-primary"><?= count($wageData) ?> Employees</span>
    </div>

    <!-- Wage Register Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-table me-1"></i>Register of Wages</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 75vh; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th style="width:40px" class="text-center">Sl</th>
                            <th style="width:70px">Emp Code</th>
                            <th>Name</th>
                            <th style="width:120px">Designation</th>
                            <th style="width:55px" class="text-center">Days</th>
                            <th style="width:85px" class="text-end">Basic</th>
                            <th style="width:85px" class="text-end">DA</th>
                            <th style="width:55px" class="text-center">OT Hrs</th>
                            <th style="width:95px" class="text-end">Gross</th>
                            <th style="width:75px" class="text-end">PF</th>
                            <th style="width:75px" class="text-end">ESI</th>
                            <th style="width:65px" class="text-end">PT</th>
                            <th style="width:75px" class="text-end">Adv</th>
                            <th style="width:85px" class="text-end">Tot Ded</th>
                            <th style="width:95px" class="text-end">Net Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($wageData)): ?>
                            <tr>
                                <td colspan="15" class="text-center text-muted py-3">
                                    <?php if (!$periodInfo): ?>
                                        No payroll period found for <?= $monthNames[$filterMonth] ?> <?= $filterYear ?>.
                                    <?php else: ?>
                                        No wage data found for the selected filters.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: $sl = 1; foreach ($wageData as $row): ?>
                            <tr>
                                <td class="text-center"><?= $sl++ ?></td>
                                <td><?= htmlspecialchars($row['employee_code']) ?></td>
                                <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                                <td class="small"><?= htmlspecialchars($row['designation']) ?></td>
                                <td class="text-center"><?= intval($row['paid_days'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($row['basic_da']) ?></td>
                                <td class="text-end">—</td>
                                <td class="text-center"><?= floatval($row['overtime_hours'] ?? 0) > 0 ? number_format(floatval($row['overtime_hours']), 1) : '—' ?></td>
                                <td class="text-end"><?= formatCurrency($row['gross_earnings']) ?></td>
                                <td class="text-end"><?= formatCurrency($row['pf_employee']) ?></td>
                                <td class="text-end"><?= formatCurrency($row['esi_employee']) ?></td>
                                <td class="text-end"><?= formatCurrency($row['professional_tax']) ?></td>
                                <td class="text-end">—</td>
                                <td class="text-end"><?= formatCurrency($row['total_deductions']) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($row['net_pay']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <!-- Totals Row -->
                            <tr class="table-dark text-white fw-bold">
                                <td colspan="4" class="text-center">TOTALS</td>
                                <td class="text-center"><?= $totals['total_days'] ?></td>
                                <td></td><td></td><td></td>
                                <td class="text-end"><?= formatCurrency($totals['total_gross']) ?></td>
                                <td class="text-end"><?= formatCurrency($totals['total_pf']) ?></td>
                                <td class="text-end"><?= formatCurrency($totals['total_esi']) ?></td>
                                <td class="text-end"><?= formatCurrency($totals['total_pt']) ?></td>
                                <td></td>
                                <td class="text-end"><?= formatCurrency($totals['total_deductions']) ?></td>
                                <td class="text-end"><?= formatCurrency($totals['total_net']) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Footer Note -->
    <div class="mt-3 small text-muted">
        <p class="mb-1"><em>Note: DA is included in Basic wages as per the salary structure. Advance recovery not tracked in payroll.</em></p>
        <p class="mb-0">Amounts in ₹ (Indian Rupees). Generated on <?= date('d-m-Y H:i') ?>.</p>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .alert { border: none !important; background: none !important; padding: 0 !important; }
    body { font-size: 9px; }
    .table { font-size: 8px; }
    .container-fluid { padding: 0 !important; }
}
</style>
