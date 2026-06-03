<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_once __DIR__ . '/../../includes/roles_helper.php';
$dashboard_ids = getDashboardRoleIds($conn);
$inv_ids = getInventoryStaffRoleIds($conn);
$allowed = array_unique(array_merge($dashboard_ids, $inv_ids));
requireRole(empty($allowed) ? [1] : $allowed);
$is_inventory_staff = in_array((int)($_SESSION['user_role'] ?? 0), $inv_ids);
$can_inv_record_production = isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_record_production', true);
$can_inv_production_history = isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_production_history', true);

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Production recorded successfully!";
}

// Include backend for POST handling
require_once __DIR__ . '/../../api/production_backend.php';

// Default production date: use server's local date; JS will also normalize to browser local
$today_date = date('Y-m-d');

// Fetch products for dropdown
$products_query = "SELECT p.Product_ID, p.product_name, u.unit_name 
                   FROM products p 
                   LEFT JOIN units u ON p.unit_id = u.unit_id 
                   WHERE p.is_discontinued = 0 
                   ORDER BY u.unit_name, p.product_name";
$products_result = $conn->query($products_query);

// Fetch orders for dropdown with customer info (exclude cancelled orders)
$orders_query = "SELECT o.Order_ID, o.order_date, c.customer_name 
                 FROM orders o 
                 LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID 
                 WHERE o.order_status != 'Cancelled' AND o.order_status != 'cancelled'
                 ORDER BY o.order_date DESC, o.Order_ID DESC 
                 LIMIT 100";
$orders_result = $conn->query($orders_query);

// Fetch production history
$history_query = "SELECT 
    si.Inventory_ID as Production_ID,
    si.date_in as production_date,
    si.production_type,
    si.bag_size,
    pr.product_name,
    u_units.unit_name,
    u.user_name as handled_by,
    il.quantity_change as produced_qty
FROM stockin_inventory si
INNER JOIN products pr ON si.Product_ID = pr.Product_ID
LEFT JOIN units u_units ON pr.unit_id = u_units.unit_id
LEFT JOIN user u ON si.handled_by = u.User_ID
LEFT JOIN inventory_ledger il ON si.Inventory_ID = il.transaction_id AND il.transaction_type = 'STOCK IN'
ORDER BY si.date_in DESC, si.Inventory_ID DESC
LIMIT 100";
$history_result = $conn->query($history_query);
$history_data = [];
if ($history_result) {
    while ($row = $history_result->fetch(PDO::FETCH_ASSOC)) {
        $history_data[] = $row;
    }
} else {
    error_log("Error fetching production history");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --rider-bg: #f8fafc;
            --rider-card: #ffffff;
            --rider-primary: #7c3aed; /* Purple theme */
            --rider-secondary: #4f46e5;
            --rider-text: #1e293b;
            --rider-muted: #64748b;
        }

        body.rider-theme {
            background-color: var(--rider-bg);
            font-family: 'Poppins', sans-serif;
            color: var(--rider-text);
        }

        .staff-header-card {
            background: var(--rider-card);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .staff-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .staff-avatar {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: var(--rider-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .staff-info h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .staff-info p {
            margin: 0;
            font-size: 0.85rem;
            color: var(--rider-muted);
        }

        .btn-logout-minimal {
            background: #f1f5f9;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 10px;
            color: var(--rider-muted);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }

        .nav-tabs-rider {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .nav-tab-rider {
            padding: 0.6rem 1.25rem;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 700;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: transparent;
            color: var(--rider-muted);
            border: none;
        }

        .nav-tab-rider.active {
            background: var(--rider-secondary);
            color: white;
        }

        .stats-grid-rider {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-box-rider {
            background: var(--rider-card);
            border-radius: 16px;
            padding: 1.25rem;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.02);
            border: 1px solid #f1f5f9;
        }

        .stat-box-rider .value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--rider-primary);
            margin-bottom: 0.25rem;
        }

        .stat-box-rider .label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--rider-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-action-rider {
            width: 100%;
            background: var(--rider-primary);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
        }

        .section-header-rider {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            font-weight: 800;
            color: #1e293b;
        }

        .production-card {
            background: var(--rider-card);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            border: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .prod-info h4 {
            margin: 0 0 0.25rem 0;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .prod-info p {
            margin: 0;
            font-size: 0.75rem;
            color: var(--rider-muted);
        }

        .prod-badge {
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            background: #f0fdf4;
            color: #16a34a;
        }

        .prod-type {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        @media (max-width: 768px) {
            .main-content { padding: 1rem !important; margin-left: 0 !important; }
            .sidebar { display: none; }
        }

        /* Modal specific mobile refinements */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-content {
            background: white;
            border-radius: 24px;
            padding: 2rem;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="rider-theme">
<div class="dashboard-wrapper">
    <!-- Main Content -->
    <main class="main-content" style="max-width: 600px; margin: 0 auto; padding: 1.5rem;">
        <!-- Staff Profile Card -->
        <?php
        $display_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Staff';
        $user_role_name = $is_inventory_staff ? 'Inventory Staff' : 'Management';
        ?>
        <header class="staff-header-card">
            <div class="staff-profile">
                <div class="staff-avatar">
                    <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                </div>
                <div class="staff-info">
                    <p style="text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: #94a3b8; font-size: 0.65rem; margin-bottom: 2px;">Production Log</p>
                    <h2><?php echo htmlspecialchars($display_name); ?></h2>
                    <p><?php echo htmlspecialchars($user_role_name); ?> · VIP Ice Plant</p>
                </div>
            </div>
            <button class="btn-logout-minimal" onclick="location.href='../logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </header>

        <!-- Navigation Tabs -->
        <div class="nav-tabs-rider">
            <button class="nav-tab-rider active">
                <i class="fas fa-industry"></i> Production
            </button>
            <button class="nav-tab-rider" onclick="location.href='manual_adjustment.php'">
                <i class="fas fa-adjust"></i> Adjustment
            </button>
            <button class="nav-tab-rider" onclick="location.href='inventory.php'">
                <i class="fas fa-boxes"></i> Inventory
            </button>
        </div>

        <?php if (isset($success)): ?>
            <div style="background: #f0fdf4; color: #166534; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; border: 1px solid #bbf7d0;">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div style="background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.875rem; border: 1px solid #fecaca;">
                <i class="fas fa-exclamation-triangle"></i>
                <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <?php
        $today_total_produced = 0;
        $today_count = 0;
        foreach ($history_data as $h) {
            $p_date = isset($h['production_date']) ? substr($h['production_date'],0,10) : '';
            if ($p_date === date('Y-m-d')) {
                $today_total_produced += (float)($h['produced_qty'] ?? 0);
                $today_count++;
            }
        }
        ?>
        <div class="stats-grid-rider">
            <div class="stat-box-rider">
                <div class="value"><?php echo number_format($today_count); ?></div>
                <div class="label">Runs Today</div>
            </div>
            <div class="stat-box-rider">
                <div class="value"><?php echo number_format($today_total_produced, 0); ?></div>
                <div class="label">Total Quantity</div>
            </div>
        </div>

        <?php if ($can_inv_record_production): ?>
        <button class="btn-action-rider" onclick="openModal()">
            <i class="fas fa-plus-circle"></i> Record new production
        </button>
        <?php endif; ?>

        <div class="section-header-rider">
            <i class="far fa-clock"></i> Recent Activity
        </div>

        <?php if ($can_inv_production_history): ?>
        <div class="production-list">
            <?php if (!empty($history_data)): ?>
                <?php foreach (array_slice($history_data, 0, 15) as $row): 
                    $type_label = ($row['production_type'] === 'stockin') ? 'For Stock' : 'Recorded';
                ?>
                    <div class="production-card">
                        <div class="prod-info">
                            <h4><?php echo htmlspecialchars($row['product_name']); ?></h4>
                            <p><?php echo date('M j, g:i A', strtotime($row['production_date'])); ?></p>
                            <span class="prod-type"><?php echo $type_label; ?></span>
                        </div>
                        <div class="prod-badge">
                            +<?php echo number_format($row['produced_qty'] ?? 0, 0); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; background: white; border-radius: 20px; color: var(--rider-muted);">
                    <i class="fas fa-inbox" style="font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.3;"></i>
                    <p>No production records found.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 2rem; background: white; border-radius: 20px; color: var(--rider-muted);">
            <p>You can’t access this module right now.</p>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal -->
<div id="productionModal" class="modal">
    <div class="modal-content" style="max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; font-weight: 800;"><i class="fas fa-industry" style="color: var(--rider-primary);"></i> Record Production</h3>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" class="form" id="productionForm">
            <?php echo csrfTokenField(); ?>
            <div class="form-group" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem;">Production Type *</label>
                <select id="production_type" name="production_type" required style="width: 100%; padding: 0.75rem; border-radius: 12px; border: 1.5px solid #e2e8f0;">
                    <option value="">Select type</option>
                    <option value="stockin" selected>For Stock</option>
                </select>
            </div>


            <div class="form-group" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem;">Product *</label>
                <select id="product_id" name="product_id" required style="width: 100%; padding: 0.75rem; border-radius: 12px; border: 1.5px solid #e2e8f0;">
                    <option value="">Select a product</option>
                    <?php
                    $products_dropdown_result = $conn->query($products_query);
                    while ($product = $products_dropdown_result->fetch(PDO::FETCH_ASSOC)):
                    ?>
                        <option value="<?php echo $product['Product_ID']; ?>" data-unit="<?php echo htmlspecialchars($product['unit_name'] ?? ''); ?>">
                            <?php echo htmlspecialchars($product['product_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div style="background: #f8fafc; border-radius: 16px; padding: 1.25rem; margin-bottom: 1rem; border: 1px solid #e2e8f0;">
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem;">Quantity Produced *</label>
                    <input type="number" id="number_of_bags" name="number_of_bags" min="0.01" step="0.01" required placeholder="Amount to add" style="width: 100%; border: 1.5px solid var(--rider-primary); background: white; padding: 0.75rem; border-radius: 10px; font-size: 1.1rem; font-weight: 700; color: var(--rider-primary); outline: none;">
                </div>

                <div class="form-group" id="pack_size_group" style="margin-bottom: 0;">
                    <label style="display: block; font-size: 0.75rem; color: var(--rider-muted); margin-bottom: 2px;">Estimated Content Size</label>
                    <input type="text" id="pack_size" name="pack_size" readonly value="Based on product unit" style="width: 100%; background: transparent; border: none; font-size: 0.85rem; font-weight: 600; color: #1e293b; pointer-events: none;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem;">Date *</label>
                <input type="date" id="production_date" name="production_date" required value="<?php echo $today_date; ?>" style="width: 100%; padding: 0.75rem; border-radius: 12px; border: 1.5px solid #e2e8f0;">
            </div>

            <!-- Compatibility fields -->
            <input type="hidden" name="produced_qty" id="produced_qty" value="1">
            <input type="hidden" name="quantity_unit" value="kg">
            <input type="hidden" name="bag_size" id="bag_size_hidden">
            <input type="hidden" name="bag_size_unit" id="bag_size_unit_hidden">

            <button type="submit" class="btn-action-rider" style="margin-bottom: 0;">
                <i class="fas fa-save"></i> Save Production
            </button>
        </form>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script>
function openModal() {
    const canRecordProduction = <?php echo $can_inv_record_production ? 'true' : 'false'; ?>;
    if (!canRecordProduction) {
        alert("You can’t access this module right now.");
        return;
    }
    document.getElementById('productionModal').style.display = 'flex';
}
function closeModal() {
    document.getElementById('productionModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const productionType = document.getElementById('production_type');
    const orderGroup = document.getElementById('order_group');
    const orderSelect = document.getElementById('order_id');
    const orderDetailsSection = document.getElementById('order_details_section');
    const orderDetailsContent = document.getElementById('order_details_content');
    const productSelect = document.getElementById('product_id');
    const packSizeInput = document.getElementById('pack_size');
    
    productionType.addEventListener('change', function() {
        if (this.value === 'orders') {
            orderGroup.style.display = 'block';
            orderSelect.required = true;
        } else {
            orderGroup.style.display = 'none';
            orderSelect.required = false;
            orderDetailsSection.style.display = 'none';
        }
    });

    orderSelect.addEventListener('change', function() {
        if (this.value) {
            fetch(`../api/get_order_details.php?order_id=${this.value}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        orderDetailsContent.innerHTML = `<strong>${data.order_info}</strong><br>${data.items.map(i => i.product_name + ' (' + i.quantity + ' bags)').join('<br>')}`;
                        orderDetailsSection.style.display = 'block';
                    }
                });
        }
    });

    productSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        const unit = option.getAttribute('data-unit') || 'units';
        packSizeInput.value = unit;
    });

    document.getElementById('number_of_bags').addEventListener('input', function() {
        // Simple logic to sync produced_qty for backend
        document.getElementById('produced_qty').value = this.value;
    });
});
</script>
</body>
</html><?php
// End of file
?>
                    html += `<li>Required Quantity: <strong>${data.required_bags} bags</strong></li>`;
                    html += `<li>Already Produced: <strong>${data.produced_bags} bags</strong></li>`;
                    html += `<li>Remaining to Produce: <strong>${data.remaining_bags} bags</strong></li>`;
                    html += `</ul></div>`;
                    orderDetailsContent.innerHTML = html;
                    orderDetailsSection.style.display = 'block';
                    
                    // Filter products to show only those in the order
                    filterProductsByOrder(currentOrderItems);
                } else {
                    orderDetailsContent.innerHTML = `<div style="color: #dc3545;">Error: ${data.message || 'Failed to load order details'}</div>`;
                    orderDetailsSection.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error loading order details:', error);
                orderDetailsContent.innerHTML = `<div style="color: #dc3545;">Error loading order details. Please check the console for details.</div>`;
                orderDetailsSection.style.display = 'block';
            });
    }
    
    // Filter products based on order items
    function filterProductsByOrder(orderItems) {
        if (!productSelect) return;
        
        const allOptions = Array.from(productSelect.options);
        const orderProductIds = orderItems.map(item => String(item.product_id));
        
        allOptions.forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
                return;
            }
            
            if (productionType.value === 'orders' && orderItems.length > 0) {
                // Show only products that match order items by Product_ID
                option.style.display = orderProductIds.includes(option.value) ? 'block' : 'none';
            } else {
                // Show all products for non-order production
                option.style.display = 'block';
            }
        });
    }
    
    // Handle product selection to populate pack size
    if (productSelect) {
        productSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value && (productionType.value === 'orders' || productionType.value === 'stockin')) {
                const unit = selectedOption.getAttribute('data-unit') || '';
                const bagSizeHidden = document.getElementById('bag_size_hidden');
                const bagSizeUnitHidden = document.getElementById('bag_size_unit_hidden');
                
                if (packSizeInput) {
                    // Extract numeric value and unit from strings like "70g", "70G", "70 grams", "Block", etc.
                    if (unit) {
                        // Try to extract number and unit separately (case-insensitive)
                        // Match patterns like: "70g", "70G", "70g each", "70 grams", "70.5kg", "Block", etc.
                        const unitMatch = unit.match(/(\d+(?:\.\d+)?)\s*([a-zA-Z]+)/i);
                        if (unitMatch) {
                            // Found number and unit: format as "70 grams (read-only)"
                            const number = unitMatch[1];
                            const unitName = unitMatch[2].toLowerCase();
                            // Normalize unit name
                            let normalizedUnit = unitName;
                            let bagSizeValue = parseFloat(number);
                            let bagSizeUnitValue = 'kg';
                            
                            if (unitName === 'g' || unitName === 'gram') {
                                normalizedUnit = 'grams';
                                bagSizeUnitValue = 'grams';
                            } else if (unitName === 'kg' || unitName === 'kgs' || unitName === 'kilogram' || unitName === 'kilograms') {
                                normalizedUnit = 'kg';
                                bagSizeUnitValue = 'kg';
                            } else if (unitName === 'block' || unitName === 'blocks') {
                                normalizedUnit = 'blocks';
                                bagSizeUnitValue = 'blocks';
                                bagSizeValue = 1; // 1 block
                            }
                            
                            packSizeInput.value = number + ' ' + normalizedUnit + ' (read-only)';
                            
                            // Store values in hidden fields for backend
                            if (bagSizeHidden) bagSizeHidden.value = bagSizeValue;
                            if (bagSizeUnitHidden) bagSizeUnitHidden.value = bagSizeUnitValue;
                        } else {
                            // No number found, check if it's just a unit name (like "Block", "G", etc.)
                            const unitLower = unit.toLowerCase().trim();
                            let normalizedUnit = unit;
                            let bagSizeValue = 1;
                            let bagSizeUnitValue = 'kg';
                            
                            if (unitLower === 'g' || unitLower === 'gram' || unitLower === 'grams') {
                                normalizedUnit = 'grams';
                                bagSizeUnitValue = 'grams';
                            } else if (unitLower === 'kg' || unitLower === 'kilogram' || unitLower === 'kilograms') {
                                normalizedUnit = 'kg';
                                bagSizeUnitValue = 'kg';
                            } else if (unitLower === 'block' || unitLower === 'blocks') {
                                normalizedUnit = 'blocks';
                                bagSizeUnitValue = 'blocks';
                                bagSizeValue = 1;
                            }
                            
                            packSizeInput.value = normalizedUnit + ' (read-only)';
                            
                            // Store values in hidden fields for backend
                            if (bagSizeHidden) bagSizeHidden.value = bagSizeValue;
                            if (bagSizeUnitHidden) bagSizeUnitHidden.value = bagSizeUnitValue;
                        }
                    } else {
                        packSizeInput.value = '';
                        if (bagSizeHidden) bagSizeHidden.value = '';
                        if (bagSizeUnitHidden) bagSizeUnitHidden.value = '';
                    }
                }
            } else if (packSizeInput) {
                packSizeInput.value = '';
                const bagSizeHidden = document.getElementById('bag_size_hidden');
                const bagSizeUnitHidden = document.getElementById('bag_size_unit_hidden');
                if (bagSizeHidden) bagSizeHidden.value = '';
                if (bagSizeUnitHidden) bagSizeUnitHidden.value = '';
            }
        });
    }
    
    
    // Initialize on page load
    toggleOrderGroup();
    if (productionType.value === 'orders' && orderSelect.value) {
        loadOrderDetails(orderSelect.value);
    }
    
    // Auto-calculate number of bags
    const producedQty = document.getElementById('produced_qty');
    const quantityUnit = document.getElementById('quantity_unit');
    const bagSize = document.getElementById('bag_size');
    const bagSizeUnit = document.getElementById('bag_size_unit');
    const numberOfBags = document.getElementById('number_of_bags');
    
    function convertToKg(value, unit) {
        if (unit === 'kg') return value;
        if (unit === 'grams') return value / 1000;
        if (unit === 'blocks') return value; // Assuming blocks are already in equivalent kg or same unit
        return value;
    }
    
    function calculateBags() {
        const qty = parseFloat(producedQty.value) || 0;
        const qtyUnit = quantityUnit.value;
        const size = parseFloat(bagSize.value) || 0;
        const sizeUnit = bagSizeUnit.value;
        
        if (qty > 0 && size > 0) {
            // Convert both to same unit for calculation
            // If units match, calculate directly
            if (qtyUnit === sizeUnit) {
                const bags = Math.ceil(qty / size);
                numberOfBags.value = bags;
            } else {
                // Convert both to kg for calculation
                const qtyInKg = convertToKg(qty, qtyUnit);
                const sizeInKg = convertToKg(size, sizeUnit);
                if (sizeInKg > 0) {
                    const bags = Math.ceil(qtyInKg / sizeInKg);
                    numberOfBags.value = bags;
                } else {
                    numberOfBags.value = '';
                }
            }
        } else {
            numberOfBags.value = '';
        }
    }
    
    producedQty.addEventListener('input', calculateBags);
    quantityUnit.addEventListener('change', calculateBags);
    bagSize.addEventListener('input', calculateBags);
    bagSizeUnit.addEventListener('change', calculateBags);
    
    // Initialize calculation if values exist
    calculateBags();
});
</script>
</body>
</html>
<?php
// PDO doesn't need free() or close() - resources are automatically freed
?>
