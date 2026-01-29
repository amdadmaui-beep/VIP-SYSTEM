-- Migration: Make Sale_ID nullable in account_receivable table
-- This allows creating AR records without linking to a sale (manual AR entries)
-- Run this SQL in your database

USE vip_db;

-- Check current constraint name (MySQL doesn't support IF EXISTS for FK, so we need to find it first)
-- Run this to find the constraint name:
-- SELECT CONSTRAINT_NAME 
-- FROM information_schema.KEY_COLUMN_USAGE 
-- WHERE TABLE_SCHEMA = 'vip_db' 
--   AND TABLE_NAME = 'account_receivable' 
--   AND COLUMN_NAME = 'Sale_ID' 
--   AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Drop the foreign key constraint (replace 'account_receivable_sale_id_foreign' with actual name if different)
SET @constraint_name = (
    SELECT CONSTRAINT_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = 'vip_db' 
      AND TABLE_NAME = 'account_receivable' 
      AND COLUMN_NAME = 'Sale_ID' 
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @sql = CONCAT('ALTER TABLE account_receivable DROP FOREIGN KEY ', @constraint_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Make Sale_ID nullable
ALTER TABLE account_receivable 
MODIFY Sale_ID INT NULL;

-- Re-add the foreign key constraint (now allowing NULL - NULL values bypass FK check)
ALTER TABLE account_receivable 
ADD CONSTRAINT account_receivable_sale_id_foreign 
FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) 
ON DELETE CASCADE;

-- Verify the change
DESCRIBE account_receivable;
