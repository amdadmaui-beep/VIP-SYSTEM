<?php
/**
 * Fetch Customers with Pending AR and their AR Details for Cashier AR Remittance Layout
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Role check: Owner (1), Manager (2/4), Cashier (3)
$user_role = (int)($_SESSION['user_role'] ?? 0);
$allowed_roles = [1, 2, 3, 4];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$customer_id = intval($_GET['customer_id'] ?? 0);

try {
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

    if ($customer_id <= 0) {
        // Return list of customers with their total pending AR balance and counts
        $query = "
            SELECT 
                c.Customer_ID, 
                c.customer_name, 
                COALESCE(SUM(ar.amount_due), 0) as pending_balance,
                COUNT(ar.AR_ID) as pending_count
            FROM customers c
            INNER JOIN account_receivable ar ON c.Customer_ID = ar.Customer_ID
            WHERE ar.amount_due > 0 AND ar.status NOT IN ('Paid', 'Closed')
            GROUP BY c.Customer_ID, c.customer_name
            ORDER BY c.customer_name ASC
        ";
        $stmt = $conn->query($query);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'customers' => $customers
        ]);
        exit;
    } else {
        // Return details for a specific customer
        $cust_stmt = $conn->prepare("SELECT Customer_ID, customer_name, phone_number, address, credit_limit, aging_days FROM customers WHERE Customer_ID = ?");
        $cust_stmt->execute([$customer_id]);
        $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Customer not found']);
            exit;
        }

        // Get outstanding AR records for this customer
        $ar_stmt = $conn->prepare("
            SELECT DISTINCT 
                ar.AR_ID, 
                ar.Sale_ID, 
                ar.invoice_amount, 
                ar.amount_due, 
                ar.due_date, 
                ar.status, 
                ar.invoice_date,
                o.Order_ID, 
                o.order_date
            FROM account_receivable ar
            LEFT JOIN sale_source ss ON ss.Sale_ID = ar.Sale_ID
            LEFT JOIN delivery d ON d.Delivery_ID = ss.Delivery_ID
            LEFT JOIN orders o ON o.Order_ID = d.Order_ID
            WHERE ar.Customer_ID = ? AND ar.amount_due > 0 AND ar.status NOT IN ('Paid', 'Closed')
            ORDER BY ar.invoice_date ASC, ar.AR_ID ASC
        ");
        $ar_stmt->execute([$customer_id]);
        $ar_records = $ar_stmt->fetchAll(PDO::FETCH_ASSOC);

        $records_with_items = [];
        $total_due = 0;

        foreach ($ar_records as $record) {
            $sale_id = (int)$record['Sale_ID'];
            $total_due += floatval($record['amount_due']);

            // Get sale details items
            $items_stmt = $conn->prepare("
                SELECT 
                    sd.Sale_detail_ID,
                    sd.Product_ID, 
                    sd.quantity, 
                    sd.unit_price, 
                    sd.subtotal,
                    p.product_name, 
                    {$unit_select}
                FROM sale_details sd
                INNER JOIN products p ON sd.Product_ID = p.Product_ID
                {$unit_join}
                WHERE sd.Sale_ID = ?
                ORDER BY sd.Sale_detail_ID ASC
            ");
            $items_stmt->execute([$sale_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted_items = [];
            foreach ($items as $item) {
                $formatted_items[] = [
                    'product_name' => $item['product_name'] ?? 'Unknown Product',
                    'unit_name' => $item['unit_name'] ?? 'Unit',
                    'quantity' => floatval($item['quantity'] ?? 0),
                    'unit_price' => floatval($item['unit_price'] ?? 0),
                    'subtotal' => floatval($item['subtotal'] ?? 0)
                ];
            }

            // Get payment history for this AR
            $ar_id = (int)$record['AR_ID'];
            $payments_stmt = $conn->prepare("
                SELECT 
                    p.payment_date, 
                    p.amount_paid, 
                    p.remaining_balance
                FROM ar_payment p
                INNER JOIN singil s ON p.payment_ID = s.Payment_ID
                WHERE s.AR_ID = ?
                ORDER BY p.payment_ID ASC
            ");
            $payments_stmt->execute([$ar_id]);
            $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted_payments = [];
            foreach ($payments as $pay) {
                $formatted_payments[] = [
                    'payment_date' => $pay['payment_date'],
                    'amount_paid' => floatval($pay['amount_paid']),
                    'remaining_balance' => floatval($pay['remaining_balance'])
                ];
            }

            $records_with_items[] = [
                'AR_ID' => $ar_id,
                'Sale_ID' => $sale_id,
                'Order_ID' => $record['Order_ID'] ? (int)$record['Order_ID'] : null,
                'invoice_date' => $record['invoice_date'],
                'due_date' => $record['due_date'],
                'invoice_amount' => floatval($record['invoice_amount']),
                'amount_due' => floatval($record['amount_due']),
                'status' => $record['status'],
                'items' => $formatted_items,
                'payments' => $formatted_payments
            ];
        }

        echo json_encode([
            'success' => true,
            'customer' => $customer,
            'ar_records' => $records_with_items,
            'total_due' => round($total_due, 2)
        ]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_customer_ar_remittance server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
}
