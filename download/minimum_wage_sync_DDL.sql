-- ============================================================
-- Minimum Wage Sync — DDL (run in phpMyAdmin)
-- Feeds Simpliance data INTO existing minimum_wages table
-- Only adds the sync_log table (minimum_wages already exists)
-- ============================================================

-- Sync log table
CREATE TABLE IF NOT EXISTS minimum_wage_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state VARCHAR(100) NOT NULL,
    state_id INT DEFAULT NULL,
    status ENUM('success','error','partial') NOT NULL DEFAULT 'success',
    records_added INT UNSIGNED DEFAULT 0,
    records_skipped INT UNSIGNED DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    sync_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sync_date (sync_date),
    INDEX idx_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add source tracking column to existing minimum_wages table
-- (safe: ADD COLUMN IF NOT EXISTS is not supported, so use a procedure)
SET @dbname = DATABASE();
SET @tablename = 'minimum_wages';
SET @columnname = 'source';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(50) DEFAULT NULL AFTER notification_date')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add version_id column (tracks Simpliance version for audit)
SET @columnname = 'version_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL AFTER source')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add zone VARCHAR column (direct zone name like "Zone I", "Zone II", or NULL for all)
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
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(50) DEFAULT NULL AFTER state_id')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;