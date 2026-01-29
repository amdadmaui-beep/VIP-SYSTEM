<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle save adjustments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_adjustments'])) {
    $adjustments = $_POST['adjust'] ?? [];
    $reasons = $_POST['reason'] ?? [];
    $user_id = $_SESSION['user_id'] ?? 1;

    if (!empty($adjustments)) {
        $conn->begin_transaction();
        try {
            // Insert into adjustments table
            $adjustment_date = date('Y-m-d');
            $notes = 'Manual inventory adjustment';
            $stmt = $conn->prepare("INSERT INTO adjustments (adjustment_date, notes, created_by) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $adjustment_date, $notes, $user_id);
            $stmt->execute();
            $adjustment_id = $conn->insert_id;
            $stmt->close();

            foreach ($adjustments as $product_id => $adjustment_value) {
                $adjustment_value = floatval($adjustment_value);
                if ($adjustment_value != 0) {
                    $reason = $reasons[$product_id] ?? '';

                    // Get current quantity
                    $stmt = $conn->prepare("SELECT quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
                    $stmt->bind_param("i", $product_id);
                    $stmt->execute();
                    $stmt->bind_result($current_quantity);
                    $stmt->fetch();
                    $stmt->close();

                    $old_quantity = $current_quantity;
                    $new_quantity = $old_quantity + $adjustment_value;
                    $adjustment_type = $adjustment_value > 0 ? 'increase' : 'decrease';

                    // Insert into adjustment_details
                    $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiddss", $product_id, $adjustment_id, $old_quantity, $new_quantity, $adjustment_type, $reason);
                    $stmt->execute();
                    $stmt->close();

                    // Get the Inventory_ID of the most recent record for this product
                    $stmt = $conn->prepare("SELECT Inventory_ID FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
                    $stmt->bind_param("i", $product_id);
                    $stmt->execute();
                    $stmt->bind_result($inventory_id);
                    $stmt->fetch();
                    $stmt->close();

                    // Update the specific inventory record
                    $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
                    $stmt->bind_param("di", $new_quantity, $inventory_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();
            echo "<script>alert('Adjustments saved successfully!');</script>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('Error saving adjustments: " . $e->getMessage() . "');</script>";
        }
    }
}
?>
