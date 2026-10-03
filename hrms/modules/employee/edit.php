<?php
/**
 * RCS HRMS Pro - Employee Edit Redirect
 * Redirects to add.php with code parameter for editing
 * Supports both ?code= (preferred) and ?id= (legacy)
 */

$employeeCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$employeeId = $_GET['id'] ?? null;

if ($employeeCode) {
    redirect('index.php?page=employee/add&code=' . urlencode($employeeCode));
} elseif ($employeeId) {
    redirect('index.php?page=employee/add&id=' . $employeeId);
} else {
    redirect('index.php?page=employee/list');
}
