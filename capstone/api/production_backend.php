<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/module_access.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/roles_helper.php';
$allowed = array_unique(array_merge(getDashboardRoleIds($conn), getInventoryStaffRoleIds($conn)));
requireRole(empty($allowed) ? [1] : $allowed);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken(false)) {
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => ['Invalid or expired security token. Please refresh the page and try again.']]);
            exit();
        }
        header("Location: ../pages/production.php?error=" . urlencode('Invalid or expired security token. Please refresh the page and try again.'));
        exit();
    }

    if (!isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_record_production', true)) {
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => ['You are restricted from recording production.']]);
            exit();
        }
        header("Location: ../pages/production.php?error=" . urlencode('You are restricted from recording production.'));
        exit();
    }
    try {
        // Detect if this request comes from an AJAX caller (e.g. inventory modal)
        $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
        $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : null;

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

    // Comprehensive validation
    $errors = [];
    
    // Product validation
    if (empty($product_id) || $product_id <= 0) {
        $errors[] = "Product is required.";
    } else {
        // Verify product exists and is not discontinued
        $product_check = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
        $product_check->execute([$product_id]);
        $product_data = $product_check->fetch(PDO::FETCH_ASSOC);
        if (!$product_data) {
            $errors[] = "Selected product does not exist.";
        } elseif ($product_data['is_discontinued'] == 1) {
            $errors[] = "Cannot record production for discontinued products.";
        }
    }
    
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
        if ($produced_qty <= 0) {
            $errors[] = "Produced quantity must be greater than 0.";
        }
        if ($produced_qty > 999999) {
            $errors[] = "Produced quantity exceeds maximum allowed (999,999).";
        }
    }
    
    // Validate bag_size if provided
    if (!empty($bag_size) && ($bag_size <= 0 || $bag_size > 1000)) {
        $errors[] = "Bag size must be between 0.01 and 1000.";
    }
    
    // Validate number_of_bags if provided
    if (!empty($number_of_bags) && ($number_of_bags <= 0 || $number_of_bags > 10000)) {
        $errors[] = "Number of bags must be between 1 and 10,000.";
    }
    
    // Validate production_date
    if (empty($production_date)) {
        $errors[] = "Production date is required.";
    } else {
        // Validate date format
        $date_parts = explode('-', $production_date);
        if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
            $errors[] = "Invalid production date format.";
        } else {
            // Check if date is not in the future
            $prod_date = strtotime($production_date);
            $today = strtotime(date('Y-m-d'));
            if ($prod_date > $today) {
                $errors[] = "Production date cannot be in the future.";
            }
        }
    }
    
    // Validate production_type
    $valid_production_types = ['orders', 'stockin', 'regular'];
    if (empty($production_type)) {
        $errors[] = "Production type is required.";
    } elseif (!in_array($production_type, $valid_production_types)) {
        $errors[] = "Invalid production type. Must be one of: " . implode(', ', $valid_production_types);
    }
    
    // Validate user_id
    if (empty($created_by) || $created_by <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    } else {
        $user_check = $conn->prepare("SELECT User_ID FROM user WHERE User_ID = ?");
        $user_check->execute([$created_by]);
        if (!$user_check->fetch()) {
            $errors[] = "Invalid user session. Please log in again.";
        }
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

    if (empty($errors)) {
        // Insert directly into stockin_inventory, getting both the log characteristics and updating the balance
        $check_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY created_at DESC LIMIT 1");
        $check_stmt->execute([$product_id]);
        $row = $check_stmt->fetch();
        
        $old_quantity = $row ? floatval($row['quantity']) : 0;
        $new_quantity = $old_quantity + $produced_qty_kg;

        $sql = "INSERT INTO stockin_inventory (Product_ID, production_type, bag_size, date_in, handled_by, quantity, storage_limit) 
                VALUES (?, ?, ?, ?, ?, ?, 1000)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute([$product_id, $production_type, $bag_size, $production_date, $created_by, $new_quantity])) {
            $production_id = $conn->lastInsertId();

            // LOG TO LEDGER
            $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'STOCK IN', ?, ?, ?, ?, ?)");
            $ledger_stmt->execute([
                $product_id, 
                $production_id, 
                $produced_qty_kg, 
                $new_quantity, 
                $created_by, 
                "Recorded Production Type: " . $production_type
            ]);
            cacheInvalidateTable('stockin_inventory');
            cacheInvalidateTable('inventory_ledger');

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Production recorded successfully.'
                ]);
                exit();
            } else {
                if ($redirect === 'inventory') {
                    header("Location: ../pages/inventory.php?success=1");
                } else {
                    header("Location: ../pages/production.php?success=1");
                }
                exit();
            }
        } else {
            $errors[] = "Error recording production.";
        }
    }

    // If this is an AJAX request and we have errors, return them as JSON
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'errors' => $errors
                ]);
                exit();
            } else {
                $_SESSION['production_errors'] = $errors;
                header("Location: ../pages/production.php?error=" . urlencode(implode(', ', $errors)));
                exit();
            }
    } catch (Throwable $e) {
        if (!empty($isAjax)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'errors' => ['Server error: ' . $e->getMessage()]
            ]);
            exit();
        }
        $_SESSION['production_errors'] = ['Server error: ' . $e->getMessage()];
        header("Location: ../pages/production.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}
?>
