<?php
if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }
/**
 * Advance & Expense Management - Reports
 * HRMS Module
 * Loaded via index.php?page=expense/reports
 */

$pageTitle = 'Expense Reports';

// Shared auto-migration & helpers
require_once __DIR__ . '/expense-setup.php';

$baseUrl = 'index.php?page=expense/reports';

// ============================================================================
// SECTION 1: Manager dropdown data (shared across tabs)
// ============================================================================

$managersList = $db->fetchAll(
    "SELECT employee_id, full_name, mobile_number, role, designation, unit_name, client_name
     FROM ess_employee_cache
     WHERE role IN ('manager','regional_manager')
     ORDER BY full_name ASC"
);

// ============================================================================
// SECTION 2: Tab 1 — Manager-wise Ledger Report
// ============================================================================

$tab1Manager  = sanitize($_GET['tab1_manager'] ?? '');
$tab1From     = sanitize($_GET['tab1_from'] ?? '');
$tab1To       = sanitize($_GET['tab1_to'] ?? '');

$ledgerTransactions = [];
$ledgerSummary = [
    'total_advance'      => 0,
    'total_expenses'     => 0,
    'total_emp_advances' => 0,
    'net_balance'        => 0,
];

if ($tab1Manager !== '' && $tab1From !== '' && $tab1To !== '') {
    $mid = (int) $tab1Manager;

    // Advance allocations within date range
    $advances = $db->fetchAll(
        "SELECT 'allocation' AS txn_type, id, amount, remarks AS description,
                created_at AS txn_date, NULL AS expense_date, NULL AS type, NULL AS category
         FROM manager_advance_allocations
         WHERE manager_id = :mid AND DATE(created_at) BETWEEN :from AND :to
         ORDER BY created_at ASC",
        ['mid' => $mid, 'from' => $tab1From, 'to' => $tab1To]
    );

    // Approved expenses within date range
    $expenses = $db->fetchAll(
        "SELECT 'expense' AS txn_type, id, amount, description,
                created_at AS txn_date, expense_date, type, category
         FROM ess_expenses
         WHERE manager_id = :mid AND status = 'approved'
               AND (
                   (expense_date IS NOT NULL AND DATE(expense_date) BETWEEN :from1 AND :to1)
                   OR (expense_date IS NULL AND DATE(created_at) BETWEEN :from2 AND :to2)
               )
         ORDER BY created_at ASC",
        ['mid' => $mid, 'from1' => $tab1From, 'to1' => $tab1To, 'from2' => $tab1From, 'to2' => $tab1To]
    );

    // Merge all transactions and sort by date
    $ledgerTransactions = array_merge($advances, $expenses);
    usort($ledgerTransactions, function ($a, $b) {
        $dateA = !empty($a['expense_date']) ? $a['expense_date'] : $a['txn_date'];
        $dateB = !empty($b['expense_date']) ? $b['expense_date'] : $b['txn_date'];
        return strcmp($dateA, $dateB);
    });

    // Calculate running balance and summary
    $runningBalance = 0;
    foreach ($ledgerTransactions as &$txn) {
        if ($txn['txn_type'] === 'allocation') {
            $runningBalance += (float) $txn['amount'];
            $ledgerSummary['total_advance'] += (float) $txn['amount'];
        } else {
            $runningBalance -= (float) $txn['amount'];
            if (($txn['category'] ?? '') === 'employee_advance') {
                $ledgerSummary['total_emp_advances'] += (float) $txn['amount'];
            } else {
                $ledgerSummary['total_expenses'] += (float) $txn['amount'];
            }
        }
        $txn['running_balance'] = $runningBalance;
    }
    unset($txn);

    $ledgerSummary['net_balance'] = $ledgerSummary['total_advance']
        - $ledgerSummary['total_expenses']
        - $ledgerSummary['total_emp_advances'];
}

// ============================================================================
// SECTION 3: Tab 2 — Expense Category Report
// ============================================================================

$tab2Month   = sanitize($_GET['tab2_month'] ?? '');
$tab2Year    = sanitize($_GET['tab2_year'] ?? '');
$tab2Manager = sanitize($_GET['tab2_manager'] ?? '');

$categoryData = [];
$categoryGrandTotal = 0;

if ($tab2Month !== '' && $tab2Year !== '') {
    $m = (int) $tab2Month;
    $y = (int) $tab2Year;

    $params = ['month' => $m, 'year' => $y];
    $managerFilter = '';
    if ($tab2Manager !== '') {
        $params['mid'] = (int) $tab2Manager;
        $managerFilter = ' AND manager_id = :mid';
    }

    $categoryData = $db->fetchAll(
        "SELECT type, category, COUNT(*) AS count, SUM(amount) AS total_amount
         FROM ess_expenses
         WHERE status = 'approved' AND MONTH(expense_date) = :month AND YEAR(expense_date) = :year
               {$managerFilter}
         GROUP BY type, category
         ORDER BY total_amount DESC",
        $params
    );

    foreach ($categoryData as $row) {
        $categoryGrandTotal += (float) $row['total_amount'];
    }
}

// ============================================================================
// SECTION 4: Tab 3 — Monthly Reconciliation Report
// ============================================================================

$tab3Month = sanitize($_GET['tab3_month'] ?? '');
$tab3Year  = sanitize($_GET['tab3_year'] ?? '');

$reconciliationData = [];
$reconSummary = [
    'total_opening'      => 0,
    'total_advance'      => 0,
    'total_expenses'     => 0,
    'total_emp_advances' => 0,
    'total_closing'      => 0,
];

if ($tab3Month !== '' && $tab3Year !== '') {
    $m = (int) $tab3Month;
    $y = (int) $tab3Year;

    $allManagers = $db->fetchAll(
        "SELECT employee_id, full_name, mobile_number, role, designation, unit_name, client_name
         FROM ess_employee_cache
         WHERE role IN ('manager','regional_manager')
         ORDER BY full_name ASC"
    );

    if ($allManagers) {
        foreach ($allManagers as $mgr) {
            $mid = (int) $mgr['employee_id'];
            $ledger = $db->fetch(
                "SELECT * FROM manager_ledger
                 WHERE manager_id = :mid AND month = :month AND year = :year",
                ['mid' => $mid, 'month' => $m, 'year' => $y]
            );

            if ($ledger) {
                $closing    = (float) $ledger['closing_balance'];
                $opening    = (float) $ledger['opening_balance'];
                $advGiven   = (float) $ledger['total_advance_given'];
                $expenses   = (float) $ledger['total_expenses'];
                $empAdv     = (float) $ledger['total_employee_advances'];

                // Determine status
                if ($closing < 0) {
                    $status = 'overdrawn';
                    $statusBadge = 'danger';
                } elseif ($closing === 0) {
                    $status = 'settled';
                    $statusBadge = 'success';
                } else {
                    $status = 'open';
                    $statusBadge = 'primary';
                }
            } else {
                // No ledger entry — compute on the fly
                $advGiven = (float) $db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM manager_advance_allocations
                     WHERE manager_id = :mid AND MONTH(created_at) = :month AND YEAR(created_at) = :year",
                    ['mid' => $mid, 'month' => $m, 'year' => $y]
                );
                $expenses = (float) $db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM ess_expenses
                     WHERE manager_id = :mid AND category = 'expense' AND status = 'approved'
                           AND MONTH(expense_date) = :month AND YEAR(expense_date) = :year",
                    ['mid' => $mid, 'month' => $m, 'year' => $y]
                );
                $empAdv = (float) $db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM ess_expenses
                     WHERE manager_id = :mid AND category = 'employee_advance' AND status = 'approved'
                           AND MONTH(expense_date) = :month AND YEAR(expense_date) = :year",
                    ['mid' => $mid, 'month' => $m, 'year' => $y]
                );
                $opening = 0;
                $closing = $advGiven - $expenses - $empAdv;
                $status  = 'no ledger';
                $statusBadge = 'secondary';
            }

            $reconciliationData[] = [
                'manager_id'       => $mid,
                'full_name'        => htmlspecialchars($mgr['full_name'] ?? 'N/A'),
                'unit_name'        => htmlspecialchars($mgr['unit_name'] ?? '-'),
                'opening_balance'  => $opening,
                'total_advance'    => $advGiven,
                'total_expenses'   => $expenses,
                'total_emp_adv'    => $empAdv,
                'closing_balance'  => $closing,
                'status'           => $status,
                'status_badge'     => $statusBadge,
            ];

            $reconSummary['total_opening']      += $opening;
            $reconSummary['total_advance']      += $advGiven;
            $reconSummary['total_expenses']     += $expenses;
            $reconSummary['total_emp_advances'] += $empAdv;
            $reconSummary['total_closing']      += $closing;
        }
    }
}

// ============================================================================
// SECTION 5: CSV Export Handler
// ============================================================================

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $tab = (int) ($_GET['tab'] ?? 0);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expense_report_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Helper for fputcsv (PHP 8.4 compatible)
    $writeCsv = function($handle, array $fields) {
        fputcsv($handle, $fields, ',', '"', '\\');
    };

    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($tab === 1 && !empty($ledgerTransactions)) {
        // Tab 1: Manager-wise Ledger
        $mgrName = 'Manager';
        foreach ($managersList as $ml) {
            if ($ml['employee_id'] == $tab1Manager) { $mgrName = $ml['full_name']; break; }
        }
        $writeCsv($output, ['Manager-wise Ledger Report', '', '', '', '']);
        $writeCsv($output, ['Manager:', $mgrName, '', 'Period:', $tab1From . ' to ' . $tab1To]);
        $writeCsv($output, []);
        $writeCsv($output, ['#', 'Date', 'Type', 'Category', 'Description', 'Debit', 'Credit', 'Running Balance']);

        $sl = 0;
        foreach ($ledgerTransactions as $txn) {
            $sl++;
            $date = !empty($txn['expense_date']) ? $txn['expense_date'] : substr($txn['txn_date'], 0, 10);
            $typeLabel = $txn['txn_type'] === 'allocation' ? 'Advance Allocation' : ucfirst($txn['type'] ?? 'expense');
            $catLabel  = $txn['txn_type'] === 'allocation' ? '-' : str_replace('_', ' ', ucfirst($txn['category'] ?? 'expense'));
            $debit     = $txn['txn_type'] === 'allocation' ? '' : number_format((float) $txn['amount'], 2);
            $credit    = $txn['txn_type'] === 'allocation' ? number_format((float) $txn['amount'], 2) : '';
            $writeCsv($output, [
                $sl, $date, $typeLabel, $catLabel,
                $txn['description'] ?? '', $debit, $credit,
                number_format((float) $txn['running_balance'], 2)
            ]);
        }
        $writeCsv($output, []);
        $writeCsv($output, ['Summary', '', '', '', '', '']);
        $writeCsv($output, ['Total Advance Received:', '', '', '', '', number_format($ledgerSummary['total_advance'], 2)]);
        $writeCsv($output, ['Total Expenses:', '', '', '', '', number_format($ledgerSummary['total_expenses'], 2)]);
        $writeCsv($output, ['Total Employee Advances:', '', '', '', '', number_format($ledgerSummary['total_emp_advances'], 2)]);
        $writeCsv($output, ['Net Balance:', '', '', '', '', number_format($ledgerSummary['net_balance'], 2)]);

    } elseif ($tab === 2 && !empty($categoryData)) {
        // Tab 2: Category Report
        $monthName = date('F', mktime(0, 0, 0, (int) $tab2Month, 1));
        $writeCsv($output, ['Expense Category Report', '', '', '']);
        $writeCsv($output, ['Period:', $monthName . ' ' . $tab2Year]);
        $writeCsv($output, []);
        $writeCsv($output, ['#', 'Type', 'Category', 'Count', 'Total Amount', '% of Total']);

        $sl = 0;
        foreach ($categoryData as $row) {
            $sl++;
            $pct = $categoryGrandTotal > 0 ? round((float) $row['total_amount'] / $categoryGrandTotal * 100, 1) : 0;
            $writeCsv($output, [
                $sl,
                ucfirst($row['type'] ?? 'other'),
                str_replace('_', ' ', ucfirst($row['category'] ?? 'expense')),
                $row['count'],
                number_format((float) $row['total_amount'], 2),
                $pct . '%'
            ]);
        }
        $writeCsv($output, []);
        $writeCsv($output, ['Grand Total', '', '', array_sum(array_column($categoryData, 'count')), number_format($categoryGrandTotal, 2), '100%']);

    } elseif ($tab === 3 && !empty($reconciliationData)) {
        // Tab 3: Reconciliation Report
        $monthName = date('F', mktime(0, 0, 0, (int) $tab3Month, 1));
        $writeCsv($output, ['Monthly Reconciliation Report', '', '', '', '', '', '', '']);
        $writeCsv($output, ['Period:', $monthName . ' ' . $tab3Year]);
        $writeCsv($output, []);
        $writeCsv($output, ['#', 'Manager', 'Unit', 'Opening Balance', 'Advance Given', 'Expenses', 'Emp. Advances', 'Closing Balance', 'Status']);

        $sl = 0;
        foreach ($reconciliationData as $row) {
            $sl++;
            $writeCsv($output, [
                $sl,
                $row['full_name'],
                $row['unit_name'],
                number_format($row['opening_balance'], 2),
                number_format($row['total_advance'], 2),
                number_format($row['total_expenses'], 2),
                number_format($row['total_emp_adv'], 2),
                number_format($row['closing_balance'], 2),
                ucfirst(str_replace('_', ' ', $row['status']))
            ]);
        }
        $writeCsv($output, []);
        $writeCsv($output, [
            'TOTAL', '', '',
            number_format($reconSummary['total_opening'], 2),
            number_format($reconSummary['total_advance'], 2),
            number_format($reconSummary['total_expenses'], 2),
            number_format($reconSummary['total_emp_advances'], 2),
            number_format($reconSummary['total_closing'], 2),
            ''
        ]);
    } else {
        $writeCsv($output, ['No data found for the selected filters.']);
    }

    fclose($output);
    exit;
}

// ============================================================================
// SECTION 6: Helper data for view
// ============================================================================

$currentYear = (int) date('Y');
$currentMonth = (int) date('m');

// Color map for pie-chart-style badges
$categoryColors = [
    'travel'   => ['#0d6efd', '#e7f0ff'],
    'food'     => ['#198754', '#e6f4ea'],
    'cab'      => ['#6f42c1', '#f0e6ff'],
    'supplies' => ['#fd7e14', '#fff3e6'],
    'medical'  => ['#dc3545', '#fde8ea'],
    'other'    => ['#6c757d', '#e9ecef'],
];
$categoryIcons = [
    'travel'   => 'bi-airplane',
    'food'     => 'bi-cup-hot',
    'cab'      => 'bi-taxi-front',
    'supplies' => 'bi-box-seam',
    'medical'  => 'bi-heart-pulse',
    'other'    => 'bi-three-dots',
];

// ============================================================================
// SECTION 7: HTML Output
// ============================================================================
?>

<style>
    /* ── Report-specific styles ─────────────────────────────────────────── */
    .report-section {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    .report-section .section-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e9ecef;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .report-section .section-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 0.95rem;
        color: #343a40;
    }
    .report-table {
        margin-bottom: 0;
        font-size: 0.85rem;
    }
    .report-table thead th {
        background: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
        color: #495057;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.5px;
        padding: 0.7rem 0.85rem;
        white-space: nowrap;
    }
    .report-table tbody td {
        padding: 0.6rem 0.85rem;
        vertical-align: middle;
        border-color: #f1f3f5;
    }
    .report-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    .report-table tfoot td,
    .report-table tfoot th {
        background: #e9ecef;
        font-weight: 700;
        font-size: 0.8rem;
        padding: 0.65rem 0.85rem;
        border-top: 2px solid #dee2e6;
    }

    .balance-positive { color: #198754; font-weight: 600; }
    .balance-negative { color: #dc3545; font-weight: 600; }
    .balance-zero     { color: #6c757d; font-weight: 600; }

    /* Summary cards row */
    .summary-cards .summary-card {
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 1rem 1.25rem;
        text-align: center;
        background: #fff;
        transition: box-shadow 0.2s;
    }
    .summary-cards .summary-card:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    .summary-cards .summary-card .sc-value {
        font-size: 1.3rem;
        font-weight: 700;
        line-height: 1.3;
    }
    .summary-cards .summary-card .sc-label {
        font-size: 0.72rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 500;
        margin-top: 2px;
    }
    .sc-green  { border-left: 4px solid #198754; }
    .sc-orange { border-left: 4px solid #fd7e14; }
    .sc-blue   { border-left: 4px solid #0d6efd; }
    .sc-red    { border-left: 4px solid #dc3545; }
    .sc-purple { border-left: 4px solid #6f42c1; }

    /* Pie-chart-style visual */
    .pie-bar {
        height: 12px;
        border-radius: 6px;
        overflow: hidden;
        display: flex;
        background: #e9ecef;
    }
    .pie-bar .pie-segment {
        height: 100%;
        transition: width 0.4s ease;
        position: relative;
    }
    .pie-bar .pie-segment:hover {
        opacity: 0.85;
    }
    .pie-legend-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 0.65rem;
        border-radius: 8px;
        font-size: 0.8rem;
        transition: background 0.15s;
    }
    .pie-legend-item:hover {
        background: #f8f9fa;
    }
    .pie-legend-dot {
        width: 12px;
        height: 12px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .empty-state {
        text-align: center;
        padding: 2.5rem 1rem;
        color: #6c757d;
    }
    .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 0.75rem;
        opacity: 0.4;
    }

    /* Tab content padding */
    .tab-content-report {
        padding-top: 1.5rem;
    }

    /* Filter bar */
    .filter-bar {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
    }

    /* Print styles */
    @media print {
        body * { visibility: hidden; }
        #printableArea, #printableArea * { visibility: visible; }
        #printableArea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            padding: 10px;
        }
        .no-print { display: none !important; }
        .report-table { font-size: 10px; }
        .report-table th, .report-table td { padding: 3px 6px !important; }
        .summary-cards .summary-card { padding: 0.5rem; }
        .summary-cards .summary-card .sc-value { font-size: 1rem; }
        .report-section { border: 1px solid #ccc; break-inside: avoid; }
        .pie-bar { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .badge, .pie-legend-dot { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    }
</style>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-1 fw-bold text-dark">
            <i class="bi bi-graph-up me-2 text-primary"></i>Expense Reports
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php?page=expense/dashboard">Expense</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print" title="Print / Save as PDF">
            <i class="bi bi-printer me-1"></i> Print / PDF
        </button>
    </div>
</div>

<!-- Printable area wrapper -->
<div id="printableArea">

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB NAVIGATION                                                         -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-0 no-print" id="reportTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab1-tab" data-bs-toggle="tab"
                data-bs-target="#tab1Ledger" type="button" role="tab"
                aria-controls="tab1Ledger" aria-selected="true">
            <i class="bi bi-journal-text me-1"></i> Manager Ledger
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab2-tab" data-bs-toggle="tab"
                data-bs-target="#tab2Category" type="button" role="tab"
                aria-controls="tab2Category" aria-selected="false">
            <i class="bi bi-pie-chart me-1"></i> Category Report
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab3-tab" data-bs-toggle="tab"
                data-bs-target="#tab3Reconciliation" type="button" role="tab"
                aria-controls="tab3Reconciliation" aria-selected="false">
            <i class="bi bi-arrow-repeat me-1"></i> Monthly Reconciliation
        </button>
    </li>
</ul>

<div class="tab-content tab-content-report" id="reportTabContent">

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: MANAGER-WISE LEDGER REPORT                                      -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tab1Ledger" role="tabpanel" aria-labelledby="tab1-tab">

    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form method="GET" action="<?= htmlspecialchars($baseUrl) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="expense/reports">

            <div class="col-md-3 col-sm-6">
                <label class="form-label small fw-semibold">Manager <span class="text-danger">*</span></label>
                <select name="tab1_manager" class="form-select form-select-sm" id="tab1_manager" required>
                    <option value="">-- Select Manager --</option>
                    <?php foreach ($managersList as $m): ?>
                        <option value="<?= htmlspecialchars($m['employee_id'] ?? '') ?>"
                            <?= $tab1Manager == $m['employee_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['full_name'] ?? '') ?>
                            <?php if (!empty($m['unit_name'])): ?> (<?= htmlspecialchars($m['unit_name']) ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small fw-semibold">From Date <span class="text-danger">*</span></label>
                <input type="date" name="tab1_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($tab1From) ?>" required>
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small fw-semibold">To Date <span class="text-danger">*</span></label>
                <input type="date" name="tab1_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($tab1To) ?>" required>
            </div>

            <div class="col-md-2 col-sm-6">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i> Generate
                </button>
            </div>

            <div class="col-md-3 col-sm-12 d-flex gap-1">
                <?php if (!empty($ledgerTransactions)): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>&tab1_manager=<?= urlencode($tab1Manager) ?>&tab1_from=<?= urlencode($tab1From) ?>&tab1_to=<?= urlencode($tab1To) ?>&export=csv&tab=1"
                       class="btn btn-success btn-sm flex-grow-1" title="Export to CSV">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary btn-sm" title="Clear">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>

    <?php if ($tab1Manager !== '' && $tab1From !== '' && $tab1To !== ''): ?>

        <?php if (empty($ledgerTransactions)): ?>
            <div class="report-section">
                <div class="empty-state">
                    <i class="bi bi-journal-x d-block"></i>
                    <p class="mb-0">No transactions found for the selected manager and date range.</p>
                </div>
            </div>
        <?php else: ?>

            <!-- Summary Cards -->
            <div class="summary-cards row g-2 mb-3">
                <div class="col-lg-3 col-md-6">
                    <div class="summary-card sc-green">
                        <div class="sc-value text-success"><?= formatCurrency($ledgerSummary['total_advance']) ?></div>
                        <div class="sc-label">Total Advance</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="summary-card sc-orange">
                        <div class="sc-value text-warning"><?= formatCurrency($ledgerSummary['total_expenses']) ?></div>
                        <div class="sc-label">Total Expenses</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="summary-card sc-blue">
                        <div class="sc-value text-info"><?= formatCurrency($ledgerSummary['total_emp_advances']) ?></div>
                        <div class="sc-label">Total Emp. Advances</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <?php
                        $netClass = $ledgerSummary['net_balance'] > 0 ? 'text-success'
                            : ($ledgerSummary['net_balance'] < 0 ? 'text-danger' : 'text-muted');
                        $netBorder = $ledgerSummary['net_balance'] > 0 ? 'sc-green'
                            : ($ledgerSummary['net_balance'] < 0 ? 'sc-red' : 'sc-blue');
                    ?>
                    <div class="summary-card <?= $netBorder ?>">
                        <div class="sc-value <?= $netClass ?>"><?= formatCurrency($ledgerSummary['net_balance']) ?></div>
                        <div class="sc-label">Net Balance</div>
                    </div>
                </div>
            </div>

            <!-- Ledger Table -->
            <div class="report-section">
                <div class="section-header">
                    <h5>
                        <i class="bi bi-journal-text me-2 text-primary"></i>
                        Ledger: <?= htmlspecialchars(array_reduce($managersList, function($c, $m) use ($tab1Manager) { return $m['employee_id'] == $tab1Manager ? $m['full_name'] : $c; }, 'Manager')) ?>
                        <small class="text-muted fw-normal ms-2"><?= htmlspecialchars($tab1From) ?> to <?= htmlspecialchars($tab1To) ?></small>
                    </h5>
                    <span class="badge bg-light text-dark border"><?= count($ledgerTransactions) ?> transactions</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover report-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:40px;">#</th>
                                <th style="width:100px;">Date</th>
                                <th style="width:80px;">Type</th>
                                <th style="width:80px;">Category</th>
                                <th>Description</th>
                                <th class="text-end" style="width:110px;">Credit (In)</th>
                                <th class="text-end" style="width:110px;">Debit (Out)</th>
                                <th class="text-end" style="width:130px;">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 0; foreach ($ledgerTransactions as $txn): $sl++; ?>
                                <?php
                                    $isCredit = ($txn['txn_type'] === 'allocation');
                                    $txnDate = !empty($txn['expense_date']) ? $txn['expense_date'] : substr($txn['txn_date'] ?? '', 0, 10);
                                    $rb = (float) $txn['running_balance'];
                                    $rbClass = $rb > 0 ? 'balance-positive' : ($rb < 0 ? 'balance-negative' : 'balance-zero');
                                ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $sl ?></td>
                                    <td>
                                        <?= !empty($txnDate) ? date('d M Y', strtotime($txnDate)) : '-' ?>
                                    </td>
                                    <td>
                                        <?php if ($isCredit): ?>
                                            <span class="badge bg-success"><i class="bi bi-arrow-down-circle me-1"></i>Advance</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-arrow-up-circle me-1"></i><?= htmlspecialchars(ucfirst($txn['type'] ?? 'expense')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isCredit): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">
                                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst($txn['category'] ?? 'expense'))) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span title="<?= htmlspecialchars($txn['description'] ?? '') ?>">
                                            <?= htmlspecialchars(mb_strimwidth($txn['description'] ?? '', 0, 50, '...')) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($isCredit): ?>
                                            <span class="fw-semibold text-success">+<?= formatCurrency($txn['amount']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$isCredit): ?>
                                            <span class="fw-semibold text-danger">-<?= formatCurrency($txn['amount']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="<?= $rbClass ?>"><?= formatCurrency($rb) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end fw-bold">Summary</td>
                                <td class="text-end text-success fw-bold"><?= formatCurrency($ledgerSummary['total_advance']) ?></td>
                                <td class="text-end text-danger fw-bold"><?= formatCurrency($ledgerSummary['total_expenses'] + $ledgerSummary['total_emp_advances']) ?></td>
                                <td class="text-end fw-bold <?= $ledgerSummary['net_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatCurrency($ledgerSummary['net_balance']) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        <?php endif; ?>
    <?php else: ?>
        <!-- Placeholder when no filters applied -->
        <div class="report-section">
            <div class="empty-state">
                <i class="bi bi-funnel d-block"></i>
                <p class="mb-0">Select a manager and date range above to generate the ledger report.</p>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /tab1 -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: EXPENSE CATEGORY REPORT                                          -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab2Category" role="tabpanel" aria-labelledby="tab2-tab">

    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form method="GET" action="<?= htmlspecialchars($baseUrl) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="expense/reports">

            <div class="col-md-2 col-sm-4">
                <label class="form-label small fw-semibold">Month <span class="text-danger">*</span></label>
                <select name="tab2_month" class="form-select form-select-sm" required>
                    <option value="">-- Select --</option>
                    <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                        <option value="<?= $mi ?>" <?= $tab2Month === (string)$mi ? 'selected' : '' ?>>
                            <?= str_pad($mi, 2, '0', STR_PAD_LEFT) ?> - <?= date('F', mktime(0, 0, 0, $mi, 1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-3">
                <label class="form-label small fw-semibold">Year <span class="text-danger">*</span></label>
                <select name="tab2_year" class="form-select form-select-sm" required>
                    <option value="">-- Select --</option>
                    <?php for ($yi = $currentYear + 1; $yi >= $currentYear - 3; $yi--): ?>
                        <option value="<?= $yi ?>" <?= $tab2Year === (string)$yi ? 'selected' : '' ?>><?= $yi ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-3 col-sm-5">
                <label class="form-label small fw-semibold">Manager (Optional)</label>
                <select name="tab2_manager" class="form-select form-select-sm">
                    <option value="">All Managers</option>
                    <?php foreach ($managersList as $m): ?>
                        <option value="<?= htmlspecialchars($m['employee_id'] ?? '') ?>"
                            <?= $tab2Manager == $m['employee_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['full_name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i> Generate
                </button>
            </div>

            <div class="col-md-3 col-sm-6 d-flex gap-1">
                <?php if (!empty($categoryData)): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>&tab2_month=<?= urlencode($tab2Month) ?>&tab2_year=<?= urlencode($tab2Year) ?>&tab2_manager=<?= urlencode($tab2Manager) ?>&export=csv&tab=2"
                       class="btn btn-success btn-sm flex-grow-1" title="Export to CSV">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary btn-sm" title="Clear">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>

    <?php if ($tab2Month !== '' && $tab2Year !== ''): ?>

        <?php if (empty($categoryData)): ?>
            <div class="report-section">
                <div class="empty-state">
                    <i class="bi bi-pie-chart d-block"></i>
                    <p class="mb-0">No approved expenses found for the selected period.</p>
                </div>
            </div>
        <?php else: ?>

            <!-- Pie-chart visual summary -->
            <div class="report-section mb-3">
                <div class="section-header">
                    <h5><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Distribution Overview</h5>
                    <span class="badge bg-primary"><?= formatCurrency($categoryGrandTotal) ?> total</span>
                </div>
                <div class="p-3">
                    <!-- Stacked bar -->
                    <div class="pie-bar mb-3">
                        <?php foreach ($categoryData as $row):
                            $pct = $categoryGrandTotal > 0 ? round((float) $row['total_amount'] / $categoryGrandTotal * 100, 1) : 0;
                            $type = $row['type'] ?? 'other';
                            $color = $categoryColors[$type][0] ?? '#6c757d';
                        ?>
                            <div class="pie-segment" style="width: <?= $pct ?>%; background: <?= $color ?>;"
                                 title="<?= htmlspecialchars(ucfirst($type)) ?>: <?= formatCurrency($row['total_amount']) ?> (<?= $pct ?>%)"></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Legend -->
                    <div class="row g-2">
                        <?php foreach ($categoryData as $row):
                            $pct = $categoryGrandTotal > 0 ? round((float) $row['total_amount'] / $categoryGrandTotal * 100, 1) : 0;
                            $type = $row['type'] ?? 'other';
                            $color = $categoryColors[$type][0] ?? '#6c757d';
                            $bgColor = $categoryColors[$type][1] ?? '#e9ecef';
                            $icon = $categoryIcons[$type] ?? 'bi-three-dots';
                            $catLabel = str_replace('_', ' ', ucfirst($row['category'] ?? 'expense'));
                        ?>
                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="pie-legend-item" style="background: <?= $bgColor ?>;">
                                    <div class="pie-legend-dot" style="background: <?= $color ?>;"></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">
                                            <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars(ucfirst($type)) ?>
                                        </div>
                                        <small class="text-muted"><?= $catLabel ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold" style="color: <?= $color ?>;"><?= formatCurrency($row['total_amount']) ?></div>
                                        <small class="text-muted"><?= $row['count'] ?> items · <?= $pct ?>%</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown Table -->
            <div class="report-section">
                <div class="section-header">
                    <h5><i class="bi bi-table me-2 text-primary"></i>Category Breakdown</h5>
                    <span class="badge bg-light text-dark border"><?= count($categoryData) ?> groups</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover report-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:40px;">#</th>
                                <th style="width:100px;">Expense Type</th>
                                <th style="width:120px;">Category</th>
                                <th class="text-center" style="width:80px;">Count</th>
                                <th class="text-end" style="width:130px;">Total Amount</th>
                                <th style="width:200px;">Share</th>
                                <th class="text-center" style="width:80px;">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 0; $totalCount = array_sum(array_column($categoryData, 'count'));
                            foreach ($categoryData as $row): $sl++;
                                $pct = $categoryGrandTotal > 0 ? round((float) $row['total_amount'] / $categoryGrandTotal * 100, 1) : 0;
                                $type = $row['type'] ?? 'other';
                                $color = $categoryColors[$type][0] ?? '#6c757d';
                                $catLabel = str_replace('_', ' ', ucfirst($row['category'] ?? 'expense'));
                            ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $sl ?></td>
                                    <td>
                                        <span class="badge" style="background: <?= $color ?>; color: #fff;">
                                            <i class="bi <?= $categoryIcons[$type] ?? 'bi-three-dots' ?> me-1"></i>
                                            <?= htmlspecialchars(ucfirst($type)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($catLabel) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-semibold"><?= (int) $row['count'] ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold"><?= formatCurrency($row['total_amount']) ?></span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar" role="progressbar"
                                                 style="width: <?= $pct ?>%; background: <?= $color ?>;"
                                                 aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </td>
                                    <td class="text-center fw-semibold"><?= $pct ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end fw-bold">Grand Total</td>
                                <td class="text-center fw-bold"><?= (int) $totalCount ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($categoryGrandTotal) ?></td>
                                <td></td>
                                <td class="text-center fw-bold">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        <?php endif; ?>
    <?php else: ?>
        <div class="report-section">
            <div class="empty-state">
                <i class="bi bi-calendar3 d-block"></i>
                <p class="mb-0">Select a month and year above to generate the category report.</p>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /tab2 -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3: MONTHLY RECONCILIATION REPORT                                    -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab3Reconciliation" role="tabpanel" aria-labelledby="tab3-tab">

    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form method="GET" action="<?= htmlspecialchars($baseUrl) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="expense/reports">

            <div class="col-md-2 col-sm-4">
                <label class="form-label small fw-semibold">Month <span class="text-danger">*</span></label>
                <select name="tab3_month" class="form-select form-select-sm" required>
                    <option value="">-- Select --</option>
                    <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                        <option value="<?= $mi ?>" <?= $tab3Month === (string)$mi ? 'selected' : '' ?>>
                            <?= str_pad($mi, 2, '0', STR_PAD_LEFT) ?> - <?= date('F', mktime(0, 0, 0, $mi, 1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-3">
                <label class="form-label small fw-semibold">Year <span class="text-danger">*</span></label>
                <select name="tab3_year" class="form-select form-select-sm" required>
                    <option value="">-- Select --</option>
                    <?php for ($yi = $currentYear + 1; $yi >= $currentYear - 3; $yi--): ?>
                        <option value="<?= $yi ?>" <?= $tab3Year === (string)$yi ? 'selected' : '' ?>><?= $yi ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-5">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i> Generate
                </button>
            </div>

            <div class="col-md-6 col-sm-12 d-flex gap-1">
                <?php if (!empty($reconciliationData)): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>&tab3_month=<?= urlencode($tab3Month) ?>&tab3_year=<?= urlencode($tab3Year) ?>&export=csv&tab=3"
                       class="btn btn-success btn-sm" title="Export to CSV">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary btn-sm" title="Clear">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>

    <?php if ($tab3Month !== '' && $tab3Year !== ''): ?>

        <?php if (empty($reconciliationData)): ?>
            <div class="report-section">
                <div class="empty-state">
                    <i class="bi bi-people d-block"></i>
                    <p class="mb-0">No managers found in employee cache.</p>
                </div>
            </div>
        <?php else:

            $monthName = date('F', mktime(0, 0, 0, (int) $tab3Month, 1));
        ?>

            <!-- Summary Cards -->
            <div class="summary-cards row g-2 mb-3">
                <div class="col-lg col-md-4 col-sm-6">
                    <div class="summary-card sc-blue">
                        <div class="sc-value text-primary"><?= formatCurrency($reconSummary['total_opening']) ?></div>
                        <div class="sc-label">Total Opening</div>
                    </div>
                </div>
                <div class="col-lg col-md-4 col-sm-6">
                    <div class="summary-card sc-green">
                        <div class="sc-value text-success"><?= formatCurrency($reconSummary['total_advance']) ?></div>
                        <div class="sc-label">Total Advance Given</div>
                    </div>
                </div>
                <div class="col-lg col-md-4 col-sm-6">
                    <div class="summary-card sc-orange">
                        <div class="sc-value text-warning"><?= formatCurrency($reconSummary['total_expenses']) ?></div>
                        <div class="sc-label">Total Expenses</div>
                    </div>
                </div>
                <div class="col-lg col-md-4 col-sm-6">
                    <div class="summary-card sc-purple">
                        <div class="sc-value" style="color: #6f42c1;"><?= formatCurrency($reconSummary['total_emp_advances']) ?></div>
                        <div class="sc-label">Total Emp. Advances</div>
                    </div>
                </div>
                <div class="col-lg col-md-4 col-sm-6">
                    <?php
                        $closingClass = $reconSummary['total_closing'] > 0 ? 'text-success'
                            : ($reconSummary['total_closing'] < 0 ? 'text-danger' : 'text-muted');
                        $closingBorder = $reconSummary['total_closing'] > 0 ? 'sc-green'
                            : ($reconSummary['total_closing'] < 0 ? 'sc-red' : 'sc-blue');
                    ?>
                    <div class="summary-card <?= $closingBorder ?>">
                        <div class="sc-value <?= $closingClass ?>"><?= formatCurrency($reconSummary['total_closing']) ?></div>
                        <div class="sc-label">Total Closing</div>
                    </div>
                </div>
            </div>

            <!-- Reconciliation Table -->
            <div class="report-section">
                <div class="section-header">
                    <h5>
                        <i class="bi bi-arrow-repeat me-2 text-primary"></i>
                        Reconciliation: <?= htmlspecialchars($monthName) ?> <?= htmlspecialchars($tab3Year) ?>
                    </h5>
                    <span class="badge bg-light text-dark border"><?= count($reconciliationData) ?> managers</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover report-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:40px;">#</th>
                                <th style="width:160px;">Manager</th>
                                <th style="width:120px;">Unit</th>
                                <th class="text-end" style="width:110px;">Opening Bal.</th>
                                <th class="text-end" style="width:110px;">Advance Given</th>
                                <th class="text-end" style="width:110px;">Expenses</th>
                                <th class="text-end" style="width:110px;">Emp. Advances</th>
                                <th class="text-end" style="width:120px;">Closing Bal.</th>
                                <th class="text-center" style="width:100px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 0;
                            foreach ($reconciliationData as $row): $sl++;
                                $closing = $row['closing_balance'];
                                $closingClass = $closing > 0 ? 'balance-positive' : ($closing < 0 ? 'balance-negative' : 'balance-zero');
                            ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $sl ?></td>
                                    <td>
                                        <strong><?= $row['full_name'] ?></strong>
                                    </td>
                                    <td>
                                        <small><?= $row['unit_name'] ?></small>
                                    </td>
                                    <td class="text-end"><?= formatCurrency($row['opening_balance']) ?></td>
                                    <td class="text-end">
                                        <span class="text-success fw-semibold"><?= formatCurrency($row['total_advance']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-warning fw-semibold"><?= formatCurrency($row['total_expenses']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-semibold" style="color: #6f42c1;"><?= formatCurrency($row['total_emp_adv']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="<?= $closingClass ?> fw-bold"><?= formatCurrency($closing) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                            $statusLabels = [
                                                'settled'    => 'Settled',
                                                'open'       => 'Open',
                                                'overdrawn'  => 'Overdrawn',
                                                'no ledger'  => 'No Ledger',
                                            ];
                                        ?>
                                        <span class="badge bg-<?= $row['status_badge'] ?>">
                                            <?= htmlspecialchars($statusLabels[$row['status']] ?? ucfirst($row['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="fw-bold">TOTAL</td>
                                <td class="text-muted"></td>
                                <td class="text-end fw-bold"><?= formatCurrency($reconSummary['total_opening']) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($reconSummary['total_advance']) ?></td>
                                <td class="text-end fw-bold text-warning"><?= formatCurrency($reconSummary['total_expenses']) ?></td>
                                <td class="text-end fw-bold" style="color: #6f42c1;"><?= formatCurrency($reconSummary['total_emp_advances']) ?></td>
                                <?php
                                    $footClosingClass = $reconSummary['total_closing'] >= 0 ? 'text-success' : 'text-danger';
                                ?>
                                <td class="text-end fw-bold <?= $footClosingClass ?>"><?= formatCurrency($reconSummary['total_closing']) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        <?php endif; ?>
    <?php else: ?>
        <div class="report-section">
            <div class="empty-state">
                <i class="bi bi-calendar-check d-block"></i>
                <p class="mb-0">Select a month and year above to generate the reconciliation report.</p>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /tab3 -->

</div><!-- /tab-content -->
</div><!-- /printableArea -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                             -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Restore active tab from URL hash ──────────────────────────────────
    var hash = window.location.hash;
    if (hash) {
        var tabTrigger = document.querySelector('button[data-bs-target="' + hash + '"]');
        if (tabTrigger) {
            var tab = new bootstrap.Tab(tabTrigger);
            tab.show();
        }
    }

    // ── Set date defaults when manager is selected in Tab 1 ───────────────
    var tab1ManagerEl = document.getElementById('tab1_manager');
    var tab1FromEl = document.querySelector('input[name="tab1_from"]');
    var tab1ToEl = document.querySelector('input[name="tab1_to"]');

    if (tab1ManagerEl && tab1FromEl && tab1ToEl && !tab1FromEl.value) {
        // Default to current month range
        var now = new Date();
        var firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        var lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        tab1FromEl.value = firstDay.toISOString().split('T')[0];
        tab1ToEl.value = lastDay.toISOString().split('T')[0];
    }

    // ── Auto-set Tab 2 and Tab 3 month/year to current if empty ───────────
    var tab2MonthEl = document.querySelector('select[name="tab2_month"]');
    var tab2YearEl = document.querySelector('select[name="tab2_year"]');
    var tab3MonthEl = document.querySelector('select[name="tab3_month"]');
    var tab3YearEl = document.querySelector('select[name="tab3_year"]');

    var now = new Date();
    var curMonth = String(now.getMonth() + 1);
    var curYear = String(now.getFullYear());

    if (tab2MonthEl && tab2MonthEl.value === '') tab2MonthEl.value = curMonth;
    if (tab2YearEl && tab2YearEl.value === '') tab2YearEl.value = curYear;
    if (tab3MonthEl && tab3MonthEl.value === '') tab3MonthEl.value = curMonth;
    if (tab3YearEl && tab3YearEl.value === '') tab3YearEl.value = curYear;

});
</script>
