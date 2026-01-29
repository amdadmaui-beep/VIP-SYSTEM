-- Migration: Add order_time column to orders table if it doesn't exist
-- Run this script to fix the "Unknown column 'order_time' in 'field list'" error

USE vip_db;

-- Check if order_time column exists, if not, add it
SET @dbname = DATABASE();
SET @tablename = 'orders';
SET @columnname = 'order_time';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1', -- Column exists, do nothing
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TIME NULL AFTER order_date')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- If column was just added, update existing records to have a default time
UPDATE orders SET order_time = TIME(created_at) WHERE order_time IS NULL;
