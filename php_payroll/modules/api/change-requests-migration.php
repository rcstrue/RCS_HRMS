<?php
/**
 * RCS HRMS Pro - Migration: employee_change_requests table
 * Standalone script — run directly or include from setup.
 *
 * Usage (CLI):  php modules/api/change-requests-migration.php
 * Usage (Web):  Only via admin panel or authorised bootstrap.
 */

// ── Bootstrap (self-contained) ─────────────────────────────────────────────

define('RCS_HRMS', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';

// Prevent browser execution unless called from an authorised context
// (CLI is always allowed for migrations / cron)
if (php_sapi_name() !== 'cli') {
    // Allow if already inside the HRMS session (admin)
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. This script can only be run via CLI or by an authenticated admin.']);
        exit;
    }
}

header('Content-Type: application/json');

try {
    $db = Database::getInstance();

    $sql = "CREATE TABLE IF NOT EXISTS employee_change_requests (
      id INT AUTO_INCREMENT PRIMARY KEY,
      employee_id INT NOT NULL,
      field_name VARCHAR(100) NOT NULL,
      old_value TEXT,
      new_value TEXT NOT NULL,
      reason TEXT,
      status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      reviewed_at DATETIME NULL,
      reviewed_by INT NULL,
      rejection_reason TEXT NULL,
      INDEX idx_employee_id (employee_id),
      INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->exec($sql);

    echo json_encode([
        'success' => true,
        'message' => 'employee_change_requests table created (or already exists).',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'  => 'Migration failed: ' . $e->getMessage(),
    ]);
}
