-- ============================================================
-- Migration: Advance & Expense Management (Petty Cash / Imprest)
-- ============================================================
-- 
-- Alters existing ess_expenses table to support the full module.
-- Creates new tables for advance allocation, ledger, and settlements.
-- 
-- RUN THIS SQL IN phpMyAdmin BEFORE using the module.
-- ============================================================

-- 1. ALTER existing ess_expenses table
ALTER TABLE `ess_expenses` 
  ADD COLUMN `category` enum('advance','expense','employee_advance') NOT NULL DEFAULT 'expense' AFTER `employee_id`,
  ADD COLUMN `manager_id` varchar(50) DEFAULT NULL COMMENT 'Manager who gave employee advance' AFTER `employee_id`,
  ADD COLUMN `emp_name` varchar(255) DEFAULT NULL COMMENT 'Employee name for employee_advance type' AFTER `manager_id`,
  ADD COLUMN `emp_code` varchar(50) DEFAULT NULL COMMENT 'Employee code for employee_advance type' AFTER `emp_name`,
  ADD COLUMN `unit_id` int(11) DEFAULT NULL COMMENT 'Unit reference' AFTER `emp_code`,
  ADD COLUMN `month` int(2) DEFAULT NULL COMMENT 'Month for settlement tracking' AFTER `unit_id`,
  ADD COLUMN `year` int(4) DEFAULT NULL COMMENT 'Year for settlement tracking' AFTER `month`,
  ADD COLUMN `bill_type` enum('image','pdf') DEFAULT NULL COMMENT 'Bill upload type' AFTER `bill_url`,
  ADD COLUMN `rejected_by` varchar(50) DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN `edited_by` varchar(50) DEFAULT NULL COMMENT 'Admin who edited the entry' AFTER `rejected_by`,
  ADD COLUMN `edited_at` datetime DEFAULT NULL COMMENT 'When admin edited' AFTER `edited_by`,
  ADD COLUMN `settlement_id` int(11) DEFAULT NULL COMMENT 'Reference to monthly settlement' AFTER `edited_at`,
  ADD INDEX `idx_manager` (`manager_id`),
  ADD INDEX `idx_category` (`category`),
  ADD INDEX `idx_status` (`status`),
  ADD INDEX `idx_month_year` (`month`, `year`);

-- 2. Manager advance allocation table (admin gives float to managers)
CREATE TABLE IF NOT EXISTS `manager_advance_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `manager_id` varchar(50) NOT NULL COMMENT 'employee_id from ess_employee_cache / employees table',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Advance amount allocated',
  `remarks` text DEFAULT NULL,
  `allocated_by` varchar(50) DEFAULT NULL COMMENT 'Admin who allocated',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_manager` (`manager_id`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Manager ledger table (running balance)
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
  UNIQUE KEY `uniq_manager_month_year` (`manager_id`, `month`, `year`),
  KEY `idx_manager` (`manager_id`),
  KEY `idx_month_year` (`month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Monthly settlement table
CREATE TABLE IF NOT EXISTS `expense_settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `manager_id` varchar(50) NOT NULL,
  `month` int(2) NOT NULL,
  `year` int(4) NOT NULL,
  `total_advance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_emp_advances` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Positive = surplus, Negative = payable',
  `status` enum('open','settled','carry_forward') NOT NULL DEFAULT 'open',
  `settlement_remarks` text DEFAULT NULL,
  `settled_by` varchar(50) DEFAULT NULL,
  `settled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_month_year` (`manager_id`, `month`, `year`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
