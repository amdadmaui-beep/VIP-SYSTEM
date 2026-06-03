<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/logger.php';
require_once '../includes/module_access.php';
require_once '../includes/adjustment_reason_helper.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/damage_type_helper.php';
$allowed = array_unique(array_merge(getDashboardRoleIds($conn), getInventoryStaffRoleIds($conn)));
requireRole(empty($allowed) ? [1] : $allowed);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_adjustment'])) {
    if (!validateCsrfToken(false)) {
        $error_msg = "Invalid or expired security token. Please refresh the page and try again.";
        echo "<script>
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Security Check Failed', text: " . json_encode($error_msg) . ", confirmButtonColor: '#ef4444' });
            } else {
                alert(" . json_encode($error_msg) . ");
            }
        </script>";
        return;
    }

    if (!isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_manual_adjustment', true)) {
        $error_msg = "You are restricted from manual adjustment.";
        echo "<script>
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Access Restricted', text: " . json_encode($error_msg) . ", confirmButtonColor: '#ef4444' });
            } else {
                alert(" . json_encode($error_msg) . ");
            }
        </script>";
        return;
    }
    $product_id = intval($_POST['product_id'] ?? 0);
    $adjustment_value = isset($_POST['adjustment_value']) ? floatval($_POST['adjustment_value']) : 0;
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $user_id = intval($_SESSION['user_id'] ?? 0);

    // Restriction: Owner (Role_ID 1) is restricted to view-only mode
    if (isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1) {
        $error_msg = "Your account (Owner) is restricted to view-only access. Manual adjustments are not allowed.";
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
    
    // Validate product_id
    if ($product_id <= 0) {
        $errors[] = "Please select a valid product.";
    } else {
        // Verify product exists and is not discontinued
        $check_product = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
        $check_product->execute([$product_id]);
        $product_data = $check_product->fetch(PDO::FETCH_ASSOC);
        if (!$product_data) {
            $errors[] = "Selected product does not exist.";
        } else {
            if ($product_data['is_discontinued'] == 1) {
                $errors[] = "Cannot adjust inventory for discontinued products.";
            }
        }
    }
    
    // Validate adjustment_value
    if ($adjustment_value == 0) {
        $errors[] = "Adjustment value cannot be zero. Please enter a positive or negative value.";
    } elseif (abs($adjustment_value) > 999999) {
        $errors[] = "Adjustment value is too large. Maximum allowed: 999,999.";
    }
    
    // Validate reason
    $valid_reasons = getAdjustmentReasonOptions($conn);
    $reason = normalizeAdjustmentReasonValue($conn, $reason);
    if (empty($valid_reasons)) {
        $errors[] = "Adjustment reasons are not configured in the database.";
    } elseif (empty($reason)) {
        $errors[] = "Reason is required.";
    } elseif (!in_array($reason, $valid_reasons, true)) {
        $errors[] = "Invalid reason selected ($reason). Please choose from the available options.";
    }

    if (strlen($remarks) > 500) {
        $errors[] = "Remarks must not exceed 500 characters.";
    }

    if (isAdjustmentOtherReason($reason) && $remarks === '') {
        $errors[] = "Remarks are required when the reason is Other.";
    }
    
    // Validate user_id
    if ($user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    // If validation passes, proceed with transaction
    if (empty($errors)) {
        $conn->beginTransaction();
        try {
            // Use manual_adjustment if exists, else adjustments
            $tables = array_column($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
            $adj_table = in_array('manual_adjustment', $tables) ? 'manual_adjustment' : 'adjustments';
            $notes = $remarks !== '' ? $remarks : 'Manual inventory adjustment';
            $stmt = $conn->prepare("INSERT INTO {$adj_table} (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)");
            if (!$stmt->execute([$notes, $user_id])) {
                throw new Exception("Failed to insert adjustment");
            }
            $adjustment_id = $conn->lastInsertId();

            // Get current quantity (handle NULL updated_at by falling back to created_at/date_in)
            $tables = array_column($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
            if (!in_array('stockin_inventory', $tables)) {
                throw new Exception("Inventory table not found (stockin_inventory).");
            }
            $si_cols = $conn->query("SHOW COLUMNS FROM stockin_inventory")->fetchAll(PDO::FETCH_COLUMN);
            $si_id_col = in_array('Inventory_ID', $si_cols) ? 'Inventory_ID' : (in_array('inventory_id', $si_cols) ? 'inventory_id' : 'Inventory_ID');
            $si_time_cols = [];
            if (in_array('updated_at', $si_cols)) $si_time_cols[] = 'updated_at';
            if (in_array('created_at', $si_cols)) $si_time_cols[] = 'created_at';
            if (in_array('date_in', $si_cols)) $si_time_cols[] = 'date_in';
            if (in_array('inventory_date', $si_cols)) $si_time_cols[] = 'inventory_date';
            $si_order_expr = !empty($si_time_cols) ? ("COALESCE(" . implode(', ', $si_time_cols) . ")") : $si_id_col;
            $si_order_by = "{$si_order_expr} DESC, {$si_id_col} DESC";

            $stmt = $conn->prepare("SELECT quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$si_order_by} LIMIT 1");
            $stmt->execute([$product_id]);
            $current_quantity_result = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_quantity = $current_quantity_result !== false ? $current_quantity_result['quantity'] : false;

            $old_quantity = $current_quantity !== false ? floatval($current_quantity) : 0;
            $new_quantity = $old_quantity + $adjustment_value;
            
            // Validate resulting quantity is not negative
            if ($new_quantity < 0) {
                throw new Exception("Adjustment would result in negative inventory. Current: " . number_format($old_quantity, 2) . ", Adjustment: " . ($adjustment_value >= 0 ? '+' : '') . number_format($adjustment_value, 2));
            }
            
            $adjustment_type = $adjustment_value > 0 ? 'increase' : 'decrease';

            // Insert into adjustment_details
            $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
            $old_qty_str = (string)$old_quantity;
            $new_qty_str = (string)$new_quantity;
            if (!$stmt->execute([$product_id, $adjustment_id, $old_qty_str, $new_qty_str, $adjustment_type, $reason])) {
                throw new Exception("Failed to insert adjustment details");
            }

            // Update or insert stockin_inventory
            if ($current_quantity !== false) {
                // Get the Inventory_ID of the most recent record for this product
                $stmt = $conn->prepare("SELECT {$si_id_col} as inv_id FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$si_order_by} LIMIT 1");
                $stmt->execute([$product_id]);
                $inventory_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $inventory_id = $inventory_result !== false ? $inventory_result['inv_id'] : null;

                // Update the specific inventory record
                $where_id = $si_id_col;
                $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE {$where_id} = ?");
                $new_quantity_str = (string)$new_quantity;
                if (!$stmt->execute([$new_quantity_str, $inventory_id])) {
                    throw new Exception("Failed to update inventory");
                }
            } else {
                // If no inventory record exists, create one
                $stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, quantity, created_at, updated_at) VALUES (?, CURDATE(), ?, NOW(), NOW())");
                $new_quantity_str = (string)$new_quantity;
                if (!$stmt->execute([$product_id, $new_quantity_str])) {
                    throw new Exception("Failed to insert inventory");
                }
                $inventory_id = $conn->lastInsertId();
            }

            // AUTO-LOG TO DAMAGE_GOODS if reason matches
            $damage_reasons = getDamageTypeOptions();
            if (in_array($reason, $damage_reasons) && $adjustment_value < 0) {
                // Fixed: Added created_at, updated_at as required by some table schemas
                $stmt = $conn->prepare("INSERT INTO damage_goods (Inventory_ID, Adjustment_ID, quantity, reported_by, reason, damage_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([
                    $inventory_id,
                    $adjustment_id,
                    abs($adjustment_value),
                    $user_id,
                    $remarks !== '' ? $remarks : "Automated sync from Manual Adjustment",
                    $reason
                ]);
            }

            // LOG TO LEDGER
            $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'ADJUSTMENTS', ?, ?, ?, ?, ?)");
                $ledger_stmt->execute([
                $product_id,
                $adjustment_id,
                $adjustment_value,
                $new_quantity,
                $user_id,
                "Manual Adjustment: " . $reason . ($remarks !== '' ? " - $remarks" : '')
            ]);

            $conn->commit();

            // Log the activity
            logActivity('INVENTORY', "Manual adjustment for {$product_data['product_name']}: " . ($adjustment_value >= 0 ? '+' : '') . "$adjustment_value units ($reason" . ($remarks !== '' ? " - $remarks" : '') . ")", $adjustment_id);

            // Using session success instead of script echo to prevent resubmission loops
            $_SESSION['success_msg'] = "Manual adjustment recorded successfully.";
            header("Location: " . ($_POST['redirect_url'] ?? 'manual_adjustment.php'));
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['error_msg'] = "Error saving adjustment: " . $e->getMessage();
            header("Location: " . ($_POST['redirect_url'] ?? 'manual_adjustment.php'));
            exit;
        }
    } else {
        $_SESSION['error_msg'] = implode('\\n', $errors);
        header("Location: " . ($_POST['redirect_url'] ?? 'manual_adjustment.php'));
        exit;
    }
}
?>
