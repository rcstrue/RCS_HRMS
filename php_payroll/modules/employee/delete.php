<?php
/**
 * RCS HRMS Pro - Soft Delete Employee
 * Marks employee as 'removed' instead of deleting from database
 * Requires date_of_leaving and reason via POST
 */

// Define redirect URL constant
define('EMPLOYEE_LIST_URL', 'index.php?page=employee/list');

// Get employee ID
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($employeeId <= 0) {
    setFlash('error', 'Invalid employee ID');
    redirect(EMPLOYEE_LIST_URL);
}

// Require POST with date_of_leaving and reason
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Invalid request. Please use the Remove button on the Employee List page.');
    redirect(EMPLOYEE_LIST_URL);
}

$dateOfLeaving = sanitize($_POST['date_of_leaving'] ?? '');
$reason = sanitize($_POST['reason'] ?? '');

if (empty($dateOfLeaving)) {
    setFlash('error', 'Date of Leaving is required.');
    redirect(EMPLOYEE_LIST_URL);
}

if (empty($reason)) {
    setFlash('error', 'Reason for removal is required.');
    redirect(EMPLOYEE_LIST_URL);
}

// Get employee details before updating
$empData = $employee->getById($employeeId);

if (!$empData) {
    setFlash('error', 'Employee not found');
    redirect(EMPLOYEE_LIST_URL);
}

// Soft delete - update status to 'removed' with date_of_leaving
// Note: reason is stored in activity log since employees table has no remarks column
try {
    $stmt = $db->prepare("UPDATE employees SET status = 'removed', date_of_leaving = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$dateOfLeaving, $employeeId]);
    
    if ($result) {
        // Log activity
        logActivity('delete', 'employees', $employeeId, "Removed employee: " . $empData['full_name'] . " (" . $empData['employee_code'] . ") | DOL: " . $dateOfLeaving . " | Reason: " . $reason);
        
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