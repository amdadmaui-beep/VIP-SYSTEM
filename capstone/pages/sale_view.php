<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

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
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

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
$items_res = $stmt->get_result();
$items = [];
$total_qty = 0;
$total_amount = 0;
while ($row = $items_res->fetch_assoc()) {
    $items[] = $row;
    $total_qty += floatval($row['quantity']);
    $total_amount += floatval($row['subtotal']);
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale #<?php echo intval($sale_id); ?> - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .card { background:#fff; border-radius:10px; padding:1.5rem; box-shadow:0 2px 4px rgba(0,0,0,0.08); margin-bottom: 1rem; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .k { color:#6b7280; font-size: 0.9rem; }
        .v { font-weight:600; }
        .table { width:100%; border-collapse: collapse; }
        .table th, .table td { padding:0.75rem; border-bottom:1px solid #e5e7eb; }
        .table th { background:#f9fafb; text-align:left; }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap: 1rem; margin-bottom: 1rem; }
        .btn { padding:0.5rem 1rem; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-flex; gap:0.5rem; align-items:center; }
        .btn-secondary { background:#6b7280; color:#fff; }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <main class="main-content" style="width:100%;">
        <div class="container">
            <div class="topbar">
                <div>
                    <h1 style="margin:0;"><i class="fas fa-receipt"></i> Sale #<?php echo intval($sale_id); ?></h1>
                    <p style="margin:0.25rem 0 0 0; color:#6b7280;"><?php echo htmlspecialchars($sale_type); ?></p>
                </div>
                <a class="btn btn-secondary" href="sales.php"><i class="fas fa-arrow-left"></i> Back to Sales</a>
            </div>

            <div class="card">
                <div class="grid">
                    <div>
                        <div class="k">Customer</div>
                        <div class="v"><?php echo htmlspecialchars($customer_display); ?></div>
                    </div>
                    <div>
                        <div class="k">Date</div>
                        <div class="v"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($sale['created_at']))); ?></div>
                    </div>

                    <div>
                        <div class="k">Delivery</div>
                        <div class="v"><?php echo $is_delivery_sale ? ('#' . intval($sale['Delivery_ID'])) : 'N/A'; ?></div>
                    </div>
                    <div>
                        <div class="k">Order</div>
                        <div class="v"><?php echo !empty($sale['Order_ID']) ? ('#' . intval($sale['Order_ID'])) : 'N/A'; ?></div>
                    </div>

                    <div>
                        <div class="k">Delivered By</div>
                        <div class="v"><?php echo htmlspecialchars($sale['delivered_by'] ?? 'N/A'); ?></div>
                    </div>
                    <div>
                        <div class="k">Delivered To</div>
                        <div class="v"><?php echo htmlspecialchars($sale['delivered_to'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-top:0;"><i class="fas fa-list"></i> Items Sold</h3>
                <table class="table">
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
                            if (!empty($it['form'])) $pname .= " ({$it['form']})";
                            if (!empty($it['unit'])) $pname .= " {$it['unit']}";
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pname); ?></td>
                                <td><strong><?php echo number_format(floatval($it['quantity']), 2); ?></strong></td>
                                <td>₱<?php echo number_format(floatval($it['unit_price']), 2); ?></td>
                                <td><strong>₱<?php echo number_format(floatval($it['subtotal']), 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="color:#6b7280;">No sale_details found for this sale.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style="text-align:right;"><strong>Totals</strong></td>
                            <td><strong><?php echo number_format($total_qty, 2); ?></strong></td>
                            <td></td>
                            <td><strong>₱<?php echo number_format($total_amount, 2); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
<?php $conn->close(); ?>

