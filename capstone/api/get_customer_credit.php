<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once '../includes/db.php';

try {
    $customer_id = intval($_GET['customer_id'] ?? 0);

    if ($customer_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
        exit;
    }

    $query = "SELECT c.customer_name, c.credit_limit, 
                    COALESCE(SUM(ar.amount_due), 0) as total_unpaid
            FROM customers c
            LEFT JOIN account_receivable ar ON c.Customer_ID = ar.Customer_ID AND ar.status != 'Paid'
            WHERE c.Customer_ID = ?
            GROUP BY c.Customer_ID";

    $stmt = $conn->prepare($query);
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch();

    if ($row) {
        $is_over_limit = $row['total_unpaid'] > $row['credit_limit'];
        echo json_encode([
            'success' => true,
            'data' => [
                'customer_name' => $row['customer_name'],
                'credit_limit' => floatval($row['credit_limit']),
                'total_unpaid' => floatval($row['total_unpaid']),
                'is_over_limit' => $is_over_limit,
                'recommendation' => $is_over_limit ? 'Credit Limit Exceeded! Recommendation: Cash-Only transaction.' : ''
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_customer_credit server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
}
?>
