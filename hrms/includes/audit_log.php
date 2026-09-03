<?php
/**
 * RCS HRMS Pro — Centralised Audit Logging
 *
 * Provides audit_log() which inserts into the audit_log table.
 * The audit_log table is expected to have at minimum:
 *   id, user_id, action, module, record_id, new_values, ip_address, created_at
 *
 * Usage (php_payroll only — ESS has its own audit needs):
 *   require_once __DIR__ . '/audit_log.php';
 *   audit_log('create', 'employee', $newId, 'Created employee: ' . $name);
 */

if (!function_exists('audit_log')) {
    /**
     * Write an audit trail entry.
     *
     * @param string      $action    E.g. 'create', 'update', 'delete', 'login', 'logout', 'approve'
     * @param string      $module    E.g. 'employee', 'payroll', 'salary_structure'
     * @param int|string  $recordId  The primary key of the affected record (or null)
     * @param string|null $details   Human-readable description or JSON string
     */
    function audit_log(string $action, string $module, $recordId = null, ?string $details = null): void
    {
        global $db;
        if (!isset($db)) {
            return;
        }

        $userId = $_SESSION['user_id'] ?? null;
        $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $stmt = $db->prepare(
                "INSERT INTO audit_log (user_id, action, module, record_id, new_values, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $userId,
                $action,
                $module,
                $recordId,
                $details,
                $ip,
            ]);
        } catch (\Throwable $e) {
            // Audit-log failure must NEVER block the primary operation
            error_log("[audit_log] Failed: " . $e->getMessage());
        }
    }
}