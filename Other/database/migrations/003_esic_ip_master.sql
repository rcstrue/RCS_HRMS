-- ============================================
-- ESIC IP Master Import Module
-- Migration: 003_esic_ip_master.sql
-- ============================================

-- 1. ESIC IP Master table (single master database of insured persons)
CREATE TABLE IF NOT EXISTS `esic_ip_master` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_number` VARCHAR(50) NOT NULL COMMENT 'ESIC IP Number — unique identifier',
    `ip_name` VARCHAR(255) DEFAULT NULL,
    `employer_code` VARCHAR(50) DEFAULT NULL,
    `employer_name` VARCHAR(255) DEFAULT NULL,
    `mobile` VARCHAR(20) DEFAULT NULL,
    `uan` VARCHAR(20) DEFAULT NULL,
    `account_number` VARCHAR(50) DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `branch_name` VARCHAR(255) DEFAULT NULL,
    `ifsc_code` VARCHAR(20) DEFAULT NULL,
    `bank_account_status` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ip_number` (`ip_number`),
    INDEX `idx_uan` (`uan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='ESIC Insured Person master data — merged from multiple CSV imports';

-- 2. Import error log (per-row errors)
CREATE TABLE IF NOT EXISTS `esic_import_errors` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `import_id` INT UNSIGNED NOT NULL COMMENT 'References esic_import_history.id',
    `file_name` VARCHAR(255) NOT NULL,
    `row_number` INT UNSIGNED DEFAULT NULL,
    `ip_number` VARCHAR(50) DEFAULT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_import_id` (`import_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Row-level errors from ESIC CSV imports';

-- 3. Import history (per-upload-session audit)
CREATE TABLE IF NOT EXISTS `esic_import_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL,
    `user_name` VARCHAR(255) DEFAULT NULL,
    `files_uploaded` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_read` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_updated` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Audit log for ESIC IP import sessions';