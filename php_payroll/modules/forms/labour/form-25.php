<?php
/**
 * RCS HRMS Pro — Form 25: Register of Contract Workers
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Register of contract workers employed in an establishment
 */
$pageTitle = 'Form 25 - Register of Contract Workers';

// ── Fetch filter dropdowns ──────────────────────────────────────────
$years     = [];
$clients   = [];
$units     = [];
$currentYear = date('Y');

try {
    global $db;

    // Years from payroll_periods
    $stmt = $db->query("SELECT DISTINCT year FROM payroll_periods ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($years)) {
        for ($y = $currentYear; $y >= $currentYear - 5; $y--) $years[] = $y;
    }

    // Clients
    $stmt = $db->query("SELECT id, name FROM clients ORDER BY name ASC");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $years = range($currentYear, $currentYear - 5);
}

// ── Apply filters ───────────────────────────────────────────────────
$filterYear   = intval($_GET['year'] ?? ($years[0] ?? $currentYear));
$filterClient = intval($_GET['client_id'] ?? 0);
$filterUnit   = intval($_GET['unit_id'] ?? 0);

// ── Fetch units based on selected client ────────────────────────────
$filteredUnits = [];
if ($filterClient > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? ORDER BY name ASC");
        $stmt->execute([$filterClient]);
        $filteredUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
}

// ── Build and execute query ─────────────────────────────────────────
$workers = [];
$totalDaysMap = [];

try {
    $sql = "SELECT e.*, ess.gross_salary
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
            WHERE e.status = 'active'";

    $params = [];

    if ($filterYear > 0) {
        $sql .= " AND (YEAR(e.date_of_joining) <= ? OR e.date_of_joining IS NULL)";
        $params[] = $filterYear;
    }
    if ($filterClient > 0) {
        $sql .= " AND e.client_id = ?";
        $params[] = $filterClient;
    }
    if ($filterUnit > 0) {
        $sql .= " AND e.unit_id = ?";
        $params[] = $filterUnit;
    }

    $sql .= " ORDER BY e.employee_code ASC, e.full_name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total days from payroll for the year
    if ($filterYear > 0 && !empty($workers)) {
        $empIds = array_column($workers, 'id');
        $placeholders = implode(',', array_fill(0, count($empIds), '?'));

        $stmt2 = $db->prepare("
            SELECT p.employee_id, SUM(p.total_days) as total_days, SUM(p.paid_days) as paid_days
            FROM payroll p
            JOIN payroll_periods pp ON pp.id = p.payroll_period_id
            WHERE p.employee_id IN ($placeholders) AND pp.year = ?
            GROUP BY p.employee_id
        ");
        $allParams = array_merge($empIds, [$filterYear]);
        $stmt2->execute($allParams);
        $payrollRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payrollRows as $pr) {
            $totalDaysMap[$pr['employee_id']] = $pr;
        }
    }
} catch (Exception $e) {
    $error = 'Fetch failed: ' . $e->getMessage();
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'Form_25_Register_of_Contract_Workers_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Sl No', 'Emp Code', 'Name', 'Father Name', 'DOB', 'Designation',
        'Date of Joining', 'Date of Leaving', 'Wages (Gross)', 'Paid Days', 'Total Wages',
        'PF No', 'ESI No']);
    $sl = 1;
    foreach ($workers as $w) {
        $days = $totalDaysMap[$w['id']]['paid_days'] ?? 0;
        $grossWages = floatval($w['gross_salary'] ?? 0);
        $totalWages = $grossWages > 0 && $days > 0 ? round($grossWages * $days / 30, 2) : $grossWages;
        fputcsv($output, [$sl++, $w['employee_code'], $w['full_name'], $w['father_name'],
            formatDate($w['dob']), $w['designation'], formatDate($w['date_of_joining']),
            formatDate($w['date_of_leaving']), formatCurrency($grossWages), $days,
            formatCurrency($totalWages), $w['pf_number'] ?? '', $w['esic_number'] ?? '']);
    }
    fclose($output);
    exit;
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-people me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Rule 76</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?page=forms/labour/form-25&year=<?= $filterYear ?>&client_id=<?= $filterClient ?>&unit_id=<?= $filterUnit ?>&export=csv"
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
                <input type="hidden" name="page" value="forms/labour/form-25">
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
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Unit</label>
                    <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Units</option>
                        <?php foreach ($filteredUnits as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filterUnit == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="?page=forms/labour/form-25" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Workers Register Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-1"></i>Register of Contract Workers
                <span class="badge bg-secondary ms-2"><?= count($workers) ?></span></h6>
            <small class="text-muted">Year: <?= $filterYear ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th style="width:45px" class="text-center">Sl No</th>
                            <th style="width:75px">Emp Code</th>
                            <th>Name</th>
                            <th>Father Name</th>
                            <th style="width:90px">DOB</th>
                            <th>Designation</th>
                            <th style="width:90px">DOJ</th>
                            <th style="width:90px">DOL</th>
                            <th class="text-end" style="width:90px">Wages</th>
                            <th class="text-center" style="width:60px">Days</th>
                            <th class="text-end" style="width:100px">Total Wages</th>
                            <th style="width:110px">PF No</th>
                            <th style="width:100px">ESI No</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($workers)): ?>
                            <tr><td colspan="13" class="text-center text-muted py-3">No workers found for the selected filters.</td></tr>
                        <?php else: $sl = 1; $grandTotal = 0; foreach ($workers as $w):
                            $days = intval($totalDaysMap[$w['id']]['paid_days'] ?? 0);
                            $grossWages = floatval($w['gross_salary'] ?? 0);
                            $totalWages = $grossWages > 0 && $days > 0 ? round($grossWages * $days / 30, 2) : $grossWages;
                            $grandTotal += $totalWages;
                        ?>
                            <tr>
                                <td class="text-center"><?= $sl++ ?></td>
                                <td><?= htmlspecialchars($w['employee_code']) ?></td>
                                <td><strong><?= htmlspecialchars($w['full_name']) ?></strong></td>
                                <td><?= htmlspecialchars($w['father_name']) ?></td>
                                <td><?= formatDate($w['dob']) ?></td>
                                <td><?= htmlspecialchars($w['designation']) ?></td>
                                <td><?= formatDate($w['date_of_joining']) ?></td>
                                <td><?= $w['date_of_leaving'] ? formatDate($w['date_of_leaving']) : '<em class="text-success">Working</em>' ?></td>
                                <td class="text-end"><?= formatCurrency($grossWages) ?></td>
                                <td class="text-center"><?= $days ?: '-' ?></td>
                                <td class="text-end"><strong><?= formatCurrency($totalWages) ?></strong></td>
                                <td class="small"><?= htmlspecialchars($w['pf_number'] ?? '') ?></td>
                                <td class="small"><?= htmlspecialchars($w['esic_number'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="table-warning fw-bold">
                                <td colspan="10" class="text-end">Grand Total</td>
                                <td class="text-end"><?= formatCurrency($grandTotal) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-size: 10px; }
    .table { font-size: 9px; }
    .container-fluid { padding: 0 !important; }
}
</style>
