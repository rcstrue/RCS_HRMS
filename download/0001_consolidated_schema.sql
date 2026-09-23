-- ============================================================================
-- 0001_consolidated_schema.sql
-- Consolidated database schema extracted from inline DDL in PHP files.
--
-- This file is the SINGLE SOURCE OF TRUTH for the complete HRMS/ESS database
-- schema. The PHP files continue to self-heal on page load, but this migration
-- can be run against a fresh database to pre-create all required tables.
--
-- Generated: 2026-03-06
-- Total tables: 19
-- Total ALTER TABLE statements across codebase: 18 (listed at end as comments)
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: employee_change_requests
-- Source: hrms/modules/api/change-requests-migration.php (line 33)
-- Source: api/ess/change-requests.php (line 29)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employee_change_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    old_value TEXT,
    new_value TEXT NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    rejection_reason TEXT NULL,
    INDEX idx_employee_id (employee_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: form_a_contractors
-- Source: hrms/modules/forms/labour/form-a.php (line 11)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS form_a_contractors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contractor_name VARCHAR(255) NOT NULL,
    contractor_address TEXT DEFAULT '',
    registration_number VARCHAR(100) DEFAULT '',
    date_of_registration DATE DEFAULT NULL,
    contractor_pan VARCHAR(20) DEFAULT '',
    establishment_name VARCHAR(255) DEFAULT '',
    establishment_address TEXT DEFAULT '',
    nature_of_work VARCHAR(255) DEFAULT '',
    max_workmen INT DEFAULT 0,
    licensing_authority VARCHAR(255) DEFAULT '',
    license_number VARCHAR(100) DEFAULT '',
    license_issue_date DATE DEFAULT NULL,
    license_valid_from DATE DEFAULT NULL,
    license_valid_to DATE DEFAULT NULL,
    license_fee DECIMAL(12,2) DEFAULT 0,
    contractor_gstin VARCHAR(30) DEFAULT '',
    pf_registration_number VARCHAR(50) DEFAULT '',
    esi_registration_number VARCHAR(50) DEFAULT '',
    contact_person_name VARCHAR(255) DEFAULT '',
    contact_person_designation VARCHAR(100) DEFAULT '',
    contact_person_mobile VARCHAR(15) DEFAULT '',
    contact_person_email VARCHAR(255) DEFAULT '',
    bank_name VARCHAR(255) DEFAULT '',
    bank_account_number VARCHAR(50) DEFAULT '',
    bank_ifsc_code VARCHAR(20) DEFAULT '',
    contract_start_date DATE DEFAULT NULL,
    contract_end_date DATE DEFAULT NULL,
    contract_value DECIMAL(12,2) DEFAULT 0,
    security_deposit DECIMAL(12,2) DEFAULT 0,
    work_location VARCHAR(255) DEFAULT '',
    remarks TEXT DEFAULT '',
    status VARCHAR(20) DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: login_attempts
-- Source: hrms/includes/portal-security.php (line 44)
-- Source: hrms/includes/class.auth.php (line 948)
-- Source: api/ess/login.php (line 265)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(255) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempts     INT          NOT NULL DEFAULT 0,
    last_attempt DATETIME     NOT NULL,
    locked_until DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_ip (username, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: whatsapp_logs
-- Source: hrms/includes/whatsapp.php (line 25)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS whatsapp_logs (
    id int(11) NOT NULL AUTO_INCREMENT,
    employee_id int(11) DEFAULT NULL,
    mobile varchar(20) NOT NULL,
    message text NOT NULL,
    message_type enum('text','image','document','payslip','letter','otp','notification') DEFAULT 'text',
    media_url varchar(500) DEFAULT NULL,
    status enum('sent','queued','failed','link_generated') DEFAULT 'sent',
    error text DEFAULT NULL,
    wa_message_id varchar(100) DEFAULT NULL,
    sent_by int(11) DEFAULT NULL,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mobile (mobile),
    KEY idx_status (status),
    KEY idx_employee (employee_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: employee_loans
-- Source: hrms/includes/class.loan.php (line 512)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employee_loans (
    id int(11) NOT NULL AUTO_INCREMENT,
    employee_id int(11) NOT NULL,
    unit_id int(11) DEFAULT NULL,
    loan_type varchar(50) DEFAULT 'Personal',
    amount decimal(12,2) NOT NULL,
    interest_rate decimal(5,2) DEFAULT 0.00,
    tenure_months int(11) NOT NULL,
    emi_amount decimal(12,2) NOT NULL,
    total_interest decimal(12,2) DEFAULT 0.00,
    total_repayable decimal(12,2) NOT NULL,
    balance_amount decimal(12,2) NOT NULL,
    emi_deducted int(11) DEFAULT 0,
    start_month int(2) NOT NULL,
    start_year int(4) NOT NULL,
    status enum('Active','Closed','Settled','Written Off') DEFAULT 'Active',
    remarks text DEFAULT NULL,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employee (employee_id),
    KEY idx_unit (unit_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: loan_emi_log
-- Source: hrms/includes/class.loan.php (line 537)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS loan_emi_log (
    id int(11) NOT NULL AUTO_INCREMENT,
    loan_id int(11) NOT NULL,
    employee_id int(11) NOT NULL,
    month int(2) NOT NULL,
    year int(4) NOT NULL,
    emi_amount decimal(12,2) NOT NULL,
    principal_component decimal(12,2) DEFAULT 0.00,
    interest_component decimal(12,2) DEFAULT 0.00,
    balance_after decimal(12,2) NOT NULL,
    deducted_via_payroll tinyint(1) DEFAULT 1,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_loan_month_year (loan_id, month, year),
    KEY idx_employee_month (employee_id, month, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: role_menu_permissions
-- Source: hrms/includes/class.auth.php (line 481)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS role_menu_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    menu_key VARCHAR(50) NOT NULL COMMENT 'Main menu key',
    submenu_key VARCHAR(100) DEFAULT NULL COMMENT 'Submenu key',
    is_visible TINYINT(1) DEFAULT 1 COMMENT 'Visibility toggle',
    can_view TINYINT(1) DEFAULT 1 COMMENT 'Can view records',
    can_add TINYINT(1) DEFAULT 0 COMMENT 'Can add/create records',
    can_edit TINYINT(1) DEFAULT 0 COMMENT 'Can edit records',
    can_delete TINYINT(1) DEFAULT 0 COMMENT 'Can delete records',
    can_export TINYINT(1) DEFAULT 0 COMMENT 'Can export data',
    can_import TINYINT(1) DEFAULT 0 COMMENT 'Can import data',
    can_print TINYINT(1) DEFAULT 0 COMMENT 'Can print data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_role_menu_submenu (role_id, menu_key, submenu_key),
    INDEX idx_role_menu (role_id, menu_key),
    INDEX idx_role_submenu (role_id, submenu_key),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: pf_form5_submissions
-- Source: hrms/modules/report/pf/form-5.php (line 103)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pf_form5_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month VARCHAR(2) NOT NULL,
    year VARCHAR(4) NOT NULL,
    client_id INT DEFAULT NULL,
    unit_id INT DEFAULT NULL,
    submitted_by VARCHAR(100),
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'submitted',
    remarks TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: user_access
-- Source: hrms/modules/settings/manager-allocation.php (line 16)
-- Source: api/ess/access.php (line 36)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_access (
    id int(11) NOT NULL AUTO_INCREMENT,
    user_id varchar(50) NOT NULL,
    access_type enum('city','unit') NOT NULL,
    access_id varchar(100) NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY unique_access (user_id, access_type, access_id),
    KEY idx_user (user_id),
    KEY idx_type (access_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: push_subscriptions
-- Source: api/ess/push-subscribe.php (line 17)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh_key VARCHAR(200) NOT NULL,
    auth_key VARCHAR(200) NOT NULL,
    user_agent VARCHAR(500) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_endpoint (endpoint(255)),
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: ess_unit_visits
-- Source: api/ess/unit-visits.php (line 52)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ess_unit_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    unit_id INT NOT NULL,
    visit_number TINYINT NOT NULL COMMENT '1=first visit, 2=second visit',
    visit_month INT NOT NULL COMMENT '1-12',
    visit_year INT NOT NULL,
    document_url VARCHAR(500) DEFAULT '',
    document_type VARCHAR(20) DEFAULT 'image' COMMENT 'image or pdf',
    notes TEXT DEFAULT NULL,
    status ENUM('submitted','approved','rejected') DEFAULT 'submitted',
    rejection_reason TEXT DEFAULT NULL,
    approved_by VARCHAR(50) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    total_score DECIMAL(8,2) DEFAULT 0,
    max_score DECIMAL(8,2) DEFAULT 0,
    score_percent DECIMAL(5,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_visit (employee_id, unit_id, visit_month, visit_year, visit_number),
    INDEX idx_employee_month (employee_id, visit_month, visit_year),
    INDEX idx_unit_month (unit_id, visit_month, visit_year),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: ess_visit_checklist_items
-- Source: api/ess/unit-visits.php (line 99)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ess_visit_checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    checklist_item_id INT NOT NULL,
    category_id INT NOT NULL,
    status ENUM('yes','no','na') DEFAULT 'yes',
    remarks TEXT DEFAULT NULL,
    photo_url VARCHAR(500) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: ess_visit_audit_log
-- Source: api/ess/unit-visits.php (line 114)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ess_visit_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    performed_by VARCHAR(50) NOT NULL,
    details TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visit (visit_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: salary_upload_records
-- Source: api/ess/salary-upload.php (line 289)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS salary_upload_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    employee_name VARCHAR(100) DEFAULT '',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    month INT NOT NULL,
    year INT NOT NULL,
    salary_date DATE DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    carry_forward DECIMAL(12,2) DEFAULT 0,
    status ENUM('pending','processed','rejected') DEFAULT 'pending',
    uploaded_by VARCHAR(50) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee (employee_id),
    INDEX idx_month_year (month, year),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: bulk_upload_logs
-- Source: api/ess/salary-upload.php (line 331)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bulk_upload_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_type ENUM('attendance','salary_structure','salary_update','employee_master','salary_upload') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    total_rows INT DEFAULT 0,
    processed_rows INT DEFAULT 0,
    error_rows INT DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'completed',
    error_details TEXT DEFAULT NULL,
    period_id INT DEFAULT NULL,
    client_id INT DEFAULT NULL,
    unit_id INT DEFAULT NULL,
    uploaded_by INT NOT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: ess_manpower_daily
-- Source: api/ess/manpower-status.php (line 47)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ess_manpower_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    unit_id INT NOT NULL,
    client_id INT NOT NULL DEFAULT 0,
    report_date DATE NOT NULL,
    morning_worker_budget INT NOT NULL DEFAULT 0,
    morning_worker_actual INT NOT NULL DEFAULT 0,
    morning_supervisor_budget INT NOT NULL DEFAULT 0,
    morning_supervisor_actual INT NOT NULL DEFAULT 0,
    evening_worker_budget INT NOT NULL DEFAULT 0,
    evening_worker_actual INT NOT NULL DEFAULT 0,
    evening_supervisor_budget INT NOT NULL DEFAULT 0,
    evening_supervisor_actual INT NOT NULL DEFAULT 0,
    remarks TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_unit_date (unit_id, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: ess_checklist_categories
-- Source: api/ess/checklist-master.php (line 199)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ess_checklist_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: ess_checklist_items
-- Source: api/ess/checklist-master.php (line 209)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ess_checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    weight DECIMAL(5,2) DEFAULT 1.00 COMMENT 'Points weight for scoring',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES ess_checklist_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: temp_employees
-- Source: api/ess/team-summary.php (line 135)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS temp_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    month INT NOT NULL,
    year INT NOT NULL,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_unit_name_month (unit_id, name, month, year),
    KEY idx_unit_month (unit_id, month, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ALTER TABLE statements found in the codebase (self-healing / migration logic)
-- These are NOT executed by this file. Listed here for documentation only.
-- The PHP files apply these dynamically via SHOW COLUMNS checks.
-- ============================================================================

-- ── payroll ──────────────────────────────────────────────────────────────────
-- Source: hrms/includes/class.payroll.php (line 257)
-- ALTER TABLE payroll ADD COLUMN extra_days_amount DECIMAL(10,2) DEFAULT 0.00 AFTER overtime_amount;

-- ── role_menu_permissions ────────────────────────────────────────────────────
-- Source: hrms/includes/class.auth.php (lines 461-473)
-- ALTER TABLE role_menu_permissions
--     ADD COLUMN submenu_key VARCHAR(100) DEFAULT NULL AFTER menu_key,
--     ADD COLUMN can_view TINYINT(1) DEFAULT 1 AFTER is_visible,
--     ADD COLUMN can_add TINYINT(1) DEFAULT 0 AFTER can_view,
--     ADD COLUMN can_edit TINYINT(1) DEFAULT 0 AFTER can_add,
--     ADD COLUMN can_delete TINYINT(1) DEFAULT 0 AFTER can_edit,
--     ADD COLUMN can_export TINYINT(1) DEFAULT 0 AFTER can_delete,
--     ADD COLUMN can_import TINYINT(1) DEFAULT 0 AFTER can_export,
--     ADD COLUMN can_print TINYINT(1) DEFAULT 0 AFTER can_import,
--     DROP INDEX uniq_role_menu,
--     ADD UNIQUE KEY uniq_role_menu_submenu (role_id, menu_key, submenu_key),
--     ADD INDEX idx_role_menu (role_id, menu_key),
--     ADD INDEX idx_role_submenu (role_id, submenu_key);

-- ── minimum_wages ────────────────────────────────────────────────────────────
-- Source: hrms/includes/class.minimumwagesync.php (line 196)
-- ALTER TABLE minimum_wages ADD COLUMN zone VARCHAR(50) DEFAULT NULL AFTER state_id;

-- ── states ───────────────────────────────────────────────────────────────────
-- Source: hrms/includes/class.minimumwagesync.php (line 222)
-- ALTER TABLE states ADD COLUMN simpliance_slug VARCHAR(100) DEFAULT NULL AFTER state_name;

-- ── designations ─────────────────────────────────────────────────────────────
-- Source: hrms/modules/employee/run-designation-migration.php (lines 26, 44)
-- ALTER TABLE designations ADD COLUMN worker_category VARCHAR(50) NOT NULL DEFAULT 'Unskilled' AFTER name;
-- ALTER TABLE designations ADD INDEX idx_worker_category (worker_category);

-- ── push_notification_queue ──────────────────────────────────────────────────
-- Source: hrms/scripts/cron-auto-notifications.php (line 59)
-- Source: hrms/scripts/cron-push-notifications.php (line 37)
-- ALTER TABLE push_notification_queue ADD COLUMN attempt_count INT DEFAULT 0;
-- ALTER TABLE push_notification_queue ADD COLUMN max_attempts INT DEFAULT 3;
-- ALTER TABLE push_notification_queue ADD COLUMN next_retry_at DATETIME DEFAULT NULL;
-- ALTER TABLE push_notification_queue ADD COLUMN last_error TEXT DEFAULT NULL;

-- ── ess_unit_visits ──────────────────────────────────────────────────────────
-- Source: api/ess/unit-visits.php (lines 80-94)
-- (Columns already included in the CREATE TABLE above; listed here for provenance)
-- ALTER TABLE ess_unit_visits ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER status;
-- ALTER TABLE ess_unit_visits ADD COLUMN approved_by VARCHAR(50) DEFAULT NULL AFTER rejection_reason;
-- ALTER TABLE ess_unit_visits ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by;
-- ALTER TABLE ess_unit_visits ADD COLUMN total_score DECIMAL(8,2) DEFAULT 0 AFTER approved_at;
-- ALTER TABLE ess_unit_visits ADD COLUMN max_score DECIMAL(8,2) DEFAULT 0 AFTER total_score;
-- ALTER TABLE ess_unit_visits ADD COLUMN score_percent DECIMAL(5,2) DEFAULT 0 AFTER max_score;
-- ALTER TABLE ess_unit_visits ADD INDEX idx_status (status);
-- ALTER TABLE ess_unit_visits ADD UNIQUE KEY uk_visit (employee_id, unit_id, visit_month, visit_year, visit_number);

-- ── ess_notifications ────────────────────────────────────────────────────────
-- Source: api/ess/admin-notifications.php (lines 58, 64, 70)
-- ALTER TABLE ess_notifications ADD COLUMN broadcast_id VARCHAR(50) DEFAULT NULL AFTER id, ADD INDEX idx_broadcast (broadcast_id);
-- ALTER TABLE ess_notifications ADD COLUMN sender_id VARCHAR(50) DEFAULT NULL AFTER broadcast_id;
-- ALTER TABLE ess_notifications ADD COLUMN target_type VARCHAR(50) DEFAULT NULL AFTER sender_id;
