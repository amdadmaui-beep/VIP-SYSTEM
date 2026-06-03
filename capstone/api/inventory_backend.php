<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/logger.php';
require_once '../includes/adjustment_reason_helper.php';
require_once '../includes/csrf.php';

// Accessible to Owner (1), Manager (2), and Cashier (3)
requireRole([1, 2, 3]);

// Handle save adjustments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_adjustments'])) {
    if (!validateCsrfToken(false)) {
        echo "<script>alert('Invalid or expired security token. Please refresh the page and try again.');</script>";
        return;
    }

    $adjustments = $_POST['adjust'] ?? [];
    $reasons = $_POST['reason'] ?? [];
    $user_id = $_SESSION['user_id'] ?? 1;

    // Restriction: Owner (Role_ID 1) is restricted to view-only mode
    if (isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1) {
        $error_msg = "Your account (Owner) is restricted to view-only access. Inventory operations are not allowed.";
        echo "<script>
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Access Restricted',
                    text: " . json_encode($error_msg) . ",
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'OK'
                });
            } else {
                alert(" . json_encode($error_msg) . ");
            }
        </script>";
        return;
    }

    // Comprehensive validation
    $errors = [];
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    } else {
        $user_check = $conn->prepare("SELECT User_ID FROM user WHERE User_ID = ?");
        $user_check->execute([$user_id]);
        if (!$user_check->fetch()) {
            $errors[] = "Invalid user session. Please log in again.";
        }
    }
    
    // Validate adjustments array
    if (empty($adjustments) || !is_array($adjustments)) {
        $errors[] = "No adjustments provided.";
    } else {
        $valid_reasons = getAdjustmentReasonOptions($conn);
        if (empty($valid_reasons)) {
            $errors[] = "Adjustment reasons are not configured in the database.";
        }
        foreach ($adjustments as $product_id => $adjustment_value) {
            $product_id = intval($product_id);
            $adjustment_value = floatval($adjustment_value);
            $reason = normalizeAdjustmentReasonValue($conn, trim($reasons[$product_id] ?? ''));
            
            // Product validation
            if ($product_id <= 0) {
                $errors[] = "Invalid product ID.";
                continue;
            }
            
            // Verify product exists
            $product_check = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
            $product_check->execute([$product_id]);
            $product_data = $product_check->fetch(PDO::FETCH_ASSOC);
            if (!$product_data) {
                $errors[] = "Product ID {$product_id} does not exist.";
                continue;
            }
            
            // Adjustment value validation
            if ($adjustment_value == 0) {
                continue; // Skip zero adjustments
            }
            if (abs($adjustment_value) > 999999) {
                $errors[] = "Adjustment value for {$product_data['product_name']} exceeds maximum (999,999).";
            }
            
            // Reason validation
            if (empty($reason)) {
                $errors[] = "Reason is required for {$product_data['product_name']}.";
            } elseif (!in_array($reason, $valid_reasons, true)) {
                $errors[] = "Invalid reason selected for {$product_data['product_name']}.";
            }
        }
    }
    
    if (!empty($errors)) {
        echo "<script>
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    html: " . json_encode(implode('<br>', array_map('htmlspecialchars', $errors))) . ",
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'OK'
                });
            } else {
                alert(" . json_encode(implode('\\n', $errors)) . ");
            }
        </script>";
        return;
    }
    
    if (!empty($adjustments)) {
        $conn->beginTransaction();
        try {
            // Insert into adjustments table
            $adjustment_date = date('Y-m-d');
            $notes = 'Manual inventory adjustment';
            $stmt = $conn->prepare("INSERT INTO adjustments (adjustment_date, notes, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$adjustment_date, $notes, $user_id]);
            $adjustment_id = $conn->lastInsertId();

            foreach ($adjustments as $product_id => $adjustment_value) {
                $product_id = intval($product_id);
                $adjustment_value = floatval($adjustment_value);
                if ($adjustment_value != 0) {
                    $reason = normalizeAdjustmentReasonValue($conn, trim($reasons[$product_id] ?? ''));

                    // Get current quantity
                    $stmt = $conn->prepare("SELECT quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
                    $stmt->execute([$product_id]);
                    $current_result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $current_quantity = $current_result !== false ? floatval($current_result['quantity']) : 0;

                    $old_quantity = $current_quantity;
                    $new_quantity = $old_quantity + $adjustment_value;
                    
                    // Validate resulting quantity is not negative
                    if ($new_quantity < 0) {
                        throw new Exception("Adjustment for product ID {$product_id} would result in negative inventory. Current: " . number_format($old_quantity, 2) . ", Adjustment: " . ($adjustment_value >= 0 ? '+' : '') . number_format($adjustment_value, 2));
                    }
                    
                    $adjustment_type = $adjustment_value > 0 ? 'increase' : 'decrease';

                    // Insert into adjustment_details
                    $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$product_id, $adjustment_id, $old_quantity, $new_quantity, $adjustment_type, $reason]);

                    // Get the Inventory_ID of the most recent record for this product
                    $stmt = $conn->prepare("SELECT Inventory_ID FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
                    $stmt->execute([$product_id]);
                    $inventory_result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $inventory_id = $inventory_result !== false ? intval($inventory_result['Inventory_ID']) : null;

                    // Update the specific inventory record
                    if ($inventory_id) {
                        $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
                        $stmt->execute([$new_quantity, $inventory_id]);
                    }
                }
            }

            $conn->commit();
            cacheInvalidateTable('adjustments');
            cacheInvalidateTable('adjustment_details');
            cacheInvalidateTable('stockin_inventory');
            cacheInvalidateTable('products');

            logActivity('INVENTORY', "Performed batch manual adjustment (ID: $adjustment_id)", $adjustment_id);

            echo "<script>
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Adjustments saved successfully!',
                        confirmButtonColor: '#6366f1',
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            location.reload();
                        }
                    });
                } else {
                    alert('Adjustments saved successfully!');
                    location.reload();
                }
            </script>";
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error_msg = htmlspecialchars($e->getMessage(), ENT_QUOTES);
            echo "<script>
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: " . json_encode($error_msg) . ",
                        confirmButtonColor: '#ef4444',
                        confirmButtonText: 'OK'
                    });
                } else {
                    alert(" . json_encode($error_msg) . ");
                }
            </script>";
        }
    }
}
?>
