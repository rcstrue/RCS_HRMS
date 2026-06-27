<?php
if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }
// modules/expense/approvals.php
// Admin page for approving/rejecting expense entries submitted by managers.

$pageTitle = 'Expense Approvals';

// Shared auto-migration & helpers
require_once __DIR__ . '/expense-setup.php';

// ─── POST Actions ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = sanitize($_POST['action']);

    // 1. Approve single entry
    if ($action === 'approve') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->update(
                'ess_expenses',
                [
                    'status'      => 'approved',
                    'approved_by' => 'admin',
                    'approved_at' => date('Y-m-d H:i:s'),
                ],
                'id = :id',
                ['id' => $id]
            );
            setFlash('success', 'Expense entry approved successfully.');
        }
        redirect('index.php?page=expense/approvals');
    }

    // 2. Reject single entry (rejection_reason required)
    if ($action === 'reject') {
        $id     = (int) ($_POST['id'] ?? 0);
        $reason = sanitize(trim($_POST['rejection_reason'] ?? ''));
        if ($id > 0 && $reason !== '') {
            $db->update(
                'ess_expenses',
                [
                    'status'           => 'rejected',
                    'rejected_by'      => 'admin',
                    'rejection_reason' => $reason,
                ],
                'id = :id',
                ['id' => $id]
            );
            setFlash('success', 'Expense entry rejected.');
        } elseif ($id > 0 && $reason === '') {
            setFlash('danger', 'Rejection reason is required.');
        }
        redirect('index.php?page=expense/approvals');
    }

    // 3. Edit entry (amount & description) – stay on same page
    if ($action === 'edit_entry') {
        $id   = (int) ($_POST['id'] ?? 0);
        $amt  = floatval($_POST['amount'] ?? 0);
        $desc = sanitize(trim($_POST['description'] ?? ''));
        if ($id > 0 && $amt >= 0) {
            $db->update(
                'ess_expenses',
                [
                    'amount'    => $amt,
                    'description' => $desc,
                    'edited_by' => 'admin',
                    'edited_at' => date('Y-m-d H:i:s'),
                ],
                'id = :id',
                ['id' => $id]
            );
            setFlash('success', 'Expense entry updated.');
        }
        redirect('index.php?page=expense/approvals');
    }

    // 4. Bulk approve
    if ($action === 'bulk_approve') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids) && is_array($ids)) {
            $count = 0;
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $db->update(
                        'ess_expenses',
                        [
                            'status'      => 'approved',
                            'approved_by' => 'admin',
                            'approved_at' => date('Y-m-d H:i:s'),
                        ],
                        'id = :id AND status = :status',
                        ['id' => $id, 'status' => 'pending']
                    );
                    $count++;
                }
            }
            setFlash('success', "{$count} expense(s) approved.");
        } else {
            setFlash('warning', 'No entries selected for approval.');
        }
        redirect('index.php?page=expense/approvals');
    }
}

// Column flags already set by expense-setup.php

// ─── GET Filters ────────────────────────────────────────────────────────────────

$fStatus   = sanitize($_GET['status'] ?? 'all');
$fCategory = sanitize($_GET['category'] ?? 'all');
$fManager  = sanitize($_GET['manager'] ?? '');
$fMonth    = sanitize($_GET['month'] ?? '');
$fYear     = sanitize($_GET['year'] ?? '');
$fSearch   = sanitize(trim($_GET['search'] ?? ''));

// ─── Build WHERE clause dynamically ─────────────────────────────────────────────

$where  = ['1=1'];
$params = [];

if ($fStatus !== 'all' && in_array($fStatus, ['pending', 'approved', 'rejected', 'reimbursed'], true)) {
    $where[]  = 'e.status = :status';
    $params['status'] = $fStatus;
}

if ($categoryColExists && $fCategory !== 'all' && in_array($fCategory, ['advance', 'expense', 'employee_advance'], true)) {
    $where[]     = 'e.category = :category';
    $params['category'] = $fCategory;
}

if ($fManager !== '') {
    if ($managerIdColExists) {
        $where[] = '(e.employee_id = :manager OR e.manager_id = :manager2)';
        $params['manager'] = $fManager;
        $params['manager2'] = $fManager;
    } else {
        $where[] = 'e.employee_id = :manager';
        $params['manager'] = $fManager;
    }
}

if ($monthColExists && $fMonth !== '') {
    $m = (int) $fMonth;
    if ($m >= 1 && $m <= 12) {
        $where[]   = 'e.month = :month';
        $params['month'] = $m;
    }
}

if ($monthColExists && $fYear !== '') {
    $y = (int) $fYear;
    if ($y >= 2000 && $y <= 2099) {
        $where[]  = 'e.year = :year';
        $params['year'] = $y;
    }
}

if ($fSearch !== '') {
    $searchConds = ['e.description LIKE :search1', 'ec.full_name LIKE :search2'];
    $params['search1'] = "%{$fSearch}%";
    $params['search2'] = "%{$fSearch}%";
    if ($categoryColExists) {
        $searchConds[] = 'e.emp_name LIKE :search3';
        $params['search3'] = "%{$fSearch}%";
    }
    $where[] = '(' . implode(' OR ', $searchConds) . ')';
}

$whereSql = implode(' AND ', $where);

// ─── Fetch expenses ─────────────────────────────────────────────────────────────

$sql = "SELECT e.*, ec.full_name AS manager_name, ec.mobile_number, ec.role, ec.designation
        FROM ess_expenses e
        LEFT JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
        WHERE {$whereSql}
        ORDER BY e.created_at DESC";

$expenses = $db->fetchAll($sql, $params);

// ─── Manager list for filter dropdown ───────────────────────────────────────────

$managers = $db->fetchAll(
    "SELECT DISTINCT ec.employee_id, ec.full_name
     FROM ess_expenses e
     JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
     ORDER BY ec.full_name ASC"
);

// ─── Summary calculations ───────────────────────────────────────────────────────

$summaryTotal   = 0;
$summaryCounts  = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'reimbursed' => 0];
$summaryAmounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'reimbursed' => 0];

foreach ($expenses as $exp) {
    $summaryTotal += (float) $exp['amount'];
    $st = $exp['status'];
    if (isset($summaryCounts[$st])) {
        $summaryCounts[$st]++;
        $summaryAmounts[$st] += (float) $exp['amount'];
    }
}

// ─── Helper functions for view ──────────────────────────────────────────────────

function categoryBadge($cat): string
{
    if (empty($cat)) $cat = 'expense';
    $map = [
        'advance'          => 'primary',
        'expense'          => 'info',
        'employee_advance' => 'warning',
    ];
    $color = $map[$cat] ?? 'secondary';
    $label = str_replace('_', ' ', ucfirst($cat ?? 'expense'));
    return "<span class=\"badge bg-{$color}\">{$label}</span>";
}

function typeBadge($type): string
{
    if (empty($type)) $type = 'other';
    $map = [
        'travel'   => 'primary',
        'food'     => 'success',
        'cab'      => 'info',
        'supplies' => 'secondary',
        'medical'  => 'danger',
        'other'    => 'dark',
    ];
    $color = $map[$type] ?? 'secondary';
    return "<span class=\"badge bg-{$color}\">" . ucfirst($type ?? 'other') . "</span>";
}

function statusBadge($status): string
{
    $map = [
        'pending'    => 'warning',
        'approved'   => 'success',
        'rejected'   => 'danger',
        'reimbursed' => 'info',
    ];
    $color = $map[$status] ?? 'secondary';
    $label = ucfirst($status);
    return "<span class=\"badge bg-{$color}\">{$label}</span>";
}

// Base URL helper for filter links
$baseUrl = 'index.php?page=expense/approvals';
function filterUrl($baseUrl, $override = []): string
{
    $params = $_GET;
    foreach ($override as $k => $v) {
        if ($v === '' || $v === 'all') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return $baseUrl . (empty($params) ? '' : '&' . http_build_query($params));
}

// ─── Output HTML ────────────────────────────────────────────────────────────────
?>
<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="bi bi-clipboard-check me-2"></i><?= htmlspecialchars($pageTitle) ?></h3>
        <span class="badge bg-secondary fs-6"><?= count($expenses) ?> records</span>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="<?= htmlspecialchars($baseUrl) ?>" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="expense/approvals">

                <!-- Status -->
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="all" <?= $fStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $fStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $fStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="reimbursed" <?= $fStatus === 'reimbursed' ? 'selected' : '' ?>>Reimbursed</option>
                    </select>
                </div>

                <!-- Category -->
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="all" <?= $fCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                        <option value="advance" <?= $fCategory === 'advance' ? 'selected' : '' ?>>Advance</option>
                        <option value="expense" <?= $fCategory === 'expense' ? 'selected' : '' ?>>Expense</option>
                        <option value="employee_advance" <?= $fCategory === 'employee_advance' ? 'selected' : '' ?>>Employee Advance</option>
                    </select>
                </div>

                <!-- Manager -->
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Manager</label>
                    <select name="manager" class="form-select form-select-sm">
                        <option value="">All Managers</option>
                        <?php foreach ($managers as $m): ?>
                            <option value="<?= htmlspecialchars($m['employee_id']) ?>" <?= $fManager === (string)$m['employee_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Month -->
                <div class="col-md-1 col-sm-3">
                    <label class="form-label small fw-semibold">Month</label>
                    <select name="month" class="form-select form-select-sm">
                        <option value="">--</option>
                        <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                            <option value="<?= $mi ?>" <?= $fMonth === (string)$mi ? 'selected' : '' ?>>
                                <?= str_pad($mi, 2, '0', STR_PAD_LEFT) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Year -->
                <div class="col-md-1 col-sm-3">
                    <label class="form-label small fw-semibold">Year</label>
                    <select name="year" class="form-select form-select-sm">
                        <option value="">--</option>
                        <?php
                        $currentYear = (int) date('Y');
                        for ($yi = $currentYear + 1; $yi >= $currentYear - 3; $yi--): ?>
                            <option value="<?= $yi ?>" <?= $fYear === (string)$yi ? 'selected' : '' ?>>
                                <?= $yi ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Search -->
                <div class="col-md-2 col-sm-8">
                    <label class="form-label small fw-semibold">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Description / Name..."
                           value="<?= htmlspecialchars($fSearch) ?>">
                </div>

                <!-- Buttons -->
                <div class="col-md-2 col-sm-4 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                    <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary btn-sm" title="Clear filters">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($expenses)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                <p class="mb-0">No expense entries found matching the current filters.</p>
            </div>
        </div>
    <?php else: ?>

        <!-- Bulk Actions Bar -->
        <div class="d-flex justify-content-between align-items-center mb-3" id="bulkActionsBar">
            <div class="d-flex align-items-center gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selectAll">
                    <label class="form-check-label small fw-semibold" for="selectAll">Select All</label>
                </div>
                <span class="text-muted small" id="selectedCount">0 selected</span>
            </div>
            <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>" id="bulkApproveForm">
                <input type="hidden" name="action" value="bulk_approve">
                <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                <button type="button" class="btn btn-success btn-sm" id="bulkApproveBtn" disabled>
                    <i class="bi bi-check-circle me-1"></i> Approve Selected
                </button>
            </form>
        </div>

        <!-- Main Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0" id="expensesTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40px;" class="text-center"><input class="form-check-input" type="checkbox" id="selectAllHeader"></th>
                            <th style="width:40px;" class="text-center">#</th>
                            <th style="width:100px;">Date</th>
                            <th style="width:140px;">Manager</th>
                            <th style="width:100px;">Category</th>
                            <th style="width:90px;">Type</th>
                            <th style="width:110px;" class="text-end">Amount</th>
                            <th>Description</th>
                            <th style="width:50px;" class="text-center">Bill</th>
                            <th style="width:90px;" class="text-center">Status</th>
                            <th style="width:120px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $serial = 1; foreach ($expenses as $row):
                        $id            = (int) $row['id'];
                        $status        = $row['status'];
                        $billUrl       = $row['bill_url'] ?? '';
                        $isPending     = ($status === 'pending');
                        $descTruncated = mb_strlen($row['description']) > 50
                            ? mb_substr($row['description'], 0, 50) . '...'
                            : $row['description'];
                    ?>
                        <tr>
                            <!-- Checkbox -->
                            <td class="text-center">
                                <?php if ($isPending): ?>
                                    <input class="form-check-input row-checkbox" type="checkbox" name="selected_ids[]" value="<?= $id ?>">
                                <?php endif; ?>
                            </td>

                            <!-- Serial -->
                            <td class="text-center text-muted small"><?= $serial++ ?></td>

                            <!-- Date -->
                            <td>
                                <?= htmlspecialchars($row['expense_date'] ?? '') ?>
                                <br><small class="text-muted"><?= htmlspecialchars($row['month'] ?? '') . '/' . htmlspecialchars($row['year'] ?? '') ?></small>
                            </td>

                            <!-- Manager -->
                            <td>
                                <strong><?= htmlspecialchars($row['manager_name'] ?? $row['emp_name'] ?? 'N/A') ?></strong>
                                <?php if (!empty($row['designation'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($row['designation']) ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Category -->
                            <td><?= categoryBadge($row['category']) ?></td>

                            <!-- Type -->
                            <td><?= typeBadge($row['type']) ?></td>

                            <!-- Amount -->
                            <td class="text-end">
                                <strong><?= formatCurrency($row['amount']) ?></strong>
                            </td>

                            <!-- Description -->
                            <td title="<?= htmlspecialchars($row['description'] ?? '') ?>">
                                <?= htmlspecialchars($descTruncated) ?>
                            </td>

                            <!-- Bill -->
                            <td class="text-center">
                                <?php if (!empty($billUrl)): ?>
                                    <a href="<?= htmlspecialchars($billUrl) ?>" target="_blank" class="btn btn-outline-info btn-sm py-0 px-1" title="View Bill">
                                        <i class="bi bi-file-earmark-image"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status -->
                            <td class="text-center">
                                <?= statusBadge($status) ?>
                                <?php if (!empty($row['rejection_reason'])): ?>
                                    <br><small class="text-danger" title="<?= htmlspecialchars($row['rejection_reason'] ?? '') ?>">
                                        <i class="bi bi-chat-left-text"></i>
                                        <?= htmlspecialchars(mb_substr($row['rejection_reason'] ?? '', 0, 20)) ?>...
                                    </small>
                                <?php endif; ?>
                            </td>

                            <!-- Actions — Single Edit button -->
                            <td class="text-center">
                                <button type="button" class="btn btn-primary btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#editModal-<?= $id ?>" title="Edit / Actions">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Summary Footer -->
        <div class="card mt-3">
            <div class="card-body py-2">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <span class="fw-semibold">Total Displayed:</span>
                        <span class="fw-bold text-primary fs-5"><?= formatCurrency($summaryTotal) ?></span>
                        <span class="text-muted small">(<?= count($expenses) ?> entries)</span>
                    </div>
                    <div class="col-md-9">
                        <div class="d-flex flex-wrap gap-3 justify-content-end">
                            <span class="badge bg-warning text-dark fs-6 py-1 px-3">
                                Pending: <?= $summaryCounts['pending'] ?>
                                <small class="ms-1"><?= formatCurrency($summaryAmounts['pending']) ?></small>
                            </span>
                            <span class="badge bg-success fs-6 py-1 px-3">
                                Approved: <?= $summaryCounts['approved'] ?>
                                <small class="ms-1"><?= formatCurrency($summaryAmounts['approved']) ?></small>
                            </span>
                            <span class="badge bg-danger fs-6 py-1 px-3">
                                Rejected: <?= $summaryCounts['rejected'] ?>
                                <small class="ms-1"><?= formatCurrency($summaryAmounts['rejected']) ?></small>
                            </span>
                            <span class="badge bg-info fs-6 py-1 px-3">
                                Reimbursed: <?= $summaryCounts['reimbursed'] ?>
                                <small class="ms-1"><?= formatCurrency($summaryAmounts['reimbursed']) ?></small>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <!-- Bootstrap Modals for Edit (placed outside table, inside container) -->
    <?php foreach ($expenses as $row):
        $id     = (int) $row['id'];
        $status = $row['status'];
    ?>
    <div class="modal fade" id="editModal-<?= $id ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title mb-0"><i class="bi bi-pencil-square me-1 text-primary"></i>Edit Entry #<?= $id ?> &mdash; <?= formatCurrency($row['amount'] ?? 0) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>" id="editForm-<?= $id ?>">
                        <input type="hidden" name="action" value="edit_entry">
                        <input type="hidden" name="id" value="<?= $id ?>">

                        <div class="row g-2 mb-3">
                            <div class="col-sm-4">
                                <label class="form-label small fw-semibold mb-1">Amount</label>
                                <input type="number" name="amount" class="form-control form-control-sm"
                                       step="0.01" min="0" value="<?= htmlspecialchars($row['amount'] ?? 0) ?>" required>
                            </div>
                            <div class="col-sm-8">
                                <label class="form-label small fw-semibold mb-1">Description</label>
                                <input type="text" name="description" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($row['description'] ?? '') ?>" required>
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- Approve / Reject / Add Expense -->
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <?php if ($status !== 'approved'): ?>
                            <button type="button" class="btn btn-success btn-sm"
                                    onclick="quickApprove(<?= $id ?>)">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </button>
                            <?php endif; ?>

                            <?php if ($status !== 'rejected'): ?>
                            <button type="button" class="btn btn-danger btn-sm"
                                    onclick="toggleRejectInline(<?= $id ?>)">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                            <?php endif; ?>

                            <a href="index.php?page=expense/ledger&manager=<?= htmlspecialchars($row['employee_id'] ?? '') ?>#admin-forms"
                               class="btn btn-outline-info btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>Add Expense
                            </a>
                        </div>

                        <!-- Inline reject reason (hidden) -->
                        <div id="rejectInline-<?= $id ?>" class="d-none mb-2">
                            <textarea id="rejectReason-<?= $id ?>"
                                      class="form-control form-control-sm"
                                      rows="2" placeholder="Enter rejection reason..."></textarea>
                            <div class="d-flex gap-1 mt-1 justify-content-end">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="toggleRejectInline(<?= $id ?>)">Cancel</button>
                                <button type="button" class="btn btn-danger btn-sm"
                                        onclick="quickReject(<?= $id ?>)">Confirm Reject</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" form="editForm-<?= $id ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const allCheckboxes    = document.querySelectorAll('.row-checkbox');
    const selectAllHeader  = document.getElementById('selectAllHeader');
    const selectAllBelow   = document.getElementById('selectAll');
    const selectedCountEl  = document.getElementById('selectedCount');
    const bulkApproveBtn   = document.getElementById('bulkApproveBtn');
    const selectedIdsInput = document.getElementById('selectedIdsInput');
    const bulkApproveForm  = document.getElementById('bulkApproveForm');

    function updateSelectedCount() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        const count   = checked.length;
        selectedCountEl.textContent = count + ' selected';
        bulkApproveBtn.disabled = (count === 0);
        const ids = Array.from(checked).map(cb => cb.value);
        selectedIdsInput.value = ids.join(',');
    }

    function syncSelectAll() {
        const total  = allCheckboxes.length;
        const checked = document.querySelectorAll('.row-checkbox:checked').length;
        const state  = (checked === total && total > 0);
        if (selectAllHeader) selectAllHeader.checked = state;
        if (selectAllBelow)  selectAllBelow.checked  = state;
    }

    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function () {
            allCheckboxes.forEach(cb => { cb.checked = this.checked; });
            syncSelectAll();
            updateSelectedCount();
        });
    }

    if (selectAllBelow) {
        selectAllBelow.addEventListener('change', function () {
            allCheckboxes.forEach(cb => { cb.checked = this.checked; });
            syncSelectAll();
            updateSelectedCount();
        });
    }

    allCheckboxes.forEach(cb => {
        cb.addEventListener('change', function () {
            syncSelectAll();
            updateSelectedCount();
        });
    });

    if (bulkApproveBtn) {
        bulkApproveBtn.addEventListener('click', function () {
            const count = document.querySelectorAll('.row-checkbox:checked').length;
            if (count === 0) return;
            if (!confirm('Approve ' + count + ' selected expense(s)?')) return;
            const existing = bulkApproveForm.querySelectorAll('input[name="selected_ids[]"]');
            existing.forEach(el => el.remove());
            document.querySelectorAll('.row-checkbox:checked').forEach(cb => {
                const input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = 'selected_ids[]';
                input.value = cb.value;
                bulkApproveForm.appendChild(input);
            });
            selectedIdsInput.remove();
            bulkApproveForm.submit();
        });
    }
});

// Toggle inline reject textarea inside modal
function toggleRejectInline(id) {
    const el = document.getElementById('rejectInline-' + id);
    if (el) el.classList.toggle('d-none');
}

// Quick approve via hidden form POST
function quickApprove(id) {
    if (!confirm('Approve this expense entry?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= htmlspecialchars($baseUrl) ?>';
    const a1 = document.createElement('input'); a1.type='hidden'; a1.name='action'; a1.value='approve';
    const a2 = document.createElement('input'); a2.type='hidden'; a2.name='id'; a2.value=id;
    form.appendChild(a1); form.appendChild(a2);
    document.body.appendChild(form);
    form.submit();
}

// Quick reject via hidden form POST
function quickReject(id) {
    const reasonEl = document.getElementById('rejectReason-' + id);
    const reason = reasonEl ? reasonEl.value.trim() : '';
    if (!reason) { alert('Please enter a rejection reason.'); reasonEl.focus(); return; }
    if (!confirm('Reject this expense entry?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= htmlspecialchars($baseUrl) ?>';
    const a1 = document.createElement('input'); a1.type='hidden'; a1.name='action'; a1.value='reject';
    const a2 = document.createElement('input'); a2.type='hidden'; a2.name='id'; a2.value=id;
    const a3 = document.createElement('input'); a3.type='hidden'; a3.name='rejection_reason'; a3.value=reason;
    form.appendChild(a1); form.appendChild(a2); form.appendChild(a3);
    document.body.appendChild(form);
    form.submit();
}
</script>
