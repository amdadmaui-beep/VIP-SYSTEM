<?php
// Disable error display and start output buffering immediately
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering FIRST to catch any output
ob_start();

// Set JSON header
header('Content-Type: application/json');

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication without redirecting (for API endpoints)
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Suppress any output from db.php
ob_start();
try {
    require_once '../includes/db.php';
    // Clear any output from db.php
    ob_end_clean();
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
} catch (Error $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// Check if connection was established
if (!isset($conn) || !$conn) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$order_id = intval($_GET['order_id'] ?? 0);

if (!$order_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit();
}

// Get order information with customer (exclude cancelled orders)
$order_query = "SELECT o.Order_ID, o.order_date, c.customer_name, o.order_status
                FROM orders o 
                LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID 
                WHERE o.Order_ID = ? AND o.order_status != 'Cancelled' AND o.order_status != 'cancelled'";
$stmt = $conn->prepare($order_query);

if (!$stmt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $order_id);
if (!$stmt->execute()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $stmt->error]);
    $stmt->close();
    exit();
}
$order_result = $stmt->get_result();

if ($order_result->num_rows === 0) {
    $stmt->close();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Order not found or has been cancelled']);
    exit();
}

$order = $order_result->fetch_assoc();
$stmt->close();

// Format order info
$order_info = 'Order #' . $order['Order_ID'];
if (!empty($order['customer_name'])) {
    $order_info .= ' – ' . htmlspecialchars($order['customer_name']);
}
$order_info .= ' – ' . date('M d, Y', strtotime($order['order_date']));

// Get order items and calculate total required quantity in bags
$items_query = "SELECT od.ordered_qty, od.Product_ID, p.product_name, p.form, p.unit 
               FROM order_details od 
               LEFT JOIN products p ON od.Product_ID = p.Product_ID 
               WHERE od.Order_ID = ?";
$stmt = $conn->prepare($items_query);

if (!$stmt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $order_id);
if (!$stmt->execute()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $stmt->error]);
    $stmt->close();
    exit();
}
$items_result = $stmt->get_result();

$total_quantity = 0;
$order_items = [];
while ($item = $items_result->fetch_assoc()) {
    // Sum up quantities (assuming they're in the same unit)
    $total_quantity += floatval($item['ordered_qty']);
    
    // Store item details for display
    $order_items[] = [
        'product_id' => intval($item['Product_ID']),
        'product_name' => $item['product_name'] ?? 'Unknown Product',
        'form' => $item['form'] ?? '-',
        'unit' => $item['unit'] ?? '-',
        'quantity' => floatval($item['ordered_qty'])
    ];
}
$stmt->close();

// Get already produced quantity for this order
$produced_query = "SELECT COALESCE(SUM(number_of_bags), 0) as total_bags 
                  FROM productions 
                  WHERE Order_ID = ? AND production_type = 'orders'";
$stmt = $conn->prepare($produced_query);

if (!$stmt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $order_id);
if (!$stmt->execute()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $stmt->error]);
    $stmt->close();
    exit();
}
$produced_result = $stmt->get_result();
$produced_data = $produced_result->fetch_assoc();
$produced_bags = intval($produced_data['total_bags']);
$stmt->close();

// For now, we'll assume the order quantity is in bags
// If not, you may need to adjust based on your business logic
$required_bags = intval($total_quantity); // Assuming quantity is already in bags
$remaining_bags = max(0, $required_bags - $produced_bags);

// Clean any output buffer and send JSON
$output = ob_get_clean();
if (!empty($output) && !json_decode($output)) {
    // If there's unexpected output, log it but still send JSON
    error_log("Unexpected output in get_order_details.php: " . substr($output, 0, 200));
}

echo json_encode([
    'success' => true,
    'order_info' => $order_info,
    'required_bags' => $required_bags,
    'produced_bags' => $produced_bags,
    'remaining_bags' => $remaining_bags,
    'items' => $order_items
]);

if (isset($conn)) {
    $conn->close();
}
exit();
?>
