<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../includes/db.php';

$sale_id = intval($_GET['id'] ?? 0);
if ($sale_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale id']);
    exit();
}

// Detect recorder column in sales (created_by/User_ID/user_id)
$sales_cols = [];
$cr = $conn->query("SHOW COLUMNS FROM sales");
if ($cr) {
    while ($r = $cr->fetch_assoc()) $sales_cols[] = $r['Field'];
    $cr->close();
}
$sales_user_col = null;
if (in_array('created_by', $sales_cols, true)) $sales_user_col = 'created_by';
elseif (in_array('User_ID', $sales_cols, true)) $sales_user_col = 'User_ID';
elseif (in_array('user_id', $sales_cols, true)) $sales_user_col = 'user_id';

$rec_select = $sales_user_col ? ", u.user_name as recorded_by" : ", 'N/A' as recorded_by";
$rec_join = $sales_user_col ? "LEFT JOIN app_users u ON u.User_ID = s.$sales_user_col" : "";

// Header
$sale_query = "
    SELECT 
        s.Sale_ID,
        s.created_at
        $rec_select,
        ss.Delivery_ID,
        d.Order_ID,
        d.delivered_to,
        o.Customer_ID,
        c.customer_name
    FROM sales s
    $rec_join
    LEFT JOIN sale_source ss ON ss.Sale_ID = s.Sale_ID
    LEFT JOIN delivery d ON d.Delivery_ID = ss.Delivery_ID
    LEFT JOIN orders o ON o.Order_ID = d.Order_ID
    LEFT JOIN customers c ON c.Customer_ID = o.Customer_ID
    WHERE s.Sale_ID = ?
    LIMIT 1
";
$stmt = $conn->prepare($sale_query);
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    echo json_encode(['success' => false, 'message' => 'Sale not found']);
    exit();
}

$is_delivery_sale = !empty($sale['Delivery_ID']);
$type = $is_delivery_sale ? 'Pre-Order (Wholesale)' : 'Walk-in (Retail)';
$customer = $sale['customer_name'] ?: ($sale['delivered_to'] ?: 'Walk-in Customer');
$recorded_by = $sale['recorded_by'] ?? 'N/A';

// Items
$items_query = "
    SELECT 
        p.product_name,
        p.form,
        p.unit,
        sd.quantity,
        sd.unit_price,
        sd.subtotal
    FROM sale_details sd
    INNER JOIN products p ON p.Product_ID = sd.Product_ID
    WHERE sd.Sale_ID = ?
    ORDER BY sd.Sale_detail_ID ASC
";
$stmt = $conn->prepare($items_query);
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
$total_qty = 0;
$total_amount = 0;
while ($row = $res->fetch_assoc()) {
    $qty = round(floatval($row['quantity']), 0);
    $subtotal = floatval($row['subtotal']);
    $items[] = [
        'product_name' => $row['product_name'],
        'form' => $row['form'] ?? '',
        'unit' => $row['unit'] ?? '',
        'quantity' => number_format($qty, 0),
        'unit_price' => number_format(floatval($row['unit_price']), 2),
        'subtotal' => number_format($subtotal, 2),
    ];
    $total_qty += $qty;
    $total_amount += $subtotal;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'sale' => [
        'sale_id' => intval($sale['Sale_ID']),
        'created_at' => date('M d, Y H:i', strtotime($sale['created_at'])),
        'type' => $type,
        'customer' => $customer,
        'recorded_by' => $recorded_by,
    ],
    'items' => $items,
    'totals' => [
        'total_qty' => number_format($total_qty, 0),
        'total_amount' => number_format($total_amount, 2),
    ],
]);

$conn->close();
?>

