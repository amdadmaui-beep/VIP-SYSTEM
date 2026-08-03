<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/adjustment_reason_helper.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/damage_type_helper.php';
require_once __DIR__ . '/../includes/damage_photo_helper.php';
require_once __DIR__ . '/../includes/delivery_damage_ui_helper.php';
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
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $user_id = intval($_SESSION['user_id'] ?? 0);
    $redirect_url = $_POST['redirect_url'] ?? 'manual_adjustment.php';

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

    // Shared field validation
    $errors = [];
    $is_batch_mode = !empty($_POST['adjustments']) && is_array($_POST['adjustments']);

    if (!$is_batch_mode) {
        $valid_reasons = getAdjustmentReasonOptions($conn);
        $reason = normalizeAdjustmentReasonValue($conn, $reason);
        if (empty($valid_reasons)) {
            $errors[] = "Adjustment reasons are not configured in the database.";
        } elseif (empty($reason)) {
            $errors[] = "Reason is required.";
        } elseif (!in_array($reason, $valid_reasons, true)) {
            $errors[] = "Invalid reason selected ($reason). Please choose from the available options.";
        }

        if (isAdjustmentOtherReason($reason) && $remarks === '') {
            $errors[] = "Remarks are required when the reason is Other.";
        }
    }

    if (strlen($remarks) > 500) {
        $errors[] = "Remarks must not exceed 500 characters.";
    }

    if ($user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }

    // ──────────────────────────────────────
    // BATCH MODE (physical count from inventory staff)
    // ──────────────────────────────────────
    if ($is_batch_mode) {
        $adjustments_raw = $_POST['adjustments'];
        $current_qtys_raw = $_POST['current_qty'] ?? [];
        $reasons_raw = $_POST['adjustment_reason'] ?? [];
        $valid_adjustments = [];
        $changed_product_names = [];
        $batch_errors = [];

        $valid_reasons_list = getAdjustmentReasonOptions($conn);

        if (empty($valid_reasons_list)) {
            $batch_errors[] = "Adjustment reasons are not configured in the database.";
        }

        if (empty($batch_errors)) {
            foreach ($adjustments_raw as $pid => $actual_qty) {
                $pid = intval($pid);
                $actual_qty = floatval($actual_qty);
                $current_qty = isset($current_qtys_raw[$pid]) ? floatval($current_qtys_raw[$pid]) : 0;

                if ($actual_qty == $current_qty) continue;
                if ($actual_qty < 0) continue;

                $check_product = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
                $check_product->execute([$pid]);
                $product_data = $check_product->fetch(PDO::FETCH_ASSOC);
                if (!$product_data) continue;
                if ($product_data['is_discontinued'] == 1) continue;

                $adjustment_value = $actual_qty - $current_qty;
                if (abs($adjustment_value) > 999999) continue;

                $product_reason = isset($reasons_raw[$pid]) ? normalizeAdjustmentReasonValue($conn, trim($reasons_raw[$pid])) : '';
                if (empty($product_reason)) {
                    $batch_errors[] = "Reason is required for {$product_data['product_name']}.";
                    continue;
                }
                if (!in_array($product_reason, $valid_reasons_list, true)) {
                    $batch_errors[] = "Invalid reason for {$product_data['product_name']}.";
                    continue;
                }

                $valid_adjustments[] = [
                    'product_id' => $pid,
                    'product_name' => $product_data['product_name'],
                    'current_qty' => $current_qty,
                    'actual_qty' => $actual_qty,
                    'adjustment_value' => $adjustment_value,
                    'reason' => $product_reason,
                ];
                $changed_product_names[] = $product_data['product_name'];
            }

            if (empty($valid_adjustments)) {
                $batch_errors[] = "No products with quantity differences found. Change at least one actual quantity value.";
            }
        }

        if (!empty($batch_errors)) {
            $_SESSION['error_msg'] = implode('\\n', $batch_errors);
            header("Location: {$redirect_url}");
            exit;
        }

        $conn->beginTransaction();
        try {
            $tables = array_column($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
            $adj_table = in_array('manual_adjustment', $tables) ? 'manual_adjustment' : 'adjustments';
            $si_cols = $conn->query("SHOW COLUMNS FROM stockin_inventory")->fetchAll(PDO::FETCH_COLUMN);
            $si_id_col = in_array('Inventory_ID', $si_cols) ? 'Inventory_ID' : 'Inventory_ID';
            $si_time_cols_adj = [];
            if (in_array('updated_at', $si_cols)) $si_time_cols_adj[] = 'updated_at';
            if (in_array('created_at', $si_cols)) $si_time_cols_adj[] = 'created_at';
            if (in_array('date_in', $si_cols)) $si_time_cols_adj[] = 'date_in';
            $si_order_expr = !empty($si_time_cols_adj) ? ("COALESCE(" . implode(', ', $si_time_cols_adj) . ")") : $si_id_col;
            $si_order_by = "{$si_order_expr} DESC, {$si_id_col} DESC";
            $has_inventory_ledger = in_array('inventory_ledger', $tables);
            $damage_reasons = getDamageTypeOptions();

            $notes = $remarks !== '' ? $remarks : 'Physical count adjustment';
            $stmt = $conn->prepare("INSERT INTO {$adj_table} (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)");
            if (!$stmt->execute([$notes, $user_id])) {
                throw new Exception("Failed to insert adjustment header");
            }
            $adjustment_id = $conn->lastInsertId();
            $adjusted_count = 0;

            foreach ($valid_adjustments as $adj) {
                $pid = $adj['product_id'];
                $adjustment_value = $adj['adjustment_value'];
                $product_name = $adj['product_name'];
                $product_reason = $adj['reason'];

                $stmt = $conn->prepare("SELECT quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$si_order_by} LIMIT 1");
                $stmt->execute([$pid]);
                $db_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $old_quantity = $db_row !== false ? floatval($db_row['quantity']) : 0;
                $new_quantity = $old_quantity + $adjustment_value;

                if ($new_quantity < 0) {
                    throw new Exception("Adjustment for {$product_name} would result in negative inventory.");
                }

                $adjustment_type = $adjustment_value > 0 ? 'increase' : 'decrease';
                $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt->execute([$pid, $adjustment_id, (string)$old_quantity, (string)$new_quantity, $adjustment_type, $product_reason])) {
                    throw new Exception("Failed to insert adjustment details for #{$pid}");
                }

                if ($db_row !== false) {
                    $stmt = $conn->prepare("SELECT {$si_id_col} as inv_id FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$si_order_by} LIMIT 1");
                    $stmt->execute([$pid]);
                    $inv_result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $inventory_id = $inv_result !== false ? $inv_result['inv_id'] : null;
                    $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE {$si_id_col} = ?");
                    if (!$stmt->execute([(string)$new_quantity, $inventory_id])) {
                        throw new Exception("Failed to update inventory for #{$pid}");
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, quantity, created_at, updated_at) VALUES (?, CURDATE(), ?, NOW(), NOW())");
                    if (!$stmt->execute([$pid, (string)$new_quantity])) {
                        throw new Exception("Failed to insert inventory for #{$pid}");
                    }
                    $inventory_id = $conn->lastInsertId();
                }

                if ($has_inventory_ledger) {
                    $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'ADJUSTMENTS', ?, ?, ?, ?, ?)");
                    $ledger_stmt->execute([$pid, $adjustment_id, $adjustment_value, $new_quantity, $user_id, "Physical Count: {$product_reason}" . ($remarks !== '' ? " - {$remarks}" : '')]);
                }

                if (in_array($product_reason, $damage_reasons, true) && $adjustment_value < 0) {
                    $stmt = $conn->prepare("INSERT INTO damage_goods (Inventory_ID, Adjustment_ID, quantity, reported_by, reason, damage_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$inventory_id, $adjustment_id, abs($adjustment_value), $user_id, $remarks !== '' ? $remarks : "Automated sync from Manual Adjustment", $product_reason]);
                }

                $adjusted_count++;
            }

            $conn->commit();
            $name_list = implode(', ', array_slice($changed_product_names, 0, 5));
            if (count($changed_product_names) > 5) $name_list .= ' and ' . (count($changed_product_names) - 5) . ' more';
            $unique_reasons = array_unique(array_column($valid_adjustments, 'reason'));
            $reason_summary = implode(', ', $unique_reasons);
            logActivity('INVENTORY', "Physical count adjustment ({$adjusted_count} products): {$name_list} (Reasons: {$reason_summary})", $adjustment_id);
            $_SESSION['success_msg'] = "Physical count complete. {$adjusted_count} product(s) adjusted.";

            try {
                require_once __DIR__ . '/../includes/inventory_staff_chrome.php';
                notifyLowStockToStaff($conn);
            } catch (Throwable $e) {
                // Email failure should not break adjustment
            }

            header("Location: {$redirect_url}");
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['error_msg'] = "Error saving adjustments: " . $e->getMessage();
            header("Location: {$redirect_url}");
            exit;
        }
    }

    // ──────────────────────────────────────
    // SINGLE MODE (legacy — one product from owner/manager)
    // ──────────────────────────────────────
    $product_id = intval($_POST['product_id'] ?? 0);
    $adjustment_value = isset($_POST['adjustment_value']) ? floatval($_POST['adjustment_value']) : 0;
    $product_data = null;

    if (empty($errors)) {
        if ($product_id <= 0) {
            $errors[] = "Please select a valid product.";
        } else {
            $check_product = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
            $check_product->execute([$product_id]);
            $product_data = $check_product->fetch(PDO::FETCH_ASSOC);
            if (!$product_data) {
                $errors[] = "Selected product does not exist.";
            } elseif ($product_data['is_discontinued'] == 1) {
                $errors[] = "Cannot adjust inventory for discontinued products.";
            }
        }

        if ($adjustment_value == 0) {
            $errors[] = "Adjustment value cannot be zero.";
        } elseif (abs($adjustment_value) > 999999) {
            $errors[] = "Adjustment value is too large. Maximum allowed: 999,999.";
        }

        $damage_reasons = getDamageTypeOptions();
        if (in_array($reason, $damage_reasons, true) && $adjustment_value < 0) {
            if (empty($_FILES['damage_photo']) || empty($_FILES['damage_photo']['name'])) {
                $errors[] = "A damage photo is required for the selected reason.";
            }
        }
    }

    if (!empty($errors)) {
        $_SESSION['error_msg'] = implode('\\n', $errors);
        header("Location: {$redirect_url}");
        exit;
    }

    $conn->beginTransaction();
    try {
        $tables = array_column($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
        $adj_table = in_array('manual_adjustment', $tables) ? 'manual_adjustment' : 'adjustments';
        $notes = $remarks !== '' ? $remarks : 'Manual inventory adjustment';
        $stmt = $conn->prepare("INSERT INTO {$adj_table} (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)");
        if (!$stmt->execute([$notes, $user_id])) {
            throw new Exception("Failed to insert adjustment");
        }
        $adjustment_id = $conn->lastInsertId();

        $si_cols = $conn->query("SHOW COLUMNS FROM stockin_inventory")->fetchAll(PDO::FETCH_COLUMN);
        $si_id_col_legacy = in_array('Inventory_ID', $si_cols) ? 'Inventory_ID' : 'Inventory_ID';
        $si_time_cols_legacy = [];
        if (in_array('updated_at', $si_cols)) $si_time_cols_legacy[] = 'updated_at';
        if (in_array('created_at', $si_cols)) $si_time_cols_legacy[] = 'created_at';
        if (in_array('date_in', $si_cols)) $si_time_cols_legacy[] = 'date_in';
        $si_order_expr_legacy = !empty($si_time_cols_legacy) ? ("COALESCE(" . implode(', ', $si_time_cols_legacy) . ")") : $si_id_col_legacy;
        $si_order_by_legacy = "{$si_order_expr_legacy} DESC, {$si_id_col_legacy} DESC";

        $stmt = $conn->prepare("SELECT quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$si_order_by_legacy} LIMIT 1");
        $stmt->execute([$product_id]);
        $current_quantity_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $has_current = $current_quantity_row !== false;
        $old_quantity = $has_current ? floatval($current_quantity_row['quantity']) : 0;
        $new_quantity = $old_quantity + $adjustment_value;

        if ($new_quantity < 0) {
            throw new Exception("Adjustment would result in negative inventory.");
        }

        $adjustment_type = $adjustment_value > 0 ? 'increase' : 'decrease';
        $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt->execute([$product_id, $adjustment_id, (string)$old_quantity, (string)$new_quantity, $adjustment_type, $reason])) {
            throw new Exception("Failed to insert adjustment details");
        }

        if ($has_current) {
            $stmt = $conn->prepare("SELECT {$si_id_col_legacy} as inv_id FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$si_order_by_legacy} LIMIT 1");
            $stmt->execute([$product_id]);
            $inv_result = $stmt->fetch(PDO::FETCH_ASSOC);
            $inventory_id = $inv_result ? $inv_result['inv_id'] : null;
            $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE {$si_id_col_legacy} = ?");
            if (!$stmt->execute([(string)$new_quantity, $inventory_id])) {
                throw new Exception("Failed to update inventory");
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, quantity, created_at, updated_at) VALUES (?, CURDATE(), ?, NOW(), NOW())");
            if (!$stmt->execute([$product_id, (string)$new_quantity])) {
                throw new Exception("Failed to insert inventory");
            }
            $inventory_id = $conn->lastInsertId();
        }

        $damage_reasons = getDamageTypeOptions();
        if (in_array($reason, $damage_reasons, true) && $adjustment_value < 0) {
            $stmt = $conn->prepare("INSERT INTO damage_goods (Inventory_ID, Adjustment_ID, quantity, reported_by, reason, damage_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$inventory_id, $adjustment_id, abs($adjustment_value), $user_id, $remarks !== '' ? $remarks : "Automated sync from Manual Adjustment", $reason]);
            $damage_id = (int) $conn->lastInsertId();
            if ($damage_id > 0 && damageGoodsHasPhotoColumn($conn)) {
                $photoPath = saveSingleDamagePhotoFromRequest('damage_photo', 'dgr_' . $damage_id . '_');
                if ($photoPath !== null) {
                    $photoUp = $conn->prepare('UPDATE damage_goods SET photo_path = ? WHERE Damage_ID = ?');
                    $photoUp->execute([$photoPath, $damage_id]);
                }
            }
        }

        if (in_array('inventory_ledger', $tables)) {
            $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'ADJUSTMENTS', ?, ?, ?, ?, ?)");
            $ledger_stmt->execute([$product_id, $adjustment_id, $adjustment_value, $new_quantity, $user_id, "Manual Adjustment: " . $reason . ($remarks !== '' ? " - $remarks" : '')]);
        }

        $conn->commit();
        logActivity('INVENTORY', "Manual adjustment for {$product_data['product_name']}: " . ($adjustment_value >= 0 ? '+' : '') . "$adjustment_value units ($reason" . ($remarks !== '' ? " - $remarks" : '') . ")", $adjustment_id);
        $_SESSION['success_msg'] = "Manual adjustment recorded successfully.";
        header("Location: {$redirect_url}");
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_msg'] = "Error saving adjustment: " . $e->getMessage();
        header("Location: {$redirect_url}");
        exit;
    }
}
?>
