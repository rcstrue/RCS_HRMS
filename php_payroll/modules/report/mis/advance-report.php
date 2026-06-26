<?php
$pageTitle = 'Advance Report';

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthName = $monthNames[$month] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? 0);

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="advance_report_' . sanitize($_GET['month'] ?? '0') . '_' . sanitize($_GET['year'] ?? date('Y')) . '.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Advance Report - ' . $monthName . ' ' . $year]);
    fputcsv($output, []);
    fputcsv($output, ['#', 'Emp Code', 'Name', 'Month', 'Year', 'Advance 1', 'Advance 2', 'Office Advance', 'Dress Advance', 'Total']);

    if (isset($rows)) {
        $sno = 1;
        foreach ($rows as $r) {
            fputcsv($output, [
                $sno++, $r['employee_code'], $r['full_name'], $r['month'], $r['year'],
                $r['advance_1'], $r['advance_2'], $r['office_advance'], $r['dress_advance'], $r['total_advance']
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['', '', '', '', 'TOTAL', $grandAdv1, $grandAdv2, $grandOffice, $grandDress, $grandTotal]);
    }

    fclose($output);
    exit;
}

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

// Fetch advances
$params = [$month, $year];
$where = ["ea.month = ?", "ea.year = ?"];

if ($clientId > 0) {
    $where[] = "e.client_id = ?";
    $params[] = $clientId;
}
if ($unitId > 0) {
    $where[] = "e.unit_id = ?";
    $params[] = $unitId;
}

$whereClause = implode(' AND ', $where);
$rows = [];

try {
    $rows = $db->fetchAll("
        SELECT ea.*, e.employee_code, e.full_name, e.designation, e.client_id, e.unit_id,
               c.name as client_name, u.name as unit_name,
               COALESCE(ea.advance_1, 0) + COALESCE(ea.advance_2, 0) + COALESCE(ea.office_advance, 0) + COALESCE(ea.dress_advance, 0) as total_advance
        FROM employee_advances ea
        JOIN employees e ON e.id = ea.employee_id
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE {$whereClause}
        ORDER BY e.employee_code
    ", $params);
} catch (Exception $e) {
    $error = $e->getMessage();
    $rows = [];
}

// Totals
$grandAdv1 = 0; $grandAdv2 = 0; $grandOffice = 0; $grandDress = 0; $grandTotal = 0;
foreach ($rows as $r) {
    $grandAdv1 += $r['advance_1'];
    $grandAdv2 += $r['advance_2'];
    $grandOffice += $r['office_advance'];
    $grandDress += $r['dress_advance'];
    $grandTotal += $r['total_advance'];
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

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/mis/advance-report">
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
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <a href="?page=report/mis/advance-report&month=<?= $month ?>&year=<?= $year ?>&client_id=<?= $clientId ?>&unit_id=<?= $unitId ?>&export=1" class="btn btn-sm btn-success"><i class="bi bi-download"></i> CSV</a>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php elseif (empty($rows)): ?>
        <div class="alert alert-info">No advance records found for <?= sanitize($monthName) ?> <?= $year ?>.</div>
    <?php else: ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card p-2 text-center bg-primary bg-opacity-10">
                <h6 class="text-muted mb-0 small">Employees</h6>
                <h5 class="text-primary mb-0"><?= count($rows) ?></h5>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card p-2 text-center bg-info bg-opacity-10">
                <h6 class="text-muted mb-0 small">Advance 1</h6>
                <h5 class="text-info mb-0"><?= formatCurrency($grandAdv1) ?></h5>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card p-2 text-center bg-success bg-opacity-10">
                <h6 class="text-muted mb-0 small">Advance 2</h6>
                <h5 class="text-success mb-0"><?= formatCurrency($grandAdv2) ?></h5>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card p-2 text-center bg-warning bg-opacity-10">
                <h6 class="text-muted mb-0 small">Office Adv</h6>
                <h5 class="text-dark mb-0"><?= formatCurrency($grandOffice) ?></h5>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card p-2 text-center bg-danger bg-opacity-10">
                <h6 class="text-muted mb-0 small">Dress Adv</h6>
                <h5 class="text-danger mb-0"><?= formatCurrency($grandDress) ?></h5>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card p-2 text-center bg-dark bg-opacity-10">
                <h6 class="text-muted mb-0 small">Grand Total</h6>
                <h5 class="text-dark mb-0"><?= formatCurrency($grandTotal) ?></h5>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card mb-3">
        <div class="card-header bg-dark text-white">
            <strong>Advance Details — <?= sanitize($monthName) ?> <?= $year ?></strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>#</th>
                            <th>Emp Code</th>
                            <th>Name</th>
                            <th>Designation</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th class="text-end">Advance 1</th>
                            <th class="text-end">Advance 2</th>
                            <th class="text-end">Office Advance</th>
                            <th class="text-end">Dress Advance</th>
                            <th class="text-end fw-bold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($rows as $row): ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['designation']) ?></td>
                            <td><?= sanitize($row['client_name'] ?? '-') ?></td>
                            <td><?= sanitize($row['unit_name'] ?? '-') ?></td>
                            <td class="text-end"><?= $row['advance_1'] > 0 ? formatCurrency($row['advance_1']) : '-' ?></td>
                            <td class="text-end"><?= $row['advance_2'] > 0 ? formatCurrency($row['advance_2']) : '-' ?></td>
                            <td class="text-end"><?= $row['office_advance'] > 0 ? formatCurrency($row['office_advance']) : '-' ?></td>
                            <td class="text-end"><?= $row['dress_advance'] > 0 ? formatCurrency($row['dress_advance']) : '-' ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency($row['total_advance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="6" class="text-end fw-bold">GRAND TOTAL (<?= count($rows) ?> Employees)</td>
                            <td class="text-end"><?= formatCurrency($grandAdv1) ?></td>
                            <td class="text-end"><?= formatCurrency($grandAdv2) ?></td>
                            <td class="text-end"><?= formatCurrency($grandOffice) ?></td>
                            <td class="text-end"><?= formatCurrency($grandDress) ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency($grandTotal) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
