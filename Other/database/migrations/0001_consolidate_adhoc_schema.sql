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
    `total_wo` decimal(5,2) DEFAULT 0.00,
    `total_paid_days` decimal(5,2) DEFAULT 0.00,
    `source` enum('Manual','Excel Upload') DEFAULT 'Manual',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_emp_unit_month_year` (`employee_id`, `unit_id`, `month`, `year`),
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
ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `alloc_date`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_from_month` int(2) DEFAULT NULL AFTER `carry_forward_amount`;
ALTER TABLE `manager_advance_allocations` ADD COLUMN `carry_forward_from_year` int(4) DEFAULT NULL AFTER `carry_forward_from_month`;

-- NOTE: ess_expenses column additions already exist at the top of this file (lines 10-21).
-- The duplicate ALTER TABLE block has been removed to prevent "Duplicate column" errors.

-- ---------------------------------------------------------------------------
-- from modules/payroll/process.php
-- ---------------------------------------------------------------------------
ALTER TABLE payroll ADD COLUMN loan_emi DECIMAL(10,2) DEFAULT 0.00 AFTER salary_advance;
ALTER TABLE payroll ADD COLUMN month INT NOT NULL DEFAULT 0;
ALTER TABLE payroll ADD COLUMN year INT NOT NULL DEFAULT 0;
ALTER TABLE payroll ADD INDEX idx_month_year (month, year);

CREATE TABLE IF NOT EXISTS `employee_loans` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `employee_id` int(11) NOT NULL,
    `unit_id` int(11) DEFAULT NULL,
    `loan_type` varchar(50) DEFAULT 'Personal',
    `amount` decimal(12,2) NOT NULL,
    `interest_rate` decimal(5,2) DEFAULT 0.00,
    `tenure_months` int(11) NOT NULL,
    `emi_amount` decimal(12,2) NOT NULL,
    `total_interest` decimal(12,2) DEFAULT 0.00,
    `total_repayable` decimal(12,2) NOT NULL,
    `balance_amount` decimal(12,2) NOT NULL,
    `emi_deducted` int(11) DEFAULT 0,
    `start_month` int(2) NOT NULL,
    `start_year` int(4) NOT NULL,
    `status` enum('Active','Closed','Settled','Written Off') DEFAULT 'Active',
    `remarks` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_employee` (`employee_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `loan_emi_log` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `loan_id` int(11) NOT NULL,
    `employee_id` int(11) NOT NULL,
    `month` int(2) NOT NULL,
    `year` int(4) NOT NULL,
    `emi_amount` decimal(12,2) NOT NULL,
    `principal_component` decimal(12,2) DEFAULT 0.00,
    `interest_component` decimal(12,2) DEFAULT 0.00,
    `balance_after` decimal(12,2) NOT NULL,
    `deducted_via_payroll` tinyint(1) DEFAULT 1,
    `payroll_id` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_loan_month_year` (`loan_id`, `month`, `year`),
    KEY `idx_employee_month` (`employee_id`, `month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;