-- Orders Module Database Schema
-- This extends the VIP System database with order management tables

USE vip_db;

-- Customers table (if not exists)
CREATE TABLE IF NOT EXISTS customers (
    Customer_ID INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    phone_number VARCHAR(50) NOT NULL,
    address TEXT,
    type VARCHAR(50) DEFAULT 'Regular',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders table
-- Tracks orders from phone calls through delivery completion
CREATE TABLE IF NOT EXISTS orders (
    Order_ID INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    Customer_ID INT NOT NULL,
    order_date DATE NOT NULL,
    order_time TIME NOT NULL,
    status ENUM(
        'Requested',
        'Confirmed',
        'Scheduled for Delivery',
        'Out for Delivery',
        'Delivered (Pending Cash Turnover)',
        'Completed',
        'Cancelled'
    ) DEFAULT 'Requested',
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) DEFAULT 'Cash on Delivery',
    delivery_address TEXT,
    delivery_date DATE NULL,
    delivery_time TIME NULL,
    notes TEXT,
    created_by INT NOT NULL,
    confirmed_by INT NULL,
    completed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    cancellation_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Customer_ID) REFERENCES customers(Customer_ID) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES app_users(User_ID),
    FOREIGN KEY (confirmed_by) REFERENCES app_users(User_ID),
    INDEX idx_status (status),
    INDEX idx_order_date (order_date),
    INDEX idx_customer (Customer_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order Items table
-- Tracks individual products in each order
CREATE TABLE IF NOT EXISTS order_items (
    OrderItem_ID INT AUTO_INCREMENT PRIMARY KEY,
    Order_ID INT NOT NULL,
    Product_ID INT NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID) ON DELETE CASCADE,
    FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) ON DELETE RESTRICT,
    INDEX idx_order (Order_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery Assignments table
-- Tracks which delivery personnel is assigned to each order
CREATE TABLE IF NOT EXISTS delivery_assignments (
    Assignment_ID INT AUTO_INCREMENT PRIMARY KEY,
    Order_ID INT NOT NULL,
    delivery_person_name VARCHAR(255) NOT NULL,
    vehicle_info VARCHAR(255),
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    dispatched_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID) ON DELETE CASCADE,
    INDEX idx_order (Order_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order Status History table (optional - for audit trail)
CREATE TABLE IF NOT EXISTS order_status_history (
    History_ID INT AUTO_INCREMENT PRIMARY KEY,
    Order_ID INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    changed_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES app_users(User_ID),
    INDEX idx_order (Order_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Function to generate order number (optional - can be done in PHP)
-- Format: ORD-YYYYMMDD-XXXX (e.g., ORD-20250125-0001)
