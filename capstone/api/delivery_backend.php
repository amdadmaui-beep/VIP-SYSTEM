<?php
/**
 * Delivery Backend API
 * Handles delivery creation, updates, and management
 * 
 * SECURITY UPDATE: Added CSRF protection for state-changing operations
 * Location: capstone/api/delivery_backend.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/order_status_helper.php';
require_once __DIR__ . '/../includes/delivery_cancellation_helper.php';
require_once __DIR__ . '/../includes/rider_availability_helper.php';
require_once __DIR__ . '/../includes/delivery_transfer_helper.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/csrf.php'; // CSRF Protection - Security Fix
require_once __DIR__ . '/../includes/preparation_tasks_helper.php';
require_once __DIR__ . '/../realtime/publish_event.php';

$rider_ids = getRiderRoleIds($conn);
$dashboard_ids = getDashboardRoleIds($conn);
$allowed = array_unique(array_merge($rider_ids, $dashboard_ids));
requireRole(empty($allowed) ? [1] : $allowed);
prepTasksEnsureSchema($conn);
ensureDeliveryTransfersTable($conn);

function deliveryHasColumn($conn, $column) {
    static $cache = [];
    if (array_key_exists($column, $cache)) return $cache[$column];
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) {
        $cache[$column] = false;
        return false;
    }
    $escapedColumn = str_replace("'", "''", (string)$column);
    $stmt = $conn->query("SHOW COLUMNS FROM delivery LIKE '{$escapedColumn}'");
    $cache[$column] = $stmt && $stmt->rowCount() > 0;
    return $cache[$column];
}

function deliveryStatusLocksAssignment(string $status): bool {
    return in_array($status, ['In Transit', 'Delivered', 'Returning', 'Completed', 'Cancelled', 'Remitted'], true);
}

function getDeliveryAssignedRiderId(PDO $conn, int $deliveryId): int
{
    return riderGetUserIdByDeliveryId($conn, $deliveryId);
}

function handleBulkTransfer(PDO $conn, int $user_id): void
{
    global $dashboard_ids, $rider_ids;

    $user_role = (int)($_SESSION['user_role'] ?? 0);
    if (!in_array($user_role, $dashboard_ids, true) || in_array($user_role, $rider_ids, true)) {
        header('Location: ../pages/delivery.php?error=' . urlencode('Only management users can transfer deliveries.'));
        exit;
    }

    $source_rider_id = (int)($_POST['source_rider_id'] ?? 0);
    $new_rider_id = (int)($_POST['new_rider_id'] ?? 0);
    $redirect_to = $_POST['redirect_to'] ?? '../pages/delivery.php';

    if ($source_rider_id <= 0 || $new_rider_id <= 0) {
        header("Location: {$redirect_to}?error=" . urlencode('Invalid rider selection.'));
        exit;
    }

    if ($source_rider_id === $new_rider_id) {
        header("Location: {$redirect_to}?error=" . urlencode('Cannot transfer to the same rider.'));
        exit;
    }

    if (!deliveryRiderHasVehicleIssueDeliveries($conn, $source_rider_id)) {
        header("Location: {$redirect_to}?error=" . urlencode('The selected rider does not have any Vehicle issue deliveries to transfer.'));
        exit;
    }

    if (!riderCanBeAssignedToTarget($conn, $new_rider_id)) {
        header("Location: {$redirect_to}?error=" . urlencode('Selected rider is not available for assignment.'));
        exit;
    }

    $rider_stmt = $conn->prepare("SELECT COALESCE(full_name, user_name) FROM user WHERE User_ID = ?");
    $rider_stmt->execute([$new_rider_id]);
    $new_rider_name = (string)($rider_stmt->fetchColumn() ?: '');
    if ($new_rider_name === '') {
        header("Location: {$redirect_to}?error=" . urlencode('New rider not found.'));
        exit;
    }

    // Find deliveries to transfer (selected IDs or all eligible for source rider)
    $requested_ids = $_POST['delivery_ids'] ?? [];
    if (!is_array($requested_ids)) {
        $requested_ids = [$requested_ids];
    }
    $requested_ids = array_values(array_unique(array_filter(array_map('intval', $requested_ids), static fn($id) => $id > 0)));

    $ownershipParams = [];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $source_rider_id, $ownershipParams);
    if ($ownership === '0 = 1') {
        header("Location: {$redirect_to}?error=" . urlencode('Cannot resolve deliveries for this rider.'));
        exit;
    }

    $excluded_ph = deliveryTransferExcludedSqlPlaceholders();
    $excluded_params = deliveryTransferExcludedSqlParams();
    $sql = "SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, d.cancellation_reason
            FROM delivery d
            WHERE {$ownership}
              AND d.delivery_status NOT IN ({$excluded_ph})";
    $params = array_merge($ownershipParams, $excluded_params);

    if (!empty($requested_ids)) {
        $id_ph = implode(',', array_fill(0, count($requested_ids), '?'));
        $sql .= " AND Delivery_ID IN ({$id_ph})";
        $params = array_merge($params, $requested_ids);
    }

    $sql .= ' ORDER BY Delivery_ID';
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $deliveries = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row) => deliveryIsTransferEligible((string)($row['delivery_status'] ?? ''))
    ));

    if (empty($deliveries)) {
        $err = !empty($requested_ids)
            ? 'No valid deliveries selected for transfer.'
            : 'No active deliveries found for this rider.';
        header("Location: {$redirect_to}?error=" . urlencode($err));
        exit;
    }

    $conn->beginTransaction();
    try {
        $order_status_col = 'order_status';
        $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
        if ($col_check && $col_check->rowCount() > 0) {
            $order_status_col = $col_check->fetch(PDO::FETCH_ASSOC)['Field'];
        }
        $scheduled_status = getValidOrderStatus($conn, 'Scheduled for Delivery', ['Scheduled for Delivery', 'Requested', 'pending']);

        $transferred_count = 0;
        foreach ($deliveries as $delivery) {
            $delivery_id = (int)$delivery['Delivery_ID'];
            $order_id = (int)$delivery['Order_ID'];

            deliveryApplyRiderTransfer($conn, $delivery_id, $new_rider_id, $new_rider_name);

            if ($order_id > 0) {
                $conn->prepare("UPDATE orders SET {$order_status_col} = ? WHERE Order_ID = ?")->execute([$scheduled_status, $order_id]);
            }

            logActivity('DELIVERY', "Bulk transferred Delivery #{$delivery_id} (Order #{$order_id}) from Rider #{$source_rider_id} to {$new_rider_name} due to Vehicle issue.", $delivery_id);

            $notifMsg = "New delivery assigned: Delivery #{$delivery_id} for Order #{$order_id}. Previously flagged as Vehicle issue — please coordinate pickup with the original rider.";
            $notifStmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID, Log_Time) VALUES (?, 'NOTIFICATION', ?, ?, CURRENT_TIMESTAMP)");
            $notifStmt->execute([$new_rider_id, $notifMsg, $delivery_id]);

            $transferStmt = $conn->prepare("INSERT INTO delivery_transfers (Delivery_ID, from_rider_id, to_rider_id, transferred_by_user_id, reason) VALUES (?, ?, ?, ?, 'Vehicle issue')");
            $transferStmt->execute([$delivery_id, $source_rider_id, $new_rider_id, $user_id]);

            $transferred_count++;
        }

        // Set old rider to Off Duty
        ensureRiderWorkflowSchema($conn);
        $conn->prepare("INSERT INTO rider_settings (User_ID, availability_status, last_set_at)
                        VALUES (?, 'Off Duty', NOW())
                        ON DUPLICATE KEY UPDATE availability_status = 'Off Duty', last_set_at = NOW()")->execute([$source_rider_id]);

        // Sync new rider availability
        if ($new_rider_id > 0) {
            syncRiderAvailabilityForUser($conn, $new_rider_id);
        }

        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('orders');
        header("Location: {$redirect_to}?success=" . urlencode("Successfully transferred {$transferred_count} delivery/deliveries to {$new_rider_name}. They can now see them in their queue."));
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: {$redirect_to}?error=" . urlencode('Bulk transfer failed: ' . $e->getMessage()));
        exit;
    }
}

function geocodeDestinationAddress($address) {
    $address = trim((string)$address);
    if ($address === '') return null;
    $queries = [$address . ', Cagayan, Philippines', $address . ', Philippines', $address];
    $isWithinPhilippines = function($lat, $lng) {
        return is_numeric($lat) && is_numeric($lng) &&
            $lat >= 4.2 && $lat <= 21.5 &&
            $lng >= 116.0 && $lng <= 127.5;
    };
    foreach ($queries as $q) {
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ph&viewbox=116.5,21.3,126.6,4.4&bounded=1&q=' . rawurlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: VIP-Ice-Plant-Delivery-Tracker/1.0\r\nAccept: application/json\r\n"
            ]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) continue;
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json[0])) continue;
        $lat = isset($json[0]['lat']) ? (float)$json[0]['lat'] : null;
        $lng = isset($json[0]['lon']) ? (float)$json[0]['lng'] : null;
        if ($lat !== null && $lng !== null && $isWithinPhilippines($lat, $lng)) {
            return ['lat' => $lat, 'lng' => $lng];
        }
    }
    return null;
}

function isJsonDeliveryRequest(): bool {
    if (!empty($_POST['ajax'])) {
        return true;
    }
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        return true;
    }
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

/**
 * Respond with JSON for AJAX requests, otherwise redirect.
 */
function respondDelivery($conn, bool $success, string $message, ?string $redirect = null): void {
    if ($redirect === null) {
        $redirect = $_POST['redirect_to'] ?? '../pages/delivery.php';
    }
    if (!preg_match('/^\.\.\/pages\/[\w]+\.php$/', $redirect)) {
        $redirect = '../pages/delivery.php';
    }
    if (isJsonDeliveryRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }
    $key = $success ? 'success' : 'error';
    header("Location: {$redirect}?{$key}=" . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $get_action = $_GET['action'] ?? '';
    if ($get_action === 'get_bulk_transfer_deliveries') {
        header('Content-Type: application/json; charset=utf-8');
        $source_rider_id = (int)($_GET['rider_id'] ?? 0);
        $user_role = (int)($_SESSION['user_role'] ?? 0);
        if (!in_array($user_role, $dashboard_ids, true) || in_array($user_role, $rider_ids, true)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        if ($source_rider_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid rider.']);
            exit;
        }
        $deliveries = deliveryFetchTransferableForRider($conn, $source_rider_id);
        echo json_encode([
            'success' => true,
            'deliveries' => $deliveries,
            'has_vehicle_issue' => deliveryRiderHasVehicleIssueDeliveries($conn, $source_rider_id),
        ]);
        exit;
    }
}

// Handle different delivery operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection: Validate token for state-changing POST actions - Security Fix
    $state_changing_actions = ['create_delivery', 'update_delivery_status', 'record_delivery_details', 'assign_rider', 'cancel_delivery', 'set_rider_availability', 'reschedule_delivery', 'transfer_returning_delivery', 'bulk_transfer'];
    $action = $_POST['action'] ?? '';
    if (in_array($action, $state_changing_actions, true)) {
        if (!validateCsrfToken(false)) {
            $error_msg = 'Invalid or expired security token. Please refresh the page and try again.';
            if (isJsonDeliveryRequest()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => $error_msg, 'csrf_token' => getCsrfToken()]);
                exit();
            }

            $redirect_to = $_POST['redirect_to'] ?? '../pages/delivery.php';
            if (!preg_match('/^\.\.\/pages\/[\w]+\.php$/', $redirect_to)) {
                $redirect_to = '../pages/delivery.php';
            }
            header("Location: {$redirect_to}?error=" . urlencode($error_msg));
            exit();
        }
    }
    
    // Restriction: Owner is view-only (Role_ID 1)
    $is_owner = ((int)($_SESSION['user_role'] ?? 0) === 1);
    if ($is_owner) {
        $error_msg = "Your account (Owner) is restricted to view-only access. Operations are not allowed.";
        if (isset($_POST['action']) || isset($_GET['action'])) {
             echo json_encode(['success' => false, 'error' => $error_msg]);
             exit();
        }
        $redirect_to = $_POST['redirect_to'] ?? '../pages/delivery.php';
        if (!preg_match('/^\.\.\/pages\/[\w]+\.php$/', $redirect_to)) {
            $redirect_to = '../pages/delivery.php';
        }
        header("Location: {$redirect_to}?error=" . urlencode($error_msg));
        exit();
    }

    $user_id = $_SESSION['user_id'] ?? 1;

    switch ($action) {
        case 'create_delivery':
            handleCreateDelivery($conn, $user_id);
            break;
        case 'update_delivery_status':
            handleUpdateDeliveryStatus($conn, $user_id);
            break;
        case 'record_delivery_details':
            handleRecordDeliveryDetails($conn, $user_id);
            break;
        case 'assign_rider':
            handleAssignRider($conn);
            break;
        case 'set_rider_availability':
            handleSetRiderAvailability($conn);
            break;
        case 'reschedule_delivery':
            handleRescheduleDelivery($conn, $user_id);
            break;
        case 'cancel_delivery':
            handleCancelDelivery($conn, $user_id);
            break;
        case 'transfer_returning_delivery':
            handleTransferReturningDelivery($conn, $user_id);
            break;
        case 'bulk_transfer':
            handleBulkTransfer($conn, $user_id);
            break;
        default:
            $redirect_to = $_POST['redirect_to'] ?? '../pages/delivery.php';
            if (!preg_match('/^\.\.\/pages\/[\w]+\.php$/', $redirect_to)) {
                $redirect_to = '../pages/delivery.php';
            }
            header("Location: {$redirect_to}?error=Invalid action");
            exit();
    }
}

/**
 * Create delivery record for an order
 */
function handleCreateDelivery($conn, $user_id) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $schedule_date = !empty($_POST['schedule_date']) ? $_POST['schedule_date'] : null;
    $delivered_by = trim($_POST['delivered_by'] ?? '');
    $delivered_to = trim($_POST['delivered_to'] ?? '');
    
    // Comprehensive validation
    $errors = [];
    
    // Order ID validation
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
    
    // Schedule date validation (if provided)
    if (!empty($schedule_date)) {
        $date_parts = explode('-', $schedule_date);
        if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
            $errors[] = "Invalid schedule date format.";
        }
    }
    
    // Delivery address validation
    if (!empty($delivery_address) && strlen($delivery_address) > 500) {
        $errors[] = "Delivery address must not exceed 500 characters.";
    }
    
    // Delivered by validation
    if (!empty($delivered_by) && strlen($delivered_by) > 100) {
        $errors[] = "Delivered by name must not exceed 100 characters.";
    }
    
    // Delivered to validation
    if (!empty($delivered_to) && strlen($delivered_to) > 100) {
        $errors[] = "Delivered to name must not exceed 100 characters.";
    }
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    if (!empty($errors)) {
        header("Location: ../pages/delivery.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }
    
    $conn->beginTransaction();
    try {
        // Allow a new delivery only when there is no prior record, or the latest one ended in Returning/Cancelled.
        $check_stmt = $conn->prepare("SELECT Delivery_ID, delivery_status FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
        $check_stmt->execute([$order_id]);
        $existing_delivery = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing_delivery && !in_array((string)($existing_delivery['delivery_status'] ?? ''), ['Returning', 'Cancelled'], true)) {
            throw new Exception("Delivery already exists for this order");
        }
        
        // Create delivery record
        $delivery_stmt = $conn->prepare("INSERT INTO delivery (
            Order_ID, delivery_address, schedule_date, delivery_status, delivered_by, delivered_to
        ) VALUES (?, ?, ?, 'Scheduled', ?, ?)");
        
        if (!$delivery_stmt->execute([$order_id, $delivery_address, $schedule_date, $delivered_by, $delivered_to])) {
            throw new Exception("Failed to create delivery");
        }
        
        $delivery_id = $conn->lastInsertId();
        
        // Get order details to create delivery_detail records
        $order_details_stmt = $conn->prepare("SELECT Order_detail_ID, Product_ID, ordered_qty 
                                               FROM order_details 
                                               WHERE Order_ID = ?");
        $order_details_stmt->execute([$order_id]);
        
        // Create delivery_detail records for each order item
        $delivery_detail_stmt = $conn->prepare("INSERT INTO delivery_detail (
            Delivery_ID, Order_detail_ID, received_qty, damage_qty, status
        ) VALUES (?, ?, ?, 0, 'Pending')");
        
        while ($order_detail = $order_details_stmt->fetch()) {
            $ordered_qty = floatval($order_detail['ordered_qty']);
            $delivery_detail_stmt->execute([
                $delivery_id, 
                $order_detail['Order_detail_ID'], 
                $ordered_qty
            ]);
        }

        // Save destination coordinates for stable map tracking (best effort)
        if (deliveryHasColumn($conn, 'destination_lat') && deliveryHasColumn($conn, 'destination_lng')) {
            $dest_addr = $delivery_address;
            if ($dest_addr === '') {
                try {
                    $ord = $conn->prepare("SELECT o.delivery_address, c.address
                                           FROM orders o
                                           LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                                           WHERE o.Order_ID = ?");
                    $ord->execute([$order_id]);
                    $row = $ord->fetch(PDO::FETCH_ASSOC);
                    $dest_addr = trim((string)($row['delivery_address'] ?? $row['address'] ?? ''));
                } catch (Exception $e) {}
            }
            $coords = geocodeDestinationAddress($dest_addr);
            if ($coords) {
                $upd = $conn->prepare("UPDATE delivery SET destination_lat = ?, destination_lng = ?, updated_at = NOW() WHERE Delivery_ID = ?");
                $upd->execute([$coords['lat'], $coords['lng'], $delivery_id]);
            }
        }

        $status_col = 'order_status';
        $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
        if ($col_check && $col_check->rowCount() > 0) {
            $status_col = $col_check->fetch(PDO::FETCH_ASSOC)['Field'];
        }
        $scheduled_status = getValidOrderStatus($conn, 'Scheduled for Delivery', ['Scheduled for Delivery', 'Requested', 'pending']);
        $conn->prepare("UPDATE orders SET {$status_col} = ? WHERE Order_ID = ?")->execute([$scheduled_status, $order_id]);
        
        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('orders');
        logActivity('DELIVERY', "Created delivery for Order #$order_id", $delivery_id);
        header("Location: ../pages/delivery.php?success=Delivery created successfully");
        exit();
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("Delivery creation error: " . $e->getMessage());
        header("Location: ../pages/delivery.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

/**
 * Update delivery status
 */
function handleUpdateDeliveryStatus($conn, $user_id) {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    $redirect_to = $_POST['redirect_to'] ?? '../pages/delivery.php';
    $user_role = (int)($_SESSION['user_role'] ?? 0);
    $is_rider = in_array($user_role, getRiderRoleIds($conn));
    if (!preg_match('/^\.\.\/pages\/[\w]+\.php$/', $redirect_to)) {
        $redirect_to = '../pages/delivery.php';
    }
    
    // Comprehensive validation
    $errors = [];
    
    // Delivery ID validation
    if (empty($delivery_id) || $delivery_id <= 0) {
        $errors[] = "Delivery ID is required.";
    } else {
        // Verify delivery exists
        $delivery_check = $conn->prepare("SELECT Delivery_ID, Order_ID, delivery_status FROM delivery WHERE Delivery_ID = ?");
        $delivery_check->execute([$delivery_id]);
        $delivery_row = $delivery_check->fetch(PDO::FETCH_ASSOC);
        if (!$delivery_row) {
            $errors[] = "Delivery does not exist.";
        } else {
            $current_status = (string)($delivery_row['delivery_status'] ?? '');
            if (in_array($current_status, ['Completed', 'Cancelled'], true)) {
                $errors[] = "This delivery can no longer be updated from Delivery Management.";
            }
            if (!$is_rider && in_array($current_status, ['Delivered', 'Returning'], true)) {
                $errors[] = "This delivery can no longer be updated from Delivery Management.";
            }
            if (!$is_rider && $current_status === 'In Transit') {
                $errors[] = "In Transit deliveries can no longer be updated manually from Delivery Management.";
            }
        }
    }

    // Riders may only update deliveries assigned to them (by ID or by name)
    if ($is_rider && $delivery_id > 0) {
        $assigned_id = riderGetUserIdByDeliveryId($conn, $delivery_id);
        if ($assigned_id !== (int)$user_id) {
            $errors[] = "Access denied - delivery not assigned to you.";
        }
    }
    
    // Status validation
    if (empty($new_status)) {
        $errors[] = "Status is required.";
    }
    
    $valid_statuses = ['Scheduled', 'In Transit', 'Delivered', 'Returning', 'Remitted', 'Completed'];
    if (!empty($new_status) && !in_array($new_status, $valid_statuses)) {
        $errors[] = "Invalid status. Must be one of: " . implode(', ', $valid_statuses);
    }

    // Only rider role can set status to Delivered, Returning, Remitted, or Completed
    if (in_array($new_status, ['Delivered', 'Returning', 'Remitted', 'Completed'])) {
        if (!$is_rider) {
            $errors[] = "Only delivery riders can set status to {$new_status}.";
        }
    }

    if ($new_status === 'In Transit') {
        $assignedRiderId = getDeliveryAssignedRiderId($conn, $delivery_id);
        if ($assignedRiderId <= 0) {
            $errors[] = "Cannot set status to In Transit — no rider is assigned to this delivery. Assign a rider first.";
        }
        if (!empty($delivery_row['Order_ID'])) {
            $prep_stmt = $conn->prepare("SELECT status FROM order_preparation_tasks WHERE Order_ID = ? LIMIT 1");
            $prep_stmt->execute([(int)$delivery_row['Order_ID']]);
            $prep_status = (string)($prep_stmt->fetchColumn() ?: 'not_started');
            $prep_status_key = strtolower(str_replace(' ', '_', trim($prep_status)));
            $prep_is_ready = in_array($prep_status_key, ['ready', 'ready_for_pickup', 'ready_to_pickup'], true);
            if (!$prep_is_ready) {
                $errors[] = "Inventory staff must mark this order as Ready for Pickup before delivery can start. Manager should confirm rider assignment first.";
            }
        }
    }
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    if (!empty($errors)) {
        header("Location: {$redirect_to}?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }
    
    $conn->beginTransaction();
    try {
        $update_stmt = $conn->prepare("UPDATE delivery SET 
            delivery_status = ?, 
            actual_date_arrived = CASE WHEN ? IN ('Delivered', 'Completed') THEN CURDATE() ELSE actual_date_arrived END,
            updated_at = NOW()
            WHERE Delivery_ID = ?");
        
        if (!$update_stmt->execute([$new_status, $new_status, $delivery_id])) {
            throw new Exception("Failed to update delivery status");
        }

        // Activity log (if activity_logs table exists)
        try {
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity) VALUES (?, ?)");
            $log_stmt->execute([$user_id, "Delivery #{$delivery_id} status updated to: {$new_status}"]);
        } catch (Exception $e) {
            // Table may not exist yet; log but don't fail
            error_log("Activity log skip: " . $e->getMessage());
        }

        if ($new_status === 'In Transit') {
            // Sync order status to Out for Delivery
            $order_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
            $order_stmt->execute([$delivery_id]);
            $order_row = $order_stmt->fetch();
            if ($order_row && !empty($order_row['Order_ID'])) {
                $status_col = 'order_status';
                $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
                if ($col_check && $col_check->rowCount() > 0) {
                    $status_col = $col_check->fetch()['Field'];
                }
                $out_status = getValidOrderStatus($conn, 'Out for Delivery', ['Out for Delivery', 'out for delivery']);
                $upd_stmt = $conn->prepare("UPDATE orders SET {$status_col} = ? WHERE Order_ID = ?");
                $upd_stmt->execute([$out_status, (int)$order_row['Order_ID']]);
            }
            $assignedRiderId = getDeliveryAssignedRiderId($conn, $delivery_id);
            if ($assignedRiderId > 0) {
                syncRiderAvailabilityForUser($conn, $assignedRiderId);
            }
        } elseif ($new_status === 'Delivered') {
            $update_details_stmt = $conn->prepare("UPDATE delivery_detail SET status = 'delivered', updated_at = NOW() WHERE Delivery_ID = ?");

            $update_details_stmt->execute([$delivery_id]);
            // Sync order status
            $order_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
            $order_stmt->execute([$delivery_id]);
            $order_row = $order_stmt->fetch();
            if ($order_row && !empty($order_row['Order_ID'])) {
                $order_id = intval($order_row['Order_ID']);
                
                $status_col = 'order_status';
                $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
                if ($col_check && $col_check->rowCount() > 0) {
                    $status_col = $col_check->fetch()['Field'];
                }
                $new_order_status = getValidOrderStatus($conn, 'Delivered (Pending Cash Turnover)', ['Delivered', 'Completed']);
                $upd_stmt = $conn->prepare("UPDATE orders SET {$status_col} = ? WHERE Order_ID = ?");
                $upd_stmt->execute([$new_order_status, $order_id]);
            }
            $assignedRiderId = getDeliveryAssignedRiderId($conn, $delivery_id);
            if ($assignedRiderId > 0) {
                syncRiderAvailabilityForUser($conn, $assignedRiderId);
            }
        } elseif ($new_status === 'Remitted') {
            // Remitted by rider - wait for cashier to record sale
            $update_details_stmt = $conn->prepare("UPDATE delivery_detail SET status = 'delivered', updated_at = NOW() WHERE Delivery_ID = ?");

            $update_details_stmt->execute([$delivery_id]);            publishRealtimeEvent([
                'event' => 'delivery.remitted',
                'data' => [
                    'delivery_id' => $delivery_id,
                    'status' => 'Remitted'
                ]
            ]);
            $assignedRiderId = getDeliveryAssignedRiderId($conn, $delivery_id);
            if ($assignedRiderId > 0) {
                syncRiderAvailabilityForUser($conn, $assignedRiderId);
            }
        } elseif ($new_status === 'Completed') {
            $update_details_stmt = $conn->prepare("UPDATE delivery_detail SET status = 'delivered', updated_at = NOW() WHERE Delivery_ID = ?");

            $update_details_stmt->execute([$delivery_id]);            $order_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
            $order_stmt->execute([$delivery_id]);
            $order_row = $order_stmt->fetch();
            if ($order_row && !empty($order_row['Order_ID'])) {
                $status_col = 'order_status';
                $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
                if ($col_check && $col_check->rowCount() > 0) {
                    $status_col = $col_check->fetch()['Field'];
                }
                $completed_status = getValidOrderStatus($conn, 'Completed', ['Completed', 'Delivered']);
                $upd_stmt = $conn->prepare("UPDATE orders SET {$status_col} = ? WHERE Order_ID = ?");
                $upd_stmt->execute([$completed_status, (int)$order_row['Order_ID']]);
                publishRealtimeEvent([
                    'event' => 'order.completed',
                    'data' => [
                        'delivery_id' => $delivery_id,
                        'order_id' => (int)$order_row['Order_ID'],
                        'status' => 'Completed'
                    ]
                ]);
            }
            $assignedRiderId = getDeliveryAssignedRiderId($conn, $delivery_id);
            if ($assignedRiderId > 0) {
                syncRiderAvailabilityForUser($conn, $assignedRiderId);
            }
        }

        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('orders');
        logActivity('DELIVERY', "Updated Delivery #$delivery_id status to '$new_status'", $delivery_id);
        header("Location: {$redirect_to}?success=Delivery status updated successfully");
        exit();
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("Delivery status update error: " . $e->getMessage());
        header("Location: {$redirect_to}?error=" . urlencode($e->getMessage()));
        exit();
    }
}

/**
 * Assign rider to delivery (manager only)
 */
function handleAssignRider($conn) {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $rider_id = isset($_POST['rider_id']) ? ($_POST['rider_id'] === '' ? null : (int)$_POST['rider_id']) : null;

    if ($delivery_id <= 0) {
        header('Location: ../pages/delivery.php?error=' . urlencode('Invalid delivery'));
        exit;
    }

    $status_stmt = $conn->prepare("SELECT delivery_status FROM delivery WHERE Delivery_ID = ?");
    $status_stmt->execute([$delivery_id]);
    $current_status = (string)($status_stmt->fetchColumn() ?: '');
    if ($current_status === '') {
        header('Location: ../pages/delivery.php?error=' . urlencode('Delivery not found'));
        exit;
    }
    if (deliveryStatusLocksAssignment($current_status)) {
        header('Location: ../pages/delivery.php?error=' . urlencode("Delivery #{$delivery_id} can no longer be assigned because it is already {$current_status}."));
        exit;
    }

    $existing_rider_id = getDeliveryAssignedRiderId($conn, $delivery_id);
    $rider_name = null;
    if ($rider_id !== null) {
        if (!riderCanBeAssignedToTarget($conn, $rider_id, $delivery_id)) {
            header('Location: ../pages/delivery.php?error=' . urlencode('Selected rider is not available for assignment.'));
            exit;
        }
        $name_stmt = $conn->prepare("SELECT COALESCE(full_name, user_name) FROM user WHERE User_ID = ?");
        $name_stmt->execute([$rider_id]);
        $rider_name = $name_stmt->fetchColumn();
    }
    $del_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $has_assigned_id = riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id');
    $has_delivered_by = in_array('delivered_by', $del_cols);

    $fields = [];
    $params = [];
    if ($has_assigned_id) {
        $fields[] = "assigned_rider_id = ?";
        $params[] = $rider_id;
    }
    if ($has_delivered_by) {
        $fields[] = "delivered_by = ?";
        $params[] = $rider_name;
    }
    $fields[] = "updated_at = NOW()";
    $params[] = $delivery_id;

    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("UPDATE delivery SET " . implode(", ", $fields) . " WHERE Delivery_ID = ?");
        $stmt->execute($params);

        if ($existing_rider_id > 0 && $existing_rider_id !== (int)$rider_id) {
            syncRiderAvailabilityForUser($conn, $existing_rider_id);
        }
        if ($rider_id !== null && $rider_id > 0) {
            syncRiderAvailabilityForUser($conn, $rider_id);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header('Location: ../pages/delivery.php?error=' . urlencode('Failed to assign rider: ' . $e->getMessage()));
        exit;
    }
    header('Location: ../pages/delivery.php?success=' . urlencode($rider_id ? 'Rider assigned' : 'Rider unassigned'));
    logActivity('DELIVERY', ($rider_id ? "Assigned rider $rider_name" : "Unassigned rider") . " to Delivery #$delivery_id", $delivery_id);
    exit;
}

function handleSetRiderAvailability(PDO $conn): void
{
    global $dashboard_ids;
    if (!in_array((int)($_SESSION['user_role'] ?? 0), $dashboard_ids, true)) {
        header('Location: ../pages/delivery.php?error=' . urlencode('Only management users can change rider availability.'));
        exit;
    }

    $rider_id = (int)($_POST['rider_id'] ?? 0);
    $status = trim((string)($_POST['availability_status'] ?? ''));
    $result = setManualRiderAvailability($conn, $rider_id, $status);

    if ($result['success']) {
        header('Location: ../pages/delivery.php?success=' . urlencode($result['message']));
    } else {
        header('Location: ../pages/delivery.php?error=' . urlencode($result['message']));
    }
    exit;
}

function handleRescheduleDelivery(PDO $conn, int $user_id): void
{
    global $dashboard_ids, $rider_ids;

    $user_role = (int)($_SESSION['user_role'] ?? 0);
    if (!in_array($user_role, $dashboard_ids, true) || in_array($user_role, $rider_ids, true)) {
        header('Location: ../pages/delivery.php?error=' . urlencode('Only management users can reschedule deliveries.'));
        exit;
    }

    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    $schedule_date = trim((string)($_POST['schedule_date'] ?? ''));
    $rider_id = isset($_POST['rider_id']) && $_POST['rider_id'] !== '' ? (int)$_POST['rider_id'] : null;

    $errors = [];
    if ($delivery_id <= 0) {
        $errors[] = 'Invalid delivery selected.';
    }
    if ($schedule_date === '') {
        $errors[] = 'New delivery date is required.';
    } else {
        $date_parts = explode('-', $schedule_date);
        if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            $errors[] = 'Invalid reschedule date.';
        }
    }
    if ($rider_id !== null && $rider_id <= 0) {
        $errors[] = 'Invalid rider selected.';
    }

    if (!empty($errors)) {
        header('Location: ../pages/delivery.php?error=' . urlencode(implode(' | ', $errors)));
        exit;
    }

    $conn->beginTransaction();
    try {
        $order_columns = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $has_order_delivery_address = in_array('delivery_address', $order_columns, true);
        $order_delivery_select = $has_order_delivery_address
            ? "o.delivery_address AS order_delivery_address,"
            : "'' AS order_delivery_address,";

        $delivery_stmt = $conn->prepare(
            "SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, d.delivery_address, d.delivered_to, d.cancellation_reason,
                     o.Customer_ID, {$order_delivery_select}
                     c.customer_name, c.address AS customer_address
              FROM delivery d
              LEFT JOIN orders o ON d.Order_ID = o.Order_ID
              LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
              WHERE d.Delivery_ID = ? LIMIT 1"
        );
        $delivery_stmt->execute([$delivery_id]);
        $source_delivery = $delivery_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$source_delivery) {
            throw new Exception('Delivery not found.');
        }

        $order_id = (int)($source_delivery['Order_ID'] ?? 0);
        if ($order_id <= 0) {
            throw new Exception('This delivery is not linked to an order.');
        }

        $is_cancelled_delivery = ((string)($source_delivery['delivery_status'] ?? '') === 'Cancelled');
        $is_returned_delivery = ((string)($source_delivery['delivery_status'] ?? '') === 'Returning');
        if (!$is_cancelled_delivery && !$is_returned_delivery) {
            throw new Exception('Only returned-to-store or cancelled deliveries can be rescheduled.');
        }

        if ($is_cancelled_delivery && !in_array((string)($source_delivery['cancellation_reason'] ?? ''), ['Customer unavailable', 'Reschedule'], true)) {
            throw new Exception('This delivery has been permanently cancelled and cannot be rescheduled.');
        }

        $latest_stmt = $conn->prepare("SELECT Delivery_ID, delivery_status FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
        $latest_stmt->execute([$order_id]);
        $latest_delivery = $latest_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$latest_delivery || (int)$latest_delivery['Delivery_ID'] !== $delivery_id) {
            throw new Exception('This order already has a newer delivery record.');
        }

        $delivered_by = '';
        if ($rider_id !== null) {
            if (!riderCanBeAssignedToTarget($conn, $rider_id, null, $order_id)) {
                throw new Exception('Selected rider is not available for assignment.');
            }
            $rider_stmt = $conn->prepare("SELECT COALESCE(full_name, user_name) FROM user WHERE User_ID = ? LIMIT 1");
            $rider_stmt->execute([$rider_id]);
            $delivered_by = (string)($rider_stmt->fetchColumn() ?: '');
            if ($delivered_by === '') {
                throw new Exception('Selected rider was not found.');
            }
        }

        $delivery_address = trim((string)($source_delivery['delivery_address'] ?? ''));
        if ($delivery_address === '') {
            $delivery_address = trim((string)($source_delivery['order_delivery_address'] ?? $source_delivery['customer_address'] ?? ''));
        }
        $delivered_to = trim((string)($source_delivery['customer_name'] ?? $source_delivery['delivered_to'] ?? ''));

        $delivery_columns = $conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_COLUMN);
        $delivery_fields = ['Order_ID', 'delivery_address', 'schedule_date', 'delivery_status', 'delivered_by', 'delivered_to'];
        $delivery_values = ['?', '?', '?', "'Scheduled'", '?', '?'];
        $delivery_params = [$order_id, $delivery_address, $schedule_date, $delivered_by, $delivered_to];
        if ($rider_id !== null && riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id')) {
            $delivery_fields[] = 'assigned_rider_id';
            $delivery_values[] = '?';
            $delivery_params[] = $rider_id;
        }

        $insert_delivery = $conn->prepare(
            "INSERT INTO delivery (" . implode(', ', $delivery_fields) . ")
             VALUES (" . implode(', ', $delivery_values) . ")"
        );
        $insert_delivery->execute($delivery_params);
        $new_delivery_id = (int)$conn->lastInsertId();

        $order_details_stmt = $conn->prepare("SELECT Order_detail_ID, ordered_qty FROM order_details WHERE Order_ID = ?");
        $order_details_stmt->execute([$order_id]);
        $insert_detail_stmt = $conn->prepare(
            "INSERT INTO delivery_detail (Delivery_ID, Order_detail_ID, received_qty, damage_qty, status)
             VALUES (?, ?, ?, 0, 'Pending')"
        );
        while ($detail = $order_details_stmt->fetch(PDO::FETCH_ASSOC)) {
            $insert_detail_stmt->execute([
                $new_delivery_id,
                (int)$detail['Order_detail_ID'],
                (float)$detail['ordered_qty']
            ]);
        }

        $status_col = 'order_status';
        $status_col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
        if ($status_col_check && $status_col_check->rowCount() > 0) {
            $status_col = $status_col_check->fetch(PDO::FETCH_ASSOC)['Field'];
        }
        $scheduled_status = getValidOrderStatus($conn, 'Scheduled for Delivery', ['Scheduled for Delivery', 'Requested', 'pending']);
        $order_cols = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $order_updates = ["{$status_col} = ?"];
        $order_params = [$scheduled_status];
        if (in_array('delivery_date', $order_cols, true)) {
            $order_updates[] = "delivery_date = ?";
            $order_params[] = $schedule_date;
        }
        if (in_array('updated_at', $order_cols, true)) {
            $order_updates[] = "updated_at = NOW()";
        }
        $order_params[] = $order_id;
        $conn->prepare("UPDATE orders SET " . implode(', ', $order_updates) . " WHERE Order_ID = ?")->execute($order_params);

        if ($rider_id !== null) {
            syncRiderAvailabilityForUser($conn, $rider_id);
        }

        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('orders');
        logActivity('DELIVERY', "Rescheduled Delivery #{$delivery_id} as new Delivery #{$new_delivery_id} for Order #{$order_id}", $new_delivery_id);
        header('Location: ../pages/delivery.php?success=' . urlencode('Delivery rescheduled successfully.'));
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        header('Location: ../pages/delivery.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

/**
 * Cancel delivery by manager (Scheduled deliveries only — soft cancel with reschedule option)
 */
function handleCancelDelivery($conn, $user_id) {
    global $dashboard_ids;

    ensureDeliveryCancellationSchema($conn);

    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $redirect_to = $_POST['redirect_to'] ?? '../pages/rider_view.php';
    $reason = trim((string)($_POST['reason'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $user_role = (int)($_SESSION['user_role'] ?? 0);
    $is_management_user = in_array($user_role, $dashboard_ids, true);
    
    if ($delivery_id <= 0) {
        header("Location: {$redirect_to}?error=" . urlencode('Invalid delivery ID'));
        exit;
    }
    
    $check_stmt = $conn->prepare("SELECT delivery_status, delivered_by FROM delivery WHERE Delivery_ID = ?");
    $check_stmt->execute([$delivery_id]);
    $delivery = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$delivery) {
        header("Location: {$redirect_to}?error=" . urlencode('Delivery not found'));
        exit;
    }
    
    if ($is_management_user) {
        if ((string)$delivery['delivery_status'] !== 'Scheduled') {
            header("Location: {$redirect_to}?error=" . urlencode('Only Scheduled deliveries can be cancelled from Delivery Management.'));
            exit;
        }

        $validReasons = getManagerCancellationReasons();
        if ($reason === '' || !in_array($reason, $validReasons, true)) {
            header("Location: {$redirect_to}?error=" . urlencode('Invalid cancellation reason selected.'));
            exit;
        }
    } elseif ($delivery['delivery_status'] !== 'Scheduled') {
        header("Location: {$redirect_to}?error=" . urlencode('Only Scheduled deliveries can be cancelled'));
        exit;
    }
    
    if (!$is_management_user) {
        $assigned_id = riderGetUserIdByDeliveryId($conn, $delivery_id);
        if ($assigned_id !== (int)$user_id) {
            header("Location: {$redirect_to}?error=" . urlencode('You are not assigned to this delivery'));
            exit;
        }
    }

    try {
        $conn->beginTransaction();

        if ($is_management_user) {
            $status_col = 'order_status';
            $status_col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
            if ($status_col_check && $status_col_check->rowCount() > 0) {
                $status_col = $status_col_check->fetch(PDO::FETCH_ASSOC)['Field'];
            }

            $is_permanent = !in_array($reason, ['Customer unavailable', 'Reschedule'], true);

            $update_stmt = $conn->prepare(
                "UPDATE delivery
                 SET delivery_status = 'Cancelled',
                     cancellation_reason = ?,
                     cancellation_remarks = ?,
                     updated_at = NOW()
                 WHERE Delivery_ID = ?"
            );
            $update_stmt->execute([$reason, ($remarks !== '' ? $remarks : null), $delivery_id]);

            $order_id_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
            $order_id_stmt->execute([$delivery_id]);
            $order_id = (int)($order_id_stmt->fetchColumn() ?: 0);
            if ($order_id > 0) {
                if ($is_permanent) {
                    $cancelled_status = getValidOrderStatus($conn, 'Cancelled', ['Cancelled', 'cancelled']);
                    $conn->prepare("UPDATE orders SET {$status_col} = ? WHERE Order_ID = ?")->execute([$cancelled_status, $order_id]);
                } else {
                    $pending_status = getValidOrderStatus($conn, 'Requested', ['Requested', 'pending']);
                    $conn->prepare("UPDATE orders SET {$status_col} = ? WHERE Order_ID = ?")->execute([$pending_status, $order_id]);
                }
            }

            logActivity('DELIVERY', "Manager cancelled Scheduled Delivery #$delivery_id" . ($reason !== '' ? " ({$reason})" : '') . ($is_permanent ? ' [Permanent]' : ''), $delivery_id);
        } else {
            $update_stmt = $conn->prepare("UPDATE delivery SET delivery_status = 'Cancelled', updated_at = NOW() WHERE Delivery_ID = ?");
            $update_stmt->execute([$delivery_id]);
            logActivity('DELIVERY', "Rider cancelled Delivery #$delivery_id", $delivery_id);
        }

        $assigned_rider_id = riderGetUserIdByDeliveryId($conn, $delivery_id);
        if ($assigned_rider_id > 0) {
            syncRiderAvailabilityForUser($conn, $assigned_rider_id);
        }

        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('orders');
        header("Location: {$redirect_to}?success=" . urlencode('Delivery cancelled successfully'));
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        header("Location: {$redirect_to}?error=" . urlencode($e->getMessage()));
        exit;
    }
}

/**
 * Record delivery details (received quantities, damages, etc.)
 */
function handleRecordDeliveryDetails($conn, $user_id) {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $delivery_details = json_decode($_POST['delivery_details'] ?? '[]', true);
    
    // Comprehensive validation
    $errors = [];
    
    // Delivery ID validation
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
    
    // Delivery details validation
    if (empty($delivery_details) || !is_array($delivery_details)) {
        $errors[] = "Delivery details are required.";
    } else {
        foreach ($delivery_details as $index => $detail) {
            $item_num = $index + 1;
            
            // Delivery detail ID validation
            $delivery_detail_id = intval($detail['delivery_detail_id'] ?? 0);
            if ($delivery_detail_id <= 0) {
                $errors[] = "Item #{$item_num}: Delivery detail ID is required.";
                continue;
            }
            
            // Verify delivery detail exists
            $detail_check = $conn->prepare("SELECT Delivery_Detail_ID FROM delivery_detail WHERE Delivery_Detail_ID = ? AND Delivery_ID = ?");
            $detail_check->execute([$delivery_detail_id, $delivery_id]);
            if (!$detail_check->fetch()) {
                $errors[] = "Item #{$item_num}: Delivery detail does not exist or does not belong to this delivery.";
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
            
            // Remarks validation
            $remarks = trim($detail['remarks'] ?? '');
            if (!empty($remarks) && strlen($remarks) > 500) {
                $errors[] = "Item #{$item_num}: Remarks must not exceed 500 characters.";
            }
        }
    }
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    if (!empty($errors)) {
        header("Location: ../pages/delivery.php?error=" . urlencode(implode(' | ', $errors)));
        exit();
    }
    
    $conn->beginTransaction();
    try {
        foreach ($delivery_details as $detail) {
            $delivery_detail_id = intval($detail['delivery_detail_id'] ?? 0);
            $received_qty = floatval($detail['received_qty'] ?? 0);
            $damage_qty = floatval($detail['damage_qty'] ?? 0);
            $remarks = trim($detail['remarks'] ?? '');
            
            $status = 'delivered';
            
            $update_stmt = $conn->prepare("UPDATE delivery_detail SET 
                received_qty = ?, damage_qty = ?, remarks = ?, status = ?, updated_at = NOW()
                WHERE Delivery_Detail_ID = ?");
            
            if (!$update_stmt->execute([$received_qty, $damage_qty, $remarks, $status, $delivery_detail_id])) {
                throw new Exception("Failed to update delivery detail");
            }
        }
        
        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        logActivity('DELIVERY', "Recorded proof of delivery for Delivery #$delivery_id", $delivery_id);
        header("Location: ../pages/delivery.php?success=Delivery details recorded successfully");
        exit();
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("Delivery details recording error: " . $e->getMessage());
        header("Location: ../pages/delivery.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

/**
 * Transfer a Returning delivery (Vehicle issue only) to a new rider
 * Status: Returning → Scheduled with new rider assigned
 */
function handleTransferReturningDelivery(PDO $conn, int $user_id): void
{
    global $dashboard_ids, $rider_ids;

    $user_role = (int)($_SESSION['user_role'] ?? 0);
    if (!in_array($user_role, $dashboard_ids, true) || in_array($user_role, $rider_ids, true)) {
        header('Location: ../pages/delivery.php?error=' . urlencode('Only management users can transfer deliveries.'));
        exit;
    }

    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    $new_rider_id = (int)($_POST['new_rider_id'] ?? 0);
    $redirect_to = $_POST['redirect_to'] ?? '../pages/delivery.php';

    if ($delivery_id <= 0 || $new_rider_id <= 0) {
        header("Location: {$redirect_to}?error=" . urlencode('Invalid delivery or rider selection.'));
        exit;
    }

    $stmt = $conn->prepare("SELECT Delivery_ID, Order_ID, delivery_status, cancellation_reason FROM delivery WHERE Delivery_ID = ?");
    $stmt->execute([$delivery_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$delivery) {
        header("Location: {$redirect_to}?error=" . urlencode('Delivery not found.'));
        exit;
    }

    $cancellation_reason = (string)($delivery['cancellation_reason'] ?? '');
    if ((string)$delivery['delivery_status'] !== 'Returning' || $cancellation_reason !== 'Vehicle issue') {
        header("Location: {$redirect_to}?error=" . urlencode('Only Returning deliveries with Vehicle issue reason can be transferred.'));
        exit;
    }

    if (!riderCanBeAssignedToTarget($conn, $new_rider_id, $delivery_id)) {
        header("Location: {$redirect_to}?error=" . urlencode('Selected rider is not available for assignment.'));
        exit;
    }

    $rider_stmt = $conn->prepare("SELECT COALESCE(full_name, user_name) FROM user WHERE User_ID = ?");
    $rider_stmt->execute([$new_rider_id]);
    $new_rider_name = (string)($rider_stmt->fetchColumn() ?: '');
    if ($new_rider_name === '') {
        header("Location: {$redirect_to}?error=" . urlencode('Rider not found.'));
        exit;
    }

    $old_rider_id = getDeliveryAssignedRiderId($conn, $delivery_id);
    $order_id = (int)$delivery['Order_ID'];

    if ($new_rider_id === $old_rider_id) {
        header("Location: {$redirect_to}?error=" . urlencode('Cannot transfer to the same rider — they are the one with the vehicle issue.'));
        exit;
    }

    $conn->beginTransaction();
    try {
        deliveryApplyRiderTransfer($conn, $delivery_id, $new_rider_id, $new_rider_name);

        $order_status_col = 'order_status';
        $col_check = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
        if ($col_check && $col_check->rowCount() > 0) {
            $order_status_col = $col_check->fetch(PDO::FETCH_ASSOC)['Field'];
        }
        $scheduled_status = getValidOrderStatus($conn, 'Scheduled for Delivery', ['Scheduled for Delivery', 'Requested', 'pending']);
        $conn->prepare("UPDATE orders SET {$order_status_col} = ? WHERE Order_ID = ?")->execute([$scheduled_status, $order_id]);

        if ($old_rider_id > 0) {
            ensureRiderWorkflowSchema($conn);
            $conn->prepare("INSERT INTO rider_settings (User_ID, availability_status, last_set_at)
                            VALUES (?, 'Off Duty', NOW())
                            ON DUPLICATE KEY UPDATE availability_status = 'Off Duty', last_set_at = NOW()")->execute([$old_rider_id]);
        }

        if ($new_rider_id > 0) {
            syncRiderAvailabilityForUser($conn, $new_rider_id);
        }

        logActivity('DELIVERY', "Transferred Delivery #{$delivery_id} (Order #{$order_id}) from Rider #{$old_rider_id} to {$new_rider_name} due to Vehicle issue.", $delivery_id);

        $notifMsg = "New delivery assigned: Delivery #{$delivery_id} for Order #{$order_id}. Previously flagged as Vehicle issue — please coordinate pickup with the original rider.";
        $notifStmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID, Log_Time) VALUES (?, 'NOTIFICATION', ?, ?, CURRENT_TIMESTAMP)");
        $notifStmt->execute([$new_rider_id, $notifMsg, $delivery_id]);

        // Log transfer in delivery_transfers table
        $transferStmt = $conn->prepare("INSERT INTO delivery_transfers (Delivery_ID, from_rider_id, to_rider_id, transferred_by_user_id, reason) VALUES (?, ?, ?, ?, 'Vehicle issue')");
        $transferStmt->execute([$delivery_id, $old_rider_id ?: null, $new_rider_id, $user_id]);

        $conn->commit();
        cacheInvalidateTable('delivery');
        cacheInvalidateTable('delivery_detail');
        cacheInvalidateTable('orders');
        header("Location: {$redirect_to}?success=" . urlencode("Delivery transferred to {$new_rider_name}. They can now see it in their queue."));
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: {$redirect_to}?error=" . urlencode($e->getMessage()));
        exit;
    }
}
