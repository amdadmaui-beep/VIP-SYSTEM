<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';

$management_ids = getManagementRoleIds($conn);
requireRole(empty($management_ids) ? [1] : $management_ids);

try {
    $sale_id = intval($_GET['id'] ?? 0);
    if ($sale_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid sale id']);
        exit();
    }

// Detect recorder column in sales (created_by/User_ID/user_id)
$sales_cols = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
$sales_user_col = null;
if (in_array('created_by', $sales_cols, true)) $sales_user_col = 'created_by';
elseif (in_array('User_ID', $sales_cols, true)) $sales_user_col = 'User_ID';
elseif (in_array('user_id', $sales_cols, true)) $sales_user_col = 'user_id';

$rec_select = $sales_user_col ? ", u.user_name as recorded_by" : ", 'N/A' as recorded_by";
$rec_join = $sales_user_col ? "LEFT JOIN user u ON u.User_ID = s.$sales_user_col" : "";

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
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();

    if (!$sale) {
        http_response_code(404);
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
        u.unit_name,
        sd.quantity,
        sd.unit_price,
        sd.subtotal
    FROM sale_details sd
    INNER JOIN products p ON p.Product_ID = sd.Product_ID
    LEFT JOIN units u ON p.unit_id = u.unit_id
    WHERE sd.Sale_ID = ?
    ORDER BY sd.Sale_detail_ID ASC
";
    $stmt = $conn->prepare($items_query);
    $stmt->execute([$sale_id]);
    $res = $stmt->fetchAll();

$items = [];
$total_qty = 0;
$total_amount = 0;
foreach ($res as $row) {
    $qty = round(floatval($row['quantity']), 0);
    $subtotal = floatval($row['subtotal']);
    $items[] = [
        'product_name' => $row['product_name'],
        'unit' => $row['unit_name'] ?? '',
        'quantity' => number_format($qty, 0),
        'unit_price' => number_format(floatval($row['unit_price']), 2),
        'subtotal' => number_format($subtotal, 2),
    ];
    $total_qty += $qty;
    $total_amount += $subtotal;
}

// Get AR/payment info if this sale was posted to AR (partial payment)
$ar_info = null;
    $ar_check = $conn->prepare("SELECT AR_ID, invoice_amount, amount_due, due_date, status 
        FROM account_receivable WHERE Sale_ID = ? LIMIT 1");
    $ar_check->execute([$sale_id]);
    $ar_row = $ar_check->fetch();
  
if ($ar_row) {
    $invoice_amount = floatval($ar_row['invoice_amount']);
    $amount_due = floatval($ar_row['amount_due']);
    $amount_paid = $invoice_amount - $amount_due;
    $ar_info = [
        'invoice_amount' => number_format($invoice_amount, 2),
        'amount_paid' => number_format($amount_paid, 2),
        'amount_due' => number_format($amount_due, 2),
        'due_date' => $ar_row['due_date'],
        'ar_status' => $ar_row['status'],
        'payment_status' => $amount_due <= 0 ? 'Fully Paid' : 'Partial Payment',
        'balance_in_ar' => $amount_due,
    ];
}

    echo json_encode([
        'success' => true,
        'sale' => [
            'sale_id' => intval($sale['Sale_ID']),
            'created_at' => date('M d, Y H:i', strtotime($sale['created_at'])),
            'type' => $type,
            'customer' => $customer,
            'recorded_by' => $recorded_by,
            'ar' => $ar_info,
        ],
        'items' => $items,
        'totals' => [
            'total_qty' => number_format($total_qty, 0),
            'total_amount' => number_format($total_amount, 2),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_sale_details server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
}

?>

