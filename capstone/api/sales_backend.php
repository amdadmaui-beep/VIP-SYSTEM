<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle different sales operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 1;

    switch ($action) {
        case 'create_sale_from_delivery':
            handleCreateSaleFromDelivery($conn, $user_id);
            break;
        case 'create_walkin_sale':
            handleCreateWalkinSale($conn, $user_id);
            break;
        default:
            header("Location: ../pages/sales.php?error=Invalid action");
            exit();
    }
}

/**
 * Returns a default Customer_ID for walk-in sales.
 * Creates a "Walk-in Customer" record if it doesn't exist.
 *
 * This is needed when the `sales` table requires Customer_ID (NOT NULL),
 * but the POS UI does not ask the cashier to pick a customer.
 */
function getOrCreateWalkinCustomerId($conn) {
    // Ensure customers table exists
    $t = $conn->query("SHOW TABLES LIKE 'customers'");
    if (!$t || $t->num_rows === 0) {
        if ($t) $t->close();
        return 0;
    }
    $t->close();

    // Look for existing record by name
    $name = 'Walk-in Customer';
    $stmt = $conn->prepare("SELECT Customer_ID FROM customers WHERE customer_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $stmt->close();
            return intval($row['Customer_ID']);
        }
        $stmt->close();
    }

    // Create record (customers.phone_number is NOT NULL in your schema)
    $phone = 'N/A';
    $address = '';
    $type = 'Regular';

    // Check which columns exist in customers table to avoid column errors
    $cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM customers");
    if ($cr) {
        while ($r = $cr->fetch_assoc()) {
            $cols[] = $r['Field'];
        }
        $cr->close();
    }

    $fields = [];
    $values = [];
    $params = [];
    $types = "";

    if (in_array('customer_name', $cols)) {
        $fields[] = 'customer_name';
        $values[] = '?';
        $params[] = $name;
        $types .= 's';
    }
    if (in_array('phone_number', $cols)) {
        $fields[] = 'phone_number';
        $values[] = '?';
        $params[] = $phone;
        $types .= 's';
    }
    if (in_array('address', $cols)) {
        $fields[] = 'address';
        $values[] = '?';
        $params[] = $address;
        $types .= 's';
    }
    if (in_array('type', $cols)) {
        $fields[] = 'type';
        $values[] = '?';
        $params[] = $type;
        $types .= 's';
    }

    if (empty($fields)) {
        return 0;
    }

    $sql = "INSERT INTO customers (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
    $ins = $conn->prepare($sql);
    if (!$ins) return 0;
    $ins->bind_param($types, ...$params);
    if (!$ins->execute()) {
        $ins->close();
        return 0;
    }
    $new_id = $conn->insert_id;
    $ins->close();
    return intval($new_id);
}

/**
 * Update the linked order after a successful sale.
 * This must be defensive because some databases use different column names
 * (e.g. `status` vs `order_status`) and may not have `completed_at` or `Sale_ID`.
 */
function updateOrderAfterSale($conn, $order_id, $sale_id) {
    $order_id = intval($order_id);
    $sale_id = intval($sale_id);
    if ($order_id <= 0) return;

    $cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM orders");
    if (!$cr) return;
    while ($r = $cr->fetch_assoc()) {
        $cols[] = $r['Field'];
    }
    $cr->close();

    $setParts = [];
    $params = [];
    $types = "";

    // status column can be order_status or status
    if (in_array('order_status', $cols)) {
        $setParts[] = "order_status = ?";
        $params[] = 'Completed';
        $types .= "s";
    } elseif (in_array('status', $cols)) {
        $setParts[] = "status = ?";
        $params[] = 'Completed';
        $types .= "s";
    }

    // link sale if column exists
    if (in_array('Sale_ID', $cols)) {
        $setParts[] = "Sale_ID = ?";
        $params[] = $sale_id;
        $types .= "i";
    }

    // only set completed_at if the column exists
    if (in_array('completed_at', $cols)) {
        $setParts[] = "completed_at = NOW()";
    }

    if (in_array('updated_at', $cols)) {
        $setParts[] = "updated_at = NOW()";
    }

    if (empty($setParts)) return;

    $sql = "UPDATE orders SET " . implode(', ', $setParts) . " WHERE Order_ID = ?";
    $params[] = $order_id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Create sale from a delivered order (pre-order with wholesale pricing)
 * This is called when payment is received from delivery rider
 */
function handleCreateSaleFromDelivery($conn, $user_id) {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $delivery_details = json_decode($_POST['delivery_details'] ?? '[]', true);
    $remarks = trim($_POST['remarks'] ?? '');
    
    if (empty($delivery_id) || empty($delivery_details) || !is_array($delivery_details)) {
        header("Location: ../pages/sales.php?error=Invalid delivery information");
        exit();
    }
    
    // Get delivery information
    $delivery_stmt = $conn->prepare("SELECT d.*, o.Order_ID, o.Customer_ID 
                                     FROM delivery d 
                                     LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                     WHERE d.Delivery_ID = ?");
    $delivery_stmt->bind_param("i", $delivery_id);
    $delivery_stmt->execute();
    $delivery_result = $delivery_stmt->get_result();
    
    if ($delivery_result->num_rows === 0) {
        $delivery_stmt->close();
        header("Location: ../pages/sales.php?error=Delivery not found");
        exit();
    }
    
    $delivery = $delivery_result->fetch_assoc();
    $delivery_stmt->close();
    
    $conn->begin_transaction();
    try {
        // Check which columns exist in sales table
        $columns_result = $conn->query("SHOW COLUMNS FROM sales");
        $existing_columns = [];
        while ($row = $columns_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        $columns_result->close();
        
        $has_status = in_array('status', $existing_columns);
        $has_remarks = in_array('remarks', $existing_columns);
        $has_user_id = in_array('User_ID', $existing_columns) || in_array('user_id', $existing_columns);
        $has_created_by = in_array('created_by', $existing_columns);
        $has_customer_id = in_array('Customer_ID', $existing_columns) || in_array('customer_id', $existing_columns);
        
        // Build INSERT statement based on existing columns
        $insert_fields = [];
        $insert_values = [];
        $bind_params = [];
        $bind_types = "";

        // Cashier / recorded-by user
        if (in_array('User_ID', $existing_columns)) {
            $insert_fields[] = 'User_ID';
            $insert_values[] = '?';
            $bind_params[] = intval($user_id);
            $bind_types .= "i";
        } elseif (in_array('user_id', $existing_columns)) {
            $insert_fields[] = 'user_id';
            $insert_values[] = '?';
            $bind_params[] = intval($user_id);
            $bind_types .= "i";
        }
        
        // Some schemas use created_by instead of User_ID/user_id
        if ($has_created_by) {
            $insert_fields[] = 'created_by';
            $insert_values[] = '?';
            $bind_params[] = intval($user_id);
            $bind_types .= "i";
        }

        // Customer_ID (required in some DBs)
        if ($has_customer_id) {
            $cid = intval($delivery['Customer_ID'] ?? 0);
            if ($cid <= 0) {
                $cid = getOrCreateWalkinCustomerId($conn);
            }

            if (in_array('Customer_ID', $existing_columns)) {
                $insert_fields[] = 'Customer_ID';
                $insert_values[] = '?';
                $bind_params[] = $cid;
                $bind_types .= "i";
            } elseif (in_array('customer_id', $existing_columns)) {
                $insert_fields[] = 'customer_id';
                $insert_values[] = '?';
                $bind_params[] = $cid;
                $bind_types .= "i";
            }
        }

        // Some DBs don't have sales.Delivery_ID. In that case, we rely on sale_source.
        if (in_array('Delivery_ID', $existing_columns)) {
            $insert_fields[] = 'Delivery_ID';
            $insert_values[] = '?';
            $bind_params[] = $delivery_id;
            $bind_types .= "i";
        } elseif (in_array('delivery_id', $existing_columns)) {
            $insert_fields[] = 'delivery_id';
            $insert_values[] = '?';
            $bind_params[] = $delivery_id;
            $bind_types .= "i";
        }
        
        if ($has_status) {
            $insert_fields[] = 'status';
            $insert_values[] = "'Completed'";
        }
        
        if ($has_remarks) {
            $insert_fields[] = 'remarks';
            $insert_values[] = '?';
            $bind_params[] = $remarks;
            $bind_types .= "s";
        }
        
        if (in_array('created_at', $existing_columns)) {
            $insert_fields[] = 'created_at';
            $insert_values[] = 'NOW()';
        }

        // Fallback: if the table has no optional columns, insert at least one column (prevents invalid SQL)
        if (empty($insert_fields)) {
            // If sales table has an auto PK only, allow empty insert
            $sql = "INSERT INTO sales () VALUES ()";
            $sale_stmt = $conn->prepare($sql);
        } else {
            $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
            $sale_stmt = $conn->prepare($sql);
        }
        
        if (!empty($bind_types)) {
            $sale_stmt->bind_param($bind_types, ...$bind_params);
        }
        
        if (!$sale_stmt->execute()) {
            throw new Exception("Failed to create sale: " . $sale_stmt->error);
        }
        
        $sale_id = $conn->insert_id;
        $sale_stmt->close();
        
        // Create sale_source link
        $source_stmt = $conn->prepare("INSERT INTO sale_source (Delivery_ID, Sale_ID) VALUES (?, ?)");
        $source_stmt->bind_param("ii", $delivery_id, $sale_id);
        $source_stmt->execute();
        $source_stmt->close();
        
        // Process each delivery detail
        foreach ($delivery_details as $detail) {
            $delivery_detail_id = intval($detail['delivery_detail_id'] ?? 0);
            $order_detail_id = intval($detail['order_detail_id'] ?? 0);
            $received_qty = floatval($detail['received_qty'] ?? 0);
            $damage_qty = floatval($detail['damage_qty'] ?? 0);
            $product_id = intval($detail['product_id'] ?? 0);
            
            if ($received_qty <= 0) continue; // Skip if nothing was received
            
            // Get order detail to determine wholesale price
            $order_detail_stmt = $conn->prepare("SELECT unit_price FROM order_details WHERE Order_detail_ID = ?");
            $order_detail_stmt->bind_param("i", $order_detail_id);
            $order_detail_stmt->execute();
            $order_detail_result = $order_detail_stmt->get_result();
            
            if ($order_detail_result->num_rows === 0) {
                $order_detail_stmt->close();
                continue; // Skip if order detail not found
            }
            
            $order_detail = $order_detail_result->fetch_assoc();
            $unit_price = floatval($order_detail['unit_price']); // Wholesale price from order
            $order_detail_stmt->close();
            
            // Calculate sale quantity (received - damaged)
            $sale_qty = max(0, $received_qty - $damage_qty);
            
            if ($sale_qty > 0) {
                // Create sale detail
                $subtotal = $sale_qty * $unit_price;
                $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (
                    Sale_ID, Product_ID, quantity, unit_price, subtotal
                ) VALUES (?, ?, ?, ?, ?)");
                $sale_detail_stmt->bind_param("iiddd", $sale_id, $product_id, $sale_qty, $unit_price, $subtotal);
                
                if (!$sale_detail_stmt->execute()) {
                    throw new Exception("Failed to create sale detail: " . $sale_detail_stmt->error);
                }
                $sale_detail_stmt->close();
                
                // REDUCE INVENTORY (only when payment is received)
                deductInventory($conn, $product_id, $sale_qty);
            }
        }
        
        // Update delivery_detail records to mark them as sold
        foreach ($delivery_details as $detail) {
            $delivery_detail_id = intval($detail['delivery_detail_id'] ?? 0);
            if ($delivery_detail_id > 0) {
                $update_delivery_detail = $conn->prepare("UPDATE delivery_detail SET 
                    status = 'Delivered', updated_at = NOW()
                    WHERE Delivery_Detail_ID = ?");
                $update_delivery_detail->bind_param("i", $delivery_detail_id);
                $update_delivery_detail->execute();
                $update_delivery_detail->close();
            }
        }
        
        // Update order status to Completed and link to sale (defensive per-schema)
        if (!empty($delivery['Order_ID'])) {
            updateOrderAfterSale($conn, intval($delivery['Order_ID']), intval($sale_id));
        }
        
        $conn->commit();
        header("Location: ../pages/sales.php?success=Sale recorded successfully. Inventory updated.");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Sale creation error: " . $e->getMessage());
        header("Location: ../pages/sales.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

/**
 * Create walk-in sale (retail pricing, no order)
 */
function handleCreateWalkinSale($conn, $user_id) {
    $items = json_decode($_POST['items'] ?? '[]', true);
    $remarks = trim($_POST['remarks'] ?? '');
    
    if (empty($items) || !is_array($items) || count($items) === 0) {
        header("Location: ../pages/sales.php?error=At least one item is required");
        exit();
    }
    
    $conn->begin_transaction();
    try {
        // Check which columns exist in sales table
        $columns_result = $conn->query("SHOW COLUMNS FROM sales");
        $existing_columns = [];
        while ($row = $columns_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        $columns_result->close();
        
        $has_status = in_array('status', $existing_columns);
        $has_remarks = in_array('remarks', $existing_columns);
        $has_user_id = in_array('User_ID', $existing_columns) || in_array('user_id', $existing_columns);
        $has_created_by = in_array('created_by', $existing_columns);
        $has_customer_id = in_array('Customer_ID', $existing_columns) || in_array('customer_id', $existing_columns);
        
        // Build INSERT statement based on existing columns (no delivery_id for walk-ins)
        $insert_fields = [];
        $insert_values = [];
        $bind_params = [];
        $bind_types = "";

        // Cashier / recorded-by user
        if (in_array('User_ID', $existing_columns)) {
            $insert_fields[] = 'User_ID';
            $insert_values[] = '?';
            $bind_params[] = intval($user_id);
            $bind_types .= "i";
        } elseif (in_array('user_id', $existing_columns)) {
            $insert_fields[] = 'user_id';
            $insert_values[] = '?';
            $bind_params[] = intval($user_id);
            $bind_types .= "i";
        }
        
        // Some schemas use created_by instead of User_ID/user_id
        if ($has_created_by) {
            $insert_fields[] = 'created_by';
            $insert_values[] = '?';
            $bind_params[] = intval($user_id);
            $bind_types .= "i";
        }

        // Customer_ID (required in some DBs) -> use default Walk-in Customer
        if ($has_customer_id) {
            $cid = getOrCreateWalkinCustomerId($conn);
            if (in_array('Customer_ID', $existing_columns)) {
                $insert_fields[] = 'Customer_ID';
                $insert_values[] = '?';
                $bind_params[] = $cid;
                $bind_types .= "i";
            } elseif (in_array('customer_id', $existing_columns)) {
                $insert_fields[] = 'customer_id';
                $insert_values[] = '?';
                $bind_params[] = $cid;
                $bind_types .= "i";
            }
        }
        
        if ($has_status) {
            $insert_fields[] = 'status';
            $insert_values[] = "'Completed'";
        }
        
        if ($has_remarks) {
            $insert_fields[] = 'remarks';
            $insert_values[] = '?';
            $bind_params[] = $remarks;
            $bind_types .= "s";
        }
        
        if (in_array('created_at', $existing_columns)) {
            $insert_fields[] = 'created_at';
            $insert_values[] = 'NOW()';
        }

        // Fallback: allow empty insert if table only has auto PK
        if (empty($insert_fields)) {
            $sql = "INSERT INTO sales () VALUES ()";
            $sale_stmt = $conn->prepare($sql);
        } else {
            $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
            $sale_stmt = $conn->prepare($sql);
        }
        
        if (!empty($bind_types)) {
            $sale_stmt->bind_param($bind_types, ...$bind_params);
        }
        
        if (!$sale_stmt->execute()) {
            throw new Exception("Failed to create sale: " . $sale_stmt->error);
        }
        
        $sale_id = $conn->insert_id;
        $sale_stmt->close();
        
        // Process each item with retail pricing
        foreach ($items as $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = floatval($item['quantity'] ?? 0);
            
            if ($quantity <= 0) continue;
            
            // Get retail price
            $product_stmt = $conn->prepare("SELECT retail_price FROM products WHERE Product_ID = ?");
            $product_stmt->bind_param("i", $product_id);
            $product_stmt->execute();
            $product_result = $product_stmt->get_result();
            
            if ($product_result->num_rows === 0) {
                $product_stmt->close();
                continue;
            }
            
            $product = $product_result->fetch_assoc();
            $unit_price = floatval($product['retail_price']); // Retail price for walk-ins
            $product_stmt->close();
            
            $subtotal = $quantity * $unit_price;
            
            // Create sale detail
            $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (
                Sale_ID, Product_ID, quantity, unit_price, subtotal
            ) VALUES (?, ?, ?, ?, ?)");
            $sale_detail_stmt->bind_param("iiddd", $sale_id, $product_id, $quantity, $unit_price, $subtotal);
            
            if (!$sale_detail_stmt->execute()) {
                throw new Exception("Failed to create sale detail: " . $sale_detail_stmt->error);
            }
            $sale_detail_stmt->close();
            
            // REDUCE INVENTORY (only when payment is received)
            deductInventory($conn, $product_id, $quantity);
        }
        
        $conn->commit();
        header("Location: ../pages/sales.php?success=Walk-in sale recorded successfully. Inventory updated.");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Walk-in sale creation error: " . $e->getMessage());
        header("Location: ../pages/sales.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

/**
 * Deduct inventory when payment is received
 */
function deductInventory($conn, $product_id, $quantity) {
    // Get current inventory
    $inv_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory
        WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
    $inv_stmt->bind_param("i", $product_id);
    $inv_stmt->execute();
    $inv_result = $inv_stmt->get_result();
    
    if ($inv_result->num_rows > 0) {
        $inv = $inv_result->fetch_assoc();
        $new_quantity = max(0, $inv['quantity'] - $quantity);
        
        // Update inventory
        $update_stmt = $conn->prepare("UPDATE stockin_inventory
            SET quantity = ?, updated_at = NOW()
            WHERE Inventory_ID = ?");
        $update_stmt->bind_param("di", $new_quantity, $inv['Inventory_ID']);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    $inv_stmt->close();
}
