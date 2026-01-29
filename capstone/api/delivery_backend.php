<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle different delivery operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
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
        default:
            header("Location: ../pages/delivery.php?error=Invalid action");
            exit();
    }
}

/**
 * Create delivery record for an order
 */
function handleCreateDelivery($conn, $user_id) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $schedule_date = $_POST['schedule_date'] ?? null;
    $delivered_by = trim($_POST['delivered_by'] ?? '');
    $delivered_to = trim($_POST['delivered_to'] ?? '');
    
    if (empty($order_id)) {
        header("Location: ../pages/delivery.php?error=Order ID is required");
        exit();
    }
    
    $conn->begin_transaction();
    try {
        // Check if delivery already exists
        $check_stmt = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Order_ID = ?");
        $check_stmt->bind_param("i", $order_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $check_stmt->close();
            throw new Exception("Delivery already exists for this order");
        }
        $check_stmt->close();
        
        // Create delivery record
        $delivery_stmt = $conn->prepare("INSERT INTO delivery (
            Order_ID, delivery_address, schedule_date, delivery_status, delivered_by, delivered_to
        ) VALUES (?, ?, ?, 'Scheduled', ?, ?)");
        $delivery_stmt->bind_param("issss", $order_id, $delivery_address, $schedule_date, $delivered_by, $delivered_to);
        
        if (!$delivery_stmt->execute()) {
            throw new Exception("Failed to create delivery: " . $delivery_stmt->error);
        }
        
        $delivery_id = $conn->insert_id;
        $delivery_stmt->close();
        
        // Get order details to create delivery_detail records
        $order_details_stmt = $conn->prepare("SELECT Order_detail_ID, Product_ID, ordered_qty 
                                               FROM order_details 
                                               WHERE Order_ID = ?");
        $order_details_stmt->bind_param("i", $order_id);
        $order_details_stmt->execute();
        $order_details_result = $order_details_stmt->get_result();
        
        // Create delivery_detail records for each order item
        $delivery_detail_stmt = $conn->prepare("INSERT INTO delivery_detail (
            Delivery_ID, Order_detail_ID, received_qty, damage_qty, status
        ) VALUES (?, ?, ?, 0, 'Pending')");
        
        while ($order_detail = $order_details_result->fetch_assoc()) {
            $ordered_qty = floatval($order_detail['ordered_qty']);
            $delivery_detail_stmt->bind_param("iid", 
                $delivery_id, 
                $order_detail['Order_detail_ID'], 
                $ordered_qty
            );
            $delivery_detail_stmt->execute();
        }
        
        $delivery_detail_stmt->close();
        $order_details_stmt->close();
        
        $conn->commit();
        header("Location: ../pages/delivery.php?success=Delivery created successfully");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
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
    
    if (empty($delivery_id) || empty($new_status)) {
        header("Location: ../pages/delivery.php?error=Delivery ID and status are required");
        exit();
    }
    
    // Validate status
    $valid_statuses = ['Scheduled', 'In Transit', 'Delivered'];
    if (!in_array($new_status, $valid_statuses)) {
        header("Location: ../pages/delivery.php?error=Invalid status");
        exit();
    }
    
    $conn->begin_transaction();
    try {
        $update_stmt = $conn->prepare("UPDATE delivery SET 
            delivery_status = ?, 
            actual_date_arrived = CASE WHEN ? = 'Delivered' THEN CURDATE() ELSE actual_date_arrived END,
            updated_at = NOW()
            WHERE Delivery_ID = ?");
        $update_stmt->bind_param("ssi", $new_status, $new_status, $delivery_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update delivery status: " . $update_stmt->error);
        }
        
        $update_stmt->close();
        
        // If status is "Delivered", update delivery_detail statuses
        if ($new_status === 'Delivered') {
            $update_details_stmt = $conn->prepare("UPDATE delivery_detail SET 
                status = 'Delivered', updated_at = NOW()
                WHERE Delivery_ID = ?");
            $update_details_stmt->bind_param("i", $delivery_id);
            $update_details_stmt->execute();
            $update_details_stmt->close();
        }
        
        $conn->commit();
        header("Location: ../pages/delivery.php?success=Delivery status updated successfully");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delivery status update error: " . $e->getMessage());
        header("Location: ../pages/delivery.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

/**
 * Record delivery details (received quantities, damages, etc.)
 */
function handleRecordDeliveryDetails($conn, $user_id) {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $delivery_details = json_decode($_POST['delivery_details'] ?? '[]', true);
    
    if (empty($delivery_id) || empty($delivery_details) || !is_array($delivery_details)) {
        header("Location: ../pages/delivery.php?error=Invalid delivery details");
        exit();
    }
    
    $conn->begin_transaction();
    try {
        foreach ($delivery_details as $detail) {
            $delivery_detail_id = intval($detail['delivery_detail_id'] ?? 0);
            $received_qty = floatval($detail['received_qty'] ?? 0);
            $damage_qty = floatval($detail['damage_qty'] ?? 0);
            $remarks = trim($detail['remarks'] ?? '');
            
            // Determine status based on quantities
            $status = 'Delivered';
            if ($damage_qty > 0) {
                $status = 'Damaged';
            } elseif ($received_qty < 0) {
                $status = 'Partial';
            }
            
            $update_stmt = $conn->prepare("UPDATE delivery_detail SET 
                received_qty = ?, damage_qty = ?, remarks = ?, status = ?, updated_at = NOW()
                WHERE Delivery_Detail_ID = ?");
            $update_stmt->bind_param("ddssi", $received_qty, $damage_qty, $remarks, $status, $delivery_detail_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update delivery detail: " . $update_stmt->error);
            }
            $update_stmt->close();
        }
        
        $conn->commit();
        header("Location: ../pages/delivery.php?success=Delivery details recorded successfully");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delivery details recording error: " . $e->getMessage());
        header("Location: ../pages/delivery.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}
