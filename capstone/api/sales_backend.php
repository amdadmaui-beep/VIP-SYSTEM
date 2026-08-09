<?php
/**
 * Sales Backend API
 * Handles sales creation, voiding, and management
 * 
 * SECURITY UPDATE: Added CSRF protection for state-changing operations
 * Location: capstone/api/sales_backend.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/rider_availability_helper.php';
require_once __DIR__ . '/../includes/cash_session_helper.php';
require_once __DIR__ . '/../includes/stock_reservation_helper.php';
require_once __DIR__ . '/../includes/csrf.php'; // CSRF Protection - Security Fix
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../realtime/publish_event.php';
require_once __DIR__ . '/../includes/mailer.php';

// Accessible to Owner (1), Cashier (2), and Manager (4)
requireRole([1, 2, 3]);

enforceRateLimit(rateLimitKey('sales'), 60, 60);

// Handle GET requests for management features
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    
    switch ($action) {
        case 'get_sales_history':
            if (!isModuleAllowedForUser($conn, $current_user_id, 'sales_history', true)) {
                echo json_encode(['success' => false, 'message' => 'Sales history access is currently restricted for your account.']);
                exit();
            }
            handleGetSalesHistory($conn);
            break;
        case 'get_z_read':
            if (!isModuleAllowedForUser($conn, $current_user_id, 'cashier_z_read', true)) {
                echo json_encode(['success' => false, 'message' => 'Sales report access is currently restricted for your account.']);
                exit();
            }
            handleGetZRead($conn, $current_user_id);
            break;
        case 'get_sale_details':
            handleGetSaleDetails($conn);
            break;
        case 'list_customers':
            handleListCustomers($conn);
            break;
    }
}

/**
 * Sends a response as JSON if it's an AJAX request, otherwise redirects.
 */
function sendResponse($conn, $success, $message, $redirectUrl = '../pages/cashier_view.php') {
    $is_ajax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
    
    if ($is_ajax) {
        http_response_code($success ? 200 : ($message === 'Invalid or expired security token. Please refresh the page and try again.' ? 403 : 400));
        header('Content-Type: application/json');
        $response = ['success' => $success];
        if (is_array($message)) {
            $response = array_merge($response, $message);
        } else {
            $response['message'] = $message;
        }
        $response['csrf_token'] = getCsrfToken();
        echo json_encode($response);
        exit();
    }
    
    $sep = (strpos($redirectUrl, '?') === false) ? '?' : '&';
    $msg = is_array($message) ? ($message['message'] ?? 'Action completed') : $message;
    $type = $success ? 'success' : 'error';
    header("Location: {$redirectUrl}{$sep}{$type}=" . urlencode($msg));
    exit();
}

// Handle different sales operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection: Validate token for state-changing POST actions - Security Fix
    $state_changing_actions = ['create_sale_from_delivery', 'create_sale_from_order', 'create_pickup_sale', 'create_walkin_sale', 'void_sale', 'update_sale_customer'];
    $action = $_POST['action'] ?? '';
    if (in_array($action, $state_changing_actions)) {
        if (!validateCsrfToken(false)) {
            sendResponse($conn, false, 'Invalid or expired security token. Please refresh the page and try again.');
        }
    }
    
    $user_id = $_SESSION['user_id'] ?? 1;
    $uid = (int)$user_id;

    if ($action === 'update_sale_customer' && !in_array($_SESSION['user_role'] ?? 0, [1, 2, 4])) {
        sendResponse($conn, false, "Only Owner and Manager can reassign customers.");
    }

    // Restriction: Owner (Role_ID 1) is restricted to view-only mode (except update_sale_customer)
    if (isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1 && $action !== 'update_sale_customer') {
        $error_msg = "Your account (Owner) is restricted to view-only access. Operations are not allowed.";
        sendResponse($conn, false, $error_msg, '../pages/cashier_view.php');
    }
    if ($action === 'void_sale' && !isModuleAllowedForUser($conn, $uid, 'cashier_void_sale', true)) {
        sendResponse($conn, false, "Void access is currently restricted for your account.");
    }
    if ($action === 'create_sale_from_delivery' && !isModuleAllowedForUser($conn, $uid, 'cashier_delivery_orders_sales', true)) {
        sendResponse($conn, false, "Delivery-order sales access is currently restricted for your account.");
    }
    if (in_array($action, ['create_sale_from_delivery', 'create_walkin_sale', 'create_pickup_sale'], true)) {
        $postToAr = isset($_POST['post_to_ar']) && ($_POST['post_to_ar'] === 'on' || $_POST['post_to_ar'] === '1' || $_POST['post_to_ar'] === true);
        if ($postToAr && !isModuleAllowedForUser($conn, $uid, 'cashier_ar_sales', true)) {
            sendResponse($conn, false, "AR posting in sales is currently restricted for your account.");
        }
    }
    if ($action === 'create_pickup_sale' && !isModuleAllowedForUser($conn, $uid, 'cashier_delivery_orders_sales', true)) {
        sendResponse($conn, false, "Pickup-order sales access is currently restricted for your account.");
    }

    switch ($action) {
        case 'create_sale_from_delivery':
        case 'create_sale_from_order':
            handleCreateSaleFromDelivery($conn, $user_id);
            break;
        case 'create_pickup_sale':
            handleCreatePickupSale($conn, $user_id);
            break;
        case 'create_walkin_sale':
            handleCreateWalkinSale($conn, $user_id);
            break;
        case 'update_sale_customer':
            handleUpdateSaleCustomer($conn, $user_id);
            break;
        default:
            sendResponse($conn, false, "Invalid action");
            break;
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
    if (!$t || $t->rowCount() === 0) {
        return 0;
    }

    // Look for existing record by name
    $name = 'Walk-in Customer';
    $stmt = $conn->prepare("SELECT Customer_ID FROM customers WHERE customer_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        return intval($row['Customer_ID']);
    }

    // Create record
    $phone = 'N/A';
    $address = '';
    $type = 'Regular';

    // Check which columns exist in customers table
    $cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM customers");
    if ($cr) {
        while ($r = $cr->fetch()) {
            $cols[] = $r['Field'];
        }
    }

    $fields = [];
    $params = [];

    if (in_array('customer_name', $cols)) {
        $fields[] = 'customer_name';
        $params[] = $name;
    }
    if (in_array('phone_number', $cols)) {
        $fields[] = 'phone_number';
        $params[] = $phone;
    }
    if (in_array('address', $cols)) {
        $fields[] = 'address';
        $params[] = $address;
    }
    if (in_array('type', $cols)) {
        $fields[] = 'type';
        $params[] = $type;
    }

    if (empty($fields)) {
        return 0;
    }

    $sql = "INSERT INTO customers (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")";
    $ins = $conn->prepare($sql);
    if (!$ins) return 0;
    if (!$ins->execute($params)) {
        return 0;
    }
    return intval($conn->lastInsertId());
}

/**
 * Update the linked order after a successful sale.
 */
function updateOrderAfterSale($conn, $order_id, $sale_id) {
    if ($order_id <= 0) return;

    $cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM orders");
    if (!$cr) return;
    while ($r = $cr->fetch()) {
        $cols[] = $r['Field'];
    }

    $setParts = [];
    $params = [];

    if (in_array('order_status', $cols)) {
        $setParts[] = "order_status = ?";
        $params[] = 'Completed';
    } elseif (in_array('status', $cols)) {
        $setParts[] = "status = ?";
        $params[] = 'Completed';
    }

    if (in_array('Sale_ID', $cols)) {
        $setParts[] = "Sale_ID = ?";
        $params[] = $sale_id;
    }

    if (in_array('completed_at', $cols)) {
        $setParts[] = "completed_at = NOW()";
    }

    if (in_array('updated_at', $cols)) {
        $setParts[] = "updated_at = NOW()";
    }

    if (empty($setParts)) return;

    $sql = "UPDATE orders SET " . implode(', ', $setParts) . " WHERE Order_ID = ?";
    $params[] = $order_id;

    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->execute($params);
}

/**
 * Create sale from a delivered order
 */
function handleCreateSaleFromDelivery($conn, $user_id) {
    $action = $_POST['action'] ?? 'create_sale_from_delivery';
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $cash_received = max(0, floatval($_POST['cash_received'] ?? $_POST['amount_paid'] ?? 0));
    $posted_sale_total = max(0, floatval($_POST['sale_total'] ?? 0));
    $discount_amount = max(0, floatval($_POST['discount_amount'] ?? 0));
    
    $delivery_details = [];
    if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
        foreach ($_POST['product_id'] as $index => $pid) {
            $delivery_details[] = [
                'product_id' => intval($pid),
                'delivery_detail_id' => intval($_POST['delivery_detail_id'][$index] ?? 0),
                'order_detail_id' => intval($_POST['order_detail_id'][$index] ?? 0),
                'received_qty' => floatval($_POST['received_qty'][$index] ?? 0),
                'damage_qty' => floatval($_POST['damage_qty'][$index] ?? 0)
            ];
        }
    }
    
    // Comprehensive validation
    $errors = [];
    
    // Delivery/Order ID validation
    if ($action === 'create_sale_from_order') {
        if (empty($order_id) || $order_id <= 0) {
            $errors[] = "Order ID is required.";
        } else {
            // Verify order exists
            $order_check = $conn->prepare("SELECT Order_ID FROM orders WHERE Order_ID = ?");
            $order_check->execute([$order_id]);
            if (!$order_check->fetch()) {
                $errors[] = "Order does not exist.";
            }
        }
    } else {
        if (empty($delivery_id) || $delivery_id <= 0) {
            $errors[] = "Delivery ID is required.";
        } else {
            // Verify delivery exists
            $delivery_check = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Delivery_ID = ?");
            $delivery_check->execute([$delivery_id]);
            if (!$delivery_check->fetch()) {
                $errors[] = "Delivery does not exist.";
            }
        }
    }
    
    // Delivery details validation
    if (empty($delivery_details) || !is_array($delivery_details) || count($delivery_details) === 0) {
        $errors[] = "At least one item is required for sale.";
    } else {
        foreach ($delivery_details as $index => $detail) {
            $item_num = $index + 1;
            
            // Product ID validation
            $product_id = intval($detail['product_id'] ?? 0);
            if ($product_id <= 0) {
                $errors[] = "Item #{$item_num}: Product ID is required.";
                continue;
            }
            
            // Verify product exists
            $product_check = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
            $product_check->execute([$product_id]);
            $product_data = $product_check->fetch(PDO::FETCH_ASSOC);
            if (!$product_data) {
                $errors[] = "Item #{$item_num}: Product does not exist.";
            } elseif ($product_data['is_discontinued'] == 1 && $action === 'create_walkin_sale') {
                $errors[] = "Item #{$item_num}: Cannot sell discontinued products.";
            }
            
            // Quantity validation
            $received_qty = floatval($detail['received_qty'] ?? 0);
            $damage_qty = floatval($detail['damage_qty'] ?? 0);
            
            if ($received_qty < 0) {
                $errors[] = "Item #{$item_num}: Received quantity cannot be negative.";
            }
            if ($received_qty > 999999) {
                $errors[] = "Item #{$item_num}: Received quantity exceeds maximum (999,999).";
            }
            if ($damage_qty < 0) {
                $errors[] = "Item #{$item_num}: Damage quantity cannot be negative.";
            }
            if ($damage_qty > $received_qty) {
                $errors[] = "Item #{$item_num}: Damage quantity cannot exceed received quantity.";
            }
            
            // Order detail ID validation (if provided)
            $order_detail_id = intval($detail['order_detail_id'] ?? 0);
            if ($order_detail_id > 0) {
                $order_detail_check = $conn->prepare("SELECT Order_detail_ID FROM order_details WHERE Order_detail_ID = ?");
                $order_detail_check->execute([$order_detail_id]);
                if (!$order_detail_check->fetch()) {
                    $errors[] = "Item #{$item_num}: Order detail does not exist.";
                }
            }
        }
    }
    
    // Remarks validation
    if (!empty($remarks) && strlen($remarks) > 500) {
        $errors[] = "Remarks must not exceed 500 characters.";
    }
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    if (!empty($errors)) {
        sendResponse($conn, false, implode(' | ', $errors));
    }
    
    // Get delivery or order information
    if ($delivery_id > 0) {
        $delivery_stmt = $conn->prepare("SELECT d.*, o.Order_ID, o.Customer_ID, c.customer_name, c.email as customer_email
                                         FROM delivery d 
                                         LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                         LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                                         WHERE d.Delivery_ID = ?");
        $delivery_stmt->execute([$delivery_id]);
    } else {
        $delivery_stmt = $conn->prepare("SELECT NULL as Delivery_ID, o.Order_ID, o.Customer_ID, c.customer_name, c.email as customer_email
                                         FROM orders o
                                         LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                                         WHERE o.Order_ID = ?");
        $delivery_stmt->execute([$order_id]);
    }
    $delivery = $delivery_stmt->fetch();
    
    if (!$delivery) {
        sendResponse($conn, false, ($delivery_id > 0 ? "Delivery" : "Order") . " not found");
    }

    $openShift = getOpenCashShiftForUser($conn, (int) $user_id);
    if (!$openShift) {
        sendResponse($conn, false, 'Open a cashier shift before recording delivery sales.');
    }

    if ($delivery_id > 0) {
        $deliveryStatus = trim((string) ($delivery['delivery_status'] ?? ''));
        $allowedStatuses = ['Remitted', 'Completed', 'Delivered (Pending Cash Turnover)'];
        if (!in_array($deliveryStatus, $allowedStatuses, true)) {
            sendResponse($conn, false, 'This delivery is not yet remitted. Record it only after rider turnover.');
        }
    }
    
    $conn->beginTransaction();
    try {
        $columns_result = $conn->query("SHOW COLUMNS FROM sales");
        $existing_columns = [];
        while ($row = $columns_result->fetch()) {
            $existing_columns[] = $row['Field'];
        }
        
        $has_status = in_array('status', $existing_columns);
        $has_remarks = in_array('remarks', $existing_columns);
        $has_created_by = in_array('created_by', $existing_columns);
        $has_customer_id = in_array('Customer_ID', $existing_columns) || in_array('customer_id', $existing_columns);
        
        $insert_fields = [];
        $values_placeholders = [];
        $params = [];

        // Recorded-by user
        if (in_array('User_ID', $existing_columns)) {
            $insert_fields[] = 'User_ID';
            $values_placeholders[] = '?';
            $params[] = intval($user_id);
        } elseif (in_array('user_id', $existing_columns)) {
            $insert_fields[] = 'user_id';
            $values_placeholders[] = '?';
            $params[] = intval($user_id);
        }
        
        if ($has_created_by) {
            $insert_fields[] = 'created_by';
            $values_placeholders[] = '?';
            $params[] = intval($user_id);
        }

        if ($has_customer_id) {
            $cid = intval($delivery['Customer_ID'] ?? 0);
            if ($cid <= 0) $cid = getOrCreateWalkinCustomerId($conn);

            if (in_array('Customer_ID', $existing_columns)) {
                $insert_fields[] = 'Customer_ID';
                $values_placeholders[] = '?';
                $params[] = $cid;
            } elseif (in_array('customer_id', $existing_columns)) {
                $insert_fields[] = 'customer_id';
                $values_placeholders[] = '?';
                $params[] = $cid;
            }
        }

        if (in_array('Delivery_ID', $existing_columns)) {
            $insert_fields[] = 'Delivery_ID';
            $values_placeholders[] = '?';
            $params[] = $delivery_id;
        } elseif (in_array('delivery_id', $existing_columns)) {
            $insert_fields[] = 'delivery_id';
            $values_placeholders[] = '?';
            $params[] = $delivery_id;
        }
        
        if ($has_status) {
            $insert_fields[] = 'status';
            $values_placeholders[] = "'Completed'";
        }
        
        if ($has_remarks) {
            $insert_fields[] = 'remarks';
            $values_placeholders[] = '?';
            $params[] = $remarks;
        }
        
        if (in_array('created_at', $existing_columns)) {
            $insert_fields[] = 'created_at';
            $values_placeholders[] = 'NOW()';
        }

        if (empty($insert_fields)) {
            $sql = "INSERT INTO sales () VALUES ()";
            $sale_stmt = $conn->prepare($sql);
            $sale_stmt->execute();
        } else {
            $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $values_placeholders) . ")";
            $sale_stmt = $conn->prepare($sql);
            $sale_stmt->execute($params);
        }
        
        $sale_id = $conn->lastInsertId();
        
        // Create sale_source link
        $order_id_val = intval($delivery['Order_ID'] ?? 0);
        $delivery_id_val = $delivery_id > 0 ? $delivery_id : null;
        $order_id_val = $order_id_val > 0 ? $order_id_val : null;

        $source_stmt = $conn->prepare("INSERT INTO sale_source (Delivery_ID, Order_ID, Sale_ID) VALUES (?, ?, ?)");
        $source_stmt->execute([$delivery_id_val, $order_id_val, $sale_id]);
        
        foreach ($delivery_details as $detail) {
            $order_detail_id = intval($detail['order_detail_id'] ?? 0);
            $received_qty = floatval($detail['received_qty'] ?? 0);
            $damage_qty = floatval($detail['damage_qty'] ?? 0);
            $product_id = intval($detail['product_id'] ?? 0);
            
            if ($received_qty <= 0) continue;
            
            $order_detail_stmt = $conn->prepare("SELECT unit_price FROM order_details WHERE Order_detail_ID = ?");
            $order_detail_stmt->execute([$order_detail_id]);
            $order_detail = $order_detail_stmt->fetch();
            
            if (!$order_detail) continue;
            
            $unit_price = floatval($order_detail['unit_price']);
            $sale_qty = max(0, $received_qty - $damage_qty);
            
            if ($sale_qty > 0) {
                $subtotal = $sale_qty * $unit_price;
                $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (Sale_ID, Product_ID, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $sale_detail_stmt->execute([$sale_id, $product_id, $sale_qty, $unit_price, $subtotal]);
                deductInventory($conn, $product_id, $sale_qty);
            }
        }

        $total_sale_query = $conn->prepare("SELECT COALESCE(SUM(subtotal), 0) as total FROM sale_details WHERE Sale_ID = ?");
        $total_sale_query->execute([$sale_id]);
        $gross_sale_total = floatval($total_sale_query->fetchColumn() ?? 0);
        $effective_sale_total = $posted_sale_total > 0 ? $posted_sale_total : max(0, $gross_sale_total - $discount_amount);
        
        foreach ($delivery_details as $detail) {
            $delivery_detail_id = intval($detail['delivery_detail_id'] ?? 0);
            if ($delivery_detail_id > 0) {
                $update_delivery_detail = $conn->prepare("UPDATE delivery_detail SET status = 'delivered', updated_at = NOW() WHERE Delivery_Detail_ID = ?");
                $update_delivery_detail->execute([$delivery_detail_id]);
            }
        }
        
        if (!empty($delivery['Order_ID'])) {
            updateOrderAfterSale($conn, intval($delivery['Order_ID']), intval($sale_id));
        }

        if ($delivery_id > 0) {
            $upd_del = $conn->prepare("UPDATE delivery SET delivery_status = 'Completed', updated_at = NOW() WHERE Delivery_ID = ?");
            $upd_del->execute([$delivery_id]);
            $assignedRiderId = riderGetUserIdByDeliveryId($conn, $delivery_id);
            if ($assignedRiderId > 0) {
                syncRiderAvailabilityForUser($conn, $assignedRiderId);
            }
        }

        // Post to AR
        $post_to_ar = isset($_POST['post_to_ar']) && ($_POST['post_to_ar'] === 'on' || $_POST['post_to_ar'] === '1' || $_POST['post_to_ar'] === true);
        $created_ar_context = null;
        if ($post_to_ar) {
            $dup_check = $conn->prepare("SELECT AR_ID FROM account_receivable WHERE Sale_ID = ? LIMIT 1");
            $dup_check->execute([$sale_id]);
            if ($dup_check->fetch()) {
                throw new Exception("AR record already exists for this sale.");
            }

            $amount_paid = $cash_received;
            $customer_id = intval($delivery['Customer_ID'] ?? 0);
            
            $aging_days = 30;
            if ($customer_id > 0) {
                $customer_query = $conn->prepare("SELECT aging_days FROM customers WHERE Customer_ID = ?");
                $customer_query->execute([$customer_id]);
                $customer_data = $customer_query->fetch();
                if ($customer_data) $aging_days = intval($customer_data['aging_days'] ?? 30);
            }
            
            $due_date = date('Y-m-d', strtotime("+{$aging_days} days"));
            
            $invoice_amount = $effective_sale_total;
            
            $amount_due = max(0, $invoice_amount - $amount_paid);
            
            if ($amount_due > 0 && $customer_id > 0) {
                // Fetch customer credit information
                $credit_check_stmt = $conn->prepare("SELECT credit_limit, customer_name FROM customers WHERE Customer_ID = ?");
                $credit_check_stmt->execute([$customer_id]);
                $credit_info = $credit_check_stmt->fetch();
                
                $credit_limit = floatval($credit_info['credit_limit'] ?? 0);
                $customer_name = $credit_info['customer_name'] ?? 'Unknown';
                
                // Calculate outstanding AR for this customer
                $outstanding_stmt = $conn->prepare("SELECT SUM(amount_due) as total FROM account_receivable WHERE Customer_ID = ? AND status NOT IN ('Paid', 'Closed')");
                $outstanding_stmt->execute([$customer_id]);
                $total_outstanding = floatval($outstanding_stmt->fetchColumn() ?? 0);
                
                // Combined credit check: allow AR as long as total outstanding + new AR doesn't exceed credit limit
                $total_after_ar = $total_outstanding + $amount_due;
                if ($credit_limit > 0 && $total_after_ar > $credit_limit) {
                    logActivity('SALE', "Credit cap blocked AR for customer {$customer_name} (ID: {$customer_id}). " .
                        "Outstanding: {$total_outstanding}, Limit: {$credit_limit}, Attempted AR: {$amount_due}", $customer_id);
                    
                    throw new Exception(
                        "Adding this AR (\u{20B1}" . number_format($amount_due, 2) . ") would bring the total to \u{20B1}" . number_format($total_after_ar, 2) . ", exceeding the credit limit of \u{20B1}" . number_format($credit_limit, 2) . ".\n" .
                        "Reduce the AR amount so that total outstanding (\u{20B1}" . number_format($total_outstanding, 2) . ") + new AR stays within the credit limit."
                    );
                }
                
                $ar_stmt = $conn->prepare("INSERT INTO account_receivable 
                    (Sale_ID, Customer_ID, invoice_date, invoice_amount, opening_balance, amount_due, due_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ar_stmt->execute([$sale_id, $customer_id, date('Y-m-d'), $invoice_amount, $amount_due, $amount_due, $due_date, 'Open']);
                $ar_id = (int)$conn->lastInsertId();

                if ($amount_paid > 0) {
                    $pay_stmt = $conn->prepare("INSERT INTO ar_payment (payment_date, amount_paid, remaining_balance, collected_by) VALUES (?, ?, ?, ?)");
                    $pay_stmt->execute([date('Y-m-d'), $amount_paid, $amount_due, $user_id]);
                    $payment_id = $conn->lastInsertId();
                    
                    $link_stmt = $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)");
                    $link_stmt->execute([$ar_id, $payment_id]);
                }

                $created_ar_context = [
                    'ar_id' => $ar_id,
                    'invoice_amount' => $invoice_amount,
                    'amount_due' => $amount_due,
                    'due_date' => $due_date,
                    'amount_paid' => $amount_paid,
                    'customer_id' => $customer_id,
                ];
            }
        }

        // Defensive normalization:
        // Some cashier UI paths can submit empty cash fields for already-remitted deliveries.
        // If this is a remitted delivery sale and not AR posting, treat blank/zero cash as exact payment.
        if (!$post_to_ar && $delivery_id > 0 && $cash_received <= 0) {
            $delivery_status_now = trim((string)($delivery['delivery_status'] ?? ''));
            if (in_array($delivery_status_now, ['Remitted', 'Completed', 'Delivered (Pending Cash Turnover)'], true)) {
                $cash_received = $effective_sale_total;
            }
        }

        $change_given = max(0, $cash_received - $effective_sale_total);
        $net_cash = max(0, $cash_received - $change_given);
        if (!$post_to_ar && $net_cash + 0.00001 < $effective_sale_total) {
            throw new Exception('Cash received is not enough to cover this delivery sale.');
        }

        recordCashSessionEntry($conn, [
            'shift_id' => (int) $openShift['shift_id'],
            'entry_type' => 'delivery_remittance',
            'source_label' => 'Delivery Remittance',
            'sale_id' => (int) $sale_id,
            'delivery_id' => $delivery_id > 0 ? (int) $delivery_id : null,
            'order_id' => !empty($delivery['Order_ID']) ? (int) $delivery['Order_ID'] : null,
            'gross_amount' => $effective_sale_total,
            'cash_received' => $cash_received,
            'change_given' => $change_given,
            'net_cash' => $net_cash,
            'User_ID' => (int) $user_id,
        ]);
        
        $conn->commit();
        cacheInvalidateTable('sales');
        cacheInvalidateTable('sale_details');
        cacheInvalidateTable('products');
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('account_receivable');
        cacheInvalidateTable('ar_payment');

        $logDetails = ($delivery_id > 0 ? "Created sale from Delivery #$delivery_id" : "Created sale from Order #$order_id");
        logActivity('SALE', $logDetails, $sale_id);

        // Fetch items and customer for email receipt
        $to_email = trim((string)($delivery['customer_email'] ?? ''));
        $to_name = trim((string)($delivery['customer_name'] ?? ''));
        
        if (!empty($to_email) && filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            try {
                // Fetch line items
                $items_stmt = $conn->prepare("SELECT sd.*, p.product_name, u.unit_name
                                              FROM sale_details sd 
                                              INNER JOIN products p ON sd.Product_ID = p.Product_ID 
                                              LEFT JOIN units u ON p.unit_id = u.unit_id
                                              WHERE sd.Sale_ID = ?
                                              ORDER BY sd.Sale_detail_ID ASC");
                $items_stmt->execute([$sale_id]);
                $email_items = [];
                while ($it = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pname = $it['product_name'];
                    if (!empty($it['unit_name'])) $pname .= " {$it['unit_name']}";
                    $email_items[] = [
                        'product_name' => $pname,
                        'quantity' => floatval($it['quantity']),
                        'unit_price' => floatval($it['unit_price']),
                        'subtotal' => floatval($it['subtotal'])
                    ];
                }

                // Calculate current AR balance if any
                $customer_id = intval($delivery['Customer_ID'] ?? 0);
                $ar_balance = 0;
                if ($customer_id > 0) {
                    $outstanding_stmt = $conn->prepare("SELECT COALESCE(SUM(amount_due), 0) FROM account_receivable WHERE Customer_ID = ? AND status NOT IN ('Paid', 'Closed')");
                    $outstanding_stmt->execute([$customer_id]);
                    $ar_balance = floatval($outstanding_stmt->fetchColumn() ?? 0);
                }

                $sale_details = [
                    'sale_id' => $sale_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'payment_type' => $post_to_ar ? 'Accounts Receivable (Credit)' : 'Cash',
                    'gross_total' => $gross_sale_total,
                    'discount' => $discount_amount,
                    'total_amount' => $effective_sale_total,
                    'cash_received' => $cash_received,
                    'change_given' => $change_given,
                    'ar_balance' => $ar_balance
                ];

                // Send email receipt asynchronously/safely without blocking response
                sendDeliverySaleReceiptEmail($to_email, $to_name, $sale_details, $email_items);

                if ($created_ar_context && ($created_ar_context['amount_due'] ?? 0) > 0) {
                    sendARCreatedEmail(
                        $to_email,
                        $to_name,
                        (int)$created_ar_context['ar_id'],
                        (float)$created_ar_context['invoice_amount'],
                        (float)$created_ar_context['amount_due'],
                        (string)$created_ar_context['due_date'],
                        (int)$sale_id
                    );
                }

                if ($created_ar_context && ($created_ar_context['amount_paid'] ?? 0) > 0) {
                    $ctx = $created_ar_context;
                    $remaining = (float)$ctx['amount_due'];
                    $paid = (float)$ctx['amount_paid'];
                    sendARPaymentEmail(
                        $to_email,
                        $to_name,
                        (int)$ctx['ar_id'],
                        $paid,
                        $remaining,
                        $remaining <= 0,
                        (float)$ctx['invoice_amount']
                    );
                }
            } catch (Throwable $e) {
                // Log failure to prevent breaking the successful checkout
                logActivity('SYSTEM_ERROR', "Failed to send receipt email for Sale #$sale_id: " . $e->getMessage(), $sale_id);
            }
        }

        if ($delivery_id > 0) {
            $rider_user_id = riderGetUserIdByDeliveryId($conn, $delivery_id);
            publishRealtimeEvent([
                'event' => 'delivery.remittance_recorded',
                'data' => [
                    'delivery_id' => $delivery_id,
                    'sale_id' => (int)$sale_id,
                    'status' => 'Completed',
                    'rider_user_id' => $rider_user_id
                ]
            ]);
        }

        sendResponse($conn, true, [
            'message' => "Sale recorded successfully.",
            'sale_id' => $sale_id
        ]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse($conn, false, $e->getMessage());
    }
}

/**
 * Create sale from a pickup order
 * Full ordered quantity only; unit price is the editable wholesale price (cashier may adjust).
 */
function handleCreatePickupSale($conn, $user_id) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $cash_received = max(0, floatval($_POST['cash_received'] ?? $_POST['amount_paid'] ?? 0));
    $posted_sale_total = max(0, floatval($_POST['sale_total'] ?? 0));
    $discount_amount = max(0, floatval($_POST['discount_amount'] ?? 0));

    $pickup_items = [];
    if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
        foreach ($_POST['product_id'] as $index => $pid) {
            $pickup_items[] = [
                'product_id' => intval($pid),
                'order_detail_id' => intval($_POST['order_detail_id'][$index] ?? 0),
                'quantity' => floatval($_POST['quantity'][$index] ?? 0),
                'unit_price' => floatval($_POST['unit_price'][$index] ?? 0)
            ];
        }
    }

    // Comprehensive validation
    $errors = [];

    if (empty($order_id) || $order_id <= 0) {
        $errors[] = "Order ID is required.";
    } else {
        $order_check = $conn->prepare("SELECT o.Order_ID, o.Customer_ID, o.order_status, o.order_type FROM orders o WHERE o.Order_ID = ?");
        $order_check->execute([$order_id]);
        $order_row = $order_check->fetch(PDO::FETCH_ASSOC);
        if (!$order_row) {
            $errors[] = "Order does not exist.";
        } elseif (strtolower((string)($order_row['order_type'] ?? 'delivery')) !== 'pickup') {
            $errors[] = "This order is not a pickup order.";
        } else {
            $cur_status = strtolower((string)($order_row['order_status'] ?? ''));
            if (in_array($cur_status, ['completed', 'cancelled'], true)) {
                $errors[] = "This order is already " . (string)($order_row['order_status'] ?? 'completed') . " and cannot be settled.";
            }
        }
    }

    if (empty($pickup_items) || !is_array($pickup_items) || count($pickup_items) === 0) {
        $errors[] = "At least one item is required for this pickup sale.";
    } else {
        $full_qty_only = true;
        foreach ($pickup_items as $index => $item) {
            $item_num = $index + 1;
            $product_id = intval($item['product_id'] ?? 0);
            if ($product_id <= 0) {
                $errors[] = "Item #{$item_num}: Product ID is required.";
                continue;
            }

            $product_check = $conn->prepare("SELECT Product_ID, product_name, is_discontinued, wholesale_price FROM products WHERE Product_ID = ?");
            $product_check->execute([$product_id]);
            $product_data = $product_check->fetch(PDO::FETCH_ASSOC);
            if (!$product_data) {
                $errors[] = "Item #{$item_num}: Product does not exist.";
                continue;
            }
            if ((int)$product_data['is_discontinued'] === 1) {
                $errors[] = "Item #{$item_num}: Cannot sell discontinued products.";
            }

            $order_detail_id = intval($item['order_detail_id'] ?? 0);
            $ordered_qty = 0;
            if ($order_detail_id > 0) {
                $od_check = $conn->prepare("SELECT ordered_qty, unit_price FROM order_details WHERE Order_detail_ID = ?");
                $od_check->execute([$order_detail_id]);
                $od_row = $od_check->fetch(PDO::FETCH_ASSOC);
                if (!$od_row) {
                    $errors[] = "Item #{$item_num}: Order detail does not exist.";
                } else {
                    $ordered_qty = floatval($od_row['ordered_qty'] ?? 0);
                }
            } else {
                $errors[] = "Item #{$item_num}: Order detail reference is missing.";
            }

            $quantity = floatval($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                $errors[] = "Item #{$item_num}: Quantity must be greater than zero.";
            } elseif ($full_qty_only && $ordered_qty > 0 && abs($quantity - $ordered_qty) > 0.00001) {
                $errors[] = "Item #{$item_num}: Pickup settlement requires the full ordered quantity (" . rtrim(rtrim(number_format($ordered_qty, 2, '.', ''), '0'), '.') . " ordered, " . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') . " entered).";
            }

            $unit_price = floatval($item['unit_price'] ?? 0);
            if ($unit_price <= 0) {
                $unit_price = floatval($product_data['wholesale_price'] ?? 0);
                if ($unit_price <= 0 && $ordered_qty > 0) {
                    $unit_price = floatval($od_row['unit_price'] ?? 0);
                }
            }
            if ($unit_price <= 0) {
                $errors[] = "Item #{$item_num}: Unit price could not be determined. Set a wholesale price for this product.";
            }
            $pickup_items[$index]['unit_price'] = $unit_price;
        }
    }

    if (!empty($remarks) && strlen($remarks) > 500) {
        $errors[] = "Remarks must not exceed 500 characters.";
    }

    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }

    if (!empty($errors)) {
        sendResponse($conn, false, implode(' | ', $errors));
    }

    $openShift = getOpenCashShiftForUser($conn, (int) $user_id);
    if (!$openShift) {
        sendResponse($conn, false, 'Open a cashier shift before recording pickup sales.');
    }

    $customer_id = intval($order_row['Customer_ID'] ?? 0);
    $customer_info = $conn->prepare("SELECT c.customer_name, c.email FROM customers c WHERE c.Customer_ID = ?");
    $customer_info->execute([$customer_id]);
    $customer_row = $customer_info->fetch(PDO::FETCH_ASSOC);
    $customer_name = $customer_row['customer_name'] ?? '';
    $customer_email = $customer_row['email'] ?? '';

    $conn->beginTransaction();
    try {
        $columns_result = $conn->query("SHOW COLUMNS FROM sales");
        $existing_columns = [];
        while ($row = $columns_result->fetch()) {
            $existing_columns[] = $row['Field'];
        }

        $has_status = in_array('status', $existing_columns);
        $has_remarks = in_array('remarks', $existing_columns);
        $has_created_by = in_array('created_by', $existing_columns);
        $has_customer_id = in_array('Customer_ID', $existing_columns) || in_array('customer_id', $existing_columns);

        $insert_fields = [];
        $values_placeholders = [];
        $params = [];

        if (in_array('User_ID', $existing_columns)) {
            $insert_fields[] = 'User_ID';
            $values_placeholders[] = '?';
            $params[] = intval($user_id);
        } elseif (in_array('user_id', $existing_columns)) {
            $insert_fields[] = 'user_id';
            $values_placeholders[] = '?';
            $params[] = intval($user_id);
        }

        if ($has_created_by) {
            $insert_fields[] = 'created_by';
            $values_placeholders[] = '?';
            $params[] = intval($user_id);
        }

        if ($has_customer_id) {
            $cid = $customer_id;
            if ($cid <= 0) $cid = getOrCreateWalkinCustomerId($conn);
            if (in_array('Customer_ID', $existing_columns)) {
                $insert_fields[] = 'Customer_ID';
                $values_placeholders[] = '?';
                $params[] = $cid;
            } elseif (in_array('customer_id', $existing_columns)) {
                $insert_fields[] = 'customer_id';
                $values_placeholders[] = '?';
                $params[] = $cid;
            }
        }

        if ($has_status) {
            $insert_fields[] = 'status';
            $values_placeholders[] = "'Completed'";
        }

        if ($has_remarks) {
            $insert_fields[] = 'remarks';
            $values_placeholders[] = '?';
            $params[] = $remarks;
        }

        if (in_array('created_at', $existing_columns)) {
            $insert_fields[] = 'created_at';
            $values_placeholders[] = 'NOW()';
        }

        $sql = empty($insert_fields) ? "INSERT INTO sales () VALUES ()" : "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $values_placeholders) . ")";
        $sale_stmt = $conn->prepare($sql);
        $sale_stmt->execute($params);
        $sale_id = $conn->lastInsertId();

        foreach ($pickup_items as $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = floatval($item['quantity'] ?? 0);
            $unit_price = floatval($item['unit_price'] ?? 0);
            if ($quantity <= 0 || $unit_price <= 0) continue;

            $subtotal = $quantity * $unit_price;
            $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (Sale_ID, Product_ID, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $sale_detail_stmt->execute([$sale_id, $product_id, $quantity, $unit_price, $subtotal]);
            deductInventory($conn, $product_id, $quantity);
        }

        $total_sale_query = $conn->prepare("SELECT COALESCE(SUM(subtotal), 0) as total FROM sale_details WHERE Sale_ID = ?");
        $total_sale_query->execute([$sale_id]);
        $gross_sale_total = floatval($total_sale_query->fetchColumn() ?? 0);
        $effective_sale_total = $posted_sale_total > 0 ? $posted_sale_total : max(0, $gross_sale_total - $discount_amount);

        updateOrderAfterSale($conn, $order_id, intval($sale_id));

        // Post to AR
        $post_to_ar = isset($_POST['post_to_ar']) && ($_POST['post_to_ar'] === 'on' || $_POST['post_to_ar'] === '1' || $_POST['post_to_ar'] === true);
        $created_ar_context = null;
        if ($post_to_ar) {
            $dup_check = $conn->prepare("SELECT AR_ID FROM account_receivable WHERE Sale_ID = ? LIMIT 1");
            $dup_check->execute([$sale_id]);
            if ($dup_check->fetch()) {
                throw new Exception("AR record already exists for this sale.");
            }

            $amount_paid = $cash_received;

            $aging_days = 30;
            if ($customer_id > 0) {
                $customer_query = $conn->prepare("SELECT aging_days FROM customers WHERE Customer_ID = ?");
                $customer_query->execute([$customer_id]);
                $customer_data = $customer_query->fetch();
                if ($customer_data) $aging_days = intval($customer_data['aging_days'] ?? 30);
            }

            $due_date = date('Y-m-d', strtotime("+{$aging_days} days"));

            $invoice_amount = $effective_sale_total;
            $amount_due = max(0, $invoice_amount - $amount_paid);

            if ($amount_due > 0 && $customer_id > 0) {
                $credit_check_stmt = $conn->prepare("SELECT credit_limit, customer_name FROM customers WHERE Customer_ID = ?");
                $credit_check_stmt->execute([$customer_id]);
                $credit_info = $credit_check_stmt->fetch();

                $credit_limit = floatval($credit_info['credit_limit'] ?? 0);
                $customer_name = $credit_info['customer_name'] ?? 'Unknown';

                $outstanding_stmt = $conn->prepare("SELECT SUM(amount_due) as total FROM account_receivable WHERE Customer_ID = ? AND status NOT IN ('Paid', 'Closed')");
                $outstanding_stmt->execute([$customer_id]);
                $total_outstanding = floatval($outstanding_stmt->fetchColumn() ?? 0);

                $total_after_ar = $total_outstanding + $amount_due;
                if ($credit_limit > 0 && $total_after_ar > $credit_limit) {
                    logActivity('SALE', "Credit cap blocked pickup AR for customer {$customer_name} (ID: {$customer_id}). " .
                        "Outstanding: {$total_outstanding}, Limit: {$credit_limit}, Attempted AR: {$amount_due}", $customer_id);

                    throw new Exception(
                        "Adding this AR (\u{20B1}" . number_format($amount_due, 2) . ") would bring the total to \u{20B1}" . number_format($total_after_ar, 2) . ", exceeding the credit limit of \u{20B1}" . number_format($credit_limit, 2) . ".\n" .
                        "Reduce the AR amount so that total outstanding (\u{20B1}" . number_format($total_outstanding, 2) . ") + new AR stays within the credit limit."
                    );
                }

                $ar_stmt = $conn->prepare("INSERT INTO account_receivable 
                    (Sale_ID, Customer_ID, invoice_date, invoice_amount, opening_balance, amount_due, due_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ar_stmt->execute([$sale_id, $customer_id, date('Y-m-d'), $invoice_amount, $amount_due, $amount_due, $due_date, 'Open']);
                $ar_id = (int)$conn->lastInsertId();

                if ($amount_paid > 0) {
                    $pay_stmt = $conn->prepare("INSERT INTO ar_payment (payment_date, amount_paid, remaining_balance, collected_by) VALUES (?, ?, ?, ?)");
                    $pay_stmt->execute([date('Y-m-d'), $amount_paid, $amount_due, $user_id]);
                    $payment_id = $conn->lastInsertId();

                    $link_stmt = $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)");
                    $link_stmt->execute([$ar_id, $payment_id]);
                }

                $created_ar_context = [
                    'ar_id' => $ar_id,
                    'invoice_amount' => $invoice_amount,
                    'amount_due' => $amount_due,
                    'due_date' => $due_date,
                    'amount_paid' => $amount_paid,
                    'customer_id' => $customer_id,
                ];
            }
        }

        // Blank/zero cash on a cash pickup = exact payment
        if (!$post_to_ar && $cash_received <= 0) {
            $cash_received = $effective_sale_total;
        }

        $change_given = max(0, $cash_received - $effective_sale_total);
        $net_cash = max(0, $cash_received - $change_given);
        if (!$post_to_ar && $net_cash + 0.00001 < $effective_sale_total) {
            throw new Exception('Cash received is not enough to cover this pickup sale.');
        }

        recordCashSessionEntry($conn, [
            'shift_id' => (int) $openShift['shift_id'],
            'entry_type' => 'pickup_sale',
            'source_label' => 'Pickup Sale',
            'sale_id' => (int) $sale_id,
            'order_id' => (int) $order_id,
            'gross_amount' => $effective_sale_total,
            'cash_received' => $cash_received,
            'change_given' => $change_given,
            'net_cash' => $net_cash,
            'User_ID' => (int) $user_id,
        ]);

        $conn->commit();
        cacheInvalidateTable('sales');
        cacheInvalidateTable('sale_details');
        cacheInvalidateTable('products');
        cacheInvalidateTable('orders');
        cacheInvalidateTable('account_receivable');
        cacheInvalidateTable('ar_payment');
        cacheInvalidateTable('cash_session_entries');

        logActivity('SALE', "Recorded pickup sale from Order #{$order_id} (Sale #{$sale_id})", $sale_id);

        // Email receipt
        $to_email = trim((string)$customer_email);
        $to_name = trim((string)$customer_name);
        if (!empty($to_email) && filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            try {
                $items_stmt = $conn->prepare("SELECT sd.*, p.product_name, u.unit_name
                                              FROM sale_details sd 
                                              INNER JOIN products p ON sd.Product_ID = p.Product_ID 
                                              LEFT JOIN units u ON p.unit_id = u.unit_id
                                              WHERE sd.Sale_ID = ?
                                              ORDER BY sd.Sale_detail_ID ASC");
                $items_stmt->execute([$sale_id]);
                $email_items = [];
                while ($it = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pname = $it['product_name'];
                    if (!empty($it['unit_name'])) $pname .= " {$it['unit_name']}";
                    $email_items[] = [
                        'product_name' => $pname,
                        'quantity' => floatval($it['quantity']),
                        'unit_price' => floatval($it['unit_price']),
                        'subtotal' => floatval($it['subtotal'])
                    ];
                }

                $ar_balance = 0;
                if ($customer_id > 0) {
                    $outstanding_stmt = $conn->prepare("SELECT COALESCE(SUM(amount_due), 0) FROM account_receivable WHERE Customer_ID = ? AND status NOT IN ('Paid', 'Closed')");
                    $outstanding_stmt->execute([$customer_id]);
                    $ar_balance = floatval($outstanding_stmt->fetchColumn() ?? 0);
                }

                $sale_details = [
                    'sale_id' => $sale_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'payment_type' => $post_to_ar ? 'Accounts Receivable (Credit)' : 'Cash',
                    'gross_total' => $gross_sale_total,
                    'discount' => $discount_amount,
                    'total_amount' => $effective_sale_total,
                    'cash_received' => $cash_received,
                    'change_given' => $change_given,
                    'ar_balance' => $ar_balance
                ];

                sendDeliverySaleReceiptEmail($to_email, $to_name, $sale_details, $email_items);

                if ($created_ar_context && ($created_ar_context['amount_due'] ?? 0) > 0) {
                    sendARCreatedEmail(
                        $to_email,
                        $to_name,
                        (int)$created_ar_context['ar_id'],
                        (float)$created_ar_context['invoice_amount'],
                        (float)$created_ar_context['amount_due'],
                        (string)$created_ar_context['due_date'],
                        (int)$sale_id
                    );
                }

                if ($created_ar_context && ($created_ar_context['amount_paid'] ?? 0) > 0) {
                    $ctx = $created_ar_context;
                    $remaining = (float)$ctx['amount_due'];
                    $paid = (float)$ctx['amount_paid'];
                    sendARPaymentEmail(
                        $to_email,
                        $to_name,
                        (int)$ctx['ar_id'],
                        $paid,
                        $remaining,
                        $remaining <= 0,
                        (float)$ctx['invoice_amount']
                    );
                }
            } catch (Throwable $e) {
                logActivity('SYSTEM_ERROR', "Failed to send receipt email for pickup Sale #$sale_id: " . $e->getMessage(), $sale_id);
            }
        }

        try {
            publishRealtimeEvent([
                'event' => 'sale.pickup_recorded',
                'data' => [
                    'order_id' => (int)$order_id,
                    'sale_id' => (int)$sale_id,
                    'status' => 'Completed'
                ]
            ]);
        } catch (Throwable $e) {
            // Non-critical
        }

        sendResponse($conn, true, [
            'message' => "Pickup sale recorded successfully (Order #{$order_id}).",
            'sale_id' => $sale_id,
            'order_id' => $order_id
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse($conn, false, $e->getMessage());
    }
}

/**
 * Create walk-in sale
 */
function handleCreateWalkinSale($conn, $user_id) {
    $items = json_decode($_POST['items'] ?? '[]', true);
    $remarks = trim($_POST['remarks'] ?? '');
    $cash_received = max(0, floatval($_POST['cash_received'] ?? $_POST['amount_paid'] ?? 0));
    $posted_sale_total = max(0, floatval($_POST['sale_total'] ?? 0));
    $discount_amount = max(0, floatval($_POST['discount_amount'] ?? 0));
    
    if (empty($items)) {
        sendResponse($conn, false, "At least one item is required");
    }

    // 1. Validate Items & Stock Availability
    $valid_items = [];
    foreach ($items as $item) {
        $pid = intval($item['product_id'] ?? 0);
        $qty = floatval($item['quantity'] ?? 0);
        
        if ($pid <= 0 || $qty <= 0) continue;
        
        $available_stock = getAvailableStock($conn, $pid);

        if ($qty > $available_stock) {
            // Get product name for better error
            $p_stmt = $conn->prepare("SELECT product_name FROM products WHERE Product_ID = ?");
            $p_stmt->execute([$pid]);
            $p_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
            $p_name = $p_row['product_name'] ?? "Product #$pid";
            sendResponse($conn, false, "Insufficient stock for $p_name. Available: $available_stock, Requested: $qty");
        }
        
        $valid_items[] = $item;
    }
    
    if (empty($valid_items)) {
        sendResponse($conn, false, "No valid items in sale.");
    }

    $openShift = getOpenCashShiftForUser($conn, (int) $user_id);
    if (!$openShift) {
        sendResponse($conn, false, 'Open a cashier shift before recording walk-in sales.');
    }
    
    $conn->beginTransaction();
    try {
        $columns_result = $conn->query("SHOW COLUMNS FROM sales");
        $existing_columns = [];
        while ($row = $columns_result->fetch()) {
            $existing_columns[] = $row['Field'];
        }
        
        $has_status = in_array('status', $existing_columns);
        $has_remarks = in_array('remarks', $existing_columns);
        $has_created_by = in_array('created_by', $existing_columns);
        $has_customer_id = in_array('Customer_ID', $existing_columns) || in_array('customer_id', $existing_columns);
        
        $insert_fields = [];
        $placeholders = [];
        $params = [];

        if (in_array('User_ID', $existing_columns)) {
            $insert_fields[] = 'User_ID'; $placeholders[] = '?'; $params[] = intval($user_id);
        } elseif (in_array('user_id', $existing_columns)) {
            $insert_fields[] = 'user_id'; $placeholders[] = '?'; $params[] = intval($user_id);
        }
        
        if ($has_created_by) {
            $insert_fields[] = 'created_by'; $placeholders[] = '?'; $params[] = intval($user_id);
        }

        if ($has_customer_id) {
            $cid = getOrCreateWalkinCustomerId($conn);
            if (in_array('Customer_ID', $existing_columns)) {
                $insert_fields[] = 'Customer_ID'; $placeholders[] = '?'; $params[] = $cid;
            } elseif (in_array('customer_id', $existing_columns)) {
                $insert_fields[] = 'customer_id'; $placeholders[] = '?'; $params[] = $cid;
            }
        }
        
        if ($has_status) {
            $insert_fields[] = 'status'; $placeholders[] = "'Completed'";
        }
        
        if ($has_remarks) {
            $insert_fields[] = 'remarks'; $placeholders[] = '?'; $params[] = $remarks;
        }
        
        if (in_array('created_at', $existing_columns)) {
            $insert_fields[] = 'created_at'; $placeholders[] = 'NOW()';
        }

        $sql = empty($insert_fields) ? "INSERT INTO sales () VALUES ()" : "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $sale_stmt = $conn->prepare($sql);
        $sale_stmt->execute($params);
        $sale_id = $conn->lastInsertId();
        
        foreach ($valid_items as $item) { // Changed from $items to $valid_items
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = floatval($item['quantity'] ?? 0);
            if ($quantity <= 0) continue;
            
            $product_stmt = $conn->prepare("SELECT retail_price FROM products WHERE Product_ID = ?");
            $product_stmt->execute([$product_id]);
            $product = $product_stmt->fetch();
            if (!$product) continue;
            
            $unit_price = floatval($product['retail_price']);
            $subtotal = $quantity * $unit_price;
            
            $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (Sale_ID, Product_ID, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $sale_detail_stmt->execute([$sale_id, $product_id, $quantity, $unit_price, $subtotal]);
            deductInventory($conn, $product_id, $quantity);
        }

        $total_sale_query = $conn->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM sale_details WHERE Sale_ID = ?");
        $total_sale_query->execute([$sale_id]);
        $gross_sale_total = floatval($total_sale_query->fetchColumn() ?? 0);
        $effective_sale_total = $posted_sale_total > 0 ? $posted_sale_total : max(0, $gross_sale_total - $discount_amount);
        $change_given = max(0, $cash_received - $effective_sale_total);
        $net_cash = max(0, $cash_received - $change_given);

        if ($net_cash + 0.00001 < $effective_sale_total) {
            throw new Exception('Cash received is not enough to cover this walk-in sale.');
        }

        recordCashSessionEntry($conn, [
            'shift_id' => (int) $openShift['shift_id'],
            'entry_type' => 'walk_in_sale',
            'source_label' => 'Walk-in Cash Sale',
            'sale_id' => (int) $sale_id,
            'gross_amount' => $effective_sale_total,
            'cash_received' => $cash_received,
            'change_given' => $change_given,
            'net_cash' => $net_cash,
            'User_ID' => (int) $user_id,
        ]);
        
        $conn->commit();
        cacheInvalidateTable('sales');
        cacheInvalidateTable('sale_details');
        cacheInvalidateTable('products');

        logActivity('SALE', "Recorded walk-in sale (Total Items: " . count($items) . ")", $sale_id);

        sendResponse($conn, true, [
            'message' => "Walk-in sale recorded successfully.",
            'sale_id' => $sale_id
        ]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse($conn, false, $e->getMessage());
    }
}

/**
 * Deduct inventory when payment is received
 */
function deductInventory($conn, $product_id, $quantity) {
    $product_id = (int)$product_id;
    $quantity = (float)$quantity;
    if ($product_id <= 0 || $quantity <= 0) {
        return;
    }

    $physical_before = getPhysicalStock($conn, $product_id);
    $new_quantity = max(0, $physical_before - $quantity);

    $product_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
    $product_cols = $product_cols_stmt ? $product_cols_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (in_array('quantity', $product_cols, true)) {
        $update_stmt = $conn->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE Product_ID = ?");
        $update_stmt->execute([$new_quantity, $product_id]);
    } else {
        $inv_stmt = $conn->prepare("SELECT Inventory_ID FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
        $inv_stmt->execute([$product_id]);
        $inv = $inv_stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $update_stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
            $update_stmt->execute([$new_quantity, (int)$inv['Inventory_ID']]);
        }
    }

    $user_id = $_SESSION['user_id'] ?? null;
    $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, quantity_change, balance_after, handled_by, notes) VALUES (?, 'SALES', ?, ?, ?, 'Sale Transaction')");
    $ledger_stmt->execute([$product_id, -$quantity, $new_quantity, $user_id]);
}

/**
 * Fetch sales history with pagination support
 * Performance Update: Fixed N+1 query issue - batch queries instead of per-record queries
 * 
 * Query params: page (default 1), per_page (default 50, max 100)
 */
function handleGetSalesHistory($conn) {
    try {
        // Pagination parameters (Performance Fix)
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = min(100, max(1, intval($_GET['per_page'] ?? 50))); // Max 100 per page
        $offset = ($page - 1) * $per_page;
        
        // Filter and Search parameters
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['search'] ?? '');
        
        $where = [];
        $params = [];
        
        if ($search !== '') {
            $where[] = "s.Sale_ID = ?";
            $params[] = intval($search);
        }
        
        if ($filter === 'daily') {
            $where[] = "DATE(s.created_at) = CURDATE()";
        } elseif ($filter === 'weekly') {
            $where[] = "YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($filter === 'monthly') {
            $where[] = "MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE())";
        }
        
        $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
        
        // Get total count for pagination (Performance Fix)
        $count_sql = "SELECT COUNT(*) FROM sales s " . $where_sql;
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = (int) $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $per_page) ?: 1;
        
        // Fetch sales with customer and user info
        $stmt_sql = "SELECT s.*, c.customer_name, u.full_name as recorder_name, ss.Delivery_ID 
                               FROM sales s 
                               LEFT JOIN customers c ON s.Customer_ID = c.Customer_ID 
                               LEFT JOIN user u ON s.User_ID = u.User_ID 
                               LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID 
                               $where_sql
                               ORDER BY s.created_at DESC 
                               LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($stmt_sql);
        
        $stmt_params = array_merge($params, [$per_page, $offset]);
        // PDO bindParam requires passing by reference or careful executing.
        // It's easier to bind manually if mixing int with string, or execute will make them strings.
        // LIMIT/OFFSET must be bound as INT explicitly.
        foreach ($params as $key => $value) {
            $stmt->bindValue($key + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sales)) {
            echo json_encode([
                'success' => true, 
                'data' => [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_records' => $total_records,
                    'total_pages' => $total_pages,
                    'has_next' => $page < $total_pages,
                    'has_prev' => $page > 1
                ]
            ]);
            return;
        }
        
        // N+1 Query Fix: Collect all Sale_IDs for batch queries
        $sale_ids = array_column($sales, 'Sale_ID');
        $placeholders = implode(',', array_fill(0, count($sale_ids), '?'));
        
        // Batch Query 1: Fetch all sale_details with product names for all sales
        $details_stmt = $conn->prepare("SELECT sd.*, p.product_name 
                                     FROM sale_details sd 
                                     LEFT JOIN products p ON sd.Product_ID = p.Product_ID 
                                     WHERE sd.Sale_ID IN ({$placeholders})");
        $details_stmt->execute($sale_ids);
        $all_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group details by Sale_ID
        $details_by_sale = [];
        foreach ($all_details as $detail) {
            $sale_id = $detail['Sale_ID'];
            if (!isset($details_by_sale[$sale_id])) {
                $details_by_sale[$sale_id] = [];
            }
            $details_by_sale[$sale_id][] = $detail;
        }
        
        // Batch Query 2: Fetch all totals in one query
        $totals_stmt = $conn->prepare("SELECT Sale_ID, COALESCE(SUM(subtotal), 0) AS total_amount 
                                     FROM sale_details 
                                     WHERE Sale_ID IN ({$placeholders})
                                     GROUP BY Sale_ID");
        $totals_stmt->execute($sale_ids);
        $totals = $totals_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Batch Query 3: Fetch all AR info in one query
        $ar_stmt = $conn->prepare("SELECT Sale_ID, invoice_amount, amount_due, status 
                                  FROM account_receivable 
                                  WHERE Sale_ID IN ({$placeholders})");
        $ar_stmt->execute($sale_ids);
        $ar_by_sale = [];
        while ($row = $ar_stmt->fetch(PDO::FETCH_ASSOC)) {
            $ar_by_sale[$row['Sale_ID']] = $row;
        }
        
        // Merge data into sales array
        foreach ($sales as &$sale) {
            $sale_id = $sale['Sale_ID'];
            
            // Attach details
            $sale['details'] = $details_by_sale[$sale_id] ?? [];
            
            // Attach total amount
            $sale['total_amount'] = floatval($totals[$sale_id] ?? 0);
            
            // Attach payment info
            if (isset($ar_by_sale[$sale_id])) {
                $ar_row = $ar_by_sale[$sale_id];
                $invoice_amount = floatval($ar_row['invoice_amount'] ?? 0);
                $amount_due = floatval($ar_row['amount_due'] ?? 0);
                $sale['payment'] = 'AR';
                $sale['amount_paid'] = $invoice_amount - $amount_due;
                $sale['sale_type'] = !empty($sale['Delivery_ID']) ? 'Pre-Order (Wholesale)' : 'Walk-in (Retail)';
            } else {
                $sale['payment'] = 'CASH';
                $sale['amount_paid'] = $sale['total_amount'];
                $sale['sale_type'] = !empty($sale['Delivery_ID']) ? 'Pre-Order (Wholesale)' : 'Walk-in (Retail)';
            }
        }

        echo json_encode([
            'success' => true, 
            'data' => $sales,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Calculate Z-Read summary for today
 */
function handleGetZRead($conn, int $user_id) {
    try {
        $shift = getOpenCashShiftForUser($conn, $user_id);
        if (!$shift) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'No open shift found for this cashier.',
            ]);
            return;
        }

        $shift_id = (int)($shift['shift_id'] ?? 0);
        $totals = calculateShiftTotalsDetailed($conn, $shift_id);
        $summary = [
            'total_count' => (int)($totals['total_count'] ?? 0),
            'gross_sales' => (float)($totals['gross_sales'] ?? 0),
            'void_count' => (int)($totals['void_count'] ?? 0),
            'void_amount' => (float)($totals['void_amount'] ?? 0),
            'net_sales' => (float)($totals['cash_sales'] ?? 0),
            'items' => [],
            'shift_id' => $shift_id,
        ];

        $item_stmt = $conn->prepare("
            SELECT p.product_name, COALESCE(SUM(sd.quantity), 0) AS total_qty, COALESCE(SUM(sd.subtotal), 0) AS total_amount
            FROM sale_details sd
            JOIN sales s ON sd.Sale_ID = s.Sale_ID
            JOIN products p ON sd.Product_ID = p.Product_ID
            WHERE s.created_at >= ?
              AND s.created_at <= COALESCE(?, NOW())
              AND COALESCE(s.User_ID, 0) = ?
              AND COALESCE(s.status, '') <> 'Voided'
            GROUP BY sd.Product_ID, p.product_name
            ORDER BY total_amount DESC
        ");
        $item_stmt->execute([
            $shift['shift_start_time'],
            $shift['shift_end_time'] ?? null,
            $user_id,
        ]);
        $summary['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $summary]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        error_log('Z-Read error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred while fetching Z-Read data.']);
    }
}

/**
 * List all active customers for the customer reassign dropdown
 */
function handleListCustomers($conn) {
    $stmt = $conn->prepare("SELECT Customer_ID, customer_name FROM customers WHERE deleted_at IS NULL ORDER BY customer_name");
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $customers]);
}

/**
 * Reassign a sale to a different customer.
 * Only Owner (1) and Manager (2, 4) can perform this.
 */
function handleUpdateSaleCustomer($conn, $user_id) {
    $sale_id = intval($_POST['sale_id'] ?? 0);
    $customer_id = intval($_POST['customer_id'] ?? 0);

    if ($sale_id <= 0 || $customer_id <= 0) {
        sendResponse($conn, false, "Invalid Sale ID or Customer ID.");
    }

    try {
        // Verify sale exists
        $check = $conn->prepare("SELECT Sale_ID, status FROM sales WHERE Sale_ID = ?");
        $check->execute([$sale_id]);
        $sale = $check->fetch();
        if (!$sale) throw new Exception("Sale not found.");

        // Verify customer exists and is not soft-deleted
        $cCheck = $conn->prepare("SELECT Customer_ID, customer_name FROM customers WHERE Customer_ID = ? AND deleted_at IS NULL");
        $cCheck->execute([$customer_id]);
        $customer = $cCheck->fetch();
        if (!$customer) throw new Exception("Customer not found.");

        // Update sale's Customer_ID
        $upd = $conn->prepare("UPDATE sales SET Customer_ID = ?, updated_at = NOW() WHERE Sale_ID = ?");
        $upd->execute([$customer_id, $sale_id]);

        // Also update linked AR records
        $arUpd = $conn->prepare("UPDATE account_receivable SET Customer_ID = ? WHERE Sale_ID = ?");
        $arUpd->execute([$customer_id, $sale_id]);

        cacheInvalidateTable('sales');
        logActivity('EDIT_SALE_CUSTOMER', "Changed customer for Sale #{$sale_id} to {$customer['customer_name']} (ID: {$customer_id})", $sale_id);
        sendResponse($conn, true, "Sale #{$sale_id} reassigned to {$customer['customer_name']}.");
    } catch (Exception $e) {
        sendResponse($conn, false, $e->getMessage());
    }
}

/**
 * Void a sale and reverse inventory
 */
function handleVoidSale($conn, $user_id) {
    $sale_id = intval($_POST['sale_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($sale_id <= 0) {
        sendResponse($conn, false, "Invalid Sale ID");
    }

    $conn->beginTransaction();
    try {
        // Check if already voided
        $check = $conn->prepare("SELECT status FROM sales WHERE Sale_ID = ? FOR UPDATE");
        $check->execute([$sale_id]);
        $sale = $check->fetch();
        
        if (!$sale) throw new Exception("Sale not found");
        if ($sale['status'] === 'Voided') throw new Exception("Sale is already voided");

        // Update status
        $upd = $conn->prepare("UPDATE sales SET status = 'Voided', updated_at = NOW() WHERE Sale_ID = ?");
        $upd->execute([$sale_id]);

        // Reverse inventory
        $details_stmt = $conn->prepare("SELECT Product_ID, quantity FROM sale_details WHERE Sale_ID = ?");
        $details_stmt->execute([$sale_id]);
        $details = $details_stmt->fetchAll();

        foreach ($details as $item) {
            // Add back to inventory (find latest stock record)
            $product_id = (int)$item['Product_ID'];
            $qty_to_add = (float)$item['quantity'];
            $physical_before = getPhysicalStock($conn, $product_id);
            $new_qty = $physical_before + $qty_to_add;

            $product_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
            $product_cols = $product_cols_stmt ? $product_cols_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            if (in_array('quantity', $product_cols, true)) {
                $upd_inv = $conn->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE Product_ID = ?");
                $upd_inv->execute([$new_qty, $product_id]);
            } else {
                $inv_stmt = $conn->prepare("SELECT Inventory_ID FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
                $inv_stmt->execute([$product_id]);
                $inv = $inv_stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv) {
                    $upd_inv = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
                    $upd_inv->execute([$new_qty, (int)$inv['Inventory_ID']]);
                }
            }
        }

        // Handle linked Delivery/Order (Optional: could revert status to 'Delivered' or keep 'Completed' but clear Sale link)
        // For now, we'll keep the order 'Completed' but log the void.

        $conn->commit();
        cacheInvalidateTable('sales');
        cacheInvalidateTable('products');
        logActivity('VOID_SALE', "Voided Sale #$sale_id. Reason: $reason", $sale_id);
        sendResponse($conn, true, "Sale #$sale_id has been voided.");
    } catch (Exception $e) {
        $conn->rollBack();
        sendResponse($conn, false, $e->getMessage());
    }
}

/**
 * Fetches full details of a specific sale for receipt generation.
 */
function handleGetSaleDetails($conn) {
    $sale_id = $_GET['sale_id'] ?? 0;
    
    if (!$sale_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid Sale ID']);
        return;
    }
    
    try {
        // Get sale header
        $stmt = $conn->prepare("
            SELECT s.*, c.customer_name as customer_name, u.full_name as cashier_name, ss.Delivery_ID
            FROM sales s
            LEFT JOIN customers c ON s.Customer_ID = c.Customer_ID
            LEFT JOIN user u ON s.User_ID = u.User_ID
            LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
            WHERE s.Sale_ID = ?
        ");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sale) {
            echo json_encode(['success' => false, 'message' => 'Sale not found']);
            return;
        }
        
        // Get sale details (items)
        $stmt = $conn->prepare("
            SELECT sd.*, p.product_name, un.unit_name
            FROM sale_details sd
            JOIN products p ON sd.Product_ID = p.Product_ID
            LEFT JOIN units un ON p.unit_id = un.unit_id
            WHERE sd.Sale_ID = ?
        ");
        $stmt->execute([$sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute totals + payment info for receipt display
        $total_amount = 0;
        foreach ($items as $it) {
            $total_amount += floatval($it['subtotal'] ?? 0);
        }

        $ar_stmt = $conn->prepare("SELECT invoice_amount, amount_due, status FROM account_receivable WHERE Sale_ID = ? LIMIT 1");
        $ar_stmt->execute([$sale_id]);
        $ar_row = $ar_stmt->fetch(PDO::FETCH_ASSOC);

        if ($ar_row) {
            $invoice_amount = floatval($ar_row['invoice_amount'] ?? 0);
            $amount_due = floatval($ar_row['amount_due'] ?? 0);
            $sale['payment'] = 'AR';
            $sale['amount_paid'] = $invoice_amount - $amount_due;
        } else {
            $sale['payment'] = 'CASH';
            $sale['amount_paid'] = $total_amount;
        }

        $sale['total_amount'] = $total_amount;
        $sale['sale_type'] = !empty($sale['Delivery_ID']) ? 'Pre-Order (Wholesale)' : 'Walk-in (Retail)';
        
        echo json_encode([
            'success' => true,
            'data' => [
                'sale' => $sale,
                'items' => $items
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
