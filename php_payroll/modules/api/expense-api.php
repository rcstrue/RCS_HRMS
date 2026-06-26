<?php
/**
 * Expense API - Manager Mobile App
 * JSON endpoints for managers to submit expenses and view balance
 *
 * Endpoint: index.php?page=api/expense-api
 *
 * Actions:
 *   - dashboard      : Financial summary for a manager
 *   - add_expense    : Submit a new expense with optional bill upload
 *   - my_expenses    : List manager's expenses with filters
 *   - my_balance     : Detailed balance breakdown with monthly breakdown
 *   - expense_detail : Single expense details by id
 */

header('Content-Type: application/json');

// ============================================================================
// SECTION 1: Auto-create missing columns on ess_expenses
// ============================================================================

$alterColumns = [
    'category'      => "ADD COLUMN category ENUM('advance','expense','employee_advance') NOT NULL DEFAULT 'expense' AFTER employee_id",
    'manager_id'    => "ADD COLUMN manager_id VARCHAR(50) DEFAULT NULL AFTER category",
    'emp_name'      => "ADD COLUMN emp_name VARCHAR(255) DEFAULT NULL AFTER manager_id",
    'emp_code'      => "ADD COLUMN emp_code VARCHAR(50) DEFAULT NULL AFTER emp_name",
    'unit_id'       => "ADD COLUMN unit_id INT DEFAULT NULL AFTER emp_code",
    'month'         => "ADD COLUMN month INT DEFAULT NULL AFTER unit_id",
    'year'          => "ADD COLUMN year INT DEFAULT NULL AFTER month",
    'bill_type'     => "ADD COLUMN bill_type ENUM('image','pdf') DEFAULT NULL AFTER bill_url",
    'rejected_by'   => "ADD COLUMN rejected_by VARCHAR(50) DEFAULT NULL AFTER rejection_reason",
    'edited_by'     => "ADD COLUMN edited_by VARCHAR(50) DEFAULT NULL AFTER rejected_by",
    'edited_at'     => "ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER edited_by",
    'settlement_id' => "ADD COLUMN settlement_id INT DEFAULT NULL AFTER edited_at",
];

foreach ($alterColumns as $colName => $alterSql) {
    try {
        $checkCol = $db->fetch("SHOW COLUMNS FROM ess_expenses LIKE :col", ['col' => $colName]);
        if (!$checkCol) {
            $db->query("ALTER TABLE ess_expenses {$alterSql}");
        }
    } catch (Exception $e) {
        // Column alteration failed for this column, silently continue
    }
}

// ============================================================================
// SECTION 2: Helper - validate employee
// ============================================================================

/**
 * Validate that the given employee_id exists in ess_employee_cache.
 * Returns the employee row on success, or null.
 */
function validateEmployee($employeeId)
{
    global $db;
    if (empty($employeeId)) {
        return null;
    }
    return $db->fetch(
        "SELECT * FROM ess_employee_cache WHERE employee_id = ?",
        [$employeeId]
    );
}

// ============================================================================
// SECTION 3: Route by action
// ============================================================================

$action = sanitize($_GET['action'] ?? '');

try {

    // ------------------------------------------------------------------
    // ACTION: dashboard
    // ------------------------------------------------------------------
    if ($action === 'dashboard') {

        $employeeId = sanitize($_GET['employee_id'] ?? '');
        if (empty($employeeId)) {
            echo json_encode(['success' => false, 'error' => 'employee_id is required']);
            exit;
        }

        $employee = validateEmployee($employeeId);
        if (!$employee) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit;
        }

        // Totals from advance allocations (approved advances given to this manager)
        $totalAdvanceGiven = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations WHERE manager_id = ?",
            [$employeeId]
        );

        // Total approved expenses (manager's own expenses)
        $totalExpenses = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
             WHERE manager_id = ? AND category = 'expense' AND status = 'approved'",
            [$employeeId]
        );

        // Total approved employee advances (advances this manager gave to employees)
        $totalEmpAdvances = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
             WHERE manager_id = ? AND category = 'employee_advance' AND status = 'approved'",
            [$employeeId]
        );

        // Current balance = advance given - own expenses - employee advances
        $currentBalance = $totalAdvanceGiven - $totalExpenses - $totalEmpAdvances;

        // Pending count & amount
        $pendingCount = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM ess_expenses WHERE manager_id = ? AND status = 'pending'",
            [$employeeId]
        );

        $pendingAmount = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses WHERE manager_id = ? AND status = 'pending'",
            [$employeeId]
        );

        echo json_encode([
            'success' => true,
            'manager' => [
                'name'   => $employee['full_name'] ?? '',
                'mobile' => $employee['mobile_number'] ?? '',
                'role'   => $employee['role'] ?? '',
            ],
            'current_balance'        => round($currentBalance, 2),
            'total_advance_given'    => round($totalAdvanceGiven, 2),
            'total_expenses'         => round($totalExpenses, 2),
            'total_employee_advances'=> round($totalEmpAdvances, 2),
            'pending_count'          => $pendingCount,
            'pending_amount'         => round($pendingAmount, 2),
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // ACTION: add_expense
    // ------------------------------------------------------------------
    if ($action === 'add_expense') {

        $employeeId = sanitize($_POST['employee_id'] ?? '');
        if (empty($employeeId)) {
            echo json_encode(['success' => false, 'error' => 'employee_id is required']);
            exit;
        }

        $employee = validateEmployee($employeeId);
        if (!$employee) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit;
        }

        // Collect & validate required fields
        $category   = sanitize($_POST['category'] ?? 'expense');
        $type       = sanitize($_POST['type'] ?? '');
        $amount     = floatval($_POST['amount'] ?? 0);
        $description= sanitize($_POST['description'] ?? '');
        $expenseDate= sanitize($_POST['expense_date'] ?? '');
        $empName    = sanitize($_POST['emp_name'] ?? '');
        $empCode    = sanitize($_POST['emp_code'] ?? '');

        // Validate category
        if (!in_array($category, ['advance', 'expense', 'employee_advance'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid category. Must be: advance, expense, or employee_advance']);
            exit;
        }

        // Validate type
        $allowedTypes = ['travel', 'food', 'cab', 'supplies', 'medical', 'other'];
        if (!in_array($type, $allowedTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid type. Must be: travel, food, cab, supplies, medical, or other']);
            exit;
        }

        // Validate amount
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
            exit;
        }

        // Validate expense_date
        if (empty($expenseDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            echo json_encode(['success' => false, 'error' => 'expense_date is required and must be in YYYY-MM-DD format']);
            exit;
        }

        $parsedDate = date_create_from_format('Y-m-d', $expenseDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $expenseDate) {
            echo json_encode(['success' => false, 'error' => 'Invalid expense_date']);
            exit;
        }

        // For employee_advance category, emp_name is required
        if ($category === 'employee_advance' && empty($empName)) {
            echo json_encode(['success' => false, 'error' => 'emp_name is required for employee_advance category']);
            exit;
        }

        // Extract month and year from expense_date
        $month = (int)$parsedDate->format('m');
        $year  = (int)$parsedDate->format('Y');

        // Handle bill/receipt file upload
        $billUrl  = null;
        $billType = null;

        if (isset($_FILES['bill']) && $_FILES['bill']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['bill']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'File upload error: ' . $_FILES['bill']['error']]);
                exit;
            }

            // Max file size: 5MB
            $maxSize = 5 * 1024 * 1024;
            if ($_FILES['bill']['size'] > $maxSize) {
                echo json_encode(['success' => false, 'error' => 'File size exceeds 5MB limit']);
                exit;
            }

            // Validate file type
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'application/pdf' => 'pdf',
            ];

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['bill']['tmp_name']);

            if (!isset($allowedMimes[$mime])) {
                echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: jpg, png, gif, pdf']);
                exit;
            }

            // Create upload directory
            $uploadDir = APP_ROOT . '/uploads/expenses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $ext      = $allowedMimes[$mime];
            $filename = $employeeId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . $filename;

            if (!move_uploaded_file($_FILES['bill']['tmp_name'], $destPath)) {
                echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
                exit;
            }

            $billUrl  = 'expenses/' . $filename;
            $billType = ($mime === 'application/pdf') ? 'pdf' : 'image';
        }

        // Build insert data
        $insertData = [
            'employee_id'  => $employeeId,
            'category'     => $category,
            'manager_id'   => $employeeId,
            'emp_name'     => $category === 'employee_advance' ? $empName : null,
            'emp_code'     => $category === 'employee_advance' ? $empCode : null,
            'month'        => $month,
            'year'         => $year,
            'type'         => $type,
            'amount'       => $amount,
            'description'  => $description,
            'bill_url'     => $billUrl,
            'bill_type'    => $billType,
            'expense_date' => $expenseDate,
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $expenseId = $db->insert('ess_expenses', $insertData);

        echo json_encode([
            'success'    => true,
            'message'    => 'Expense submitted successfully',
            'expense_id' => $expenseId,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // ACTION: my_expenses
    // ------------------------------------------------------------------
    if ($action === 'my_expenses') {

        $employeeId = sanitize($_GET['employee_id'] ?? '');
        if (empty($employeeId)) {
            echo json_encode(['success' => false, 'error' => 'employee_id is required']);
            exit;
        }

        $employee = validateEmployee($employeeId);
        if (!$employee) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit;
        }

        // Build query with optional filters
        $where  = "WHERE e.manager_id = ?";
        $params = [$employeeId];

        // Filter by status
        $statusFilter = sanitize($_GET['status'] ?? '');
        if (!empty($statusFilter)) {
            $validStatuses = ['pending', 'approved', 'rejected', 'reimbursed'];
            if (in_array($statusFilter, $validStatuses)) {
                $where .= " AND e.status = ?";
                $params[] = $statusFilter;
            }
        }

        // Filter by month
        $monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : 0;
        if ($monthFilter >= 1 && $monthFilter <= 12) {
            $where .= " AND e.month = ?";
            $params[] = $monthFilter;
        }

        // Filter by year
        $yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : 0;
        if ($yearFilter > 2000) {
            $where .= " AND e.year = ?";
            $params[] = $yearFilter;
        }

        $expenses = $db->fetchAll(
            "SELECT e.* FROM ess_expenses e {$where} ORDER BY e.created_at DESC",
            $params
        );

        echo json_encode([
            'success'  => true,
            'count'    => count($expenses),
            'expenses' => $expenses,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // ACTION: my_balance
    // ------------------------------------------------------------------
    if ($action === 'my_balance') {

        $employeeId = sanitize($_GET['employee_id'] ?? '');
        if (empty($employeeId)) {
            echo json_encode(['success' => false, 'error' => 'employee_id is required']);
            exit;
        }

        $employee = validateEmployee($employeeId);
        if (!$employee) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit;
        }

        // Overall totals (all-time approved)
        $totalAdvanceGiven = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations WHERE manager_id = ?",
            [$employeeId]
        );

        $totalExpensesApproved = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
             WHERE manager_id = ? AND category = 'expense' AND status = 'approved'",
            [$employeeId]
        );

        $totalEmpAdvancesApproved = (float)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
             WHERE manager_id = ? AND category = 'employee_advance' AND status = 'approved'",
            [$employeeId]
        );

        $currentBalance = $totalAdvanceGiven - $totalExpensesApproved - $totalEmpAdvancesApproved;

        // Monthly breakdown - get distinct month/year combos from both allocations and expenses
        $monthlyRows = $db->fetchAll(
            "SELECT month, year FROM ess_expenses WHERE manager_id = ? AND month IS NOT NULL AND year IS NOT NULL
             UNION
             SELECT MONTH(created_at) as month, YEAR(created_at) as year FROM manager_advance_allocations WHERE manager_id = ?
             ORDER BY year DESC, month DESC",
            [$employeeId, $employeeId]
        );

        $monthlyBreakdown = [];
        foreach ($monthlyRows as $row) {
            $m  = (int)$row['month'];
            $y  = (int)$row['year'];

            // Skip invalid
            if ($m < 1 || $m > 12 || $y < 2000) {
                continue;
            }

            $advance   = (float)$db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM manager_advance_allocations
                 WHERE manager_id = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?",
                [$employeeId, $m, $y]
            );
            $expenses  = (float)$db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
                 WHERE manager_id = ? AND category = 'expense' AND status = 'approved' AND month = ? AND year = ?",
                [$employeeId, $m, $y]
            );
            $empAdv    = (float)$db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM ess_expenses
                 WHERE manager_id = ? AND category = 'employee_advance' AND status = 'approved' AND month = ? AND year = ?",
                [$employeeId, $m, $y]
            );
            $balance   = $advance - $expenses - $empAdv;

            $monthlyBreakdown[] = [
                'month'         => $m,
                'year'          => $y,
                'advance'       => round($advance, 2),
                'expenses'      => round($expenses, 2),
                'emp_advances'  => round($empAdv, 2),
                'balance'       => round($balance, 2),
            ];
        }

        echo json_encode([
            'success'                  => true,
            'total_advance_given'      => round($totalAdvanceGiven, 2),
            'total_expenses_approved'  => round($totalExpensesApproved, 2),
            'total_emp_advances_approved' => round($totalEmpAdvancesApproved, 2),
            'current_balance'          => round($currentBalance, 2),
            'monthly_breakdown'        => $monthlyBreakdown,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // ACTION: expense_detail
    // ------------------------------------------------------------------
    if ($action === 'expense_detail') {

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Expense id is required']);
            exit;
        }

        $expense = $db->fetch("SELECT * FROM ess_expenses WHERE id = ?", [$id]);

        if (!$expense) {
            echo json_encode(['success' => false, 'error' => 'Expense not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'expense' => $expense,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // Unknown action
    // ------------------------------------------------------------------
    echo json_encode([
        'success' => false,
        'error'   => 'Unknown action. Valid actions: dashboard, add_expense, my_expenses, my_balance, expense_detail',
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
    ]);
    exit;
}
