-- Migration: Update order_status ENUM to include new status values
-- Run this script to add 'pending' and 'delivered' status options
-- and ensure all required statuses are available

USE vip_db;

-- First, check if the column is named 'order_status' or 'status'
-- This migration handles both cases

-- Option 1: If column is named 'order_status'
ALTER TABLE orders 
MODIFY COLUMN order_status ENUM(
    'pending',
    'Requested',
    'Confirmed',
    'Scheduled for Delivery',
    'out for delivery',
    'Out for Delivery',
    'delivered',
    'Delivered (Pending Cash Turnover)',
    'Completed',
    'cancelled',
    'Cancelled'
) DEFAULT 'pending';

-- If the above fails, try with column name 'status':
-- ALTER TABLE orders 
-- MODIFY COLUMN status ENUM(
--     'pending',
--     'Confirmed',
--     'Scheduled for Delivery',
--     'out for delivery',
--     'Out for Delivery',
--     'delivered',
--     'Delivered (Pending Cash Turnover)',
--     'Completed',
--     'cancelled',
--     'Cancelled'
-- ) DEFAULT 'pending';
