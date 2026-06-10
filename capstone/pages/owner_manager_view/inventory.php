<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/stock_reservation_helper.php';
require_once __DIR__ . '/../../includes/csrf.php';

// Accessible to Owner (1), Manager (2, 4), and Inventory Staff
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/delivery_damage_ui_helper.php';
$staff_ids = getInventoryStaffRoleIds($conn);
$allowed_roles = array_unique(array_merge([1, 2, 4], $staff_ids));
requireRole($allowed_roles);

$role_id = (int)($_SESSION['user_role'] ?? 0);
$can_manage_products = in_array($role_id, [1, 2, 4], true);
$can_manage_inventory_operations = ($role_id !== 1);

$is_inventory_staff = in_array($role_id, $staff_ids);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken(false)) {
    $_SESSION['inventory_production_errors'] = ['Invalid or expired security token. Please refresh the page and try again.'];
    header('Location: inventory.php');
    exit;
}

// Redirect inventory staff to their dedicated mobile-optimized page
if ($is_inventory_staff) {
    header('Location: inventory_staff.php');
    exit;
}

// Schema compatibility flags (some deployments use stockin_inventory only).
$has_productions_table = (bool) $conn->query("SHOW TABLES LIKE 'productions'")->fetchColumn();
$stockin_cols = [];
$stockin_cols_stmt = $conn->query("SHOW COLUMNS FROM stockin_inventory");
if ($stockin_cols_stmt) {
    while ($c = $stockin_cols_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stockin_cols[$c['Field']] = true;
    }
}
$stockin_has_production_id = isset($stockin_cols['Production_ID']);

// Handle product soft delete / restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_action']) && isset($_POST['product_id']) && !$is_inventory_staff) {
    $productId = (int) $_POST['product_id'];
    $action = $_POST['product_action'];
    if ($productId > 0 && in_array($action, ['deactivate', 'restore'], true)) {
        $isDiscontinued = $action === 'deactivate' ? 1 : 0;
        $stmt = $conn->prepare("UPDATE products SET is_discontinued = ? WHERE Product_ID = ?");
        if ($stmt->execute([$isDiscontinued, $productId])) {
            $_SESSION['inventory_product_success'] = $isDiscontinued ? 'Product has been set to inactive.' : 'Product has been restored.';
        }
    }
    header('Location: inventory.php');
    exit;
}

// Retrieve flash messages from session (set by PRG redirects above)
$inventory_product_success = $_SESSION['inventory_product_success'] ?? null;
unset($_SESSION['inventory_product_success']);

$inventory_production_success = $_SESSION['inventory_production_success'] ?? null;
unset($_SESSION['inventory_production_success']);

$inventory_production_errors = $_SESSION['inventory_production_errors'] ?? [];
unset($_SESSION['inventory_production_errors']);

// Handle "Add Production" submissions from the inventory modal (stockin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['production_type']) && $_POST['production_type'] === 'stockin') {
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $number_of_bags = isset($_POST['number_of_bags']) ? (int) $_POST['number_of_bags'] : 0;
    $production_date = !empty($_POST['production_date']) ? $_POST['production_date'] : null;
    $created_by = $_SESSION['user_id'] ?? 1;
    $production_errors_tmp = [];

    if ($product_id <= 0) {
        $production_errors_tmp[] = 'Product is required.';
    }
    if ($number_of_bags <= 0) {
        $production_errors_tmp[] = 'Number of packs must be greater than 0.';
    }
    if (empty($production_date)) {
        $production_errors_tmp[] = 'Production date is required.';
    }

    // For simple stockin from inventory: treat each pack as 1 kg equivalent
    $produced_qty = $number_of_bags;
    $produced_qty_kg = $produced_qty;

    if (empty($production_errors_tmp)) {
        $production_id = null;

        // Optional legacy write into productions table (if table exists in this deployment).
        if ($has_productions_table) {
            $stmt = $conn->prepare("INSERT INTO productions (Product_ID, production_type, produced_qty, production_date, created_by, Order_ID, bag_size, number_of_bags) VALUES (?, 'stockin', ?, ?, ?, NULL, NULL, ?)");
            if ($stmt->execute([$product_id, $produced_qty, $production_date, $created_by, $number_of_bags])) {
                $production_id = (int) $conn->lastInsertId();
            }
        }

        // Update or insert into stockin_inventory (primary inventory source).
        $check_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY created_at DESC LIMIT 1");
        $check_stmt->execute([$product_id]);
        $inv_row = $check_stmt->fetch();

        if ($inv_row) {
            $inv_id = $inv_row['Inventory_ID'];
            $new_quantity = $inv_row['quantity'] + $produced_qty_kg;
            $update_stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
            $update_stmt->execute([$new_quantity, $inv_id]);
        } else {
            $insert_inv_stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, handled_by, quantity, storage_limit) VALUES (?, ?, ?, ?, 1000)");
            $insert_inv_stmt->execute([$product_id, $production_date, $created_by, $produced_qty_kg]);
            $inv_id = $conn->lastInsertId();
        }

        // LOG TO LEDGER for consistent history
        $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'STOCK IN', ?, ?, ?, ?, 'Stock-in from Inventory Panel')");
        $ledger_stmt->execute([$product_id, $inv_id, $produced_qty_kg, ($inv_row ? $inv_row['quantity'] + $produced_qty_kg : $produced_qty_kg), $created_by]);

        $_SESSION['inventory_production_success'] = 'Stock added successfully.';
    } else {
        $_SESSION['inventory_production_errors'] = $production_errors_tmp;
    }

    header('Location: inventory.php');
    exit;
}

// Query to fetch inventory data with product details (include discontinued flag)
$query = "SELECT 
    p.Product_ID,
    p.product_name,
    u.unit_name,
    c.category_name,
    p.wholesale_price,
    p.retail_price,
    p.description,
    p.product_image,
    p.created_date,
    p.is_discontinued,
    (SELECT quantity FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1) as current_quantity,
    (SELECT storage_limit FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1) as storage_limit,
    (SELECT updated_at FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1) as inventory_updated_at
FROM products p
LEFT JOIN units u ON p.unit_id = u.unit_id
LEFT JOIN product_categories c ON p.category_id = c.category_id
ORDER BY p.created_date DESC";

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$count_query = "SELECT COUNT(*) as total FROM products p LEFT JOIN units u ON p.unit_id = u.unit_id";
$total = (int)$conn->query($count_query)->fetch()['total'];
$total_pages = ceil($total / $per_page);

$result = $conn->query($query . " LIMIT $per_page OFFSET $offset")->fetchAll();

// Compute reserved/sellable in bulk (query-time computed values).
$productIds = array_values(array_filter(array_map(static fn ($r) => (int)($r['Product_ID'] ?? 0), $result), static fn ($id) => $id > 0));
$reservedMap = !empty($productIds) ? getReservedStockByProductIds($conn, $productIds) : [];
$reservationDetailsMap = [];
if (!empty($productIds)) {
    $statusColumns = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    $orderStatusCol = 'order_status';
    if ($statusColumns && $statusColumns->rowCount() > 0) {
        $orderStatusCol = (string)($statusColumns->fetch(PDO::FETCH_ASSOC)['Field'] ?? 'order_status');
    }

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

// Calculate counts for stats
$active_count = 0;
foreach ($result as $row) {
    if (empty($row['is_discontinued'])) {
        $active_count++;
    }
}
$total_count = count($result); // All products in db

// Fetch products for production and adjustment modals
$products_query = "SELECT p.Product_ID, p.product_name, u.unit_name,
                   COALESCE((SELECT quantity FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1), 0) as current_quantity
                   FROM products p 
                   LEFT JOIN units u ON p.unit_id = u.unit_id 
                   WHERE p.is_discontinued = 0 
                   ORDER BY u.unit_name, p.product_name";
$products_result = $conn->query($products_query)->fetchAll();

// Handle Manual Adjustment submissions
$inventory_adjustment_success = null;
$inventory_adjustment_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjustment_action']) && $_POST['adjustment_action'] === 'manual_adjust') {
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $adjustment_value = isset($_POST['adjustment_value']) ? (float) $_POST['adjustment_value'] : 0;
    $reason = trim($_POST['reason'] ?? '');
    $user_id = $_SESSION['user_id'] ?? 1;

    if ($product_id <= 0) $inventory_adjustment_errors[] = 'Product is required.';
    if ($adjustment_value == 0) $inventory_adjustment_errors[] = 'Adjustment value cannot be zero.';
    if (empty($reason)) $inventory_adjustment_errors[] = 'Reason is required.';

    if (empty($inventory_adjustment_errors)) {
        $conn->beginTransaction();
        try {
            // Log the adjustment event
            $stmt = $conn->prepare("INSERT INTO manual_adjustment (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)");
            $stmt->execute(['Stock adjustment via Inventory module', $user_id]);
            $adjustment_id = $conn->lastInsertId();

            // Get current quantity again for safety
            $stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$product_id]);
            $inv_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $old_qty = (float)($inv_row['quantity'] ?? 0);
            $inventory_id = $inv_row['Inventory_ID'] ?? null;
            $new_qty = $old_qty + $adjustment_value;

            if ($new_qty < 0) throw new Exception("Insufficient stock for this adjustment.");

            // Insert details
            $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $adjustment_id, $old_qty, $new_qty, ($adjustment_value > 0 ? 'increase' : 'decrease'), $reason]);

            // Update primary stock
            $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Product_ID = ? ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$new_qty, $product_id]);

            // LOG TO LEDGER
            $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'ADJUSTMENTS', ?, ?, ?, ?, ?)");
            $ledger_stmt->execute([
                $product_id,
                $adjustment_id,
                $adjustment_value,
                $new_qty,
                $user_id,
                "Manual Adjustment: " . $reason
            ]);

            // AUTO-LOG TO DAMAGE_GOODS if damage-related reason and negative adjustment
            $damage_reasons = ['Damage', 'Melted Loss', 'Spoilage', 'Expired', 'Lost / Stolen'];
            if ($inventory_id && in_array($reason, $damage_reasons) && $adjustment_value < 0) {
                $d_stmt = $conn->prepare("INSERT INTO damage_goods (Inventory_ID, Adjustment_ID, quantity, reported_by, reason, damage_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $d_stmt->execute([$inventory_id, $adjustment_id, abs($adjustment_value), $user_id, 'Automated sync from Inventory Adjustment', $reason]);
            }

            $conn->commit();
            logActivity('INVENTORY', "Manual adjustment: " . ($adjustment_value > 0 ? '+' : '') . "$adjustment_value units for Product #$product_id ($reason)", $adjustment_id);

            // PRG pattern: store success in session and redirect to clear POST data
            $_SESSION['inv_adj_success'] = 'Adjustment saved successfully.';
            header('Location: inventory.php');
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['inv_adj_error'] = 'Error: ' . $e->getMessage();
            header('Location: inventory.php');
            exit;
        }
    } else {
        $_SESSION['inv_adj_error'] = implode(' ', $inventory_adjustment_errors);
        header('Location: inventory.php');
        exit;
    }
}

// Fetch production history for history modal.
// Use productions when present; otherwise derive history from stockin_inventory.
if ($has_productions_table) {
    $history_query = "SELECT 
        p.Production_ID,
        p.production_date,
        p.production_type,
        p.produced_qty,
        pr.product_name,
        u_units.unit_name,
        u.user_name as handled_by,
        o.Order_ID as order_id_display,
        o.order_date as order_date_display
    FROM productions p
    INNER JOIN products pr ON p.Product_ID = pr.Product_ID
    LEFT JOIN units u_units ON pr.unit_id = u_units.unit_id
    LEFT JOIN user u ON p.created_by = u.User_ID
    LEFT JOIN orders o ON p.Order_ID = o.Order_ID
    ORDER BY p.production_date DESC, p.Production_ID DESC
    LIMIT 100";
} else {
    $pTypeExpr = "'stockin'";
    $dateExpr = "DATE(si.created_at)";

    $history_query = "SELECT 
        si.Inventory_ID AS Production_ID,
        {$dateExpr} AS production_date,
        {$pTypeExpr} AS production_type,
        pr.product_name,
        u_units.unit_name,
        u.user_name as handled_by,
        il.quantity_change as produced_qty
    FROM stockin_inventory si
    INNER JOIN products pr ON si.Product_ID = pr.Product_ID
    LEFT JOIN units u_units ON pr.unit_id = u_units.unit_id
    LEFT JOIN user u ON si.handled_by = u.User_ID
    LEFT JOIN inventory_ledger il ON si.Inventory_ID = il.transaction_id AND il.transaction_type = 'STOCK IN'
    ORDER BY production_date DESC, si.Inventory_ID DESC
    LIMIT 100";
}
$history_result = $conn->query($history_query)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Adjustment Modal Styles */
        .preview-qty { font-weight: 700; color: #6366f1; }
        .current-qty { font-weight: 600; color: #64748b; }
        .adjustment-preview-box { background: #f8fafc; border-radius: 12px; padding: 1rem; margin-top: 1rem; border: 1px dashed #cbd5e1; }
        .adjustment-row { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
        .adjustment-row:last-child { margin-bottom: 0; padding-top: 0.5rem; border-top: 1px solid #e2e8f0; margin-top: 0.5rem; }

        @media (max-width: 768px) {
            .inventory-header { padding: 3rem 1rem 2rem !important; }
            .sidebar { display: none !important; }
            .mobile-sidebar-toggle { display: none !important; }
            .main-content { margin-left: 0 !important; padding-top: 0 !important; }
        }

        <?php if ($is_inventory_staff): ?>
        .sidebar { display: none !important; }
        .mobile-sidebar-toggle { display: none !important; }
        .main-content { margin-left: 0 !important; padding-top: 0 !important; }
        .inventory-header { display: none !important; } /* We use staff header card instead */
        <?php endif; ?>

        /* Staff Header Card Style (Copied for consistency) */
        .staff-header-card {
            background: white; border-radius: 20px; padding: 1.5rem; margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center;
        }
        .staff-profile { display: flex; align-items: center; gap: 1rem; }
        .staff-avatar {
            width: 56px; height: 56px; border-radius: 12px; background: #7c3aed; color: white;
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700;
        }
        .staff-info h2 { margin: 0; font-size: 1.15rem; font-weight: 800; line-height: 1.2; }
        .staff-info p { margin: 0; font-size: 0.85rem; color: #64748b; }
        .btn-logout-minimal {
            background: #f1f5f9; border: none; padding: 0.6rem 1rem; border-radius: 10px;
            color: #64748b; font-size: 0.8rem; font-weight: 600; cursor: pointer;
            display: flex; flex-direction: column; align-items: center; gap: 2px;
        }
        .nav-tabs-rider { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; overflow-x: auto; padding-bottom: 5px; }
        .nav-tab-rider {
            padding: 0.6rem 1.25rem; border-radius: 10px; font-size: 0.875rem; font-weight: 700;
            white-space: nowrap; cursor: pointer; transition: all 0.2s; display: flex;
            align-items: center; gap: 0.5rem; background: transparent; color: #64748b; border: none;
        }
        .nav-tab-rider.active { background: #4f46e5; color: white; }

        /* ===== Bigger table sizing ===== */
        .inventory-table th,
        .inventory-history-table th,
        .reservation-modal-table th {
            padding: 1.5rem;
            font-size: 0.95rem;
        }
        .inventory-table td,
        .inventory-history-table td,
        .reservation-modal-table td {
            padding: 1.5rem;
            font-size: 1.1rem;
        }
        .product-name {
            font-size: 1.15rem;
        }
        .quantity-cell {
            padding: 0.6rem 1.1rem;
            font-size: 1rem;
        }
        .price-cell {
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'inventory']);
    ?>
    <aside class="sidebar legacy-sidebar" style="display:none;">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon">
                    <i class="fas fa-snowflake"></i>
                </div>
                <div class="brand-text">
                    <h2>Villanueva</h2>
                    <p>Ice Plant System</p>
                </div>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-angles-left"></i>
            </button>
        </div>

        <nav class="sidebar-menu">
            <div class="menu-section">
                <div class="menu-label">Main Menu</div>
                <a href="../index.php" class="menu-item">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                <a href="sales.php" class="menu-item">
                    <i class="fas fa-receipt"></i>
                    <span>Sales</span>
                </a>
                <a href="inventory.php" class="menu-item active">
                    <i class="fas fa-cubes"></i>
                    <span>Inventory</span>
                </a>
                <a href="damage_goods.php" class="menu-item">
                    <i class="fas fa-heart-broken"></i>
                    <span>Damage Goods</span>
                </a>
                <a href="stock_ledger.php" class="menu-item">
                    <i class="fas fa-file-invoice"></i>
                    <span>Stock Ledger</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Orders</span>
                </a>
                <a href="delivery.php" class="menu-item">
                    <i class="fas fa-truck"></i>
                    <span>Delivery</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">Accounting</div>
                <a href="accounts_receivable.php" class="menu-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Accounts Receivable</span>
                    <span class="menu-item-badge">3</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
                <?php if (in_array($_SESSION['user_role'] ?? 1, [1, 2])): ?>
                <a href="user_management.php" class="menu-item">
                    <i class="fas fa-user-shield"></i>
                    <span>User Management</span>
                </a>
                <?php endif; ?>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent" style="<?php echo $is_inventory_staff ? 'max-width: 800px; margin: 0 auto; padding: 1.5rem;' : ''; ?>">
        <?php if ($is_inventory_staff): ?>
            <?php
            $display_name = $_SESSION['user_name'] ?? 'User';
            $display_role = 'Staff';
            ?>
            <header class="staff-header-card">
                <div class="staff-profile">
                    <div class="staff-avatar">
                        <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                    </div>
                    <div class="staff-info">
                        <p style="text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: #94a3b8; font-size: 0.65rem; margin-bottom: 2px;">Inventory Session</p>
                        <h2><?php echo htmlspecialchars($display_name); ?></h2>
                        <p><?php echo htmlspecialchars($display_role); ?> · VIP Ice Plant</p>
                    </div>
                </div>
                <button class="btn-logout-minimal" onclick="location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </button>
            </header>

            <div class="nav-tabs-rider">
                <button class="nav-tab-rider" onclick="location.href='manual_adjustment.php'">
                    <i class="fas fa-th-large"></i> Dashboard
                </button>
                <button class="nav-tab-rider" onclick="location.href='manual_adjustment.php?tab=history'">
                    <i class="fas fa-history"></i> History
                </button>
                <button class="nav-tab-rider active">
                    <i class="fas fa-boxes"></i> Inventory
                </button>
            </div>
        <?php else: ?>
            <button class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <!-- Inventory Header -->
            <section class="inventory-header">
                <div class="inventory-header-content">
                    <h1 class="inventory-title">
                        <i class="fas fa-cubes" style="color: white; opacity: 0.9;"></i>
                        Inventory Management
                    </h1>
                    <p class="inventory-subtitle">View and manage your inventory stocks efficiently</p>
                </div>
            </section>
        <?php endif; ?>

        <!-- Search and Filter Controls -->
        <section class="inventory-controls">
            <div class="search-filter-grid">
                <div class="search-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search products..." id="searchInput">
                </div>
                <select class="filter-select" id="statusFilter">
                    <option value="active" selected>Active Products</option>
                    <option value="inactive">Inactive Products</option>
                    <option value="all">All Products</option>
                </select>
                <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
                    <?php if ($can_manage_inventory_operations): ?>
                    <button type="button" id="openManagerAdjustmentModal" class="btn-add-product" style="background: #1e293b; border: 1px solid #334155;">
                        <i class="fas fa-user-shield" style="color: #6366f1;"></i>
                        Manager Adjustment
                    </button>
                    <?php endif; ?>

                    <?php if ($can_manage_products && !$is_inventory_staff): ?>
                        <a href="products_add.php" class="btn-add-product">
                            <i class="fas fa-plus"></i>
                            Add Product
                        </a>
                    <?php endif; ?>

                    <?php if ($can_manage_inventory_operations): ?>
                    <?php if (!$is_inventory_staff): ?>
                        <button id="openProductionModalHeader" type="button" class="btn-add-product">
                            <i class="fas fa-industry"></i>
                            Add Production
                        </button>
                    <?php else: ?>
                        <button id="openProductionModalHeader" type="button" class="btn-add-product" style="background: #7c3aed;">
                            <i class="fas fa-industry"></i>
                            Add Productions
                        </button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <button id="openProductionHistoryModalHeader" type="button" class="btn-add-product" style="background: #4f46e5;">
                        <i class="fas fa-history"></i>
                        Production History
                    </button>
                </div>
            </div>
        </section>
        
        <?php
        $pending_ddr_count = countPendingDeliveryDamageReports($conn);
        if ($pending_ddr_count > 0 && userCanAccessDeliveryDamageQueue($conn, $role_id)):
        ?>
        <section class="inventory-stats" style="margin-bottom: 1.5rem;">
            <div class="stat-card" style="border-left: 4px solid #f59e0b; background: #fffbeb; cursor: pointer; grid-column: 1 / -1;" onclick="location.href='delivery_damage_queue.php'">
                <div class="stat-content">
                    <div class="stat-info">
                        <p style="color: #92400e; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Pending Delivery Damage</p>
                        <h3 style="color: #92400e; margin: 0.25rem 0;"><?php echo $pending_ddr_count; ?> Reports Awaiting Review</h3>
                        <div class="stat-change" style="color: #b45309; font-size: 0.875rem;">
                            <i class="fas fa-exclamation-triangle"></i>
                            Action required to adjust inventory levels
                        </div>
                    </div>
                    <div class="stat-icon" style="background: #fef3c7; color: #f59e0b;">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Inventory Stats -->
        <section class="inventory-stats">
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Total Products</p>
                        <h3 id="inventoryTotalProducts"><?php echo $active_count; ?></h3>
                        <div class="stat-change neutral">
                            <i class="fas fa-circle"></i>
                            <span id="inventoryActiveProducts"><?php echo $active_count; ?></span> active products
                        </div>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-cubes"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Low Stock Items</p>
                        <h3 id="inventoryLowStock">0</h3>
                        <div class="stat-change negative">
                            <i class="fas fa-exclamation-triangle"></i>
                            Items below threshold
                        </div>
                    </div>
                    <div class="stat-icon amber">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Total Value</p>
                        <h3 id="inventoryTotalValue">₱0.00</h3>
                        <div class="stat-change neutral">
                            <i class="fas fa-dollar-sign"></i>
                            Inventory value
                        </div>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Out of Stock</p>
                        <h3 id="inventoryOutOfStock">0</h3>
                        <div class="stat-change neutral">
                            <i class="fas fa-times-circle"></i>
                            Requires attention
                        </div>
                    </div>
                    <div class="stat-icon pink">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
            </div>
        </section>

        <!-- Inventory Table -->
        <section class="inventory-table-container">
            <?php if (count($result) > 0): ?>
                <div class="table-responsive">
                    <table class="inventory-table" id="inventoryTable">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Unit</th>
                                <th>Category</th>
                                <th>Wholesale Price</th>
                                <th>Retail Price</th>
                                <th>Description</th>
                                <th>On Hand</th>
                                <th>Reserved</th>
                                <th>Sellable</th>
                                <th>Storage Limit</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Last Updated</th>
                                <?php if (!$is_inventory_staff): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result as $row): 
                                $is_inactive = !empty($row['is_discontinued']);
                                $initial_style = $is_inactive ? 'style="display:none;"' : '';
                            ?>
                                <tr data-status="<?php echo $is_inactive ? 'inactive' : 'active'; ?>" <?php echo $initial_style; ?>>
                                    <td>
                                        <div class="product-name">
                                            <i class="fas fa-box"></i>
                                            <?php if (!empty($row['product_image'])): ?>
                                                <img src="../uploads/products/<?php echo htmlspecialchars($row['product_image']); ?>" alt="" style="width: 36px; height: 36px; border-radius: 6px; object-fit: cover; border: 1px solid #e2e8f0;">
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($row['product_name']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['unit_name'] ?? '-'); ?></td>
                                    <td>
                                        <?php if (!empty($row['category_name'])): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #eef2ff; color: #6366f1; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                                                <i class="fas fa-tag" style="font-size: 0.7rem;"></i>
                                                <?php echo htmlspecialchars($row['category_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.85rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="price-cell"><?php echo htmlspecialchars($row['wholesale_price']); ?></td>
                                    <td class="price-cell"><?php echo htmlspecialchars($row['retail_price']); ?></td>
                                    <td><?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                                    <td>
                                        <?php
                                        $quantity = $row['current_quantity'] ?? 0;
                                        $reservedQty = (float)($reservedMap[(int)$row['Product_ID']] ?? 0);
                                        $sellableQty = max(0.0, (float)$quantity - $reservedQty);
                                        $quantityClass = 'quantity-high';
                                        if ($quantity < 10) {
                                            $quantityClass = 'quantity-low';
                                        } elseif ($quantity < 50) {
                                            $quantityClass = 'quantity-medium';
                                        }
                                        ?>
                                        <span class="quantity-cell <?php echo $quantityClass; ?>">
                                            <?php echo number_format((float)($quantity ?? 0), 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $reservedClass = $reservedQty > 0 ? 'quantity-medium' : 'quantity-high';
                                        ?>
                                        <span class="quantity-cell <?php echo $reservedClass; ?>">
                                            <?php echo number_format((float)$reservedQty, 0); ?>
                                        </span>
                                        <?php if ($reservedQty > 0): ?>
                                            <?php
                                            $holdsJson = htmlspecialchars(json_encode($reservationDetailsMap[(int)$row['Product_ID']] ?? []), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <div style="margin-top: 6px;">
                                                <button type="button"
                                                        class="btn-edit"
                                                        style="background:#fff7ed; color:#9a3412; border-color:#fed7aa; font-size:0.7rem; padding:0.2rem 0.5rem;"
                                                        data-product="<?php echo htmlspecialchars($row['product_name']); ?>"
                                                        data-holds="<?php echo $holdsJson; ?>"
                                                        onclick="showReservationDebug(this)">
                                                    <i class="fas fa-link"></i> Holds
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $sellableClass = $sellableQty <= 0 ? 'quantity-low' : ($sellableQty < 10 ? 'quantity-medium' : 'quantity-high');
                                        ?>
                                        <span class="quantity-cell <?php echo $sellableClass; ?>">
                                            <?php echo number_format((float)$sellableQty, 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php $storageLimit = (float)($row['storage_limit'] ?? 100); ?>
                                        <span class="quantity-cell quantity-medium"><?php echo number_format((float)$storageLimit, 0); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['is_discontinued'])): ?>
                                            <span class="quantity-cell quantity-low">Inactive</span>
                                        <?php else: ?>
                                            <span class="quantity-cell quantity-high">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['created_date'] ?? '-'); ?></td>
                                     <td><?php echo htmlspecialchars($row['inventory_updated_at'] ?? '-'); ?></td>
                                    <?php if (!$is_inventory_staff): ?>
                                        <td>
                                            <div class="action-buttons">
                                                <button onclick="viewProductDetails(<?php echo (int)$row['Product_ID']; ?>)" class="btn-edit" style="background:#f0f9ff; color:#0284c7; border-color:#bae6fd;" title="View product details">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($can_manage_products): ?>
                                                    <a href="products_edit.php?id=<?php echo $row['Product_ID']; ?>" class="btn-edit">
                                                        <i class="fas fa-edit"></i>
                                                        Edit
                                                    </a>
                                                    <form method="POST" action="inventory.php" style="display:inline;">
                                                        <?php echo csrfTokenField(); ?>
                                                        <input type="hidden" name="product_id" value="<?php echo (int)$row['Product_ID']; ?>">
                                                        <?php if (empty($row['is_discontinued'])): ?>
                                                            <button type="submit" name="product_action" value="deactivate" class="btn-history" onclick="return confirm('Mark this product as inactive?');">
                                                                <i class="fas fa-ban"></i>
                                                                Inactivate
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="submit" name="product_action" value="restore" class="btn-production">
                                                                <i class="fas fa-undo"></i>
                                                                Restore
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination" style="display:flex; justify-content:center; align-items:center; gap:0.5rem; margin-top:1.5rem; flex-wrap:wrap;">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page-1; ?>" class="btn-pagination" style="padding:0.5rem 1rem; border-radius:8px; background:#f1f5f9; color:#475569; text-decoration:none; font-weight:600; font-size:0.9rem; transition:all 0.2s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">&laquo; Prev</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>" class="btn-pagination" style="<?php echo $i === $page ? 'background:#7c3aed; color:white;' : 'background:#f1f5f9; color:#475569;'; ?> padding:0.5rem 0.9rem; border-radius:8px; text-decoration:none; font-weight:600; font-size:0.9rem; transition:all 0.2s;"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page+1; ?>" class="btn-pagination" style="padding:0.5rem 1rem; border-radius:8px; background:#f1f5f9; color:#475569; text-decoration:none; font-weight:600; font-size:0.9rem; transition:all 0.2s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-cubes"></i>
                    <h3>No Products Found</h3>
                    <p>You haven't added any products to your inventory yet. Start by adding your first product.</p>
                    <?php if ($can_manage_products): ?>
                    <a href="products_add.php" class="btn-add-product">
                        <i class="fas fa-plus"></i>
                        Add Your First Product
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Inventory Production Alerts -->
        <?php if (!empty($inventory_product_success)): ?>
            <div class="alert alert-success" style="margin: 0 1.5rem 1.5rem;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($inventory_product_success); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($inventory_production_success)): ?>
            <div class="alert alert-success" style="margin: 0 1.5rem 1.5rem;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($inventory_production_success); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($inventory_production_errors)): ?>
            <div class="alert alert-danger" style="margin: 0 1.5rem 1.5rem;">
                <i class="fas fa-exclamation-triangle"></i>
                <ul style="margin: 0.5rem 0 0 1.5rem;">
                    <?php foreach ($inventory_production_errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Inventory Production Modal -->
<div id="inventoryProductionModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header">
            <h2>
                <i class="fas fa-industry"></i>
                Record Production
            </h2>
            <button class="inventory-modal-close" data-close-inventory-modal>&times;</button>
        </div>
        <div class="inventory-modal-body">
            <form id="inventoryProductionForm" method="POST" action="inventory.php">
                <?php echo csrfTokenField(); ?>
                <input type="hidden" name="production_type" value="stockin">
                <input type="hidden" name="redirect" value="inventory">

                <div class="form-group">
                    <label for="inv_prod_product_id">Product *</label>
                    <select id="inv_prod_product_id" name="product_id" required>
                        <option value="">Select a product</option>
                        <?php
                        if ($products_result) {
                            foreach ($products_result as $product):
                                $product_display = htmlspecialchars($product['product_name']);
                                if (!empty($product['unit_name'])) {
                                    $product_display .= ' (' . htmlspecialchars($product['unit_name']) . ')';
                                }
                        ?>
                            <option value="<?php echo $product['Product_ID']; ?>">
                                <?php echo $product_display; ?>
                            </option>
                        <?php
                            endforeach;
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="inv_prod_production_date">Production Date</label>
                    <input type="date" id="inv_prod_production_date" name="production_date" required>
                </div>

                <div class="form-group">
                    <label for="inv_prod_number_of_bags">Number of Packs to Produce</label>
                    <input type="number" id="inv_prod_number_of_bags" name="number_of_bags" min="1" step="1" required>
                </div>

                <div id="inv_prod_error" style="display:none;color:#b91c1c;font-size:0.85rem;"></div>

                <div class="inventory-modal-actions">
                    <button type="button" class="inventory-modal-btn inventory-modal-btn-secondary" data-close-inventory-modal>
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="submit" class="inventory-modal-btn inventory-modal-btn-primary">
                        <i class="fas fa-save"></i>
                        Save Production
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Inventory Stock In History Modal -->
<div id="inventoryHistoryModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content history-wide">
        <div class="inventory-modal-header">
            <h2>
                <i class="fas fa-warehouse"></i>
                Stock In History
            </h2>
            <button class="inventory-modal-close" data-close-inventory-modal>&times;</button>
        </div>
            <div class="inventory-modal-body">
            <div class="form-group">
                <label>Scope</label>
                <input type="text" readonly value="All products">
            </div>
            <div class="form-group">
                <label>Export Range</label>
                <div style="display:flex; gap:0.5rem; align-items:center;">
                    <input type="date" id="export_production_start" style="flex:1;">
                    <span style="color:#6b7280; font-size:0.85rem;">to</span>
                    <input type="date" id="export_production_end" style="flex:1;">
                </div>
            </div>
            <div style="display: flex; gap: 0.75rem; margin-bottom: 1.25rem;">
                <button type="button" id="btnExportRange" class="btn-export-new">
                    <i class="fas fa-file-export"></i> Export Range
                </button>
                <button type="button" id="btnExportToday" class="btn-export-new btn-export-secondary">
                    <i class="fas fa-calendar-day"></i> Export Today
                </button>
            </div>
            <div class="table-responsive">
                <table class="inventory-history-table">
                    <thead>
                        <tr>
                            <th>Production Date</th>
                            <th>Product</th>
                            <th>Bag Produced Qty</th>
                            <th>Unit Size</th>
                            <th>Handled By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history_result && count($history_result) > 0): ?>
                            <?php foreach ($history_result as $row): 
                                // Show only base product name here (hide form/unit details in history)
                                $product_display = htmlspecialchars($row['product_name']);

                            ?>
                                <tr>
                                    <td class="date-cell">
                                        <?php
                                        if (!empty($row['production_date'])) {
                                            // Treat as plain date to avoid timezone-based day shifts
                                            $d = substr($row['production_date'], 0, 10);
                                            $parts = explode('-', $d);
                                            if (count($parts) === 3) {
                                                echo date('M d, Y', mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]));
                                            } else {
                                                echo htmlspecialchars($row['production_date']);
                                            }
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td class="product-cell"><?php echo $product_display; ?></td>
                                    <td class="qty-cell">
                                        <span class="qty-badge">
                                            <?php 
                                            $produced_qty = floatval($row['produced_qty'] ?? 0);
                                            echo '+' . number_format($produced_qty, 0);
                                            ?>
                                        </span>
                                    </td>
                                    <td class="badge-cell">
                                        <?php
                                        $unit_badge_class = 'unit-badge';
                                        if (isset($row['unit_name']) && stripos($row['unit_name'], 'kg') !== false) {
                                            $unit_badge_class .= ' unit-badge-kg';
                                        } elseif (isset($row['unit_name']) && stripos($row['unit_name'], 'piece') !== false) {
                                            $unit_badge_class .= ' unit-badge-pieces';
                                        }
                                        ?>
                                        <span class="<?php echo $unit_badge_class; ?>">
                                            <?php 
                                            if (isset($row['unit_name']) && $row['unit_name'] !== null && $row['unit_name'] !== '') {
                                                echo htmlspecialchars($row['unit_name']);
                                            } else {
                                                if (isset($row['bag_size']) && $row['bag_size'] !== null && $row['bag_size'] !== '') {
                                                    $bag_size = floatval($row['bag_size']);
                                                    if ($bag_size > 0) {
                                                        echo number_format($bag_size, 0) . ' kg';
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                } else {
                                                    echo 'N/A';
                                                }
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="badge-cell">
                                        <span class="user-badge">
                                            <i class="fas fa-user-circle"></i>
                                            <?php echo htmlspecialchars($row['handled_by'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="inventory-history-empty">No production history found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script src="../assets/js/inventory.js"></script>
<script>
// Load inventory statistics and initialize modals / filters
document.addEventListener('DOMContentLoaded', function() {
    loadInventoryStats();
    initInventoryModals();
    initInventoryFilters();
});

async function loadInventoryStats() {
    try {
        const response = await fetch('../api/inventory_stats.php');
        const result = await response.json();
        
        if (result.success) {
            const data = result.data;
            
            document.getElementById('inventoryTotalProducts').textContent = data.total_products;
            document.getElementById('inventoryActiveProducts').textContent = data.total_products;
            document.getElementById('inventoryLowStock').textContent = data.low_stock;
            document.getElementById('inventoryTotalValue').textContent = formatCurrency(data.total_value);
            document.getElementById('inventoryOutOfStock').textContent = data.out_of_stock;
        }
    } catch (error) {
        console.error('Error loading inventory stats:', error);
    }
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function initInventoryModals() {
    const body = document.body;
    const productionModal = document.getElementById('inventoryProductionModal');
    const historyModal = document.getElementById('inventoryHistoryModal');

    // Helper: local date string (YYYY-MM-DD) using browser's timezone
    function getLocalDateString() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function openModal(modal) {
        if (!modal) return;
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        if (body) {
            body.style.overflow = 'hidden';
        }
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        if (body) {
            body.style.overflow = '';
        }
    }

    // Close buttons inside modals
    document.querySelectorAll('[data-close-inventory-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            closeModal(productionModal);
            closeModal(historyModal);
        });
    });

    // Close when clicking outside modal content
    window.addEventListener('click', (e) => {
        if (e.target === productionModal) closeModal(productionModal);
        if (e.target === historyModal) closeModal(historyModal);
    });

    // Open production modal from header button
    const headerButton = document.getElementById('openProductionModalHeader');
    if (headerButton) {
        headerButton.addEventListener('click', () => {
            const dateInput = document.getElementById('inv_prod_production_date');
            if (dateInput && !dateInput.value) {
                dateInput.value = getLocalDateString();
            }
            openModal(productionModal);
        });
    }

    // Open global production history modal (server-rendered table)
    const historyHeaderButton = document.getElementById('openProductionHistoryModalHeader');
    if (historyHeaderButton) {
        historyHeaderButton.addEventListener('click', () => {
            openModal(historyModal);

            // Pre-fill export range with today when opening
            const startInput = document.getElementById('export_production_start');
            const endInput = document.getElementById('export_production_end');
            const today = getLocalDateString();
            if (startInput && !startInput.value) startInput.value = today;
            if (endInput && !endInput.value) endInput.value = today;
        });
    }

    // Export buttons (range + today)
    const startInput = document.getElementById('export_production_start');
    const endInput = document.getElementById('export_production_end');
    const btnExportRange = document.getElementById('btnExportRange');
    const btnExportToday = document.getElementById('btnExportToday');

    if (btnExportRange && startInput && endInput) {
        btnExportRange.addEventListener('click', () => {
            let start = startInput.value;
            let end = endInput.value || start;
            if (!start) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select a start date',
                    text: 'Please select at least a start date to export production.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            // Ensure start <= end
            if (end && start > end) {
                const tmp = start;
                start = end;
                end = tmp;
            }
            window.location.href = `../api/export_production_today.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
        });
    }

    if (btnExportToday) {
        btnExportToday.addEventListener('click', () => {
            const t = getLocalDateString();
            window.location.href = `../api/export_production_today.php?start=${encodeURIComponent(t)}&end=${encodeURIComponent(t)}`;
        });
    }
}

// Show/hide products based on Active / Inactive / All filter
function initInventoryFilters() {
    const table = document.getElementById('inventoryTable');
    const statusFilter = document.getElementById('statusFilter');
    const searchInput = document.getElementById('searchInput');
    if (!table || !statusFilter) return;

    const rows = Array.from(table.querySelectorAll('tbody tr'));

    function applyFilters() {
        const statusValue = statusFilter.value || 'active';
        const searchTerm = (searchInput?.value || '').toLowerCase().trim();

        rows.forEach(row => {
            const status = row.getAttribute('data-status') || 'active';
            const productName = row.querySelector('.product-name')?.textContent.toLowerCase() || '';
            
            let showStatus = true;
            if (statusValue === 'active') {
                showStatus = status === 'active';
            } else if (statusValue === 'inactive') {
                showStatus = status === 'inactive';
            }

            const showSearch = productName.includes(searchTerm);
            
            row.style.display = (showStatus && showSearch) ? '' : 'none';
        });
    }

    statusFilter.addEventListener('change', applyFilters);
    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    // Default view: active products only
    if (!statusFilter.value) {
        statusFilter.value = 'active';
    }
    applyFilters();
}

function showReservationDebug(buttonEl) {
    const product = buttonEl?.dataset?.product || 'Product';
    let holds = [];
    try {
        holds = JSON.parse(buttonEl?.dataset?.holds || '[]');
    } catch (e) {
        holds = [];
    }
    if (!Array.isArray(holds) || holds.length === 0) {
        Swal.fire({
            icon: 'info',
            title: `${product} reservation holds`,
            text: 'No active reservation rows found.'
        });
        return;
    }

    const totalQty = holds.reduce((sum, h) => sum + (Number(h.ordered_qty) || 0), 0);

    const statusColors = {
        'requested': { bg: '#fef3c7', text: '#92400e', icon: 'fa-clock' },
        'out for delivery': { bg: '#dbeafe', text: '#1d4ed8', icon: 'fa-truck' },
        'in transit': { bg: '#e0e7ff', text: '#4338ca', icon: 'fa-shipping-fast' },
        'delivered': { bg: '#dcfce7', text: '#166534', icon: 'fa-check-circle' },
        'returning': { bg: '#fee2e2', text: '#dc2626', icon: 'fa-undo' },
        'pending': { bg: '#fef3c7', text: '#92400e', icon: 'fa-hourglass-half' },
        'preparing': { bg: '#fef9c3', text: '#854d0e', icon: 'fa-spinner' }
    };

    const rows = holds.map((h) => {
        const orderId = h.order_id || '-';
        const customerName = h.customer_name || 'N/A';
        const qty = Number(h.ordered_qty || 0).toString().replace(/\.0+$/,'');
        const orderStatus = (h.order_status || '').toString().toLowerCase() || '-';

        const orderStyle = statusColors[orderStatus] || { bg: '#f3f4f6', text: '#374151', icon: 'fa-info' };

        return `<tr style="transition:background 0.2s;">
            <td style="padding:0.875rem 1rem; border-bottom:1px solid #e2e8f0; font-weight:600; color:#1e293b;">#${orderId}</td>
            <td style="padding:0.875rem 1rem; border-bottom:1px solid #e2e8f0; font-weight:600; color:#475569;">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-user-circle" style="color:#94a3b8; font-size:1rem;"></i>
                    ${customerName}
                </div>
            </td>
            <td style="padding:0.875rem 1rem; border-bottom:1px solid #e2e8f0; text-align:center; font-weight:700; color:#6366f1;">${qty}</td>
            <td style="padding:0.875rem 1rem; border-bottom:1px solid #e2e8f0;">
                <span style="display:inline-flex; align-items:center; gap:0.375rem; padding:0.375rem 0.75rem; border-radius:9999px; font-size:0.75rem; font-weight:600; background:${orderStyle.bg}; color:${orderStyle.text};">
                    <i class="fas ${orderStyle.icon}"></i> ${h.order_status || '-'}
                </span>
            </td>
        </tr>`;
    }).join('');

    Swal.fire({
        title: `<div style="display:flex; align-items:center; gap:0.75rem;">
            <i class="fas fa-box-open" style="color:#6366f1;"></i>
            <span>${product} reservation holds</span>
        </div>`,
        width: 850,
        html: `
            <style>
                .reservation-modal-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.875rem; }
                .reservation-modal-table th { background:linear-gradient(135deg, #f8fafc, #f1f5f9); color:#475569; font-weight:600; text-transform:uppercase; font-size:0.7rem; letter-spacing:0.05em; padding:0.75rem 1rem; text-align:left; border-bottom:2px solid #e2e8f0; }
                .reservation-modal-table tr:hover td { background:#f8fafc; }
                .reservation-summary { display:flex; align-items:center; justify-content:space-between; background:linear-gradient(135deg, #fef3c7, #fde68a); border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; border:1px solid #fcd34d; }
            </style>

            <div class="reservation-summary">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <div style="width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg, #f59e0b, #d97706); display:flex; align-items:center; justify-content:center; color:white; font-size:1rem;">
                        <i class="fas fa-link"></i>
                    </div>
                    <div>
                        <div style="font-size:0.75rem; color:#92400e; text-transform:uppercase; font-weight:600;">Active Reservations</div>
                        <div style="font-size:1rem; font-weight:700; color:#78350f;">${holds.length} order${holds.length !== 1 ? 's' : ''} holding inventory</div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:0.75rem; color:#92400e; text-transform:uppercase; font-weight:600;">Total Reserved Qty</div>
                    <div style="font-size:1.25rem; font-weight:800; color:#78350f;">${totalQty}</div>
                </div>
            </div>

            <div style="overflow:auto; max-height:380px; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <table class="reservation-modal-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer Name</th>
                            <th style="text-align:center;">Reserved Qty</th>
                            <th>Order Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem; padding:0.75rem; background:#f8fafc; border-radius:8px; font-size:0.8rem; color:#64748b; display:flex; align-items:center; gap:0.5rem;">
                <i class="fas fa-info-circle" style="color:#6366f1;"></i>
                <span>These orders are currently reserving stock from available inventory.</span>
            </div>
        `,
        confirmButtonText: 'Close',
        confirmButtonColor: '#6366f1',
        customClass: {
            title: 'swal2-title-custom',
            popup: 'swal2-popup-custom'
        }
    });
}
</script>

<?php if (!empty($inventory_product_success)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'success',
        title: 'Product Updated',
        text: '<?php echo addslashes($inventory_product_success); ?>',
        confirmButtonText: 'OK'
    });
});
</script>
<?php endif; ?>

<?php if (!empty($inventory_production_success)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'success',
        title: 'Production Recorded',
        text: '<?php echo addslashes($inventory_production_success); ?>',
        confirmButtonText: 'OK'
    });
});
</script>
<?php endif; ?>

<?php if (!empty($inventory_production_errors)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'error',
        title: 'Unable to record production',
        html: '<?php echo addslashes(implode("<br>", array_map("htmlspecialchars", $inventory_production_errors))); ?>',
        confirmButtonText: 'OK'
    });
});
</script>
<?php endif; ?>
<!-- Manager Adjustment Modal -->
<div class="inventory-modal" id="managerAdjustmentModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(15, 23, 42, 0.6); align-items:center; justify-content:center; backdrop-filter: blur(8px); transition: all 0.3s ease;">
    <div class="modal-content" style="max-width: 750px; width: 95%; border-radius: 2rem; overflow: hidden; border: none; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.3); animation: modalAppear 0.4s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(255,255,255,0.1);">
            <div style="display: flex; flex-direction: column;">
                <h2 style="color: #fff; margin: 0; font-size: 1.35rem; font-weight: 800; display: flex; align-items: center; gap: 0.75rem; letter-spacing: -0.025em;">
                    <div style="background: rgba(255,255,255,0.2); width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-user-shield" style="font-size: 1.1rem;"></i>
                    </div>
                    Manager Stock Adjustment
                </h2>
                <span style="color: rgba(255,255,255,0.8); font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 4px; margin-left: 3.25rem;">Authorized Management Console</span>
            </div>
            <button onclick="closeModal('managerAdjustmentModal')" style="background: rgba(255,255,255,0.1); border: none; color: #fff; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'; this.style.transform='rotate(90deg)';" onmouseout="this.style.background='rgba(255,255,255,0.1)'; this.style.transform='rotate(0deg)';">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 2.5rem; background: #ffffff;">
        <form method="post" id="adjustmentForm">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="adjustment_action" value="manual_adjust">
            
            <div class="form-group">
                <label>Product *</label>
                <select name="product_id" id="adj_product_id" required onchange="updateAdjQtyPreview()">
                    <option value="">Select Product</option>
                    <?php foreach ($products_result as $p): ?>
                        <option value="<?php echo $p['Product_ID']; ?>" data-qty="<?php echo (float)($p['current_quantity'] ?? 0); ?>">
                            <?php echo htmlspecialchars($p['product_name'] . ' - ' . ($p['unit_name'] ?? 'Units')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="adjustment-preview-box" style="background: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 20px; padding: 1.5rem; margin: 1.5rem 0; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: center;">
                <div style="border-right: 1px solid #f1f5f9; padding-right: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 1.25rem;">
                        <span style="font-weight: 700; color: #94a3b8; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;">Current Quantity</span>
                        <span id="adj_current_display_label" style="font-weight: 800; color: #1e293b; font-size: 1.15rem;">Select Product</span>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label style="font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 0.5rem;">Adjustment Amount *</label>
                        <input type="number" name="adjustment_value" id="adj_value" step="0.01" required 
                               placeholder="e.g., -5 or +10" 
                               style="font-size: 1.1rem; font-weight: 700; color: #6366f1; border-color: #cbd5e1;"
                               oninput="updateAdjQtyPreview()">
                        <small style="color: #94a3b8; font-size: 0.75rem; font-weight: 500; margin-top: 4px; display: block;">(+ to add, - to deduct)</small>
                    </div>
                </div>
                <div style="text-align: center; background: #ffffff; padding: 1.5rem; border-radius: 16px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); border: 1px solid #f1f5f9;">
                    <span style="display:block; font-weight: 700; color: #94a3b8; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Calculated New Quantity</span>
                    <span id="adj_result_display" class="preview-qty" style="font-size: 2.5rem; font-weight: 900; color: #6366f1; line-height: 1;">0</span>
                    <span style="display:block; color: #64748b; font-size: 0.8rem; font-weight: 600; margin-top: 0.5rem;">Units Remaining</span>
                </div>
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label>Reason *</label>
                <select name="reason" required>
                    <option value="">Select Reason</option>
                    <option value="Damage">Damage</option>
                    <option value="Melted Loss">Melted Loss</option>
                    <option value="Spoilage">Spoilage</option>
                    <option value="Expired">Expired</option>
                    <option value="Lost / Stolen">Lost / Stolen</option>
                    <option value="Physical Discrepancy">Physical Discrepancy</option>
                    <option value="System Discrepancy">System Discrepancy</option>
                    <option value="Correction">Correction</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div style="display:flex; gap:1rem; margin-top: 1.5rem;">
                <button type="button" onclick="closeModal('managerAdjustmentModal')" style="flex:1; background: #f8fafc; color: #6366f1; border: 1px solid #e0e7ff; padding: 1rem; border-radius: 12px; font-weight: 700; font-size: 0.95rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.borderColor='#c7d2fe';" onmouseout="this.style.background='#f8fafc'; this.style.borderColor='#e0e7ff';">Cancel</button>
                <button type="submit" style="flex:2; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #ffffff; border: none; padding: 1rem; border-radius: 12px; font-weight: 800; font-size: 1rem; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.75rem; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(99, 102, 241, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(99, 102, 241, 0.3)';">
                    <i class="fas fa-save"></i> Save Adjustment
                </button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Product Details Modal -->
<div class="modal" id="productDetailsModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(15, 23, 42, 0.6); align-items:center; justify-content:center; backdrop-filter: blur(8px); transition: all 0.3s ease;">
    <div class="modal-content" style="background:#fff; border-radius:2rem; width:95%; max-width:600px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.3); border:none; overflow: hidden; animation: modalAppear 0.4s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; position: relative;">
            <h2 style="font-weight:800; color:#ffffff; margin:0; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem; letter-spacing: -0.025em;">
                <div style="background: rgba(255,255,255,0.2); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-box-open" style="font-size: 1.1rem;"></i>
                </div>
                Product Specification
            </h2>
            <button onclick="closeModal('productDetailsModal')" style="border:none; background:rgba(255,255,255,0.1); width: 36px; height: 36px; border-radius: 50%; font-size:1rem; cursor:pointer; color:#ffffff; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'; this.style.transform='rotate(90deg)';" onmouseout="this.style.background='rgba(255,255,255,0.1)'; this.style.transform='rotate(0deg)';">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="productDetailsBody" style="padding: 2.5rem 2rem; max-height:70vh; overflow-y:auto; background: #ffffff;">
            <div style="text-align:center; padding: 2rem;">
                <div class="loading-spinner" style="width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #6366f1; border-radius: 50%; margin: 0 auto 1rem; animation: spin 1s linear infinite;"></div>
                <p style="color: #64748b; font-weight: 500;">Retrieving specifications...</p>
            </div>
        </div>
        <div style="padding: 1.5rem 2rem; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 1rem;">
            <button onclick="closeModal('productDetailsModal')" style="background: #ffffff; color: #475569; border: 1px solid #e2e8f0; padding: 0.75rem 2rem; border-radius: 12px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);" onmouseover="this.style.background='#f1f5f9'; this.style.color='#1e293b'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.background='#ffffff'; this.style.color='#475569'; this.style.borderColor='#e2e8f0';">
                Close
            </button>
        </div>
    </div>
</div>

<style>
@keyframes modalAppear {
    from { opacity: 0; transform: scale(0.95) translateY(20px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
function updateAdjQtyPreview() {
    const select = document.getElementById('adj_product_id');
    const displayCurrentLabel = document.getElementById('adj_current_display_label');
    const displayResult = document.getElementById('adj_result_display');
    const inputAdj = document.getElementById('adj_value');

    const selected = select.options[select.selectedIndex];
    const productName = selected?.text || '';
    const current = parseFloat(selected?.getAttribute('data-qty')) || 0;
    const adjust = parseFloat(inputAdj.value) || 0;
    
    if (select.value) {
        displayCurrentLabel.textContent = `${productName.split(' - ')[0]} ${current.toLocaleString()} units`;
    } else {
        displayCurrentLabel.textContent = 'Select Product';
    }
    
    displayResult.textContent = (current + adjust).toLocaleString();
    
    // Change color if negative
    displayResult.style.color = (current + adjust < 0) ? '#ef4444' : '#6366f1';
}

function viewProductDetails(productId) {
    const modal = document.getElementById('productDetailsModal');
    const body = document.getElementById('productDetailsBody');
    if (!modal || !body) return;

    modal.style.display = 'flex';
    if (document.body) document.body.style.overflow = 'hidden';
    
    body.innerHTML = `
        <div style="text-align:center; padding: 3rem 0;">
            <div class="loading-spinner" style="width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #6366f1; border-radius: 50%; margin: 0 auto 1.5rem; animation: spin 1s linear infinite;"></div>
            <p style="color: #64748b; font-weight: 500; font-size: 0.95rem;">Retrieving specifications...</p>
        </div>
    `;

    // Fetch the product list and find the product
    fetch('inventory.php') 
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const row = doc.querySelector(`button[onclick*="viewProductDetails(${productId})"]`)?.closest('tr');
            
            if (row) {
                const name = row.querySelector('.product-name').textContent.trim();
                const unit = row.cells[1].textContent.trim();
                const wholesale = row.cells[2].textContent.trim();
                const retail = row.cells[3].textContent.trim();
                const desc = row.cells[4].textContent.trim();
                const qty = row.cells[5].textContent.trim();
                const status = row.cells[6].textContent.trim();
                const created = row.cells[7].textContent.trim();
                const updated = row.cells[8].textContent.trim();

                const isLow = parseFloat(qty) < 20;
                const statusColor = status.toLowerCase().includes('active') ? '#10b981' : '#ef4444';

                body.innerHTML = `
                    <div style="display:flex; flex-direction:column; gap:2rem;">
                        <div style="text-align: center; margin-bottom: 0.5rem;">
                            <div style="display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; background: #f1f5f9; border-radius: 20px; margin-bottom: 1.25rem; color: #6366f1; font-size: 1.75rem; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);">
                                <i class="fas fa-cube"></i>
                            </div>
                            <h3 style="margin:0 0 0.5rem 0; color:#1e293b; font-size:1.75rem; font-weight: 800; letter-spacing: -0.025em;">${name}</h3>
                            <p style="margin:0 auto; color:#64748b; font-size: 1rem; max-width: 400px; line-height: 1.6;">${desc || 'No description provided for this product.'}</p>
                        </div>
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem;">
                            <div style="background: #f8fafc; padding: 1.25rem; border-radius: 16px; border: 1px solid #f1f5f9;">
                                <label style="display:block; color:#94a3b8; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">Stock Status</label>
                                <div style="display:flex; align-items:baseline; gap:0.5rem;">
                                    <span style="font-size: 1.75rem; font-weight: 900; color: ${isLow ? '#ef4444' : '#1e293b'};">${qty}</span>
                                    <span style="color: #64748b; font-weight: 600; font-size: 0.85rem;">${unit}</span>
                                </div>
                                <div style="margin-top: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: ${statusColor};"></div>
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #475569;">${status}</span>
                                </div>
                            </div>
                            
                            <div style="background: #f8fafc; padding: 1.25rem; border-radius: 16px; border: 1px solid #f1f5f9;">
                                <label style="display:block; color:#94a3b8; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">Pricing (PHP)</label>
                                <div style="margin-bottom: 0.75rem;">
                                    <span style="display:block; font-size: 0.75rem; color: #64748b; font-weight: 500;">Retail:</span>
                                    <span style="font-size: 1.25rem; font-weight: 800; color: #6366f1;">₱${retail}</span>
                                </div>
                                <div>
                                    <span style="display:block; font-size: 0.75rem; color: #64748b; font-weight: 500;">Wholesale:</span>
                                    <span style="font-size: 1rem; font-weight: 700; color: #334155;">₱${wholesale}</span>
                                </div>
                            </div>
                        </div>

                        <div style="background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display:flex; justify-content: space-between; align-items: center;">
                                <div style="display:flex; align-items: center; gap: 0.75rem;">
                                    <div style="width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; color: #3b82f6; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                        <i class="fas fa-calendar-plus"></i>
                                    </div>
                                    <span style="font-size: 0.85rem; color: #64748b; font-weight: 500;">Date Created</span>
                                </div>
                                <span style="font-size: 0.85rem; color: #1e293b; font-weight: 700;">${created}</span>
                            </div>
                            <div style="height: 1px; background: #f1f5f9; width: 100%;"></div>
                            <div style="display:flex; justify-content: space-between; align-items: center;">
                                <div style="display:flex; align-items: center; gap: 0.75rem;">
                                    <div style="width: 32px; height: 32px; border-radius: 8px; background: #fff7ed; color: #f97316; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <span style="font-size: 0.85rem; color: #64748b; font-weight: 500;">Last Restocked</span>
                                </div>
                                <span style="font-size: 0.85rem; color: #1e293b; font-weight: 700;">${updated}</span>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                body.innerHTML = `
                    <div style="text-align:center; padding: 3rem 0;">
                        <div style="width: 64px; height: 64px; background: #fff1f2; color: #f43f5e; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin: 0 auto 1.25rem;">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <h4 style="color: #1e293b; font-weight: 700; margin-bottom: 0.5rem;">Product Not Found</h4>
                        <p style="color: #64748b; font-size: 0.9rem;">The details for this product could not be retrieved from the current view.</p>
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error(err);
            body.innerHTML = '<div class="alert alert-danger">Error loading product details.</div>';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const btnOpen = document.getElementById('openManagerAdjustmentModal');
    if (btnOpen) {
        btnOpen.addEventListener('click', () => openModal('managerAdjustmentModal'));
    }
});
</script>

<?php if (!empty($_SESSION['inv_adj_success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'success',
        title: 'Adjustment Saved',
        text: <?php echo json_encode($_SESSION['inv_adj_success']); ?>,
        confirmButtonText: 'OK',
        confirmButtonColor: '#6366f1'
    });
});
</script>
<?php unset($_SESSION['inv_adj_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['inv_adj_error'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'error',
        title: 'Adjustment Error',
        text: <?php echo json_encode($_SESSION['inv_adj_error']); ?>,
        confirmButtonText: 'OK',
        confirmButtonColor: '#ef4444'
    });
});
</script>
<?php unset($_SESSION['inv_adj_error']); ?>
<?php endif; ?>

</body>
</html>
