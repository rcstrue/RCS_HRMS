-- =========================================================================
-- Consolidated ad-hoc schema changes
-- Extracted from runtime PHP files — run ONCE against the live DB,
-- then remove the inline DDL from the source files in a follow-up commit.
-- =========================================================================

-- ---------------------------------------------------------------------------
-- from modules/api/expense-api.php
-- ---------------------------------------------------------------------------
ALTER TABLE ess_expenses ADD COLUMN category ENUM('advance','expense','employee_advance') NOT NULL DEFAULT 'expense' AFTER employee_id;
ALTER TABLE ess_expenses ADD COLUMN manager_id VARCHAR(50) DEFAULT NULL AFTER category;
ALTER TABLE ess_expenses ADD COLUMN emp_name VARCHAR(255) DEFAULT NULL AFTER manager_id;
ALTER TABLE ess_expenses ADD COLUMN emp_code VARCHAR(50) DEFAULT NULL AFTER emp_name;
ALTER TABLE ess_expenses ADD COLUMN unit_id INT DEFAULT NULL AFTER emp_code;
ALTER TABLE ess_expenses ADD COLUMN month INT DEFAULT NULL AFTER unit_id;
ALTER TABLE ess_expenses ADD COLUMN year INT DEFAULT NULL AFTER month;
ALTER TABLE ess_expenses ADD COLUMN bill_type ENUM('image','pdf') DEFAULT NULL AFTER bill_url;
ALTER TABLE ess_expenses ADD COLUMN rejected_by VARCHAR(50) DEFAULT NULL AFTER rejection_reason;
ALTER TABLE ess_expenses ADD COLUMN edited_by VARCHAR(50) DEFAULT NULL AFTER rejected_by;
ALTER TABLE ess_expenses ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER edited_by;
ALTER TABLE ess_expenses ADD COLUMN settlement_id INT DEFAULT NULL AFTER edited_at;

-- ---------------------------------------------------------------------------
-- from modules/attendance/upload.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_summary` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `employee_id` int(11) NOT NULL,
    `unit_id` int(11) DEFAULT NULL,
    `month` int(2) NOT NULL,
    `year` int(4) NOT NULL,
    `total_present` decimal(5,2) DEFAULT 0.00,
    `total_extra` decimal(5,2) DEFAULT 0.00,
    `overtime_hours` decimal(6,2) DEFAULT 0.00,
    `total_wo` int(3) DEFAULT 0,
    `source` enum('Manual','Excel Upload') DEFAULT 'Manual',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_emp_month_year` (`employee_id`, `month`, `year`),
    KEY `idx_unit_month_year` (`unit_id`, `month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_advances` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `employee_id` int(11) NOT NULL,
    `unit_id` int(11) DEFAULT NULL,
    `month` int(2) NOT NULL,
    `year` int(4) NOT NULL,
    `adv1` decimal(10,2) DEFAULT 0.00,
    `adv2` decimal(10,2) DEFAULT 0.00,
    `office_advance` decimal(10,2) DEFAULT 0.00,
    `dress_advance` decimal(10,2) DEFAULT 0.00,
    `remarks` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_emp_month_year` (`employee_id`, `month`, `year`),
    KEY `idx_unit_month_year` (`unit_id`, `month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/notifications/announcements.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ess_announcements` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(255) NOT NULL,
    `content` text NOT NULL,
    `created_by` varchar(50) NOT NULL,
    `target_scope` enum('all','managers','admin') NOT NULL DEFAULT 'all',
    `target_id` varchar(50) DEFAULT NULL,
    `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ess_announcements` MODIFY COLUMN `target_scope` enum('all','managers','admin') NOT NULL DEFAULT 'all';

CREATE TABLE IF NOT EXISTS `ess_announcement_reads` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `announcement_id` int(11) NOT NULL,
    `user_id` varchar(50) NOT NULL,
    `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_announcement_user` (`announcement_id`, `user_id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/expense/expense-setup.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `manager_advance_allocations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `manager_id` varchar(50) NOT NULL,
    `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    `remarks` text DEFAULT NULL,
    `allocated_by` varchar(50) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_manager` (`manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_ledger` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expense_settlements` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `manager_advance_allocations` ADD COLUMN `month` int(2) DEFAULT NULL AFTER `amount`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `year` int(4) DEFAULT NULL AFTER `month`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `alloc_date` date DEFAULT NULL AFTER `year`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `year`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_from_month` int(2) DEFAULT NULL AFTER `carry_forward_amount`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_from_year` int(4) DEFAULT NULL AFTER `carry_forward_from_month`;

ALTER TABLE `ess_expenses` ADD COLUMN `category` enum('advance','expense','employee_advance') NOT NULL DEFAULT 'expense' AFTER `employee_id`;
ALTER TABLE `ess_expenses` ADD COLUMN `manager_id` varchar(50) DEFAULT NULL AFTER `category`;
ALTER TABLE `ess_expenses` ADD COLUMN `emp_name` varchar(255) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `emp_code` varchar(50) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `unit_id` int(11) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `month` int(2) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `year` int(4) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `bill_type` varchar(20) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `rejected_by` varchar(50) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `edited_by` varchar(50) DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `edited_at` datetime DEFAULT NULL;
ALTER TABLE `ess_expenses` ADD COLUMN `settlement_id` int(11) DEFAULT NULL;