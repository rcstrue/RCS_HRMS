-- ============================================================================
-- RCS HRMS Migration 001: Status ENUM + app_role Single Source of Truth
-- ============================================================================
-- Date: 2026-06-27
-- Summary:
--   1. Add 'active' to employees.status ENUM (ESS queries for 'active')
--   2. Add 'admin' and 'field_officer' to ess_employee_cache.role ENUM
--   3. Create app_role column on employees if not exists
--   4. Backfill app_role from employee_role/worker_category
--   5. Add comment documenting app_role as single source of truth
-- ============================================================================

-- STEP 1: Add 'active' to employees.status ENUM
-- Both 'approved' and 'active' will be valid. Existing 'approved' rows stay.
-- New employees can use either. ESS API now checks IN ('approved', 'active').
ALTER TABLE employees MODIFY COLUMN status ENUM(
    'approved',
    'active',
    'pending_hr_verification',
    'pending_document_verification',
    'inactive',
    'terminated',
    'removed'
) NOT NULL DEFAULT 'pending_hr_verification';

-- STEP 2: Ensure ess_employee_cache.role can store 'admin'
-- The cache table's role column may have a restrictive ENUM that excludes 'admin'
ALTER TABLE ess_employee_cache MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'employee';

-- STEP 3: Ensure app_role column exists on employees table
SET @dbname = DATABASE();
SET @tablename = 'employees';
SET @columnname = 'app_role';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(30) NOT NULL DEFAULT ''employee'' AFTER employee_role')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- STEP 4: Backfill app_role from existing employee_role / worker_category
-- Only update rows where app_role is still the default 'employee'
UPDATE employees SET app_role = CASE
    WHEN LOWER(employee_role) = 'admin' THEN 'admin'
    WHEN LOWER(employee_role) IN ('manager', 'field_officer', 'regional_manager') THEN LOWER(employee_role)
    WHEN LOWER(worker_category) LIKE '%regional%' OR LOWER(employee_role) LIKE '%regional%' THEN 'regional_manager'
    WHEN LOWER(worker_category) LIKE '%manager%' OR LOWER(worker_category) LIKE '%area manager%'
        OR LOWER(worker_category) LIKE '%field officer%' THEN 'manager'
    WHEN LOWER(worker_category) LIKE '%supervisor%' OR LOWER(worker_category) LIKE '%team lead%'
        OR LOWER(employee_role) LIKE '%supervisor%' THEN 'supervisor'
    ELSE 'employee'
END
WHERE app_role = 'employee'
  AND (employee_role IS NOT NULL AND employee_role != '' OR worker_category IS NOT NULL AND worker_category != '');

-- STEP 5: Add column comments for documentation
-- (These are informational and don't affect runtime)
ALTER TABLE employees MODIFY COLUMN app_role VARCHAR(30) NOT NULL DEFAULT 'employee'
  COMMENT 'Single source of truth for role. Values: admin, regional_manager, manager, field_officer, supervisor, employee';

-- ============================================================================
-- VERIFICATION QUERIES (run these to verify migration success):
-- ============================================================================
-- SELECT DISTINCT status FROM employees;
--   -- Should show: approved, active, pending_hr_verification, inactive, terminated, removed
-- SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'employees' AND COLUMN_NAME = 'status';
-- SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'ess_employee_cache' AND COLUMN_NAME = 'role';
-- SELECT app_role, COUNT(*) FROM employees GROUP BY app_role;