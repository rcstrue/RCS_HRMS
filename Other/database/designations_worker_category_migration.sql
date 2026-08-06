-- =====================================================================
-- Migration: Add worker_category column to designations table
-- Purpose: Link each designation to a worker category so that when an
--          employee is assigned a designation, the worker category is
--          auto-populated (used by minimum-wage lookup & salary engine).
-- Run via: phpMyAdmin → SQL tab
-- =====================================================================

-- 1. Add worker_category column (if it does not already exist)
ALTER TABLE `designations`
    ADD COLUMN IF NOT EXISTS `worker_category`
        VARCHAR(50)
        NOT NULL
        DEFAULT 'Unskilled'
        AFTER `name`;

-- 2. Backfill existing rows so they have a sane default
UPDATE `designations`
   SET `worker_category` = 'Unskilled'
 WHERE `worker_category` IS NULL
    OR `worker_category` = '';

-- 3. (Optional) helpful index for joins
ALTER TABLE `designations`
    ADD INDEX IF NOT EXISTS `idx_worker_category` (`worker_category`);

-- =====================================================================
-- Verification queries (run manually to confirm):
--   DESCRIBE designations;
--   SELECT id, name, worker_category, desi_view FROM designations LIMIT 20;
-- =====================================================================
