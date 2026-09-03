<?php
/**
 * Migration Runner: Add worker_category to designations table
 *
 * Usage: visit  index.php?page=employee/run-designation-migration
 *        (admin only). Idempotent — safe to run multiple times.
 */
if (!defined('APP_RUNNING')) {
    // allow direct access for CLI/standalone
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../includes/class.database.php';
    $db = new Database();
}

header('Content-Type: text/plain');

echo "=== Designations: add worker_category column ===\n\n";

$steps = [];

// 1. Check if column already exists
$col = $db->fetch("SHOW COLUMNS FROM designations LIKE 'worker_category'");
if ($col) {
    $steps[] = "[SKIP] Column 'worker_category' already exists.";
} else {
    $db->query("ALTER TABLE designations
                ADD COLUMN worker_category VARCHAR(50) NOT NULL DEFAULT 'Unskilled' AFTER name");
    $steps[] = "[OK]   Added column 'worker_category' (default 'Unskilled').";
}

// 2. Backfill any NULL / empty values
$cnt = $db->fetch("SELECT COUNT(*) c FROM designations
                       WHERE worker_category IS NULL OR worker_category = ''");
if ($cnt && $cnt['c'] > 0) {
    $db->query("UPDATE designations SET worker_category = 'Unskilled'
                 WHERE worker_category IS NULL OR worker_category = ''");
    $steps[] = "[OK]   Backfilled {$cnt['c']} row(s) with default 'Unskilled'.";
} else {
    $steps[] = "[SKIP] No NULL/empty worker_category rows.";
}

// 3. Index (best-effort)
try {
    $db->query("ALTER TABLE designations ADD INDEX idx_worker_category (worker_category)");
    $steps[] = "[OK]   Added index idx_worker_category.";
} catch (Exception $e) {
    $steps[] = "[SKIP] Index already exists or could not be added: " . $e->getMessage();
}

foreach ($steps as $s) {
    echo $s . "\n";
}

echo "\n--- Verification (first 20 rows) ---\n";
$rows = $db->fetchAll("SELECT id, name, worker_category, desi_view
                         FROM designations ORDER BY id LIMIT 20");
if ($rows) {
    foreach ($rows as $r) {
        echo sprintf("  #%d  %-30s  cat=%-15s  view=%d\n",
            $r['id'], $r['name'], $r['worker_category'], $r['desi_view']);
    }
} else {
    echo "  (no rows)\n";
}

echo "\nDone.\n";
exit;
