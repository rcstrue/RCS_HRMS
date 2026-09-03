<?php
/**
 * Bulk Email - CSV Export
 * Downloads results as CSV file
 */
$pageTitle = 'Export';

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    die('Access denied.');
}

$csvData = $_SESSION['bulk_email_csv'] ?? '';
if (empty($csvData)) {
    setFlash('error', 'No export data available.');
    redirect('index.php?page=notifications/bulk-email');
    exit;
}

$filename = 'bulk_email_results_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');
header('Pragma: public');

// Add BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";
echo $csvData;
exit;
