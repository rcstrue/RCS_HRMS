<?php
/**
 * RCS HRMS Pro - Employee Edit Redirect
 * Redirects to add.php with id parameter for editing
 */

// Redirect to add.php with the id parameter
$employeeId = $_GET['id'] ?? null;
if ($employeeId) {
    redirect('index.php?page=employee/add&id=' . $employeeId);
} else {
    redirect('index.php?page=employee/list');
}
