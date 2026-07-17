<?php
/**
 * RCS HRMS Pro - Employee Portal Logout
 * Note: Included by index.php BEFORE any HTML output, so header() works.
 * Session is already started by the framework.
 */

// Log the logout action (before destroying session)
if (isset($_SESSION['employee_portal'])) {
    try {
        $db = Database::getInstance();
        $db->insert('activity_log', [
            'user_id' => null,
            'action' => 'employee_portal_logout',
            'module' => 'portal',
            'description' => "Employee {$_SESSION['employee_portal']['employee_code']} logged out",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        // Ignore errors on logout
    }
}

// Destroy entire session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// Cache-busting redirect
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: index.php?page=portal/login&t=' . time());
exit;