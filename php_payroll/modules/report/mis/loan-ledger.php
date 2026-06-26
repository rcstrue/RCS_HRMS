<?php
$pageTitle = 'Loan Ledger';

$statusFilter = sanitize($_GET['status'] ?? 'all');
$clientId = (int)($_GET['client_id'] ?? 0);

$clients = [];
try {
    $clients = $db->fetchAll("SELECT id, name FROM clients ORDER BY name");
} catch (Exception $e) {
    $clients = [];
}

// Fetch loans
$params = [];
$where = [];

if ($statusFilter !== 'all') {
    $where[] = "el.status = ?";
    $params[] = $statusFilter;
}
if ($clientId > 0) {
    $where[] = "e.client_id = ?";
    $params[] = $clientId;
}

$whereClause = implode(' AND ', $where);
$rows = [];

try {
    $rows = $db->fetchAll("
        SELECT el.*, e.employee_code, e.full_name, e.designation, e.client_id,
               c.name as client_name
        FROM employee_loans el
        JOIN employees e ON e.id = el.employee_id
        LEFT JOIN clients c ON c.id = e.client_id
        {$whereClause ? 'WHERE ' . $whereClause : ''}
        ORDER BY el.status, e.employee_code
    ", $params);
} catch (Exception $e) {
    $error = $e->getMessage();
    $rows = [];
}

// Summary
$totalLoans = count($rows);
$totalOutstanding = 0;
$totalEMI = 0;
foreach ($rows as $row) {
    $totalOutstanding += $row['balance_amount'];
    $totalEMI += $row['emi_amount'];
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
}
.emi-table { display: none; }
.emi-table.show { display: table; }
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/mis/loan-ledger">
        <div class="col-auto">
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
            </select>
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
        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary me-1"><i class="bi bi-search"></i> View</button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print</button>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php else: ?>

    <!-- Summary -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 text-center bg-primary bg-opacity-10">
                <h6 class="text-muted mb-1">Total Loans</h6>
                <h3 class="text-primary mb-0"><?= $totalLoans ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 text-center bg-danger bg-opacity-10">
                <h6 class="text-muted mb-1">Total Outstanding</h6>
                <h3 class="text-danger mb-0"><?= formatCurrency($totalOutstanding) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 text-center bg-warning bg-opacity-10">
                <h6 class="text-muted mb-1">Total Monthly EMI</h6>
                <h3 class="text-warning mb-0"><?= formatCurrency($totalEMI) ?></h3>
            </div>
        </div>
    </div>

    <!-- Loans Table -->
    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Emp Code</th>
                            <th>Name</th>
                            <th>Client</th>
                            <th>Loan Type</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Interest %</th>
                            <th>Tenure</th>
                            <th class="text-end">EMI</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th class="no-print">EMI Schedule</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($rows as $row):
                            $statusBadge = $row['status'] === 'active' ? 'success' : 'secondary';
                        ?>
                        <tr>
                            <td><?= $sno++ ?></td>
                            <td><?= sanitize($row['employee_code']) ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['client_name'] ?? '-') ?></td>
                            <td><?= sanitize($row['loan_type']) ?></td>
                            <td class="text-end"><?= formatCurrency($row['amount']) ?></td>
                            <td class="text-end"><?= $row['interest_rate'] ?>%</td>
                            <td><?= $row['tenure_months'] ?> mo</td>
                            <td class="text-end fw-bold"><?= formatCurrency($row['emi_amount']) ?></td>
                            <td class="text-end fw-bold <?= $row['balance_amount'] > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($row['balance_amount']) ?></td>
                            <td><span class="badge bg-<?= $statusBadge ?>"><?= ucfirst(sanitize($row['status'])) ?></span></td>
                            <td class="no-print">
                                <button type="button" class="btn btn-xs btn-outline-primary" onclick="toggleEmi(<?= $row['id'] ?>)">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- EMI Schedule (hidden by default) -->
                        <?php
                        $emiSchedule = [];
                        try {
                            $emiSchedule = $db->fetchAll("
                                SELECT * FROM loan_emi_log WHERE loan_id = ? ORDER BY emi_year, emi_month
                            ", [$row['id']]);
                        } catch (Exception $e) {
                            $emiSchedule = [];
                        }
                        ?>
                        <tr id="emi-<?= $row['id'] ?>" class="emi-table">
                            <td colspan="12" class="p-2 bg-light">
                                <?php if (empty($emiSchedule)): ?>
                                    <p class="text-muted small mb-0">No EMI schedule available.</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="table-secondary">
                                            <tr>
                                                <th>Month</th>
                                                <th>Year</th>
                                                <th class="text-end">EMI Amount</th>
                                                <th class="text-end">Principal</th>
                                                <th class="text-end">Interest</th>
                                                <th class="text-end">Balance After</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($emiSchedule as $emi): ?>
                                            <tr>
                                                <td><?= $emi['emi_month'] ?></td>
                                                <td><?= $emi['emi_year'] ?></td>
                                                <td class="text-end"><?= formatCurrency($emi['emi_amount']) ?></td>
                                                <td class="text-end"><?= formatCurrency($emi['principal']) ?></td>
                                                <td class="text-end"><?= formatCurrency($emi['interest']) ?></td>
                                                <td class="text-end"><?= formatCurrency($emi['balance_after']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">TOTAL</td>
                            <td class="text-end"><?= formatCurrency(array_sum(array_column($rows, 'amount'))) ?></td>
                            <td colspan="2"></td>
                            <td class="text-end fw-bold"><?= formatCurrency($totalEMI) ?></td>
                            <td class="text-end fw-bold"><?= formatCurrency($totalOutstanding) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleEmi(loanId) {
    var el = document.getElementById('emi-' + loanId);
    if (el) {
        el.classList.toggle('show');
    }
}
</script>
