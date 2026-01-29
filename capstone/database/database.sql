-- VIP System Database Schema
-- Create this database in your MySQL/MariaDB server

CREATE DATABASE IF NOT EXISTS vip_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE vip_db;

-- Users table
CREATE TABLE IF NOT EXISTS app_users (
    User_ID INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    Role_ID INT DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    status VARCHAR(20) DEFAULT 'active',
    linked_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products table
CREATE TABLE IF NOT EXISTS products (
    Product_ID INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(255) NOT NULL,
    form VARCHAR(50),
    unit VARCHAR(50) NOT NULL,
    wholesale_price DECIMAL(10, 2) NOT NULL,
    retail_price DECIMAL(10, 2) NOT NULL,
    is_discontinued TINYINT(1) DEFAULT 0,
    description TEXT,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_date DATE DEFAULT (CURRENT_DATE),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock In Inventory table
CREATE TABLE IF NOT EXISTS stockin_inventory (
    Inventory_ID INT AUTO_INCREMENT PRIMARY KEY,
    Product_ID INT NOT NULL,
    Production_ID INT NULL,
    date_in DATE NOT NULL,
    handled_by VARCHAR(255),
    quantity DECIMAL(10, 2) NOT NULL,
    storage_limit DECIMAL(10, 2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adjustments table
CREATE TABLE IF NOT EXISTS adjustments (
    Adjustment_ID INT AUTO_INCREMENT PRIMARY KEY,
    adjustment_date DATE NOT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES app_users(User_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adjustment Details table
CREATE TABLE IF NOT EXISTS adjustment_details (
    Detail_ID INT AUTO_INCREMENT PRIMARY KEY,
    Product_ID INT NOT NULL,
    Adjustment_ID INT NOT NULL,
    old_quantity DECIMAL(10, 2) NOT NULL,
    new_quantity DECIMAL(10, 2) NOT NULL,
    adjustment_type ENUM('increase', 'decrease') NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE CASCADE,
    FOREIGN KEY (Adjustment_ID) REFERENCES adjustments(Adjustment_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
