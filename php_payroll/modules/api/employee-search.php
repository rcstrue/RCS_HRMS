<?php
/**
 * RCS HRMS Pro - Employee Search API
 * Lightweight search for employee autocomplete (used by WhatsApp, etc.)
 *
 * Route: index.php?page=api/employee-search&q=searchterm
 */

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$q = sanitize($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$rows = $db->fetchAll(
    "SELECT e.id, e.employee_code, e.full_name, e.mobile_number, e.designation, e.status
     FROM employees e
     WHERE (e.full_name LIKE ? OR e.employee_code LIKE ? OR e.mobile_number LIKE ?)
     AND e.status IN ('approved', 'active')
     ORDER BY e.employee_code
     LIMIT 20",
    ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']
);

echo json_encode($rows);