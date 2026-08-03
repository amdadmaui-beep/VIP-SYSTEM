<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/module_access.php';

// Accessible to Owner (1), Cashier (2), and Manager (4)
if (!isset($_SESSION['user_role']) || !in_array((int)$_SESSION['user_role'], [1, 2, 3])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$query = trim($_GET['q'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$can_delivery_orders = isModuleAllowedForUser($conn, $uid, 'cashier_delivery_orders_sales', true);
$can_ar_sales = isModuleAllowedForUser($conn, $uid, 'cashier_ar_sales', true);

if (!$can_delivery_orders && !$can_ar_sales) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Search access is restricted for your account.']);
    exit();
}

if (strlen($query) < 1) {
    echo json_encode(['success' => true, 'data' => []]);
    exit();
}

// Strip AR- prefix if present for ID search
$clean_query = preg_replace('/^AR-/i', '', $query);
$is_ar_search = preg_match('/^AR-/i', $query);

try {
    // Detect order status column
    $order_status_col = 'order_status';
    $cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if ($cols_res && $cols_res->rowCount() > 0) {
        $row = $cols_res->fetch(PDO::FETCH_ASSOC);
        $order_status_col = $row['Field'];
    }

    // Search for remitted delivery orders not yet sold, plus AR records for follow-up collections.
    // We search across Order_ID and Customer_Name
    $search_sql = "SELECT 
                        d.Delivery_ID, 
                        o.Order_ID, 
                        c.customer_name, 
                        d.delivered_to,
                        o.order_date,
                        d.delivery_status,
                        ar.AR_ID,
                        ar.amount_due,
                        o.Customer_ID,
                        ss.Sale_ID
                    FROM delivery d
                    INNER JOIN orders o ON d.Order_ID = o.Order_ID
                    LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                    LEFT JOIN sale_source ss ON d.Delivery_ID = ss.Delivery_ID
                    LEFT JOIN sales s ON ss.Sale_ID = s.Sale_ID
                    LEFT JOIN account_receivable ar ON s.Sale_ID = ar.Sale_ID
                     WHERE d.delivery_status IN ('Remitted', 'Completed', 'Delivered (Pending Cash Turnover)')
                      AND (
                        -- Either it hasn't been sold yet
                        " . ($can_delivery_orders ? "(d.delivery_status IN ('Remitted', 'Completed', 'Delivered (Pending Cash Turnover)') AND NOT EXISTS (SELECT 1 FROM sale_source ss2 WHERE ss2.Delivery_ID = d.Delivery_ID AND ss2.Sale_ID IS NOT NULL))" : "0=1") . "
                        -- OR the user is specifically searching for an AR ID or it has one
                        OR " . ($can_ar_sales ? "ar.AR_ID IS NOT NULL" : "0=1") . "
                      )
                      AND (o.Order_ID LIKE ? OR c.customer_name LIKE ? OR d.delivered_to LIKE ? OR ar.AR_ID LIKE ? OR CAST(ar.AR_ID AS CHAR) LIKE ?)
                   ORDER BY (ar.AR_ID IS NOT NULL) DESC, COALESCE(d.actual_date_arrived, d.created_at) DESC
                   LIMIT 10";

    $stmt = $conn->prepare($search_sql);
    $search_term = "%$query%";
    $clean_search_term = "%$clean_query%";
    $stmt->execute([$search_term, $search_term, $search_term, $search_term, $clean_search_term]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each result, fetch item details
    $data = [];
    foreach ($results as $row) {
        // Fetch items from order_details and link to delivery_detail if possible
        $items_stmt = $conn->prepare("SELECT 
                                        p.Product_ID, 
                                        p.product_name, 
                                        od.ordered_qty as ordered_qty,
                                        COALESCE(dd.received_qty, od.ordered_qty) as received_qty, 
                                        od.unit_price,
                                        u.unit_name,
                                        od.Order_detail_ID,
                                        dd.Delivery_Detail_ID
                                     FROM order_details od
                                     INNER JOIN products p ON od.Product_ID = p.Product_ID
                                     LEFT JOIN units u ON p.unit_id = u.unit_id
                                     LEFT JOIN delivery_detail dd ON dd.Order_detail_ID = od.Order_detail_ID AND dd.Delivery_ID = ?
                                     WHERE od.Order_ID = ?");
        $items_stmt->execute([$row['Delivery_ID'], $row['Order_ID']]);
        $row['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        $data[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('pos_order_search error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while searching.']);
}
?>
