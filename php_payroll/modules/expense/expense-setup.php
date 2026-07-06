<?php
/**
 * Shared Auto-Migration & Helpers for Expense Module
 * Include this at the top of every expense page (after $db guard).
 */

// ============================================================================
// Auto-create tables
// ============================================================================

try {
    $db->query("CREATE TABLE IF NOT EXISTS `manager_advance_allocations` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `manager_id` varchar(50) NOT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `remarks` text DEFAULT NULL,
        `allocated_by` varchar(50) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_manager` (`manager_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

try {
    $db->query("CREATE TABLE IF NOT EXISTS `manager_ledger` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `manager_id` varchar(50) NOT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
        `total_advance_given` decimal(12,2) NOT NULL DEFAULT 0.00,
        `total_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
        `total_employee_advances` decimal(12,2) NOT NULL DEFAULT 0.00,
        `closing_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
        `carried_forward` tinyint(1) NOT NULL DEFAULT 0,
        `settled_by` varchar(50) DEFAULT NULL,
        `settled_at` datetime DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_manager_month_year` (`manager_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

try {
    $db->query("CREATE TABLE IF NOT EXISTS `expense_settlements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `manager_id` varchar(50) NOT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `total_advance` decimal(12,2) NOT NULL DEFAULT 0.00,
        `total_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
        `total_emp_advances` decimal(12,2) NOT NULL DEFAULT 0.00,
        `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
        `status` enum('open','settled','carry_forward') NOT NULL DEFAULT 'open',
        `settlement_remarks` text DEFAULT NULL,
        `settled_by` varchar(50) DEFAULT NULL,
        `settled_at` datetime DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_manager_month_year` (`manager_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// ============================================================================
// Helper: safely add column only if it doesn't exist (no noisy errors)
// ============================================================================

function _ensureColumn($db, $table, $colName, $alterSql) {
    try {
        $row = $db->fetch("SHOW COLUMNS FROM `{$table}` LIKE '{$colName}'");
        if (!$row) {
            $db->query("ALTER TABLE `{$table}` {$alterSql}");
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Add month/year/alloc_date/carry-forward columns to manager_advance_allocations
_ensureColumn($db, 'manager_advance_allocations', 'month',                   "ADD COLUMN `month` int(2) DEFAULT NULL AFTER `amount`");
_ensureColumn($db, 'manager_advance_allocations', 'year',                    "ADD COLUMN `year` int(4) DEFAULT NULL AFTER `month`");
_ensureColumn($db, 'manager_advance_allocations', 'alloc_date',              "ADD COLUMN `alloc_date` date DEFAULT NULL AFTER `year`");
_ensureColumn($db, 'manager_advance_allocations', 'carry_forward_amount',    "ADD COLUMN `carry_forward_amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `year`");
_ensureColumn($db, 'manager_advance_allocations', 'carry_forward_from_month',"ADD COLUMN `carry_forward_from_month` int(2) DEFAULT NULL AFTER `carry_forward_amount`");
_ensureColumn($db, 'manager_advance_allocations', 'carry_forward_from_year', "ADD COLUMN `carry_forward_from_year` int(4) DEFAULT NULL AFTER `carry_forward_from_month`");

// ============================================================================
// Auto-alter missing columns on ess_expenses (check before ALTER)
// ============================================================================

$expenseColFlags = [];

_ensureColumn($db, 'ess_expenses', 'category',
    "ADD COLUMN `category` enum('advance','expense','employee_advance') NOT NULL DEFAULT 'expense' AFTER `employee_id`");
_ensureColumn($db, 'ess_expenses', 'manager_id',
    "ADD COLUMN `manager_id` varchar(50) DEFAULT NULL AFTER `category`");
_ensureColumn($db, 'ess_expenses', 'emp_name',
    "ADD COLUMN `emp_name` varchar(255) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'emp_code',
    "ADD COLUMN `emp_code` varchar(50) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'unit_id',
    "ADD COLUMN `unit_id` int(11) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'month',
    "ADD COLUMN `month` int(2) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'year',
    "ADD COLUMN `year` int(4) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'bill_type',
    "ADD COLUMN `bill_type` varchar(20) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'rejected_by',
    "ADD COLUMN `rejected_by` varchar(50) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'edited_by',
    "ADD COLUMN `edited_by` varchar(50) DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'edited_at',
    "ADD COLUMN `edited_at` datetime DEFAULT NULL");
_ensureColumn($db, 'ess_expenses', 'settlement_id',
    "ADD COLUMN `settlement_id` int(11) DEFAULT NULL");

// Check which key columns exist
$expenseColFlags['category']   = (bool)$db->fetch("SHOW COLUMNS FROM `ess_expenses` LIKE 'category'");
$expenseColFlags['manager_id'] = (bool)$db->fetch("SHOW COLUMNS FROM `ess_expenses` LIKE 'manager_id'");
$expenseColFlags['month']      = (bool)$db->fetch("SHOW COLUMNS FROM `ess_expenses` LIKE 'month'");

// Shortcuts used by multiple pages
$categoryColExists   = $expenseColFlags['category'] ?? false;
$managerIdColExists  = $expenseColFlags['manager_id'] ?? false;
$monthColExists      = $expenseColFlags['month'] ?? false;

// ============================================================================
// Shared helper: formatCurrency
// ============================================================================

if (!function_exists('formatCurrency')) {
    function formatCurrency($amt) {
        return '&#8377;' . number_format((float)$amt, 2);
    }
}

// Shared helper: build scope WHERE clause for announcements
// Returns array: ["(a.target_scope = 'all' OR ...)", [':scope_role1' => ...]]
if (!function_exists('annScopeWhere')) {
    function annScopeWhere($role, $uid) {
        $isAdmin = ($role === 'admin');
        $isManager = in_array($role, ['manager', 'regional_manager']);
        if ($isAdmin) {
            // Admin sees everything
            return ['', []];
        } elseif ($isManager) {
            // Manager sees: all + managers + own (created by self)
            return ["AND (a.target_scope = 'all' OR a.target_scope = 'managers' OR a.created_by = :selfid)", [':selfid' => $uid]];
        } else {
            // Others see: all + own (created by self)
            return ["AND (a.target_scope = 'all' OR a.created_by = :selfid)", [':selfid' => $uid]];
        }
    }
}
