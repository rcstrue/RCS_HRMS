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

-- ============================================================
-- Zones table (for states that have Zone I, Zone II, etc.)
-- ============================================================
CREATE TABLE IF NOT EXISTS zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_id INT NOT NULL,
    zone_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_state_zone (state_id, zone_name),
    INDEX idx_state (state_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add zone_id column to minimum_wages (nullable FK to zones.id)
SET @columnname = 'zone_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL AFTER state_id')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;