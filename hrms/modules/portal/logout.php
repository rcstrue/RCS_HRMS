<?php
/**
 * RCS HRMS Pro - Employee Portal Logout
 * Note: Included by index.php BEFORE any HTML output, so header() works.
 * Session is already started by the framework.
 *
 * SECURITY (Round 3): use audit_log (consistent with portal/login.php) instead
 * of activity_log which does not exist in the schema. Include ip_address +
 * employee_code in the audit detail for traceability.
 */

// Log the logout action (before destroying session)
if (isset($_SESSION['employee_portal'])) {
    try {
        $db = Database::getInstance();
        $empCode = $_SESSION['employee_portal']['employee_code'] ?? '';
        $empId   = $_SESSION['employee_portal']['employee_id'] ?? null;
        $db->insert('audit_log', [
            'user_id'    => $empId,
            'action'     => 'employee_portal_logout',
            'details'    => json_encode([
                'employee_code' => $empCode,
                'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        // Ignore errors on logout — don't block the user from logging out.
        error_log('portal logout audit_log insert failed: ' . $e->getMessage());
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