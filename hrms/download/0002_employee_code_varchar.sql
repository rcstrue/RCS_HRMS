-- Migration: Change employee_code from INT to VARCHAR(20)
-- Supports alphanumeric codes like GFLA110001, RBL4001, U6001
-- Existing numeric codes (1001, 1002, ...) are preserved as strings

-- 1. Alter employees table
ALTER TABLE employees MODIFY employee_code VARCHAR(20) NOT NULL;

-- 2. Alter ess_employee_cache table (if it exists)
-- Run this separately if the table exists:
-- ALTER TABLE ess_employee_cache MODIFY employee_code VARCHAR(20);

-- 3. Remove the UNIQUE constraint if it was added as a named constraint
-- (MySQL auto-names it, so ALTER TABLE handles it)
-- The UNIQUE property is preserved by the MODIFY operation

-- Note: No data migration needed — integer values are valid VARCHAR values
-- All existing queries (WHERE employee_code = ?) continue to work identically
