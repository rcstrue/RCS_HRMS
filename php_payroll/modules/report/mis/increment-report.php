<?php
$pageTitle = 'Increment Report';

$year = (int)($_GET['year'] ?? date('Y'));
$clientId = (int)($_GET['client_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? 0);
$typeFilter = sanitize($_GET['type'] ?? '');

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

// Fetch salary revisions
$params = [];
$where = ["YEAR(sr.effective_from) = ?"];
$params[] = $year;

if ($clientId > 0) {
    $where[] = "e.client_id = ?";
    $params[] = $clientId;
}
if ($unitId > 0) {
    $where[] = "e.unit_id = ?";
    $params[] = $unitId;
}
if ($typeFilter) {
    $where[] = "sr.revision_type = ?";
    $params[] = $typeFilter;
}

$whereClause = implode(' AND ', $where);
$rows = [];

try {
    $rows = $db->fetchAll("
        SELECT sr.*, e.employee_code, e.full_name, e.designation, e.client_id, e.unit_id,
               c.name as client_name, u.name as unit_name
        FROM salary_revisions sr
        JOIN employees e ON e.id = sr.employee_id
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE {$whereClause}
        ORDER BY sr.effective_from DESC, e.employee_code
    ", $params);
} catch (Exception $e) {
    $error = $e->getMessage();
    $rows = [];
}

// Summary calculations
$totalIncrements = count($rows);
$totalIncrease = 0;
$totalOldGross = 0;
$totalNewGross = 0;

foreach ($rows as $row) {
    $diff = $row['new_gross'] - $row['old_gross'];
    $totalIncrease += $diff;
    $totalOldGross += $row['old_gross'];
    $totalNewGross += $row['new_gross'];
}
$avgHikePct = $totalOldGross > 0 ? round(($totalIncrease / $totalOldGross) * 100, 1) : 0;
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
        <input type="hidden" name="page" value="report/mis/increment-report">
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
            <label class="form-label">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="annual" <?= $typeFilter === 'annual' ? 'selected' : '' ?>>Annual</option>
                <option value="promotion" <?= $typeFilter === 'promotion' ? 'selected' : '' ?>>Promotion</option>
                <option value="correction" <?= $typeFilter === 'correction' ? 'selected' : '' ?>>Correction</option>
                <option value="special" <?= $typeFilter === 'special' ? 'selected' : '' ?>>Special</option>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php elseif (empty($rows)): ?>
        <div class="alert alert-info">No salary revisions found for <?= $year ?>.</div>
    <?php else: ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card p-3 text-center bg-primary bg-opacity-10">
                <h6 class="text-muted mb-1">Total Increments</h6>
                <h3 class="text-primary mb-0"><?= $totalIncrements ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-success bg-opacity-10">
                <h6 class="text-muted mb-1">Total Increase Amount</h6>
                <h3 class="text-success mb-0"><?= formatCurrency($totalIncrease) ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-info bg-opacity-10">
                <h6 class="text-muted mb-1">Average Hike %</h6>
                <h3 class="text-info mb-0"><?= $avgHikePct ?>%</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 text-center bg-warning bg-opacity-10">
                <h6 class="text-muted mb-1">Old Gross Total</h6>
                <h5 class="text-dark mb-0"><?= formatCurrency($totalOldGross) ?></h5>
                <small>→ <?= formatCurrency($totalNewGross) ?> (New)</small>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card mb-3">
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
                            <th class="text-end">Old Basic</th>
                            <th class="text-end">New Basic</th>
                            <th class="text-end">Old Gross</th>
                            <th class="text-end">New Gross</th>
                            <th class="text-end">Difference</th>
                            <th class="text-end">Hike %</th>
                            <th>Type</th>
                            <th>Effective Date</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($rows as $row):
                            $diff = $row['new_gross'] - $row['old_gross'];
                            $hikePct = $row['old_gross'] > 0 ? round(($diff / $row['old_gross']) * 100, 1) : 0;
                            $typeBadge = 'secondary';
                            if ($row['revision_type'] === 'promotion') $typeBadge = 'success';
                            elseif ($row['revision_type'] === 'annual') $typeBadge = 'primary';
                            elseif ($row['revision_type'] === 'correction') $typeBadge = 'warning';
                            elseif ($row['revision_type'] === 'special') $typeBadge = 'info';
                        ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['designation']) ?></td>
                            <td><?= sanitize($row['client_name'] ?? '-') ?></td>
                            <td class="text-end"><?= formatCurrency($row['old_basic_da']) ?></td>
                            <td class="text-end"><?= formatCurrency($row['new_basic_da']) ?></td>
                            <td class="text-end"><?= formatCurrency($row['old_gross']) ?></td>
                            <td class="text-end"><?= formatCurrency($row['new_gross']) ?></td>
                            <td class="text-end fw-bold text-success">+<?= formatCurrency($diff) ?></td>
                            <td class="text-end text-success"><?= $hikePct ?>%</td>
                            <td><span class="badge bg-<?= $typeBadge ?>"><?= sanitize($row['revision_type'] ?? 'N/A') ?></span></td>
                            <td><?= formatDate($row['effective_from']) ?></td>
                            <td><?= sanitize($row['remarks'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="7" class="text-end">TOTAL</td>
                            <td class="text-end"><?= formatCurrency($totalOldGross) ?></td>
                            <td class="text-end"><?= formatCurrency($totalNewGross) ?></td>
                            <td class="text-end fw-bold">+<?= formatCurrency($totalIncrease) ?></td>
                            <td class="text-end"><?= $avgHikePct ?>%</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
