<?php
/**
 * RCS HRMS Pro - One-time Migration Script
 * Fixes unit-visit upload paths in the database to include /uploads/ prefix
 * 
 * Usage: CLI: php scripts/fix-unit-visit-paths.php
 * Or browser: https://your-domain.com/hrms/scripts/fix-unit-visit-paths.php
 * 
 * Fixes:
 * - ess_unit_visits.document_url
 * - ess_visit_checklist_items.photo_url
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

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) echo "<h2>Unit Visit Upload Path Migration</h2><pre>\n";
else echo "Unit Visit Upload Path Migration\n" . str_repeat('=', 40) . "\n";

$totalFixed = 0;

// ── Fix ess_unit_visits.document_url ────────────────────────────────────────
$table1 = 'ess_unit_visits';
$col1 = 'document_url';

$exists1 = $conn->query("SHOW TABLES LIKE '$table1'")->rowCount() > 0;
if (!$exists1) {
    echo "Table $table1: NOT FOUND - skipping\n";
} else {
    $stmt = $conn->prepare("
        SELECT id, $col1 FROM $table1
        WHERE $col1 IS NOT NULL
          AND $col1 != ''
          AND $col1 NOT LIKE 'http%'
          AND $col1 NOT LIKE '/uploads/%'
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $count = count($rows);
    echo "$table1.$col1: $count row(s) to fix\n";

    if ($count > 0) {
        $update = $conn->prepare("UPDATE $table1 SET $col1 = ? WHERE id = ?");
        foreach ($rows as $row) {
            $fixedPath = '/uploads/' . ltrim($row[$col1], '/');
            $update->execute([$fixedPath, $row['id']]);
            echo "  - Visit #{$row['id']}: {$row[$col1]} -> $fixedPath\n";
            $totalFixed++;
        }
    }
}

// ── Fix ess_visit_checklist_items.photo_url ────────────────────────────────
$table2 = 'ess_visit_checklist_items';
$col2 = 'photo_url';

$exists2 = $conn->query("SHOW TABLES LIKE '$table2'")->rowCount() > 0;
if (!$exists2) {
    echo "Table $table2: NOT FOUND - skipping\n";
} else {
    $stmt2 = $conn->prepare("
        SELECT id, $col2 FROM $table2
        WHERE $col2 IS NOT NULL
          AND $col2 != ''
          AND $col2 NOT LIKE 'http%'
          AND $col2 NOT LIKE '/uploads/%'
    ");
    $stmt2->execute();
    $rows2 = $stmt2->fetchAll();

    $count2 = count($rows2);
    echo "$table2.$col2: $count2 row(s) to fix\n";

    if ($count2 > 0) {
        $update2 = $conn->prepare("UPDATE $table2 SET $col2 = ? WHERE id = ?");
        foreach ($rows2 as $row) {
            $fixedPath = '/uploads/' . ltrim($row[$col2], '/');
            $update2->execute([$fixedPath, $row['id']]);
            echo "  - Item #{$row['id']}: {$row[$col2]} -> $fixedPath\n";
            $totalFixed++;
        }
    }
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "Total paths fixed: $totalFixed\n";
echo "Migration complete!\n";
if (!$isCli) echo "</pre>";
