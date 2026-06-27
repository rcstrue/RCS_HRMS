<?php
if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }
$pageTitle = 'Advance Allocation';

// Shared auto-migration & helpers
require_once __DIR__ . '/expense-setup.php';

$allocatePageUrl = 'index.php?page=expense/allocations';

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$monthShort = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
    5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
    9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
];

// ─── Selected Month/Year (default to current) ─────────────────────────────
$selectedMonth = (int)($_GET['month'] ?? prev_month_num());
$selectedYear  = (int)($_GET['year'] ?? date('Y'));
if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = (int)prev_month_num();
if ($selectedYear < 2000 || $selectedYear > 2099) $selectedYear = (int)date('Y');

// ─── POST Handlers ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    // ── Allocate Advance (Month-wise) ─────────────────────────────────────
    if ($action === 'allocate_advance') {
        $manager_id = sanitize(trim($_POST['manager_id'] ?? ''));
        $amount     = floatval($_POST['amount'] ?? 0);
        $remarks    = sanitize(trim($_POST['remarks'] ?? ''));
        $allocMonth = (int)($_POST['alloc_month'] ?? 0);
        $allocYear  = (int)($_POST['alloc_year'] ?? 0);

        if ($manager_id === '' || $amount <= 0 || $allocMonth < 1 || $allocMonth > 12 || $allocYear < 2000) {
            setFlash('error', 'Please select a manager, month/year, and enter a positive amount.');
            redirect($allocatePageUrl . '&month=' . $allocMonth . '&year=' . $allocYear);
        }

        $data = [
            'manager_id'   => $manager_id,
            'amount'       => $amount,
            'month'        => $allocMonth,
            'year'         => $allocYear,
            'remarks'      => $remarks,
            'allocated_by' => $_SESSION['user_id'] ?? 'system',
        ];

        $db->insert('manager_advance_allocations', $data);

        setFlash('success', 'Advance of ' . number_format($amount, 2) . ' allocated for ' . $monthNames[$allocMonth] . ' ' . $allocYear . '.');
        redirect($allocatePageUrl . '&month=' . $allocMonth . '&year=' . $allocYear);
    }

    // ── Edit Allocation ──────────────────────────────────────────────────
    if ($action === 'edit_allocation') {
        $alloc_id = intval($_POST['alloc_id'] ?? 0);
        $amount   = floatval($_POST['amount'] ?? 0);
        $remarks  = sanitize(trim($_POST['remarks'] ?? ''));

        if ($alloc_id > 0 && $amount > 0) {
            $db->update(
                'manager_advance_allocations',
                [
                    'amount'  => $amount,
                    'remarks' => $remarks,
                ],
                'id = :id',
                ['id' => $alloc_id]
            );
            setFlash('success', 'Allocation updated successfully.');
        } else {
            setFlash('error', 'Invalid allocation or amount.');
        }

        redirect($allocatePageUrl . '&month=' . $selectedMonth . '&year=' . $selectedYear);
    }

    // ── Delete Allocation ────────────────────────────────────────────────
    if ($action === 'delete_allocation') {
        $alloc_id = intval($_POST['alloc_id'] ?? 0);
        if ($alloc_id > 0) {
            $db->query("DELETE FROM manager_advance_allocations WHERE id = :id", ['id' => $alloc_id]);
            setFlash('success', 'Allocation deleted successfully.');
        }
        redirect($allocatePageUrl . '&month=' . $selectedMonth . '&year=' . $selectedYear);
    }

    // Unknown action
    setFlash('error', 'Unknown action.');
    redirect($allocatePageUrl);
}

// ─── GET: Fetch Data ──────────────────────────────────────────────────────────

// Managers for the dropdown — all employees except Workers
try {
    $managers = $db->fetchAll(
        "SELECT e.id AS employee_id, e.full_name, e.designation, u.name AS unit_name, u.city
         FROM employees e
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.status = 'approved'
         AND e.designation NOT LIKE '%Worker%' AND e.designation NOT LIKE '%worker%'
         ORDER BY e.full_name ASC"
    );
} catch (Exception $e) {
    // Fallback to ess_employee_cache if employees table query fails
    try {
        $managers = $db->fetchAll(
            "SELECT employee_id, full_name, designation, unit_name, city
             FROM ess_employee_cache
             WHERE designation NOT LIKE '%Worker%' AND designation NOT LIKE '%worker%'
             ORDER BY full_name ASC"
        );
    } catch (Exception $e2) {
        $managers = [];
    }
}

// Monthly allocations for the selected month/year
try {
    $allocations = $db->fetchAll(
        "SELECT ma.*, ec.full_name, ec.mobile_number, ec.designation, ec.unit_name, ec.city
         FROM manager_advance_allocations ma
         LEFT JOIN ess_employee_cache ec ON ma.manager_id = ec.employee_id
         WHERE ma.month = :m AND ma.year = :y
         ORDER BY ma.created_at DESC",
        ['m' => $selectedMonth, 'y' => $selectedYear]
    );
} catch (Exception $e) {
    // If month/year columns don't exist yet, fallback to date-based filtering
    try {
        $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
        $endDate   = sprintf('%04d-%02d-31', $selectedYear, $selectedMonth);
        $allocations = $db->fetchAll(
            "SELECT ma.*, ec.full_name, ec.mobile_number, ec.designation, ec.unit_name, ec.city
             FROM manager_advance_allocations ma
             LEFT JOIN ess_employee_cache ec ON ma.manager_id = ec.employee_id
             WHERE DATE(ma.created_at) >= :sd AND DATE(ma.created_at) <= :ed
             ORDER BY ma.created_at DESC",
            ['sd' => $startDate, 'ed' => $endDate]
        );
    } catch (Exception $e2) {
        $allocations = [];
    }
}

// ─── Monthly Summary Per Manager (Allocated vs Spent) ────────────────────

$managerSummary = [];
$monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$monthEnd   = sprintf('%04d-%02d-31', $selectedYear, $selectedMonth);

foreach ($managers as $mgr) {
    $mid = $mgr['employee_id'];

    // Total advance allocated this month
    $allocated = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations
         WHERE manager_id = :mid AND month = :m AND year = :y",
        ['mid' => $mid, 'm' => $selectedMonth, 'y' => $selectedYear]
    );

    // Total expenses spent this month (approved)
    $spent = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
         WHERE manager_id = :mid1 AND category IN ('expense','employee_advance') AND status = 'approved'
         AND COALESCE(expense_date, DATE(created_at)) >= :sd AND COALESCE(expense_date, DATE(created_at)) <= :ed",
        ['mid1' => $mid, 'sd' => $monthStart, 'ed' => $monthEnd]
    );

    // Overall balance (all-time allocated - all-time spent)
    $totalAllocAll = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations WHERE manager_id = :mid",
        ['mid' => $mid]
    );
    $totalSpentAll = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
         WHERE manager_id = :mid1 AND category IN ('expense','employee_advance') AND status = 'approved'",
        ['mid1' => $mid]
    );

    // Only include managers who have any allocation or spending activity
    if ($allocated > 0 || $spent > 0 || $totalAllocAll > 0) {
        $managerSummary[$mid] = [
            'name'           => $mgr['full_name'],
            'designation'    => $mgr['designation'],
            'unit_name'      => $mgr['unit_name'],
            'city'           => $mgr['city'],
            'month_allocated' => $allocated,
            'month_spent'    => $spent,
            'month_balance'  => $allocated - $spent,
            'total_allocated' => $totalAllocAll,
            'total_spent'    => $totalSpentAll,
            'total_balance'  => $totalAllocAll - $totalSpentAll,
        ];
    }
}

// Totals for summary cards
$grandTotalAllocated = 0;
$grandTotalSpent = 0;
$grandTotalBalance = 0;
foreach ($managerSummary as $ms) {
    $grandTotalAllocated += $ms['month_allocated'];
    $grandTotalSpent += $ms['month_spent'];
    $grandTotalBalance += $ms['month_balance'];
}

// Active managers count this month
$activeManagersCount = count($managerSummary);

$flashMsg = $flashMsg ?? '';
$flashType = $flashType ?? 'success';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MONTH-WISE ADVANCE ALLOCATION PAGE
     ═══════════════════════════════════════════════════════════════════════════ -->

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1 fw-bold text-dark">
                <i class="bi bi-cash-stack me-2 text-primary"></i>Advance Allocation
            </h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php?page=expense/dashboard">Expense</a></li>
                    <li class="breadcrumb-item active">Allocations</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="index.php?page=expense/ledger" class="btn btn-outline-primary btn-sm me-1">
                <i class="bi bi-journal-text me-1"></i>Ledger
            </a>
        </div>
    </div>

    <!-- Flash Message -->
    <?php if (isset($flashMsg) && $flashMsg): ?>
    <div class="alert alert-<?php echo $flashType === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show py-2" role="alert">
        <small class="fw-medium"><?php echo htmlspecialchars($flashMsg); ?></small>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ═══ MONTH/YEAR NAVIGATION BAR ═══ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" action="<?php echo htmlspecialchars($allocatePageUrl); ?>" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="expense/allocations">

                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">
                        <i class="bi bi-calendar3 me-1"></i>Month
                    </label>
                    <select name="month" class="form-select form-select-sm" style="min-width: 140px;">
                        <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                        <option value="<?php echo $mi; ?>" <?php echo $selectedMonth === $mi ? 'selected' : ''; ?>>
                            <?php echo $monthNames[$mi]; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Year</label>
                    <select name="year" class="form-select form-select-sm" style="min-width: 100px;">
                        <?php
                        $curYear = (int)date('Y');
                        for ($yi = $curYear + 1; $yi >= $curYear - 3; $yi--): ?>
                        <option value="<?php echo $yi; ?>" <?php echo $selectedYear === $yi ? 'selected' : ''; ?>>
                            <?php echo $yi; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search me-1"></i>View
                    </button>
                </div>

                <!-- Quick nav: Previous / Next month -->
                <div class="col-auto ms-auto">
                    <div class="btn-group btn-group-sm">
                        <?php
                        $prevM = $selectedMonth - 1; $prevY = $selectedYear;
                        if ($prevM < 1) { $prevM = 12; $prevY--; }
                        $nextM = $selectedMonth + 1; $nextY = $selectedYear;
                        if ($nextM > 12) { $nextM = 1; $nextY++; }
                        ?>
                        <a href="<?php echo $allocatePageUrl . '&month=' . $prevM . '&year=' . $prevY; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-chevron-left"></i> <?php echo $monthShort[$prevM]; ?>
                        </a>
                        <span class="btn btn-dark disabled" style="min-width: 130px;">
                            <?php echo $monthShort[$selectedMonth] . ' ' . $selectedYear; ?>
                        </span>
                        <a href="<?php echo $allocatePageUrl . '&month=' . $nextM . '&year=' . $nextY; ?>" class="btn btn-outline-secondary">
                            <?php echo $monthShort[$nextM]; ?> <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ SUMMARY CARDS ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small fw-semibold"><i class="bi bi-people-fill me-1"></i>Active Managers</div>
                    <div class="fs-3 fw-bold text-primary mt-1"><?php echo $activeManagersCount; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small fw-semibold"><i class="bi bi-plus-circle me-1 text-success"></i>Total Allocated</div>
                    <div class="fs-3 fw-bold text-success mt-1"><?php echo number_format($grandTotalAllocated, 2); ?></div>
                    <div class="text-muted small"><?php echo $monthShort[$selectedMonth] . ' ' . $selectedYear; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small fw-semibold"><i class="bi bi-dash-circle me-1 text-danger"></i>Total Spent</div>
                    <div class="fs-3 fw-bold text-danger mt-1"><?php echo number_format($grandTotalSpent, 2); ?></div>
                    <div class="text-muted small"><?php echo $monthShort[$selectedMonth] . ' ' . $selectedYear; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small fw-semibold"><i class="bi bi-wallet2 me-1"></i>Net Balance</div>
                    <div class="fs-3 fw-bold mt-1 <?php echo $grandTotalBalance >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo ($grandTotalBalance < 0 ? '-' : '') . number_format(abs($grandTotalBalance), 2); ?>
                    </div>
                    <div class="text-muted small"><?php echo $monthShort[$selectedMonth] . ' ' . $selectedYear; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- ── Allocation Form (Left / Top) ───────────────────────────────── -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-primary bg-opacity-10 border-0">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-plus-circle me-2"></i>Allocate Advance
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="allocateForm" novalidate>
                        <input type="hidden" name="action" value="allocate_advance">
                        <input type="hidden" name="alloc_month" value="<?php echo $selectedMonth; ?>">
                        <input type="hidden" name="alloc_year" value="<?php echo $selectedYear; ?>">

                        <!-- Month/Year Display -->
                        <div class="alert alert-light border py-2 px-3 mb-3">
                            <small class="fw-semibold text-primary">
                                <i class="bi bi-calendar-check me-1"></i>
                                Allocating for: <strong><?php echo $monthNames[$selectedMonth] . ' ' . $selectedYear; ?></strong>
                            </small>
                            <div class="text-muted small" style="font-size: 0.72rem;">Change month using the navigation above</div>
                        </div>

                        <!-- Manager Select -->
                        <div class="mb-3">
                            <label for="manager_id" class="form-label fw-medium small">Manager <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm mb-1" id="managerSearch" placeholder="Search by name, designation, or unit..." autocomplete="off" style="max-width: 350px;">
                            <select name="manager_id" id="manager_id" class="form-select form-select-sm" required style="max-width: 350px;">
                                <option value="">-- Select Manager --</option>
                                <?php foreach ($managers as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['employee_id']); ?>"
                                    data-label="<?php echo htmlspecialchars(($m['full_name'] ?? '') . ' ' . ($m['designation'] ?? '') . ' ' . ($m['unit_name'] ?? '') . ' ' . ($m['city'] ?? '')); ?>">
                                    <?php echo htmlspecialchars($m['full_name']); ?>
                                    <?php if ($m['designation']): ?> — <?php echo htmlspecialchars($m['designation']); ?><?php endif; ?>
                                    <?php if ($m['unit_name']): ?> (<?php echo htmlspecialchars($m['unit_name']); ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Amount -->
                        <div class="mb-3">
                            <label for="amount" class="form-label fw-medium small">Amount <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                                <input type="number" name="amount" id="amount" class="form-control"
                                       placeholder="Enter amount" step="0.01" min="0.01" required>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="mb-3">
                            <label for="remarks" class="form-label fw-medium small">Remarks</label>
                            <textarea name="remarks" id="remarks" class="form-control form-control-sm" rows="2"
                                      placeholder="Optional remarks..."></textarea>
                        </div>

                        <!-- Submit -->
                        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                            <i class="bi bi-cash-coin me-2"></i>Allocate Advance
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Manager Monthly Summary (Right) ───────────────────────────── -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-success bg-opacity-10 border-0">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-bar-chart-line me-2"></i>Manager Summary — <?php echo $monthShort[$selectedMonth] . ' ' . $selectedYear; ?>
                    </h5>
                    <span class="badge bg-secondary"><?php echo $activeManagersCount; ?> Managers</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($managerSummary)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No allocation data for <?php echo $monthNames[$selectedMonth] . ' ' . $selectedYear; ?>.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Manager</th>
                                    <th class="text-end" style="width:110px;">Allocated</th>
                                    <th class="text-end" style="width:110px;">Spent</th>
                                    <th class="text-end" style="width:110px;">Balance</th>
                                    <th class="text-end" style="width:120px;">Overall Balance</th>
                                    <th class="text-center" style="width:60px;">Ledger</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($managerSummary as $mid => $ms):
                                    $isNeg = $ms['month_balance'] < 0;
                                    $isOverallNeg = $ms['total_balance'] < 0;
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-semibold small"><?php echo htmlspecialchars($ms['name']); ?></div>
                                        <?php if ($ms['unit_name']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($ms['unit_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small">
                                        <?php if ($ms['month_allocated'] > 0): ?>
                                        <span class="fw-semibold text-success"><?php echo number_format($ms['month_allocated'], 2); ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small">
                                        <?php if ($ms['month_spent'] > 0): ?>
                                        <span class="fw-semibold text-danger"><?php echo number_format($ms['month_spent'], 2); ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small">
                                        <?php if ($isNeg): ?>
                                        <span class="fw-bold text-danger">-<?php echo number_format(abs($ms['month_balance']), 2); ?></span>
                                        <?php else: ?>
                                        <span class="fw-bold text-success"><?php echo number_format($ms['month_balance'], 2); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small">
                                        <?php if ($isOverallNeg): ?>
                                        <span class="fw-bold text-danger">-<?php echo number_format(abs($ms['total_balance']), 2); ?></span>
                                        <?php else: ?>
                                        <span class="fw-bold <?php echo $ms['total_balance'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo number_format($ms['total_balance'], 2); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="index.php?page=expense/ledger&manager=<?php echo urlencode($mid); ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>"
                                           class="btn btn-outline-primary btn-sm py-0 px-1" title="View Ledger">
                                            <i class="bi bi-journal-text"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <!-- Totals Row -->
                                <tr class="table-dark">
                                    <td class="ps-3 fw-bold small">TOTAL</td>
                                    <td class="text-end small fw-bold text-success"><?php echo number_format($grandTotalAllocated, 2); ?></td>
                                    <td class="text-end small fw-bold text-danger"><?php echo number_format($grandTotalSpent, 2); ?></td>
                                    <td class="text-end small fw-bold <?php echo $grandTotalBalance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo ($grandTotalBalance < 0 ? '-' : '') . number_format(abs($grandTotalBalance), 2); ?>
                                    </td>
                                    <td class="text-end small text-muted">—</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Allocation Entries for Selected Month ─────────────────────────── -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark bg-opacity-10 border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-clock-history me-2"></i>Allocation Entries — <?php echo $monthNames[$selectedMonth] . ' ' . $selectedYear; ?>
            </h5>
            <span class="badge bg-secondary"><?php echo count($allocations); ?> Records</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($allocations)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                No allocations found for <?php echo $monthNames[$selectedMonth] . ' ' . $selectedYear; ?>.
                <br><small>Use the form above to allocate funds to managers.</small>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-sm">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:100px;">Date</th>
                            <th>Manager Name</th>
                            <th>Unit / City</th>
                            <th class="text-end" style="width:120px;">Amount</th>
                            <th>Remarks</th>
                            <th>Allocated By</th>
                            <th class="text-center" style="width:100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allocations as $row): ?>
                        <tr>
                            <!-- Date -->
                            <td class="ps-3 text-nowrap small">
                                <?php
                                $dt = strtotime($row['created_at']);
                                echo date('d M Y', $dt) . '<br>';
                                echo '<small class="text-muted">' . date('h:i A', $dt) . '</small>';
                                ?>
                            </td>

                            <!-- Manager Name -->
                            <td>
                                <div class="fw-semibold small"><?php echo htmlspecialchars($row['full_name'] ?? 'N/A'); ?></div>
                                <?php if (!empty($row['designation'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($row['designation']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Unit / City -->
                            <td class="small">
                                <?php if (!empty($row['unit_name'])): ?>
                                <?php echo htmlspecialchars($row['unit_name']); ?>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                                <?php if (!empty($row['city'])): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($row['city']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Amount -->
                            <td class="text-end">
                                <span class="fw-bold text-primary small"><?php echo number_format((float)$row['amount'], 2); ?></span>
                            </td>

                            <!-- Remarks -->
                            <td class="small">
                                <?php if (!empty($row['remarks'])): ?>
                                <?php echo htmlspecialchars($row['remarks']); ?>
                                <?php else: ?>
                                <span class="text-muted fst-italic">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Allocated By -->
                            <td class="text-nowrap">
                                <span class="badge bg-light text-dark border small">
                                    <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($row['allocated_by'] ?? 'system'); ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="text-center">
                                <button type="button" class="btn btn-primary btn-sm py-0 px-1 me-1"
                                        data-bs-toggle="modal" data-bs-target="#editAllocModal-<?php echo (int)$row['id']; ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete this allocation?');">
                                    <input type="hidden" name="action" value="delete_allocation">
                                    <input type="hidden" name="alloc_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm py-0 px-1" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Edit Allocation Modals -->
<?php foreach ($allocations as $row): ?>
<div class="modal fade" id="editAllocModal-<?php echo (int)$row['id']; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="bi bi-pencil-square me-1 text-primary"></i>Edit Allocation</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_allocation">
                <input type="hidden" name="alloc_id" value="<?php echo (int)$row['id']; ?>">
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Manager</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($row['full_name'] ?? 'N/A'); ?>" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Month</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo $monthNames[(int)($row['month'] ?? $selectedMonth)] . ' ' . ($row['year'] ?? $selectedYear); ?>" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Amount</label>
                        <input type="number" name="amount" class="form-control form-control-sm"
                               step="0.01" min="0.01" value="<?php echo htmlspecialchars($row['amount']); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Remarks</label>
                        <textarea name="remarks" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     INLINE SCRIPTS
     ═══════════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('allocateForm');

    if (form) {
        form.addEventListener('submit', function (e) {
            const managerId = document.getElementById('manager_id').value.trim();
            const amount    = parseFloat(document.getElementById('amount').value);

            if (!managerId) {
                e.preventDefault();
                alert('Please select a manager.');
                return;
            }

            if (isNaN(amount) || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount greater than 0.');
                return;
            }

            if (!confirm('Allocate \u20B9' + amount.toLocaleString('en-IN', {minimumFractionDigits:2}) + ' to this manager?')) {
                e.preventDefault();
            }
        });
    }

    // Manager search filter
    var searchInput = document.getElementById('managerSearch');
    var managerSelect = document.getElementById('manager_id');
    if (searchInput && managerSelect) {
        // Store all original options
        var allOptions = [];
        for (var i = 0; i < managerSelect.options.length; i++) {
            allOptions.push({
                value: managerSelect.options[i].value,
                text: managerSelect.options[i].text,
                label: (managerSelect.options[i].getAttribute('data-label') || '').toLowerCase()
            });
        }

        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var currentVal = managerSelect.value;
            // Rebuild options
            managerSelect.innerHTML = '';
            for (var i = 0; i < allOptions.length; i++) {
                if (i === 0 || !q || allOptions[i].label.indexOf(q) !== -1) {
                    var opt = document.createElement('option');
                    opt.value = allOptions[i].value;
                    opt.textContent = allOptions[i].text;
                    managerSelect.appendChild(opt);
                }
            }
            // Re-select previous value if still visible
            if (currentVal) managerSelect.value = currentVal;
        });
    }

});
</script>
