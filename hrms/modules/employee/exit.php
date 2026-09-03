<?php
/**
 * RCS HRMS Pro - Mark Employee Exit
 * Sets employee status to 'inactive' with date_of_leaving.
 * Unlike 'remove' (which sets status='removed'), exit keeps the employee
 * record visible but inactive.
 */

define('EMPLOYEE_LIST_URL', 'index.php?page=employee/list');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Invalid request.');
    redirect(EMPLOYEE_LIST_URL);
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request. Please refresh the page and try again.');
    redirect(EMPLOYEE_LIST_URL);
}

$employeeId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($employeeId <= 0) {
    setFlash('error', 'Invalid employee ID.');
    redirect(EMPLOYEE_LIST_URL);
}

$dateOfLeaving = sanitize($_POST['date_of_leaving'] ?? '');
$reason = sanitize($_POST['reason'] ?? '');

if (empty($dateOfLeaving)) {
    setFlash('error', 'Date of Leaving is required.');
    redirect(EMPLOYEE_LIST_URL);
}

if (empty($reason)) {
    setFlash('error', 'Reason for exit is required.');
    redirect(EMPLOYEE_LIST_URL);
}

$empData = $employee->getById($employeeId);

if (!$empData) {
    setFlash('error', 'Employee not found.');
    redirect(EMPLOYEE_LIST_URL);
}

try {
    $stmt = $db->prepare("UPDATE employees SET status = 'inactive', date_of_leaving = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$dateOfLeaving, $employeeId]);

    if ($result) {
        logActivity('update', 'employees', $employeeId,
            "Marked exit: " . $empData['full_name'] . " (" . $empData['employee_code'] . ") | DOL: " . $dateOfLeaving . " | Reason: " . $reason);
        setFlash('success', "Employee '" . $empData['full_name'] . "' marked as exited (inactive)." );
    } else {
        setFlash('error', 'Failed to mark exit. Please try again.');
    }
} catch (Exception $e) {
    error_log("Error marking employee exit: " . $e->getMessage());
    setFlash('error', 'An error occurred while marking exit.');
}

redirect(EMPLOYEE_LIST_URL);
