-- Migration: Payroll Audit Fixes (Issues 7, 23)
-- Date: 2026-06-17
-- 
-- 1. Add payroll_id to loan_emi_log (Issue #7: link EMI deductions to payroll rows)
-- 2. Add missing indexes for common payroll queries (Issue #23)

-- ── 1. loan_emi_log: add payroll_id column ──
ALTER TABLE `loan_emi_log` ADD COLUMN `payroll_id` int(11) DEFAULT NULL AFTER `balance_after`;
ALTER TABLE `loan_emi_log` ADD INDEX `idx_payroll_id` (`payroll_id`);

-- ── 2. Missing indexes for payroll joins ──
-- payroll lookups by period+unit
ALTER TABLE `payroll` ADD INDEX `idx_period_unit` (`payroll_period_id`, `unit_id`);
-- attendance lookups by unit+month+year
ALTER TABLE `attendance_summary` ADD INDEX `idx_unit_month_year` (`unit_id`, `month`, `year`);
-- salary structure effective date range
ALTER TABLE `employee_salary_structures` ADD INDEX `idx_emp_eff_from` (`employee_id`, `effective_from`, `effective_to`);
-- loan lookups by employee+status+start
ALTER TABLE `employee_loans` ADD INDEX `idx_emp_status_dates` (`employee_id`, `status`, `start_year`, `start_month`);
-- loan EMI log by employee+month+year
ALTER TABLE `loan_emi_log` ADD INDEX `idx_emp_month_year` (`employee_id`, `month`, `year`);
-- payroll_unit_status lookups
ALTER TABLE `payroll_unit_status` ADD INDEX `idx_period_unit` (`payroll_period_id`, `unit_id`);