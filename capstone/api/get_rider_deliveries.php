<?php
/**
 * Get Rider Deliveries API
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = (int)($_SESSION['user_role'] ?? 0);
$allowed_roles = [1, 2, 3, 4]; // Owner, Manager, Rider, Inventory
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$rider_id = intval($_GET['rider_id'] ?? 0);
if ($rider_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Rider ID is required']);
    exit();
}

try {
    $rider_stmt = $conn->prepare("SELECT u.User_ID, COALESCE(u.full_name, u.user_name) as rider_name, u.Role_ID FROM user u WHERE u.User_ID = ? AND u.is_active = 1");
    $rider_stmt->execute([$rider_id]);
    $rider = $rider_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Rider not found or inactive']);
        exit();
    }
    
    require_once __DIR__ . '/../includes/roles_helper.php';
    require_once __DIR__ . '/../includes/rider_availability_helper.php';
    $rider_role_ids = getRiderRoleIds($conn);
    
    if (!in_array((int)$rider['Role_ID'], $rider_role_ids, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selected user is not a rider']);
        exit();
    }

    syncDeliveriesWithRecordedSales($conn, $rider_id);
    
    // Check products table schema for unit handling
    $p_cols = $conn->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $has_unit_id = in_array('unit_id', $p_cols);
    $has_unit = in_array('unit', $p_cols);
    
    $unit_join = '';
    $unit_select = "'' as unit_name";
    
    if ($has_unit_id) {
        $units_check = $conn->query("SHOW TABLES LIKE 'units'")->rowCount();
        if ($units_check > 0) {
            $unit_join = "LEFT JOIN units u ON p.unit_id = u.unit_id";
            $unit_select = "COALESCE(u.unit_name, '') as unit_name";
        }
    } elseif ($has_unit) {
        $unit_select = "COALESCE(p.unit, '') as unit_name";
    }

    $params = [];
    $ownership_condition = riderBuildOwnershipCondition($conn, 'd', $rider_id, $params);
    
    $d_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $rider_col_sel = in_array('rider_collected_amount', $d_cols) ? 'd.rider_collected_amount' : 'NULL as rider_collected_amount';

    $delivery_stmt = $conn->prepare("
        SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, d.created_at as delivery_created_at, {$rider_col_sel}, o.order_date, o.is_ar, o.Customer_ID, c.customer_name, c.phone_number, c.email as customer_email, c.address as customer_address
        FROM delivery d
        INNER JOIN orders o ON d.Order_ID = o.Order_ID
        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
        WHERE {$ownership_condition}
          AND d.delivery_status IN ('Delivered', 'Remitted', 'Completed', 'Delivered (Pending Cash Turnover)')
          AND NOT EXISTS (SELECT 1 FROM sale_source ss WHERE ss.Delivery_ID = d.Delivery_ID AND ss.Sale_ID IS NOT NULL)
        ORDER BY d.created_at ASC
    ");
    $delivery_stmt->execute($params);
    $deliveries = $delivery_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($deliveries)) {
        echo json_encode([
            'success' => true,
            'rider' => ['User_ID' => (int)$rider['User_ID'], 'rider_name' => $rider['rider_name']],
            'deliveries' => [],
            'totals' => ['expected_remittance' => 0.00, 'total_damaged_value' => 0.00, 'amount_to_collect' => 0.00]
        ]);
        exit();
    }
    
    $deliveries_with_items = [];
    $grand_expected = 0;
    $grand_damaged = 0;
    
    foreach ($deliveries as $delivery) {
        $delivery_id = (int)$delivery['Delivery_ID'];
        $order_id = (int)$delivery['Order_ID'];
        
        $items_stmt = $conn->prepare("
            SELECT dd.Delivery_Detail_ID, dd.Order_detail_ID, dd.received_qty, dd.damage_qty, dd.status as delivery_detail_status, od.Product_ID, od.ordered_qty, od.unit_price, p.product_name, {$unit_select}
            FROM delivery_detail dd
            INNER JOIN order_details od ON dd.Order_detail_ID = od.Order_detail_ID
            INNER JOIN products p ON od.Product_ID = p.Product_ID
            {$unit_join}
            WHERE dd.Delivery_ID = ?
        ");
        $items_stmt->execute([$delivery_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            $items_stmt = $conn->prepare("
                SELECT 0 as Delivery_Detail_ID, od.Order_detail_ID, od.ordered_qty as received_qty, 0 as damage_qty, 'Pending' as delivery_detail_status, od.Product_ID, od.ordered_qty, od.unit_price, p.product_name, {$unit_select}
                FROM order_details od
                INNER JOIN products p ON od.Product_ID = p.Product_ID
                {$unit_join}
                WHERE od.Order_ID = ?
            ");
            $items_stmt->execute([$order_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $delivery_expected = 0;
        $delivery_damaged = 0;
        $items_with_calc = [];
        
        foreach ($items as $item) {
            $ordered_qty = floatval($item['ordered_qty'] ?? 0);
            $received_qty = floatval($item['received_qty'] ?? 0);
            
            // Get reported damage from delivery_damage_report table where review status is not rejected
            $dmg_stmt = $conn->prepare("
                SELECT COALESCE(SUM(r.damaged_qty), 0) 
                FROM delivery_damage_report r
                LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                WHERE r.Delivery_ID = ? AND r.Order_detail_ID = ? 
                  AND COALESCE(rev.status, 'pending_review') IN ('pending_review', 'approved')
            ");
            $dmg_stmt->execute([$delivery_id, (int)$item['Order_detail_ID']]);
            $reported_dmg = floatval($dmg_stmt->fetchColumn() ?: 0);
            
            $damage_qty = max(floatval($item['damage_qty'] ?? 0), $reported_dmg);
            $unit_price = floatval($item['unit_price'] ?? 0);
            
            $line_expected = $ordered_qty * $unit_price;
            $line_damaged = $damage_qty * $unit_price;
            $line_collectible = ($ordered_qty - $damage_qty) * $unit_price;
            
            $delivery_expected += $line_expected;
            $delivery_damaged += $line_damaged;
            
            $items_with_calc[] = [
                'delivery_detail_id' => (int)$item['Delivery_Detail_ID'],
                'order_detail_id' => (int)$item['Order_detail_ID'],
                'product_id' => (int)$item['Product_ID'],
                'product_name' => $item['product_name'] ?? 'Unknown Product',
                'unit_name' => $item['unit_name'] ?? 'Unit',
                'ordered_qty' => $ordered_qty,
                'received_qty' => $received_qty,
                'damage_qty' => $damage_qty,
                'unit_price' => $unit_price,
                'line_expected' => round($line_expected, 2),
                'line_damaged_value' => round($line_damaged, 2),
                'line_amount_to_collect' => round($line_collectible, 2)
            ];
        }
        
        $grand_expected += $delivery_expected;
        $grand_damaged += $delivery_damaged;
        
        $rider_col = isset($delivery['rider_collected_amount']) ? (float)$delivery['rider_collected_amount'] : null;

        // Check if any damage reports for this delivery are still pending review
        $has_pending_damage = false;
        try {
            $pd_stmt = $conn->prepare("
                SELECT 1 FROM delivery_damage_report r
                LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                WHERE r.Delivery_ID = ?
                  AND COALESCE(rev.status, 'pending_review') = 'pending_review'
                LIMIT 1
            ");
            $pd_stmt->execute([$delivery_id]);
            $has_pending_damage = (bool)$pd_stmt->fetchColumn();
        } catch (Exception $e) {
            $has_pending_damage = false;
        }

        $deliveries_with_items[] = [
            'Delivery_ID' => $delivery_id,
            'Order_ID' => $order_id,
            'delivery_status' => $delivery['delivery_status'],
            'order_date' => $delivery['order_date'],
            'is_ar' => !empty($delivery['is_ar']),
            'rider_collected_amount' => $rider_col,
            'has_pending_damage' => $has_pending_damage,
            'customer_name' => $delivery['customer_name'] ?? 'N/A',
            'customer_email' => $delivery['customer_email'] ?? '',
            'phone_number' => $delivery['phone_number'] ?? '',
            'customer_address' => $delivery['customer_address'] ?? '',
            'items' => $items_with_calc,
            'delivery_expected' => round($delivery_expected, 2),
            'delivery_damaged_value' => round($delivery_damaged, 2),
            'delivery_amount_to_collect' => round($delivery_expected - $delivery_damaged, 2)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'rider' => ['User_ID' => (int)$rider['User_ID'], 'rider_name' => $rider['rider_name']],
        'deliveries' => $deliveries_with_items,
        'totals' => ['expected_remittance' => round($grand_expected, 2), 'total_damaged_value' => round($grand_damaged, 2), 'amount_to_collect' => round($grand_expected - $grand_damaged, 2)]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('get_rider_deliveries server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}
