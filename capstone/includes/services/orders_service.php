<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/orders_helper.php';
require_once __DIR__ . '/../repositories/orders_repository.php';
require_once __DIR__ . '/../order_cancellation_helper.php';
require_once __DIR__ . '/../delivery_cancellation_helper.php';
require_once __DIR__ . '/../rider_availability_helper.php';
require_once __DIR__ . '/../roles_helper.php';
require_once __DIR__ . '/../stock_reservation_helper.php';
require_once __DIR__ . '/../../realtime/publish_event.php';
require_once __DIR__ . '/../preparation_tasks_helper.php';

function ordersNormalizeStatus(string $status): string
{
    $key = strtolower(trim($status));
    $key = preg_replace('/\s+/', ' ', $key);
    switch ($key) {
        case 'requested':
        case 'pending':
        case 'confirmed':
            return 'pending';
        case 'scheduled':
        case 'scheduled for delivery':
            return 'scheduled';
        case 'out for delivery':
        case 'in transit':
            return 'out_for_delivery';
        case 'delivered':
        case 'delivered (pending cash turnover)':
        case 'pending cash turnover':
        case 'remitted':
            return 'handoff_done';
        case 'completed':
            return 'completed';
        case 'cancelled':
        case 'canceled':
            return 'cancelled';
        default:
            return $key;
    }
}

function ordersCanManagerTransition(string $fromStatus, string $toStatus): bool
{
    $from = ordersNormalizeStatus($fromStatus);
    $to = ordersNormalizeStatus($toStatus);
    $allowed = [
        'pending' => ['scheduled'],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

function ordersGetLatestDeliveryRecord(PDO $conn, int $orderId): ?array
{
    if ($orderId <= 0 || !ordersRepoTableExists($conn, 'delivery')) {
        return null;
    }

    $stmt = $conn->prepare("SELECT Delivery_ID, delivery_status FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ordersDeliveryHistoryLocked(?string $status): bool
{
    return in_array((string)$status, ['Delivered', 'Returning', 'Completed', 'Cancelled', 'Remitted'], true);
}

function ordersGetLatestAssignedRiderId(PDO $conn, int $orderId): int
{
    if ($orderId <= 0 || !ordersRepoTableExists($conn, 'delivery')) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $deliveryId = (int)$stmt->fetchColumn();
    if ($deliveryId <= 0) {
        return 0;
    }

    return riderGetUserIdByDeliveryId($conn, $deliveryId);
}

function ordersHandleCreateOrder(PDO $conn, int $userId): void
{
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $order_date = (string)($_POST['order_date'] ?? '');
    $order_time = (string)($_POST['order_time'] ?? date('H:i:s'));
    $delivery_address = trim((string)($_POST['delivery_address'] ?? ''));
    $posted_dest_lat = isset($_POST['destination_lat']) ? (float)$_POST['destination_lat'] : null;
    $posted_dest_lng = isset($_POST['destination_lng']) ? (float)$_POST['destination_lng'] : null;
    $posted_dest_label = trim((string)($_POST['destination_label'] ?? ''));
    $posted_dest_source = trim((string)($_POST['destination_source'] ?? ''));
    $posted_dest_conf = trim((string)($_POST['destination_confidence'] ?? ''));
    $posted_dest_verified = (int)($_POST['destination_verified'] ?? 0);
    $has_posted_dest = ordersHasValidCoordinates($posted_dest_lat, $posted_dest_lng);
    $delivery_date = !empty($_POST['delivery_date']) ? (string)$_POST['delivery_date'] : null;
    $delivery_person_meta = ordersRepoResolveDeliveryPerson($conn, (string)($_POST['delivery_person'] ?? ''));
    $delivery_person_id = $delivery_person_meta['id'];
    $delivery_person = $delivery_person_meta['name'];
    $notes = '';
    $payment_method_label = 'Cash on Delivery';
    $discount_amount = 0.0;
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);

    $errors = [];
    if ($customer_id <= 0) {
        $errors[] = "Customer is required.";
    } else {
        $customer_check = $conn->prepare("SELECT Customer_ID FROM customers WHERE Customer_ID = ?");
        $customer_check->execute([$customer_id]);
        if (!$customer_check->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = "Selected customer does not exist.";
        }
    }

    if (empty($order_date) || !ordersValidateDate($order_date)) {
        $errors[] = "Invalid order date format.";
    }

    if (!empty($order_time) && !ordersValidateTime($order_time)) {
        $errors[] = "Invalid order time format. Use HH:MM:SS format.";
    }

    if (empty($items) || !is_array($items)) {
        $errors[] = "At least one item is required.";
    } else {
        foreach ($items as $index => $item) {
            $item_num = $index + 1;
            $product_id = (int)($item['product_id'] ?? 0);
            if ($product_id <= 0) {
                $errors[] = "Item #{$item_num}: Product is required.";
                continue;
            }
            $product_check = $conn->prepare("SELECT is_discontinued FROM products WHERE Product_ID = ?");
            $product_check->execute([$product_id]);
            $product_data = $product_check->fetch(PDO::FETCH_ASSOC);
            if (!$product_data) {
                $errors[] = "Item #{$item_num}: Product does not exist.";
            } elseif ((int)$product_data['is_discontinued'] === 1) {
                $errors[] = "Item #{$item_num}: Cannot order discontinued products.";
            }

            $quantity = (float)($item['quantity'] ?? 0);
            $unit_price = (float)($item['unit_price'] ?? 0);
            if ($quantity <= 0) $errors[] = "Item #{$item_num}: Quantity must be greater than 0.";
            if ($quantity > 999999) $errors[] = "Item #{$item_num}: Quantity exceeds maximum allowed (999,999).";
            if ($unit_price < 0) $errors[] = "Item #{$item_num}: Unit price cannot be negative.";
            if ($unit_price > 999999) $errors[] = "Item #{$item_num}: Unit price exceeds maximum allowed (P999,999).";
        }
    }

    if (!empty($delivery_date) && !ordersValidateDate($delivery_date)) {
        $errors[] = "Invalid delivery date format.";
    }
    if (!empty($delivery_person) && strlen($delivery_person) > 100) {
        $errors[] = "Delivery person name must not exceed 100 characters.";
    }
    if ($delivery_person_id !== null && !riderCanBeAssignedToTarget($conn, $delivery_person_id)) {
        $errors[] = "Selected rider is not available for assignment.";
    }
    if (!empty($delivery_address) && strlen($delivery_address) > 500) {
        $errors[] = "Delivery address must not exceed 500 characters.";
    }
    $line_total = 0.0;
    if (empty($errors)) {
        foreach ($items as $item) {
            $line_total += (float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0);
        }
    }

    if (!empty($errors)) {
        $_SESSION['order_errors'] = $errors;
        header("Location: ../pages/orders.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }

    // Server-side Credit & Balance Check
    try {
        $credit_stmt = $conn->prepare("SELECT credit_limit FROM customers WHERE Customer_ID = ?");
        $credit_stmt->execute([$customer_id]);
        $customer_credit_limit = (float)($credit_stmt->fetchColumn() ?: 0);

        $unpaid_stmt = $conn->prepare("SELECT SUM(amount_due) FROM account_receivable WHERE Customer_ID = ? AND status NOT IN ('Paid', 'Closed')");
        $unpaid_stmt->execute([$customer_id]);
        $total_unpaid = (float)($unpaid_stmt->fetchColumn() ?: 0);

        // Combined credit check: allow orders as long as total unpaid + new order doesn't exceed credit limit
        $total_after_order = $total_unpaid + $line_total;
        if ($customer_credit_limit > 0 && $total_after_order > $customer_credit_limit) {
            $errors[] = "Order blocked: This order would bring the customer's total balance to \u{20B1}" . number_format($total_after_order, 2) . ", exceeding their credit limit of \u{20B1}" . number_format($customer_credit_limit, 2) . ".";
        }
    } catch (Exception $e) {
        // Log error but don't block order if credit check fails (fallback)
        error_log("Credit check failed: " . $e->getMessage());
    }

    if (!empty($errors)) {
        $_SESSION['order_errors'] = $errors;
        header("Location: ../pages/orders.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }

    $total_amount = max(0, $line_total - $discount_amount);
    $columns_result = $conn->query("SHOW COLUMNS FROM orders");
    $existing_columns = [];
    $order_status_type = null;
    while ($row = $columns_result->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $row['Field'];
        if ($row['Field'] === 'order_status') {
            $order_status_type = $row['Type'];
        }
    }

    $conn->beginTransaction();
    try {
        $order_status_value = 'pending';
        if (strpos(strtolower((string)$order_status_type), 'enum') !== false) {
            preg_match("/enum\s*\((.+)\)/i", (string)$order_status_type, $matches);
            if (!empty($matches[1])) {
                $enum_values = array_map(static function ($v) {
                    return trim($v, " '\"");
                }, explode(',', $matches[1]));
                if (!in_array('Requested', $enum_values, true)) {
                    $order_status_value = !empty($enum_values) ? $enum_values[0] : 'Requested';
                }
            }
        }

        $insert_fields = ['Customer_ID', 'order_date', 'order_status', 'total_amount', 'remarks'];
        $insert_values = ['?', '?', '?', '?', '?'];
        $bind_params = [$customer_id, $order_date, $order_status_value, $total_amount, $notes];

        if (in_array('order_time', $existing_columns, true)) {
            $insert_fields[] = 'order_time';
            $insert_values[] = '?';
            $bind_params[] = $order_time;
        }
        if (in_array('delivery_address', $existing_columns, true)) {
            $insert_fields[] = 'delivery_address';
            $insert_values[] = '?';
            $bind_params[] = $delivery_address;
        }
        if (in_array('delivery_date', $existing_columns, true) && !empty($delivery_date)) {
            $insert_fields[] = 'delivery_date';
            $insert_values[] = '?';
            $bind_params[] = $delivery_date;
        }
        if (in_array('created_by', $existing_columns, true)) {
            $insert_fields[] = 'created_by';
            $insert_values[] = '?';
            $bind_params[] = $userId;
        }
        if (in_array('payment_method', $existing_columns, true)) {
            $insert_fields[] = 'payment_method';
            $insert_values[] = '?';
            $bind_params[] = $payment_method_label;
        }
        if (in_array('is_ar', $existing_columns, true)) {
            $insert_fields[] = 'is_ar';
            $insert_values[] = '?';
            $bind_params[] = (int)($_POST['is_ar'] ?? 0);
        }
        if (in_array('created_at', $existing_columns, true)) {
            $insert_fields[] = 'created_at';
            $insert_values[] = 'NOW()';
        }

        $sql = "INSERT INTO orders (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
        $stmt = $conn->prepare($sql);
        if (!$stmt->execute($bind_params)) {
            throw new Exception("Failed to create order");
        }

        $order_id = (int)$conn->lastInsertId();
        $item_stmt = $conn->prepare("INSERT INTO order_details (Order_ID, Product_ID, ordered_qty, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($items as $item) {
            if (!$item_stmt->execute([$order_id, (int)$item['product_id'], (float)$item['quantity'], (float)$item['unit_price']])) {
                throw new Exception("Failed to insert order detail");
            }
        }

        // Snapshot customer data at order creation time
        $snap_cust = $conn->prepare("SELECT customer_name, phone_number, address FROM customers WHERE Customer_ID = ?");
        $snap_cust->execute([$customer_id]);
        $cust_data = $snap_cust->fetch(PDO::FETCH_ASSOC);
        if ($cust_data) {
            $snap_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $snap_set = [];
            $snap_params = [];
            if (in_array('customer_name_snapshot', $snap_cols, true)) {
                $snap_set[] = "customer_name_snapshot = ?";
                $snap_params[] = $cust_data['customer_name'];
            }
            if (in_array('customer_phone_snapshot', $snap_cols, true)) {
                $snap_set[] = "customer_phone_snapshot = ?";
                $snap_params[] = $cust_data['phone_number'];
            }
            if (in_array('customer_address_snapshot', $snap_cols, true)) {
                $snap_set[] = "customer_address_snapshot = ?";
                $snap_params[] = $cust_data['address'];
            }
            if (!empty($snap_set)) {
                $snap_params[] = $order_id;
                $conn->prepare("UPDATE orders SET " . implode(', ', $snap_set) . " WHERE Order_ID = ?")->execute($snap_params);
            }
        }

        // Snapshot product data for each order_detail
        $snap_prod = $conn->prepare("SELECT p.product_name, u.unit_name FROM products p LEFT JOIN units u ON p.unit_id = u.unit_id WHERE p.Product_ID = ?");
        $snap_od_cols = array_column($conn->query("SHOW COLUMNS FROM order_details")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $has_prod_snap = in_array('product_name_snapshot', $snap_od_cols, true);
        $has_unit_snap = in_array('unit_name_snapshot', $snap_od_cols, true);
        if ($has_prod_snap || $has_unit_snap) {
            $snap_od_upd = $conn->prepare("UPDATE order_details SET product_name_snapshot = ?, unit_name_snapshot = ? WHERE Order_detail_ID = ?");
            $det_stmt = $conn->prepare("SELECT Order_detail_ID, Product_ID FROM order_details WHERE Order_ID = ?");
            $det_stmt->execute([$order_id]);
            while ($det = $det_stmt->fetch(PDO::FETCH_ASSOC)) {
                $snap_prod->execute([(int)$det['Product_ID']]);
                $prod_data = $snap_prod->fetch(PDO::FETCH_ASSOC);
                if ($prod_data) {
                    $snap_od_upd->execute([
                        $has_prod_snap ? $prod_data['product_name'] : null,
                        $has_unit_snap ? ($prod_data['unit_name'] ?? '') : null,
                        (int)$det['Order_detail_ID']
                    ]);
                }
            }
        }

        if (!empty($delivery_person) && ordersRepoTableExists($conn, 'delivery')) {
            $customer_stmt = $conn->prepare("SELECT customer_name FROM customers WHERE Customer_ID = ?");
            $customer_stmt->execute([$customer_id]);
            $customer_name = (string)($customer_stmt->fetchColumn() ?: '');
            $delivery_columns = ordersRepoGetColumns($conn, 'delivery');

            $delivery_fields = ['Order_ID'];
            $delivery_values = ['?'];
            $delivery_params = [$order_id];
            if (in_array('delivery_address', $delivery_columns, true)) { $delivery_fields[] = 'delivery_address'; $delivery_values[] = '?'; $delivery_params[] = $delivery_address; }
            if ($has_posted_dest && in_array('destination_lat', $delivery_columns, true)) { $delivery_fields[] = 'destination_lat'; $delivery_values[] = '?'; $delivery_params[] = $posted_dest_lat; }
            if ($has_posted_dest && in_array('destination_lng', $delivery_columns, true)) { $delivery_fields[] = 'destination_lng'; $delivery_values[] = '?'; $delivery_params[] = $posted_dest_lng; }
            if ($has_posted_dest && in_array('destination_label', $delivery_columns, true)) { $delivery_fields[] = 'destination_label'; $delivery_values[] = '?'; $delivery_params[] = $posted_dest_label; }
            if ($has_posted_dest && in_array('destination_source', $delivery_columns, true)) { $delivery_fields[] = 'destination_source'; $delivery_values[] = '?'; $delivery_params[] = ($posted_dest_source !== '' ? $posted_dest_source : 'auto'); }
            if ($has_posted_dest && in_array('destination_confidence', $delivery_columns, true)) { $delivery_fields[] = 'destination_confidence'; $delivery_values[] = '?'; $delivery_params[] = ($posted_dest_conf !== '' ? $posted_dest_conf : 'low'); }
            if ($has_posted_dest && in_array('destination_verified', $delivery_columns, true)) { $delivery_fields[] = 'destination_verified'; $delivery_values[] = '?'; $delivery_params[] = ($posted_dest_verified ? 1 : 0); }
            if (in_array('schedule_date', $delivery_columns, true)) { $delivery_fields[] = 'schedule_date'; $delivery_values[] = '?'; $delivery_params[] = $delivery_date; }
            if (in_array('delivery_status', $delivery_columns, true)) { $delivery_fields[] = 'delivery_status'; $delivery_values[] = "'Scheduled'"; }
            if (in_array('delivered_by', $delivery_columns, true)) { $delivery_fields[] = 'delivered_by'; $delivery_values[] = '?'; $delivery_params[] = $delivery_person; }
            if (in_array('delivered_to', $delivery_columns, true)) { $delivery_fields[] = 'delivered_to'; $delivery_values[] = '?'; $delivery_params[] = $customer_name; }
            if ($delivery_person_id !== null) {
                if (riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id')) {
                    $delivery_fields[] = 'assigned_rider_id';
                    $delivery_values[] = '?';
                    $delivery_params[] = $delivery_person_id;
                }
                if (riderWorkflowHasColumn($conn, 'delivery', 'delivered_by_user_id')) {
                    $delivery_fields[] = 'delivered_by_user_id';
                    $delivery_values[] = '?';
                    $delivery_params[] = $delivery_person_id;
                }
            }

            $delivery_sql = "INSERT INTO delivery (" . implode(', ', $delivery_fields) . ") VALUES (" . implode(', ', $delivery_values) . ")";
            $delivery_stmt = $conn->prepare($delivery_sql);
            $delivery_stmt->execute($delivery_params);
            if ($delivery_person_id !== null) {
                syncRiderAvailabilityForUser($conn, $delivery_person_id);
            }

            // Auto-create prep task for inventory staff
            if (function_exists('prepTasksUpdateStatus')) {
                prepTasksUpdateStatus($conn, $order_id, 'not_started', $userId);
            }

            // Log notification in activity_logs for inventory staff
            try {
                $invStaffRoleIds = function_exists('getInventoryStaffRoleIds') ? getInventoryStaffRoleIds($conn) : [];
                if (!empty($invStaffRoleIds)) {
                    $placeholders = implode(',', array_fill(0, count($invStaffRoleIds), '?'));
                    $staffStmt = $conn->prepare("SELECT User_ID FROM user WHERE Role_ID IN ($placeholders) AND is_active = 1");
                    $staffStmt->execute(array_values($invStaffRoleIds));
                    $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                    $customerName = $customer_name ?: "Customer ID: $customer_id";
                    $notifMsg = "New order #{$order_id} for {$customerName} needs preparation. Delivery: " . ($delivery_date ?: 'Not set');
                    $notifStmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID) VALUES (?, 'NOTIFICATION', ?, ?)");
                    foreach ($staffIds as $sid) {
                        $notifStmt->execute([(int)$sid, $notifMsg, $order_id]);
                    }
                }
            } catch (Throwable $e) {
                error_log("Failed to notify inventory staff: " . $e->getMessage());
            }
        }

        ordersRepoLogStatusChange($conn, $order_id, null, $order_status_value, $userId, 'Order created');
        $conn->commit();
        cacheInvalidateTable('orders');
        cacheInvalidateTable('delivery');

        if (function_exists('publishRealtimeEvent')) {
            publishRealtimeEvent([
                'event' => 'order.created',
                'data' => [
                    'order_id' => $order_id,
                    'customer_id' => $customer_id,
                    'status' => $order_status_value,
                    'message' => "New order #$order_id needs preparation."
                ]
            ]);
        }

        if (function_exists('logActivity')) {
            $customer_stmt = $conn->prepare("SELECT customer_name FROM customers WHERE Customer_ID = ?");
            $customer_stmt->execute([$customer_id]);
            $customer_name = (string)($customer_stmt->fetchColumn() ?: "ID: $customer_id");
            logActivity('ORDER', "Created new order #$order_id for customer: $customer_name", $order_id);
        }
        header("Location: ../pages/orders.php?success=Order created successfully&order_id=" . $order_id);
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function ordersHandleUpdateOrder(PDO $conn, int $userId): void
{
    $order_id = (int)($_POST['order_id'] ?? 0);
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $order_date = (string)($_POST['order_date'] ?? '');
    $delivery_date = !empty($_POST['delivery_date']) ? (string)$_POST['delivery_date'] : null;
    $delivery_address = trim((string)($_POST['delivery_address'] ?? ''));
    $discount_amount = 0.0;
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);

    $errors = [];
    if ($order_id <= 0) $errors[] = "Invalid order ID.";
    if ($customer_id <= 0) $errors[] = "Customer is required.";
    if (empty($order_date) || !ordersValidateDate($order_date)) $errors[] = "Invalid order date.";
    if (!empty($delivery_date) && !ordersValidateDate($delivery_date)) $errors[] = "Invalid delivery date.";
    if (!is_array($items) || count($items) === 0) $errors[] = "At least one item is required.";

    if (!empty($errors)) {
        header("Location: ../pages/orders.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }

    $status_col = 'order_status';
    $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if ($col_check && $col_check->rowCount() > 0) {
        $status_col = (string)$col_check->fetch(PDO::FETCH_ASSOC)['Field'];
    }

    $order_stmt = $conn->prepare("SELECT {$status_col} AS order_status FROM orders WHERE Order_ID = ?");
    $order_stmt->execute([$order_id]);
    $existing = $order_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        header("Location: ../pages/orders.php?error=Order not found");
        exit();
    }
    $normalized_status = ordersNormalizeStatus((string)$existing['order_status']);
    if ($normalized_status !== 'pending') {
        header("Location: ../pages/orders.php?error=" . urlencode("Only pending orders can be edited."));
        exit();
    }

    $delivery_row = $conn->prepare("SELECT delivery_status FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
    $delivery_row->execute([$order_id]);
    $delivery_status = strtolower((string)$delivery_row->fetchColumn());
    if (in_array($delivery_status, ['remitted', 'completed'], true)) {
        header("Location: ../pages/orders.php?error=" . urlencode("This order can no longer be edited because it is already remitted/completed."));
        exit();
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        if ($qty <= 0) {
            header("Location: ../pages/orders.php?error=" . urlencode("All item quantities must be greater than zero."));
            exit();
        }
        $subtotal += ($qty * $price);
    }
    $total_amount = max(0, $subtotal - $discount_amount);

    $conn->beginTransaction();
    try {
        $columns = ordersRepoGetColumns($conn, 'orders');
        $set_parts = ["Customer_ID = ?", "order_date = ?", "total_amount = ?"];
        $params = [$customer_id, $order_date, $total_amount];
        if (in_array('delivery_address', $columns, true)) { $set_parts[] = "delivery_address = ?"; $params[] = $delivery_address; }
        if (in_array('delivery_date', $columns, true)) { $set_parts[] = "delivery_date = ?"; $params[] = $delivery_date; }
        if (in_array('updated_at', $columns, true)) { $set_parts[] = "updated_at = NOW()"; }
        $params[] = $order_id;
        $conn->prepare("UPDATE orders SET " . implode(', ', $set_parts) . " WHERE Order_ID = ?")->execute($params);

        $conn->prepare("DELETE FROM order_details WHERE Order_ID = ?")->execute([$order_id]);
        $item_stmt = $conn->prepare("INSERT INTO order_details (Order_ID, Product_ID, ordered_qty, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($items as $item) {
            $item_stmt->execute([$order_id, (int)$item['product_id'], (float)$item['quantity'], (float)$item['unit_price']]);
        }

        // Re-snapshot customer and product data
        $snap_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $snap_set = [];
        $snap_params = [];
        if (in_array('customer_name_snapshot', $snap_cols, true)) {
            $cst = $conn->prepare("SELECT customer_name FROM customers WHERE Customer_ID = ?");
            $cst->execute([$customer_id]);
            $sn = $cst->fetchColumn();
            if ($sn) { $snap_set[] = "customer_name_snapshot = ?"; $snap_params[] = $sn; }
        }
        if (in_array('customer_phone_snapshot', $snap_cols, true)) {
            $cst = $conn->prepare("SELECT phone_number FROM customers WHERE Customer_ID = ?");
            $cst->execute([$customer_id]);
            $ph = $cst->fetchColumn();
            if ($ph) { $snap_set[] = "customer_phone_snapshot = ?"; $snap_params[] = $ph; }
        }
        if (in_array('customer_address_snapshot', $snap_cols, true)) {
            $cst = $conn->prepare("SELECT address FROM customers WHERE Customer_ID = ?");
            $cst->execute([$customer_id]);
            $ad = $cst->fetchColumn();
            if ($ad) { $snap_set[] = "customer_address_snapshot = ?"; $snap_params[] = $ad; }
        }
        if (!empty($snap_set)) {
            $snap_params[] = $order_id;
            $conn->prepare("UPDATE orders SET " . implode(', ', $snap_set) . " WHERE Order_ID = ?")->execute($snap_params);
        }

        // Re-snapshot product data
        $snap_od_cols = array_column($conn->query("SHOW COLUMNS FROM order_details")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $has_prod_snap = in_array('product_name_snapshot', $snap_od_cols, true);
        $has_unit_snap = in_array('unit_name_snapshot', $snap_od_cols, true);
        if ($has_prod_snap || $has_unit_snap) {
            $snap_prod = $conn->prepare("SELECT p.product_name, u.unit_name FROM products p LEFT JOIN units u ON p.unit_id = u.unit_id WHERE p.Product_ID = ?");
            $snap_od_upd = $conn->prepare("UPDATE order_details SET product_name_snapshot = ?, unit_name_snapshot = ? WHERE Order_detail_ID = ?");
            $det_stmt = $conn->prepare("SELECT Order_detail_ID, Product_ID FROM order_details WHERE Order_ID = ?");
            $det_stmt->execute([$order_id]);
            while ($det = $det_stmt->fetch(PDO::FETCH_ASSOC)) {
                $snap_prod->execute([(int)$det['Product_ID']]);
                $prod_data = $snap_prod->fetch(PDO::FETCH_ASSOC);
                if ($prod_data) {
                    $snap_od_upd->execute([
                        $has_prod_snap ? $prod_data['product_name'] : null,
                        $has_unit_snap ? ($prod_data['unit_name'] ?? '') : null,
                        (int)$det['Order_detail_ID']
                    ]);
                }
            }
        }

        if (ordersRepoTableExists($conn, 'delivery')) {
            $delivery_cols = ordersRepoGetColumns($conn, 'delivery');
            $delivery_set = [];
            $delivery_params = [];
            if (in_array('delivery_address', $delivery_cols, true)) { $delivery_set[] = "delivery_address = ?"; $delivery_params[] = $delivery_address; }
            if (in_array('schedule_date', $delivery_cols, true)) { $delivery_set[] = "schedule_date = ?"; $delivery_params[] = $delivery_date; }
            if (!empty($delivery_set)) {
                $latest_delivery = ordersGetLatestDeliveryRecord($conn, $order_id);
                if ($latest_delivery && !ordersDeliveryHistoryLocked((string)($latest_delivery['delivery_status'] ?? ''))) {
                    $delivery_set[] = "updated_at = NOW()";
                    $delivery_params[] = (int)$latest_delivery['Delivery_ID'];
                    $conn->prepare("UPDATE delivery SET " . implode(', ', $delivery_set) . " WHERE Delivery_ID = ?")->execute($delivery_params);
                }
            }
        }

        ordersRepoLogStatusChange($conn, $order_id, (string)$existing['order_status'], (string)$existing['order_status'], $userId, 'Order details updated');
        $conn->commit();
        cacheInvalidateTable('orders');
        header("Location: ../pages/orders.php?success=Order updated successfully");
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function ordersHandleReorderOrder(PDO $conn, int $userId): void
{
    $source_order_id = (int)($_POST['order_id'] ?? 0);
    if ($source_order_id <= 0) {
        header("Location: ../pages/orders.php?error=Invalid source order ID");
        exit();
    }

    $source_columns = ordersRepoGetColumns($conn, 'orders');
    $source_select = ['Customer_ID', 'order_date'];
    if (in_array('delivery_date', $source_columns, true)) {
        $source_select[] = 'delivery_date';
    } else {
        $source_select[] = 'NULL AS delivery_date';
    }
    if (in_array('delivery_address', $source_columns, true)) {
        $source_select[] = 'delivery_address';
    } else {
        $source_select[] = "'' AS delivery_address";
    }
    $source_stmt = $conn->prepare("SELECT " . implode(', ', $source_select) . " FROM orders WHERE Order_ID = ?");
    $source_stmt->execute([$source_order_id]);
    $source = $source_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        header("Location: ../pages/orders.php?error=Source order not found");
        exit();
    }

    $items_stmt = $conn->prepare("SELECT Product_ID, ordered_qty, unit_price FROM order_details WHERE Order_ID = ?");
    $items_stmt->execute([$source_order_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        header("Location: ../pages/orders.php?error=Source order has no items");
        exit();
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += ((float)$item['ordered_qty'] * (float)$item['unit_price']);
    }
    $discount = 0.0;
    $total_amount = max(0, $subtotal - $discount);

    $conn->beginTransaction();
    try {
        $columns = ordersRepoGetColumns($conn, 'orders');
        $insert_fields = ['Customer_ID', 'order_date', 'order_status', 'total_amount', 'remarks'];
        $insert_values = ['?', '?', '?', '?', '?'];
        $params = [(int)$source['Customer_ID'], date('Y-m-d'), 'pending', $total_amount, 'Reorder from #' . $source_order_id];
        if (in_array('delivery_date', $columns, true)) { $insert_fields[] = 'delivery_date'; $insert_values[] = '?'; $params[] = $source['delivery_date'] ?: null; }
        if (in_array('delivery_address', $columns, true)) { $insert_fields[] = 'delivery_address'; $insert_values[] = '?'; $params[] = (string)($source['delivery_address'] ?? ''); }
        if (in_array('created_by', $columns, true)) { $insert_fields[] = 'created_by'; $insert_values[] = '?'; $params[] = $userId; }
        if (in_array('created_at', $columns, true)) { $insert_fields[] = 'created_at'; $insert_values[] = 'NOW()'; }

        $stmt = $conn->prepare("INSERT INTO orders (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")");
        $stmt->execute($params);
        $new_order_id = (int)$conn->lastInsertId();

        $item_stmt = $conn->prepare("INSERT INTO order_details (Order_ID, Product_ID, ordered_qty, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($items as $item) {
            $item_stmt->execute([$new_order_id, (int)$item['Product_ID'], (float)$item['ordered_qty'], (float)$item['unit_price']]);
        }

        // Snapshot customer and product data for reorder
        $snap_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $snap_set = [];
        $snap_params = [];
        $cid = (int)$source['Customer_ID'];
        if (in_array('customer_name_snapshot', $snap_cols, true)) {
            $cst = $conn->prepare("SELECT customer_name FROM customers WHERE Customer_ID = ?");
            $cst->execute([$cid]);
            $sn = $cst->fetchColumn();
            if ($sn) { $snap_set[] = "customer_name_snapshot = ?"; $snap_params[] = $sn; }
        }
        if (in_array('customer_phone_snapshot', $snap_cols, true)) {
            $cst = $conn->prepare("SELECT phone_number FROM customers WHERE Customer_ID = ?");
            $cst->execute([$cid]);
            $ph = $cst->fetchColumn();
            if ($ph) { $snap_set[] = "customer_phone_snapshot = ?"; $snap_params[] = $ph; }
        }
        if (in_array('customer_address_snapshot', $snap_cols, true)) {
            $cst = $conn->prepare("SELECT address FROM customers WHERE Customer_ID = ?");
            $cst->execute([$cid]);
            $ad = $cst->fetchColumn();
            if ($ad) { $snap_set[] = "customer_address_snapshot = ?"; $snap_params[] = $ad; }
        }
        if (!empty($snap_set)) {
            $snap_params[] = $new_order_id;
            $conn->prepare("UPDATE orders SET " . implode(', ', $snap_set) . " WHERE Order_ID = ?")->execute($snap_params);
        }

        $snap_od_cols = array_column($conn->query("SHOW COLUMNS FROM order_details")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $has_prod_snap = in_array('product_name_snapshot', $snap_od_cols, true);
        $has_unit_snap = in_array('unit_name_snapshot', $snap_od_cols, true);
        if ($has_prod_snap || $has_unit_snap) {
            $snap_prod = $conn->prepare("SELECT p.product_name, u.unit_name FROM products p LEFT JOIN units u ON p.unit_id = u.unit_id WHERE p.Product_ID = ?");
            $snap_od_upd = $conn->prepare("UPDATE order_details SET product_name_snapshot = ?, unit_name_snapshot = ? WHERE Order_detail_ID = ?");
            $det_stmt = $conn->prepare("SELECT Order_detail_ID, Product_ID FROM order_details WHERE Order_ID = ?");
            $det_stmt->execute([$new_order_id]);
            while ($det = $det_stmt->fetch(PDO::FETCH_ASSOC)) {
                $snap_prod->execute([(int)$det['Product_ID']]);
                $prod_data = $snap_prod->fetch(PDO::FETCH_ASSOC);
                if ($prod_data) {
                    $snap_od_upd->execute([
                        $has_prod_snap ? $prod_data['product_name'] : null,
                        $has_unit_snap ? ($prod_data['unit_name'] ?? '') : null,
                        (int)$det['Order_detail_ID']
                    ]);
                }
            }
        }

        ordersRepoLogStatusChange($conn, $new_order_id, null, 'pending', $userId, 'Reordered from #' . $source_order_id);
        $conn->commit();
        cacheInvalidateTable('orders');
        header("Location: ../pages/orders.php?success=Reorder created successfully");
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function ordersHandleUpdateStatus(PDO $conn, int $userId): void
{
    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = trim((string)($_POST['new_status'] ?? ''));
    $delivery_date = $_POST['delivery_date'] ?? null;
    $delivery_person_meta = ordersRepoResolveDeliveryPerson($conn, (string)($_POST['delivery_person'] ?? ''));
    $delivery_person_id = $delivery_person_meta['id'];
    $delivery_person = $delivery_person_meta['name'];
    $notes = trim((string)($_POST['notes'] ?? ''));

    $errors = [];
    if ($order_id <= 0) $errors[] = "Invalid order ID.";
    if ($new_status === '') $errors[] = "New status is required.";
    if (!empty($delivery_date) && !ordersValidateDate((string)$delivery_date)) $errors[] = "Invalid delivery date format.";
    if (!empty($notes) && strlen($notes) > 500) $errors[] = "Notes must not exceed 500 characters.";
    if (!empty($delivery_person) && strlen($delivery_person) > 100) $errors[] = "Delivery person name must not exceed 100 characters.";

    if (!empty($errors)) {
        header("Location: ../pages/orders.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }

    $check_stmt = $conn->prepare("SELECT order_status FROM orders WHERE Order_ID = ?");
    $check_stmt->execute([$order_id]);
    $order = $check_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        header("Location: ../pages/orders.php?error=Order not found");
        exit();
    }
    $old_status = (string)$order['order_status'];
    $normalized_old_status = ordersNormalizeStatus($old_status);
    if ($delivery_person_id !== null && !riderCanBeAssignedToTarget($conn, $delivery_person_id, null, $order_id)) {
        $errors[] = "Selected rider is not available for assignment.";
    }

    if (!empty($errors)) {
        header("Location: ../pages/orders.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }

    $conn->beginTransaction();
    try {
        $previous_assigned_rider_id = ordersGetLatestAssignedRiderId($conn, $order_id);
        $existing_columns = ordersRepoGetColumns($conn, 'orders');
        $columns_meta = $conn->query("SHOW COLUMNS FROM orders")->fetchAll();
        $order_status_type = null;
        foreach ($columns_meta as $col) {
            if ($col['Field'] === 'order_status' || $col['Field'] === 'status') {
                $order_status_type = $col['Type'];
                break;
            }
        }
        if ($order_status_type && strpos(strtolower((string)$order_status_type), 'enum') !== false) {
            preg_match("/enum\s*\((.+)\)/i", (string)$order_status_type, $matches);
            if (!empty($matches[1])) {
                $enum_values = array_map(static function ($v) { return trim($v, " '\""); }, explode(',', $matches[1]));
                $enum_map = [];
                foreach ($enum_values as $val) $enum_map[strtolower($val)] = $val;
                if (isset($enum_map[strtolower($new_status)])) {
                    $new_status = $enum_map[strtolower($new_status)];
                } else {
                    throw new Exception("Status '$new_status' is not valid.");
                }
            }
        }

        if (!ordersCanManagerTransition($old_status, $new_status)) {
            throw new Exception("Invalid status transition. Manager can only move Pending → Scheduled for Delivery.");
        }
        $update_fields = "order_status = ?";
        $update_params = [$new_status];
        if (!empty($delivery_date) && in_array('delivery_date', $existing_columns, true)) { $update_fields .= ", delivery_date = ?"; $update_params[] = $delivery_date; }
        if ($new_status === 'Completed' && in_array('completed_at', $existing_columns, true)) { $update_fields .= ", completed_at = NOW()"; }
        $update_params[] = $order_id;
        $conn->prepare("UPDATE orders SET $update_fields, updated_at = NOW() WHERE Order_ID = ?")->execute($update_params);

        if (!empty($delivery_person) && ordersRepoTableExists($conn, 'delivery')) {
            $select_fields = ['COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name'];
            if (in_array('delivery_address', $existing_columns, true)) $select_fields[] = 'o.delivery_address';
            if (in_array('delivery_date', $existing_columns, true)) $select_fields[] = 'o.delivery_date';
            $order_info_stmt = $conn->prepare("SELECT " . implode(', ', $select_fields) . " FROM orders o INNER JOIN customers c ON o.Customer_ID = c.Customer_ID WHERE o.Order_ID = ?");
            $order_info_stmt->execute([$order_id]);
            $order_info = $order_info_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $delivery_address = $order_info['delivery_address'] ?? '';
            $delivered_to = $order_info['customer_name'] ?? '';
            $schedule_date = (!empty($delivery_date) ? $delivery_date : ($order_info['delivery_date'] ?? null));

            $delivery_row = ordersGetLatestDeliveryRecord($conn, $order_id);
            $can_update_existing_delivery = $delivery_row && !ordersDeliveryHistoryLocked((string)($delivery_row['delivery_status'] ?? ''));
            if ($can_update_existing_delivery) {
                $delivery_update_fields = ["delivered_by = ?", "delivery_address = ?", "delivered_to = ?", "schedule_date = ?", "updated_at = NOW()"];
                $delivery_update_params = [$delivery_person, $delivery_address, $delivered_to, $schedule_date];
                if (strtolower($new_status) === 'delivered' || strtolower($new_status) === 'delivered (pending cash turnover)') {
                    $delivery_update_fields[] = "delivery_status = 'Delivered'";
                    $delivery_update_fields[] = "actual_date_arrived = CURDATE()";
                } elseif (strtolower($new_status) === 'out for delivery') {
                    $delivery_update_fields[] = "delivery_status = 'In Transit'";
                } elseif (ordersNormalizeStatus($new_status) === 'scheduled') {
                    $delivery_update_fields[] = "delivery_status = 'Scheduled'";
                }
                if ($delivery_person_id !== null) {
                    $delivery_cols = ordersRepoGetColumns($conn, 'delivery');
                    if (in_array('assigned_rider_id', $delivery_cols, true)) {
                        $delivery_update_fields[] = "assigned_rider_id = ?";
                        $delivery_update_params[] = $delivery_person_id;
                    }
                    if (in_array('delivered_by_user_id', $delivery_cols, true)) {
                        $delivery_update_fields[] = "delivered_by_user_id = ?";
                        $delivery_update_params[] = $delivery_person_id;
                    }
                }
                $delivery_update_params[] = (int)$delivery_row['Delivery_ID'];
                $conn->prepare("UPDATE delivery SET " . implode(', ', $delivery_update_fields) . " WHERE Delivery_ID = ?")->execute($delivery_update_params);
                ordersRepoEnsureDeliveryDetails($conn, (int)$delivery_row['Delivery_ID'], $order_id);
                if ($delivery_person_id !== null) {
                    syncRiderAvailabilityForUser($conn, $delivery_person_id);
                }
            } else {
                $delivery_columns = ordersRepoGetColumns($conn, 'delivery');
                $delivery_fields = ['Order_ID'];
                $delivery_values = ['?'];
                $delivery_params = [$order_id];
                if (in_array('delivery_address', $delivery_columns, true)) { $delivery_fields[] = 'delivery_address'; $delivery_values[] = '?'; $delivery_params[] = $delivery_address; }
                if (in_array('schedule_date', $delivery_columns, true)) { $delivery_fields[] = 'schedule_date'; $delivery_values[] = '?'; $delivery_params[] = $schedule_date; }
                if (in_array('delivery_status', $delivery_columns, true)) { $delivery_fields[] = 'delivery_status'; $delivery_values[] = "'Scheduled'"; }
                if (in_array('delivered_by', $delivery_columns, true)) { $delivery_fields[] = 'delivered_by'; $delivery_values[] = '?'; $delivery_params[] = $delivery_person; }
                if (in_array('delivered_to', $delivery_columns, true)) { $delivery_fields[] = 'delivered_to'; $delivery_values[] = '?'; $delivery_params[] = $delivered_to; }
                if ($delivery_person_id !== null) {
                    if (in_array('assigned_rider_id', $delivery_columns, true)) {
                        $delivery_fields[] = 'assigned_rider_id';
                        $delivery_values[] = '?';
                        $delivery_params[] = $delivery_person_id;
                    }
                    if (in_array('delivered_by_user_id', $delivery_columns, true)) {
                        $delivery_fields[] = 'delivered_by_user_id';
                        $delivery_values[] = '?';
                        $delivery_params[] = $delivery_person_id;
                    }
                }
                $delivery_sql = "INSERT INTO delivery (" . implode(', ', $delivery_fields) . ") VALUES (" . implode(', ', $delivery_values) . ")";
                $conn->prepare($delivery_sql)->execute($delivery_params);
                ordersRepoEnsureDeliveryDetails($conn, (int)$conn->lastInsertId(), $order_id);
                if ($delivery_person_id !== null) {
                    syncRiderAvailabilityForUser($conn, $delivery_person_id);
                }
            }
        }

        if (ordersRepoTableExists($conn, 'delivery')) {
            $latest_delivery = ordersGetLatestDeliveryRecord($conn, $order_id);
            $latest_delivery_id = (int)($latest_delivery['Delivery_ID'] ?? 0);
            if ($new_status === 'out for delivery' || $new_status === 'Out for Delivery') {
                if ($latest_delivery_id > 0 && !ordersDeliveryHistoryLocked((string)($latest_delivery['delivery_status'] ?? ''))) {
                    $conn->prepare("UPDATE delivery SET delivery_status = 'In Transit', updated_at = NOW() WHERE Delivery_ID = ?")->execute([$latest_delivery_id]);
                }
            } elseif ($new_status === 'delivered' || $new_status === 'Delivered (Pending Cash Turnover)') {
                if ($latest_delivery_id > 0 && !ordersDeliveryHistoryLocked((string)($latest_delivery['delivery_status'] ?? ''))) {
                    $conn->prepare("UPDATE delivery SET delivery_status = 'Delivered', actual_date_arrived = CURDATE(), updated_at = NOW() WHERE Delivery_ID = ?")->execute([$latest_delivery_id]);
                }
            }
        }
        if ($previous_assigned_rider_id > 0 && $previous_assigned_rider_id !== (int)$delivery_person_id) {
            syncRiderAvailabilityForUser($conn, $previous_assigned_rider_id);
        }

        // Auto-create prep task when order is scheduled
        if (ordersNormalizeStatus($new_status) === 'scheduled') {
            if (function_exists('prepTasksUpdateStatus')) {
                prepTasksUpdateStatus($conn, $order_id, 'not_started', $userId);
            }

            // Log notification in activity_logs for inventory staff
            try {
                $invStaffRoleIds = function_exists('getInventoryStaffRoleIds') ? getInventoryStaffRoleIds($conn) : [];
                if (!empty($invStaffRoleIds)) {
                    $placeholders = implode(',', array_fill(0, count($invStaffRoleIds), '?'));
                    $staffStmt = $conn->prepare("SELECT User_ID FROM user WHERE Role_ID IN ($placeholders) AND is_active = 1");
                    $staffStmt->execute(array_values($invStaffRoleIds));
                    $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                    $notifMsg = "Order #{$order_id} has been scheduled for delivery and needs preparation.";
                    $notifStmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID) VALUES (?, 'NOTIFICATION', ?, ?)");
                    foreach ($staffIds as $sid) {
                        $notifStmt->execute([(int)$sid, $notifMsg, $order_id]);
                    }
                }
            } catch (Throwable $e) {
                error_log("Failed to notify inventory staff: " . $e->getMessage());
            }
        }

        ordersRepoLogStatusChange($conn, $order_id, $old_status, $new_status, $userId, $notes);
        $conn->commit();
        cacheInvalidateTable('orders');
        cacheInvalidateTable('delivery');

        if (ordersNormalizeStatus($new_status) === 'scheduled' && function_exists('publishRealtimeEvent')) {
            publishRealtimeEvent([
                'event' => 'order.scheduled',
                'data' => [
                    'order_id' => $order_id,
                    'status' => $new_status,
                    'message' => "Order #$order_id has been scheduled and needs preparation."
                ]
            ]);
        }

        if (function_exists('logActivity')) {
            logActivity('ORDER', "Updated order #$order_id status from '$old_status' to '$new_status'", $order_id);
        }
        header("Location: ../pages/orders.php?success=" . urlencode("Status updated successfully"));
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

function ordersHandleAssignDelivery(PDO $conn): void
{
    $order_id = (int)($_POST['order_id'] ?? 0);
    $delivery_person_meta = ordersRepoResolveDeliveryPerson($conn, (string)($_POST['delivery_person'] ?? ''));
    $delivery_person_id = $delivery_person_meta['id'];
    $delivery_person = $delivery_person_meta['name'];

    if ($order_id <= 0 || $delivery_person === '') {
        header("Location: ../pages/orders.php?error=Delivery person is required");
        exit();
    }
    if ($delivery_person_id === null || !riderCanBeAssignedToTarget($conn, $delivery_person_id, null, $order_id)) {
        header("Location: ../pages/orders.php?error=" . urlencode("Selected rider is not available for assignment."));
        exit();
    }

    $order_columns = ordersRepoGetColumns($conn, 'orders');
    $select_fields = ['COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name'];
    if (in_array('delivery_address', $order_columns, true)) $select_fields[] = 'o.delivery_address';
    $order_stmt = $conn->prepare("SELECT " . implode(', ', $select_fields) . " FROM orders o INNER JOIN customers c ON o.Customer_ID = c.Customer_ID WHERE o.Order_ID = ?");
    $order_stmt->execute([$order_id]);
    $order_data = $order_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order_data) {
        header("Location: ../pages/orders.php?error=Order not found");
        exit();
    }

    $existing_delivery = ordersGetLatestDeliveryRecord($conn, $order_id);
    $delivery_address = $order_data['delivery_address'] ?? '';
    $delivered_to = $order_data['customer_name'] ?? '';
    $delivery_status = 'Scheduled';
    $can_update_existing_delivery = $existing_delivery && !ordersDeliveryHistoryLocked((string)($existing_delivery['delivery_status'] ?? ''));
    $previous_assigned_rider_id = ordersGetLatestAssignedRiderId($conn, $order_id);

    if ($can_update_existing_delivery) {
        $delivery_id = (int)$existing_delivery['Delivery_ID'];
        $set_assigned = ($delivery_person_id !== null && in_array('assigned_rider_id', ordersRepoGetColumns($conn, 'delivery'), true)) ? ', assigned_rider_id = ?' : '';
        $params = [$delivery_person, $delivery_address, $delivered_to, $delivery_status];
        if ($set_assigned !== '') $params[] = $delivery_person_id;
        $params[] = $delivery_id;
        $stmt = $conn->prepare("UPDATE delivery SET delivered_by = ?, delivery_address = ?, delivered_to = ?, delivery_status = ?{$set_assigned}, updated_at = NOW() WHERE Delivery_ID = ?");
        $success = $stmt->execute($params);
    } else {
        $schedule_stmt = $conn->prepare("SELECT delivery_date FROM orders WHERE Order_ID = ?");
        $schedule_stmt->execute([$order_id]);
        $schedule_date = $schedule_stmt->fetchColumn() ?: null;
        $has_assigned = ($delivery_person_id !== null && in_array('assigned_rider_id', ordersRepoGetColumns($conn, 'delivery'), true));
        $fields = ['Order_ID', 'delivery_address', 'schedule_date', 'delivery_status', 'delivered_by', 'delivered_to'];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        $values = [$order_id, $delivery_address, $schedule_date, $delivery_status, $delivery_person, $delivered_to];
        if ($has_assigned) { $fields[] = 'assigned_rider_id'; $placeholders[] = '?'; $values[] = $delivery_person_id; }
        $stmt = $conn->prepare("INSERT INTO delivery (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
        $success = $stmt->execute($values);
        $delivery_id = (int)$conn->lastInsertId();
    }

    if ($success) {
        if ($previous_assigned_rider_id > 0 && $previous_assigned_rider_id !== (int)$delivery_person_id) {
            syncRiderAvailabilityForUser($conn, $previous_assigned_rider_id);
        }
        if ($delivery_person_id !== null) {
            syncRiderAvailabilityForUser($conn, $delivery_person_id);
        }
        ordersRepoEnsureDeliveryDetails($conn, $delivery_id, $order_id);

        if (function_exists('publishRealtimeEvent')) {
            publishRealtimeEvent([
                'event' => 'order.scheduled',
                'data' => [
                    'order_id' => $order_id,
                    'message' => "Order #$order_id has been assigned a delivery and needs preparation."
                ]
            ]);
        }

        if (function_exists('logActivity')) {
            logActivity('ORDER', "Scheduled delivery for order #$order_id with rider $delivery_person", $order_id);
        }

        cacheInvalidateTable('delivery');
        header("Location: ../pages/orders.php?success=Delivery assigned successfully");
    } else {
        header("Location: ../pages/orders.php?error=Failed to assign delivery");
    }
    exit();
}

function ordersHandleCancelOrder(PDO $conn, int $userId): void
{
    $manager_role_ids = function_exists('getManagerRoleIds') ? getManagerRoleIds($conn) : [];
    if (!in_array((int)($_SESSION['user_role'] ?? 0), $manager_role_ids, true)) {
        header("Location: ../pages/orders.php?error=" . urlencode("Only managers can cancel orders."));
        exit();
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $reason = normalizeOrderCancellationReasonValue($conn, trim((string)($_POST['cancellation_reason'] ?? '')));
    $remarks = trim((string)($_POST['cancellation_remarks'] ?? ''));
    $errors = [];
    if ($order_id <= 0) $errors[] = "Invalid order ID.";
    if ($reason === '') $errors[] = "Cancellation reason is required.";
    else {
        $validReasons = getOrderCancellationReasonOptions($conn);
        if (!in_array($reason, $validReasons, true)) {
            $errors[] = "Invalid cancellation reason selected.";
        }
    }
    if ($remarks !== '' && strlen($remarks) > 255) $errors[] = "Cancellation remarks must not exceed 255 characters.";

    if (!empty($errors)) {
        header("Location: ../pages/orders.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("SELECT order_status FROM orders WHERE Order_ID = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new Exception("Order not found");
        $old_status = (string)$order['order_status'];

        $status_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field = 'order_status'")->fetch(PDO::FETCH_ASSOC);
        $use_cancelled = 'Cancelled';
        if ($status_check && strpos(strtolower((string)$status_check['Type']), 'enum') !== false) {
            preg_match("/enum\s*\((.+)\)/i", (string)$status_check['Type'], $matches);
            if (!empty($matches[1])) {
                $enum_values = array_map(static function ($v) { return trim($v, " '\""); }, explode(',', $matches[1]));
                foreach ($enum_values as $val) {
                    if (strtolower($val) === 'cancelled') { $use_cancelled = $val; break; }
                }
            }
        }

        $existing_columns = ordersRepoGetColumns($conn, 'orders');
        $update_fields = ["order_status = ?"];
        $update_params = [$use_cancelled];
        if (in_array('cancelled_at', $existing_columns, true)) $update_fields[] = "cancelled_at = NOW()";
        if (in_array('cancellation_reason', $existing_columns, true)) { $update_fields[] = "cancellation_reason = ?"; $update_params[] = $reason; }
        if (in_array('cancellation_remarks', $existing_columns, true)) { $update_fields[] = "cancellation_remarks = ?"; $update_params[] = ($remarks !== '' ? $remarks : null); }
        if (in_array('updated_at', $existing_columns, true)) { $update_fields[] = "updated_at = NOW()"; }
        $update_params[] = $order_id;
        $conn->prepare("UPDATE orders SET " . implode(', ', $update_fields) . " WHERE Order_ID = ?")->execute($update_params);

        if (ordersRepoTableExists($conn, 'delivery')) {
            $delivery_cols = ordersRepoGetColumns($conn, 'delivery');
            $delivery_set = ["delivery_status = 'Cancelled'"];
            $delivery_params = [];
            $deliveryRemarksValue = ($remarks !== '' ? $remarks : null);
            if (in_array('cancellation_reason', $delivery_cols, true)) {
                $deliveryReasonValue = $reason;
                $deliveryReasonOptions = function_exists('getDeliveryCancellationReasonOptions')
                    ? getDeliveryCancellationReasonOptions($conn)
                    : [];
                if (!empty($deliveryReasonOptions)) {
                    $reasonMap = [
                        'Customer Change Mind' => 'Customer requested cancellation',
                        'No Longer Needed' => 'Customer requested cancellation',
                        'Others' => 'Other',
                    ];
                    $mappedReason = $reasonMap[$reason] ?? 'Other';
                    if (!in_array($mappedReason, $deliveryReasonOptions, true)) {
                        $mappedReason = in_array('Other', $deliveryReasonOptions, true)
                            ? 'Other'
                            : ((string)($deliveryReasonOptions[0] ?? ''));
                    }
                    if ($mappedReason !== '') {
                        $deliveryReasonValue = $mappedReason;
                        if ($mappedReason !== $reason) {
                            $extra = "Order cancellation reason: {$reason}";
                            $deliveryRemarksValue = $deliveryRemarksValue
                                ? ($deliveryRemarksValue . ' | ' . $extra)
                                : $extra;
                        }
                    }
                }
                $delivery_set[] = "cancellation_reason = ?";
                $delivery_params[] = $deliveryReasonValue;
            }
            if (in_array('cancellation_remarks', $delivery_cols, true)) {
                $delivery_set[] = "cancellation_remarks = ?";
                $delivery_params[] = $deliveryRemarksValue;
            }
            if (in_array('updated_at', $delivery_cols, true)) {
                $delivery_set[] = "updated_at = NOW()";
            }
            $delivery_params[] = $order_id;
            $conn->prepare(
                "UPDATE delivery SET " . implode(', ', $delivery_set) . " WHERE Order_ID = ? AND delivery_status IN ('Scheduled', 'In Transit', 'Returning', 'Cancelled')"
            )->execute($delivery_params);

            if (ordersRepoTableExists($conn, 'delivery_detail')) {
                $detailNote = $reason . ($remarks !== '' ? ' - ' . $remarks : '');
                $stmt = $conn->prepare(
                    "UPDATE delivery_detail dd
                     INNER JOIN delivery d ON d.Delivery_ID = dd.Delivery_ID
                     SET dd.status = 'cancelled',
                         dd.remarks = CONCAT(COALESCE(dd.remarks, ''), CASE WHEN COALESCE(dd.remarks, '') = '' THEN '' ELSE ' ' END, '[Cancelled: ', ?, ']'),
                         dd.updated_at = NOW()
                     WHERE d.Order_ID = ? AND d.delivery_status = 'Cancelled'"
                );
                $stmt->execute([$detailNote, $order_id]);
            }
        }

        $statusNote = "Cancelled: " . $reason . ($remarks !== '' ? " - " . $remarks : '');
        ordersRepoLogStatusChange($conn, $order_id, $old_status, $use_cancelled, $userId, $statusNote);
        $conn->commit();
        cacheInvalidateTable('orders');
        cacheInvalidateTable('delivery');
        header("Location: ../pages/orders.php?success=Order cancelled successfully");
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: ../pages/orders.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}
