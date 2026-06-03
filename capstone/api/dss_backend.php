<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    $alerts = [];

    // 1. Inventory DSS: If 'Current Stock' < 'Safety Stock'
    $inventoryQuery = "SELECT p.Product_ID, p.product_name, p.safety_stock, 
                              COALESCE(si.quantity, 0) as current_stock
                       FROM products p
                       LEFT JOIN (
                           SELECT si1.Product_ID, si1.quantity
                           FROM stockin_inventory si1
                           INNER JOIN (
                               SELECT Product_ID, MAX(updated_at) as max_updated
                               FROM stockin_inventory
                               GROUP BY Product_ID
                           ) si2 ON si1.Product_ID = si2.Product_ID 
                                 AND si1.updated_at = si2.max_updated
                       ) si ON p.Product_ID = si.Product_ID
                       WHERE p.is_discontinued = 0";
    
    $inventoryResult = $conn->query($inventoryQuery);
    while ($row = $inventoryResult->fetch(PDO::FETCH_ASSOC)) {
        if ($row['current_stock'] < $row['safety_stock']) {
            $alerts[] = [
                'module' => 'Inventory',
                'title' => 'Low Stock Alert',
                'item' => $row['product_name'],
                'status' => 'Below Safety Stock',
                'message' => "Current stock (" . number_format((float)$row['current_stock'], 0) . ") is below safety stock level (" . number_format((float)$row['safety_stock'], 0) . ").",
                'recommendation' => "Start a production batch for {$row['product_name']} immediately.",
                'severity' => 'danger'
            ];
        }
    }

    // 2. Credit Risk DSS: If 'Total Unpaid Balance' > 'Credit Limit'
    $creditQuery = "SELECT c.Customer_ID, c.customer_name, c.credit_limit,
                           COALESCE(SUM(ar.amount_due), 0) as total_unpaid,
                           COUNT(CASE WHEN DATEDIFF(CURDATE(), ar.due_date) > 0 THEN 1 END) as overdue_count,
                           MAX(DATEDIFF(CURDATE(), ar.due_date)) as max_days_overdue
                     FROM customers c
                     LEFT JOIN account_receivable ar ON c.Customer_ID = ar.Customer_ID AND ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0
                     WHERE c.deleted_at IS NULL
                     GROUP BY c.Customer_ID";
    
    $creditResult = $conn->query($creditQuery);
    while ($row = $creditResult->fetch(PDO::FETCH_ASSOC)) {
        $total_unpaid = floatval($row['total_unpaid']);
        $credit_limit = floatval($row['credit_limit']);
        $overdue_count = intval($row['overdue_count']);
        $max_days = intval($row['max_days_overdue']);

        if ($total_unpaid > $credit_limit) {
            $alerts[] = [
                'module' => 'Credit Risk',
                'title' => 'Credit Limit Exceeded',
                'item' => $row['customer_name'],
                'status' => 'High Risk',
                'message' => "Total unpaid balance (₱" . number_format($total_unpaid, 2) . ") exceeds credit limit (₱" . number_format($credit_limit, 2) . ").",
                'recommendation' => "Disable 'Credit Sale' and switch to 'Cash-Only' transactions for this dealer until payment is received.",
                'severity' => 'danger'
            ];
        } elseif ($total_unpaid > ($credit_limit * 0.9) && $credit_limit > 0) {
            $alerts[] = [
                'module' => 'Credit Risk',
                'title' => 'Near Credit Limit',
                'item' => $row['customer_name'],
                'status' => 'Caution',
                'message' => "Customer has used " . round(($total_unpaid / $credit_limit) * 100) . "% of their ₱" . number_format($credit_limit, 2) . " limit.",
                'recommendation' => "Advise customer of remaining credit and request partial payment before next large order.",
                'severity' => 'warning'
            ];
        }

        if ($overdue_count > 0) {
            $severity = 'info';
            $rec = "Send a payment reminder.";
            if ($max_days > 90) {
                $severity = 'danger';
                $rec = "Severe delinquency! Send final demand letter and suspend deliveries.";
            } elseif ($max_days > 60) {
                $severity = 'danger';
                $rec = "High delinquency. Escalate collection efforts and call dealer directly.";
            } elseif ($max_days > 30) {
                $severity = 'warning';
                $rec = "Moderate delinquency. Send formal overdue notice.";
            }

            $alerts[] = [
                'module' => 'Accounts Receivable',
                'title' => 'Overdue Invoices',
                'item' => $row['customer_name'],
                'status' => "{$overdue_count} Overdue Invoice(s)",
                'message' => "Customer has {$overdue_count} unpaid invoices past due date. Oldest is {$max_days} days overdue.",
                'recommendation' => $rec,
                'severity' => $severity
            ];
        }
    }

    // 3. Production DSS: If 'Forecasted Demand' > 'Current Inventory'
    // Simplified Forecast: Average daily sales of the last 7 days
    $productionQuery = "SELECT p.Product_ID, p.product_name,
                               COALESCE(si.quantity, 0) as current_stock,
                               COALESCE(forecast.avg_daily_sales, 0) as forecasted_demand
                        FROM products p
                        LEFT JOIN (
                            SELECT si1.Product_ID, si1.quantity
                            FROM stockin_inventory si1
                            INNER JOIN (
                                SELECT Product_ID, MAX(updated_at) as max_updated
                                FROM stockin_inventory
                                GROUP BY Product_ID
                            ) si2 ON si1.Product_ID = si2.Product_ID 
                                  AND si1.updated_at = si2.max_updated
                        ) si ON p.Product_ID = si.Product_ID
                        LEFT JOIN (
                            SELECT Product_ID, SUM(quantity) / 7 as avg_daily_sales
                            FROM sale_details
                            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            GROUP BY Product_ID
                        ) forecast ON p.Product_ID = forecast.Product_ID
                        WHERE p.is_discontinued = 0";

    $productionResult = $conn->query($productionQuery);
    while ($row = $productionResult->fetch(PDO::FETCH_ASSOC)) {
        if ($row['forecasted_demand'] > $row['current_stock']) {
            $shortfall = ceil($row['forecasted_demand'] - $row['current_stock']);
            $alerts[] = [
                'module' => 'Production',
                'title' => 'Shortfall Warning',
                'item' => $row['product_name'],
                'status' => 'Insufficient Stock for Demand',
                'message' => "Forecasted demand for tomorrow (" . number_format((float)$row['forecasted_demand'], 0) . ") exceeds current inventory (" . number_format((float)$row['current_stock'], 0) . ").",
                'recommendation' => "Produce at least {$shortfall} more blocks to meet expected demand.",
                'severity' => 'info'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $alerts
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
