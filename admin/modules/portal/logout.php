<?php
/**
 * RCS HRMS Pro - Employee Portal Logout
 */

session_start();

// Log the logout action
if (isset($_SESSION['employee_portal'])) {
    require_once '../../config/config.php';
    require_once '../../includes/database.php';
    
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

// Destroy session
unset($_SESSION['employee_portal']);
session_destroy();

// Redirect to login
header('Location: index.php?page=portal/login');
exit;
