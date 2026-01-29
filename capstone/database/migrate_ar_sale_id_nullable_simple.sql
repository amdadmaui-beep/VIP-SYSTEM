-- Simple Migration: Make Sale_ID nullable in account_receivable table
-- This allows creating AR records without linking to a sale (manual AR entries)
-- Run these SQL commands one by one in your database

USE vip_db;

-- Step 1: Find the foreign key constraint name (run this first to see the name)
-- SELECT CONSTRAINT_NAME 
-- FROM information_schema.KEY_COLUMN_USAGE 
-- WHERE TABLE_SCHEMA = 'vip_db' 
--   AND TABLE_NAME = 'account_receivable' 
--   AND COLUMN_NAME = 'Sale_ID' 
--   AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Step 2: Drop the foreign key (replace 'account_receivable_sale_id_foreign' with the actual name from Step 1)
-- ALTER TABLE account_receivable DROP FOREIGN KEY account_receivable_sale_id_foreign;

-- Step 3: Make Sale_ID nullable
ALTER TABLE account_receivable MODIFY Sale_ID INT NULL;

-- Step 4: Re-add the foreign key constraint (NULL values are allowed in FK columns)
ALTER TABLE account_receivable 
ADD CONSTRAINT account_receivable_sale_id_foreign 
FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) 
ON DELETE CASCADE;

-- Done! Now you can create AR records with Sale_ID = NULL
