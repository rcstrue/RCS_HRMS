<?php
/**
 * RCS HRMS Pro - One-time Migration Script
 * Fixes employee upload paths in the database to include /uploads/ prefix
 * 
 * Usage: Access via browser: https://your-domain.com/hrms/scripts/fix-upload-paths.php
 * Or CLI: php scripts/fix-upload-paths.php
 * 
 * This script updates the following columns in the employees table:
 * - profile_pic_url
 * - profile_pic_cropped_url
 * - aadhaar_front_url
 * - aadhaar_back_url
 * - bank_document_url
 * 
 * Also updates employee_change_requests table where new_value contains upload paths
 */

// Bootstrap HRMS environment
$scriptDir = __DIR__;
$appDir = dirname($scriptDir);
$rootDir = dirname($appDir);

// Try to load the HRMS config
$configFile = $rootDir . '/includes/config.php';
if (!file_exists($configFile)) {
    die("Error: config.php not found at $configFile\n");
}

// We need DB connection - load config to get credentials
require_once $configFile;

// Get DB settings from defines
$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$dbName = defined('DB_NAME') ? DB_NAME : '';
$dbUser = defined('DB_USER') ? DB_USER : '';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

if (empty($dbName)) {
    die("Error: DB_NAME not defined in config.\n");
}

try {
    $conn = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

echo "<h2>Upload Path Migration</h2>\n";
echo "<pre>\n";

$columns = ['profile_pic_url', 'profile_pic_cropped_url', 'aadhaar_front_url', 'aadhaar_back_url', 'bank_document_url'];
$totalFixed = 0;

foreach ($columns as $col) {
    // Check if column exists in employees table
    $colCheck = $conn->query("SHOW COLUMNS FROM employees LIKE '$col'");
    if ($colCheck->rowCount() === 0) {
        echo "Column $col: NOT FOUND - skipping\n";
        continue;
    }
    
    // Find rows where the value is a relative path (doesn't start with /uploads/ and isn't a full URL)
    $stmt = $conn->prepare("
        SELECT id, $col FROM employees 
        WHERE $col IS NOT NULL 
          AND $col != '' 
          AND $col NOT LIKE 'http%' 
          AND $col NOT LIKE '/uploads/%'
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    
    $count = count($rows);
    echo "Column $col: $count row(s) to fix\n";
    
    if ($count > 0) {
        $update = $conn->prepare("UPDATE employees SET $col = ? WHERE id = ?");
        foreach ($rows as $row) {
            $fixedPath = '/uploads/' . ltrim($row[$col], '/');
            $update->execute([$fixedPath, $row['id']]);
            echo "  - Employee #{$row['id']}: {$row[$col]} -> $fixedPath\n";
            $totalFixed++;
        }
    }
}

// Also fix employee_change_requests table (new_value for profile_pic_url)
echo "\nChecking employee_change_requests table...\n";

try {
    $crColCheck = $conn->query("SHOW TABLES LIKE 'employee_change_requests'");
    if ($crColCheck->rowCount() > 0) {
        $stmt = $conn->prepare("
            SELECT id, new_value FROM employee_change_requests 
            WHERE field_name IN ('profile_pic_url', 'profile_pic_cropped_url', 'aadhaar_front_url', 'aadhaar_back_url', 'bank_document_url')
              AND new_value IS NOT NULL 
              AND new_value != ''
              AND new_value NOT LIKE 'http%' 
              AND new_value NOT LIKE '/uploads/%'
              AND status = 'pending'
        ");
        $stmt->execute();
        $crRows = $stmt->fetchAll();
        
        echo "Change requests with relative paths: " . count($crRows) . "\n";
        
        if (count($crRows) > 0) {
            $crUpdate = $conn->prepare("UPDATE employee_change_requests SET new_value = ? WHERE id = ?");
            foreach ($crRows as $row) {
                $fixedPath = '/uploads/' . ltrim($row['new_value'], '/');
                $crUpdate->execute([$fixedPath, $row['id']]);
                echo "  - CR #{$row['id']}: {$row['new_value']} -> $fixedPath\n";
                $totalFixed++;
            }
        }
    } else {
        echo "Table employee_change_requests does not exist - skipping\n";
    }
} catch (Exception $e) {
    echo "Error checking change_requests: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Total paths fixed: $totalFixed\n";
echo "Migration complete!\n";
echo "</pre>\n";
?>