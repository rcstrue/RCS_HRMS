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

// Add month/year columns to manager_advance_allocations (for month-wise tracking)
try { $db->query("ALTER TABLE `manager_advance_allocations` ADD COLUMN `month` int(2) DEFAULT NULL AFTER `amount`"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE `manager_advance_allocations` ADD COLUMN `year` int(4) DEFAULT NULL AFTER `month`"); } catch (Exception $e) {}

// Add alloc_date column (custom date when advance was actually given)
try { $db->query("ALTER TABLE `manager_advance_allocations` ADD COLUMN `alloc_date` date DEFAULT NULL AFTER `year`"); } catch (Exception $e) {}

// Add carry-forward columns to manager_advance_allocations
try { $db->query("ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `year`"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_from_month` int(2) DEFAULT NULL AFTER `carry_forward_amount`"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_from_year` int(4) DEFAULT NULL AFTER `carry_forward_from_month`"); } catch (Exception $e) {}

// ============================================================================
// Auto-alter missing columns on ess_expenses
// Just try ALTER — if column exists, catch the duplicate error.
// ============================================================================

$expenseColFlags = [];

// category
try {
    $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `category` enum('advance','expense','employee_advance') NOT NULL DEFAULT 'expense' AFTER `employee_id`");
    $expenseColFlags['category'] = true;
} catch (Exception $e) {
    // Column exists or error — check via SELECT
    $expenseColFlags['category'] = false;
    try { $db->fetchColumn("SELECT `category` FROM `ess_expenses` LIMIT 1"); $expenseColFlags['category'] = true; } catch (Exception $e2) {}
}

// manager_id
try {
    $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `manager_id` varchar(50) DEFAULT NULL AFTER `category`");
    $expenseColFlags['manager_id'] = true;
} catch (Exception $e) {
    $expenseColFlags['manager_id'] = false;
    try { $db->fetchColumn("SELECT `manager_id` FROM `ess_expenses` LIMIT 1"); $expenseColFlags['manager_id'] = true; } catch (Exception $e2) {}
}

// emp_name
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `emp_name` varchar(255) DEFAULT NULL"); } catch (Exception $e) {}

// emp_code
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `emp_code` varchar(50) DEFAULT NULL"); } catch (Exception $e) {}

// unit_id
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `unit_id` int(11) DEFAULT NULL"); } catch (Exception $e) {}

// month
try {
    $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `month` int(2) DEFAULT NULL");
    $expenseColFlags['month'] = true;
} catch (Exception $e) {
    $expenseColFlags['month'] = false;
    try { $db->fetchColumn("SELECT `month` FROM `ess_expenses` LIMIT 1"); $expenseColFlags['month'] = true; } catch (Exception $e2) {}
}

// year
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `year` int(4) DEFAULT NULL"); } catch (Exception $e) {}

// bill_type
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `bill_type` varchar(20) DEFAULT NULL"); } catch (Exception $e) {}

// rejected_by
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `rejected_by` varchar(50) DEFAULT NULL"); } catch (Exception $e) {}

// edited_by
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `edited_by` varchar(50) DEFAULT NULL"); } catch (Exception $e) {}

// edited_at
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `edited_at` datetime DEFAULT NULL"); } catch (Exception $e) {}

// settlement_id
try { $db->query("ALTER TABLE `ess_expenses` ADD COLUMN `settlement_id` int(11) DEFAULT NULL"); } catch (Exception $e) {}

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
