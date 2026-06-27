<?php
/**
 * Standalone CSV template download for Salary Revision
 * This file must be outside the module system to send headers before any HTML output.
 */
define('RCS_HRMS', true);
define('APP_ROOT', __DIR__);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/class.database.php';

$db = Database::getInstance();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="salary_template_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM for Excel

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'Employee Code',
    'Basic + DA',
    'HRA',
    'Leave Encashment',
    'Bonus Encashment',
    'Washing Allowance',
    'PF Applicable (1=Yes, 0=No)',
    'ESI Applicable (1=Yes, 0=No)',
    'PT Applicable (1=Yes, 0=No)',
    'LWF Applicable (1=Yes, 0=No)',
    'Employee Name (Reference Only)'
], ',', '"', '');

// Get all active employees for reference
$allEmployees = $db->fetchAll(
    "SELECT e.employee_code, e.full_name
     FROM employees e
     WHERE e.status = 'approved'
     ORDER BY e.employee_code"
);

foreach ($allEmployees as $emp) {
    fputcsv($output, [
        $emp['employee_code'],
        '', '', '', '', '',
        '1', '1', '1', '1',
        $emp['full_name']
    ], ',', '"', '');
}

fclose($output);
exit;