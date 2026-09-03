<?php
/**
 * Standalone Excel template download for Salary Revision
 * Generates a proper Excel 2003 XML (.xls) file without any external libraries.
 * This file must be outside the module system to send headers before any HTML output.
 */
define('RCS_HRMS', true);
define('APP_ROOT', __DIR__);
require_once __DIR__ . '/config/config.php';

// ── SECURITY: auth + role check ─────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/html');
    echo '<h1>401 - Authentication required</h1><p>Please <a href="index.php?page=auth/login">log in</a> first.</p>';
    exit;
}
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr_executive', 'hr', 'manager'], true)) {
    http_response_code(403);
    header('Content-Type: text/html');
    echo '<h1>403 - Access Denied</h1><p>You do not have permission to download the salary template.</p>';
    exit;
}

$db = Database::getInstance();

// Optional: filter by unit if provided
$filterUnitId = (int)($_GET['unit_id'] ?? 0);
$filterClientId = (int)($_GET['client_id'] ?? 0);

try {
    // Build query
    $sql = "SELECT e.employee_code, e.full_name,
                   c.name as client_name, u.name as unit_name
            FROM employees e
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE e.status = 'approved'";
    $params = [];

    if ($filterUnitId > 0) {
        $sql .= " AND e.unit_id = ?";
        $params[] = $filterUnitId;
    } elseif ($filterClientId > 0) {
        $sql .= " AND e.client_id = ?";
        $params[] = $filterClientId;
    }

    $sql .= " ORDER BY c.name, u.name, e.employee_code";

    $allEmployees = $db->fetchAll($sql, $params);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/html');
    echo '<h1>Database Error</h1><p>Could not fetch employees: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}

// ── Generate Excel 2003 XML (SpreadsheetML) ─────────────────────────────────
// This format opens natively in Excel, LibreOffice, and Google Sheets.
// No external libraries required.

$filename = 'salary_revision_template_' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

// Build XML
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel">' . "\n";

// Styles
$xml .= '<Styles>' . "\n";
// Header style - bold with background
$xml .= '  <Style ss:ID="header">' . "\n";
$xml .= '    <Font ss:Bold="1" ss:Size="11" ss:Color="#FFFFFF"/>' . "\n";
$xml .= '    <Interior ss:Color="#4472C4" ss:Pattern="Solid"/>' . "\n";
$xml .= '    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
$xml .= '    <Borders>' . "\n";
$xml .= '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
$xml .= '      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
$xml .= '    </Borders>' . "\n";
$xml .= '  </Style>' . "\n";
// Sub-header style
$xml .= '  <Style ss:ID="subheader">' . "\n";
$xml .= '    <Font ss:Bold="1" ss:Size="10" ss:Color="#333333"/>' . "\n";
$xml .= '    <Interior ss:Color="#D9E2F3" ss:Pattern="Solid"/>' . "\n";
$xml .= '    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
$xml .= '  </Style>' . "\n";
// Normal cell
$xml .= '  <Style ss:ID="normal">' . "\n";
$xml .= '    <Font ss:Size="10"/>' . "\n";
$xml .= '    <Alignment ss:Vertical="Center"/>' . "\n";
$xml .= '  </Style>' . "\n";
// Code cell - monospace
$xml .= '  <Style ss:ID="code">' . "\n";
$xml .= '    <Font ss:Size="10" ss:FontName="Consolas"/>' . "\n";
$xml .= '    <Alignment ss:Vertical="Center"/>' . "\n";
$xml .= '  </Style>' . "\n";
// Number cell - right aligned
$xml .= '  <Style ss:ID="number">' . "\n";
$xml .= '    <Font ss:Size="10"/>' . "\n";
$xml .= '    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . "\n";
$xml .= '    <NumberFormat ss:Format="#,##0.00"/>' . "\n";
$xml .= '  </Style>' . "\n";
// Reference (readonly-looking) cell
$xml .= '  <Style ss:ID="reference">' . "\n";
$xml .= '    <Font ss:Size="10" ss:Color="#888888" ss:Italic="1"/>' . "\n";
$xml .= '    <Alignment ss:Vertical="Center"/>' . "\n";
$xml .= '  </Style>' . "\n";
$xml .= '</Styles>' . "\n";

// Worksheet
$xml .= '<Worksheet ss:Name="Salary Revision">' . "\n";
$xml .= '<Table ss:DefaultColumnWidth="100" ss:DefaultRowHeight="20">' . "\n";

// Column widths
$colWidths = [
    'Employee Code' => 80,
    'Employee Name' => 120,
    'Client' => 100,
    'Unit' => 100,
    'Basic + DA' => 90,
    'HRA' => 80,
    'Leave Encashment' => 90,
    'Bonus Encashment' => 90,
    'Washing Allowance' => 90,
    'PF (1=Yes/0=No)' => 80,
    'ESI (1=Yes/0=No)' => 80,
    'PT (1=Yes/0=No)' => 80,
    'LWF (1=Yes/0=No)' => 80,
];
foreach ($colWidths as $name => $width) {
    $xml .= '<Column ss:Width="' . $width . '"/>' . "\n";
}

// Header row
$headers = ['Employee Code', 'Employee Name', 'Client', 'Unit',
            'Basic + DA', 'HRA', 'Leave Encashment', 'Bonus Encashment', 'Washing Allowance',
            'PF (1=Yes/0=No)', 'ESI (1=Yes/0=No)', 'PT (1=Yes/0=No)', 'LWF (1=Yes/0=No)'];

$xml .= '<Row ss:Height="30">' . "\n";
foreach ($headers as $h) {
    $xml .= '  <Cell ss:StyleID="header"><Data ss:Type="String">' . htmlspecialchars($h, ENT_XML1) . '</Data></Cell>' . "\n";
}
$xml .= '</Row>' . "\n";

// Instruction row
$xml .= '<Row ss:Height="20">' . "\n";
$instructions = [
    'Enter exact code', 'Reference only', 'Reference only', 'Reference only',
    'Enter amount', 'Enter amount', 'Enter amount', 'Enter amount', 'Enter amount',
    '1 or 0', '1 or 0', '1 or 0', '1 or 0'
];
for ($i = 0; $i < count($instructions); $i++) {
    $xml .= '  <Cell ss:StyleID="subheader"><Data ss:Type="String">' . htmlspecialchars($instructions[$i], ENT_XML1) . '</Data></Cell>' . "\n";
}
$xml .= '</Row>' . "\n";

// Data rows
$rowNum = 2;
foreach ($allEmployees as $emp) {
    $rowNum++;
    $xml .= '<Row>' . "\n";
    
    // Employee Code
    $xml .= '  <Cell ss:StyleID="code"><Data ss:Type="String">' . htmlspecialchars($emp['employee_code'], ENT_XML1) . '</Data></Cell>' . "\n";
    // Employee Name (reference)
    $xml .= '  <Cell ss:StyleID="reference"><Data ss:Type="String">' . htmlspecialchars($emp['full_name'], ENT_XML1) . '</Data></Cell>' . "\n";
    // Client (reference)
    $xml .= '  <Cell ss:StyleID="reference"><Data ss:Type="String">' . htmlspecialchars($emp['client_name'] ?? '', ENT_XML1) . '</Data></Cell>' . "\n";
    // Unit (reference)
    $xml .= '  <Cell ss:StyleID="reference"><Data ss:Type="String">' . htmlspecialchars($emp['unit_name'] ?? '', ENT_XML1) . '</Data></Cell>' . "\n";
    // Blank editable number cells
    for ($i = 0; $i < 5; $i++) {
        $xml .= '  <Cell ss:StyleID="number"><Data ss:Type="Number">0</Data></Cell>' . "\n";
    }
    // Statutory defaults = 1
    for ($i = 0; $i < 4; $i++) {
        $xml .= '  <Cell ss:StyleID="number"><Data ss:Type="Number">1</Data></Cell>' . "\n";
    }
    
    $xml .= '</Row>' . "\n";
}

$xml .= '</Table>' . "\n";
$xml .= '</Worksheet>' . "\n";
$xml .= '</Workbook>';

echo $xml;
exit;
