<?php
/**
 * RCS HRMS - Expense Management (Single Page)
 * Modern UI with tabs: Dashboard, Add Advance, Add Expense, Approvals, Reports
 * Route: index.php?page=expense/dashboard
 */

if (!isset($db) || !is_object($db)) { header("Location: index.php"); exit; }

$pageTitle = 'Expense Management';
require_once __DIR__ . '/expense-setup.php';
// xlsx_reader still available if needed but CSV is primary format now

$baseUrl = 'index.php?page=expense/dashboard';
$activeTab = sanitize($_GET['tab'] ?? 'dashboard');
if (!in_array($activeTab, ['dashboard','advance','expense','approvals','reports','upload'])) $activeTab = 'dashboard';
// Report sub-tabs
$rptSub = sanitize($_GET['rpt_sub'] ?? 'monthly');
if (!in_array($rptSub, ['monthly','yearly','ledger','category','pending','top','stats','trend','emp_adv','bill'])) $rptSub = 'monthly';

// Shared data
$currentYear = (int)date('Y');
$currentMonth = (int)prev_month_num();
$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$monthShort = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

// ============================================================================
// POST HANDLERS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);

    // ── Allocate Advance ──────────────────────────────────────────
    if ($action === 'allocate_advance') {
        $manager_id = sanitize(trim($_POST['manager_id'] ?? ''));
        $amount     = round(floatval($_POST['amount'] ?? 0), 2);
        $remarks    = sanitize(trim($_POST['remarks'] ?? ''));
        $allocMonth = (int)($_POST['alloc_month'] ?? 0);
        $allocYear  = (int)($_POST['alloc_year'] ?? 0);
        $allocDate  = sanitize($_POST['alloc_date'] ?? '');
        if ($manager_id && $amount > 0 && $allocMonth >= 1 && $allocMonth <= 12 && $allocYear >= 2000) {
            // ── Calculate carry-forward from previous month ──
            $carryForward = 0;
            $prevMonth = $allocMonth - 1; $prevYear = $allocYear;
            if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
            try {
                $prevAlloc = (float)$db->fetchColumn(
                    "SELECT COALESCE(SUM(amount + COALESCE(carry_forward_amount,0)),0) FROM manager_advance_allocations WHERE manager_id=:m AND month=:mo AND year=:yr",
                    ['m'=>$manager_id,'mo'=>$prevMonth,'yr'=>$prevYear]
                );
                $monthStart = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
                $monthEnd   = sprintf('%04d-%02d-31', $prevYear, $prevMonth);
                // Include ESS app entries (manager_id=0 but employee_id matches)
                $prevExpenses = (float)$db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='expense' AND status='approved' AND (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0'))) AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed",
                    ['m'=>$manager_id,'m2'=>$manager_id,'sd'=>$monthStart,'ed'=>$monthEnd]
                );
                $prevEmpAdv = (float)$db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='employee_advance' AND status='approved' AND (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0'))) AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed",
                    ['m'=>$manager_id,'m2'=>$manager_id,'sd'=>$monthStart,'ed'=>$monthEnd]
                );
                $carryForward = round($prevAlloc - $prevExpenses - $prevEmpAdv, 2);
                if ($carryForward < 0) $carryForward = 0;
            } catch (Exception $e) {}

            $insertData = [
                'manager_id' => $manager_id, 'amount' => $amount,
                'month' => $allocMonth, 'year' => $allocYear,
                'alloc_date' => ($allocDate ? $allocDate : null),
                'carry_forward_amount' => $carryForward,
                'carry_forward_from_month' => ($carryForward > 0 ? $prevMonth : null),
                'carry_forward_from_year'  => ($carryForward > 0 ? $prevYear : null),
                'remarks' => $remarks, 'allocated_by' => $_SESSION['user_id'] ?? 'admin',
            ];
            $db->insert('manager_advance_allocations', $insertData);
            $msg = 'Advance of &#8377;' . number_format($amount,2) . ' allocated for ' . $monthNames[$allocMonth] . ' ' . $allocYear . '.';
            if ($carryForward > 0) {
                $msg .= ' Carry-forward from ' . $monthNames[$prevMonth] . ': &#8377;' . number_format($carryForward,2);
            }
            setFlash('success', $msg);
        } else {
            setFlash('danger', 'Please fill all required fields.');
        }
        redirect($baseUrl . '&tab=advance');
    }

    // ── Add Expense ───────────────────────────────────────────────
    if ($action === 'add_expense') {
        $manager_id  = sanitize(trim($_POST['manager_id'] ?? ''));
        $category    = sanitize($_POST['category'] ?? 'expense');
        $type        = sanitize($_POST['type'] ?? 'other');
        $amount      = round(floatval($_POST['amount'] ?? 0), 2);
        $description = sanitize(trim($_POST['description'] ?? ''));
        $expense_date = sanitize($_POST['expense_date'] ?? '');
        $emp_name    = sanitize(trim($_POST['emp_name'] ?? ''));
        $emp_code    = sanitize(trim($_POST['emp_code'] ?? ''));

        if ($manager_id && $amount > 0 && $expense_date) {
            // Get manager info for emp fields
            if (empty($emp_name)) {
                try {
                    $mgrInfo = $db->fetch("SELECT full_name, designation FROM ess_employee_cache WHERE employee_id = :mid", ['mid' => $manager_id]);
                    if ($mgrInfo) { $emp_name = $mgrInfo['full_name']; $emp_code = $manager_id; }
                } catch (Exception $e) {}
            }
            $expMonth = (int)date('m', strtotime($expense_date));
            $expYear  = (int)date('Y', strtotime($expense_date));

            $insertData = [
                'employee_id' => $manager_id, 'category' => $category, 'type' => $type,
                'amount' => $amount, 'description' => $description, 'expense_date' => $expense_date,
                'status' => 'pending', 'manager_id' => $manager_id,
                'emp_name' => $emp_name, 'emp_code' => $emp_code,
                'month' => $expMonth, 'year' => $expYear,
            ];
            if ($monthColExists) {
                $insertData['month'] = $expMonth;
                $insertData['year'] = $expYear;
            }
            $db->insert('ess_expenses', $insertData);
            setFlash('success', 'Expense of &#8377;' . number_format($amount,2) . ' added successfully.');
        } else {
            setFlash('danger', 'Please fill all required fields.');
        }
        redirect($baseUrl . '&tab=expense');
    }

    // ── Approve ───────────────────────────────────────────────────
    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $exp = $db->fetch("SELECT * FROM ess_expenses WHERE id = :id", ['id' => $id]);
        if ($exp && $exp['status'] === 'pending') {
            $db->update('ess_expenses',
                ['status' => 'approved', 'approved_by' => $_SESSION['user_id'] ?? 'admin', 'approved_at' => date('Y-m-d H:i:s')],
                'id = :id', ['id' => $id]
            );
            setFlash('success', 'Expense #' . $id . ' approved.');
        }
        redirect($baseUrl . '&tab=approvals');
    }

    // ── Reject ────────────────────────────────────────────────────
    if ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = sanitize(trim($_POST['rejection_reason'] ?? ''));
        if ($id > 0 && $reason !== '') {
            $db->update('ess_expenses',
                ['status' => 'rejected', 'rejected_by' => $_SESSION['user_id'] ?? 'admin', 'rejection_reason' => $reason],
                'id = :id', ['id' => $id]
            );
            setFlash('success', 'Expense #' . $id . ' rejected.');
        } else {
            setFlash('danger', 'Rejection reason is required.');
        }
        redirect($baseUrl . '&tab=approvals');
    }

    // ── Edit Entry ────────────────────────────────────────────────
    if ($action === 'edit_entry') {
        $id   = (int)($_POST['id'] ?? 0);
        $amt  = round(floatval($_POST['amount'] ?? 0), 2);
        $desc = sanitize(trim($_POST['description'] ?? ''));
        if ($id > 0 && $amt >= 0) {
            $db->update('ess_expenses',
                ['amount' => $amt, 'description' => $desc, 'edited_by' => 'admin', 'edited_at' => date('Y-m-d H:i:s')],
                'id = :id', ['id' => $id]
            );
            setFlash('success', 'Entry updated.');
        }
        redirect($baseUrl . '&tab=' . $activeTab);
    }

    // ── Delete Allocation ─────────────────────────────────────────
    if ($action === 'delete_allocation') {
        $id = (int)($_POST['alloc_id'] ?? 0);
        if ($id > 0) {
            $db->query("DELETE FROM manager_advance_allocations WHERE id = :id", ['id' => $id]);
            setFlash('success', 'Allocation deleted.');
        }
        redirect($baseUrl . '&tab=advance');
    }

    // ── Bulk CSV Upload (Advance + Expense in one file) ─────────
    if ($action === 'bulk_xlsx_upload') {
        require_once __DIR__ . '/bulk-upload-handler.php';
    }
}
// ============================================================================
// SHARED DATA QUERIES
// ============================================================================

// Employees for dropdowns (exclude Workers & HK Lady)
$exclDesignations = ["'%worker%'", "'%hk lady%'"];
$exclWhere = 'LOWER(e.designation) NOT LIKE ' . implode(' AND LOWER(e.designation) NOT LIKE ', $exclDesignations);
$managersList = [];
try {
    $managersList = $db->fetchAll(
        "SELECT e.id AS employee_id, e.full_name, e.designation, u.name AS unit_name, u.city
         FROM employees e LEFT JOIN units u ON e.unit_id = u.id
         WHERE e.status = 'approved' AND $exclWhere
         ORDER BY e.full_name ASC"
    );
} catch (Exception $e) {
    try {
        $managersList = $db->fetchAll(
            "SELECT employee_id, full_name, designation, unit_name, city FROM ess_employee_cache
             WHERE LOWER(designation) NOT LIKE '%worker%' AND LOWER(designation) NOT LIKE '%hk lady%' ORDER BY full_name ASC"
        );
    } catch (Exception $e2) { $managersList = []; }
}

// ── Only load data for the active tab (performance) ────────────
$totalAdvanceIssued = 0; $totalExpenses = 0; $pendingApprovals = 0; $totalEmpAdvances = 0;
$managerSummary = [];
$mgrCacheList = [];

// Pending approvals count needed for badge on all tabs
try { $pendingApprovals = (int)$db->fetchColumn("SELECT COUNT(*) FROM ess_expenses WHERE status='pending'"); } catch (Exception $e) {}

if ($activeTab === 'dashboard') {
    try { $totalAdvanceIssued = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM manager_advance_allocations"); } catch (Exception $e) {}
    try {
        if ($categoryColExists) {
            $totalExpenses = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='expense' AND status='approved'");
            $totalEmpAdvances = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='employee_advance' AND status='approved'");
        } else {
            $totalExpenses = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE status='approved'");
        }
    } catch (Exception $e) {}

    // Manager summary — BULK queries (no N+1)
    try {
        $activeManagers = $db->fetchAll(
            "SELECT DISTINCT COALESCE(NULLIF(NULLIF(e.manager_id,''),'0'), e.employee_id) AS emp_id
             FROM ess_expenses e WHERE 1=1 LIMIT 500"
        );
        $allocManagers = $db->fetchAll("SELECT DISTINCT manager_id AS emp_id FROM manager_advance_allocations LIMIT 500");
        $allActiveIds = array_unique(array_merge(
            array_column($activeManagers, 'emp_id'),
            array_column($allocManagers, 'emp_id')
        ));
    } catch (Exception $e) { $allActiveIds = []; }

    if (!empty($allActiveIds)) {
        // Bulk fetch all manager info
        $placeholders = implode(',', array_fill(0, count($allActiveIds), '?'));
        $placeholders2 = implode(',', array_fill(0, count($allActiveIds), '?'));
        $mgrRows = $db->fetchAll("SELECT employee_id, full_name, designation, unit_name FROM ess_employee_cache WHERE employee_id IN ($placeholders)", array_values($allActiveIds));
        $mgrMap = [];
        foreach ($mgrRows as $mr) { $mgrMap[(string)$mr['employee_id']] = $mr; }

        // Bulk fetch advance totals per manager
        $advRows = $db->fetchAll("SELECT manager_id, COALESCE(SUM(amount),0) AS total_adv FROM manager_advance_allocations WHERE manager_id IN ($placeholders) GROUP BY manager_id", array_values($allActiveIds));
        $advMap = [];
        foreach ($advRows as $ar) { $advMap[(string)$ar['manager_id']] = (float)$ar['total_adv']; }

        // Bulk fetch expense totals per manager (include ESS app entries with manager_id=NULL/0)
        if ($categoryColExists) {
            $expRows = $db->fetchAll("SELECT COALESCE(NULLIF(NULLIF(manager_id,''),'0'), employee_id) AS mgr_id, category, COALESCE(SUM(amount),0) AS total_amt FROM ess_expenses WHERE status='approved' AND (manager_id IN ($placeholders) OR (employee_id IN ($placeholders2) AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0'))) GROUP BY mgr_id, category", array_merge(array_values($allActiveIds), array_values($allActiveIds)));
        } else {
            $expRows = $db->fetchAll("SELECT employee_id AS manager_id, 'expense' AS category, COALESCE(SUM(amount),0) AS total_amt FROM ess_expenses WHERE employee_id IN ($placeholders) AND status='approved' GROUP BY employee_id", array_values($allActiveIds));
        }
        $expMap = []; $empAdvMap = [];
        foreach ($expRows as $er) {
            $mid = (string)($er['mgr_id'] ?? $er['manager_id'] ?? $er['employee_id']);
            if (($er['category'] ?? '') === 'employee_advance') { $empAdvMap[$mid] = (float)$er['total_amt']; }
            else { $expMap[$mid] = (float)$er['total_amt']; }
        }

        // Bulk fetch pending counts
        $pendRows = $db->fetchAll("SELECT employee_id, COUNT(*) AS cnt FROM ess_expenses WHERE status='pending' AND employee_id IN ($placeholders) GROUP BY employee_id", array_values($allActiveIds));
        $pendMap = [];
        foreach ($pendRows as $pr) { $pendMap[(string)$pr['employee_id']] = (int)$pr['cnt']; }

        foreach ($allActiveIds as $mid) {
            $mid = (string)$mid;
            if (!isset($mgrMap[$mid])) continue;
            $mi = $mgrMap[$mid];
            $adv = $advMap[$mid] ?? 0;
            $exp = $expMap[$mid] ?? 0;
            $empAdv = $empAdvMap[$mid] ?? 0;
            $pend = $pendMap[$mid] ?? 0;
            $managerSummary[] = [
                'id'=>$mid, 'name'=>$mi['full_name'], 'designation'=>$mi['designation'], 'unit'=>$mi['unit_name'],
                'advance'=>$adv, 'expenses'=>$exp, 'emp_advances'=>$empAdv, 'balance'=>$adv-$exp-$empAdv, 'pending'=>$pend
            ];
        }
    }
}

// ── Advance tab data ─────────────────────────────────────────────
$advMonth = (int)($_GET['adv_month'] ?? $currentMonth);
$advYear  = (int)($_GET['adv_year'] ?? $currentYear);
if ($advMonth < 1 || $advMonth > 12) $advMonth = $currentMonth;

$allocations = [];
if ($activeTab === 'advance') {
    try {
        $allocations = $db->fetchAll(
            "SELECT ma.*, ec.full_name, ec.designation, ec.unit_name, ec.city
             FROM manager_advance_allocations ma
             LEFT JOIN ess_employee_cache ec ON ma.manager_id = ec.employee_id
             WHERE ma.month = :m AND ma.year = :y ORDER BY ma.created_at DESC",
            ['m' => $advMonth, 'y' => $advYear]
        );
    } catch (Exception $e) {}
}

// ── Approvals data ───────────────────────────────────────────────
$pendingList = [];
if ($activeTab === 'approvals' && empty($fStatus)) {
    try {
        $pendingList = $db->fetchAll(
            "SELECT e.*, ec.full_name AS manager_name, ec.designation
             FROM ess_expenses e
             LEFT JOIN ess_employee_cache ec ON " . ($managerIdColExists ? "e.manager_id = ec.employee_id" : "e.employee_id = ec.employee_id") . "
             WHERE e.status='pending' ORDER BY e.created_at DESC"
        );
    } catch (Exception $e) {}
}

// ── All Expenses data with filters ───────────────────────────────
$fMonth   = sanitize($_GET['f_month'] ?? '');
$fYear    = sanitize($_GET['f_year'] ?? '');
$fManager = sanitize($_GET['f_manager'] ?? '');
$fStatus  = sanitize($_GET['f_status'] ?? '');

$allExpenses = [];
$expTotal = 0; $expCounts = ['pending'=>0,'approved'=>0,'rejected'=>0]; $expAmounts = ['pending'=>0,'approved'=>0,'rejected'=>0];
if ($activeTab === 'approvals' && !empty($fStatus)) {
    $where = ['1=1']; $params = [];
    if ($fStatus !== '' && in_array($fStatus, ['pending','approved','rejected'])) { $where[]='e.status=:s'; $params['s']=$fStatus; }
    if ($managerIdColExists && $fManager !== '') { $where[]='(e.employee_id=:m1 OR e.manager_id=:m2)'; $params['m1']=$fManager; $params['m2']=$fManager; }
    elseif ($fManager !== '') { $where[]='e.employee_id=:m1'; $params['m1']=$fManager; }
    if ($monthColExists && $fMonth !== '') { $m=(int)$fMonth; if ($m>=1&&$m<=12) { $where[]='e.month=:mo'; $params['mo']=$m; } }
    if ($monthColExists && $fYear !== '') { $y=(int)$fYear; if ($y>=2000&&$y<=2099) { $where[]='e.year=:yr'; $params['yr']=$y; } }
    $allExpenses = $db->fetchAll(
        "SELECT e.*, ec.full_name AS manager_name, ec.designation
         FROM ess_expenses e LEFT JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
         WHERE " . implode(' AND ', $where) . " ORDER BY e.created_at DESC LIMIT 200", $params
    );
    foreach ($allExpenses as $e) { $expTotal+=(float)$e['amount']; $s=$e['status']; if(isset($expCounts[$s])){ $expCounts[$s]++; $expAmounts[$s]+=(float)$e['amount']; } }
}

// ── Reports data ─────────────────────────────────────────────────
$rptType   = sanitize($_GET['rpt_type'] ?? 'monthly');
$rptMonth  = sanitize($_GET['rpt_month'] ?? $currentMonth);
$rptYear   = sanitize($_GET['rpt_year'] ?? $currentYear);
$rptManager = sanitize($_GET['rpt_manager'] ?? '');

// Monthly report data
$monthlyReport = [];
if ($activeTab === 'reports' && $rptType === 'monthly') {
    $allMgrs = $managersList;
    $monthStart = sprintf('%04d-%02d-01', (int)$rptYear, (int)$rptMonth);
    $monthEnd   = sprintf('%04d-%02d-31', (int)$rptYear, (int)$rptMonth);
    foreach ($allMgrs as $mgr) {
        $mid = $mgr['employee_id'];
        $allocated = $spent = $empAdv = $carryForward = 0;
        try { $allocated = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM manager_advance_allocations WHERE manager_id=:m AND month=:mo AND year=:yr", ['m'=>$mid,'mo'=>$rptMonth,'yr'=>$rptYear]); } catch (Exception $e) {}
        try { $carryForward = (float)$db->fetchColumn("SELECT COALESCE(SUM(carry_forward_amount),0) FROM manager_advance_allocations WHERE manager_id=:m AND month=:mo AND year=:yr", ['m'=>$mid,'mo'=>$rptMonth,'yr'=>$rptYear]); } catch (Exception $e) {}
        // Include ESS app entries: manager_id matches OR (employee_id matches AND manager_id=0)
        try { $spent = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='expense' AND status='approved' AND (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0'))) AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['m'=>$mid,'m2'=>$mid,'sd'=>$monthStart,'ed'=>$monthEnd]); } catch (Exception $e) {}
        try { $empAdv = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='employee_advance' AND status='approved' AND (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0'))) AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['m'=>$mid,'m2'=>$mid,'sd'=>$monthStart,'ed'=>$monthEnd]); } catch (Exception $e) {}
        $totalAlloc = $allocated + $carryForward;
        // Only show managers who have some activity (allocation, expense, or employee advance)
        if ($allocated > 0 || $carryForward > 0 || $spent > 0 || $empAdv > 0) {
            $monthlyReport[] = ['name'=>$mgr['full_name'], 'unit'=>$mgr['unit_name'], 'allocated'=>$allocated, 'carry_forward'=>$carryForward, 'total_allocated'=>$totalAlloc, 'expenses'=>$spent, 'emp_adv'=>$empAdv, 'balance'=>$totalAlloc-$spent-$empAdv];
        }
    }
}

// Manager-wise ledger report data
$ledgerTransactions = []; $ledgerSummary = ['total_advance'=>0,'total_expenses'=>0,'total_emp_advances'=>0,'net_balance'=>0,'total_pending'=>0,'total_rejected'=>0];
if ($rptType === 'manager' && $rptManager !== '') {
    // Support both new (month/year) and old (date) URL params for backward compat
    $rptFromMonth = (int)($_GET['rpt_from_month'] ?? 0);
    $rptFromYear  = (int)($_GET['rpt_from_year'] ?? 0);
    $rptToMonth   = (int)($_GET['rpt_to_month'] ?? 0);
    $rptToYear    = (int)($_GET['rpt_to_year'] ?? 0);
    if ($rptFromMonth >= 1 && $rptFromMonth <= 12 && $rptFromYear >= 2000) {
        $fromDate = sprintf('%04d-%02d-01', $rptFromYear, $rptFromMonth);
    } else {
        $fromDate = sanitize($_GET['rpt_from'] ?? '');
    }
    if ($rptToMonth >= 1 && $rptToMonth <= 12 && $rptToYear >= 2000) {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $rptToMonth, $rptToYear);
        $toDate = sprintf('%04d-%02d-%02d', $rptToYear, $rptToMonth, $daysInMonth);
    } else {
        $toDate = sanitize($_GET['rpt_to'] ?? '');
    }
    if ($fromDate && $toDate) {
        // Build month/year range for filtering allocations by their month/year columns
        $fromMonth = (int)date('m', strtotime($fromDate));
        $fromYear  = (int)date('Y', strtotime($fromDate));
        $toMonth   = (int)date('m', strtotime($toDate));
        $toYear    = (int)date('Y', strtotime($toDate));
        // Convert to comparable integers: YYYYMM
        $fromYM = $fromYear * 100 + $fromMonth;
        $toYM   = $toYear * 100 + $toMonth;

        $advances = $db->fetchAll(
            "SELECT 'allocation' AS txn_type, id, amount, remarks AS description, COALESCE(alloc_date, created_at) AS txn_date, alloc_date, month, year, NULL AS expense_date, NULL AS type, NULL AS category, 'approved' AS status
             FROM manager_advance_allocations WHERE manager_id=:m AND (year*100+month) BETWEEN :fym AND :tym ORDER BY COALESCE(alloc_date, created_at) ASC",
            ['m'=>$rptManager,'fym'=>$fromYM,'tym'=>$toYM]
        );
        // Include ALL ESS expenses (approved, pending, rejected — not just approved)
        $expenses = $db->fetchAll(
            "SELECT 'expense' AS txn_type, id, amount, description, created_at AS txn_date, expense_date, type, category, status
             FROM ess_expenses
             WHERE (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0')))
             AND ((expense_date IS NOT NULL AND DATE(expense_date) BETWEEN :f1 AND :t1) OR (expense_date IS NULL AND DATE(created_at) BETWEEN :f2 AND :t2))
             ORDER BY created_at ASC",
            ['m'=>$rptManager,'m2'=>$rptManager,'f1'=>$fromDate,'t1'=>$toDate,'f2'=>$fromDate,'t2'=>$toDate]
        );
        $ledgerTransactions = array_merge($advances, $expenses);
        usort($ledgerTransactions, function($a,$b) {
            return strcmp(!empty($a['expense_date'])?$a['expense_date']:$a['txn_date'], !empty($b['expense_date'])?$b['expense_date']:$b['txn_date']);
        });
        $rb = 0;
        foreach ($ledgerTransactions as &$txn) {
            if ($txn['txn_type']==='allocation') {
                $rb+=(float)$txn['amount']; $ledgerSummary['total_advance']+=(float)$txn['amount'];
            } else {
                $txnStatus = $txn['status'] ?? '';
                // Only approved expenses affect balance; pending/rejected are informational
                if ($txnStatus === 'approved') {
                    $rb-=(float)$txn['amount'];
                    if (($txn['category']??'')==='employee_advance') $ledgerSummary['total_emp_advances']+=(float)$txn['amount'];
                    else $ledgerSummary['total_expenses']+=(float)$txn['amount'];
                } elseif ($txnStatus === 'rejected') {
                    $ledgerSummary['total_rejected']+=1;
                } elseif ($txnStatus === 'pending') {
                    $ledgerSummary['total_pending']+=1;
                }
            }
            $txn['running_balance'] = $rb;
        }
        unset($txn);
        $ledgerSummary['net_balance'] = $ledgerSummary['total_advance'] - $ledgerSummary['total_expenses'] - $ledgerSummary['total_emp_advances'];
    }
}

// ── Report: Yearly Summary ────────────────────────────────────────
$yearlySummary = [];
if ($activeTab === 'reports') {
    $rptYearForAnnual = (int)($_GET['rpt_yearly'] ?? $currentYear);
    for ($m = 1; $m <= 12; $m++) {
        $allocAmt = 0; $expAmt = 0; $empAdvAmt = 0;
        try { $allocAmt = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM manager_advance_allocations WHERE month=:m AND year=:y", ['m'=>$m,'y'=>$rptYearForAnnual]); } catch (Exception $e) {}
        $mStart = sprintf('%04d-%02d-01', $rptYearForAnnual, $m);
        $mEnd = sprintf('%04d-%02d-31', $rptYearForAnnual, $m);
        try { $expAmt = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='expense' AND status='approved' AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$mStart,'ed'=>$mEnd]); } catch (Exception $e) {}
        try { $empAdvAmt = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='employee_advance' AND status='approved' AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$mStart,'ed'=>$mEnd]); } catch (Exception $e) {}
        $yearlySummary[$m] = ['allocated'=>$allocAmt, 'expenses'=>$expAmt, 'emp_adv'=>$empAdvAmt, 'net'=>$allocAmt-$expAmt-$empAdvAmt];
    }
}

// ── Report: Category-wise Expense Breakdown ──────────────────────
$categoryBreakdown = [];
if ($activeTab === 'reports') {
    $catFrom = sanitize($_GET['cat_from'] ?? date('Y-m-01'));
    $catTo   = sanitize($_GET['cat_to'] ?? date('Y-m-d'));
    try {
        $catRows = $db->fetchAll(
            "SELECT COALESCE(type,'other') AS exp_type, category, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
             FROM ess_expenses
             WHERE status='approved' AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed
             GROUP BY exp_type, category
             ORDER BY total DESC",
            ['sd'=>$catFrom,'ed'=>$catTo]
        );
        $categoryBreakdown = $catRows;
    } catch (Exception $e) {}
}

// ── Report: Pending Expense Aging ────────────────────────────────
$pendingAging = [];
if ($activeTab === 'reports') {
    try {
        $pendingRows = $db->fetchAll(
            "SELECT e.*, ec.full_name AS manager_name, ec.designation, DATEDIFF(CURDATE(), COALESCE(DATE(e.expense_date), DATE(e.created_at))) AS days_pending
             FROM ess_expenses e
             LEFT JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
             WHERE e.status='pending'
             ORDER BY days_pending DESC, e.created_at ASC
             LIMIT 100"
        );
        $pendingAging = $pendingRows;
    } catch (Exception $e) {}
}

// ── Report: Top Spenders (all-time) ──────────────────────────────
$topSpenders = [];
if ($activeTab === 'reports') {
    try {
        $topRows = $db->fetchAll(
            "SELECT ec.employee_id, ec.full_name, ec.designation, ec.unit_name,
                    COUNT(e.id) AS txn_count, COALESCE(SUM(e.amount),0) AS total_spent
             FROM ess_expenses e
             JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
             WHERE e.status='approved' AND e.category='expense'
             GROUP BY ec.employee_id
             ORDER BY total_spent DESC
             LIMIT 15"
        );
        $topSpenders = $topRows;
    } catch (Exception $e) {}
}

// ── Report: Approval Statistics ──────────────────────────────────
$approvalStats = ['approved'=>0,'pending'=>0,'rejected'=>0,'approved_amt'=>0,'pending_amt'=>0,'rejected_amt'=>0];
if ($activeTab === 'reports') {
    try {
        $statRows = $db->fetchAll(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
             FROM ess_expenses
             GROUP BY status"
        );
        foreach ($statRows as $sr) {
            $s = $sr['status'];
            if (isset($approvalStats[$s])) {
                $approvalStats[$s] = (int)$sr['cnt'];
                $approvalStats[$s.'_amt'] = (float)$sr['total'];
            }
        }
    } catch (Exception $e) {}
}

// ── Report: Employee Advance Register ────────────────────────────
$empAdvanceRegister = [];
if ($activeTab === 'reports') {
    $eaMonth = sanitize($_GET['ea_month'] ?? $currentMonth);
    $eaYear  = sanitize($_GET['ea_year'] ?? $currentYear);
    try {
        $eaStart = sprintf('%04d-%02d-01', (int)$eaYear, (int)$eaMonth);
        $eaEnd   = sprintf('%04d-%02d-31', (int)$eaYear, (int)$eaMonth);
        $eaRows = $db->fetchAll(
            "SELECT e.id, e.amount, e.description, e.status, e.created_at,
                    COALESCE(e.expense_date, DATE(e.created_at)) AS txn_date,
                    ec.full_name, ec.designation, ec.unit_name
             FROM ess_expenses e
             LEFT JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
             WHERE e.category='employee_advance'
             AND COALESCE(e.expense_date, DATE(e.created_at)) BETWEEN :sd AND :ed
             ORDER BY e.created_at DESC",
            ['sd'=>$eaStart, 'ed'=>$eaEnd]
        );
        $empAdvanceRegister = $eaRows;
    } catch (Exception $e) {}
}

// ── Report: Month-over-Month Trend ───────────────────────────────
$momTrend = [];
if ($activeTab === 'reports') {
    try {
        for ($i = 5; $i >= 0; $i--) {
            $tm = (int)date('m', strtotime("-$i months"));
            $ty = (int)date('Y', strtotime("-$i months"));
            $tStart = sprintf('%04d-%02d-01', $ty, $tm);
            $tEnd   = sprintf('%04d-%02d-31', $ty, $tm);
            $tAlloc = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM manager_advance_allocations WHERE month=:m AND year=:y", ['m'=>$tm,'y'=>$ty]);
            $tExp   = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='expense' AND status='approved' AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$tStart,'ed'=>$tEnd]);
            $tEmpA  = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE category='employee_advance' AND status='approved' AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$tStart,'ed'=>$tEnd]);
            $momTrend[] = ['month'=>$tm, 'year'=>$ty, 'label'=>$monthShort[$tm].' '.$ty, 'allocated'=>$tAlloc, 'expenses'=>$tExp, 'emp_adv'=>$tEmpA, 'net'=>$tAlloc-$tExp-$tEmpA];
        }
    } catch (Exception $e) {}
}

// ── Report: Bill Compliance ──────────────────────────────────────
$billCompliance = ['with_bill'=>0,'with_bill_amt'=>0,'without_bill'=>0,'without_bill_amt'=>0,'total'=>0,'total_amt'=>0];
if ($activeTab === 'reports') {
    $billMonth = sanitize($_GET['bill_month'] ?? $currentMonth);
    $billYear  = sanitize($_GET['bill_year'] ?? $currentYear);
    try {
        $bStart = sprintf('%04d-%02d-01', (int)$billYear, (int)$billMonth);
        $bEnd   = sprintf('%04d-%02d-31', (int)$billYear, (int)$billMonth);
        $billCompliance['with_bill'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM ess_expenses WHERE status='approved' AND category='expense' AND (bill_url IS NOT NULL AND bill_url != '') AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$bStart,'ed'=>$bEnd]);
        $billCompliance['with_bill_amt'] = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE status='approved' AND category='expense' AND (bill_url IS NOT NULL AND bill_url != '') AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$bStart,'ed'=>$bEnd]);
        $billCompliance['without_bill'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM ess_expenses WHERE status='approved' AND category='expense' AND (bill_url IS NULL OR bill_url = '') AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$bStart,'ed'=>$bEnd]);
        $billCompliance['without_bill_amt'] = (float)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM ess_expenses WHERE status='approved' AND category='expense' AND (bill_url IS NULL OR bill_url = '') AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed", ['sd'=>$bStart,'ed'=>$bEnd]);
        $billCompliance['total'] = $billCompliance['with_bill'] + $billCompliance['without_bill'];
        $billCompliance['total_amt'] = $billCompliance['with_bill_amt'] + $billCompliance['without_bill_amt'];
    } catch (Exception $e) {}
}

// ============================================================================
// HELPERS
// ============================================================================

function catBadge($cat) {
    $m = ['advance'=>'primary','expense'=>'info','employee_advance'=>'warning'];
    return '<span class="badge bg-'.($m[$cat]??'secondary').'">'.str_replace('_',' ',ucfirst($cat?:'expense')).'</span>';
}
function statusBadge($s) {
    $m = ['pending'=>'warning text-dark','approved'=>'success','rejected'=>'danger'];
    return '<span class="badge bg-'.($m[$s]??'secondary').'">'.ucfirst($s).'</span>';
}

// Filter managers for dropdowns (people who have expenses OR allocations, excluding Workers & HK Lady)
$filterManagers = [];
try {
    $filterManagers = $db->fetchAll(
        "SELECT DISTINCT ec.employee_id, ec.full_name
         FROM ess_employee_cache ec
         WHERE LOWER(ec.designation) NOT LIKE '%worker%' AND LOWER(ec.designation) NOT LIKE '%hk lady%'
         AND (ec.employee_id IN (SELECT DISTINCT employee_id FROM ess_expenses)
              OR ec.employee_id IN (SELECT DISTINCT manager_id FROM manager_advance_allocations)
              OR ec.employee_id IN (SELECT DISTINCT COALESCE(NULLIF(NULLIF(manager_id,''),'0'), employee_id) FROM ess_expenses))
         ORDER BY ec.full_name ASC"
    );
} catch (Exception $e) {
    try {
        $filterManagers = $db->fetchAll(
            "SELECT DISTINCT ec.employee_id, ec.full_name FROM ess_expenses e
             JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
             WHERE LOWER(ec.designation) NOT LIKE '%worker%' AND LOWER(ec.designation) NOT LIKE '%hk lady%'
             ORDER BY ec.full_name ASC"
        );
    } catch (Exception $e2) { $filterManagers = []; }
}
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODERN EXPENSE MANAGEMENT - SINGLE PAGE
     ═══════════════════════════════════════════════════════════════════════════ -->
<style>
.exp-page { --exp-primary: #4f46e5; --exp-success: #059669; --exp-warning: #d97706; --exp-danger: #dc2626; --exp-info: #0284c7; --exp-bg: #f8fafc; --exp-card: #ffffff; --exp-border: #e2e8f0; --exp-text: #1e293b; --exp-muted: #64748b; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
.exp-page * { box-sizing: border-box; }

/* ── Top Navigation Tabs ──────────────────────────────────────── */
.exp-tabs { display: flex; gap: 4px; background: #ffffff; border-bottom: 2px solid var(--exp-border); padding: 0 16px; overflow-x: auto; }
.exp-tab { padding: 12px 20px; font-size: 0.82rem; font-weight: 500; color: var(--exp-muted); border: none; background: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: all 0.2s; display: flex; align-items: center; gap: 6px; border-radius: 6px 6px 0 0; }
.exp-tab:hover { color: var(--exp-primary); background: #eef2ff; }
.exp-tab.active { color: var(--exp-primary); border-bottom-color: var(--exp-primary); font-weight: 600; }
.exp-tab .tab-badge { background: var(--exp-danger); color: #fff; font-size: 0.65rem; padding: 1px 6px; border-radius: 10px; font-weight: 700; }

/* ── Cards ─────────────────────────────────────────────────────── */
.exp-card { background: var(--exp-card); border: 1px solid var(--exp-border); border-radius: 12px; overflow: hidden; }
.exp-card-header { padding: 16px 20px; border-bottom: 1px solid var(--exp-border); display: flex; justify-content: space-between; align-items: center; }
.exp-card-header h5 { margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--exp-text); }
.exp-card-body { padding: 20px; }

/* ── Stat Cards ────────────────────────────────────────────────── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--exp-card); border: 1px solid var(--exp-border); border-radius: 12px; padding: 20px; position: relative; overflow: hidden; transition: box-shadow 0.2s, transform 0.2s; }
.stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); transform: translateY(-1px); }
.stat-card .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 12px; }
.stat-card .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--exp-text); line-height: 1.2; }
.stat-card .stat-label { font-size: 0.75rem; color: var(--exp-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; font-weight: 500; }

/* ── Tables ─────────────────────────────────────────────────────── */
.exp-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.exp-table thead th { background: #f8fafc; padding: 10px 14px; text-align: left; font-weight: 600; color: var(--exp-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--exp-border); white-space: nowrap; }
.exp-table thead th.text-end { text-align: right; }
.exp-table thead th.text-center { text-align: center; }
.exp-table tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: var(--exp-text); vertical-align: middle; }
.exp-table tbody tr:hover { background: #f8fafc; }
.exp-table tbody tr:last-child td { border-bottom: none; }
.exp-table tfoot td { background: #f1f5f9; font-weight: 700; padding: 10px 14px; border-top: 2px solid var(--exp-border); font-size: 0.8rem; }

/* ── Forms ──────────────────────────────────────────────────────── */
.exp-form .form-label { font-size: 0.78rem; font-weight: 600; color: var(--exp-text); margin-bottom: 4px; }
.exp-form .form-control, .exp-form .form-select { font-size: 0.85rem; border-color: var(--exp-border); border-radius: 8px; padding: 8px 12px; }
.exp-form .form-control:focus, .exp-form .form-select:focus { border-color: var(--exp-primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
.exp-btn { padding: 8px 20px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
.exp-btn-primary { background: var(--exp-primary); color: #fff; }
.exp-btn-primary:hover { background: #4338ca; }
.exp-btn-success { background: var(--exp-success); color: #fff; }
.exp-btn-success:hover { background: #047857; }
.exp-btn-danger { background: var(--exp-danger); color: #fff; }
.exp-btn-danger:hover { background: #b91c1c; }
.exp-btn-outline { background: transparent; color: var(--exp-muted); border: 1px solid var(--exp-border); }
.exp-btn-outline:hover { background: #f1f5f9; color: var(--exp-text); }
.exp-btn-sm { padding: 4px 10px; font-size: 0.75rem; }

/* ── Filter Bar ─────────────────────────────────────────────────── */
.filter-bar { background: #f8fafc; border: 1px solid var(--exp-border); border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
.filter-bar .row { align-items: flex-end; }

/* ── Badge helpers ─────────────────────────────────────────────── */
.badge-sm { font-size: 0.68rem; padding: 2px 8px; border-radius: 6px; }

/* ── Empty state ───────────────────────────────────────────────── */
.empty-state { text-align: center; padding: 48px 20px; color: var(--exp-muted); }
.empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.3; display: block; }

/* ── Responsive ─────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .exp-tab { padding: 10px 14px; font-size: 0.75rem; }
}

/* ── Print ──────────────────────────────────────────────────────── */
@media print {
    .exp-tabs, .no-print, .filter-bar { display: none !important; }
    body * { visibility: hidden; }
    #expensePage, #expensePage * { visibility: visible; }
    #expensePage { position: absolute; left: 0; top: 0; width: 100%; }
}
</style>

<div class="exp-page" id="expensePage">

    <!-- ═══ PAGE HEADER ═══ -->
    <div style="padding: 20px 0 0;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 style="margin:0; font-weight:700; color:var(--exp-text); font-size:1.3rem;">
                    <i class="bi bi-wallet2 me-2" style="color:var(--exp-primary);"></i>Expense Management
                </h4>
                <nav aria-label="breadcrumb" style="margin:4px 0 0;">
                    <ol class="breadcrumb" style="margin:0; font-size:0.75rem;">
                        <li class="breadcrumb-item"><a href="index.php" style="text-decoration:none; color:var(--exp-muted);">Home</a></li>
                        <li class="breadcrumb-item active" style="color:var(--exp-primary);">Expense</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <!-- ═══ FLASH MESSAGES ═══ -->
    <?php if (isset($_SESSION['flash'])): ?>
    <div style="padding: 12px 0 0;">
        <?php foreach ($_SESSION['flash'] as $flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show py-2 px-3" role="alert" style="font-size:0.82rem; border-radius:8px;">
            <?= $flash['message'] ?>
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
        <?php endforeach; ?>
        <?php unset($_SESSION['flash']); ?>
    </div>
    <?php endif; ?>

    <!-- ═══ TAB NAVIGATION ═══ -->
    <div class="exp-tabs" style="margin-top: 16px;">
        <a href="<?= $baseUrl ?>&tab=dashboard" class="exp-tab <?= $activeTab==='dashboard'?'active':'' ?>">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>
        <a href="<?= $baseUrl ?>&tab=advance" class="exp-tab <?= $activeTab==='advance'?'active':'' ?>">
            <i class="bi bi-cash-plus"></i> Add Advance
        </a>
        <a href="<?= $baseUrl ?>&tab=expense" class="exp-tab <?= $activeTab==='expense'?'active':'' ?>">
            <i class="bi bi-receipt"></i> Add Expense
        </a>
        <a href="<?= $baseUrl ?>&tab=approvals" class="exp-tab <?= $activeTab==='approvals'?'active':'' ?>">
            <i class="bi bi-check2-square"></i> Approvals
            <?php if ($pendingApprovals > 0): ?><span class="tab-badge"><?= $pendingApprovals ?></span><?php endif; ?>
        </a>
        <a href="<?= $baseUrl ?>&tab=reports" class="exp-tab <?= $activeTab==='reports'?'active':'' ?>">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>
        <a href="<?= $baseUrl ?>&tab=upload" class="exp-tab <?= $activeTab==='upload'?'active':'' ?>">
            <i class="bi bi-file-earmark-spreadsheet"></i> Upload
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB: DASHBOARD
         ═══════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'dashboard'): ?>
    <div style="padding: 24px 0;">

        <!-- Summary Stats -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dcfce7; color:#059669;"><i class="bi bi-cash-stack"></i></div>
                <div class="stat-value" style="color:#059669;">&#8377;<?= number_format($totalAdvanceIssued,2) ?></div>
                <div class="stat-label">Total Advance Issued</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef3c7; color:#d97706;"><i class="bi bi-receipt"></i></div>
                <div class="stat-value" style="color:#d97706;">&#8377;<?= number_format($totalExpenses,2) ?></div>
                <div class="stat-label">Total Expenses</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fee2e2; color:#dc2626;"><i class="bi bi-clock-history"></i></div>
                <div class="stat-value" style="color:#dc2626;"><?= $pendingApprovals ?></div>
                <div class="stat-label">Pending Approvals</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#e0e7ff; color:#4f46e5;"><i class="bi bi-people"></i></div>
                <div class="stat-value" style="color:#4f46e5;">&#8377;<?= number_format($totalEmpAdvances,2) ?></div>
                <div class="stat-label">Employee Advances</div>
            </div>
        </div>

        <!-- Manager Balance Table -->
        <div class="exp-card">
            <div class="exp-card-header">
                <h5><i class="bi bi-people me-2"></i>Manager Balance Overview</h5>
                <span class="badge bg-light text-dark border badge-sm"><?= count($managerSummary) ?> managers</span>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (empty($managerSummary)): ?>
                <div class="empty-state"><i class="bi bi-people"></i><p>No managers found.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th class="text-end">Allocated</th>
                                <th class="text-end">Expenses</th>
                                <th class="text-end">Emp. Adv.</th>
                                <th class="text-end">Balance</th>
                                <th class="text-center">Pending</th>
                                <th class="text-center">Ledger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($managerSummary as $ms): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($ms['name']) ?></strong>
                                    <?php if ($ms['designation']): ?><br><small style="color:var(--exp-muted);"><?= htmlspecialchars($ms['designation']) ?></small><?php endif; ?>
                                    <?php if ($ms['unit']): ?><br><small style="color:var(--exp-muted);"><?= htmlspecialchars($ms['unit']) ?></small><?php endif; ?>
                                </td>
                                <td class="text-end" style="color:#059669; font-weight:600;">&#8377;<?= number_format($ms['advance'],2) ?></td>
                                <td class="text-end" style="color:#d97706; font-weight:600;">&#8377;<?= number_format($ms['expenses'],2) ?></td>
                                <td class="text-end" style="color:#0284c7; font-weight:600;">&#8377;<?= number_format($ms['emp_advances'],2) ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $ms['balance']>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($ms['balance'],2) ?></td>
                                <td class="text-center">
                                    <?php if ($ms['pending']>0): ?>
                                    <a href="<?= $baseUrl ?>&tab=approvals" class="badge bg-danger text-decoration-none badge-sm"><?= $ms['pending'] ?></a>
                                    <?php else: ?><span style="color:var(--exp-muted);">-</span><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="<?= $baseUrl ?>&tab=reports&rpt_type=manager&rpt_manager=<?= urlencode($ms['id']) ?>" class="exp-btn exp-btn-outline exp-btn-sm" title="View Ledger">
                                        <i class="bi bi-journal-text"></i>
                                    </a>
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

    <!-- ═══════════════════════════════════════════════════════════════
         TAB: ADD ADVANCE
         ═══════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'advance'): ?>
    <div style="padding: 24px 0;">
        <div class="row g-4">
            <!-- Allocation Form -->
            <div class="col-lg-4">
                <div class="exp-card" style="position:sticky; top:20px;">
                    <div class="exp-card-header" style="background:#f0fdf4;">
                        <h5 style="color:#059669;"><i class="bi bi-plus-circle me-2"></i>Allocate Advance</h5>
                    </div>
                    <div class="exp-card-body">
                        <form method="POST" action="" class="exp-form" id="allocateForm">
                            <input type="hidden" name="action" value="allocate_advance">

                            <div class="mb-3">
                                <label class="form-label">Manager <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm mb-1" id="advSearch" placeholder="Search name, designation..." autocomplete="off">
                                <select name="manager_id" id="advManagerId" class="form-select form-select-sm" required>
                                    <option value="">-- Select Manager --</option>
                                    <?php foreach ($managersList as $m): ?>
                                    <option value="<?= htmlspecialchars($m['employee_id']) ?>" data-label="<?= htmlspecialchars(($m['full_name']??'').' '.($m['designation']??'').' '.($m['unit_name']??'')) ?>">
                                        <?= htmlspecialchars($m['full_name']) ?><?php if ($m['designation']): ?> - <?= htmlspecialchars($m['designation']) ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label">Month <span class="text-danger">*</span></label>
                                    <select name="alloc_month" class="form-select form-select-sm" required>
                                        <?php for ($mi=1;$mi<=12;$mi++): ?>
                                        <option value="<?= $mi ?>" <?= $advMonth==$mi?'selected':'' ?>><?= $monthNames[$mi] ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <select name="alloc_year" class="form-select form-select-sm" required>
                                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                                        <option value="<?= $yi ?>" <?= $advYear==$yi?'selected':'' ?>><?= $yi ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Advance Given Date</label>
                                <input type="date" name="alloc_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Amount (&#8377;) <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                                    <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="Optional..."></textarea>
                            </div>

                            <button type="submit" class="exp-btn exp-btn-success w-100" style="padding:10px;">
                                <i class="bi bi-cash-coin"></i> Allocate Advance
                            </button>

                            <div style="margin-top:12px; background:#fffbeb; border-radius:8px; padding:10px 14px; font-size:0.72rem; color:#92400e;">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong>How carry-forward works:</strong><br>
                                When you allocate for a month, the system automatically checks the previous month's balance:<br>
                                <strong>Balance = Prev. Allocation - Prev. Expenses - Prev. Employee Advances</strong><br>
                                If balance &gt; 0, it gets added as carry-forward to this month's allocation.
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Side -->
            <div class="col-lg-8">
                <!-- Month Navigation -->
                <div class="filter-bar no-print">
                    <form method="GET" action="<?= $baseUrl ?>" class="row g-2 align-items-center">
                        <input type="hidden" name="page" value="expense/dashboard">
                        <input type="hidden" name="tab" value="advance">
                        <div class="col-auto">
                            <select name="adv_month" class="form-select form-select-sm" style="min-width:130px;">
                                <?php for ($mi=1;$mi<=12;$mi++): ?>
                                <option value="<?= $mi ?>" <?= $advMonth===$mi?'selected':'' ?>><?= $monthNames[$mi] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select name="adv_year" class="form-select form-select-sm" style="min-width:90px;">
                                <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                                <option value="<?= $yi ?>" <?= $advYear===$yi?'selected':'' ?>><?= $yi ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="exp-btn exp-btn-primary exp-btn-sm"><i class="bi bi-search"></i> View</button>
                        </div>
                        <div class="col-auto ms-auto">
                            <div class="btn-group btn-group-sm">
                                <?php
                                $pm=$advMonth-1; $py=$advYear; if($pm<1){$pm=12;$py--;}
                                $nm=$advMonth+1; $ny=$advYear; if($nm>12){$nm=1;$ny++;}
                                ?>
                                <a href="<?= $baseUrl ?>&tab=advance&adv_month=<?= $pm ?>&adv_year=<?= $py ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
                                <span class="btn btn-dark btn-sm disabled" style="min-width:120px;"><?= $monthShort[$advMonth] ?> <?= $advYear ?></span>
                                <a href="<?= $baseUrl ?>&tab=advance&adv_month=<?= $nm ?>&adv_year=<?= $ny ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Allocations Table -->
                <div class="exp-card">
                    <div class="exp-card-header">
                        <h5><i class="bi bi-list-ul me-2"></i>Allocations — <?= $monthNames[$advMonth] ?> <?= $advYear ?></h5>
                        <span class="badge bg-light text-dark border badge-sm"><?= count($allocations) ?> records</span>
                    </div>
                    <div class="exp-card-body" style="padding:0;">
                        <?php if (empty($allocations)): ?>
                        <div class="empty-state"><i class="bi bi-inbox"></i><p>No allocations for this month.</p></div>
                        <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="exp-table">
                                <thead><tr><th>Date Given</th><th>Month</th><th>Manager</th><th class="text-end">Amount</th><th class="text-end">Carry-Forward</th><th class="text-end">Total</th><th>Remarks</th><th class="text-center">Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($allocations as $row): 
                                    $cf = (float)($row['carry_forward_amount'] ?? 0); 
                                    $totalAlloc = (float)$row['amount'] + $cf; 
                                    $showDate = !empty($row['alloc_date']) ? $row['alloc_date'] : $row['created_at']; ?>
                                    <tr>
                                        <td style="white-space:nowrap;"><?= date('d M Y', strtotime($showDate)) ?></td>
                                        <td><span class="badge bg-light text-dark border badge-sm"><?= $monthShort[(int)($row['month']??0)] ?> <?= $row['year']??'' ?></span></td>
                                        <td><strong><?= htmlspecialchars($row['full_name']??'N/A') ?></strong><br><small style="color:var(--exp-muted);"><?= htmlspecialchars($row['unit_name']??'') ?></small></td>
                                        <td class="text-end" style="font-weight:700; color:var(--exp-primary);">&#8377;<?= number_format((float)$row['amount'],2) ?></td>
                                        <td class="text-end"><?php if ($cf > 0): ?><span style="color:#059669; font-weight:600;">+&#8377;<?= number_format($cf,2) ?></span><?php else: ?><span style="color:var(--exp-muted);">-</span><?php endif; ?></td>
                                        <td class="text-end" style="font-weight:700;">&#8377;<?= number_format($totalAlloc,2) ?></td>
                                        <td><?= htmlspecialchars($row['remarks']??'-') ?><?php if ($cf > 0): ?><br><small style="color:#059669;">incl. &#8377;<?= number_format($cf,2) ?> from <?= $monthNames[(int)($row['carry_forward_from_month']??0)] ?? '' ?> <?= $row['carry_forward_from_year']??'' ?></small><?php endif; ?></td>
                                        <td class="text-center">
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete?');">
                                                <input type="hidden" name="action" value="delete_allocation">
                                                <input type="hidden" name="alloc_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="exp-btn exp-btn-danger exp-btn-sm"><i class="bi bi-trash"></i></button>
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
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB: ADD EXPENSE
         ═══════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'expense'): ?>
    <div style="padding: 24px 0;">
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="exp-card" style="position:sticky; top:20px;">
                    <div class="exp-card-header" style="background:#eff6ff;">
                        <h5 style="color:var(--exp-info);"><i class="bi bi-plus-circle me-2"></i>Add New Expense</h5>
                    </div>
                    <div class="exp-card-body">
                        <form method="POST" action="" class="exp-form" id="addExpenseForm">
                            <input type="hidden" name="action" value="add_expense">

                            <div class="mb-3">
                                <label class="form-label">Manager <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm mb-1" id="expSearch" placeholder="Search..." autocomplete="off">
                                <select name="manager_id" id="expManagerId" class="form-select form-select-sm" required>
                                    <option value="">-- Select --</option>
                                    <?php foreach ($managersList as $m): ?>
                                    <option value="<?= htmlspecialchars($m['employee_id']) ?>" data-label="<?= htmlspecialchars(($m['full_name']??'').' '.($m['designation']??'')) ?>">
                                        <?= htmlspecialchars($m['full_name']) ?> - <?= htmlspecialchars($m['designation']??'') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select form-select-sm">
                                        <option value="expense">Expense</option>
                                        <option value="employee_advance">Employee Advance</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select form-select-sm">
                                        <option value="travel">Travel</option>
                                        <option value="food">Food</option>
                                        <option value="cab">Cab</option>
                                        <option value="supplies">Supplies</option>
                                        <option value="medical">Medical</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="form-label">Amount (&#8377;) <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                                        <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <label class="form-label">Date <span class="text-danger">*</span></label>
                                    <input type="date" name="expense_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Brief description..."></textarea>
                            </div>

                            <div class="mb-3" id="empFields">
                                <label class="form-label">Employee Name <small class="text-muted">(for Employee Advance)</small></label>
                                <input type="text" name="emp_name" class="form-control form-control-sm" placeholder="Employee name (if applicable)">
                            </div>

                            <button type="submit" class="exp-btn exp-btn-primary w-100" style="padding:10px;">
                                <i class="bi bi-plus-circle"></i> Add Expense
                            </button>

                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Expenses -->
            <div class="col-lg-7">
                <div class="exp-card">
                    <div class="exp-card-header">
                        <h5><i class="bi bi-clock-history me-2"></i>Recent Expenses</h5>
                        <a href="<?= $baseUrl ?>&tab=approvals" class="exp-btn exp-btn-outline exp-btn-sm">View All</a>
                    </div>
                    <div class="exp-card-body" style="padding:0;">
                        <?php
                        $recentExpenses = $db->fetchAll(
                            "SELECT e.*, ec.full_name AS manager_name FROM ess_expenses e
                             LEFT JOIN ess_employee_cache ec ON e.employee_id = ec.employee_id
                             ORDER BY e.created_at DESC LIMIT 20"
                        );
                        if (empty($recentExpenses)): ?>
                        <div class="empty-state"><i class="bi bi-inbox"></i><p>No expenses yet.</p></div>
                        <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="exp-table">
                                <thead><tr><th>Date</th><th>Manager</th><th>Category</th><th class="text-end">Amount</th><th class="text-center">Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentExpenses as $r): ?>
                                    <tr>
                                        <td><?= date('d M', strtotime($r['expense_date']??$r['created_at'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['manager_name']??$r['emp_name']??'N/A') ?></strong></td>
                                        <td><?= catBadge($r['category']) ?></td>
                                        <td class="text-end" style="font-weight:600;">&#8377;<?= number_format((float)$r['amount'],2) ?></td>
                                        <td class="text-center"><?= statusBadge($r['status']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB: APPROVALS
         ═══════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'approvals'): ?>
    <div style="padding: 24px 0;">

        <!-- Sub-tabs: Pending | All -->
        <div class="d-flex gap-2 mb-3">
            <a href="<?= $baseUrl ?>&tab=approvals" class="exp-btn <?= empty($fStatus) || $fStatus==='pending' ? 'exp-btn-primary' : 'exp-btn-outline' ?> exp-btn-sm">
                <i class="bi bi-hourglass-split"></i> Pending <?= $pendingApprovals>0?'('.$pendingApprovals.')':'' ?>
            </a>
            <a href="<?= $baseUrl ?>&tab=approvals" class="exp-btn exp-btn-outline exp-btn-sm" onclick="this.href+='&f_status=approved'">
                <i class="bi bi-check-circle"></i> Approved
            </a>
            <a href="<?= $baseUrl ?>&tab=approvals" class="exp-btn exp-btn-outline exp-btn-sm" onclick="this.href+='&f_status=rejected'">
                <i class="bi bi-x-circle"></i> Rejected
            </a>
        </div>

        <!-- Pending List -->
        <?php if (empty($fStatus) || $fStatus === 'pending'): ?>
        <div class="exp-card">
            <div class="exp-card-header" style="background:#fef9c3;">
                <h5 style="color:#a16207;"><i class="bi bi-hourglass-split me-2"></i>Pending Approvals</h5>
                <span class="badge bg-warning text-dark badge-sm"><?= count($pendingList) ?></span>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (empty($pendingList)): ?>
                <div class="empty-state"><i class="bi bi-check-circle"></i><p>All caught up! No pending approvals.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table" id="pendingTable">
                        <thead><tr><th>Date</th><th>Manager</th><th>Category</th><th>Type</th><th class="text-end">Amount</th><th>Description</th><th class="text-center">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingList as $p): $billUrl = $p['bill_url'] ?? ''; $billType = $p['bill_type'] ?? ''; ?>
                            <tr id="prow-<?= (int)$p['id'] ?>">
                                <td style="white-space:nowrap;"><?= date('d M Y', strtotime($p['expense_date']??$p['created_at'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($p['manager_name']??$p['emp_name']??'N/A') ?></strong>
                                    <?php if ($p['designation']): ?><br><small style="color:var(--exp-muted);"><?= htmlspecialchars($p['designation']) ?></small><?php endif; ?>
                                </td>
                                <td><?= catBadge($p['category']) ?></td>
                                <td><span class="badge bg-light text-dark border badge-sm"><?= ucfirst($p['type']??'other') ?></span></td>
                                <td class="text-end" style="font-weight:700;">&#8377;<?= number_format((float)$p['amount'],2) ?></td>
                                <td><?= htmlspecialchars(mb_substr($p['description']??'',0,50)) ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <?php if ($billUrl): ?><button type="button" class="exp-btn exp-btn-outline exp-btn-sm" title="View Bill" data-bs-toggle="modal" data-bs-target="#viewModal-<?= (int)$p['id'] ?>"><i class="bi bi-eye"></i></button><?php endif; ?>
                                        <button type="button" class="exp-btn exp-btn-outline exp-btn-sm" title="View Details" data-bs-toggle="modal" data-bs-target="#detailModal-<?= (int)$p['id'] ?>"><i class="bi bi-info-lg"></i></button>
                                        <form method="POST" action="<?= $baseUrl ?>&tab=approvals" style="display:inline;" onsubmit="return confirm('Approve?');">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="exp-btn exp-btn-success exp-btn-sm" title="Approve"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                        <button type="button" class="exp-btn exp-btn-danger exp-btn-sm" title="Reject" onclick="showRejectBox(<?= (int)$p['id'] ?>)">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                        <button type="button" class="exp-btn exp-btn-outline exp-btn-sm" title="Edit"
                                                data-bs-toggle="modal" data-bs-target="#editModal-<?= (int)$p['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reject Box -->
        <div id="rejectBox" class="exp-card mt-3 d-none" style="border-color:#dc2626;">
            <div class="exp-card-header" style="background:#fee2e2;">
                <h5 style="color:#dc2626; font-size:0.85rem;"><i class="bi bi-x-circle me-1"></i>Reject Expense #<span id="rejectId"></span></h5>
            </div>
            <div class="exp-card-body" style="padding:12px 16px;">
                <form method="POST" action="<?= $baseUrl ?>&tab=approvals" id="rejectForm">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" id="rejectIdInput" value="">
                    <textarea name="rejection_reason" class="form-control form-control-sm mb-2" rows="2" required placeholder="Reason for rejection..."></textarea>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="exp-btn exp-btn-outline exp-btn-sm" onclick="hideRejectBox()">Cancel</button>
                        <button type="submit" class="exp-btn exp-btn-danger exp-btn-sm"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filtered All Expenses -->
        <?php if (!empty($fStatus)): ?>
        <div class="filter-bar no-print">
            <form method="GET" action="<?= $baseUrl ?>" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="expense/dashboard">
                <input type="hidden" name="tab" value="approvals">
                <input type="hidden" name="f_status" value="<?= htmlspecialchars($fStatus) ?>">
                <div class="col-md-3"><label class="form-label small fw-semibold">Manager</label>
                    <select name="f_manager" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($filterManagers as $m): ?>
                        <option value="<?= $m['employee_id'] ?>" <?= $fManager==$m['employee_id']?'selected':'' ?>><?= htmlspecialchars($m['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-semibold">Month</label>
                    <select name="f_month" class="form-select form-select-sm">
                        <option value="">--</option>
                        <?php for ($mi=1;$mi<=12;$mi++): ?><option value="<?= $mi ?>" <?= $fMonth==(string)$mi?'selected':'' ?>><?= $monthShort[$mi] ?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-semibold">Year</label>
                    <select name="f_year" class="form-select form-select-sm">
                        <option value="">--</option>
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?><option value="<?= $yi ?>" <?= $fYear==(string)$yi?'selected':'' ?>><?= $yi ?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto"><button type="submit" class="exp-btn exp-btn-primary exp-btn-sm"><i class="bi bi-funnel"></i> Filter</button></div>
                <div class="col-auto"><a href="<?= $baseUrl ?>&tab=approvals" class="exp-btn exp-btn-outline exp-btn-sm"><i class="bi bi-x-circle"></i></a></div>
            </form>
        </div>

        <div class="exp-card">
            <div class="exp-card-header">
                <h5><i class="bi bi-list-ul me-2"></i><?= ucfirst($fStatus) ?> Expenses</h5>
                <span class="badge bg-light text-dark border badge-sm"><?= count($allExpenses) ?> entries</span>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (empty($allExpenses)): ?>
                <div class="empty-state"><i class="bi bi-inbox"></i><p>No expenses found.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>Date</th><th>Manager</th><th>Category</th><th class="text-end">Amount</th><th>Description</th><th class="text-center">Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($allExpenses as $row): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($row['expense_date']??$row['created_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($row['manager_name']??'N/A') ?></strong></td>
                                <td><?= catBadge($row['category']) ?></td>
                                <td class="text-end" style="font-weight:600;">&#8377;<?= number_format((float)$row['amount'],2) ?></td>
                                <td><?= htmlspecialchars(mb_substr($row['description']??'',0,50)) ?></td>
                                <td class="text-center"><?= statusBadge($row['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end">Total (<?= count($allExpenses) ?>):</td>
                                <td class="text-end" style="color:var(--exp-primary); font-weight:700;">&#8377;<?= number_format($expTotal,2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB: REPORTS (SUB-TABS - 10 REPORTS)
         ═══════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'reports'): ?>
    <div style="padding: 24px 0;">
        <!-- Sub-tab Navigation -->
        <div class="no-print" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px; padding-bottom:16px; border-bottom:2px solid var(--exp-border);">
            <?php
            $rptTabs = [
                'monthly'  => ['icon'=>'bi-calendar-range','label'=>'Monthly','color'=>'#059669'],
                'yearly'   => ['icon'=>'bi-graph-up','label'=>'Yearly','color'=>'#4f46e5'],
                'ledger'   => ['icon'=>'bi-journal-text','label'=>'Ledger','color'=>'#d97706'],
                'category' => ['icon'=>'bi-pie-chart','label'=>'Category','color'=>'#7c3aed'],
                'pending'  => ['icon'=>'bi-clock-history','label'=>'Pending','color'=>'#dc2626'],
                'top'      => ['icon'=>'bi-trophy','label'=>'Top Spenders','color'=>'#0284c7'],
                'stats'    => ['icon'=>'bi-clipboard-data','label'=>'Statistics','color'=>'#059669'],
                'trend'    => ['icon'=>'bi-arrow-left-right','label'=>'Trend','color'=>'#7c3aed'],
                'emp_adv'  => ['icon'=>'bi-person-check','label'=>'Emp. Advance','color'=>'#ea580c'],
                'bill'     => ['icon'=>'bi-receipt-cutoff','label'=>'Bill Compliance','color'=>'#0891b2'],
            ];
            foreach ($rptTabs as $rk => $rt):
                $isActive = ($rptSub === $rk);
            ?>
            <a href="<?= $baseUrl ?>&tab=reports&rpt_sub=<?= $rk ?>" class="text-decoration-none" style="flex-shrink:0;">
                <div style="display:inline-flex; align-items:center; gap:5px; padding:7px 14px; border-radius:8px; font-size:0.78rem; font-weight:600; transition:all .15s; cursor:pointer;
                    background:<?= $isActive?$rt['color'].'12':'transparent' ?>;
                    border:1.5px solid <?= $isActive?$rt['color']:'var(--exp-border)' ?>;
                    color:<?= $isActive?$rt['color']:'var(--exp-muted)' ?>;">
                    <i class="bi <?= $rt['icon'] ?>"></i><?= $rt['label'] ?>
                </div>
            </a>
            <?php endforeach; ?>
            <button onclick="window.print()" class="exp-btn exp-btn-outline exp-btn-sm" style="margin-left:auto; align-self:center;"><i class="bi bi-printer"></i> Print</button>
        </div>

        <?php if ($rptSub === 'monthly'): ?>
        <!-- CARD 1: MONTHLY SUMMARY -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#f0fdf4;">
                <h5 style="color:#059669;"><i class="bi bi-calendar-range me-2"></i>Monthly Summary</h5>
                <form method="GET" class="d-flex gap-2 align-items-center no-print" style="font-size:0.78rem;">
                    <input type="hidden" name="page" value="expense/dashboard">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rpt_sub" value="<?= $rptSub ?>">
                    <input type="hidden" name="rpt_type" value="monthly">
                    <select name="rpt_month" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($mi=1;$mi<=12;$mi++): ?>
                        <option value="<?= $mi ?>" <?= $rptMonth==(string)$mi?'selected':'' ?>><?= $monthShort[$mi] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="rpt_year" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                        <option value="<?= $yi ?>" <?= $rptYear==(string)$yi?'selected':'' ?>><?= $yi ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="exp-btn exp-btn-sm" style="background:#059669;color:#fff;padding:4px 12px;"><i class="bi bi-arrow-clockwise"></i></button>
                </form>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (!empty($monthlyReport)):
                    $rptTotalAlloc = $rptTotalCF = $rptTotalExp = $rptTotalEmpAdv = $rptTotalBal = 0;
                    foreach ($monthlyReport as $r) { $rptTotalAlloc+=$r['allocated']; $rptTotalCF+=$r['carry_forward']; $rptTotalExp+=$r['expenses']; $rptTotalEmpAdv+=$r['emp_adv']; $rptTotalBal+=$r['balance']; }
                ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>Manager</th><th>Unit</th><th class="text-end">Allocated</th><th class="text-end">Carry-Fwd</th><th class="text-end">Total Avail.</th><th class="text-end">Expenses</th><th class="text-end">Emp. Adv.</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>
                            <?php foreach ($monthlyReport as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                                <td style="color:var(--exp-muted);"><?= htmlspecialchars($r['unit']??'-') ?></td>
                                <td class="text-end" style="color:#059669;">&#8377;<?= number_format($r['allocated'],2) ?></td>
                                <td class="text-end"><?php if ($r['carry_forward'] > 0): ?><span style="color:#4f46e5; font-weight:600;">+&#8377;<?= number_format($r['carry_forward'],2) ?></span><?php else: ?><span style="color:var(--exp-muted);">-</span><?php endif; ?></td>
                                <td class="text-end" style="font-weight:700; color:#059669;">&#8377;<?= number_format($r['total_allocated'],2) ?></td>
                                <td class="text-end" style="color:#d97706;">&#8377;<?= number_format($r['expenses'],2) ?></td>
                                <td class="text-end" style="color:#db2777;">&#8377;<?= number_format($r['emp_adv'],2) ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $r['balance']>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($r['balance'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-end" style="font-weight:700;">TOTAL</td>
                                <td class="text-end" style="color:#059669;">&#8377;<?= number_format($rptTotalAlloc,2) ?></td>
                                <td class="text-end" style="color:#4f46e5;">&#8377;<?= number_format($rptTotalCF,2) ?></td>
                                <td class="text-end" style="font-weight:700; color:#059669;">&#8377;<?= number_format($rptTotalAlloc+$rptTotalCF,2) ?></td>
                                <td class="text-end" style="color:#d97706;">&#8377;<?= number_format($rptTotalExp,2) ?></td>
                                <td class="text-end" style="color:#db2777;">&#8377;<?= number_format($rptTotalEmpAdv,2) ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $rptTotalBal>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($rptTotalBal,2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-funnel"></i><p>Select month/year and refresh.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'yearly'): ?>
        <!-- CARD 2: YEARLY OVERVIEW -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#eef2ff;">
                <h5 style="color:#4f46e5;"><i class="bi bi-graph-up me-2"></i>Yearly Overview</h5>
                <form method="GET" class="d-flex gap-2 align-items-center no-print" style="font-size:0.78rem;">
                    <input type="hidden" name="page" value="expense/dashboard">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rpt_sub" value="<?= $rptSub ?>">
                    <select name="rpt_yearly" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                        <option value="<?= $yi ?>" <?= $rptYearForAnnual==$yi?'selected':'' ?>><?= $yi ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="exp-btn exp-btn-sm" style="background:#4f46e5;color:#fff;padding:4px 12px;"><i class="bi bi-arrow-clockwise"></i></button>
                </form>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>Month</th><th class="text-end">Advance Allocated</th><th class="text-end">Expenses</th><th class="text-end">Emp. Advances</th><th class="text-end">Net</th></tr></thead>
                        <tbody>
                            <?php
                            $yTA=0; $yTE=0; $yEA=0; $yTN=0;
                            for ($m=1;$m<=12;$m++):
                                $yd = $yearlySummary[$m] ?? ['allocated'=>0,'expenses'=>0,'emp_adv'=>0,'net'=>0];
                                $yTA+=$yd['allocated']; $yTE+=$yd['expenses']; $yEA+=$yd['emp_adv']; $yTN+=$yd['net'];
                                $isActive = ($yd['allocated']>0 || $yd['expenses']>0 || $yd['emp_adv']>0);
                            ?>
                            <tr style="<?= $isActive?'':'opacity:0.4;' ?>">
                                <td><strong><?= $monthShort[$m] ?></strong></td>
                                <td class="text-end" style="color:#059669;"><?= $yd['allocated']>0?'&#8377;'.number_format($yd['allocated'],2):'-' ?></td>
                                <td class="text-end" style="color:#d97706;"><?= $yd['expenses']>0?'&#8377;'.number_format($yd['expenses'],2):'-' ?></td>
                                <td class="text-end" style="color:#db2777;"><?= $yd['emp_adv']>0?'&#8377;'.number_format($yd['emp_adv'],2):'-' ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $yd['net']>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($yd['net'],2) ?></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="font-weight:700;">Total <?= $rptYearForAnnual ?></td>
                                <td class="text-end" style="color:#059669;">&#8377;<?= number_format($yTA,2) ?></td>
                                <td class="text-end" style="color:#d97706;">&#8377;<?= number_format($yTE,2) ?></td>
                                <td class="text-end" style="color:#db2777;">&#8377;<?= number_format($yEA,2) ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $yTN>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($yTN,2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($rptSub === 'ledger'): ?>
        <!-- CARD 3: MANAGER LEDGER -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#fef3c7;">
                <h5 style="color:#d97706;"><i class="bi bi-journal-text me-2"></i>Manager Ledger</h5>
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap no-print" style="font-size:0.78rem;">
                    <input type="hidden" name="page" value="expense/dashboard">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rpt_sub" value="<?= $rptSub ?>">
                    <input type="hidden" name="rpt_type" value="manager">
                    <select name="rpt_manager" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem; min-width:150px;">
                        <option value="">-- Select --</option>
                        <?php foreach ($managersList as $m): ?>
                        <option value="<?= $m['employee_id'] ?>" <?= $rptManager==$m['employee_id']?'selected':'' ?>><?= htmlspecialchars($m['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="rpt_from_month" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($mi=1;$mi<=12;$mi++): ?>
                        <option value="<?= $mi ?>" <?= ((int)($_GET['rpt_from_month']??$currentMonth))==$mi?'selected':'' ?>><?= $monthShort[$mi] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="rpt_from_year" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                        <option value="<?= $yi ?>" <?= ((int)($_GET['rpt_from_year']??($currentYear-1)))==$yi?'selected':'' ?>><?= $yi ?></option>
                        <?php endfor; ?>
                    </select>
                    <span style="color:var(--exp-muted);">to</span>
                    <select name="rpt_to_month" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($mi=1;$mi<=12;$mi++): ?>
                        <option value="<?= $mi ?>" <?= ((int)($_GET['rpt_to_month']??$currentMonth))==$mi?'selected':'' ?>><?= $monthShort[$mi] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="rpt_to_year" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                        <option value="<?= $yi ?>" <?= ((int)($_GET['rpt_to_year']??$currentYear))==$yi?'selected':'' ?>><?= $yi ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="exp-btn exp-btn-sm" style="background:#d97706;color:#fff;padding:4px 12px;"><i class="bi bi-arrow-clockwise"></i></button>
                </form>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if ($rptType === 'manager' && !empty($ledgerTransactions)): ?>
                <div style="display:flex; gap:16px; padding:14px 20px; background:#fffbeb; border-bottom:1px solid var(--exp-border); flex-wrap:wrap; font-size:0.78rem;">
                    <div><strong>Advance:</strong> <span style="color:#059669; font-weight:700;">&#8377;<?= number_format($ledgerSummary['total_advance'],2) ?></span></div>
                    <div><strong>Expenses:</strong> <span style="color:#dc2626; font-weight:700;">&#8377;<?= number_format($ledgerSummary['total_expenses'],2) ?></span></div>
                    <div><strong>Emp.Adv:</strong> <span style="color:#4f46e5; font-weight:700;">&#8377;<?= number_format($ledgerSummary['total_emp_advances'],2) ?></span></div>
                    <div><strong>Pending:</strong> <span style="color:#d97706; font-weight:700;"><?= $ledgerSummary['total_pending'] ?></span></div>
                    <div><strong>Rejected:</strong> <span style="color:#dc2626; font-weight:700;"><?= $ledgerSummary['total_rejected'] ?></span></div>
                    <div style="margin-left:auto;"><strong>Net:</strong> <span style="font-weight:700; color:<?= $ledgerSummary['net_balance']>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($ledgerSummary['net_balance'],2) ?></span></div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>#</th><th>Date</th><th>Category</th><th>Type</th><th>Description</th><th class="text-end">Credit</th><th class="text-end">Debit</th><th class="text-end">Balance</th><th class="text-center">Status</th></tr></thead>
                        <tbody>
                            <?php $sl=0; foreach ($ledgerTransactions as $txn): $sl++; $isCredit=$txn['txn_type']==='allocation'; $rb=(float)$txn['running_balance']; $txnStatus=$txn['status']??''; $txnCat=$txn['category']??''; $txnType=!empty($txn['type'])?$txn['type']:'other'; ?>
                            <tr style="<?= ($txnStatus==='rejected')?'opacity:0.45;':'' ?>">
                                <td class="text-center" style="color:var(--exp-muted);"><?= $sl ?></td>
                                <td style="white-space:nowrap;"><?= date('d M Y', strtotime(!empty($txn['expense_date'])?$txn['expense_date']:$txn['txn_date'])) ?></td>
                                <td><?= $isCredit?'<span class="badge bg-success badge-sm">Allocation</span>':catBadge($txnCat) ?></td>
                                <td><?= $isCredit?'<span style="color:var(--exp-muted);">-</span>':'<span class="badge bg-light text-dark border badge-sm">'.ucfirst($txnType).'</span>' ?></td>
                                <td><?= htmlspecialchars(mb_substr($txn['description']??'',0,40)) ?></td>
                                <td class="text-end"><?= $isCredit?'<span style="color:#059669;">+&#8377;'.number_format((float)$txn['amount'],2).'</span>':'-' ?></td>
                                <td class="text-end"><?= (!$isCredit && $txnStatus==='approved')?'<span style="color:#dc2626;">-&#8377;'.number_format((float)$txn['amount'],2).'</span>':((!$isCredit)?'<span style="color:var(--exp-muted);">&#8377;'.number_format((float)$txn['amount'],2).'</span>':'-') ?></td>
                                <td class="text-end" style="font-weight:600; color:<?= $rb>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($rb,2) ?></td>
                                <td class="text-center"><?= $isCredit?'<span class="badge bg-success badge-sm">Done</span>':statusBadge($txnStatus) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="text-end">Net Balance:</td>
                                <td class="text-end" style="color:#059669;">&#8377;<?= number_format($ledgerSummary['total_advance'],2) ?></td>
                                <td class="text-end" style="color:#dc2626;">&#8377;<?= number_format($ledgerSummary['total_expenses']+$ledgerSummary['total_emp_advances'],2) ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $ledgerSummary['net_balance']>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($ledgerSummary['net_balance'],2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php elseif ($rptType === 'manager'): ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-journal-text"></i><p>Select a manager and date range to view the ledger.</p></div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-journal-text"></i><p>Select a manager above to view their detailed ledger.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'category'): ?>
        <!-- CARD 4: EXPENSE TYPE BREAKDOWN -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#fefce8;">
                <h5 style="color:#854d0e;"><i class="bi bi-pie-chart me-2"></i>Expense Type Breakdown</h5>
                <form method="GET" class="d-flex gap-2 align-items-center no-print" style="font-size:0.78rem;">
                    <input type="hidden" name="page" value="expense/dashboard">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rpt_sub" value="<?= $rptSub ?>">
                    <input type="date" name="cat_from" value="<?= htmlspecialchars($catFrom) ?>" class="form-control form-control-sm py-0" style="width:auto; font-size:0.78rem;">
                    <span style="color:var(--exp-muted);">to</span>
                    <input type="date" name="cat_to" value="<?= htmlspecialchars($catTo) ?>" class="form-control form-control-sm py-0" style="width:auto; font-size:0.78rem;">
                    <button type="submit" class="exp-btn exp-btn-sm" style="background:#854d0e;color:#fff;padding:4px 12px;"><i class="bi bi-arrow-clockwise"></i></button>
                </form>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (!empty($categoryBreakdown)):
                    $catGT = array_sum(array_column($categoryBreakdown, 'total'));
                ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>Expense Type</th><th>Category</th><th class="text-center">Count</th><th class="text-end">Total Amount</th><th class="text-end">% of Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($categoryBreakdown as $cr): ?>
                            <tr>
                                <td><strong><?= ucfirst(htmlspecialchars($cr['exp_type'])) ?></strong></td>
                                <td><?= catBadge($cr['category']) ?></td>
                                <td class="text-center"><?= (int)$cr['cnt'] ?></td>
                                <td class="text-end" style="font-weight:600;">&#8377;<?= number_format((float)$cr['total'],2) ?></td>
                                <td class="text-end"><?= $catGT>0? round((float)$cr['total']/$catGT*100,1):0 ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end" style="font-weight:700;">Grand Total</td>
                                <td class="text-end" style="font-weight:700;">&#8377;<?= number_format($catGT,2) ?></td>
                                <td class="text-end" style="font-weight:700;">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-pie-chart"></i><p>No approved expenses in this date range.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'pending'): ?>
        <!-- CARD 5: PENDING EXPENSES (AGING) -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#fef2f2;">
                <h5 style="color:#dc2626;"><i class="bi bi-clock-history me-2"></i>Pending Expenses <span class="badge bg-danger badge-sm"><?= count($pendingAging) ?></span></h5>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (!empty($pendingAging)): ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>Employee</th><th>Description</th><th>Category</th><th class="text-end">Amount</th><th>Date</th><th class="text-center">Days Pending</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingAging as $pa): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pa['manager_name']??$pa['emp_name']??'-') ?></strong><br><small style="color:var(--exp-muted);"><?= htmlspecialchars($pa['designation']??'') ?></small></td>
                                <td><?= htmlspecialchars(mb_substr($pa['description']??'',0,50)) ?></td>
                                <td><?= catBadge($pa['category']??'') ?></td>
                                <td class="text-end" style="font-weight:600;">&#8377;<?= number_format((float)$pa['amount'],2) ?></td>
                                <td style="white-space:nowrap;"><?= date('d M Y', strtotime(!empty($pa['expense_date'])?$pa['expense_date']:$pa['created_at'])) ?></td>
                                <td class="text-center">
                                    <?php $dp = (int)$pa['days_pending']; ?>
                                    <?php if ($dp >= 7): ?><span class="badge bg-danger badge-sm" style="font-weight:700;"><?= $dp ?>d</span>
                                    <?php elseif ($dp >= 3): ?><span class="badge bg-warning text-dark badge-sm" style="font-weight:700;"><?= $dp ?>d</span>
                                    <?php else: ?><span class="badge bg-success badge-sm"><?= $dp ?>d</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px; background:#f0fdf4;"><i class="bi bi-check-circle" style="color:#059669;"></i><p style="color:#059669; font-weight:600;">All caught up! No pending expenses.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'top'): ?>
        <!-- CARD 6: TOP SPENDERS -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#f0f9ff;">
                <h5 style="color:#0284c7;"><i class="bi bi-trophy me-2"></i>Top Spenders (All Time)</h5>
                <span class="badge bg-light text-dark border badge-sm">Top 15</span>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (!empty($topSpenders)): ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>#</th><th>Employee</th><th>Designation</th><th>Unit</th><th class="text-center">Transactions</th><th class="text-end">Total Spent</th></tr></thead>
                        <tbody>
                            <?php $rank=0; $topGT = array_sum(array_column($topSpenders, 'total_spent')); foreach ($topSpenders as $ts): $rank++; ?>
                            <tr>
                                <td class="text-center" style="font-weight:700; color:<?= $rank<=3?'#d97706':'var(--exp-muted)' ?>;"><?= $rank<=3?'<i class="bi bi-trophy-fill"></i>':'' ?><?= $rank ?></td>
                                <td><strong><?= htmlspecialchars($ts['full_name']) ?></strong></td>
                                <td style="color:var(--exp-muted);"><?= htmlspecialchars($ts['designation']??'-') ?></td>
                                <td style="color:var(--exp-muted);"><?= htmlspecialchars($ts['unit_name']??'-') ?></td>
                                <td class="text-center"><?= (int)$ts['txn_count'] ?></td>
                                <td class="text-end" style="font-weight:700; color:#dc2626;">&#8377;<?= number_format((float)$ts['total_spent'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end" style="font-weight:700;">Grand Total (all employees)</td>
                                <td class="text-end" style="font-weight:700;">&#8377;<?= number_format($topGT,2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-trophy"></i><p>No expense data yet.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'stats'): ?>
        <!-- CARD 7: APPROVAL STATISTICS (ALL TIME) -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#f0fdf4;">
                <h5 style="color:#059669;"><i class="bi bi-clipboard-data me-2"></i>Approval Statistics <span class="badge bg-light text-dark border badge-sm">All Time</span></h5>
            </div>
            <div class="exp-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:2rem; font-weight:800; color:#059669;"><?= number_format($approvalStats['approved']) ?></div>
                            <div style="font-size:0.78rem; color:#166534; font-weight:600;">Approved</div>
                            <div style="font-size:0.85rem; font-weight:700; color:#059669;">&#8377;<?= number_format($approvalStats['approved_amt'],2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:#fefce8; border:1px solid #fde68a; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:2rem; font-weight:800; color:#d97706;"><?= number_format($approvalStats['pending']) ?></div>
                            <div style="font-size:0.78rem; color:#92400e; font-weight:600;">Pending</div>
                            <div style="font-size:0.85rem; font-weight:700; color:#d97706;">&#8377;<?= number_format($approvalStats['pending_amt'],2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:2rem; font-weight:800; color:#dc2626;"><?= number_format($approvalStats['rejected']) ?></div>
                            <div style="font-size:0.78rem; color:#991b1b; font-weight:600;">Rejected</div>
                            <div style="font-size:0.85rem; font-weight:700; color:#dc2626;">&#8377;<?= number_format($approvalStats['rejected_amt'],2) ?></div>
                        </div>
                    </div>
                </div>
                <?php $totalAll = $approvalStats['approved']+$approvalStats['pending']+$approvalStats['rejected']; if ($totalAll > 0): ?>
                <div style="margin-top:16px;">
                    <div style="display:flex; border-radius:8px; overflow:hidden; height:28px;">
                        <?php $pctApp = round($approvalStats['approved']/$totalAll*100,1); $pctPen = round($approvalStats['pending']/$totalAll*100,1); $pctRej = round($approvalStats['rejected']/$totalAll*100,1); ?>
                        <div style="width:<?= $pctApp ?>%; background:#059669; display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.7rem; font-weight:700;" title="Approved: <?= $pctApp ?>%"><?= $pctApp>8?$pctApp.'%':'' ?></div>
                        <div style="width:<?= $pctPen ?>%; background:#d97706; display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.7rem; font-weight:700;" title="Pending: <?= $pctPen ?>%"><?= $pctPen>8?$pctPen.'%':'' ?></div>
                        <div style="width:<?= $pctRej ?>%; background:#dc2626; display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.7rem; font-weight:700;" title="Rejected: <?= $pctRej ?>%"><?= $pctRej>8?$pctRej.'%':'' ?></div>
                    </div>
                    <div style="display:flex; gap:16px; margin-top:6px; font-size:0.72rem; color:var(--exp-muted); justify-content:center;">
                        <span><i class="bi bi-square-fill" style="color:#059669;"></i> Approved</span>
                        <span><i class="bi bi-square-fill" style="color:#d97706;"></i> Pending</span>
                        <span><i class="bi bi-square-fill" style="color:#dc2626;"></i> Rejected</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'trend'): ?>
        <!-- CARD 8: MONTH-OVER-MONTH TREND (LAST 6 MONTHS) -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#f5f3ff;">
                <h5 style="color:#7c3aed;"><i class="bi bi-arrow-left-right me-2"></i>Month-over-Month Trend <span class="badge bg-light text-dark border badge-sm">Last 6 Months</span></h5>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>Month</th><th class="text-end">Allocated</th><th class="text-end">Expenses</th><th class="text-end">Emp. Adv.</th><th class="text-end">Net</th><th class="text-center">Trend</th></tr></thead>
                        <tbody>
                            <?php $prevNet = null; foreach ($momTrend as $mt): ?>
                            <tr>
                                <td><strong><?= $mt['label'] ?></strong></td>
                                <td class="text-end" style="color:#059669;"><?= $mt['allocated']>0?'&#8377;'.number_format($mt['allocated'],2):'-' ?></td>
                                <td class="text-end" style="color:#d97706;"><?= $mt['expenses']>0?'&#8377;'.number_format($mt['expenses'],2):'-' ?></td>
                                <td class="text-end" style="color:#db2777;"><?= $mt['emp_adv']>0?'&#8377;'.number_format($mt['emp_adv'],2):'-' ?></td>
                                <td class="text-end" style="font-weight:700; color:<?= $mt['net']>=0?'#059669':'#dc2626' ?>;">&#8377;<?= number_format($mt['net'],2) ?></td>
                                <td class="text-center">
                                    <?php if ($prevNet !== null): ?>
                                        <?php if ($mt['net'] > $prevNet): ?><span style="color:#059669; font-weight:700;"><i class="bi bi-arrow-up-short"></i>+&#8377;<?= number_format($mt['net']-$prevNet,2) ?></span>
                                        <?php elseif ($mt['net'] < $prevNet): ?><span style="color:#dc2626; font-weight:700;"><i class="bi bi-arrow-down-short"></i>&#8377;<?= number_format($mt['net']-$prevNet,2) ?></span>
                                        <?php else: ?><span style="color:var(--exp-muted);">-</span>
                                        <?php endif; ?>
                                    <?php else: ?><span style="color:var(--exp-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php $prevNet = $mt['net']; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($rptSub === 'emp_adv'): ?>
        <!-- CARD 9: EMPLOYEE ADVANCE REGISTER -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#fff7ed;">
                <h5 style="color:#ea580c;"><i class="bi bi-person-check me-2"></i>Employee Advance Register</h5>
                <form method="GET" class="d-flex gap-2 align-items-center no-print" style="font-size:0.78rem;">
                    <input type="hidden" name="page" value="expense/dashboard">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rpt_sub" value="<?= $rptSub ?>">
                    <select name="ea_month" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($mi=1;$mi<=12;$mi++): ?>
                        <option value="<?= $mi ?>" <?= $eaMonth==(string)$mi?'selected':'' ?>><?= $monthShort[$mi] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="ea_year" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                        <option value="<?= $yi ?>" <?= $eaYear==(string)$yi?'selected':'' ?>><?= $yi ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="exp-btn exp-btn-sm" style="background:#ea580c;color:#fff;padding:4px 12px;"><i class="bi bi-arrow-clockwise"></i></button>
                </form>
            </div>
            <div class="exp-card-body" style="padding:0;">
                <?php if (!empty($empAdvanceRegister)):
                    $eaGT = array_sum(array_column($empAdvanceRegister, 'amount'));
                ?>
                <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead><tr><th>#</th><th>Employee</th><th>Designation</th><th>Unit</th><th class="text-end">Amount</th><th>Description</th><th>Date</th><th class="text-center">Status</th></tr></thead>
                        <tbody>
                            <?php $eaIdx=0; foreach ($empAdvanceRegister as $ea): $eaIdx++; ?>
                            <tr>
                                <td class="text-center"><?= $eaIdx ?></td>
                                <td><strong><?= htmlspecialchars($ea['full_name']??'-') ?></strong></td>
                                <td style="color:var(--exp-muted); font-size:0.82rem;"><?= htmlspecialchars($ea['designation']??'-') ?></td>
                                <td style="color:var(--exp-muted); font-size:0.82rem;"><?= htmlspecialchars($ea['unit_name']??'-') ?></td>
                                <td class="text-end" style="font-weight:700; color:#ea580c;">&#8377;<?= number_format((float)$ea['amount'],2) ?></td>
                                <td style="font-size:0.82rem;"><?= htmlspecialchars(mb_substr($ea['description']??'',0,40)) ?></td>
                                <td style="white-space:nowrap; font-size:0.82rem;"><?= date('d M Y', strtotime($ea['txn_date'])) ?></td>
                                <td class="text-center"><?= statusBadge($ea['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end" style="font-weight:700;">Total (<?= $eaIdx ?> entries)</td>
                                <td class="text-end" style="font-weight:700; color:#ea580c;">&#8377;<?= number_format($eaGT,2) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-person-x"></i><p>No employee advances in this month.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($rptSub === 'bill'): ?>
        <!-- CARD 10: BILL COMPLIANCE -->
        <div class="exp-card" style="margin-bottom:20px;">
            <div class="exp-card-header" style="background:#ecfeff;">
                <h5 style="color:#0891b2;"><i class="bi bi-receipt-cutoff me-2"></i>Bill Compliance</h5>
                <form method="GET" class="d-flex gap-2 align-items-center no-print" style="font-size:0.78rem;">
                    <input type="hidden" name="page" value="expense/dashboard">
                    <input type="hidden" name="tab" value="reports">
                    <input type="hidden" name="rpt_sub" value="<?= $rptSub ?>">
                    <select name="bill_month" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($mi=1;$mi<=12;$mi++): ?>
                        <option value="<?= $mi ?>" <?= $billMonth==(string)$mi?'selected':'' ?>><?= $monthShort[$mi] ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="bill_year" class="form-select form-select-sm py-0" style="width:auto; font-size:0.78rem;">
                        <?php for ($yi=$currentYear+1;$yi>=$currentYear-3;$yi--): ?>
                        <option value="<?= $yi ?>" <?= $billYear==(string)$yi?'selected':'' ?>><?= $yi ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="exp-btn exp-btn-sm" style="background:#0891b2;color:#fff;padding:4px 12px;"><i class="bi bi-arrow-clockwise"></i></button>
                </form>
            </div>
            <div class="exp-card-body">
                <?php if ($billCompliance['total'] > 0):
                    $billPct = round($billCompliance['with_bill']/$billCompliance['total']*100,1);
                    $noBillPct = round($billCompliance['without_bill']/$billCompliance['total']*100,1);
                ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div style="background:#f0fdfa; border:1px solid #99f6e4; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:1.6rem; font-weight:800; color:#0891b2;"><?= $billCompliance['total'] ?></div>
                            <div style="font-size:0.78rem; color:#155e75; font-weight:600;">Total Expenses</div>
                            <div style="font-size:0.85rem; font-weight:700;">&#8377;<?= number_format($billCompliance['total_amt'],2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:1.6rem; font-weight:800; color:#059669;"><?= $billCompliance['with_bill'] ?></div>
                            <div style="font-size:0.78rem; color:#166534; font-weight:600;">With Bill</div>
                            <div style="font-size:0.85rem; font-weight:700;">&#8377;<?= number_format($billCompliance['with_bill_amt'],2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:1.6rem; font-weight:800; color:#dc2626;"><?= $billCompliance['without_bill'] ?></div>
                            <div style="font-size:0.78rem; color:#991b1b; font-weight:600;">Without Bill</div>
                            <div style="font-size:0.85rem; font-weight:700;">&#8377;<?= number_format($billCompliance['without_bill_amt'],2) ?></div>
                        </div>
                    </div>
                </div>
                <div>
                    <div style="font-size:0.78rem; font-weight:600; margin-bottom:6px;">Bill Upload Rate: <span style="color:<?= $billPct>=80?'#059669':($billPct>=50?'#d97706':'#dc2626') ?>; font-weight:800;"><?= $billPct ?>%</span></div>
                    <div style="display:flex; border-radius:8px; overflow:hidden; height:24px;">
                        <div style="width:<?= $billPct ?>%; background:#059669; display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.7rem; font-weight:700;" title="With Bill: <?= $billPct ?>%"><?= $billPct>10?$billPct.'%':'' ?></div>
                        <div style="width:<?= $noBillPct ?>%; background:#dc2626; display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.7rem; font-weight:700;" title="Without Bill: <?= $noBillPct ?>%"><?= $noBillPct>10?$noBillPct.'%':'' ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:30px;"><i class="bi bi-receipt"></i><p>No approved expenses in this month.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <?php elseif ($activeTab === 'upload'): ?>
    <div style="padding: 24px 0;">
        <div class="row g-4 justify-content-center">
            <div class="col-lg-8 col-xl-7">
                <div class="exp-card">
                    <div class="exp-card-header" style="background:#f5f3ff;">
                        <h5 style="color:#7c3aed;"><i class="bi bi-file-earmark-arrow-up me-2"></i>Bulk Upload — Advance &amp; Expense (CSV)</h5>
                    </div>
                    <div class="exp-card-body">
                        <form method="POST" action="<?= $baseUrl ?>&tab=upload" enctype="multipart/form-data" id="csvUploadForm">
                            <input type="hidden" name="action" value="bulk_xlsx_upload">
                            <div class="alert" style="background:#f5f3ff; border:1px solid #ddd6fe; color:#5b21b6; border-radius:10px; padding:16px; margin-bottom:20px;">
                                <h6 style="margin:0 0 10px; font-weight:700; font-size:0.88rem;"><i class="bi bi-info-circle me-1"></i>How to Upload</h6>
                                <ol style="margin:0; padding-left:20px; font-size:0.8rem; line-height:1.8;">
                                    <li><a href="<?= $baseUrl ?>&download_template=upload" class="text-decoration-none" style="color:#7c3aed; font-weight:700;" download><i class="bi bi-download me-1"></i>Download the CSV template</a></li>
                                    <li>Fill in Employee Code, Date, Advance amount, Expense amount, Remark</li>
                                    <li>One row per entry — you can put both advance and expense in the same row</li>
                                    <li>Leave Advance or Expense as <code>0</code> or empty if not applicable</li>
                                    <li>Upload the filled .csv file below</li>
                                </ol>
                            </div>

                            <div class="row g-3 align-items-end">
                                <div class="col-12">
                                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Select CSV File <span class="text-danger">*</span></label>
                                    <input type="file" name="csv_file" accept=".csv,.txt" class="form-control" id="csvUploadFile" required style="font-size:0.88rem;">
                                    <div class="form-text" style="font-size:0.75rem;">Only .csv files accepted. Download template above to get correct format.</div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="exp-btn w-100" style="padding:12px; background:#7c3aed; color:#fff; font-size:0.9rem;" onclick="return confirm('Upload this file and process all rows?')">
                                        <i class="bi bi-upload me-2"></i>Upload &amp; Process
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?php if (!empty($_SESSION['upload_debug'])): ?>
                        <div style="margin-top:20px; border:1px solid #cbd5e1; border-radius:10px; overflow:hidden;">
                            <div style="background:#fefce8; padding:10px 16px; font-weight:600; font-size:0.78rem; color:#854d0e; border-bottom:1px solid #fde68a; cursor:pointer;" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
                                <i class="bi bi-bug me-1"></i>Upload Debug Log (click to expand/collapse)
                            </div>
                            <div id="debugLog" style="background:#fafaf9; padding:12px 16px; font-family:'Courier New',monospace; font-size:0.72rem; line-height:1.7; color:#374151; max-height:300px; overflow-y:auto; display:block;">
                                <?php foreach ($_SESSION['upload_debug'] as $dbgLine): ?>
                                <div><?= htmlspecialchars($dbgLine) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php unset($_SESSION['upload_debug']); endif; ?>
                    </div>
                </div>
                <div style="margin-top:16px; text-align:center;">
                    <a href="<?= $baseUrl ?>&download_template=upload" class="btn btn-outline-secondary btn-sm" download style="border-radius:8px;">
                        <i class="bi bi-download me-1"></i> Download CSV Template
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /exp-page -->

<!-- ═══════════════════════════════════════════════════════════════
     DETAIL + BILL VIEW MODALS (for Approvals)
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'approvals' && empty($fStatus)): ?>
<?php foreach ($pendingList as $p):
    $pid = (int)$p['id'];
    $billUrl = $p['bill_url'] ?? '';
    $billType = strtolower($p['bill_type'] ?? '');
    $billLabel = ($billType === 'pdf') ? 'PDF' : (($billType === 'image') ? 'Image' : 'Bill');
    $billIsPdf = ($billType === 'pdf');
?>
<!-- Detail Modal -->
<div class="modal fade" id="detailModal-<?= $pid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header py-2" style="background:#f8fafc; border-bottom:2px solid var(--exp-primary);">
                <h6 class="modal-title mb-0" style="font-size:0.9rem; color:var(--exp-primary);"><i class="bi bi-receipt me-2"></i>Expense #<?= $pid ?> — Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px;">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Employee / Manager</div>
                        <div style="font-weight:600;"><?= htmlspecialchars($p['manager_name']??$p['emp_name']??'N/A') ?></div>
                        <?php if ($p['designation']): ?><div style="font-size:0.78rem; color:var(--exp-muted);"><?= htmlspecialchars($p['designation']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-3">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Amount</div>
                        <div style="font-weight:700; font-size:1.1rem; color:var(--exp-primary);">&#8377;<?= number_format((float)$p['amount'],2) ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Date</div>
                        <div style="font-weight:600;"><?= date('d M Y', strtotime($p['expense_date']??$p['created_at'])) ?></div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Category</div>
                        <div><?= catBadge($p['category']) ?></div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Type</div>
                        <div><span class="badge bg-light text-dark border"><?= ucfirst($p['type']??'other') ?></span></div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Status</div>
                        <div><?= statusBadge($p['status']) ?></div>
                    </div>
                    <?php if (!empty($p['emp_name'])): ?>
                    <div class="col-sm-6">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Employee (for Advance)</div>
                        <div style="font-weight:600;"><?= htmlspecialchars($p['emp_name']) ?><?php if ($p['emp_code']): ?> <small style="color:var(--exp-muted);">(<?= htmlspecialchars($p['emp_code']) ?>)</small><?php endif; ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($p['month']) && !empty($p['year'])): ?>
                    <div class="col-sm-3">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Month / Year</div>
                        <div><?= $monthShort[(int)$p['month']] ?> <?= $p['year'] ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Description</div>
                        <div style="background:#f8fafc; border-radius:8px; padding:10px 14px; font-size:0.85rem; min-height:40px;"><?= htmlspecialchars($p['description'] ?? 'No description') ?></div>
                    </div>
                    <?php if (!empty($billUrl)): ?>
                    <div class="col-12">
                        <div style="font-size:0.7rem; color:var(--exp-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">Uploaded Bill</div>
                        <div style="background:#f0fdf4; border:1px dashed #86efac; border-radius:10px; padding:12px; text-align:center;">
                            <?php if ($billIsPdf): ?>
                            <a href="<?= htmlspecialchars($billUrl) ?>" target="_blank" style="text-decoration:none; color:var(--exp-primary); font-weight:600;">
                                <i class="bi bi-file-earmark-pdf" style="font-size:1.5rem;"></i><br>
                                <small>Open PDF Bill</small>
                            </a>
                            <?php else: ?>
                            <img src="<?= htmlspecialchars($billUrl) ?>" alt="Bill" style="max-width:100%; max-height:280px; border-radius:8px; object-fit:contain; cursor:pointer;" onclick="window.open(this.src,'_blank')">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12" style="margin-top:4px;">
                        <div style="font-size:0.68rem; color:var(--exp-muted);">Submitted: <?= date('d M Y h:i A', strtotime($p['created_at'])) ?></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2" style="border-top:1px solid var(--exp-border);">
                <button type="button" class="exp-btn exp-btn-outline exp-btn-sm" data-bs-dismiss="modal">Close</button>
                <form method="POST" action="<?= $baseUrl ?>&tab=approvals" style="display:inline;" onsubmit="return confirm('Approve this expense?');">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <button type="submit" class="exp-btn exp-btn-success exp-btn-sm"><i class="bi bi-check-lg"></i> Approve</button>
                </form>
                <button type="button" class="exp-btn exp-btn-danger exp-btn-sm" onclick="bootstrap.Modal.getInstance(document.getElementById('detailModal-<?= $pid ?>')).hide(); showRejectBox(<?= $pid ?>);"><i class="bi bi-x-lg"></i> Reject</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     EDIT MODALS
     ═══════════════════════════════════════════════════════════════ -->
<?php
$modalRows = ($activeTab === 'approvals' && empty($fStatus)) ? $pendingList : (in_array($activeTab, ['approvals']) && !empty($fStatus) ? $allExpenses : []);
?>
<?php foreach ($modalRows as $row):
    $id = (int)$row['id'];
    $st = $row['status'];
?>
<div class="modal fade" id="editModal-<?= $id ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:12px;">
            <div class="modal-header py-2" style="border-bottom:1px solid var(--exp-border);">
                <h6 class="modal-title mb-0" style="font-size:0.85rem;"><i class="bi bi-pencil-square me-1" style="color:var(--exp-primary);"></i>Edit #<?= $id ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= $baseUrl ?>&tab=<?= $activeTab ?>" id="editForm-<?= $id ?>">
                <input type="hidden" name="action" value="edit_entry">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="modal-body" style="padding:16px;">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Amount</label>
                        <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0" value="<?= htmlspecialchars($row['amount']??0) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Description</label>
                        <input type="text" name="description" class="form-control form-control-sm" value="<?= htmlspecialchars($row['description']??'') ?>" required>
                    </div>
                    <?php if ($st !== 'approved'): ?>
                    <div class="d-flex gap-2 mt-3">
                        <form method="POST" action="<?= $baseUrl ?>&tab=<?= $activeTab ?>" style="display:inline; flex:1;">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="exp-btn exp-btn-success w-100 exp-btn-sm" onclick="return confirm('Approve?');"><i class="bi bi-check-lg"></i> Approve</button>
                        </form>
                        <button type="button" class="exp-btn exp-btn-danger w-100 exp-btn-sm" onclick="bootstrap.Modal.getInstance(document.getElementById('editModal-<?= $id ?>')).hide(); showRejectBox(<?= $id ?>);">
                            <i class="bi bi-x-lg"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer py-2" style="border-top:1px solid var(--exp-border);">
                    <button type="button" class="exp-btn exp-btn-outline exp-btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editForm-<?= $id ?>" class="exp-btn exp-btn-primary exp-btn-sm"><i class="bi bi-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ═══════════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════════ -->
<script>
// ── Reject box ───────────────────────────────────────────────
function showRejectBox(id) {
    document.getElementById('rejectBox').classList.remove('d-none');
    document.getElementById('rejectId').textContent = id;
    document.getElementById('rejectIdInput').value = id;
    document.getElementById('rejectBox').scrollIntoView({behavior:'smooth',block:'center'});
}
function hideRejectBox() {
    document.getElementById('rejectBox').classList.add('d-none');
    document.getElementById('rejectIdInput').value = '';
    document.querySelector('#rejectForm textarea').value = '';
}

// ── Dropdown search filter ───────────────────────────────────
function initSearch(searchId, selectId) {
    var searchInput = document.getElementById(searchId);
    var select = document.getElementById(selectId);
    if (!searchInput || !select) return;

    var allOptions = [];
    for (var i = 0; i < select.options.length; i++) {
        allOptions.push({
            value: select.options[i].value,
            text: select.options[i].textContent,
            label: (select.options[i].getAttribute('data-label') || '').toLowerCase()
        });
    }

    searchInput.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        var cur = select.value;
        select.innerHTML = '';
        for (var i = 0; i < allOptions.length; i++) {
            if (i === 0 || !q || allOptions[i].label.indexOf(q) !== -1) {
                var opt = document.createElement('option');
                opt.value = allOptions[i].value;
                opt.textContent = allOptions[i].text;
                select.appendChild(opt);
            }
        }
        if (cur) select.value = cur;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initSearch('advSearch', 'advManagerId');
    initSearch('expSearch', 'expManagerId');

    // Allocate form validation
    var af = document.getElementById('allocateForm');
    if (af) af.addEventListener('submit', function(e) {
        if (!document.getElementById('advManagerId').value) { e.preventDefault(); alert('Select a manager.'); return; }
        var a = parseFloat(document.querySelector('#allocateForm input[name=amount]').value);
        if (isNaN(a) || a <= 0) { e.preventDefault(); alert('Enter valid amount.'); return; }
        if (!confirm('Allocate \u20B9' + a.toLocaleString('en-IN',{minimumFractionDigits:2}) + ' to this manager?')) e.preventDefault();
    });

    // Expense form validation
    var ef = document.getElementById('addExpenseForm');
    if (ef) ef.addEventListener('submit', function(e) {
        if (!document.getElementById('expManagerId').value) { e.preventDefault(); alert('Select a manager.'); return; }
        var a = parseFloat(document.querySelector('#addExpenseForm input[name=amount]').value);
        if (isNaN(a) || a <= 0) { e.preventDefault(); alert('Enter valid amount.'); return; }
    });
});
</script>
