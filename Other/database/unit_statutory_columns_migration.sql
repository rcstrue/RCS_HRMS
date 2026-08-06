-- =========================================================================
-- Migration: Add Statutory Configuration + Zone to `units` table
-- =========================================================================
-- Adds 5 new columns to the `units` table:
--   zone             VARCHAR(50)  — Minimum Wage Zone (e.g., "Zone I", "Zone II")
--   pf_applicable    TINYINT(1)   — Whether PF applies for this unit (default 1)
--   esi_applicable   TINYINT(1)   — Whether ESI applies for this unit (default 1)
--   pt_applicable    TINYINT(1)   — Whether Professional Tax applies (default 1)
--   lwf_applicable   TINYINT(1)   — Whether Labour Welfare Fund applies (default 1)
--
-- These columns are the UNIT-LEVEL defaults. Per template/employee, the same
-- flags can be overridden (per Q1=A decision: "default as per unit, can be
-- changed for employee").
--
-- Run ONCE in phpMyAdmin against the live HRMS database.
-- Safe to re-run (uses INFORMATION_SCHEMA check — no-op if columns exist).
-- =========================================================================

SET @dbname = DATABASE();
SET @tablename = 'units';

-- ── 1. zone ──
SET @columnname = 'zone';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(50) DEFAULT NULL AFTER state')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ── 2. pf_applicable ──
SET @columnname = 'pf_applicable';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) NOT NULL DEFAULT 1 AFTER zone')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ── 3. esi_applicable ──
SET @columnname = 'esi_applicable';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) NOT NULL DEFAULT 1 AFTER pf_applicable')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ── 4. pt_applicable ──
SET @columnname = 'pt_applicable';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) NOT NULL DEFAULT 1 AFTER esi_applicable')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ── 5. lwf_applicable ──
SET @columnname = 'lwf_applicable';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) NOT NULL DEFAULT 1 AFTER pt_applicable')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ── Verification ──
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'units'
  AND COLUMN_NAME IN ('zone', 'pf_applicable', 'esi_applicable', 'pt_applicable', 'lwf_applicable')
ORDER BY ORDINAL_POSITION;
