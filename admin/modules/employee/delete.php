<?php
/**
 * RCS HRMS Pro - Soft Delete Employee
 * Marks employee as 'removed' instead of deleting from database
 */

// Define redirect URL constant
define('EMPLOYEE_LIST_URL', 'index.php?page=employee/list');

// Get employee ID
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($employeeId <= 0) {
    setFlash('error', 'Invalid employee ID');
    redirect(EMPLOYEE_LIST_URL);
}

// Get employee details before updating
$empData = $employee->getById($employeeId);

if (!$empData) {
    setFlash('error', 'Employee not found');
    redirect(EMPLOYEE_LIST_URL);
}

// Soft delete - update status to 'removed' instead of deleting
try {
    $stmt = $db->prepare("UPDATE employees SET status = 'removed', updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$employeeId]);
    
    if ($result) {
        // Log activity
        logActivity('delete', 'employees', $employeeId, "Removed employee: " . $empData['full_name'] . " (" . $empData['employee_code'] . ")");
        
        setFlash('success', "Employee '{$empData['full_name']}' has been removed successfully.");
    } else {
        setFlash('error', 'Failed to remove employee. Please try again.');
    }
} catch (Exception $e) {
    error_log("Error removing employee: " . $e->getMessage());
    setFlash('error', 'An error occurred while removing the employee.');
}

// Redirect back to employee list
redirect(EMPLOYEE_LIST_URL);
