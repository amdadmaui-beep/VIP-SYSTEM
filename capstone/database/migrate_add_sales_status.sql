-- Migration: Add status column to sales table if it doesn't exist
-- This ensures the sales table has the status column for tracking sale status

USE vip_db;

-- Check if status column exists, if not add it
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'vip_db' 
    AND TABLE_NAME = 'sales' 
    AND COLUMN_NAME = 'status');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN status ENUM(\'Pending\', \'Completed\', \'Cancelled\') DEFAULT \'Completed\' AFTER remarks',
    'SELECT "status column already exists in sales table"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
