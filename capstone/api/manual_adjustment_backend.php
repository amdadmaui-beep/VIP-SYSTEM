<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_adjustment'])) {
    $product_id = intval($_POST['product_id']);
    $adjustment_value = floatval($_POST['adjustment_value']);
    $reason = trim($_POST['reason']);
    $user_id = $_SESSION['user_id'] ?? 1;

    if ($product_id > 0 && $adjustment_value != 0 && !empty($reason)) {
        $conn->begin_transaction();
        try {
            // Insert into manual_adjustment table
            // Use MySQL CURDATE() to get current date from database server
            $notes = 'Manual inventory adjustment';
            $stmt = $conn->prepare("INSERT INTO manual_adjustment (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)");
            $stmt->bind_param("si", $notes, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert adjustment: " . $stmt->error);
            }
            $adjustment_id = $conn->insert_id;
            $stmt->close();

            // Get current quantity
            $stmt = $conn->prepare("SELECT quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $stmt->bind_result($current_quantity);
            $stmt->fetch();
            $stmt->close();

            $old_quantity = $current_quantity ?? 0;
            $new_quantity = $old_quantity + $adjustment_value;
            $adjustment_type = $adjustment_value > 0 ? 'increase' : 'decrease';

            // Insert into adjustment_details
            $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
            $old_qty_str = (string)$old_quantity;
            $new_qty_str = (string)$new_quantity;
            $stmt->bind_param("iissss", $product_id, $adjustment_id, $old_qty_str, $new_qty_str, $adjustment_type, $reason);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert adjustment details: " . $stmt->error);
            }
            $stmt->close();

            // Update or insert stockin_inventory
            if ($current_quantity !== null) {
                // Get the Inventory_ID of the most recent record for this product
                $stmt = $conn->prepare("SELECT Inventory_ID FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $stmt->bind_result($inventory_id);
                $stmt->fetch();
                $stmt->close();

                // Update the specific inventory record
                $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
                $new_quantity_str = (string)$new_quantity;
                $stmt->bind_param("si", $new_quantity_str, $inventory_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update inventory: " . $stmt->error);
                }
                $stmt->close();
            } else {
                // If no inventory record exists, create one
                $stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, quantity, created_at, updated_at) VALUES (?, CURDATE(), ?, NOW(), NOW())");
                $new_quantity_str = (string)$new_quantity;
                $stmt->bind_param("is", $product_id, $new_quantity_str);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert inventory: " . $stmt->error);
                }
                $stmt->close();
            }

            $conn->commit();
            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Adjustment saved successfully!',
                    confirmButtonText: 'OK'
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            </script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert(" . json_encode('Error saving adjustment: ' . $e->getMessage()) . ");</script>";
        }
    } else {
        echo "<script>alert(" . json_encode('Please fill in all required fields.') . ");</script>";
    }
}
?>
