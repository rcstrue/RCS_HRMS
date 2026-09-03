-- ════════════════════════════════════════════════════════════════════
-- Unit Salary Templates — Migration SQL
-- Run this in phpMyAdmin once before deploying the feature code.
-- ════════════════════════════════════════════════════════════════════

-- 1. New table: unit_salary_templates (separate from unit_salary_formulas
--    to avoid breaking the payroll JOIN which expects one row per unit)
CREATE TABLE IF NOT EXISTS `unit_salary_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `template_name` VARCHAR(100) NOT NULL,
    `worker_categories` VARCHAR(500) DEFAULT NULL,
    -- Comma-separated: 'Unskilled,Semi-skilled'. NULL = catch-all default.
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    -- ── Salary components (calculated from Net reverse calc) ──
    `net_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `basic_da` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `hra` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `leave_encashment` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `bonus_encashment` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `washing_allowance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `gross_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- ── Statutory applicability flags ──
    `pf_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `esi_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `pt_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `lwf_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `overtime_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `bonus_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `gratuity_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    -- ── Reverse calc parameters ──
    `bonus_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `leave_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    -- ── Meta ──
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_unit_active` (`unit_id`, `is_active`),
    INDEX `idx_unit_default` (`unit_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add template reference to employee_salary_structures
--    (Safe: ADD COLUMN IF NOT EXISTS pattern via information_schema)
SET @dbname = DATABASE();
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'employee_salary_structures'
    AND COLUMN_NAME = 'template_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `employee_salary_structures`
     ADD COLUMN `template_id` INT UNSIGNED NULL AFTER `employee_id`,
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'employee_salary_structures'
    AND COLUMN_NAME = 'applied_month'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `employee_salary_structures`
     ADD COLUMN `applied_month` DATE NULL AFTER `template_id`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'employee_salary_structures'
    AND INDEX_NAME = 'idx_template'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `employee_salary_structures` ADD INDEX `idx_template` (`template_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
