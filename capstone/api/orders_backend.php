<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle different order operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 1;

    switch ($action) {
        case 'create_order':
            handleCreateOrder($conn, $user_id);
            break;
        case 'update_status':
            handleUpdateStatus($conn, $user_id);
            break;
        case 'assign_delivery':
            handleAssignDelivery($conn);
            break;
        case 'cancel_order':
            handleCancelOrder($conn, $user_id);
            break;
        default:
            header("Location: ../pages/orders.php?error=Invalid action");
            exit();
    }
}

/**
 * Ensure `delivery_detail` rows exist for a delivery.
 * Creates one row per `order_details` item if missing.
 */
function ensureDeliveryDetails($conn, $delivery_id, $order_id) {
    if (empty($delivery_id) || empty($order_id)) {
        return;
    }

    // Check required tables exist
    $t1 = $conn->query("SHOW TABLES LIKE 'delivery_detail'");
    $t2 = $conn->query("SHOW TABLES LIKE 'order_details'");
    if (!$t1 || $t1->num_rows === 0 || !$t2 || $t2->num_rows === 0) {
        if ($t1) $t1->close();
        if ($t2) $t2->close();
        return;
    }
    $t1->close();
    $t2->close();

    // Insert missing delivery_detail rows for each order_details row
    $sql = "
        INSERT INTO delivery_detail (Delivery_ID, Order_detail_ID, received_qty, damage_qty, status, created_at, updated_at)
        SELECT
            ?, od.Order_detail_ID, od.ordered_qty, 0, 'Pending', NOW(), NOW()
        FROM order_details od
        LEFT JOIN delivery_detail dd
          ON dd.Delivery_ID = ? AND dd.Order_detail_ID = od.Order_detail_ID
        WHERE od.Order_ID = ? AND dd.Delivery_Detail_ID IS NULL
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("ensureDeliveryDetails prepare failed: " . $conn->error);
        return;
    }
    $stmt->bind_param("iii", $delivery_id, $delivery_id, $order_id);
    if (!$stmt->execute()) {
        error_log("ensureDeliveryDetails execute failed: " . $stmt->error);
    }
    $stmt->close();
}

function handleCreateOrder($conn, $user_id) {
    $customer_id = intval($_POST['customer_id']);
    $order_date = $_POST['order_date'];
    $order_time = $_POST['order_time'] ?? date('H:i:s');
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $delivery_date = $_POST['delivery_date'] ?? null;
    $delivery_time = $_POST['delivery_time'] ?? null;
    $delivery_person = trim($_POST['delivery_person'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $items = json_decode($_POST['items'], true);
    
    $errors = [];
    if (empty($customer_id)) $errors[] = "Customer is required.";
    if (empty($order_date)) $errors[] = "Order date is required.";
    if (empty($items) || !is_array($items) || count($items) === 0) {
        $errors[] = "At least one item is required.";
    }
    
    if (!empty($errors)) {
        $_SESSION['order_errors'] = $errors;
        header("Location: ../pages/orders.php?error=" . urlencode(implode(', ', $errors)));
        exit();
    }
    
    // Calculate total amount
    $total_amount = 0;
    foreach ($items as $item) {
        $quantity = floatval($item['quantity']);
        $unit_price = floatval($item['unit_price']);
        $total_amount += $quantity * $unit_price;
    }
    
    $conn->begin_transaction();
    try {
        // Check which columns exist in the orders table
        $columns_result = $conn->query("SHOW COLUMNS FROM orders");
        $existing_columns = [];
        $order_status_type = null;
        while ($row = $columns_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
            if ($row['Field'] === 'order_status') {
                $order_status_type = $row['Type'];
            }
        }
        $columns_result->close();
        
        // Determine the correct order_status value based on column type
        $order_status_value = 'pending'; // Default status for new orders
        
        // If it's an ENUM, make sure we use an exact match
        if (strpos(strtolower($order_status_type), 'enum') !== false) {
            // Extract ENUM values
            preg_match("/enum\s*\((.+)\)/i", $order_status_type, $matches);
            if (!empty($matches[1])) {
                $enum_values = array_map(function($v) {
                    return trim($v, " '\"");
                }, explode(',', $matches[1]));
                // Check if 'Requested' is in the enum, otherwise use the first value
                if (!in_array('Requested', $enum_values)) {
                    $order_status_value = !empty($enum_values) ? $enum_values[0] : 'Requested';
                }
            }
        } elseif (strpos(strtolower($order_status_type), 'varchar') !== false) {
            // If it's VARCHAR, extract the length and truncate if needed
            preg_match("/varchar\s*\((\d+)\)/i", $order_status_type, $matches);
            if (!empty($matches[1])) {
                $max_length = intval($matches[1]);
                if (strlen($order_status_value) > $max_length) {
                    $order_status_value = substr($order_status_value, 0, $max_length);
                }
            }
        }
        $insert_fields = ['Customer_ID', 'order_date', 'order_status', 'total_amount', 'remarks'];
        $insert_values = ['?', '?', '?', '?', '?']; // Use bind parameter for order_status too
        $bind_params = [$customer_id, $order_date, $order_status_value, $total_amount, $notes];
        $bind_types = "issds"; // i=Customer_ID, s=order_date, s=order_status, d=total_amount, s=remarks
        
        // Conditionally add optional columns if they exist
        if (in_array('order_time', $existing_columns)) {
            $insert_fields[] = 'order_time';
            $insert_values[] = '?';
            $bind_params[] = $order_time;
            $bind_types .= 's';
        }
        
        if (in_array('delivery_address', $existing_columns)) {
            $insert_fields[] = 'delivery_address';
            $insert_values[] = '?';
            $bind_params[] = $delivery_address;
            $bind_types .= 's';
        }
        
        if (in_array('delivery_date', $existing_columns) && !empty($delivery_date)) {
            $insert_fields[] = 'delivery_date';
            $insert_values[] = '?';
            $bind_params[] = $delivery_date;
            $bind_types .= 's';
        }
        
        if (in_array('created_by', $existing_columns)) {
            $insert_fields[] = 'created_by';
            $insert_values[] = '?';
            $bind_params[] = $user_id;
            $bind_types .= 'i';
        }
        
        // Build and execute the INSERT statement
        $sql = "INSERT INTO orders (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        // Validate that bind types match bind params count
        $type_count = strlen($bind_types);
        $param_count = count($bind_params);
        if ($type_count !== $param_count) {
            error_log("Bind param mismatch: types='$bind_types' ($type_count), params=$param_count. SQL: $sql");
            throw new Exception("Internal error: Parameter count mismatch. Please contact support.");
        }
        
        $stmt->bind_param($bind_types, ...$bind_params);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create order: " . $stmt->error);
        }
        
        $order_id = $conn->insert_id;
        $stmt->close();
        
        // Insert order details
        $item_stmt = $conn->prepare("INSERT INTO order_details (
            Order_ID, Product_ID, ordered_qty, unit_price
        ) VALUES (?, ?, ?, ?)");
        
        foreach ($items as $item) {
            $product_id = intval($item['product_id']);
            $quantity = floatval($item['quantity']);
            $unit_price = floatval($item['unit_price']);
            
            $item_stmt->bind_param("iidd", $order_id, $product_id, $quantity, $unit_price);
            if (!$item_stmt->execute()) {
                throw new Exception("Failed to insert order detail: " . $item_stmt->error);
            }
        }
        $item_stmt->close();
        
        // If delivery person is provided, create delivery record (if delivery table exists)
        if (!empty($delivery_person)) {
            // Check if delivery table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'delivery'");
            if ($table_check && $table_check->num_rows > 0) {
                $table_check->close();
                // Get customer name for delivered_to
                $customer_stmt = $conn->prepare("SELECT customer_name FROM customers WHERE Customer_ID = ?");
                $customer_stmt->bind_param("i", $customer_id);
                $customer_stmt->execute();
                $customer_result = $customer_stmt->get_result();
                $customer_name = '';
                if ($customer_result->num_rows > 0) {
                    $customer_data = $customer_result->fetch_assoc();
                    $customer_name = $customer_data['customer_name'];
                }
                $customer_stmt->close();
                
                // Check delivery table columns
                $delivery_cols = $conn->query("SHOW COLUMNS FROM delivery");
                $delivery_columns = [];
                while ($row = $delivery_cols->fetch_assoc()) {
                    $delivery_columns[] = $row['Field'];
                }
                $delivery_cols->close();
                
                $delivery_fields = ['Order_ID'];
                $delivery_values = ['?'];
                $delivery_params = [$order_id];
                $delivery_types = "i";
                
                if (in_array('delivery_address', $delivery_columns)) {
                    $delivery_fields[] = 'delivery_address';
                    $delivery_values[] = '?';
                    $delivery_params[] = $delivery_address;
                    $delivery_types .= 's';
                }
                if (in_array('schedule_date', $delivery_columns)) {
                    $delivery_fields[] = 'schedule_date';
                    $delivery_values[] = '?';
                    $delivery_params[] = $delivery_date;
                    $delivery_types .= 's';
                }
                if (in_array('delivery_status', $delivery_columns)) {
                    $delivery_fields[] = 'delivery_status';
                    $delivery_values[] = "'Scheduled'";
                }
                if (in_array('delivered_by', $delivery_columns)) {
                    $delivery_fields[] = 'delivered_by';
                    $delivery_values[] = '?';
                    $delivery_params[] = $delivery_person;
                    $delivery_types .= 's';
                }
                if (in_array('delivered_to', $delivery_columns)) {
                    $delivery_fields[] = 'delivered_to';
                    $delivery_values[] = '?';
                    $delivery_params[] = $customer_name;
                    $delivery_types .= 's';
                }
                
                $delivery_sql = "INSERT INTO delivery (" . implode(', ', $delivery_fields) . ") VALUES (" . implode(', ', $delivery_values) . ")";
                $delivery_stmt = $conn->prepare($delivery_sql);
                if ($delivery_stmt) {
                    $delivery_stmt->bind_param($delivery_types, ...$delivery_params);
                    if (!$delivery_stmt->execute()) {
                        error_log("Warning: Failed to assign delivery person: " . $delivery_stmt->error);
                        // Don't throw exception, just log the warning
                    }
                    $delivery_stmt->close();
                }
            }
        }
        
        // Log status change
        logStatusChange($conn, $order_id, null, $order_status_value, $user_id, 'Order created');
        
        $conn->commit();
        header("Location: ../pages/orders.php?success=Order created successfully&order_id=" . $order_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order creation error: " . $e->getMessage());
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function handleUpdateStatus($conn, $user_id) {
    // Debug: Log received data
    error_log("Update status received: " . print_r($_POST, true));
    
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $delivery_date = $_POST['delivery_date'] ?? null;
    $delivery_person = trim($_POST['delivery_person'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate required fields
    if (empty($order_id) || empty($new_status)) {
        error_log("Missing required fields: order_id=$order_id, new_status=$new_status");
        header("Location: ../pages/orders.php?error=" . urlencode("Missing required information"));
        exit();
    }
    
    // Get current status and validate against actual database ENUM
    $check_stmt = $conn->prepare("SELECT order_status FROM orders WHERE Order_ID = ?");
    if (!$check_stmt) {
        header("Location: ../pages/orders.php?error=Order not found");
        exit();
    }
    $check_stmt->bind_param("i", $order_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows === 0) {
        $check_stmt->close();
        header("Location: ../pages/orders.php?error=Order not found");
        exit();
    }
    $order = $result->fetch_assoc();
    $old_status = $order['order_status'];
    $check_stmt->close();
    
    $conn->begin_transaction();
    try {
        // Check which columns exist in the orders table and get ENUM values
        $columns_result = $conn->query("SHOW COLUMNS FROM orders");
        $existing_columns = [];
        $order_status_type = null;
        $status_column_name = null;
        while ($row = $columns_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
            if ($row['Field'] === 'order_status' || $row['Field'] === 'status') {
                $order_status_type = $row['Type'];
                $status_column_name = $row['Field'];
            }
        }
        $columns_result->close();
        
        // Validate new_status against actual database ENUM values
        if ($order_status_type && strpos(strtolower($order_status_type), 'enum') !== false) {
            preg_match("/enum\s*\((.+)\)/i", $order_status_type, $matches);
            if (!empty($matches[1])) {
                $enum_values = array_map(function($v) {
                    return trim($v, " '\"");
                }, explode(',', $matches[1]));
                
                // Create a case-insensitive mapping
                $enum_map = [];
                foreach ($enum_values as $val) {
                    $enum_map[strtolower($val)] = $val;
                }
                
                // Check if new_status is in the ENUM (case-insensitive)
                $new_status_lower = strtolower($new_status);
                if (isset($enum_map[$new_status_lower])) {
                    // Normalize to the exact ENUM value (case-sensitive)
                    $new_status = $enum_map[$new_status_lower];
                } else {
                    throw new Exception("Status '$new_status' is not valid. Valid statuses: " . implode(', ', $enum_values));
                }
            }
        }
        
        // Update order status
        $update_fields = "order_status = ?";
        $update_params = [$new_status];
        $param_types = "s";
        
        // Update delivery_date if provided
        if (!empty($delivery_date) && in_array('delivery_date', $existing_columns)) {
            $update_fields .= ", delivery_date = ?";
            $update_params[] = $delivery_date;
            $param_types .= "s";
        }
        
        if ($new_status === 'Confirmed' && in_array('confirmed_by', $existing_columns)) {
            $update_fields .= ", confirmed_by = ?";
            $update_params[] = $user_id;
            $param_types .= "i";
        }
        
        if ($new_status === 'Completed' && in_array('completed_at', $existing_columns)) {
            $update_fields .= ", completed_at = NOW()";
        }
        
        $update_params[] = $order_id;
        $param_types .= "i";
        
        $sql = "UPDATE orders SET $update_fields, updated_at = NOW() WHERE Order_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$update_params);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update status: " . $stmt->error);
        }
        $stmt->close();
        
        // If delivery person is provided, create or update delivery record
        if (!empty($delivery_person)) {
            // Check if delivery table exists first
            $table_check = $conn->query("SHOW TABLES LIKE 'delivery'");
            if ($table_check && $table_check->num_rows > 0) {
                $table_check->close();
                
                // Get order details for delivery address and customer name
                // Check if delivery_address column exists first
                $columns_result = $conn->query("SHOW COLUMNS FROM orders");
                $order_columns = [];
                while ($row = $columns_result->fetch_assoc()) {
                    $order_columns[] = $row['Field'];
                }
                $columns_result->close();
                
                $select_fields = ['c.customer_name'];
                if (in_array('delivery_address', $order_columns)) {
                    $select_fields[] = 'o.delivery_address';
                }
                if (in_array('delivery_date', $order_columns)) {
                    $select_fields[] = 'o.delivery_date';
                }
                
                $order_info_stmt = $conn->prepare("SELECT " . implode(', ', $select_fields) . " 
                    FROM orders o 
                    INNER JOIN customers c ON o.Customer_ID = c.Customer_ID 
                    WHERE o.Order_ID = ?");
                $order_info_stmt->bind_param("i", $order_id);
                $order_info_stmt->execute();
                $order_info_result = $order_info_stmt->get_result();
                $order_info = $order_info_result->fetch_assoc();
                $order_info_stmt->close();
                
                $delivery_address = $order_info['delivery_address'] ?? '';
                $delivered_to = $order_info['customer_name'] ?? '';
                // Use provided delivery_date or existing one from order
                $schedule_date = !empty($delivery_date) ? $delivery_date : ($order_info['delivery_date'] ?? null);
                
                // Check if delivery record exists
                $check_delivery = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Order_ID = ?");
                $check_delivery->bind_param("i", $order_id);
                $check_delivery->execute();
                $delivery_result = $check_delivery->get_result();
                $check_delivery->close();
                
                if ($delivery_result->num_rows > 0) {
                    // Update existing delivery record
                    $update_delivery = $conn->prepare("UPDATE delivery SET 
                        delivered_by = ?, 
                        delivery_address = ?,
                        delivered_to = ?,
                        schedule_date = ?,
                        updated_at = NOW()
                        WHERE Order_ID = ?");
                    $update_delivery->bind_param("ssssi", $delivery_person, $delivery_address, $delivered_to, $schedule_date, $order_id);
                    if (!$update_delivery->execute()) {
                        error_log("Warning: Failed to update delivery person: " . $update_delivery->error);
                        // Don't throw exception, just log - delivery person update is optional
                    } else {
                        $update_delivery->close();
                    }

                    // Ensure delivery_detail exists for this delivery
                    $did_stmt = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Order_ID = ? LIMIT 1");
                    if ($did_stmt) {
                        $did_stmt->bind_param("i", $order_id);
                        $did_stmt->execute();
                        $did_res = $did_stmt->get_result();
                        if ($did_res && $did_res->num_rows > 0) {
                            $did_row = $did_res->fetch_assoc();
                            ensureDeliveryDetails($conn, intval($did_row['Delivery_ID']), $order_id);
                        }
                        $did_stmt->close();
                    }
                } else {
                    // Create new delivery record - check what columns exist in delivery table
                    $delivery_cols = $conn->query("SHOW COLUMNS FROM delivery");
                    $delivery_columns = [];
                    while ($row = $delivery_cols->fetch_assoc()) {
                        $delivery_columns[] = $row['Field'];
                    }
                    $delivery_cols->close();
                    
                    $delivery_fields = ['Order_ID'];
                    $delivery_values = ['?'];
                    $delivery_params = [$order_id];
                    $delivery_types = "i";
                    
                    if (in_array('delivery_address', $delivery_columns)) {
                        $delivery_fields[] = 'delivery_address';
                        $delivery_values[] = '?';
                        $delivery_params[] = $delivery_address;
                        $delivery_types .= 's';
                    }
                    if (in_array('schedule_date', $delivery_columns)) {
                        $delivery_fields[] = 'schedule_date';
                        $delivery_values[] = '?';
                        $delivery_params[] = $schedule_date;
                        $delivery_types .= 's';
                    }
                    if (in_array('delivery_status', $delivery_columns)) {
                        $delivery_fields[] = 'delivery_status';
                        $delivery_values[] = "'Scheduled'";
                    }
                    if (in_array('delivered_by', $delivery_columns)) {
                        $delivery_fields[] = 'delivered_by';
                        $delivery_values[] = '?';
                        $delivery_params[] = $delivery_person;
                        $delivery_types .= 's';
                    }
                    if (in_array('delivered_to', $delivery_columns)) {
                        $delivery_fields[] = 'delivered_to';
                        $delivery_values[] = '?';
                        $delivery_params[] = $delivered_to;
                        $delivery_types .= 's';
                    }
                    
                    $delivery_sql = "INSERT INTO delivery (" . implode(', ', $delivery_fields) . ") VALUES (" . implode(', ', $delivery_values) . ")";
                    $insert_delivery = $conn->prepare($delivery_sql);
                    if ($insert_delivery) {
                        $insert_delivery->bind_param($delivery_types, ...$delivery_params);
                        if (!$insert_delivery->execute()) {
                            error_log("Warning: Failed to assign delivery person: " . $insert_delivery->error);
                            // Don't throw exception, just log - delivery person assignment is optional
                        } else {
                            $new_delivery_id = $conn->insert_id;
                            ensureDeliveryDetails($conn, $new_delivery_id, $order_id);
                            $insert_delivery->close();
                        }
                    }
                }
            } else {
                // Delivery table doesn't exist, just log it
                error_log("Delivery table does not exist, skipping delivery person assignment");
            }
        }
        
        // Update delivery table based on status (only if delivery table exists)
        $table_check = $conn->query("SHOW TABLES LIKE 'delivery'");
        if ($table_check && $table_check->num_rows > 0) {
            $table_check->close();
            
            if ($new_status === 'out for delivery' || $new_status === 'Out for Delivery') {
                // Update delivery status to "Out for Delivery"
                $delivery_stmt = $conn->prepare("UPDATE delivery SET 
                    delivery_status = 'Out for Delivery',
                    updated_at = NOW()
                    WHERE Order_ID = ?");
                if ($delivery_stmt) {
                    $delivery_stmt->bind_param("i", $order_id);
                    $delivery_stmt->execute();
                    $delivery_stmt->close();
                }
            } elseif ($new_status === 'delivered' || $new_status === 'Delivered (Pending Cash Turnover)') {
                // Update delivery status and actual_date_arrived
                $delivery_stmt = $conn->prepare("UPDATE delivery SET 
                    delivery_status = 'Delivered',
                    actual_date_arrived = CURDATE(),
                    updated_at = NOW()
                    WHERE Order_ID = ?");
                if ($delivery_stmt) {
                    $delivery_stmt->bind_param("i", $order_id);
                    $delivery_stmt->execute();
                    $delivery_stmt->close();
                }
            }
        }
        
        // Note: Inventory is NOT deducted when order status is "Completed"
        // Inventory is only reduced when payment is received and sale is recorded
        // This happens in sales_backend.php
        
        // Log status change
        logStatusChange($conn, $order_id, $old_status, $new_status, $user_id, $notes);
        
        $conn->commit();
        
        // Use absolute URL or relative path based on your setup
        $redirect_url = "../pages/orders.php?success=" . urlencode("Status updated successfully");
        error_log("Redirecting to: $redirect_url");
        header("Location: " . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Status update error: " . $e->getMessage());
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function handleAssignDelivery($conn) {
    $order_id = intval($_POST['order_id']);
    $delivery_person = trim($_POST['delivery_person']);
    $vehicle_info = trim($_POST['vehicle_info'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($delivery_person)) {
        header("Location: ../pages/orders.php?error=Delivery person name is required");
        exit();
    }
    
    // Get order details for delivery address and customer
    // Check if delivery_address column exists
    $columns_result = $conn->query("SHOW COLUMNS FROM orders");
    $order_columns = [];
    while ($row = $columns_result->fetch_assoc()) {
        $order_columns[] = $row['Field'];
    }
    $columns_result->close();
    
    $select_fields = ['c.customer_name'];
    if (in_array('delivery_address', $order_columns)) {
        $select_fields[] = 'o.delivery_address';
    }
    
    $order_stmt = $conn->prepare("SELECT " . implode(', ', $select_fields) . " 
        FROM orders o 
        INNER JOIN customers c ON o.Customer_ID = c.Customer_ID 
        WHERE o.Order_ID = ?");
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    if ($order_result->num_rows === 0) {
        $order_stmt->close();
        header("Location: ../pages/orders.php?error=Order not found");
        exit();
    }
    $order_data = $order_result->fetch_assoc();
    $order_stmt->close();
    
    // Check if delivery record already exists
    $check_stmt = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Order_ID = ?");
    $check_stmt->bind_param("i", $order_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    $delivery_address = $order_data['delivery_address'] ?? '';
    $delivered_to = $order_data['customer_name'] ?? '';
    $delivery_status = 'Scheduled'; // Default status when assigned
    
    if ($result->num_rows > 0) {
        // Update existing delivery record
        $delivery_row = $result->fetch_assoc();
        $delivery_id = $delivery_row['Delivery_ID'];
        $stmt = $conn->prepare("UPDATE delivery SET 
            delivered_by = ?, 
            delivery_address = ?,
            delivered_to = ?,
            delivery_status = ?,
            updated_at = NOW()
            WHERE Delivery_ID = ?");
        $stmt->bind_param("ssssi", $delivery_person, $delivery_address, $delivered_to, $delivery_status, $delivery_id);
    } else {
        // Create new delivery record
        // Get schedule_date from orders table if available
        $schedule_stmt = $conn->prepare("SELECT delivery_date FROM orders WHERE Order_ID = ?");
        $schedule_stmt->bind_param("i", $order_id);
        $schedule_stmt->execute();
        $schedule_result = $schedule_stmt->get_result();
        $schedule_date = null;
        if ($schedule_result->num_rows > 0) {
            $schedule_data = $schedule_result->fetch_assoc();
            $schedule_date = $schedule_data['delivery_date'];
        }
        $schedule_stmt->close();
        
        $stmt = $conn->prepare("INSERT INTO delivery 
            (Order_ID, delivery_address, schedule_date, delivery_status, delivered_by, delivered_to) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $order_id, $delivery_address, $schedule_date, $delivery_status, $delivery_person, $delivered_to);
    }
    
    if ($stmt->execute()) {
        // Determine delivery_id (existing or newly created)
        if (empty($delivery_id)) {
            $delivery_id = $conn->insert_id;
        }

        // Ensure delivery_detail exists for this delivery
        ensureDeliveryDetails($conn, intval($delivery_id), $order_id);

        $check_stmt->close();
        $stmt->close();
        header("Location: ../pages/orders.php?success=Delivery assigned successfully");
        exit();
    } else {
        $check_stmt->close();
        $stmt->close();
        header("Location: ../pages/orders.php?error=" . urlencode($stmt->error));
        exit();
    }
}

function handleCancelOrder($conn, $user_id) {
    $order_id = intval($_POST['order_id']);
    $reason = trim($_POST['cancellation_reason'] ?? '');
    
    if (empty($reason)) {
        header("Location: ../pages/orders.php?error=Cancellation reason is required");
        exit();
    }
    
    $conn->begin_transaction();
    try {
        // Get current status
        $stmt = $conn->prepare("SELECT order_status FROM orders WHERE Order_ID = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception("Order not found");
        }
        $order = $result->fetch_assoc();
        $old_status = $order['order_status'];
        $stmt->close();
        
        // Update order - check which cancelled status exists in the ENUM
        $status_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field = 'order_status'");
        $use_cancelled = 'Cancelled'; // Default
        if ($status_check && $status_check->num_rows > 0) {
            $col_info = $status_check->fetch_assoc();
            if (strpos(strtolower($col_info['Type']), 'enum') !== false) {
                preg_match("/enum\s*\((.+)\)/i", $col_info['Type'], $matches);
                if (!empty($matches[1])) {
                    $enum_values = array_map(function($v) {
                        return trim($v, " '\"");
                    }, explode(',', $matches[1]));
                    
                    // Check for both 'cancelled' and 'Cancelled' (case-insensitive)
                    foreach ($enum_values as $val) {
                        if (strtolower($val) === 'cancelled') {
                            $use_cancelled = $val; // Use the exact ENUM value
                            break;
                        }
                    }
                }
            }
        }
        if ($status_check) $status_check->close();
        
        // Check which columns exist before updating
        $columns_result = $conn->query("SHOW COLUMNS FROM orders");
        $existing_columns = [];
        while ($row = $columns_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        $columns_result->close();
        
        // Build UPDATE query based on existing columns
        $update_fields = "order_status = ?";
        $update_params = [$use_cancelled];
        $param_types = "s";
        
        if (in_array('cancelled_at', $existing_columns)) {
            $update_fields .= ", cancelled_at = NOW()";
        }
        if (in_array('cancellation_reason', $existing_columns)) {
            $update_fields .= ", cancellation_reason = ?";
            $update_params[] = $reason;
            $param_types .= "s";
        }
        if (in_array('updated_at', $existing_columns)) {
            $update_fields .= ", updated_at = NOW()";
        }
        
        $update_fields .= " WHERE Order_ID = ?";
        $update_params[] = $order_id;
        $param_types .= "i";
        
        $stmt = $conn->prepare("UPDATE orders SET " . $update_fields);
        $stmt->bind_param($param_types, ...$update_params);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to cancel order: " . $stmt->error);
        }
        $stmt->close();
        
        // Log status change
        logStatusChange($conn, $order_id, $old_status, $use_cancelled, $user_id, "Cancelled: " . $reason);
        
        $conn->commit();
        header("Location: ../pages/orders.php?success=Order cancelled successfully");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order cancellation error: " . $e->getMessage());
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function generateOrderNumber($conn) {
    $date_prefix = date('Ymd');
    $stmt = $conn->prepare("SELECT order_number FROM orders 
        WHERE order_number LIKE ? 
        ORDER BY order_number DESC LIMIT 1");
    $pattern = "ORD-{$date_prefix}-%";
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $last_order = $result->fetch_assoc();
        $last_number = intval(substr($last_order['order_number'], -4));
        $new_number = $last_number + 1;
    } else {
        $new_number = 1;
    }
    $stmt->close();
    
    return sprintf("ORD-%s-%04d", $date_prefix, $new_number);
}

function logStatusChange($conn, $order_id, $old_status, $new_status, $user_id, $notes = '') {
    // Check if order_status_history table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'order_status_history'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO order_status_history 
            (Order_ID, old_status, new_status, changed_by, notes) 
            VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $order_id, $old_status, $new_status, $user_id, $notes);
        $stmt->execute();
        $stmt->close();
    }
    // If table doesn't exist, silently skip logging (optional feature)
}



function deductInventoryForOrder($conn, $order_id) {
    // Get all items for this order
    $stmt = $conn->prepare("SELECT Product_ID, ordered_qty FROM order_details WHERE Order_ID = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $product_id = $item['Product_ID'];
        $quantity = $item['ordered_qty'];

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

    $stmt->close();
}
