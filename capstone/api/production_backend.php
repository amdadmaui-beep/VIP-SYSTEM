<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id']);
    $produced_qty = !empty($_POST['produced_qty']) ? floatval($_POST['produced_qty']) : 0;
    $quantity_unit = !empty($_POST['quantity_unit']) ? $_POST['quantity_unit'] : 'kg';
    $production_date = $_POST['production_date'];
    $production_type = !empty($_POST['production_type']) ? $_POST['production_type'] : null;
    $order_id = !empty($_POST['order_id']) ? intval($_POST['order_id']) : null;
    $bag_size = !empty($_POST['bag_size']) ? floatval($_POST['bag_size']) : null;
    $bag_size_unit = !empty($_POST['bag_size_unit']) ? $_POST['bag_size_unit'] : 'kg';
    $number_of_bags = !empty($_POST['number_of_bags']) ? intval($_POST['number_of_bags']) : null;
    $created_by = $_SESSION['user_id'] ?? 1;

    // Basic validation
    $errors = [];
    if (empty($product_id)) $errors[] = "Product is required.";
    
    // For order and stock production, validate number_of_bags instead of produced_qty
    if ($production_type === 'orders' || $production_type === 'stockin') {
        if (empty($number_of_bags) || $number_of_bags <= 0) {
            $errors[] = "Number of packs must be greater than 0.";
        }
        // For orders and stock, calculate produced_qty from number_of_bags and pack size
        // If bag_size is provided, use it; otherwise use a default calculation
        if ($bag_size && $bag_size > 0 && $number_of_bags > 0) {
            if ($bag_size_unit === 'grams') {
                // Convert grams to kg for inventory
                $produced_qty = ($bag_size * $number_of_bags) / 1000;
                $quantity_unit = 'kg';
            } elseif ($bag_size_unit === 'blocks') {
                // For blocks, treat as 1 kg per block
                $produced_qty = $number_of_bags; // 1 block = 1 kg equivalent
                $quantity_unit = 'kg';
            } else {
                // kg or other units
                $produced_qty = $bag_size * $number_of_bags;
                $quantity_unit = $bag_size_unit;
            }
        } else {
            // Fallback: use number_of_bags as produced_qty (assuming each pack = 1 kg equivalent)
            $produced_qty = $number_of_bags;
            $quantity_unit = 'kg';
        }
    } else {
        // For regular production, validate produced_qty
        if ($produced_qty <= 0) $errors[] = "Produced quantity must be greater than 0.";
    }
    
    // Convert quantity to kg for inventory calculations (after validation and calculation)
    $produced_qty_kg = $produced_qty;
    if ($quantity_unit === 'grams') {
        $produced_qty_kg = $produced_qty / 1000; // Convert grams to kg
    } elseif ($quantity_unit === 'blocks') {
        // For blocks, we'll store as-is but convert to kg for inventory
        // Assuming 1 block = 1 kg equivalent (adjust as needed)
        $produced_qty_kg = $produced_qty;
    }
    
    if (empty($production_date)) $errors[] = "Production date is required.";
    if (empty($production_type)) $errors[] = "Production type is required.";
    if ($production_type === 'orders' && empty($order_id)) $errors[] = "Order is required when production type is 'For Customer Order'.";

    if (empty($errors)) {
        // Insert into productions table - store original values with units
        // Note: We store the original quantity and unit, but use converted kg for inventory
        $stmt = $conn->prepare("INSERT INTO productions (Product_ID, production_type, produced_qty, production_date, created_by, Order_ID, bag_size, number_of_bags) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isdsiidd", $product_id, $production_type, $produced_qty, $production_date, $created_by, $order_id, $bag_size, $number_of_bags);

        if ($stmt->execute()) {
            $production_id = $stmt->insert_id;

            // Now insert into stockin_inventory
            // Use converted kg value for inventory
            $check_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY created_at DESC LIMIT 1");
            $check_stmt->bind_param("i", $product_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            if ($result->num_rows > 0) {
                // Update existing inventory
                $row = $result->fetch_assoc();
                $new_quantity = $row['quantity'] + $produced_qty_kg;
                $update_stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
                $update_stmt->bind_param("di", $new_quantity, $row['Inventory_ID']);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // Insert new inventory record
                $insert_inv_stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, Production_ID, date_in, handled_by, quantity, storage_limit) VALUES (?, ?, ?, ?, ?, 1000)");
                $insert_inv_stmt->bind_param("iisid", $product_id, $production_id, $production_date, $created_by, $produced_qty_kg);
                $insert_inv_stmt->execute();
                $insert_inv_stmt->close();
            }

            $check_stmt->close();
            $stmt->close();
            header("Location: ../pages/production.php?success=1");
            exit();
        } else {
            $errors[] = "Error recording production: " . $conn->error;
        }
        $stmt->close();
    }
}
?>
