-- Migration: Update production_type ENUM to use 'stockin' and 'orders'
-- Run this script to fix the "Data truncated for column 'production_type'" error

USE vip_db;

-- Update the ENUM values for production_type column
ALTER TABLE productions 
MODIFY COLUMN production_type ENUM('stockin', 'orders') NOT NULL DEFAULT 'stockin';

-- If there are existing records with old values, update them
UPDATE productions SET production_type = 'stockin' WHERE production_type = 'stock';
UPDATE productions SET production_type = 'orders' WHERE production_type = 'order';
