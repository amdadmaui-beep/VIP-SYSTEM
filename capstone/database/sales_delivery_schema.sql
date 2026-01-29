-- Sales and Delivery Module Database Schema
-- This extends the VIP System database with sales and delivery management tables
-- Inventory is only reduced when payment is received (sale recorded), not at delivery

USE vip_db;

-- Delivery table
-- Tracks delivery information for orders
CREATE TABLE IF NOT EXISTS delivery (
    Delivery_ID INT AUTO_INCREMENT PRIMARY KEY,
    Order_ID INT NULL,
    delivery_address TEXT,
    schedule_date DATE NULL,
    actual_date_arrived DATE NULL,
    delivery_status ENUM('Scheduled', 'In Transit', 'Delivered') DEFAULT 'Scheduled',
    delivered_by VARCHAR(255),
    delivered_to VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID) ON DELETE SET NULL,
    INDEX idx_order (Order_ID),
    INDEX idx_status (delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery Detail table
-- Tracks individual products in each delivery, including received and damaged quantities
CREATE TABLE IF NOT EXISTS delivery_detail (
    Delivery_Detail_ID INT AUTO_INCREMENT PRIMARY KEY,
    Delivery_ID INT NOT NULL,
    Order_detail_ID INT NOT NULL,
    Damage_ID INT NULL,
    received_qty DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    damage_qty DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    remarks TEXT,
    status ENUM('Pending', 'Delivered', 'Partial', 'Damaged') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) ON DELETE CASCADE,
    FOREIGN KEY (Order_detail_ID) REFERENCES order_details(Order_detail_ID) ON DELETE CASCADE,
    INDEX idx_delivery (Delivery_ID),
    INDEX idx_order_detail (Order_detail_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sales table
-- Records sales after payment is received (inventory is reduced here)
CREATE TABLE IF NOT EXISTS sales (
    Sale_ID INT AUTO_INCREMENT PRIMARY KEY,
    Delivery_Detail_ID INT NULL,
    Delivery_ID INT NULL,
    Order_detail_ID INT NULL,
    Damage_ID INT NULL,
    received_qty DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    damage_qty DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    remarks TEXT,
    status ENUM('Pending', 'Completed', 'Cancelled') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Delivery_Detail_ID) REFERENCES delivery_detail(Delivery_Detail_ID) ON DELETE SET NULL,
    FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) ON DELETE SET NULL,
    FOREIGN KEY (Order_detail_ID) REFERENCES order_details(Order_detail_ID) ON DELETE SET NULL,
    INDEX idx_delivery_detail (Delivery_Detail_ID),
    INDEX idx_delivery (Delivery_ID),
    INDEX idx_order_detail (Order_detail_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sale Details table
-- Line items for each sale (what was actually sold)
CREATE TABLE IF NOT EXISTS sale_details (
    Sale_detail_ID INT AUTO_INCREMENT PRIMARY KEY,
    Sale_ID INT NOT NULL,
    Product_ID INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) ON DELETE CASCADE,
    FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE RESTRICT,
    INDEX idx_sale (Sale_ID),
    INDEX idx_product (Product_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sale Source table
-- Links sales to deliveries to determine if sale is from walk-in (retail) or pre-order (wholesale)
CREATE TABLE IF NOT EXISTS sale_source (
    Sale_delivery_ID INT AUTO_INCREMENT PRIMARY KEY,
    Delivery_ID INT NULL,
    Sale_ID INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) ON DELETE SET NULL,
    FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) ON DELETE CASCADE,
    INDEX idx_delivery (Delivery_ID),
    INDEX idx_sale (Sale_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add Sale_ID column to orders table if it doesn't exist
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'vip_db' 
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME = 'Sale_ID');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN Sale_ID INT NULL',
    'SELECT "Sale_ID column already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for Sale_ID if it doesn't exist
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = 'vip_db' 
    AND TABLE_NAME = 'orders' 
    AND CONSTRAINT_NAME = 'fk_order_sale');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE orders ADD CONSTRAINT fk_order_sale FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) ON DELETE SET NULL',
    'SELECT "Foreign key fk_order_sale already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for Sale_ID if it doesn't exist
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'vip_db' 
    AND TABLE_NAME = 'orders' 
    AND INDEX_NAME = 'idx_sale');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE orders ADD INDEX idx_sale (Sale_ID)',
    'SELECT "Index idx_sale already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure order_details table has Order_detail_ID as primary key
-- Check if Order_detail_ID exists, if not add it
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'vip_db' 
    AND TABLE_NAME = 'order_details' 
    AND COLUMN_NAME = 'Order_detail_ID');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE order_details ADD COLUMN Order_detail_ID INT AUTO_INCREMENT PRIMARY KEY FIRST',
    'SELECT "Order_detail_ID column already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
