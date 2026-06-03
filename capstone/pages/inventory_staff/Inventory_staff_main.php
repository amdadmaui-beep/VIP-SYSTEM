    <?php
    $activeTab = 'inventory';
    if (isset($_GET['tab'])) {
        if ($_GET['tab'] === 'history') $activeTab = 'history';
        if ($_GET['tab'] === 'dashboard') $activeTab = 'dashboard';
    }

    $todayTotalProd = 0;
    $todayDate = date('Y-m-d');
    try {
        if (isset($has_inventory_ledger_table) && $has_inventory_ledger_table) {
            $todayStmt = $conn->query("SELECT COALESCE(SUM(li.quantity_change), 0) FROM inventory_ledger li WHERE li.transaction_type = 'STOCK IN' AND DATE(li.created_at) = '$todayDate'");
        } else {
            $todayStmt = $conn->query("SELECT COALESCE(SUM(si.quantity), 0) FROM stockin_inventory si WHERE DATE(si.date_in) = '$todayDate'");
        }
        if ($todayStmt) $todayTotalProd = (int)$todayStmt->fetchColumn();
    } catch (Throwable $e) {}
    $pendingPrepCount = 0;
    try {
        $hasPrepTable = (bool) $conn->query("SHOW TABLES LIKE 'order_preparation_tasks'")->fetchColumn();
        if ($hasPrepTable) {
            $orderStatusCol = 'order_status';
            $stmtCol = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
            if ($stmtCol && $stmtCol->rowCount() > 0) {
                $rowCol = $stmtCol->fetch(PDO::FETCH_ASSOC);
                $orderStatusCol = (string)$rowCol['Field'];
            }
            $hasDeliveryTable = (bool) $conn->query("SHOW TABLES LIKE 'delivery'")->fetchColumn();
            $deliveryJoin = '';
            $deliveryConditions = '';
            if ($hasDeliveryTable) {
                $deliveryJoin = "LEFT JOIN delivery d ON d.Delivery_ID = (
                    SELECT d2.Delivery_ID FROM delivery d2 WHERE d2.Order_ID = o.Order_ID ORDER BY d2.Delivery_ID DESC LIMIT 1
                )";
                $deliveryConditions = "AND (d.delivery_status IS NULL OR d.delivery_status != 'Cancelled')";
            }
            $countQuery = "
                SELECT COUNT(DISTINCT o.Order_ID)
                FROM orders o
                {$deliveryJoin}
                LEFT JOIN order_preparation_tasks t ON t.Order_ID = o.Order_ID
                WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
                  {$deliveryConditions}
            ";
            $ppStmt = $conn->query($countQuery);
            if ($ppStmt) $pendingPrepCount = (int)$ppStmt->fetchColumn();
        }
    } catch (Throwable $e) {}
    ?>
    <main class="p-5">

        <!-- DASHBOARD TAB -->
        <div id="pane-dashboard" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active staggered-group' : 'hidden'; ?>">
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 gap-3 mb-5">
                <div class="bg-white border border-slate-100 p-5 rounded-2xl shadow-sm text-center">
                    <div class="text-2xl font-black text-indigo-600 mb-1"><?php echo $total_products; ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Total Products</div>
                </div>
                <div class="bg-white border border-slate-100 p-5 rounded-2xl shadow-sm text-center">
                    <div class="text-2xl font-black text-amber-500 mb-1"><?php echo $low_stock + $out_of_stock; ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Low / Out of Stock</div>
                </div>
                <div class="bg-white border border-slate-100 p-5 rounded-2xl shadow-sm text-center">
                    <div class="text-2xl font-black text-emerald-600 mb-1"><?php echo number_format($todayTotalProd); ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Today's Stock In</div>
                </div>
                <div class="bg-white border border-slate-100 p-5 rounded-2xl shadow-sm text-center">
                    <div class="text-2xl font-black text-rose-600 mb-1"><?php echo $pendingPrepCount; ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Pending Prep</div>
                </div>
            </div>

            <!-- Overdue Alert -->
            <?php if ($overdue_orders_count > 0): ?>
            <a href="inventory_staff_view.php" class="block mb-5 p-4 bg-rose-50 border border-rose-200 rounded-2xl hover:bg-rose-100 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <div class="text-sm font-bold text-rose-800"><?php echo $overdue_orders_count; ?> overdue prep task<?php echo $overdue_orders_count > 1 ? 's' : ''; ?></div>
                        <div class="text-xs font-medium text-rose-600">Delivery date passed — needs immediate attention</div>
                    </div>
                    <i class="fas fa-chevron-right ml-auto text-rose-400"></i>
                </div>
            </a>
            <?php endif; ?>

            <!-- Low Stock List -->
            <?php
            $lowStockProducts = array_filter($products, function($p) {
                return (float)$p['current_quantity'] < 20;
            });
            if (!empty($lowStockProducts)):
            ?>
            <div class="mb-5">
                <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-amber-500"></i> Low Stock Alerts
                </h3>
                <div class="space-y-2">
                    <?php foreach (array_slice($lowStockProducts, 0, 5) as $lp): ?>
                    <div class="flex items-center justify-between bg-white p-3.5 rounded-xl border border-slate-100 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                                <i class="fas fa-cube text-sm"></i>
                            </div>
                            <span class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($lp['product_name']); ?></span>
                        </div>
                        <span class="text-xs font-black text-amber-700 bg-amber-50 px-2.5 py-1 rounded-lg"><?php echo number_format((float)$lp['current_quantity'], 0); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($needsAttentionOrders)): ?>
            <div class="card" style="border:1px solid #fde68a; border-radius:16px; background:#fff; margin-bottom:1rem; overflow:hidden;">
                <div class="card-header" style="background:#fffbeb; padding:0.85rem 1.25rem; border-bottom:1px solid #fde68a; display:flex; align-items:center; gap:0.6rem; font-weight:700; font-size:0.85rem; color:#92400e;">
                    <i class="fas fa-exclamation-triangle" style="color:#d97706;"></i> Orders Needing Attention
                    <span style="margin-left:auto; font-size:0.7rem; font-weight:600; color:#b45309;">Pack the shortage, then Record Stock In</span>
                </div>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.82rem;">
                        <thead>
                            <tr style="background:#fefce8; border-bottom:1px solid #fde68a;">
                                <th style="padding:0.6rem 0.75rem; text-align:left; color:#92400e;">Order</th>
                                <th style="padding:0.6rem 0.75rem; text-align:left; color:#92400e;">Customer</th>
                                <th style="padding:0.6rem 0.75rem; text-align:left; color:#92400e;">Product</th>
                                <th style="padding:0.6rem 0.75rem; text-align:center; color:#92400e;">Ordered</th>
                                <th style="padding:0.6rem 0.75rem; text-align:center; color:#92400e;">In Stock</th>
                                <th style="padding:0.6rem 0.75rem; text-align:center; color:#92400e;">To Pack</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($needsAttentionOrders as $na): ?>
                            <?php $toPack = max(0, (int)$na['ordered_qty'] - (int)$na['available_stock']); ?>
                            <tr style="border-bottom:1px solid #fef3c7;">
                                <td style="padding:0.6rem 0.75rem; font-weight:600;">#<?php echo (int)$na['Order_ID']; ?></td>
                                <td style="padding:0.6rem 0.75rem;"><?php echo htmlspecialchars($na['customer_name']); ?></td>
                                <td style="padding:0.6rem 0.75rem;"><?php echo htmlspecialchars($na['product_name']); ?></td>
                                <td style="padding:0.6rem 0.75rem; text-align:center;"><?php echo (int)$na['ordered_qty']; ?></td>
                                <td style="padding:0.6rem 0.75rem; text-align:center;"><?php echo (int)$na['available_stock']; ?></td>
                                <td style="padding:0.6rem 0.75rem; text-align:center; font-weight:700; color:#dc2626;"><?php echo $toPack; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 gap-3">
                <button onclick="openProductionModal()" class="p-4 bg-indigo-600 text-white rounded-2xl font-bold text-sm shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-plus-circle"></i> Record Stock In
                </button>
                <button onclick="openAdjustmentModal()" class="p-4 bg-slate-800 text-white rounded-2xl font-bold text-sm shadow-lg shadow-slate-200 hover:bg-slate-900 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-edit"></i> Manual Adjustment
                </button>
            </div>
        </div>

        <!-- INVENTORY TAB -->
        <div id="pane-inventory" class="tab-content <?php echo $activeTab === 'inventory' ? 'active staggered-group' : 'hidden'; ?>">
            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3 mb-5">
                <div class="bg-white border md:border-none border-slate-100 p-4 rounded-2xl shadow-sm text-center">
                    <div class="text-2xl font-black text-indigo-600 mb-1"><?php echo $total_products; ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Products</div>
                </div>
                <div class="bg-white border md:border-none border-slate-100 p-4 rounded-2xl shadow-sm text-center">
                    <div class="text-2xl font-black text-red-500 mb-1"><?php echo $low_stock + $out_of_stock; ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Low / Out</div>
                </div>
            </div>

            <!-- Primary Action Buttons -->
            <div class="mb-5 grid grid-cols-1 gap-2">
                <?php if ($ddr_queue_show && $ddr_pending_n > 0): ?>
                <a href="inventory_staff_delivery_damage.php" class="w-full py-4 bg-orange-500 hover:bg-orange-600 text-white rounded-2xl font-bold text-sm shadow-lg shadow-orange-100 transition-all flex items-center justify-center gap-2 animate-pulse">
                    <i class="fas fa-exclamation-triangle"></i> Review Damage Reports (<?php echo $ddr_pending_n; ?>)
                </a>
                <?php endif; ?>
                <button onclick="openProductionModal()" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-bold text-sm shadow-lg shadow-indigo-100 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-plus-circle"></i> Record Stock In
                </button>
                <button onclick="openAdjustmentModal()" class="w-full py-4 bg-slate-800 hover:bg-slate-900 text-white rounded-2xl font-bold text-sm shadow-lg shadow-slate-200 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-edit"></i> Manual Adjustment
                </button>
            </div>

            <!-- Advanced Search with Filter Icon -->
            <div class="flex gap-2 mb-5">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" id="searchInput" oninput="filterProducts()" placeholder="Search products..." class="w-full pl-11 pr-4 py-3.5 bg-slate-100 border-none rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500 outline-none transition-all placeholder-slate-400 shadow-inner">
                </div>
                <button onclick="toggleFilters()" class="w-12 h-12 bg-slate-100 text-slate-500 rounded-2xl flex items-center justify-center hover:bg-slate-200 transition-colors focus:ring-2 focus:ring-indigo-500 outline-none">
                    <i class="fas fa-sliders-h"></i>
                </button>
            </div>

            <!-- Expandable Filters -->
            <div id="filterPanel" class="hidden bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-5 text-sm">
                <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wide">Filter Status</label>
                <div class="flex gap-2 mb-4">
                    <button onclick="applyFilter('all')" class="filter-btn active flex-1 py-2 text-xs font-bold bg-indigo-100 text-indigo-700 rounded-lg">All</button>
                    <button onclick="applyFilter('low')" class="filter-btn flex-1 py-2 text-xs font-bold bg-white text-slate-600 border border-slate-200 rounded-lg">Low/Out</button>
                    <button onclick="applyFilter('in')" class="filter-btn flex-1 py-2 text-xs font-bold bg-white text-slate-600 border border-slate-200 rounded-lg">In Stock</button>
                </div>
            </div>

            <!-- Product Cards -->
            <div class="max-h-[calc(100vh-350px)] overflow-y-auto pr-1 custom-scrollbar" id="productScrollContainer">
            <div class="space-y-3 pb-24 staggered-group" id="productList">
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $p):
                        $qty = (float)$p['current_quantity'];
                        $reserved = (float)($reservedMap[(int)($p['Product_ID'] ?? 0)] ?? 0);
                        $sellable = max(0.0, $qty - $reserved);
                        $max = (float)($p['storage_limit'] ?? 0);
                        if ($max <= 0) {
                            $max = 100;
                        }
                        $min = max(1, (int)round($max * 0.2)); // 20% of storage limit
                        
                        // Status Logic
                        if ($qty == 0) {
                            $status = 'Out of Stock'; $color = 'red'; $icon = 'exclamation-circle';
                        } elseif ($qty < $min) {
                            $status = 'Low Stock'; $color = 'yellow'; $icon = 'exclamation-triangle';
                        } else {
                            $status = 'In Stock'; $color = 'emerald'; $icon = 'check-circle';
                        }
                        
                        $pct = min(100, max(0, ($qty / $max) * 100));
                        if ($qty == 0) $barColor = 'bg-red-500';
                        elseif ($qty < $min) $barColor = 'bg-yellow-400';
                        else $barColor = 'bg-emerald-500';

                        // Format last updated nice relative time
                        $updated = $p['last_updated'] ? date('M j, g:i A', strtotime($p['last_updated'])) : 'Never updated';
                        $unit = trim(htmlspecialchars($p['unit_name'] ?? ''));
                        if(empty($unit)) $unit = 'pcs';
                    ?>
                    <div class="product-item bg-white p-4 rounded-[20px] shadow-sm border border-slate-100 transition-transform active:scale-95" data-name="<?php echo strtolower(htmlspecialchars($p['product_name'])); ?>" data-status="<?php echo ($qty < $min) ? 'low' : 'in'; ?>">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-500 flex items-center justify-center">
                                    <i class="fas fa-cube text-lg"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-slate-800 text-sm mb-0.5"><?php echo htmlspecialchars($p['product_name']); ?></h4>
                                    <div class="flex items-center gap-1.5 text-[10px] font-bold text-<?php echo $color; ?>-600 bg-<?php echo $color; ?>-50 px-2 py-0.5 rounded-md inline-flex">
                                        <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo $status; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl font-black text-slate-800"><?php echo number_format($qty, 0); ?></div>
                                <div class="text-[10px] font-bold text-slate-400 uppercase"><?php echo $unit; ?></div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 mt-2 text-[10px] font-bold">
                            <span class="bg-slate-50 text-slate-600 px-2.5 py-1 rounded-lg border border-slate-100">
                                On hand: <span class="text-slate-800"><?php echo number_format($qty, 0); ?></span>
                            </span>
                            <?php if ($reserved > 0): ?>
                                <span class="bg-orange-50 text-orange-700 px-2.5 py-1 rounded-lg border border-orange-100 cursor-pointer hover:bg-orange-100 transition-colors" onclick="event.stopPropagation(); showReservationDebug(<?php echo (int)$p['Product_ID']; ?>, '<?php echo htmlspecialchars($p['product_name'], ENT_QUOTES); ?>')" title="View which orders are holding this stock">
                                    <i class="fas fa-link text-[9px] mr-1"></i>Reserved: <span class="text-orange-900"><?php echo number_format($reserved, 0); ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-lg border border-indigo-100">
                                Sellable: <span class="text-indigo-900"><?php echo number_format($sellable, 0); ?></span>
                            </span>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="mt-3">
                            <div class="flex justify-between text-[10px] font-bold text-slate-400 mb-1 px-0.5">
                                <span>Min: <?php echo $min; ?></span>
                                <span>Limit: <?php echo $max; ?></span>
                            </div>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill <?php echo $barColor; ?>" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                            <div class="mt-2 text-[10px] font-medium text-slate-400 flex items-center gap-1.5">
                                <i class="far fa-clock"></i> Updated: <?php echo $updated; ?>
                            </div>
                        </div>
                        
                        <!-- Storage Limit Button -->
                        <button onclick="event.stopPropagation(); openStorageLimitModal(<?php echo $p['Product_ID']; ?>, '<?php echo htmlspecialchars($p['product_name'], ENT_QUOTES); ?>', <?php echo $qty; ?>, <?php echo $max; ?>)" 
                                class="mt-2 w-auto px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg text-[10px] font-medium transition-colors flex items-center gap-1.5 pointer-events-auto relative z-10">
                            <i class="fas fa-sliders-h text-[10px]"></i> Limit
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-10 bg-white rounded-2xl border border-dashed border-slate-200">
                        <i class="fas fa-box-open text-4xl text-slate-200 mb-3"></i>
                        <p class="text-sm font-semibold text-slate-400">No products found.</p>
                    </div>
                <?php endif; ?>
            </div>
            </div>

            <!-- Product Pagination Controls -->
            <div id="productPagination" class="flex items-center justify-between mt-4 pb-24 px-1 hidden">
                <button onclick="prevProductPage()" class="w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-500 flex items-center justify-center hover:bg-slate-50 hover:text-indigo-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-sm">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="text-xs font-bold text-slate-500">
                    Page <span id="productPageNum" class="text-indigo-600">1</span> of <span id="productPageTotal">1</span>
                </div>
                <button onclick="nextProductPage()" class="w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-500 flex items-center justify-center hover:bg-slate-50 hover:text-indigo-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-sm">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>

        <!-- HISTORY TAB -->
        <div id="pane-history" class="tab-content <?php echo $activeTab === 'history' ? 'active staggered-group' : 'hidden'; ?>">
            <!-- Modern Sub-tabs -->
            <div class="flex p-1 bg-slate-100 rounded-xl mb-4">
                <button onclick="switchSubTab('productions', this)" class="sub-tab active flex-1 py-2 text-xs font-bold bg-white text-indigo-600 rounded-lg shadow-sm transition-all text-center">
                    <i class="fas fa-boxes-stacked mr-1"></i> Stock In
                </button>
                <button onclick="switchSubTab('adjustments', this)" class="sub-tab flex-1 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 transition-all text-center">
                    <i class="fas fa-sliders-h mr-1"></i> Adjustments
                </button>
            </div>

            <!-- History Search Filter -->
            <div class="relative mb-4">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="text" id="historySearchInput" oninput="filterHistory()" placeholder="Search history records..." class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500 outline-none transition-all placeholder-slate-400 shadow-sm">
            </div>

            <!-- Productions Sub-pane -->
            <div id="sub-productions" class="sub-pane block staggered-group">
                <?php if (count($production_history) > 0): ?>
                    <div class="space-y-3 pb-2 max-h-[50vh] overflow-y-auto pr-1" style="scrollbar-width: thin;">
                    <?php foreach ($production_history as $h): ?>
                        <div class="history-item bg-white p-4 rounded-2xl shadow-sm border border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-500 flex items-center justify-center">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($h['product_name']); ?></h4>
                                    <p class="text-[10px] font-medium text-slate-400">
                                        <?php echo date('M j, Y', strtotime($h['production_date'])); ?> • <?php echo htmlspecialchars($h['handled_by'] ?? 'Staff'); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-lg text-sm font-black">
                                +<?php echo number_format((float)($h['produced_qty'] ?? 0), 0); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10 animate-fade-in-up">
                        <i class="fas fa-history text-4xl text-slate-200 mb-3 block"></i>
                        <span class="text-sm font-semibold text-slate-400">No recent stock in records.</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Adjustments Sub-pane -->
            <div id="sub-adjustments" class="sub-pane hidden staggered-group">
                <?php if (count($adjustment_history) > 0): ?>
                    <div class="space-y-3 pb-2 max-h-[50vh] overflow-y-auto pr-1" style="scrollbar-width: thin;">
                    <?php foreach ($adjustment_history as $a): 
                        $change = (float)$a['new_quantity'] - (float)$a['old_quantity'];
                        $is_pos = $change >= 0;
                        $color = $is_pos ? 'emerald' : 'red';
                        $icon = $is_pos ? 'fa-arrow-up' : 'fa-arrow-down';
                    ?>
                        <div class="history-item bg-white p-4 rounded-2xl shadow-sm border border-slate-100 cursor-pointer hover:border-indigo-200 transition-colors" onclick="viewAdjDetail(<?php echo htmlspecialchars(json_encode($a)); ?>)">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-500 flex items-center justify-center">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($a['product_name']); ?></h4>
                                        <p class="text-[10px] font-medium text-slate-400">
                                            <?php echo date('M j, Y', strtotime($a['adjustment_date'])); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-700 px-3 py-1 rounded-lg text-sm font-black">
                                    <?php echo ($is_pos ? '+' : '') . number_format($change, 0); ?>
                                </div>
                            </div>
                            <div class="text-xs font-medium text-slate-500 bg-slate-50 px-3 py-2 rounded-lg border border-slate-100">
                                Reason: <span class="text-slate-700 font-bold"><?php echo htmlspecialchars($a['reason'] ?? 'N/A'); ?></span>
                                <?php $adjRemarks = normalizeAdjustmentNotes((string)($a['notes'] ?? '')); ?>
                                <?php if ($adjRemarks !== ''): ?>
                                    <div class="mt-1 text-slate-500">Remarks: <span class="text-slate-700 font-semibold"><?php echo htmlspecialchars($adjRemarks); ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10 animate-fade-in-up">
                        <i class="fas fa-sliders-h text-4xl text-slate-200 mb-3 block"></i>
                        <span class="text-sm font-semibold text-slate-400">No recent adjustments.</span>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination Controls -->
            <div id="historyPagination" class="flex items-center justify-between mt-4 pb-20 px-1 hidden">
                <button onclick="prevHistoryPage()" class="w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-500 flex items-center justify-center hover:bg-slate-50 hover:text-indigo-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-sm">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="text-xs font-bold text-slate-500">
                    Page <span id="historyPageNum" class="text-indigo-600">1</span> of <span id="historyPageTotal">1</span>
                </div>
                <button onclick="nextHistoryPage()" class="w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-500 flex items-center justify-center hover:bg-slate-50 hover:text-indigo-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-sm">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </main>