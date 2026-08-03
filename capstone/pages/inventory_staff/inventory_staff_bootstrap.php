<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/delivery_damage_ui_helper.php';
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/inventory_staff_chrome.php';
require_once __DIR__ . '/../../includes/adjustment_reason_helper.php';
require_once __DIR__ . '/../../includes/damage_type_helper.php';
require_once __DIR__ . '/../../includes/stock_reservation_helper.php';
require_once __DIR__ . '/../../includes/rider_availability_helper.php';

$staff_ids = getInventoryStaffRoleIds($conn);
$allowed_roles = array_unique(array_merge([1, 2, 4], $staff_ids));
requireRole($allowed_roles);

$is_inventory_staff = in_array((int)($_SESSION['user_role'] ?? 0), $staff_ids);

// Schema compatibility flags: some deployments only have stockin_inventory.
$has_productions_table = (bool) $conn->query("SHOW TABLES LIKE 'productions'")->fetchColumn();
$stockin_cols = [];
$stockin_cols_stmt = $conn->query("SHOW COLUMNS FROM stockin_inventory");
if ($stockin_cols_stmt) {
    while ($c = $stockin_cols_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stockin_cols[$c['Field']] = true;
    }
}
$stockin_has_production_id = isset($stockin_cols['Production_ID']);
$stockin_has_produced_qty = isset($stockin_cols['produced_qty']);
$stockin_has_number_of_bags = isset($stockin_cols['number_of_bags']);
$stockin_has_production_type = isset($stockin_cols['production_type']);
$stockin_has_storage_limit = isset($stockin_cols['storage_limit']);
$has_inventory_ledger_table = (bool) $conn->query("SHOW TABLES LIKE 'inventory_ledger'")->fetchColumn();

$stockin_time_cols = [];
if (isset($stockin_cols['updated_at'])) $stockin_time_cols[] = 'updated_at';
if (isset($stockin_cols['created_at'])) $stockin_time_cols[] = 'created_at';
if (isset($stockin_cols['date_in'])) $stockin_time_cols[] = 'date_in';
$stockin_order_expr = !empty($stockin_time_cols) ? ("COALESCE(" . implode(', ', $stockin_time_cols) . ")") : 'Inventory_ID';
$stockin_history_date_expr = isset($stockin_cols['date_in'])
    ? (isset($stockin_cols['created_at']) ? 'COALESCE(si.date_in, DATE(si.created_at))' : 'si.date_in')
    : (isset($stockin_cols['created_at']) ? 'DATE(si.created_at)' : 'CURDATE()');
$products_cols = [];
$products_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
if ($products_cols_stmt) {
    while ($c = $products_cols_stmt->fetch(PDO::FETCH_ASSOC)) {
        $products_cols[$c['Field']] = true;
    }
}
$has_units_table = (bool) $conn->query("SHOW TABLES LIKE 'units'")->fetchColumn();
$product_unit_select = "'-' AS unit_name";
$product_unit_join = '';
if ($has_units_table && isset($products_cols['unit_id'])) {
    $product_unit_select = "COALESCE(u.unit_name, '-') AS unit_name";
    $product_unit_join = "LEFT JOIN units u ON p.unit_id = u.unit_id";
} elseif (isset($products_cols['unit'])) {
    $product_unit_select = "COALESCE(p.unit, '-') AS unit_name";
}
$product_wholesale_select = isset($products_cols['wholesale_price']) ? 'p.wholesale_price' : '0 AS wholesale_price';
$product_retail_select = isset($products_cols['retail_price']) ? 'p.retail_price' : '0 AS retail_price';
$product_active_where = isset($products_cols['is_discontinued']) ? 'WHERE p.is_discontinued = 0' : '';
$product_discontinued_select = isset($products_cols['is_discontinued']) ? 'is_discontinued' : '0 AS is_discontinued';
$stockin_last_updated_select = isset($stockin_cols['updated_at'])
    ? 'updated_at'
    : (isset($stockin_cols['created_at']) ? 'created_at' : (isset($stockin_cols['date_in']) ? 'date_in' : 'NULL'));
$stockin_last_updated_select_single = isset($stockin_cols['updated_at'])
    ? 'latest.updated_at'
    : (isset($stockin_cols['created_at']) ? 'latest.created_at' : (isset($stockin_cols['date_in']) ? 'latest.date_in' : 'NULL'));
$product_created_fallback = isset($products_cols['created_at']) ? 'p.created_at' : 'NULL';

if (!$is_inventory_staff) {
    header('Location: inventory.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['production_type']) && $_POST['production_type'] === 'stockin') {
    if (!validateCsrfToken(false)) {
        $_SESSION['inv_staff_flash_error'] = 'Invalid or expired security token. Please refresh the page and try again.';
        header('Location: inventory_staff.php');
        exit;
    }

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $number_of_bags = isset($_POST['number_of_bags']) ? (int) $_POST['number_of_bags'] : 0;
    $production_date = !empty($_POST['production_date']) ? $_POST['production_date'] : null;
    $created_by = $_SESSION['user_id'] ?? 1;
    $production_errors = [];

    if ($product_id <= 0) $production_errors[] = 'Product is required.';
    if ($number_of_bags <= 0) $production_errors[] = 'Number of packs must be greater than 0.';
    if (empty($production_date)) {
        $production_errors[] = 'Stock in date is required.';
    } else {
        $date_parts = explode('-', $production_date);
        if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            $production_errors[] = 'Invalid stock in date.';
        } elseif (strtotime($production_date) > strtotime(date('Y-m-d'))) {
            $production_errors[] = 'Stock in date cannot be in the future.';
        }
    }

    if (empty($production_errors)) {
        try {
            $product_stmt = $conn->prepare("SELECT Product_ID, product_name, {$product_discontinued_select} FROM products WHERE Product_ID = ?");
            $product_stmt->execute([$product_id]);
            $product_row = $product_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product_row) {
                $production_errors[] = 'Selected product does not exist.';
            } elseif ((int)($product_row['is_discontinued'] ?? 0) === 1) {
                $production_errors[] = 'Cannot stock in a discontinued product.';
            }

            if (!empty($production_errors)) {
                $_SESSION['error_msg'] = implode("\n", $production_errors);
                header('Location: inventory_staff.php');
                exit;
            }

            $conn->beginTransaction();
            $production_id = null;

            // Keep the legacy table in sync only when it exists.
            if ($has_productions_table) {
                $stmt = $conn->prepare("INSERT INTO productions (Product_ID, production_type, produced_qty, production_date, created_by, Order_ID, bag_size, number_of_bags) VALUES (?, 'stockin', ?, ?, ?, NULL, NULL, ?)");
                if ($stmt->execute([$product_id, $number_of_bags, $production_date, $created_by, $number_of_bags])) {
                    $production_id = (int)$conn->lastInsertId();
                }
            }

            $check_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$stockin_order_expr} DESC, Inventory_ID DESC LIMIT 1");
            $check_stmt->execute([$product_id]);
            $inv_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

            $old_quantity = $inv_row ? (float)$inv_row['quantity'] : 0;
            $new_quantity = $old_quantity + $number_of_bags;

            $insert_cols = ['Product_ID', 'date_in', 'handled_by', 'quantity'];
            $insert_vals = ['?', '?', '?', '?'];
            $insert_params = [$product_id, $production_date, $created_by, $new_quantity];

            if ($stockin_has_production_id) {
                $insert_cols[] = 'Production_ID';
                $insert_vals[] = '?';
                $insert_params[] = $production_id;
            }
            if ($stockin_has_production_type) {
                $insert_cols[] = 'production_type';
                $insert_vals[] = '?';
                $insert_params[] = 'stockin';
            }
            if ($stockin_has_produced_qty) {
                $insert_cols[] = 'produced_qty';
                $insert_vals[] = '?';
                $insert_params[] = $number_of_bags;
            }
            if ($stockin_has_number_of_bags) {
                $insert_cols[] = 'number_of_bags';
                $insert_vals[] = '?';
                $insert_params[] = $number_of_bags;
            }
            if ($stockin_has_storage_limit) {
                $insert_cols[] = 'storage_limit';
                $insert_vals[] = '?';
                $insert_params[] = 1000;
            }

            $insert_inv_stmt = $conn->prepare(
                "INSERT INTO stockin_inventory (" . implode(', ', $insert_cols) . ") VALUES (" . implode(', ', $insert_vals) . ")"
            );
            $insert_inv_stmt->execute($insert_params);
            $stockin_id = (int)$conn->lastInsertId();

            if ($has_inventory_ledger_table) {
                $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'STOCK IN', ?, ?, ?, ?, ?)");
                $ledger_stmt->execute([
                    $product_id,
                    $stockin_id,
                    $number_of_bags,
                    $new_quantity,
                    $created_by,
                    'Manual stock in entry'
                ]);
            }

            $conn->commit();

            logActivity('INVENTORY', "Stock in recorded: +{$number_of_bags} units for {$product_row['product_name']}", $stockin_id);
            $_SESSION['success_msg'] = 'Stock In recorded successfully.';

            // Send low stock alert if products are below threshold (silent on failure)
            try {
                require_once __DIR__ . '/../../includes/inventory_staff_chrome.php';
                notifyLowStockToStaff($conn);
            } catch (Throwable $e) {
                // Email failure should not break stock-in
            }
            $_SESSION['success_details'] = json_encode([
                'product_name' => $product_row['product_name'],
                'quantity' => $number_of_bags,
                'balance' => $new_quantity,
            ]);
            header('Location: inventory_staff.php');
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['error_msg'] = 'Error recording stock in: ' . $e->getMessage();
            header('Location: inventory_staff.php');
            exit;
        }
    }

    $_SESSION['error_msg'] = implode("\n", $production_errors);
    header('Location: inventory_staff.php');
    exit;
}

// ──────────────────────────────────────
// Batch Stock In Handler (tabular mode)
// ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_stockin']) && $_POST['batch_stockin'] === '1') {
    if (!validateCsrfToken(false)) {
        $_SESSION['inv_staff_flash_error'] = 'Invalid or expired security token. Please refresh and try again.';
        header('Location: inventory_staff.php');
        exit;
    }

    $stockin_date = !empty($_POST['batch_stockin_date']) ? $_POST['batch_stockin_date'] : null;
    $created_by = $_SESSION['user_id'] ?? 1;
    $errors = [];

    if (empty($stockin_date)) {
        $errors[] = 'Stock in date is required.';
    } else {
        $date_parts = explode('-', $stockin_date);
        if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            $errors[] = 'Invalid stock in date.';
        } elseif (strtotime($stockin_date) > strtotime(date('Y-m-d'))) {
            $errors[] = 'Stock in date cannot be in the future.';
        }
    }

    if (!empty($errors)) {
        $_SESSION['error_msg'] = implode("\n", $errors);
        header('Location: inventory_staff.php');
        exit;
    }

    $stockin_qty = $_POST['stockin_qty'] ?? [];
    if (empty($stockin_qty) || !is_array($stockin_qty)) {
        $_SESSION['error_msg'] = 'No stock in quantities provided.';
        header('Location: inventory_staff.php');
        exit;
    }

    // Filter out zero/empty values
    $to_process = [];
    foreach ($stockin_qty as $pid => $qty) {
        $qty_val = (int)$qty;
        if ($qty_val > 0) {
            $to_process[(int)$pid] = $qty_val;
        }
    }

    if (empty($to_process)) {
        $_SESSION['error_msg'] = 'Please enter at least one product with a quantity greater than 0.';
        header('Location: inventory_staff.php');
        exit;
    }

    $processed_count = 0;
    $processed_names = [];
    $total_qty = 0;

    try {
        $conn->beginTransaction();

        // Prepare all statements once outside the loop
        $product_info_stmt = $conn->prepare("SELECT Product_ID, product_name, {$product_discontinued_select} FROM products WHERE Product_ID = ?");
        $check_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY {$stockin_order_expr} DESC, Inventory_ID DESC LIMIT 1");

        $prod_insert_stmt = null;
        if ($has_productions_table) {
            $prod_insert_stmt = $conn->prepare("INSERT INTO productions (Product_ID, production_type, produced_qty, production_date, created_by, Order_ID, bag_size, number_of_bags) VALUES (?, 'stockin', ?, ?, ?, NULL, NULL, ?)");
        }

        // Build stockin_inventory INSERT once (columns don't change per product)
        $si_cols = ['Product_ID', 'date_in', 'handled_by', 'quantity'];
        $si_vals = ['?', '?', '?', '?'];
        $si_param_builders = [];
        $col_idx = 4;
        if ($stockin_has_production_id) {
            $si_cols[] = 'Production_ID'; $si_vals[] = '?';
            $si_param_builders[] = static fn(&$params, $pid, $production_id, $qty_to_add, $stockin_date, $created_by, $new_quantity) => $params[] = $production_id;
        }
        if ($stockin_has_production_type) {
            $si_cols[] = 'production_type'; $si_vals[] = '?';
            $si_param_builders[] = static fn(&$params, $pid, $production_id, $qty_to_add, $stockin_date, $created_by, $new_quantity) => $params[] = 'stockin';
        }
        if ($stockin_has_produced_qty) {
            $si_cols[] = 'produced_qty'; $si_vals[] = '?';
            $si_param_builders[] = static fn(&$params, $pid, $production_id, $qty_to_add, $stockin_date, $created_by, $new_quantity) => $params[] = $qty_to_add;
        }
        if ($stockin_has_number_of_bags) {
            $si_cols[] = 'number_of_bags'; $si_vals[] = '?';
            $si_param_builders[] = static fn(&$params, $pid, $production_id, $qty_to_add, $stockin_date, $created_by, $new_quantity) => $params[] = $qty_to_add;
        }
        if ($stockin_has_storage_limit) {
            $si_cols[] = 'storage_limit'; $si_vals[] = '?';
            $si_param_builders[] = static fn(&$params, $pid, $production_id, $qty_to_add, $stockin_date, $created_by, $new_quantity) => $params[] = 1000;
        }
        $insert_inv_stmt = $conn->prepare("INSERT INTO stockin_inventory (" . implode(', ', $si_cols) . ") VALUES (" . implode(', ', $si_vals) . ")");

        $ledger_stmt = null;
        if ($has_inventory_ledger_table) {
            $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'STOCK IN', ?, ?, ?, ?, ?)");
        }

        foreach ($to_process as $pid => $qty_to_add) {
            $product_info_stmt->execute([$pid]);
            $product_row = $product_info_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product_row) continue;
            if ((int)($product_row['is_discontinued'] ?? 0) === 1) continue;

            $production_id = null;
            if ($prod_insert_stmt) {
                if ($prod_insert_stmt->execute([$pid, $qty_to_add, $stockin_date, $created_by, $qty_to_add])) {
                    $production_id = (int)$conn->lastInsertId();
                }
            }

            $check_stmt->execute([$pid]);
            $inv_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
            $old_quantity = $inv_row ? (float)$inv_row['quantity'] : 0;
            $new_quantity = $old_quantity + $qty_to_add;

            $insert_params = [$pid, $stockin_date, $created_by, $new_quantity];
            foreach ($si_param_builders as $builder) {
                $builder($insert_params, $pid, $production_id, $qty_to_add, $stockin_date, $created_by, $new_quantity);
            }
            $insert_inv_stmt->execute($insert_params);
            $stockin_id = (int)$conn->lastInsertId();

            if ($ledger_stmt) {
                $ledger_stmt->execute([$pid, $stockin_id, $qty_to_add, $new_quantity, $created_by, 'Batch stock in entry']);
            }

            $processed_count++;
            $processed_names[] = $product_row['product_name'];
            $total_qty += $qty_to_add;
        }

        $conn->commit();

        $summary = implode(', ', array_slice($processed_names, 0, 5));
        if (count($processed_names) > 5) $summary .= ' and ' . (count($processed_names) - 5) . ' more';
        logActivity('INVENTORY', "Batch stock in: +{$total_qty} units across {$processed_count} products ({$summary})");

        $_SESSION['success_msg'] = "Stock in recorded for {$processed_count} product(s) (+{$total_qty} units).";

        // Send low stock alert silently - never break stock-in on email failure
        try {
            require_once __DIR__ . '/../../includes/inventory_staff_chrome.php';
            notifyLowStockToStaff($conn);
        } catch (Throwable $e) {
            // Email failure should not break stock-in
        }

        header('Location: inventory_staff.php');
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_msg'] = 'Error recording batch stock in: ' . $e->getMessage();
        header('Location: inventory_staff.php');
        exit;
    }
}

// Data Queries
$query = "SELECT 
    p.Product_ID, p.product_name, {$product_unit_select}, {$product_wholesale_select}, {$product_retail_select},
    COALESCE(latest.quantity, 0) AS current_quantity,
    COALESCE(latest.storage_limit, 100) AS storage_limit,
    COALESCE({$stockin_last_updated_select_single}, {$product_created_fallback}) AS last_updated
FROM products p {$product_unit_join}
LEFT JOIN (
    SELECT si.*
    FROM stockin_inventory si
    INNER JOIN (
        SELECT Product_ID, MAX(Inventory_ID) AS max_id
        FROM stockin_inventory
        GROUP BY Product_ID
    ) grouped ON si.Inventory_ID = grouped.max_id
) latest ON p.Product_ID = latest.Product_ID
{$product_active_where} ORDER BY p.product_name";
$products = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

$productIds = array_values(array_filter(array_map(static fn ($r) => (int)($r['Product_ID'] ?? 0), $products), static fn ($id) => $id > 0));
$reservedMap = !empty($productIds) ? getReservedStockByProductIds($conn, $productIds) : [];

// Detect order status column name (schema drift workaround)
$orderStatusCol = 'order_status';
$oscStmt = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
if ($oscStmt && $oscStmt->rowCount() > 0) {
    $oscRow = $oscStmt->fetch(PDO::FETCH_ASSOC);
    $orderStatusCol = (string)($oscRow['Field'] ?? 'order_status');
}

// Build reservation details map (which specific orders are holding each product)
$reservationDetailsMap = [];
if (!empty($productIds)) {
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $detailsSql = "
        SELECT
            od.Product_ID,
            od.Order_ID,
            od.ordered_qty,
            LOWER(COALESCE(o.{$orderStatusCol}, '')) AS order_status_norm,
            d.Delivery_ID,
            LOWER(COALESCE(d.delivery_status, '')) AS delivery_status_norm,
            COALESCE(c.customer_name, '') AS customer_name
        FROM order_details od
        INNER JOIN orders o ON o.Order_ID = od.Order_ID
        LEFT JOIN customers c ON c.Customer_ID = o.Customer_ID
        LEFT JOIN (
            SELECT d1.Delivery_ID, d1.Order_ID, d1.delivery_status
            FROM delivery d1
            INNER JOIN (
                SELECT Order_ID, MAX(Delivery_ID) AS last_delivery_id
                FROM delivery
                GROUP BY Order_ID
            ) latest ON latest.last_delivery_id = d1.Delivery_ID
        ) d ON d.Order_ID = o.Order_ID
        WHERE od.Product_ID IN ($in)
          AND LOWER(COALESCE(o.{$orderStatusCol}, '')) IN (
            'pending','requested','confirmed','scheduled','scheduled for delivery',
            'preparing','ready','ready_for_pickup','ready_to_pickup',
            'out for delivery','out_for_delivery','in transit','in_transit'
          )
          AND (
            d.delivery_status IS NULL
            OR LOWER(d.delivery_status) NOT IN ('cancelled', 'completed', 'delivered', 'remitted')
          )
        ORDER BY od.Product_ID, od.Order_ID
    ";
    $detailsStmt = $conn->prepare($detailsSql);
    $detailsStmt->execute($productIds);
    while ($hold = $detailsStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($hold['Product_ID'] ?? 0);
        if ($pid <= 0) continue;
        if (!isset($reservationDetailsMap[$pid])) {
            $reservationDetailsMap[$pid] = [];
        }
        $reservationDetailsMap[$pid][] = [
            'order_id'        => (int)($hold['Order_ID'] ?? 0),
            'ordered_qty'     => (float)($hold['ordered_qty'] ?? 0),
            'order_status'    => (string)($hold['order_status_norm'] ?? ''),
            'delivery_status' => (string)($hold['delivery_status_norm'] ?? ''),
            'customer_name'   => (string)($hold['customer_name'] ?? ''),
        ];
    }
}

// Fetch orders needing attention (ordered qty > available stock)
$needsAttentionOrders = [];
try {
    $naSql = "
        SELECT
            od.Order_ID,
            od.Product_ID,
            od.ordered_qty,
            COALESCE(c.customer_name, o.customer_name_snapshot, 'Unknown') AS customer_name,
            COALESCE(p.product_name, od.product_name_snapshot, 'Unknown') AS product_name,
            COALESCE(
                (SELECT quantity FROM stockin_inventory si
                 WHERE si.Product_ID = od.Product_ID
                 ORDER BY COALESCE(si.updated_at, si.created_at, si.date_in) DESC, si.Inventory_ID DESC
                 LIMIT 1),
                0
            ) AS available_stock
        FROM order_details od
        INNER JOIN orders o ON o.Order_ID = od.Order_ID
        LEFT JOIN customers c ON c.Customer_ID = o.Customer_ID
        LEFT JOIN products p ON p.Product_ID = od.Product_ID
        LEFT JOIN (
            SELECT d1.Order_ID, d1.delivery_status
            FROM delivery d1
            INNER JOIN (
                SELECT Order_ID, MAX(Delivery_ID) AS last_delivery_id
                FROM delivery
                GROUP BY Order_ID
            ) ld ON ld.last_delivery_id = d1.Delivery_ID
        ) d ON d.Order_ID = o.Order_ID
        WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) IN (
            'pending','requested','confirmed','scheduled','scheduled for delivery',
            'preparing','ready','ready_for_pickup','ready_to_pickup'
        )
        AND (
            d.delivery_status IS NULL
            OR LOWER(d.delivery_status) NOT IN ('cancelled', 'completed', 'delivered', 'remitted')
        )
        HAVING ordered_qty > available_stock
        ORDER BY (ordered_qty - available_stock) DESC, od.Order_ID
    ";
    $naStmt = $conn->query($naSql);
    if ($naStmt) {
        $needsAttentionOrders = $naStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $needsAttentionOrders = [];
}

$history_qty_expr = 'COALESCE(si.quantity, 0)';
if ($stockin_has_produced_qty && $stockin_has_number_of_bags) {
    $history_qty_expr = 'COALESCE(si.produced_qty, si.number_of_bags, si.quantity, 0)';
} elseif ($stockin_has_produced_qty) {
    $history_qty_expr = 'COALESCE(si.produced_qty, si.quantity, 0)';
} elseif ($stockin_has_number_of_bags) {
    $history_qty_expr = 'COALESCE(si.number_of_bags, si.quantity, 0)';
}

$history_filters = [];
if ($stockin_has_production_type) {
    $history_filters[] = "(si.production_type = 'stockin' OR si.production_type IS NULL)";
}
if ($stockin_has_produced_qty && $stockin_has_number_of_bags) {
    $history_filters[] = "(si.produced_qty IS NOT NULL OR si.number_of_bags IS NOT NULL)";
} elseif ($stockin_has_produced_qty) {
    $history_filters[] = "si.produced_qty IS NOT NULL";
} elseif ($stockin_has_number_of_bags) {
    $history_filters[] = "si.number_of_bags IS NOT NULL";
}
$history_where = empty($history_filters) ? '' : ('WHERE ' . implode(' AND ', $history_filters));

$history_query = "SELECT 
    si.Inventory_ID AS Production_ID,
    {$stockin_history_date_expr} AS production_date,
    il.quantity_change AS produced_qty,
    pr.product_name,
    u.user_name as handled_by
FROM stockin_inventory si
INNER JOIN products pr ON si.Product_ID = pr.Product_ID
LEFT JOIN user u ON si.handled_by = u.User_ID
LEFT JOIN inventory_ledger il ON si.Inventory_ID = il.transaction_id AND il.transaction_type = 'STOCK IN'
ORDER BY production_date DESC, si.Inventory_ID DESC
LIMIT 50";
$production_history = $conn->query($history_query)->fetchAll(PDO::FETCH_ASSOC);

$adj_table = in_array('manual_adjustment', array_column($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0)) ? 'manual_adjustment' : 'adjustments';
$adj_history_query = "SELECT ma.adjustment_date, ma.notes, pr.product_name, ad.old_quantity, ad.new_quantity, ad.reason, appuser.user_name as handled_by 
FROM adjustment_details ad INNER JOIN {$adj_table} ma ON ad.Adjustment_ID = ma.Adjustment_ID INNER JOIN products pr ON ad.Product_ID = pr.Product_ID 
LEFT JOIN user appuser ON ma.created_by = appuser.User_ID ORDER BY ma.adjustment_date DESC, ma.Adjustment_ID DESC LIMIT 50";
$adjustment_history = $conn->query($adj_history_query)->fetchAll(PDO::FETCH_ASSOC);

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$user_info_stmt = $conn->prepare("SELECT u.full_name, r.role_name FROM user u LEFT JOIN roles r ON u.Role_ID = r.Role_ID WHERE u.User_ID = :uid");
$user_info_stmt->execute([':uid' => $current_user_id]);
$user_info = $user_info_stmt->fetch(PDO::FETCH_ASSOC);
$display_name = $user_info['full_name'] ?? $_SESSION['user_name'] ?? 'User';
$display_role = $user_info['role_name'] ?? 'Staff';

// Fetch profile picture
$profilePicture = '';
try {
    $stmt = $conn->prepare("SELECT profile_picture FROM User_Profile WHERE User_ID = ? LIMIT 1");
    $stmt->execute([$current_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['profile_picture'])) {
        $profilePicture = $row['profile_picture'];
    }
} catch (Throwable $e) {
    // Ignore errors, fallback to initial
}

$ddr_role_id = (int)($_SESSION['user_role'] ?? 0);
$ddr_queue_show = ddr_table_exists($conn) && userCanAccessDeliveryDamageQueue($conn, $ddr_role_id);
$ddr_pending_n = $ddr_queue_show ? countPendingDeliveryDamageReports($conn) : 0;
$ddr_nav_href = $ddr_queue_show ? 'inventory_staff_delivery_damage.php' : '';

// Fetch rider availability
$rider_ids = getRiderRoleIds($conn);
$rider_groups = ['Available' => [], 'On Delivery' => [], 'Off Duty' => []];
try {
    ensureRiderWorkflowSchema($conn);
    $ra_rows = getRiderAvailabilityRows($conn, $rider_ids);
    foreach ($ra_rows as $r) {
        $s = (string)($r['rider_availability_status'] ?? 'Available');
        if (isset($rider_groups[$s])) {
            $rider_groups[$s][] = $r;
        }
    }
} catch (Throwable $e) {
    $rider_groups = ['Available' => [], 'On Delivery' => [], 'Off Duty' => []];
}

// Calculate overdue orders count for notification badge
$overdue_orders_count = 0;
try {
    $orderColumns = [];
    $stmtCols = $conn->query("SHOW COLUMNS FROM orders");
    if ($stmtCols) {
        $orderColumns = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    }
    $hasDeliveryDate = in_array('delivery_date', $orderColumns, true);
    $hasScheduleDate = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'delivery'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $hasScheduleDate = true;
    }
    
    $deliveryDateSelect = $hasDeliveryDate ? 'o.delivery_date' : 'o.order_date';
    $scheduleDateJoin = "";
    $deliveryConditions = "";
    if ($hasScheduleDate) {
        $scheduleDateJoin = "LEFT JOIN delivery d ON d.Delivery_ID = (
            SELECT d2.Delivery_ID FROM delivery d2 WHERE d2.Order_ID = o.Order_ID ORDER BY d2.Delivery_ID DESC LIMIT 1
        )";
        $deliveryConditions = "AND COALESCE(d.delivery_status, 'Scheduled') = 'Scheduled'
            AND (
                d.Delivery_ID IS NOT NULL
                OR {$deliveryDateSelect} IS NOT NULL
                OR LOWER(COALESCE(o.{$orderStatusCol}, '')) IN ('scheduled for delivery', 'scheduled')
            )";
    }
    $scheduleDateCol = $hasScheduleDate ? "COALESCE(d.schedule_date, $deliveryDateSelect)" : $deliveryDateSelect;
    
    $countQuery = "
        SELECT COUNT(DISTINCT o.Order_ID)
        FROM orders o
        $scheduleDateJoin
        LEFT JOIN order_preparation_tasks opt ON o.Order_ID = opt.Order_ID
        WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
          $deliveryConditions
          AND $scheduleDateCol < CURRENT_DATE()
    ";
    $countStmt = $conn->query($countQuery);
    if ($countStmt) {
        $overdue_orders_count = (int)$countStmt->fetchColumn();
    }
} catch (Throwable $e) {}

$total_notifications_n = getUnreadNotificationsCount($conn);

$total_products = count($products);
$low_stock = 0; $out_of_stock = 0;
foreach ($products as $p) {
    if ((float)$p['current_quantity'] == 0) $out_of_stock++;
    elseif ((float)$p['current_quantity'] < 20) $low_stock++;
}

$reasons = getAdjustmentReasonOptions($conn);
$has_other_reason = in_array('Other (with remarks)', $reasons, true);
$damage_type_reasons = getDamageTypeOptions();

$inv_staff_flash_success = '';
$inv_staff_flash_success_details = null;
if (!empty($_SESSION['success_msg'])) {
    $inv_staff_flash_success = (string) $_SESSION['success_msg'];
    if (!empty($_SESSION['success_details'])) {
        $inv_staff_flash_success_details = json_decode((string)$_SESSION['success_details'], true);
        unset($_SESSION['success_details']);
    }
    unset($_SESSION['success_msg']);
}
$inv_staff_flash_error = '';
if (!empty($_SESSION['error_msg'])) {
    $inv_staff_flash_error = (string) $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}
