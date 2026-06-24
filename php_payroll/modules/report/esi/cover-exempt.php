<?php
$pageTitle = 'ESI Cover & Exempt Report';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthName = $monthNames[$month] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? 0);

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="esi_cover_exempt_' . sanitize($_GET['month'] ?? '0') . '_' . sanitize($_GET['year'] ?? date('Y')) . '.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['ESI Cover & Exempt Report — ' . $monthName . ' ' . $year]);
    fputcsv($output, []);
    fputcsv($output, ['--- COVERED EMPLOYEES ---']);
    fputcsv($output, ['#', 'Emp Code', 'Name', 'Designation', 'Gross Salary', 'ESI No', 'Client', 'Unit']);

    if (isset($coveredRows)) {
        $sno = 1;
        foreach ($coveredRows as $r) {
            fputcsv($output, [$sno++, $r['employee_code'], $r['full_name'], $r['designation'], $r['gross_salary'], $r['esic_number'], $r['client_name'], $r['unit_name']]);
        }
    }

    fputcsv($output, []);
    fputcsv($output, ['--- EXEMPT EMPLOYEES ---']);
    fputcsv($output, ['#', 'Emp Code', 'Name', 'Designation', 'Gross Salary', 'Reason', 'Client', 'Unit']);

    if (isset($exemptRows)) {
        $sno = 1;
        foreach ($exemptRows as $r) {
            fputcsv($output, [$sno++, $r['employee_code'], $r['full_name'], $r['designation'], $r['gross_salary'], $r['reason'], $r['client_name'], $r['unit_name']]);
        }
    }

    fclose($output);
    exit;
}

// Fetch filter options
$clients = [];
$units = [];
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

// Build query
$params = [];
$where = ["e.status = 'active'"];

if ($clientId > 0) {
    $where[] = "e.client_id = ?";
    $params[] = $clientId;
}
if ($unitId > 0) {
    $where[] = "e.unit_id = ?";
    $params[] = $unitId;
}

$whereClause = implode(' AND ', $where);

// Covered employees: esi_applicable=1 AND gross_salary <= 21000
$coveredRows = [];
$exemptRows = [];

try {
    $coveredRows = $db->fetchAll("
        SELECT e.employee_code, e.full_name, e.father_name, e.designation, e.esic_number, e.client_id, e.unit_id,
               ess.gross_salary,
               c.name as client_name, u.name as unit_name
        FROM employees e
        LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
            AND ess.effective_from <= DATE('{$year}-{$month}-01')
            AND (ess.effective_to IS NULL OR ess.effective_to >= DATE('{$year}-{$month}-01'))
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE {$whereClause}
            AND ess.esi_applicable = 1 AND ess.gross_salary <= 21000
        ORDER BY e.employee_code
    ", $params);
} catch (Exception $e) {
    $coveredRows = [];
    $error = $e->getMessage();
}

try {
    $exemptRows = $db->fetchAll("
        SELECT e.employee_code, e.full_name, e.father_name, e.designation, e.esic_number, e.client_id, e.unit_id,
               ess.gross_salary, ess.esi_applicable,
               CASE
                   WHEN ess.esi_applicable = 0 THEN 'Not Applicable'
                   WHEN ess.gross_salary > 21000 THEN 'Above Wage Ceiling (₹21,000)'
                   ELSE 'Exempt'
               END as reason,
               c.name as client_name, u.name as unit_name
        FROM employees e
        LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
            AND ess.effective_from <= DATE('{$year}-{$month}-01')
            AND (ess.effective_to IS NULL OR ess.effective_to >= DATE('{$year}-{$month}-01'))
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE {$whereClause}
            AND (ess.esi_applicable = 0 OR ess.gross_salary > 21000 OR ess.esi_applicable IS NULL)
        ORDER BY e.employee_code
    ", $params);
} catch (Exception $e) {
    $exemptRows = [];
    if (!isset($error)) $error = $e->getMessage();
}

$totalAll = count($coveredRows) + count($exemptRows);
$coveredPercent = $totalAll > 0 ? round((count($coveredRows) / $totalAll) * 100, 1) : 0;
$exemptPercent = $totalAll > 0 ? round((count($exemptRows) / $totalAll) * 100, 1) : 0;
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
        <input type="hidden" name="page" value="report/esi/cover-exempt">
        <div class="col-auto">
            <label class="form-label">Month</label>
            <select name="month" class="form-select form-select-sm" id="monthSelect">
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
            <select name="client_id" class="form-select form-select-sm" id="clientSelect">
                <option value="">All Clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">Unit</label>
            <select name="unit_id" class="form-select form-select-sm" id="unitSelect">
                <option value="">All Units</option>
                <?php foreach ($units as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= sanitize($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <a href="?page=report/esi/cover-exempt&month=<?= $month ?>&year=<?= $year ?>&client_id=<?= $clientId ?>&unit_id=<?= $unitId ?>&export=1" class="btn btn-sm btn-success"><i class="bi bi-download"></i> CSV</a>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php else: ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card p-3 text-center bg-primary bg-opacity-10">
                <h6 class="text-muted mb-1">Total Employees</h6>
                <h3 class="text-primary mb-0"><?= $totalAll ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-success bg-opacity-10">
                <h6 class="text-muted mb-1">ESI Covered</h6>
                <h3 class="text-success mb-0"><?= count($coveredRows) ?></h3>
                <small class="text-muted"><?= $coveredPercent ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-danger bg-opacity-10">
                <h6 class="text-muted mb-1">ESI Exempt</h6>
                <h3 class="text-danger mb-0"><?= count($exemptRows) ?></h3>
                <small class="text-muted"><?= $exemptPercent ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center">
                <h6 class="text-muted mb-1">Coverage Ratio</h6>
                <div class="progress mt-2" style="height: 20px;">
                    <div class="progress-bar bg-success" style="width: <?= $coveredPercent ?>%"><?= $coveredPercent ?>%</div>
                    <div class="progress-bar bg-danger" style="width: <?= $exemptPercent ?>%"><?= $exemptPercent ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Covered Employees Table -->
    <div class="card mb-3">
        <div class="card-header bg-success text-white">
            <strong><i class="bi bi-check-circle me-1"></i> ESI Covered Employees (Gross ≤ ₹21,000 & ESI Applicable)</strong>
        </div>
        <div class="card-body p-0">
            <?php if (empty($coveredRows)): ?>
                <p class="text-muted p-3 mb-0">No covered employees found.</p>
            <?php else: ?>
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>#</th>
                            <th>Emp Code</th>
                            <th>Employee Name</th>
                            <th>Designation</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th>ESI No</th>
                            <th class="text-end">Gross Salary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($coveredRows as $row): ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['designation']) ?></td>
                            <td><?= sanitize($row['client_name'] ?? '-') ?></td>
                            <td><?= sanitize($row['unit_name'] ?? '-') ?></td>
                            <td><?= sanitize($row['esic_number'] ?? '-') ?></td>
                            <td class="text-end"><?= formatCurrency($row['gross_salary']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success">
                            <td colspan="7" class="text-end fw-bold">Total: <?= count($coveredRows) ?> Employees</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Exempt Employees Table -->
    <div class="card mb-3">
        <div class="card-header bg-danger text-white">
            <strong><i class="bi bi-x-circle me-1"></i> ESI Exempt Employees</strong>
        </div>
        <div class="card-body p-0">
            <?php if (empty($exemptRows)): ?>
                <p class="text-muted p-3 mb-0">No exempt employees found.</p>
            <?php else: ?>
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>#</th>
                            <th>Emp Code</th>
                            <th>Employee Name</th>
                            <th>Designation</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th class="text-end">Gross Salary</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($exemptRows as $row): ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['designation']) ?></td>
                            <td><?= sanitize($row['client_name'] ?? '-') ?></td>
                            <td><?= sanitize($row['unit_name'] ?? '-') ?></td>
                            <td class="text-end"><?= formatCurrency($row['gross_salary']) ?></td>
                            <td><span class="badge bg-danger"><?= sanitize($row['reason'] ?? 'Exempt') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-danger">
                            <td colspan="7" class="text-end fw-bold">Total: <?= count($exemptRows) ?> Employees</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>
