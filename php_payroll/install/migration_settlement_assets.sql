-- ============================================================
-- RCS HRMS Pro - Settlement & Assets Module Migration
-- Run this SQL in phpMyAdmin to create missing tables
-- ============================================================

-- 1. Employee Full & Final Settlement Table
CREATE TABLE IF NOT EXISTS `employee_settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `last_working_day` date DEFAULT NULL,
  `leaving_reason` varchar(200) DEFAULT NULL,
  `service_years` decimal(6,2) DEFAULT 0.00,
  `salary_days` int(11) DEFAULT 0,
  `salary_amount` decimal(12,2) DEFAULT 0.00,
  `leave_encashment_days` decimal(6,2) DEFAULT 0.00,
  `leave_encashment_amount` decimal(12,2) DEFAULT 0.00,
  `gratuity_years` int(11) DEFAULT 0,
  `gratuity_amount` decimal(12,2) DEFAULT 0.00,
  `bonus_amount` decimal(12,2) DEFAULT 0.00,
  `notice_shortfall` int(11) DEFAULT 0,
  `notice_recovery` decimal(12,2) DEFAULT 0.00,
  `advance_recovery` decimal(12,2) DEFAULT 0.00,
  `total_earnings` decimal(12,2) DEFAULT 0.00,
  `total_deductions` decimal(12,2) DEFAULT 0.00,
  `net_payable` decimal(12,2) DEFAULT 0.00,
  `status` enum('pending','approved','paid','on_hold') DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_mode` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Assets Master Table
CREATE TABLE IF NOT EXISTS `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `asset_type` enum('equipment','uniform','tools','vehicle','electronic','furniture','safety','other') DEFAULT 'other',
  `description` text DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `available_quantity` int(11) NOT NULL DEFAULT 1,
  `is_returnable` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_asset_code` (`asset_code`),
  KEY `idx_asset_type` (`asset_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Employee Asset Issuance Table
CREATE TABLE IF NOT EXISTS `employee_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `issue_date` date NOT NULL,
  `expected_return_date` date DEFAULT NULL,
  `issue_condition` enum('new','good','worn','damaged') DEFAULT 'new',
  `issue_remarks` text DEFAULT NULL,
  `status` enum('issued','returned','damaged','lost') DEFAULT 'issued',
  `issued_by` int(11) DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `return_condition` enum('new','good','worn','damaged') DEFAULT NULL,
  `return_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_asset_id` (`asset_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Leave Types Table (needed by settlement module for leave encashment)
CREATE TABLE IF NOT EXISTS `leave_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_code` varchar(20) NOT NULL,
  `leave_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT 1,
  `is_encashable` tinyint(1) DEFAULT 0,
  `max_per_year` decimal(6,2) DEFAULT 0.00,
  `carry_forward` tinyint(1) DEFAULT 0,
  `max_carry_forward` decimal(6,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_leave_code` (`leave_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default leave types if empty
INSERT IGNORE INTO `leave_types` (`leave_code`, `leave_name`, `is_paid`, `is_encashable`, `max_per_year`, `carry_forward`, `max_carry_forward`) VALUES
('EL', 'Earned Leave', 1, 1, 15.00, 1, 30.00),
('CL', 'Casual Leave', 1, 0, 12.00, 0, 0.00),
('SL', 'Sick Leave', 1, 0, 12.00, 0, 0.00),
('PL', 'Privilege Leave', 1, 1, 15.00, 1, 30.00),
('ML', 'Maternity Leave', 1, 0, 180.00, 0, 0.00),
('PTL', 'Paternity Leave', 1, 0, 15.00, 0, 0.00),
('CO', 'Compensatory Off', 1, 0, 0.00, 0, 0.00);

-- 5. Employee Leave Balance Table (needed by settlement module)
CREATE TABLE IF NOT EXISTS `employee_leave_balance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` int(4) NOT NULL,
  `opening_balance` decimal(6,2) DEFAULT 0.00,
  `earned` decimal(6,2) DEFAULT 0.00,
  `used` decimal(6,2) DEFAULT 0.00,
  `balance` decimal(6,2) DEFAULT 0.00,
  `encashed` decimal(6,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_type_year` (`employee_id`, `leave_type_id`, `year`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_year` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
