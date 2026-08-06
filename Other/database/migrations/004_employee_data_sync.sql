-- employee_data_sync_logs table
CREATE TABLE IF NOT EXISTS `employee_data_sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `source_table` VARCHAR(50) NOT NULL COMMENT 'epfo_members or esic_ip_master',
    `source_record_id` VARCHAR(50) DEFAULT NULL,
    `updated_by` VARCHAR(50) NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `remarks` VARCHAR(500) DEFAULT NULL,
    INDEX `idx_employee_id` (`employee_id`),
    INDEX `idx_updated_at` (`updated_at`),
    INDEX `idx_source_table` (`source_table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- employee_data_sync_ignore table
CREATE TABLE IF NOT EXISTS `employee_data_sync_ignore` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `source` VARCHAR(50) NOT NULL COMMENT 'epfo or esic',
    `source_id` VARCHAR(50) DEFAULT NULL,
    `reason` VARCHAR(500) DEFAULT NULL,
    `ignored_by` VARCHAR(50) NOT NULL,
    `ignored_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_emp_source` (`employee_id`, `source`),
    INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;