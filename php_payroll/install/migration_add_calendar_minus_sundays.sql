-- Migration: Add 'calendar_minus_sundays' pay_days_type
-- Date: 2026-04-02
-- Description: Adds a new pay_days_type option to exclude Sundays from total working days
--              e.g., April 2026 has 30 days, 5 Sundays → 25 working days

-- Alter unit_salary_formulas table
ALTER TABLE `unit_salary_formulas`
  MODIFY COLUMN `pay_days_type` ENUM('actual','previous_month','fixed_30','calendar_minus_sundays') DEFAULT 'actual';

-- Alter payroll_periods table (if it exists with pay_days_type)
ALTER TABLE `payroll_periods`
  MODIFY COLUMN `pay_days_type` ENUM('actual','previous_month','fixed_30','calendar_minus_sundays') DEFAULT 'actual';
