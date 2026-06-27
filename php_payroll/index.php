<?php
/**
 * RCS HRMS Pro - Main Entry Point
 * Security: User inputs are sanitized and validated before use
 */

// Define application constant
define('RCS_HRMS', true);

// Include configuration (this also starts session and has autoloader)
require_once dirname(__FILE__) . '/config/config.php';

// Include database connection
require_once dirname(__FILE__) . '/includes/database.php';

// Initialize all classes
try {
    $auth = new Auth();
    $employee = new Employee();
    $attendance = new Attendance();
    $payroll = new Payroll();
    $compliance = new Compliance();
    $client = new Client();
    $unit = new Unit();
    if (class_exists('Loan')) { $loan = new Loan(); }
} catch (Exception $e) {
    die("Error initializing application: " . $e->getMessage());
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

/**
 * Sanitize page parameter to prevent path traversal and injection attacks
 * @param string $page The page parameter to sanitize
 * @return string|null Sanitized page or null if invalid
 */
function sanitizePageParam($page) {
    if (empty($page)) {
        return null;
    }
    
    // Remove any null bytes
    $page = str_replace("\0", '', $page);
    
    // Only allow alphanumeric characters, forward slash, underscore, and hyphen
    if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $page)) {
        return null;
    }
    
    // Prevent path traversal
    if (strpos($page, '..') !== false || strpos($page, '//') !== false) {
        return null;
    }
    
    // Must start with a letter
    if (!preg_match('/^[a-zA-Z]/', $page)) {
        return null;
    }
    
    return $page;
}

/**
 * Validate and get safe file path for module
 * @param string $page The sanitized page parameter
 * @return string|null Safe file path or null if invalid
 */
function getSafeModulePath($page) {
    if ($page === null) {
        return null;
    }
    
    // Define allowed modules (whitelist)
    $allowedModules = [
        'dashboard', 'auth', 'employee', 'attendance', 'payroll', 'compliance',
        'report', 'settings', 'profile', 'client', 'unit', 'forms', 'helpdesk',
        'assets', 'recruitment', 'billing', 'ratecard', 'contract', 'deployment',
        'announcement', 'requisition', 'advance', 'timesheet', 'leave', 'settlement',
        'audit', 'notifications', 'portal', 'api', 'bulk-upload', 'expense', 'loan', 'entry'
    ];
    
    // Extract module name
    $pageParts = explode('/', $page);
    $module = isset($pageParts[0]) ? $pageParts[0] : '';
    
    // Check if module is allowed
    if (!in_array($module, $allowedModules)) {
        return null;
    }
    
    // Build the file path
    $basePath = dirname(__FILE__) . '/modules/';
    $filePath = $basePath . $page . '.php';
    
    // Resolve the real path and verify it's within the modules directory
    $realPath = realpath($basePath);
    $resolvedPath = realpath(dirname($filePath));
    
    if ($resolvedPath === false || strpos($resolvedPath, $realPath) !== 0) {
        return null;
    }
    
    // Check if file exists
    if (!file_exists($filePath)) {
        return null;
    }
    
    return $filePath;
}

// Get and sanitize requested page
$rawPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$page = sanitizePageParam($rawPage);

// If page is invalid, redirect to dashboard
if ($page === null) {
    if ($isLoggedIn) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid page request.'];
        header("Location: index.php?page=dashboard");
    } else {
        header("Location: index.php?page=auth/login");
    }
    exit;
}

$action = isset($_GET['action']) ? sanitizePageParam($_GET['action']) : null;

// Handle AJAX requests
if (isset($_GET['ajax']) || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    if (!$isLoggedIn && $page !== 'auth/login') {
        echo json_encode(['error' => 'Authentication required', 'redirect' => 'index.php?page=auth/login']);
        exit;
    }
    
    // Fix: Single assignment using getSafeModulePath only
    $ajaxFile = getSafeModulePath($page . '/ajax');
    
    if ($ajaxFile !== null && file_exists($ajaxFile)) {
        include $ajaxFile;
    } else {
        echo json_encode(['error' => 'Invalid request']);
    }
    exit;
}

// Handle API requests
if (strpos($page, 'api/') === 0) {
    header('Content-Type: application/json');

    // Require authentication for API endpoints
    if (!$isLoggedIn) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    // Role-based access control for API endpoints — same $moduleAccess table as
    // normal page routing, export, and delete.  Without this, any logged-in user
    // (including the lowest-privilege 'worker' role) could call admin-only API
    // actions like bulk salary edits or employee approval.
    $apiPageParts = explode('/', $page);
    $apiModule = $apiPageParts[1] ?? '';  // e.g. 'bulk-edit' from 'api/bulk-edit'
    // Map API sub-paths to their parent module for access checks
    $apiModuleMap = [
        'bulk-edit'       => 'employee',
        'employees'       => 'employee',
        'payroll-update'  => 'payroll',
        'payroll-save-row'=> 'payroll',
        'expense-api'     => 'expense',
        'menu-permissions'=> 'settings',
        'crop-save'       => 'employee',
        'image-tool'      => 'employee',
        'manager-units'   => 'employee',
        'next-unit-code'  => 'unit',
        'save-filter'     => 'dashboard',
        'units'           => 'unit',
        'whatsapp-salary' => 'payroll',
        'zones'           => 'unit',
    ];
    $effectiveModule = $apiModuleMap[$apiModule] ?? $apiModule;

    $roleCode = $_SESSION['role_code'] ?? '';
    $apiModuleAccess = [
        'admin'        => ['all'],
        'hr_executive' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry', 'unit', 'client'],
        'hr'           => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry', 'unit', 'client'],
        'manager'      => ['dashboard', 'employee', 'attendance', 'payroll', 'report', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry', 'unit'],
        'supervisor'   => ['dashboard', 'employee', 'attendance', 'profile', 'auth', 'notifications'],
        'worker'       => ['dashboard', 'portal', 'profile', 'auth', 'notifications', 'expense'],
    ];

    $apiAllowed = $apiModuleAccess[$roleCode] ?? ['dashboard', 'profile', 'auth', 'notifications'];
    if (!in_array('all', $apiAllowed) && !in_array($effectiveModule, $apiAllowed)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. Insufficient permissions for this API endpoint.']);
        exit;
    }

    // Validate API path
    $apiPath = getSafeModulePath($page);
    if ($apiPath !== null) {
        include $apiPath;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not found']);
    }
    exit;
}

// Handle Expense Dashboard CSV/XLSX template downloads (before header to avoid headers already sent)
if (isset($_GET['download_template']) && isset($_GET['page']) && $_GET['page'] === 'expense/dashboard' && $isLoggedIn) {
    $headers = ['Employee Code', 'Date', 'Advance', 'Expense', 'Remark'];
    $sample  = ['EMP001', '2026-06-01', '5000', '0', 'Monthly advance'];
    $fileName = 'Bulk_Upload_Template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'w');
    // BOM for Excel UTF-8 support
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, $headers, ',', '"', '\\');
    fputcsv($output, $sample, ',', '"', '\\');
    fclose($output);
    exit;
}

// Handle Download Template request (before header is included)
if (isset($_GET['download_template']) && $isLoggedIn && !isset($_GET['page'])) {
    $filename = 'Salary_Upload_Template.csv';
    $headers = ['Emp Code', 'Basic+DA', 'HRA', 'Leave Encashment', 'Bonus Encashment', 'Washing Allowance', 'Other Allowance'];
    $sample = ['EMP001', '15000', '3000', '500', '1000', '200', '500'];
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers, ',', '"', '\\');
    fputcsv($output, $sample, ',', '"', '\\');
    fclose($output);
    exit;
}

// Handle Download Current Salary request (before header is included)
if (isset($_GET['download_current']) && $isLoggedIn) {
    $clientId = (int)($_GET['client_id'] ?? 0);
    $unitId = (int)($_GET['unit_id'] ?? 0);
    
    // Use actual columns from employee_salary_structures: basic_da, hra, leave_encashment, bonus_encashment, washing_allowance
    $sql = "SELECT e.employee_code, e.full_name, c.name as client_name, u.name as unit_name,
            COALESCE(ess.basic_da,0) as basic_da,
            COALESCE(ess.hra,0) as hra,
            COALESCE(ess.leave_encashment,0) as leave_encashment,
            COALESCE(ess.bonus_encashment,0) as bonus_encashment,
            COALESCE(ess.washing_allowance,0) as washing,
            COALESCE(ess.gross_salary,0) as gross_salary
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE e.status = 'approved'";
    
    $params = [];
    if ($clientId) {
        $sql .= " AND e.client_id = ?";
        $params[] = $clientId;
    }
    if ($unitId) {
        $sql .= " AND e.unit_id = ?";
        $params[] = $unitId;
    }
    
    $sql .= " ORDER BY c.name, u.name, e.employee_code";
    
    $employees = $db->fetchAll($sql, $params);
    
    $filename = 'Current_Salary_' . date('Ymd') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Emp Code', 'Name', 'Client', 'Unit', 'Basic+DA', 'HRA', 'Leave Encashment', 'Bonus Encashment', 'Washing', 'Gross'], ',', '"', '\\');
    
    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['employee_code'],
            $emp['full_name'],
            $emp['client_name'],
            $emp['unit_name'],
            $emp['basic_da'],
            $emp['hra'],
            $emp['leave_encashment'],
            $emp['bonus_encashment'],
            $emp['washing'],
            $emp['gross_salary']
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit;
}



// Handle Export requests (before header is included to avoid "headers already sent" errors)
if (isset($_GET['export']) && $isLoggedIn) {
    // RBAC check - use same access rules as normal routing
    $pageParts = explode('/', $page);
    $module = $pageParts[0] ?? '';
    $roleCode = $_SESSION['role_code'] ?? '';
    
    $moduleAccess = [
        'admin' => ['all'],
        'hr_executive' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'hr' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'manager' => ['dashboard', 'employee', 'attendance', 'payroll', 'report', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'supervisor' => ['dashboard', 'employee', 'attendance', 'profile', 'auth', 'notifications'],
        'worker' => ['dashboard', 'portal', 'profile', 'auth', 'notifications', 'expense']
    ];
    
    $allowed = $moduleAccess[$roleCode] ?? ['dashboard', 'profile', 'auth', 'notifications', 'expense'];
    if (!in_array('all', $allowed) && !in_array($module, $allowed)) {
        http_response_code(403);
        exit('Access denied');
    }
    
    $exportPath = getSafeModulePath($page);
    if ($exportPath !== null) {
        $isExportRequest = true;
        include $exportPath;
    }
    exit;
}

// Handle Delete/Remove requests (before header is included)
if (strpos($page, '/delete') !== false && $isLoggedIn) {
    // RBAC check - use same access rules as normal routing
    $pageParts = explode('/', $page);
    $module = $pageParts[0] ?? '';
    $roleCode = $_SESSION['role_code'] ?? '';
    
    $moduleAccess = [
        'admin' => ['all'],
        'hr_executive' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'hr' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'manager' => ['dashboard', 'employee', 'attendance', 'payroll', 'report', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'supervisor' => ['dashboard', 'employee', 'attendance', 'profile', 'auth', 'notifications'],
        'worker' => ['dashboard', 'portal', 'profile', 'auth', 'notifications', 'expense']
    ];

    $allowed = $moduleAccess[$roleCode] ?? ['dashboard', 'profile', 'auth', 'notifications', 'expense'];
    if (!in_array('all', $allowed) && !in_array($module, $allowed)) {
        http_response_code(403);
        exit('Access denied');
    }

    // CSRF validation for POST-based deletes
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }
    
    $deletePath = getSafeModulePath($page);
    if ($deletePath !== null) {
        include $deletePath;
    }
    exit;
}

// Handle ID Card generation and preview (before header is included to avoid "headers already sent" errors)
if ($page === 'employee/id-card' && $isLoggedIn && (isset($_GET['generate']) || isset($_GET['preview']))) {
    $idCardPath = getSafeModulePath($page);
    if ($idCardPath !== null) {
        $isIdCardGeneration = true;
        include $idCardPath;
    }
    exit;
}

// Route to appropriate page
if (!$isLoggedIn) {
    $allowedPages = ['auth/login', 'auth/forgot-password', 'auth/reset-password'];
    
    if (!in_array($page, $allowedPages)) {
        header("Location: index.php?page=auth/login");
        exit;
    }
} else {
    $pageParts = explode('/', $page);
    $module = isset($pageParts[0]) ? $pageParts[0] : '';
    
    $moduleAccess = [
        'admin' => ['all'],
        'hr_executive' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'loan', 'entry'],
        'hr' => ['dashboard', 'employee', 'attendance', 'payroll', 'compliance', 'report', 'settings', 'profile', 'auth', 'notifications', 'loan', 'entry'],
        'manager' => ['dashboard', 'employee', 'attendance', 'payroll', 'report', 'profile', 'auth', 'notifications', 'expense', 'loan', 'entry'],
        'supervisor' => ['dashboard', 'employee', 'attendance', 'profile', 'auth', 'notifications'],
        'worker' => ['dashboard', 'portal', 'profile', 'auth', 'notifications']
    ];
    
    $roleCode = isset($_SESSION['role_code']) ? $_SESSION['role_code'] : '';
    $allowedModules = isset($moduleAccess[$roleCode]) ? $moduleAccess[$roleCode] : ['dashboard', 'profile', 'auth', 'notifications'];
    
    // Always allow auth module (for logout, password change, etc.)
    if (!in_array('all', $allowedModules) && !in_array('auth', $allowedModules)) {
        $allowedModules[] = 'auth';
    }
    
    if (!in_array('all', $allowedModules) && !in_array($module, $allowedModules)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'You do not have access to this module.'];
        header("Location: index.php?page=dashboard");
        exit;
    }
}

// Include header template
include dirname(__FILE__) . '/templates/header.php';

// Include page content with validated path
$pagePath = getSafeModulePath($page);
if ($pagePath !== null) {
    include $pagePath;
} else {
    // Default to dashboard if page not found
    include dirname(__FILE__) . '/modules/dashboard/index.php';
}

// Include footer template
include dirname(__FILE__) . '/templates/footer.php';
