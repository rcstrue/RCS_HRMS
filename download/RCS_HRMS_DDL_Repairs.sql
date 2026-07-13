-- ============================================================================
-- RCS_HRMS — Complete DDL Repair Script
-- Source: RCS_HRMS_COMPLETE_REPAIR.md items 2-6, 18-25, 30
-- Run in phpMyAdmin during off-hours
-- ============================================================================

-- ============================================================================
-- PRIORITY 1 — Fix before next payroll run
-- ============================================================================

-- [2] payroll_unit_status — status case mismatch breaks approve flow
UPDATE payroll_unit_status SET status='Processed'  WHERE status='processed';
UPDATE payroll_unit_status SET status='Approved'   WHERE status='approved';
UPDATE payroll_unit_status SET status='Finalized'  WHERE status='finalized';

ALTER TABLE payroll_unit_status MODIFY status
  ENUM('pending','attendance_uploaded','Processed','Approved','Finalized')
  DEFAULT 'pending';

-- [3] employee_advances — no UNIQUE key allows double-deduction
-- Remove duplicate advances if any exist first:
DELETE a1 FROM employee_advances a1
  INNER JOIN employee_advances a2
  WHERE a1.id > a2.id
    AND a1.employee_id = a2.employee_id
    AND a1.unit_id = a2.unit_id
    AND a1.month = a2.month
    AND a1.year = a2.year;

-- Then add the unique key:
ALTER TABLE employee_advances
  ADD UNIQUE KEY uniq_emp_unit_month_year (employee_id, unit_id, month, year);

-- [4] employee_salary_structures.updated_at is VARCHAR — breaks sorting
-- First check for any malformed dates:
-- SELECT id, updated_at FROM employee_salary_structures
--   WHERE updated_at != '' AND updated_at IS NOT NULL
--   AND STR_TO_DATE(updated_at, '%Y-%m-%d %H:%i:%s') IS NULL;

-- Then convert:
ALTER TABLE employee_salary_structures
  MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- [5] employee_documents — only table missing PRIMARY KEY
ALTER TABLE employee_documents
  ADD PRIMARY KEY (id),
  MODIFY id INT(11) NOT NULL AUTO_INCREMENT;

-- [6] Add essential indexes — all critical tables have zero indexes
-- employees
ALTER TABLE employees
  ADD INDEX idx_status (status),
  ADD INDEX idx_unit_id (unit_id),
  ADD INDEX idx_client_id (client_id),
  ADD UNIQUE INDEX idx_employee_code (employee_code),
  ADD INDEX idx_mobile (mobile_number);

-- attendance_summary
ALTER TABLE attendance_summary
  ADD UNIQUE INDEX idx_emp_unit_mo_yr (employee_id, unit_id, month, year),
  ADD INDEX idx_month_year (month, year);

-- employee_advances
ALTER TABLE employee_advances
  ADD INDEX idx_emp_unit_mo_yr (employee_id, unit_id, month, year),
  ADD INDEX idx_month_year (month, year);

-- payroll
ALTER TABLE payroll
  ADD INDEX idx_period_id (payroll_period_id),
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_unit_id (unit_id),
  ADD INDEX idx_status (status),
  ADD UNIQUE INDEX idx_period_emp (payroll_period_id, employee_id);

-- payroll_periods
ALTER TABLE payroll_periods
  ADD UNIQUE INDEX idx_month_year (month, year),
  ADD INDEX idx_status (status);

-- payroll_unit_status
ALTER TABLE payroll_unit_status
  ADD UNIQUE INDEX idx_period_unit (payroll_period_id, unit_id),
  ADD INDEX idx_status (status);

-- employee_salary_structures
ALTER TABLE employee_salary_structures
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_effective (employee_id, effective_from);

-- leave_applications
ALTER TABLE leave_applications
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_status (status);

-- leave_balances
ALTER TABLE leave_balances
  ADD UNIQUE INDEX idx_emp_type_year (employee_id, leave_type, year);

-- employee_loans
ALTER TABLE employee_loans
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_status (status);

-- wage_register
ALTER TABLE wage_register
  ADD INDEX idx_emp_mo_yr (employee_id, month, year),
  ADD INDEX idx_unit_mo_yr (unit_id, month, year);

-- audit_log
ALTER TABLE audit_log
  ADD INDEX idx_created_at (created_at),
  ADD INDEX idx_user_id (user_id);

-- login_attempts
ALTER TABLE login_attempts
  ADD INDEX idx_identifier_time (identifier, attempted_at);

-- ============================================================================
-- PRIORITY 4 — Database cleanup
-- ============================================================================

-- [18] Drop duplicate attendance table (merge first)
-- Step 1: check data
-- SELECT 'attendance' as tbl, COUNT(*) as cnt FROM attendance
-- UNION ALL
-- SELECT 'attendance_summary', COUNT(*) FROM attendance_summary;

-- Step 2: migrate unique records (if any)
INSERT IGNORE INTO attendance_summary
  SELECT * FROM attendance
  WHERE (employee_id, unit_id, month, year) NOT IN
    (SELECT employee_id, unit_id, month, year FROM attendance_summary);

-- Step 3: drop the duplicate
DROP TABLE IF EXISTS attendance;

-- [19] Drop backup tables
DROP TABLE IF EXISTS employees1;
DROP TABLE IF EXISTS employees2;

-- [20] employees table — latin1 charset corrupts regional names
ALTER TABLE employees CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_applications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_balances CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE announcements CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE designations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE pfdatabase CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- [21] employee_salary_structures — duplicate washing allowance columns
UPDATE employee_salary_structures
  SET washing_allowance = washing
  WHERE washing > 0 AND washing_allowance = 0;

ALTER TABLE employee_salary_structures DROP COLUMN IF EXISTS washing;

-- [22] Drop redundant leave balance tables (ESS now reads from leave_balances)
DROP TABLE IF EXISTS ess_leave_balances;
DROP TABLE IF EXISTS employee_leave_balance;

-- [23] employee_id type mismatch — align to UNSIGNED
-- Run AFTER indexes (item 6) — type change rebuilds indexes
ALTER TABLE payroll MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE attendance_summary MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_advances MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE leave_applications MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE leave_balances MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_loans MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_salary_structures MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_settlements MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE salary_revisions MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE wage_register MODIFY employee_id INT(10) UNSIGNED NOT NULL;

-- [24] Drop unused professional_tax_slabs table
DROP TABLE IF EXISTS professional_tax_slabs;

-- [25] ess_employee_cache — add app_role column
ALTER TABLE ess_employee_cache
  ADD COLUMN IF NOT EXISTS app_role VARCHAR(50) DEFAULT 'employee'
  AFTER role;

-- ============================================================================
-- NOTE: Item 30 (LWF tables) is deferred — lwf_rates already queried first,
-- falls back to lwf_state_rates. Long-term: migrate and drop lwf_state_rates.
-- ============================================================================