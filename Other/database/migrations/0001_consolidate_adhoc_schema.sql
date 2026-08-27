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

-- ---------------------------------------------------------------------------
-- from modules/notifications/center.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` VARCHAR(20) NOT NULL,
    `endpoint` VARCHAR(500) NOT NULL,
    `p256dh_key` VARCHAR(200) NOT NULL,
    `auth_key` VARCHAR(200) NOT NULL,
    `user_agent` VARCHAR(500) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_endpoint` (`endpoint`(255)),
    INDEX `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `push_notification_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `url` VARCHAR(500) DEFAULT '/',
    `icon` VARCHAR(500) DEFAULT '/logo.png',
    `target` VARCHAR(50) DEFAULT 'all',
    `employee_ids` TEXT DEFAULT NULL,
    `status` ENUM('pending','sending','completed','failed') DEFAULT 'pending',
    `sent_count` INT UNSIGNED DEFAULT 0,
    `failed_count` INT UNSIGNED DEFAULT 0,
    `expired_count` INT UNSIGNED DEFAULT 0,
    `errors` TEXT DEFAULT NULL,
    `scheduled_at` DATETIME DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `created_by` VARCHAR(50) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `attempt_count` INT UNSIGNED DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED DEFAULT 5,
    `next_retry_at` DATETIME DEFAULT NULL,
    `last_error` TEXT DEFAULT NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduled_at`, `status`),
    INDEX `idx_retry` (`next_retry_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ---------------------------------------------------------------------------
-- from modules/employee/data-sync.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_data_sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED DEFAULT NULL,
    `target_table` VARCHAR(50) DEFAULT NULL,
    `target_record_id` VARCHAR(50) DEFAULT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `source_table` VARCHAR(50) NOT NULL,
    `source_record_id` VARCHAR(50) DEFAULT NULL,
    `updated_by` VARCHAR(50) NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `remarks` VARCHAR(500) DEFAULT NULL,
    INDEX `idx_employee_id` (`employee_id`),
    INDEX `idx_target` (`target_table`, `target_record_id`),
    INDEX `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/employee/esic-import.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `esic_ip_master` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_number` VARCHAR(50) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esic_import_errors` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `import_id` INT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `row_number` INT UNSIGNED DEFAULT NULL,
    `ip_number` VARCHAR(50) DEFAULT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_import_id` (`import_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/leave/apply.php (also used by modules/entry/leave-entry.php)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leave_applications` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT(10) UNSIGNED NOT NULL,
    leave_type ENUM('CL','PL','SL','EL','CO','ML','LWP') NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    total_days DECIMAL(5,1) DEFAULT 0.5,
    reason TEXT,
    status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_employee (employee_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `leave_balances` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT(10) UNSIGNED NOT NULL,
    leave_type ENUM('CL','PL','SL','EL','CO','ML') NOT NULL,
    year INT NOT NULL,
    opening_balance DECIMAL(5,2) DEFAULT 0,
    accrued DECIMAL(5,2) DEFAULT 0,
    used DECIMAL(5,2) DEFAULT 0,
    closing_balance DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_emp_leave_year (employee_id, leave_type, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/assets/add.php
-- ---------------------------------------------------------------------------
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
    `return_date` date DEFAULT NULL,
    `return_condition` enum('new','good','worn','damaged') DEFAULT NULL,
    `return_remarks` text DEFAULT NULL,
    `issued_by` int(11) DEFAULT NULL,
    `received_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_employee` (`employee_id`),
    KEY `idx_asset` (`asset_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/timesheet/create.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheets` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timesheet_code VARCHAR(50) UNIQUE,
    client_id INT,
    unit_id INT,
    month INT NOT NULL,
    year INT NOT NULL,
    start_date DATE,
    end_date DATE,
    total_employees INT DEFAULT 0,
    total_hours DECIMAL(10,2) DEFAULT 0,
    total_overtime_hours DECIMAL(10,2) DEFAULT 0,
    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
    remarks TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    approved_by INT,
    approved_at TIMESTAMP NULL,
    INDEX idx_client_month_year (client_id, month, year),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `timesheet_entries` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timesheet_id INT NOT NULL,
    employee_id INT NOT NULL,
    employee_code VARCHAR(50),
    date DATE NOT NULL,
    shift_start TIME,
    shift_end TIME,
    total_hours DECIMAL(5,2) DEFAULT 0,
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    is_present TINYINT(1) DEFAULT 1,
    remarks VARCHAR(255),
    INDEX idx_timesheet_employee (timesheet_id, employee_id),
    INDEX idx_date (date),
    FOREIGN KEY (timesheet_id) REFERENCES timesheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/timesheet/list.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_timesheets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT UNSIGNED DEFAULT NULL,
    `unit_id` INT UNSIGNED DEFAULT NULL,
    `invoice_id` INT UNSIGNED DEFAULT NULL,
    `period_from` DATE DEFAULT NULL,
    `period_to` DATE DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'draft',
    `total_manpower` DECIMAL(10,2) DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `remarks` TEXT DEFAULT NULL,
    `created_by` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_period` (`period_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/settlement/list.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_settlements` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT(10) UNSIGNED NOT NULL,
    last_working_day DATE NOT NULL,
    leaving_reason VARCHAR(50) NOT NULL DEFAULT 'Resignation',
    service_years DECIMAL(6,2) DEFAULT 0,
    salary_days INT DEFAULT 0,
    salary_amount DECIMAL(12,2) DEFAULT 0,
    leave_encashment_days DECIMAL(6,2) DEFAULT 0,
    leave_encashment_amount DECIMAL(12,2) DEFAULT 0,
    gratuity_years INT DEFAULT 0,
    gratuity_amount DECIMAL(12,2) DEFAULT 0,
    bonus_amount DECIMAL(12,2) DEFAULT 0,
    notice_shortfall INT DEFAULT 0,
    notice_recovery DECIMAL(12,2) DEFAULT 0,
    advance_recovery DECIMAL(12,2) DEFAULT 0,
    total_earnings DECIMAL(12,2) DEFAULT 0,
    total_deductions DECIMAL(12,2) DEFAULT 0,
    net_payable DECIMAL(12,2) DEFAULT 0,
    status ENUM('pending','approved','paid','on_hold','rejected') DEFAULT 'pending',
    payment_date DATE NULL,
    payment_mode VARCHAR(50) NULL,
    payment_reference VARCHAR(100) NULL,
    created_by INT NULL,
    approved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    INDEX idx_employee (employee_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/announcement/list.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    type ENUM('general','holiday','policy','event','urgent') DEFAULT 'general',
    start_date DATE,
    end_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/helpdesk/list.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ess_helpdesk_tickets` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `employee_id` int(11) DEFAULT NULL,
    `category` enum('hr','payroll','it','admin','other') NOT NULL DEFAULT 'hr',
    `subject` varchar(255) NOT NULL,
    `description` text DEFAULT NULL,
    `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `resolved_by` varchar(50) DEFAULT NULL,
    `resolution` text DEFAULT NULL,
    `created_by` varchar(50) DEFAULT NULL,
    `ticket_number` varchar(20) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_employee` (`employee_id`),
    KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ess_helpdesk_comments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` int(11) NOT NULL,
    `user_id` varchar(50) DEFAULT NULL,
    `comment` text NOT NULL,
    `is_internal` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/compliance/pt.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pt_challans` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state VARCHAR(100) NOT NULL,
    month INT NOT NULL,
    year INT NOT NULL,
    challan_number VARCHAR(100),
    challan_date DATE,
    amount DECIMAL(12,2),
    total_employees INT DEFAULT 0,
    remarks TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_state_month_year (state, month, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/settings/holidays.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `holidays` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(200) NOT NULL,
    holiday_type ENUM('national','state','company','optional') DEFAULT 'national',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/unit/salary-templates.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `unit_salary_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NOT NULL,
    `template_name` VARCHAR(100) NOT NULL,
    `worker_categories` VARCHAR(500) DEFAULT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `net_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `basic_da` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `hra` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `leave_encashment` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `bonus_encashment` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `washing_allowance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `gross_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `pf_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `esi_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `pt_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `lwf_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `overtime_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `bonus_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `gratuity_applicable` TINYINT(1) NOT NULL DEFAULT 1,
    `bonus_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `leave_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_unit_active` (`unit_id`, `is_active`),
    INDEX `idx_unit_default` (`unit_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- from modules/payroll/salary-revision.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salary_revision_uploads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uploaded_by` INT NOT NULL,
    `effective_from` DATE NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `uploaded_file` VARCHAR(500) DEFAULT NULL,
    `error_file` VARCHAR(500) DEFAULT NULL,
    `total_rows` INT NOT NULL DEFAULT 0,
    `succeeded` INT NOT NULL DEFAULT 0,
    `failed` INT NOT NULL DEFAULT 0,
    `status` ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_uploaded_by` (`uploaded_by`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/forms/labour/form-32.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `injury_register` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    injury_date DATE NOT NULL,
    employee_id INT,
    employee_code VARCHAR(100) DEFAULT '',
    employee_name VARCHAR(255) DEFAULT '',
    nature_of_injury VARCHAR(255) NOT NULL,
    cause_of_injury VARCHAR(255) DEFAULT '',
    body_part_affected VARCHAR(255) DEFAULT '',
    treatment_given VARCHAR(500) DEFAULT '',
    hospital_name VARCHAR(255) DEFAULT '',
    days_lost INT DEFAULT 0,
    compensation_amount DECIMAL(12,2) DEFAULT 0,
    accident_type VARCHAR(50) DEFAULT 'minor',
    status VARCHAR(50) DEFAULT 'open',
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/forms/labour/form-xxi.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deductions_register` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deduction_date DATE NOT NULL,
    employee_id INT,
    employee_code VARCHAR(100) DEFAULT '',
    employee_name VARCHAR(255) DEFAULT '',
    nature_of_deduction VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    authority VARCHAR(255) DEFAULT '',
    recovery_date DATE,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/forms/labour/form-24.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contractors_register` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contractor_name VARCHAR(255) NOT NULL,
    registration_number VARCHAR(100) DEFAULT '',
    nature_of_work VARCHAR(255) DEFAULT '',
    total_workers INT DEFAULT 0,
    license_valid_from DATE,
    license_valid_to DATE,
    license_fee DECIMAL(12,2) DEFAULT 0,
    remarks TEXT DEFAULT '',
    status VARCHAR(50) DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/report/gumastadhara-fine-register.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fine_register` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    employee_id INT NOT NULL,
    fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    nature_of_fine VARCHAR(500),
    recovery_date DATE,
    remarks TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- from modules/entry/muster-entry.php, modules/report/muster-roll.php,
--      modules/report/gumastadhara-muster-roll.php
-- ---------------------------------------------------------------------------
ALTER TABLE `attendance_summary` ADD COLUMN `daily_data` LONGTEXT DEFAULT NULL AFTER `total_paid_days`;

-- ---------------------------------------------------------------------------
-- from modules/employee/designation.php, modules/employee/add.php
-- (ensure worker_category column exists on designations table)
-- ---------------------------------------------------------------------------
ALTER TABLE `designations` ADD COLUMN `worker_category` VARCHAR(50) DEFAULT 'Unskilled' AFTER `name`;


-- ---------------------------------------------------------------------------
-- from modules/notifications/bulk-email.php
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bulk_email_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_subject` VARCHAR(500) NOT NULL,
    `recipient_email` VARCHAR(255) NOT NULL,
    `recipient_name` VARCHAR(255),
    `status` ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
    `error_message` TEXT,
    `sent_by` INT,
    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
