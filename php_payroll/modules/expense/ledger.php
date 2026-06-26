<?php
if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }
/**
 * Manager Ledger - Full financial history and running balance
 * Loaded via index.php?page=expense/ledger
 *
 * ALL display calculations are LIVE (computed from actual transaction data).
 * The manager_ledger table is used for storage/settlement only.
 */

$pageTitle = 'Manager Ledger';

// Shared auto-migration & helpers
require_once __DIR__ . '/expense-setup.php';

$baseUrl = 'index.php?page=expense/ledger';

// ============================================================================
// Helper: Build the WHERE clause fragment for matching expenses to a manager
// Uses both employee_id and manager_id columns, with COALESCE for date.
// ============================================================================
function buildExpenseManagerWhere($alias = 'e') {
    return "({$alias}.employee_id = :mid OR {$alias}.manager_id = :mid)";
}

// ============================================================================
// Helper: Compute LIVE opening balance for a manager at the start of a month
// Strategy: find the most recent settled ledger before this month.
// If none exists, walk back through all previous months summing live data.
// ============================================================================
function computeLiveOpeningBalance($db, $managerId, $month, $year) {
    // Look for the most recent settled ledger before this month/year
    $prevSettled = $db->fetch(
        "SELECT * FROM manager_ledger
         WHERE manager_id = :mid
           AND (year < :yr OR (year = :yr2 AND month < :m))
           AND carried_forward = 1
         ORDER BY year DESC, month DESC
         LIMIT 1",
        ['mid' => $managerId, 'yr' => $year, 'yr2' => $year, 'm' => $month]
    );

    if ($prevSettled) {
        // We have a settled ledger. But there may be un-settled months between
        // that settled month and the current month. Sum live data for those gap months.
        $baseBalance = (float)$prevSettled['closing_balance'];
        $gapStartMonth = (int)$prevSettled['month'] + 1;
        $gapStartYear  = (int)$prevSettled['year'];

        // Normalize: if gap start month > 12, rollover
        if ($gapStartMonth > 12) {
            $gapStartMonth = 1;
            $gapStartYear++;
        }

        // Walk from gap start to the month BEFORE the target month
        $bal = $baseBalance;
        $cy = $gapStartYear;
        $cm = $gapStartMonth;

        while ($cy < $year || ($cy === $year && $cm < $month)) {
            $live = computeLiveMonthTotals($db, $managerId, $cm, $cy);
            $bal = $bal + $live['advances'] - $live['expenses'] - $live['employee_advances'];
            $cm++;
            if ($cm > 12) { $cm = 1; $cy++; }
        }
        return $bal;
    }

    // No settled ledger found at all. Compute from the very beginning by
    // summing all transactions before the target month.
    $startDate = '2000-01-01';
    $endDate   = sprintf('%04d-%02d-01', $year, $month); // first day of target month, exclusive

    // Sum all advances before this month
    $totalAdvances = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations
         WHERE manager_id = :mid AND created_at < :ed",
        ['mid' => $managerId, 'ed' => $endDate]
    );

    // Sum all approved expenses before this month
    $totalExpenses = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
         WHERE (employee_id = :mid1 OR manager_id = :mid2)
           AND category = 'expense' AND status = 'approved'
           AND COALESCE(expense_date, DATE(created_at)) < :ed",
        ['mid1' => $managerId, 'mid2' => $managerId, 'ed' => $endDate]
    );

    // Sum all approved employee advances before this month
    $totalEmpAdvances = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
         WHERE (employee_id = :mid1 OR manager_id = :mid2)
           AND category = 'employee_advance' AND status = 'approved'
           AND COALESCE(expense_date, DATE(created_at)) < :ed",
        ['mid1' => $managerId, 'mid2' => $managerId, 'ed' => $endDate]
    );

    return $totalAdvances - $totalExpenses - $totalEmpAdvances;
}

// ============================================================================
// Helper: Compute LIVE totals for a specific month from actual transaction data
// ============================================================================
function computeLiveMonthTotals($db, $managerId, $month, $year) {
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate   = sprintf('%04d-%02d-31', $year, $month);

    // Total advance allocations this month
    $advances = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations
         WHERE manager_id = :mid
           AND DATE(created_at) >= :sd AND DATE(created_at) <= :ed",
        ['mid' => $managerId, 'sd' => $startDate, 'ed' => $endDate]
    );

    // Total approved expenses this month
    $expenses = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
         WHERE (employee_id = :mid1 OR manager_id = :mid2)
           AND category = 'expense' AND status = 'approved'
           AND COALESCE(expense_date, DATE(created_at)) >= :sd
           AND COALESCE(expense_date, DATE(created_at)) <= :ed",
        ['mid1' => $managerId, 'mid2' => $managerId, 'sd' => $startDate, 'ed' => $endDate]
    );

    // Total approved employee advances this month
    $employee_advances = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
         WHERE (employee_id = :mid1 OR manager_id = :mid2)
           AND category = 'employee_advance' AND status = 'approved'
           AND COALESCE(expense_date, DATE(created_at)) >= :sd
           AND COALESCE(expense_date, DATE(created_at)) <= :ed",
        ['mid1' => $managerId, 'mid2' => $managerId, 'sd' => $startDate, 'ed' => $endDate]
    );

    return [
        'advances'          => $advances,
        'expenses'          => $expenses,
        'employee_advances' => $employee_advances,
    ];
}

// ============================================================================
// SECTION 1: Handle POST Actions
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = sanitize($_POST['action']);

    // ── Admin: Add Advance ───────────────────────────────────────────────
    if ($action === 'admin_add_advance') {
        $managerId = sanitize(trim($_POST['manager'] ?? ''));
        $amount    = floatval($_POST['advance_amount'] ?? 0);
        $remarks   = sanitize(trim($_POST['advance_remarks'] ?? ''));
        $advDate   = sanitize(trim($_POST['advance_date'] ?? ''));

        if ($managerId === '' || $amount <= 0) {
            setFlash('danger', 'Please select a manager and enter a valid amount.');
            redirect($baseUrl . '&manager=' . $managerId . '&month=' . ($selectedMonth ?? prev_month_num()) . '&year=' . ($selectedYear ?? prev_month_year()) . '#admin-forms');
        }

        $insertData = [
            'manager_id'   => $managerId,
            'amount'       => $amount,
            'remarks'      => $remarks ?: 'Advance allocated by admin',
            'allocated_by' => $_SESSION['user_id'] ?? 'admin',
        ];

        // If a specific date is provided, we insert and then update created_at
        $db->insert('manager_advance_allocations', $insertData);

        // If a custom date was given, update the created_at timestamp
        if ($advDate !== '' && strtotime($advDate)) {
            $lastId = $db->fetchColumn("SELECT LAST_INSERT_ID()");
            if ($lastId) {
                $db->query("UPDATE manager_advance_allocations SET created_at = :dt WHERE id = :id", [
                    'dt' => $advDate . ' 00:00:00',
                    'id' => (int)$lastId,
                ]);
            }
        }

        setFlash('success', 'Advance of ' . formatCurrency($amount) . ' added successfully for this manager.');
        redirect($baseUrl . '&manager=' . $managerId);
    }

    // ── Admin: Add Expense ───────────────────────────────────────────────
    if ($action === 'admin_add_expense') {
        $managerId   = sanitize(trim($_POST['manager'] ?? ''));
        $amount      = floatval($_POST['expense_amount'] ?? 0);
        $type        = sanitize(trim($_POST['expense_type'] ?? 'other'));
        $description = sanitize(trim($_POST['expense_description'] ?? ''));
        $expDate     = sanitize(trim($_POST['expense_date'] ?? ''));
        $billType    = sanitize(trim($_POST['bill_type'] ?? 'without_bill'));

        if ($managerId === '' || $amount <= 0) {
            setFlash('danger', 'Please select a manager and enter a valid amount.');
            redirect($baseUrl . '&manager=' . $managerId . '&month=' . ($selectedMonth ?? prev_month_num()) . '&year=' . ($selectedYear ?? prev_month_year()) . '#admin-forms');
        }

        // Validate expense type
        $validTypes = ['travel', 'food', 'cab', 'supplies', 'medical', 'other'];
        if (!in_array($type, $validTypes)) {
            $type = 'other';
        }

        // Validate bill type
        if (!in_array($billType, ['with_bill', 'without_bill'])) {
            $billType = 'without_bill';
        }

        // Get manager info for emp_name / emp_code
        $mgrInfo = $db->fetch(
            "SELECT * FROM ess_employee_cache WHERE employee_id = :eid",
            ['eid' => $managerId]
        );

        $insertData = [
            'employee_id'  => $managerId,
            'category'     => 'expense',
            'manager_id'   => $managerId,
            'amount'       => $amount,
            'type'         => $type,
            'description'  => $description ?: ('Admin entry: ' . ucfirst($type)),
            'expense_date' => ($expDate !== '' && strtotime($expDate)) ? $expDate : null,
            'bill_type'    => $billType,
            'status'       => 'approved',
            'emp_name'     => $mgrInfo['full_name'] ?? '',
            'emp_code'     => $mgrInfo['employee_code'] ?? $managerId,
            'unit_id'      => $mgrInfo['unit_id'] ?? null,
            'month'        => ($expDate !== '' && strtotime($expDate)) ? (int)date('n', strtotime($expDate)) : (int)prev_month_num(),
            'year'         => ($expDate !== '' && strtotime($expDate)) ? (int)date('Y', strtotime($expDate)) : (int)prev_month_year(),
        ];

        $db->insert('ess_expenses', $insertData);

        setFlash('success', 'Expense of ' . formatCurrency($amount) . ' (' . ucfirst($type) . ') added and auto-approved.');
        redirect($baseUrl . '&manager=' . $managerId);
    }

    // ── Generate / Regenerate Ledger (stored data + settlement) ──────────
    if ($action === 'generate_ledger') {
        $managerId = sanitize(trim($_POST['manager'] ?? ''));
        $month     = (int)($_POST['month'] ?? 0);
        $year      = (int)($_POST['year'] ?? 0);

        if ($managerId === '' || $month < 1 || $month > 12 || $year < 2000) {
            setFlash('danger', 'Invalid manager, month, or year selected.');
            redirect($baseUrl);
        }

        // 1. Compute live opening balance
        $openingBalance = computeLiveOpeningBalance($db, $managerId, $month, $year);

        // 2. Compute live totals for this month
        $live = computeLiveMonthTotals($db, $managerId, $month, $year);
        $totalAdvanceGiven      = $live['advances'];
        $totalExpenses           = $live['expenses'];
        $totalEmployeeAdvances   = $live['employee_advances'];

        // 3. Calculate closing balance
        $closingBalance = $openingBalance + $totalAdvanceGiven - $totalExpenses - $totalEmployeeAdvances;

        // 4. Upsert into manager_ledger
        $existing = $db->fetch(
            "SELECT id FROM manager_ledger
             WHERE manager_id = :mid AND month = :m AND year = :yr",
            ['mid' => $managerId, 'm' => $month, 'yr' => $year]
        );

        $ledgerData = [
            'opening_balance'         => $openingBalance,
            'total_advance_given'     => $totalAdvanceGiven,
            'total_expenses'          => $totalExpenses,
            'total_employee_advances' => $totalEmployeeAdvances,
            'closing_balance'         => $closingBalance,
        ];

        if ($existing) {
            $db->update(
                'manager_ledger',
                $ledgerData,
                'manager_id = :mid AND month = :m AND year = :yr',
                ['mid' => $managerId, 'm' => $month, 'yr' => $year]
            );
            setFlash('success', 'Ledger for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ' regenerated with live data.');
        } else {
            $ledgerData['manager_id'] = $managerId;
            $ledgerData['month']      = $month;
            $ledgerData['year']       = $year;
            $db->insert('manager_ledger', $ledgerData);
            setFlash('success', 'Ledger for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ' generated with live data.');
        }

        redirect($baseUrl . '&manager=' . $managerId . '&month=' . $month . '&year=' . $year);
    }

    // ── Month End Settlement ──────────────────────────────────────────────
    if ($action === 'settle_month') {
        $managerId = sanitize(trim($_POST['manager'] ?? ''));
        $month     = (int)($_POST['month'] ?? 0);
        $year      = (int)($_POST['year'] ?? 0);

        if ($managerId === '' || $month < 1 || $month > 12 || $year < 2000) {
            setFlash('danger', 'Invalid parameters for settlement.');
            redirect($baseUrl);
        }

        // First, generate/update the stored ledger with live data
        $openingBalance = computeLiveOpeningBalance($db, $managerId, $month, $year);
        $live = computeLiveMonthTotals($db, $managerId, $month, $year);
        $totalAdvanceGiven      = $live['advances'];
        $totalExpenses           = $live['expenses'];
        $totalEmployeeAdvances   = $live['employee_advances'];
        $closingBalance = $openingBalance + $totalAdvanceGiven - $totalExpenses - $totalEmployeeAdvances;

        // Upsert ledger with live data
        $existing = $db->fetch(
            "SELECT id FROM manager_ledger
             WHERE manager_id = :mid AND month = :m AND year = :yr",
            ['mid' => $managerId, 'm' => $month, 'yr' => $year]
        );

        $ledgerData = [
            'opening_balance'         => $openingBalance,
            'total_advance_given'     => $totalAdvanceGiven,
            'total_expenses'          => $totalExpenses,
            'total_employee_advances' => $totalEmployeeAdvances,
            'closing_balance'         => $closingBalance,
        ];

        if ($existing) {
            $db->update(
                'manager_ledger',
                $ledgerData,
                'manager_id = :mid AND month = :m AND year = :yr',
                ['mid' => $managerId, 'm' => $month, 'yr' => $year]
            );
        } else {
            $ledgerData['manager_id'] = $managerId;
            $ledgerData['month']      = $month;
            $ledgerData['year']       = $year;
            $db->insert('manager_ledger', $ledgerData);
        }

        // Now fetch the ledger row for settlement
        $ledger = $db->fetch(
            "SELECT * FROM manager_ledger
             WHERE manager_id = :mid AND month = :m AND year = :yr",
            ['mid' => $managerId, 'm' => $month, 'yr' => $year]
        );

        if (!$ledger) {
            setFlash('danger', 'Failed to create ledger for settlement.');
            redirect($baseUrl);
        }

        if ((int)$ledger['carried_forward'] === 1) {
            setFlash('warning', 'This month has already been settled and carried forward.');
            redirect($baseUrl . '&manager=' . $managerId . '&month=' . $month . '&year=' . $year);
        }

        // Mark as carried forward
        $db->update(
            'manager_ledger',
            [
                'carried_forward' => 1,
                'settled_by'      => $_SESSION['user_id'] ?? 'admin',
                'settled_at'      => date('Y-m-d H:i:s'),
            ],
            'id = :id',
            ['id' => (int)$ledger['id']]
        );

        // Create expense_settlements row
        $settlementData = [
            'manager_id'          => $managerId,
            'month'               => $month,
            'year'                => $year,
            'total_advance'       => $totalAdvanceGiven,
            'total_expenses'      => $totalExpenses,
            'total_emp_advances'  => $totalEmployeeAdvances,
            'balance'             => $closingBalance,
            'status'              => 'settled',
            'settlement_remarks'  => 'Month end settlement for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)),
            'settled_by'          => $_SESSION['user_id'] ?? 'admin',
            'settled_at'          => date('Y-m-d H:i:s'),
        ];

        try {
            $db->insert('expense_settlements', $settlementData);
        } catch (Exception $e) {
            // Settlements table might not exist, silently continue
        }

        $monthLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));
        setFlash('success', 'Month end settlement completed for ' . $monthLabel . '. Balance of ' . formatCurrency($closingBalance) . ' carried forward.');

        redirect($baseUrl . '&manager=' . $managerId . '&month=' . $month . '&year=' . $year);
    }

    // Unknown action fallback
    setFlash('danger', 'Unknown action.');
    redirect($baseUrl);
}

// ============================================================================
// SECTION 2: GET Parameters & Data Fetching
// ============================================================================

// Manager selection — support both `manager` and `manager_id` params
$selectedManager = sanitize(trim($_GET['manager'] ?? $_GET['manager_id'] ?? ''));
$selectedMonth   = (int)($_GET['month'] ?? prev_month_num());
$selectedYear    = (int)($_GET['year'] ?? prev_month_year());

// Validate month/year
if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = (int)prev_month_num();
if ($selectedYear < 2000 || $selectedYear > 2099) $selectedYear = (int)prev_month_year();

// Fetch all managers for the dropdown
$managers = $db->fetchAll(
    "SELECT employee_id, full_name, designation, unit_name, city, role
     FROM ess_employee_cache
     WHERE role IN ('manager', 'regional_manager')
     ORDER BY full_name ASC"
);

// Selected manager info
$selectedManagerInfo = null;
if ($selectedManager !== '') {
    $selectedManagerInfo = $db->fetch(
        "SELECT * FROM ess_employee_cache WHERE employee_id = :eid",
        ['eid' => $selectedManager]
    );
}

// Ledger data for selected period (used for settlement status only)
$ledger = null;
if ($selectedManager !== '') {
    $ledger = $db->fetch(
        "SELECT * FROM manager_ledger
         WHERE manager_id = :mid AND month = :m AND year = :yr",
        ['mid' => $selectedManager, 'm' => $selectedMonth, 'yr' => $selectedYear]
    );
}

// ============================================================================
// SECTION 3: LIVE Calculations (the real source of truth for display)
// ============================================================================

// Compute live opening balance
$liveOpeningBalance = 0.00;
$liveAdvances = 0.00;
$liveExpenses = 0.00;
$liveEmployeeAdvances = 0.00;
$liveClosingBalance = 0.00;

if ($selectedManager !== '') {
    $liveOpeningBalance = computeLiveOpeningBalance($db, $selectedManager, $selectedMonth, $selectedYear);
    $liveTotals = computeLiveMonthTotals($db, $selectedManager, $selectedMonth, $selectedYear);
    $liveAdvances         = $liveTotals['advances'];
    $liveExpenses         = $liveTotals['expenses'];
    $liveEmployeeAdvances = $liveTotals['employee_advances'];
    $liveClosingBalance   = $liveOpeningBalance + $liveAdvances - $liveExpenses - $liveEmployeeAdvances;
}

// ============================================================================
// SECTION 4: Build Detailed Transaction List (for selected month)
// ============================================================================

$transactions = [];

if ($selectedManager !== '') {
    $mid = $selectedManager;
    $m   = $selectedMonth;
    $y   = $selectedYear;

    $startDate = sprintf('%04d-%02d-01', $y, $m);
    $endDate   = sprintf('%04d-%02d-31', $y, $m);

    // Union query combining all transaction types for the selected month
    // Uses COALESCE(expense_date, DATE(created_at)) for date filtering
    $unionSql = "
        SELECT created_at AS txn_date, remarks AS txn_desc, amount AS debit, 0 AS credit, 'advance' AS txn_type, id AS ref_id
        FROM manager_advance_allocations
        WHERE manager_id = :mid1 AND DATE(created_at) >= :sd1 AND DATE(created_at) <= :ed1

        UNION ALL

        SELECT COALESCE(expense_date, DATE(created_at)) AS txn_date, CONCAT(type, ': ', description) AS txn_desc, 0 AS debit, amount AS credit, 'expense' AS txn_type, id AS ref_id
        FROM ess_expenses
        WHERE (employee_id = :mid2 OR manager_id = :mid2b)
          AND category = 'expense' AND status = 'approved'
          AND COALESCE(expense_date, DATE(created_at)) >= :sd2 AND COALESCE(expense_date, DATE(created_at)) <= :ed2

        UNION ALL

        SELECT COALESCE(expense_date, DATE(created_at)) AS txn_date, CONCAT('Emp Adv: ', COALESCE(emp_name, 'Employee')) AS txn_desc, 0 AS debit, amount AS credit, 'employee_advance' AS txn_type, id AS ref_id
        FROM ess_expenses
        WHERE (employee_id = :mid3 OR manager_id = :mid3b)
          AND category = 'employee_advance' AND status = 'approved'
          AND COALESCE(expense_date, DATE(created_at)) >= :sd3 AND COALESCE(expense_date, DATE(created_at)) <= :ed3

        UNION ALL

        SELECT COALESCE(expense_date, DATE(created_at)) AS txn_date, CONCAT(type, ': ', description, ' [REJECTED]') AS txn_desc, 0 AS debit, 0 AS credit, 'rejected' AS txn_type, id AS ref_id
        FROM ess_expenses
        WHERE (employee_id = :mid4 OR manager_id = :mid4b)
          AND status = 'rejected'
          AND COALESCE(expense_date, DATE(created_at)) >= :sd4 AND COALESCE(expense_date, DATE(created_at)) <= :ed4

        ORDER BY txn_date ASC, ref_id ASC
    ";

    $unionParams = [
        'mid1'  => $mid, 'sd1' => $startDate, 'ed1' => $endDate,
        'mid2'  => $mid, 'mid2b' => $mid, 'sd2' => $startDate, 'ed2' => $endDate,
        'mid3'  => $mid, 'mid3b' => $mid, 'sd3' => $startDate, 'ed3' => $endDate,
        'mid4'  => $mid, 'mid4b' => $mid, 'sd4' => $startDate, 'ed4' => $endDate,
    ];

    $transactions = $db->fetchAll($unionSql, $unionParams);
}

// ============================================================================
// SECTION 5: Monthly Ledger History for selected manager
// ============================================================================

$ledgerHistory = [];
if ($selectedManager !== '') {
    $ledgerHistory = $db->fetchAll(
        "SELECT * FROM manager_ledger
         WHERE manager_id = :mid
         ORDER BY year DESC, month DESC",
        ['mid' => $selectedManager]
    );
}

// Month name helper
$monthNames = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
    5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
    9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
];

// Determine if current period is settled (from stored ledger)
$isSettled   = false;
$settledBy   = null;
$settledAt   = null;
if ($ledger) {
    $isSettled   = (int)$ledger['carried_forward'] === 1;
    $settledBy   = $ledger['settled_by'] ?? null;
    $settledAt   = $ledger['settled_at'] ?? null;
}

// ============================================================================
// SECTION 6: HTML Output
// ============================================================================

// Page style
?>
<style>
    .ledger-card {
        border: none;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    }
    .ledger-summary-item {
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid rgba(0,0,0,0.06);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .ledger-summary-item:last-child {
        border-bottom: none;
    }
    .ledger-summary-item .label {
        font-size: 0.875rem;
        color: #6c757d;
        font-weight: 500;
    }
    .ledger-summary-item .value {
        font-size: 1.05rem;
        font-weight: 600;
    }
    .ledger-summary-item.total-row {
        background: #f8f9fa;
    }
    .ledger-summary-item.total-row .label {
        font-weight: 700;
        color: #343a40;
        font-size: 1rem;
    }
    .ledger-summary-item.total-row .value {
        font-size: 1.25rem;
    }
    .balance-positive { color: #198754 !important; }
    .balance-negative { color: #dc3545 !important; }
    .balance-zero     { color: #6c757d !important; }

    .txn-table {
        font-size: 0.875rem;
    }
    .txn-table thead th {
        background: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #495057;
        padding: 0.65rem 0.75rem;
        white-space: nowrap;
    }
    .txn-table tbody td {
        padding: 0.55rem 0.75rem;
        vertical-align: middle;
        border-color: #f1f3f5;
    }
    .txn-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    .txn-type-advance {
        border-left: 3px solid #198754;
    }
    .txn-type-expense {
        border-left: 3px solid #dc3545;
    }
    .txn-type-employee_advance {
        border-left: 3px solid #0d6efd;
    }
    .txn-type-rejected {
        border-left: 3px solid #ffc107;
        opacity: 0.7;
    }

    .history-table {
        font-size: 0.82rem;
    }
    .history-table thead th {
        background: #343a40;
        color: #fff;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        padding: 0.6rem 0.7rem;
        white-space: nowrap;
    }
    .history-table tbody td {
        padding: 0.5rem 0.7rem;
        vertical-align: middle;
    }

    .filter-bar {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1rem 1.25rem;
        box-shadow: 0 1px 6px rgba(0,0,0,0.04);
    }

    .section-panel {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        margin-bottom: 1.5rem;
    }
    .section-panel .panel-header {
        padding: 0.9rem 1.25rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .section-panel .panel-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 1rem;
        color: #343a40;
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: #6c757d;
    }
    .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 0.75rem;
        opacity: 0.35;
        display: block;
    }

    .badge-settled {
        background-color: #198754;
        color: #fff;
    }
    .badge-open {
        background-color: #ffc107;
        color: #212529;
    }
    .badge-carried {
        background-color: #0d6efd;
        color: #fff;
    }

    .live-badge {
        background-color: #0dcaf0;
        color: #000;
        font-size: 0.65rem;
        vertical-align: middle;
    }
    .admin-form-section .form-label {
        font-size: 0.82rem;
        font-weight: 600;
        color: #495057;
    }
</style>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-1 fw-bold text-dark">
            <i class="bi bi-journal-text me-2 text-primary"></i>Manager Ledger
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php?page=expense/dashboard">Expense</a></li>
                <li class="breadcrumb-item active">Ledger</li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="index.php?page=expense/dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- Flash Messages -->
<?php if (isset($_SESSION['flash'])): ?>
    <div id="flashContainer">
        <?php foreach ($_SESSION['flash'] as $flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show py-2" role="alert">
                <small class="fw-medium"><?= htmlspecialchars($flash['message']) ?></small>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
        <?php unset($_SESSION['flash']); ?>
    </div>
<?php endif; ?>

<!-- ======================================================================== -->
<!-- FILTER BAR: Manager + Month/Year + Generate                              -->
<!-- ======================================================================== -->
<div class="filter-bar mb-4">
    <form method="GET" action="<?= htmlspecialchars($baseUrl) ?>" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="expense/ledger">

        <!-- Manager Select -->
        <div class="col-lg-4 col-md-6">
            <label class="form-label small fw-semibold mb-1">
                <i class="bi bi-person-badge me-1"></i>Manager
            </label>
            <select name="manager" id="managerSelect" class="form-select form-select-sm" required>
                <option value="">-- Select Manager --</option>
                <?php foreach ($managers as $mgr): ?>
                    <option value="<?= htmlspecialchars($mgr['employee_id']) ?>"
                        <?= ($selectedManager === (string)$mgr['employee_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($mgr['full_name']) ?>
                        <?php if ($mgr['designation']): ?> — <?= htmlspecialchars($mgr['designation']) ?><?php endif; ?>
                        <?php if ($mgr['unit_name']): ?> (<?= htmlspecialchars($mgr['unit_name']) ?>)<?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Month -->
        <div class="col-lg-2 col-md-3 col-6">
            <label class="form-label small fw-semibold mb-1">
                <i class="bi bi-calendar-event me-1"></i>Month
            </label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                    <option value="<?= $mi ?>" <?= ($selectedMonth === $mi) ? 'selected' : '' ?>>
                        <?= $monthNames[$mi] ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- Year -->
        <div class="col-lg-2 col-md-3 col-6">
            <label class="form-label small fw-semibold mb-1">Year</label>
            <select name="year" class="form-select form-select-sm">
                <?php
                $curYear = (int)date('Y');
                for ($yi = $curYear + 1; $yi >= $curYear - 5; $yi--): ?>
                    <option value="<?= $yi ?>" <?= ($selectedYear === $yi) ? 'selected' : '' ?>><?= $yi ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- View Button -->
        <div class="col-lg-1 col-md-3 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="bi bi-search me-1"></i> View
            </button>
        </div>
    </form>

    <!-- Generate Ledger Button (outside the GET filter form to avoid nested forms) -->
    <?php if ($selectedManager !== ''): ?>
        <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>" class="d-inline" id="generateForm">
            <input type="hidden" name="action" value="generate_ledger">
            <input type="hidden" name="manager" value="<?= $selectedManager ?>">
            <input type="hidden" name="month" value="<?= $selectedMonth ?>">
            <input type="hidden" name="year" value="<?= $selectedYear ?>">
            <button type="submit" class="btn btn-success btn-sm"
                    onclick="return confirm('Generate/Regenerate stored ledger for <?= htmlspecialchars($selectedManagerInfo['full_name'] ?? 'this manager') ?> for <?= $monthNames[$selectedMonth] ?> <?= $selectedYear ?>?\\n\\nThis updates stored data for settlement purposes.');">
                <i class="bi bi-arrow-repeat me-1"></i>
                Generate / Recalculate Ledger
            </button>
        </form>
    <?php else: ?>
        <button type="button" class="btn btn-success btn-sm" disabled title="Select a manager first">
            <i class="bi bi-arrow-repeat me-1"></i> Generate Ledger
        </button>
    <?php endif; ?>
</div>

<?php if ($selectedManager === '' || $selectedManager === '0'): ?>
    <!-- No manager selected state -->
    <div class="section-panel">
        <div class="empty-state py-5">
            <i class="bi bi-person-badge"></i>
            <h5 class="text-muted">Select a Manager</h5>
            <p class="mb-0">Choose a manager from the dropdown above to view their financial ledger.</p>
        </div>
    </div>

<?php else: ?>
    <!-- ===================== MANAGER SELECTED — SHOW LIVE DATA ===================== -->

    <!-- Manager Info Bar -->
    <div class="alert alert-light border mb-4 py-2 px-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-person-badge-fill me-2 text-primary"></i>
                    <?= htmlspecialchars($selectedManagerInfo['full_name'] ?? 'N/A') ?>
                </h5>
                <?php if (!empty($selectedManagerInfo['designation'])): ?>
                    <small class="text-muted"><?= htmlspecialchars($selectedManagerInfo['designation']) ?></small>
                <?php endif; ?>
                <?php if (!empty($selectedManagerInfo['unit_name'])): ?>
                    <small class="text-muted ms-2"><i class="bi bi-building me-1"></i><?= htmlspecialchars($selectedManagerInfo['unit_name']) ?></small>
                <?php endif; ?>
            </div>
            <div class="col-md-3 text-md-center mt-2 mt-md-0">
                <span class="badge bg-primary fs-6 px-3 py-2">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= $monthNames[$selectedMonth] ?> <?= $selectedYear ?>
                </span>
            </div>
            <div class="col-md-3 text-md-end mt-2 mt-md-0">
                <?php if ($isSettled): ?>
                    <span class="badge badge-settled px-3 py-2">
                        <i class="bi bi-check-circle-fill me-1"></i> Settled & Carried Forward
                    </span>
                <?php else: ?>
                    <span class="badge badge-open px-3 py-2">
                        <i class="bi bi-clock me-1"></i> Open
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">

        <!-- ================================================================= -->
        <!-- LEDGER SUMMARY CARD (LIVE DATA)                                   -->
        <!-- ================================================================= -->
        <div class="col-lg-4">
            <div class="ledger-card h-100">
                <div class="panel-header bg-primary bg-opacity-10">
                    <h5>
                        <i class="bi bi-calculator me-2 text-primary"></i>Ledger Summary
                        <span class="badge live-badge ms-1">LIVE</span>
                    </h5>
                    <?php if (!$isSettled): ?>
                        <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>" id="settleForm">
                            <input type="hidden" name="action" value="settle_month">
                            <input type="hidden" name="manager" value="<?= $selectedManager ?>">
                            <input type="hidden" name="month" value="<?= $selectedMonth ?>">
                            <input type="hidden" name="year" value="<?= $selectedYear ?>">
                            <button type="submit" class="btn btn-warning btn-sm"
                                    onclick="return confirm('Settle this month?\\n\\nThis will auto-generate the stored ledger with current live data and carry the closing balance forward.');">
                                <i class="bi bi-lock me-1"></i> Settle Month
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="p-0">
                    <!-- Opening Balance -->
                    <div class="ledger-summary-item">
                        <span class="label"><i class="bi bi-box-arrow-in-left me-1"></i>Opening Balance</span>
                        <span class="value <?= $liveOpeningBalance > 0 ? 'balance-positive' : ($liveOpeningBalance < 0 ? 'balance-negative' : 'balance-zero') ?>">
                            <?= formatCurrency($liveOpeningBalance) ?>
                        </span>
                    </div>

                    <!-- Advance Given (LIVE) -->
                    <div class="ledger-summary-item">
                        <span class="label"><i class="bi bi-plus-circle me-1 text-success"></i>(+) Advance Given</span>
                        <span class="value text-success">
                            <?= formatCurrency($liveAdvances) ?>
                        </span>
                    </div>

                    <!-- Expenses (LIVE) -->
                    <div class="ledger-summary-item">
                        <span class="label"><i class="bi bi-dash-circle me-1 text-danger"></i>(−) Expenses</span>
                        <span class="value text-danger">
                            <?= formatCurrency($liveExpenses) ?>
                        </span>
                    </div>

                    <!-- Employee Advances (LIVE) -->
                    <div class="ledger-summary-item">
                        <span class="label"><i class="bi bi-dash-circle me-1 text-danger"></i>(−) Employee Advances</span>
                        <span class="value text-danger">
                            <?= formatCurrency($liveEmployeeAdvances) ?>
                        </span>
                    </div>

                    <!-- Closing Balance (LIVE, Bold) -->
                    <?php
                    $liveBalClass = $liveClosingBalance > 0 ? 'balance-positive' : ($liveClosingBalance < 0 ? 'balance-negative' : 'balance-zero');
                    ?>
                    <div class="ledger-summary-item total-row">
                        <span class="label"><i class="bi bi-equal me-1"></i>(=) Closing Balance</span>
                        <span class="value <?= $liveBalClass ?>" style="font-size:1.35rem;">
                            <?= formatCurrency($liveClosingBalance) ?>
                            <?php if ($liveClosingBalance < 0): ?>
                                <span class="badge bg-danger ms-1" style="font-size:0.65rem; vertical-align: middle;">OVERSPENT</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Status -->
                    <div class="ledger-summary-item" style="background: #f8f9fa;">
                        <span class="label"><i class="bi bi-info-circle me-1"></i>Status</span>
                        <span class="value">
                            <?php if ($isSettled): ?>
                                <span class="badge badge-settled">Settled</span>
                            <?php else: ?>
                                <span class="badge badge-open">Open</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if ($isSettled && $settledAt): ?>
                    <div class="ledger-summary-item">
                        <span class="label"><i class="bi bi-clock-history me-1"></i>Settled At</span>
                        <span class="value small text-muted"><?= date('d M Y h:i A', strtotime($settledAt)) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($isSettled && $settledBy): ?>
                    <div class="ledger-summary-item">
                        <span class="label"><i class="bi bi-person-check me-1"></i>Settled By</span>
                        <span class="value small text-muted"><?= htmlspecialchars($settledBy) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- DETAILED TRANSACTION TABLE (LIVE)                                 -->
        <!-- ================================================================= -->
        <div class="col-lg-8">
            <div class="section-panel h-100">
                <div class="panel-header">
                    <h5>
                        <i class="bi bi-list-ul me-2 text-dark"></i>
                        Detailed Transactions
                        <span class="badge bg-light text-dark border ms-2"><?= count($transactions) ?></span>
                        <span class="badge live-badge ms-1">LIVE</span>
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Period: <?= $monthNames[$selectedMonth] ?> <?= $selectedYear ?></small>
                    </div>
                </div>

                <?php if (empty($transactions)): ?>
                    <div class="empty-state">
                        <i class="bi bi-receipt"></i>
                        <p class="mb-0">No transactions found for this period.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover txn-table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th class="text-end">Debit (Advance In)</th>
                                    <th class="text-end">Credit (Expense Out)</th>
                                    <th class="text-end">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $running = $liveOpeningBalance;
                                $sl = 0;
                                foreach ($transactions as $txn):
                                    $sl++;
                                    $debit  = (float)$txn['debit'];
                                    $credit = (float)$txn['credit'];
                                    // Running balance: +debit (money in), -credit (money out)
                                    $running = $running + $debit - $credit;
                                    $txnType    = $txn['txn_type'] ?? '';
                                    $rowClass   = 'txn-type-' . $txnType;
                                    $rowBalClass = $running > 0 ? 'balance-positive' : ($running < 0 ? 'balance-negative' : 'balance-zero');
                                    $txnDateStr = !empty($txn['txn_date']) ? date('d M Y', strtotime($txn['txn_date'])) : '-';
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="text-muted"><?= $sl ?></td>
                                        <td class="text-nowrap"><?= $txnDateStr ?></td>
                                        <td>
                                            <?php
                                            $desc = $txn['txn_desc'] ?? '';
                                            echo htmlspecialchars($desc);
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($debit > 0): ?>
                                                <span class="fw-semibold text-success"><?= formatCurrency($debit) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($credit > 0): ?>
                                                <span class="fw-semibold text-danger"><?= formatCurrency($credit) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <span class="fw-bold <?= $rowBalClass ?>"><?= formatCurrency($running) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="3" class="text-end">Closing Balance:</td>
                                    <td class="text-end text-success"><?= formatCurrency($liveAdvances) ?></td>
                                    <td class="text-end text-danger"><?= formatCurrency($liveExpenses + $liveEmployeeAdvances) ?></td>
                                    <td class="text-end <?= $liveBalClass ?>"><?= formatCurrency($running) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ================================================================= -->
    <!-- MONTHLY LEDGER HISTORY TABLE                                       -->
    <!-- ================================================================= -->
    <div class="section-panel">
        <div class="panel-header">
            <h5>
                <i class="bi bi-clock-history me-2 text-secondary"></i>
                Monthly Ledger History
                <span class="badge bg-light text-dark border ms-2"><?= count($ledgerHistory) ?> months</span>
            </h5>
            <?php if ($selectedManager !== '' && $selectedManager !== '0'): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>&manager=<?= $selectedManager ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($ledgerHistory)): ?>
            <div class="empty-state">
                <i class="bi bi-calendar-x"></i>
                <p class="mb-0">No ledger history found for this manager. Use "Generate / Recalculate Ledger" to create stored entries.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover history-table mb-0">
                    <thead>
                        <tr>
                            <th class="text-center">#</th>
                            <th>Month / Year</th>
                            <th class="text-end">Opening Balance</th>
                            <th class="text-end">Advance In</th>
                            <th class="text-end">Expenses</th>
                            <th class="text-end">Emp. Advances</th>
                            <th class="text-end">Closing Balance</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hSl = 0;
                        foreach ($ledgerHistory as $hRow):
                            $hSl++;
                            $hMonth    = (int)$hRow['month'];
                            $hYear     = (int)$hRow['year'];
                            $hClosing  = (float)$hRow['closing_balance'];
                            $hSettled  = (int)$hRow['carried_forward'];
                            $hBalClass = $hClosing > 0 ? 'balance-positive' : ($hClosing < 0 ? 'balance-negative' : 'balance-zero');
                            $isActive  = ($hMonth === $selectedMonth && $hYear === $selectedYear);
                        ?>
                            <tr class="<?= $isActive ? 'table-primary' : '' ?>">
                                <td class="text-center text-muted"><?= $hSl ?></td>
                                <td>
                                    <strong>
                                        <a href="<?= htmlspecialchars($baseUrl) ?>&manager=<?= $selectedManager ?>&month=<?= $hMonth ?>&year=<?= $hYear ?>"
                                           class="text-decoration-none <?= $isActive ? 'text-white' : 'text-dark' ?>">
                                            <?= $monthNames[$hMonth] ?> <?= $hYear ?>
                                        </a>
                                    </strong>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;">CURRENT</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatCurrency((float)$hRow['opening_balance']) ?></td>
                                <td class="text-end text-success"><?= formatCurrency((float)$hRow['total_advance_given']) ?></td>
                                <td class="text-end text-danger"><?= formatCurrency((float)$hRow['total_expenses']) ?></td>
                                <td class="text-end text-danger"><?= formatCurrency((float)$hRow['total_employee_advances']) ?></td>
                                <td class="text-end fw-bold <?= $hBalClass ?>"><?= formatCurrency($hClosing) ?></td>
                                <td class="text-center">
                                    <?php if ($hSettled): ?>
                                        <span class="badge badge-settled">Settled</span>
                                    <?php else: ?>
                                        <span class="badge badge-open">Open</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================= -->
    <!-- ADMIN ENTRY FORMS                                                  -->
    <!-- ================================================================= -->
    <div class="section-panel admin-form-section" id="admin-forms">
        <div class="panel-header bg-dark bg-opacity-10">
            <h5>
                <i class="bi bi-plus-circle me-2 text-dark"></i>
                Admin: Add Transactions
            </h5>
            <small class="text-muted">Add advances or expenses on behalf of the selected manager</small>
        </div>

        <div class="p-3">
            <div class="row g-4">

                <!-- ── Add Advance Form ─────────────────────────────────── -->
                <div class="col-lg-6">
                    <div class="card border h-100">
                        <div class="card-header bg-success bg-opacity-10 py-2">
                            <h6 class="mb-0 fw-bold text-success">
                                <i class="bi bi-cash-stack me-2"></i>Add Advance
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>" id="adminAdvanceForm">
                                <input type="hidden" name="action" value="admin_add_advance">
                                <input type="hidden" name="manager" value="<?= $selectedManager ?>">

                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-person me-1"></i>Manager
                                    </label>
                                    <input type="text" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($selectedManagerInfo['full_name'] ?? $selectedManager) ?>"
                                           readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="advance_amount">
                                        <i class="bi bi-currency-rupee me-1"></i>Amount <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" name="advance_amount" id="advance_amount"
                                           class="form-control form-control-sm" placeholder="Enter amount"
                                           step="0.01" min="0.01" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="advance_remarks">
                                        <i class="bi bi-chat-left-text me-1"></i>Remarks
                                    </label>
                                    <input type="text" name="advance_remarks" id="advance_remarks"
                                           class="form-control form-control-sm"
                                           placeholder="e.g. Monthly advance allocation">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="advance_date">
                                        <i class="bi bi-calendar me-1"></i>Date
                                    </label>
                                    <input type="date" name="advance_date" id="advance_date"
                                           class="form-control form-control-sm"
                                           value="<?= date('Y-m-d') ?>">
                                    <small class="text-muted">Defaults to today if left empty</small>
                                </div>

                                <button type="submit" class="btn btn-success btn-sm w-100"
                                        onclick="return confirm('Add this advance of the entered amount for <?= htmlspecialchars($selectedManagerInfo['full_name'] ?? 'this manager') ?>?');">
                                    <i class="bi bi-plus-circle me-1"></i> Add Advance
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ── Add Expense Form ─────────────────────────────────── -->
                <div class="col-lg-6">
                    <div class="card border h-100">
                        <div class="card-header bg-danger bg-opacity-10 py-2">
                            <h6 class="mb-0 fw-bold text-danger">
                                <i class="bi bi-receipt me-2"></i>Add Expense
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>" id="adminExpenseForm">
                                <input type="hidden" name="action" value="admin_add_expense">
                                <input type="hidden" name="manager" value="<?= $selectedManager ?>">

                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-person me-1"></i>Manager
                                    </label>
                                    <input type="text" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($selectedManagerInfo['full_name'] ?? $selectedManager) ?>"
                                           readonly>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-7">
                                        <label class="form-label" for="expense_amount">
                                            <i class="bi bi-currency-rupee me-1"></i>Amount <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" name="expense_amount" id="expense_amount"
                                               class="form-control form-control-sm" placeholder="Enter amount"
                                               step="0.01" min="0.01" required>
                                    </div>
                                    <div class="col-5">
                                        <label class="form-label" for="expense_type">
                                            <i class="bi bi-tag me-1"></i>Type <span class="text-danger">*</span>
                                        </label>
                                        <select name="expense_type" id="expense_type" class="form-select form-select-sm" required>
                                            <option value="travel">Travel</option>
                                            <option value="food">Food</option>
                                            <option value="cab">Cab</option>
                                            <option value="supplies">Supplies</option>
                                            <option value="medical">Medical</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="expense_description">
                                        <i class="bi bi-text-left me-1"></i>Description
                                    </label>
                                    <input type="text" name="expense_description" id="expense_description"
                                           class="form-control form-control-sm"
                                           placeholder="Brief description of the expense">
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label" for="expense_date">
                                            <i class="bi bi-calendar me-1"></i>Expense Date
                                        </label>
                                        <input type="date" name="expense_date" id="expense_date"
                                               class="form-control form-control-sm"
                                               value="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="bill_type">
                                            <i class="bi bi-file-earmark-check me-1"></i>Bill Type
                                        </label>
                                        <select name="bill_type" id="bill_type" class="form-select form-select-sm">
                                            <option value="with_bill">With Bill</option>
                                            <option value="without_bill">Without Bill</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="alert alert-info py-1 px-2 small mb-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    This expense will be <strong>auto-approved</strong> since it's being added by admin.
                                </div>

                                <button type="submit" class="btn btn-danger btn-sm w-100"
                                        onclick="return confirm('Add this expense for <?= htmlspecialchars($selectedManagerInfo['full_name'] ?? 'this manager') ?>? It will be auto-approved.');">
                                    <i class="bi bi-plus-circle me-1"></i> Add Expense (Auto-Approved)
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

<?php endif; ?>
