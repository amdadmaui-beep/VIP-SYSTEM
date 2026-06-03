<?php
/**
 * Add Missing Foreign Keys for Data Integrity
 * Execute: php database/add_foreign_keys.php (from capstone/)
 * 
 * This script adds foreign key constraints to ensure referential integrity
 * across the database. It handles errors gracefully and skips existing keys.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

function runMigration($conn, $sql, $desc) {
    try {
        $conn->exec($sql);
        echo "[OK] $desc\n";
        return true;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate') !== false ||
            strpos($msg, 'already exists') !== false ||
            strpos($msg, 'ER_DUP_KEY') !== false ||
            strpos($msg, 'ER_FK_DUP_NAME') !== false) {
            echo "[SKIP] $desc - already exists\n";
            return true;
        }
        if (strpos($msg, 'Cannot add or update a child row') !== false ||
            strpos($msg, 'ER_NO_REFERENCED_ROW') !== false ||
            strpos($msg, 'ER_ROW_IS_REFERENCED') !== false) {
            echo "[WARN] $desc - data inconsistency detected: " . $e->getMessage() . "\n";
            return false;
        }
        echo "[ERROR] $desc: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "Adding Missing Foreign Keys\n";
echo str_repeat("=", 50) . "\n\n";

// ============================================
// SALE_DETAILS TABLE FOREIGN KEYS
// ============================================

// sale_details -> sales (Sale_ID)
runMigration($conn,
    "ALTER TABLE sale_details 
     ADD CONSTRAINT fk_sale_details_sales 
     FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: sale_details.Sale_ID -> sales.Sale_ID"
);

// sale_details -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE sale_details 
     ADD CONSTRAINT fk_sale_details_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: sale_details.Product_ID -> products.Product_ID"
);

// ============================================
// ORDER_DETAILS TABLE FOREIGN KEYS
// ============================================

// order_details -> orders (Order_ID)
runMigration($conn,
    "ALTER TABLE order_details 
     ADD CONSTRAINT fk_order_details_orders 
     FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: order_details.Order_ID -> orders.Order_ID"
);

// order_details -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE order_details 
     ADD CONSTRAINT fk_order_details_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: order_details.Product_ID -> products.Product_ID"
);

// ============================================
// DELIVERY_DETAIL TABLE FOREIGN KEYS
// ============================================

// delivery_detail -> delivery (Delivery_ID)
runMigration($conn,
    "ALTER TABLE delivery_detail 
     ADD CONSTRAINT fk_delivery_detail_delivery 
     FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: delivery_detail.Delivery_ID -> delivery.Delivery_ID"
);

// delivery_detail -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE delivery_detail 
     ADD CONSTRAINT fk_delivery_detail_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: delivery_detail.Product_ID -> products.Product_ID"
);

// ============================================
// ACCOUNT_RECEIVABLE TABLE FOREIGN KEYS
// ============================================

// account_receivable -> customers (Customer_ID)
runMigration($conn,
    "ALTER TABLE account_receivable 
     ADD CONSTRAINT fk_ar_customers 
     FOREIGN KEY (Customer_ID) REFERENCES customers(Customer_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: account_receivable.Customer_ID -> customers.Customer_ID"
);

// account_receivable -> sales (Sale_ID) - if column exists and is not nullable in some cases
// Note: This might be nullable, so we use SET NULL
runMigration($conn,
    "ALTER TABLE account_receivable 
     ADD CONSTRAINT fk_ar_sales 
     FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: account_receivable.Sale_ID -> sales.Sale_ID"
);

// ============================================
// PRODUCTIONS TABLE FOREIGN KEYS
// ============================================

// productions -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE productions 
     ADD CONSTRAINT fk_productions_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: productions.Product_ID -> products.Product_ID"
);

// productions -> user (produced_by) - if column exists
runMigration($conn,
    "ALTER TABLE productions 
     ADD CONSTRAINT fk_productions_users 
     FOREIGN KEY (produced_by) REFERENCES user(User_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: productions.produced_by -> user.User_ID"
);

// ============================================
// STOCKIN_INVENTORY TABLE FOREIGN KEYS
// ============================================

// stockin_inventory -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE stockin_inventory 
     ADD CONSTRAINT fk_stockin_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: stockin_inventory.Product_ID -> products.Product_ID"
);

// ============================================
// DELIVERY TABLE FOREIGN KEYS
// ============================================

// delivery -> orders (Order_ID)
runMigration($conn,
    "ALTER TABLE delivery 
     ADD CONSTRAINT fk_delivery_orders 
     FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: delivery.Order_ID -> orders.Order_ID"
);

// delivery -> customers (Customer_ID) - if exists
runMigration($conn,
    "ALTER TABLE delivery 
     ADD CONSTRAINT fk_delivery_customers 
     FOREIGN KEY (Customer_ID) REFERENCES customers(Customer_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: delivery.Customer_ID -> customers.Customer_ID"
);

// ============================================
// SALES TABLE FOREIGN KEYS
// ============================================

// sales -> customers (Customer_ID)
runMigration($conn,
    "ALTER TABLE sales 
     ADD CONSTRAINT fk_sales_customers 
     FOREIGN KEY (Customer_ID) REFERENCES customers(Customer_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: sales.Customer_ID -> customers.Customer_ID"
);

// sales -> user (User_ID)
runMigration($conn,
    "ALTER TABLE sales 
     ADD CONSTRAINT fk_sales_users 
     FOREIGN KEY (User_ID) REFERENCES user(User_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: sales.User_ID -> user.User_ID"
);

// ============================================
// ORDERS TABLE FOREIGN KEYS
// ============================================

// orders -> customers (Customer_ID)
runMigration($conn,
    "ALTER TABLE orders 
     ADD CONSTRAINT fk_orders_customers 
     FOREIGN KEY (Customer_ID) REFERENCES customers(Customer_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: orders.Customer_ID -> customers.Customer_ID"
);

// orders -> user (created_by) - if exists
runMigration($conn,
    "ALTER TABLE orders 
     ADD CONSTRAINT fk_orders_users 
     FOREIGN KEY (created_by) REFERENCES user(User_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: orders.created_by -> user.User_ID"
);

// ============================================
// ADJUSTMENT_DETAILS TABLE FOREIGN KEYS
// ============================================

// adjustment_details -> adjustments (Adjustment_ID)
runMigration($conn,
    "ALTER TABLE adjustment_details 
     ADD CONSTRAINT fk_adj_details_adjustments 
     FOREIGN KEY (Adjustment_ID) REFERENCES adjustments(Adjustment_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: adjustment_details.Adjustment_ID -> adjustments.Adjustment_ID"
);

// adjustment_details -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE adjustment_details 
     ADD CONSTRAINT fk_adj_details_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: adjustment_details.Product_ID -> products.Product_ID"
);

// ============================================
// ADJUSTMENTS TABLE FOREIGN KEYS
// ============================================

// adjustments -> user (created_by) - if exists
runMigration($conn,
    "ALTER TABLE adjustments 
     ADD CONSTRAINT fk_adjustments_users 
     FOREIGN KEY (created_by) REFERENCES user(User_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: adjustments.created_by -> user.User_ID"
);

// ============================================
// MANUAL_ADJUSTMENT TABLE FOREIGN KEYS
// ============================================

// manual_adjustment -> products (Product_ID)
runMigration($conn,
    "ALTER TABLE manual_adjustment 
     ADD CONSTRAINT fk_manual_adj_products 
     FOREIGN KEY (Product_ID) REFERENCES products(Product_ID) 
     ON DELETE RESTRICT 
     ON UPDATE CASCADE",
    "FK: manual_adjustment.Product_ID -> products.Product_ID"
);

// manual_adjustment -> user (adjusted_by) - if exists
runMigration($conn,
    "ALTER TABLE manual_adjustment 
     ADD CONSTRAINT fk_manual_adj_users 
     FOREIGN KEY (adjusted_by) REFERENCES user(User_ID) 
     ON DELETE SET NULL 
     ON UPDATE CASCADE",
    "FK: manual_adjustment.adjusted_by -> user.User_ID"
);

// ============================================
// SALE_SOURCE TABLE FOREIGN KEYS (if exists)
// ============================================

// sale_source -> sales (Sale_ID)
runMigration($conn,
    "ALTER TABLE sale_source 
     ADD CONSTRAINT fk_sale_source_sales 
     FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: sale_source.Sale_ID -> sales.Sale_ID"
);

// sale_source -> delivery (Delivery_ID)
runMigration($conn,
    "ALTER TABLE sale_source 
     ADD CONSTRAINT fk_sale_source_delivery 
     FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) 
     ON DELETE CASCADE 
     ON UPDATE CASCADE",
    "FK: sale_source.Delivery_ID -> delivery.Delivery_ID"
);

// ============================================
// ACTIVITY_LOGS TABLE FOREIGN KEYS
// ============================================

// activity_logs already has FK in run_rider_migrations.php
// Just adding index if not exists
runMigration($conn,
    "ALTER TABLE activity_logs 
     ADD INDEX idx_activity_logs_user (User_ID)",
    "Index: activity_logs.User_ID"
);

echo "\n" . str_repeat("=", 50) . "\n";
echo "Foreign Key Migration Complete\n";
echo str_repeat("=", 50) . "\n";
