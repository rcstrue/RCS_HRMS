-- ══════════════════════════════════════════════════════════════════
-- RCS HRMS Pro — Unit Salary Templates Migration (FIXED)
-- Adds template_id + applied_month to employee_salary_structures
-- ══════════════════════════════════════════════════════════════════

SET @dbname = DATABASE();

-- ── 1. Add template_id column ──
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'employee_salary_structures' AND COLUMN_NAME = 'template_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE employee_salary_structures ADD COLUMN template_id INT UNSIGNED NULL AFTER employee_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 2. Add applied_month column ──
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'employee_salary_structures' AND COLUMN_NAME = 'applied_month'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE employee_salary_structures ADD COLUMN applied_month DATE NULL AFTER template_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 3. Add idx_template index ──
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'employee_salary_structures' AND INDEX_NAME = 'idx_template'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE employee_salary_structures ADD INDEX idx_template (template_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
