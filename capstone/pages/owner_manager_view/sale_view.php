<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Accessible to Owner (1), Cashier, and Manager (2, 4)
requireRole([1, 2, 4]);

$sale_id = intval($_GET['id'] ?? 0);
if ($sale_id <= 0) {
    header("Location: sales.php?error=Invalid sale ID");
    exit();
}

// Fetch sale header + source (walk-in vs delivery)
$sale_query = "
    SELECT 
        s.Sale_ID,
        s.created_at,
        s.updated_at,
        ss.Delivery_ID,
        d.Order_ID,
        d.delivered_to,
        d.delivered_by,
        o.Customer_ID,
        c.customer_name
    FROM sales s
    LEFT JOIN sale_source ss ON ss.Sale_ID = s.Sale_ID
    LEFT JOIN delivery d ON d.Delivery_ID = ss.Delivery_ID
    LEFT JOIN orders o ON o.Order_ID = d.Order_ID
    LEFT JOIN customers c ON c.Customer_ID = o.Customer_ID
    WHERE s.Sale_ID = ?
    LIMIT 1
";
$stmt = $conn->prepare($sale_query);
$stmt->execute([$sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    header("Location: sales.php?error=Sale not found");
    exit();
}

$is_delivery_sale = !empty($sale['Delivery_ID']);
$sale_type = $is_delivery_sale ? 'Pre-Order (Wholesale)' : 'Walk-in (Retail)';
$customer_display = $sale['customer_name'] ?: ($sale['delivered_to'] ?: 'Walk-in Customer');

// Fetch sale line items
$items_query = "
    SELECT 
        sd.Product_ID,
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
$items = [];
$total_qty = 0;
$total_amount = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = $row;
    $total_qty += floatval($row['quantity']);
    $total_amount += floatval($row['subtotal']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale #<?php echo intval($sale_id); ?> - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .invoice-wrapper {
            width: 100%;
            max-width: 820px;
        }
        .invoice {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 24px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .invoice-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            padding: 1.75rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .invoice-header-left h1 {
            color: #fff;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .invoice-header-left h1 i {
            margin-right: 0.5rem;
            opacity: 0.85;
        }
        .invoice-type-badge {
            display: inline-block;
            background: rgba(255,255,255,0.18);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            margin-top: 0.4rem;
            letter-spacing: 0.02em;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.15);
            color: #fff;
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.2s ease;
        }
        .btn-back:hover {
            background: rgba(255,255,255,0.25);
        }
        .invoice-body {
            padding: 2rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem 2rem;
            padding-bottom: 1.75rem;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1.75rem;
        }
        .info-group {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .info-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #111827;
        }
        .info-value.muted {
            font-weight: 400;
            color: #6b7280;
        }
        .items-section h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .items-section h2 i {
            color: #4f46e5;
            font-size: 0.9rem;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table thead th {
            background: #f9fafb;
            padding: 0.7rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }
        .items-table thead th:last-child,
        .items-table tbody td:last-child,
        .items-table tfoot td:last-child {
            text-align: right;
        }
        .items-table thead th:nth-child(2),
        .items-table tbody td:nth-child(2) {
            text-align: center;
        }
        .items-table thead th:nth-child(3),
        .items-table tbody td:nth-child(3) {
            text-align: right;
        }
        .items-table tbody td {
            padding: 0.85rem 1rem;
            font-size: 0.9rem;
            color: #374151;
            border-bottom: 1px solid #f3f4f6;
        }
        .items-table tbody tr:last-child td {
            border-bottom: none;
        }
        .items-table tbody tr:hover {
            background: #fafbff;
        }
        .items-table tfoot td {
            padding: 0.85rem 1rem;
            font-size: 0.9rem;
            border-top: 2px solid #e5e7eb;
            background: #fafbff;
        }
        .items-table tfoot td strong {
            font-weight: 700;
        }
        .total-row td {
            font-weight: 700;
            color: #111827;
        }
        .total-row .total-amount {
            font-size: 1.05rem;
            color: #4f46e5;
        }
        .invoice-footer {
            background: #f9fafb;
            padding: 1rem 2rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 2rem;
        }
        .footer-stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #6b7280;
        }
        .footer-stat i {
            color: #4f46e5;
        }
        @media (max-width: 640px) {
            body { padding: 1rem 0.5rem; }
            .invoice-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .info-grid { grid-template-columns: 1fr; gap: 1rem; }
            .invoice-body { padding: 1.25rem; }
            .items-table thead th,
            .items-table tbody td,
            .items-table tfoot td { padding: 0.6rem 0.5rem; font-size: 0.8rem; }
        }
    </style>
</head>
<body>
<div class="invoice-wrapper">
    <div class="invoice">
        <div class="invoice-header">
            <div class="invoice-header-left">
                <h1><i class="fas fa-receipt"></i> Sale #<?php echo intval($sale_id); ?></h1>
                <span class="invoice-type-badge"><?php echo htmlspecialchars($sale_type); ?></span>
            </div>
            <a class="btn-back" href="sales.php"><i class="fas fa-arrow-left"></i> Back to Sales</a>
        </div>

        <div class="invoice-body">
            <div class="info-grid">
                <div class="info-group">
                    <span class="info-label"><i class="fas fa-user"></i> Customer</span>
                    <span class="info-value"><?php echo htmlspecialchars($customer_display); ?></span>
                </div>
                <div class="info-group">
                    <span class="info-label"><i class="fas fa-calendar"></i> Date</span>
                    <span class="info-value"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($sale['created_at']))); ?></span>
                </div>
                <div class="info-group">
                    <span class="info-label"><i class="fas fa-truck"></i> Delivery</span>
                    <span class="info-value <?php echo !$is_delivery_sale ? 'muted' : ''; ?>"><?php echo $is_delivery_sale ? ('#' . intval($sale['Delivery_ID'])) : 'N/A'; ?></span>
                </div>
                <div class="info-group">
                    <span class="info-label"><i class="fas fa-shopping-cart"></i> Order</span>
                    <span class="info-value <?php echo empty($sale['Order_ID']) ? 'muted' : ''; ?>"><?php echo !empty($sale['Order_ID']) ? ('#' . intval($sale['Order_ID'])) : 'N/A'; ?></span>
                </div>
                <div class="info-group">
                    <span class="info-label"><i class="fas fa-user-check"></i> Delivered By</span>
                    <span class="info-value <?php echo empty($sale['delivered_by']) ? 'muted' : ''; ?>"><?php echo htmlspecialchars($sale['delivered_by'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-group">
                    <span class="info-label"><i class="fas fa-user-plus"></i> Delivered To</span>
                    <span class="info-value <?php echo empty($sale['delivered_to']) ? 'muted' : ''; ?>"><?php echo htmlspecialchars($sale['delivered_to'] ?? 'N/A'); ?></span>
                </div>
            </div>

            <div class="items-section">
                <h2><i class="fas fa-box"></i> Items Sold</h2>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $it): 
                            $pname = $it['product_name'];
                            if (!empty($it['unit_name'])) $pname .= " {$it['unit_name']}";
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pname); ?></td>
                                <td><?php echo number_format(floatval($it['quantity']), 0); ?></td>
                                <td>₱<?php echo number_format(floatval($it['unit_price']), 2); ?></td>
                                <td><strong>₱<?php echo number_format(floatval($it['subtotal']), 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="color:#9ca3af; text-align:center; padding:2rem;">No items found for this sale.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td><strong>Total</strong></td>
                            <td><strong><?php echo number_format($total_qty, 0); ?></strong></td>
                            <td></td>
                            <td class="total-amount"><strong>₱<?php echo number_format($total_amount, 2); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="invoice-footer">
            <span class="footer-stat"><i class="fas fa-hashtag"></i> Sale #<?php echo intval($sale_id); ?></span>
            <span class="footer-stat"><i class="far fa-clock"></i> Recorded <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($sale['created_at']))); ?></span>
        </div>
    </div>
</div>
</body>
</html>


