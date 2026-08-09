<body>
    <div class="pos-container">
        <!-- POS Header -->
        <header class="pos-header">
            <div class="pos-brand">
                <i class="fas fa-cash-register"></i>
                <h1>VIP Villanueva Ice Plant</h1>
            </div>

            <div class="pos-user-actions">
                <div class="pos-management">
                    <button class="btn-mgmt" onclick="window.location.href='delivery_remittance.php'" title="Delivery Remittance">
                        <i class="fas fa-truck-loading"></i> Remittance
                    </button>
                    <?php if ($can_cashier_delivery_orders): ?>
                    <button class="btn-mgmt" onclick="window.location.href='pickup_orders.php'" title="Pickup Orders">
                        <i class="fas fa-shopping-basket"></i> Pickup
                    </button>
                    <?php endif; ?>
                    <?php if ($can_cashier_ar_sales): ?>
                    <button class="btn-mgmt" onclick="window.location.href='ar_remittance.php'" title="AR Payments">
                        <i class="fas fa-file-invoice-dollar"></i> AR Payments
                    </button>
                    <?php endif; ?>
                    <button class="btn-mgmt" onclick="openShiftModal()" title="Shift Management">
                        <i class="fas fa-clock"></i> Shift
                    </button>
                    <?php if ($can_sales_history): ?>
                    <button class="btn-mgmt" onclick="openHistoryModal()" title="Transaction History">
                        <i class="fas fa-history"></i> History
                    </button>
                    <?php endif; ?>
                    <?php if ($can_sales_report && $can_cashier_z_read): ?>
                    <button class="btn-mgmt" onclick="openZReadModal()" title="Daily Z-Read">
                        <i class="fas fa-file-invoice"></i> Z-Read
                    </button>
                    <?php endif; ?>
                </div>
                <div class="pos-user" onclick="openModal('profileModal')" style="cursor:pointer;">
                    <div class="pos-avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
                    <div class="pos-user-info">
                        <span><?php echo htmlspecialchars($full_name); ?></span>
                    </div>
                </div>
                <?php require_once __DIR__ . '/../../includes/profile_modal.php'; ?>
                <a href="#" onclick="confirmLogout(event)" class="btn-exit" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </header>

        <!-- POS Main -->
        <main class="pos-main" id="posMain">
            <!-- Catalog Area (Left) -->
            <button class="btn-expand-catalog" onclick="toggleCatalog()" title="Show Catalog">
                <i class="fas fa-chevron-right"></i>
            </button>
            <section class="pos-catalog-panel">
                <div class="catalog-header">
                    <h2><i class="fas fa-th-large"></i> Products</h2>
                    <div class="product-search-wrapper">
                        <i class="fas fa-search product-search-icon"></i>
                        <input type="text" class="product-search-input" id="productSearch" placeholder="Search products..." autocomplete="off">
                    </div>
                    <button class="btn-collapse-catalog" onclick="toggleCatalog()" title="Hide Catalog">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                </div>
                <div class="catalog-grid" id="catalogGrid">
                    <?php foreach($products_res as $index => $p): ?>
                        <div class="catalog-card slide-in" onclick='addItemFromGrid(<?php echo json_encode($p); ?>)' style="position: relative; animation-delay: <?php echo $index * 0.05; ?>s" data-product-name="<?php echo strtolower(htmlspecialchars($p['product_name'])); ?>" data-product-id="<?php echo $p['Product_ID']; ?>" data-original-stock="<?php echo (float)$p['available_stock']; ?>">
                            <div class="product-image">
                                <!-- Badges overlapping image top-left -->
                                <div class="card-badges">
                                    <?php
                                    $stock = (float)$p['available_stock'];
                                    if ($stock <= 0) {
                                        $badgeClass = 'badge-danger';
                                        $badgeText = 'OUT';
                                    } elseif ($stock <= 5) {
                                        $badgeClass = 'badge-warning';
                                        $badgeText = 'LOW: ' . rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.');
                                    } else {
                                        $badgeClass = 'badge-success';
                                        $badgeText = 'QTY: ' . rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.');
                                    }
                                    ?>
                                    <span class="card-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                                    
                                    <?php $reserved = (float)($p['reserved_stock'] ?? 0); if ($reserved > 0): ?>
                                    <span class="card-badge badge-purple">RES: <?php echo rtrim(rtrim(number_format($reserved, 2, '.', ''), '0'), '.'); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($p['product_image'])): ?>
                                    <img src="../uploads/products/<?php echo htmlspecialchars($p['product_image']); ?>" alt="<?php echo htmlspecialchars($p['product_name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-cube"></i>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <h5><?php echo htmlspecialchars($p['product_name']); ?></h5>
                                <p class="unit"><?php echo htmlspecialchars($p['unit_name'] ?? 'Unit'); ?></p>
                                <span class="price">₱<?php echo number_format($p['retail_price'], 2); ?></span>
                                
                                <div class="add-icon">
                                    <i class="fas fa-plus"></i>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Cart Area -->
            <section class="pos-cart-panel">
                <div class="cart-header">
                    <h2><i class="fas fa-shopping-cart"></i> Selected Items</h2>
                    <?php if (!hasRole(1)): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn-clear-cart" onclick="clearCart()">
                            <i class="fas fa-trash-alt"></i> Clear
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="cart-table-wrapper">
                    <table class="cart-table" id="cartTable">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th style="width: 140px; text-align: center;">Quantity</th>
                                <th style="width: 120px; text-align: right;">Price</th>
                                <th style="width: 120px; text-align: right;">Subtotal</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="cartBody">
                            <!-- Items populated via JS -->
                        </tbody>
                    </table>
                    <div id="cartEmptyState" class="cart-empty">
                        <i class="fas fa-shopping-basket"></i>
                        <p>Total Items: <span id="emptyItemCount">0</span></p>
                    </div>
                </div>
                <div id="cartOrderInfo"></div>
            </section>

            <!-- Billing Area - Modern Order Summary -->
            <section class="pos-billing-panel">
                <div class="billing-header">
                    <h2><i class="fas fa-receipt"></i> Current Order</h2>
                </div>

                <!-- Modern Cart Items -->
                <div class="cart-items-modern" id="cartItemsModern">
                    <div id="cartEmptyStateModern" class="cart-empty" style="padding: 3rem 1rem;">
                        <i class="fas fa-shopping-basket" style="font-size: 3rem; color: var(--pos-border); margin-bottom: 1rem;"></i>
                        <p style="color: var(--pos-text-muted);">Your cart is empty</p>
                        <p style="font-size: 0.8rem; color: var(--pos-text-muted);">Click on products to add them</p>
                    </div>
                    <div id="orderItemsList" class="order-items-list" style="display: none;">
                        <!-- Populated via JS -->
                    </div>
                </div>

                <!-- AR Payment Modal -->
                <div class="modal" id="paymentModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2><i class="fas fa-money-bill-wave"></i> Record Payment</h2>
                            <button class="modal-close" onclick="closeModal('paymentModal')"><i class="fas fa-times"></i></button>
                        </div>
                        <form id="paymentForm" onsubmit="submitPayment(event)" style="padding: 1.25rem 1.5rem 1.5rem;">
                            <input type="hidden" name="ar_id" id="paymentArId">
                            <input type="hidden" name="customer_id" id="paymentCustomerId">
                            <div class="form-group">
                                <label>Customer *</label>
                                <input type="text" id="paymentCustomerName" readonly class="form-control" style="background: #f8fafc;">
                            </div>
                            <div class="customer-balance" id="pay_customerBalanceInfo">
                                <div class="balance-row">
                                    <span>Open Invoices:</span>
                                    <span id="pay_openInvoicesCount">0</span>
                                </div>
                                <div class="balance-row">
                                    <span>Total Outstanding:</span>
                                    <span id="pay_totalOutstanding">₱0.00</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Payment Amount *</label>
                                <input type="number" name="amount_paid" id="paymentAmount" step="0.01" min="0.01" required placeholder="0.00" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Payment Date</label>
                                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                            </div>
                            <div style="margin-top: 0.5rem;">
                                <button type="submit" class="form-submit">
                                    <i class="fas fa-check"></i> Record Payment
                                </button>
                                <p style="font-size: 0.75rem; color: #64748b; margin-top: 0.75rem; text-align: center; margin-bottom: 0;">
                                    Payment will be applied to oldest invoices first
                                </p>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- History Modal -->
                <div class="modal" id="historyModal">
                    <div class="modal-content" style="max-width: 900px;">
                        <div class="modal-header">
                            <h2><i class="fas fa-history"></i> Transaction History</h2>
                            <button class="modal-close" onclick="document.getElementById('historyModal').style.display='none';"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-body">
                            <!-- Filter and Search Controls -->
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; gap: 1rem; flex-wrap: wrap;">
                                <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                                    <div class="search-bar" style="position: relative;">
                                        <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--pos-text-muted);"></i>
                                        <input type="text" id="historySearch" class="form-control" placeholder="Search by Sales ID..." style="padding-left: 35px;" oninput="debounceHistorySearch()">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                                    <select id="historyFilter" class="form-control" onchange="openHistoryModal()">
                                        <option value="all">All Time</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Keeps table contained; prevents overlap with the rest of the POS UI -->
                            <div style="overflow-x: auto; overflow-y: auto; max-height: 70vh;">
                                <table class="mgmt-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Date & Time</th>
                                            <th>Customer</th>
                                            <th>Type</th>
                                            <th style="text-align: right;">Amount</th>
                                            <th style="text-align: center;">Status</th>
                                            <th style="text-align: right;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="historyTableBody">
                                        <!-- Populated via JS -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Receipt Modal -->
                <div class="modal" id="receiptModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2><i class="fas fa-print"></i> Order Receipt</h2>
                            <button class="modal-close" onclick="document.getElementById('receiptModal').style.display='none';"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-body" style="padding: 1.5rem; background: #f1f5f9;">
                            <div class="receipt-paper" id="receiptContent">
                                <!-- Populated via JS -->
                            </div>
                        </div>
                        <div class="modal-footer" style="padding: 1rem; text-align: right; background: white; border-top: 1px solid #eee;">
                            <button class="btn btn-secondary" onclick="document.getElementById('receiptModal').style.display='none';" style="margin-right: 8px;">Close</button>
                            <button class="btn btn-primary" onclick="window.print()">
                                <i class="fas fa-print"></i> Print Receipt
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Z-Read Modal -->
                <div class="modal" id="zReadModal">
                    <div class="modal-content" style="max-width: 800px;">
                        <div class="modal-header">
                            <h2><i class="fas fa-file-invoice"></i> Daily Z-Read (Summary)</h2>
                            <button class="modal-close" onclick="closeModal('zReadModal')"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-body" id="zReadContent">
                            <!-- Populated via JS -->
                        </div>
                        <div class="modal-footer" style="padding: 1.5rem; border-top: 1px solid var(--pos-border); text-align: right;">
                            <button class="btn-qty" style="padding: 0.5rem 1.5rem; width: auto;" onclick="window.print()">
                                <i class="fas fa-print"></i> Print Summary
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Shift Management Modal -->
                <div class="modal" id="shiftModal">
                    <div class="modal-content" style="max-width: 860px;">
                        <div class="modal-header" style="background: linear-gradient(135deg, #7132f5, #9f5ef8); display: flex; align-items: center; justify-content: space-between;">
                            <h2 style="color: white; margin: 0;"><i class="fas fa-cash-register"></i> Cash Drawer - Shift Management</h2>
                            <button class="modal-close" onclick="closeModal('shiftModal')" style="color: white;"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-body" id="shiftModalBody" style="padding: 1.5rem; max-height: 80vh; overflow-y: auto;">
                            <!-- Content populated via JS -->
                        </div>
                    </div>
                </div>

                <!-- Closing Count Modal -->
                <div class="modal" id="closingCountModal">
                    <div class="modal-content" style="max-width: 860px;">
                        <div class="modal-header" style="background: linear-gradient(135deg, #ef4444, #b91c1c);">
                            <h2><i class="fas fa-lock"></i> End of Shift - Physical Cash Count</h2>
                            <button class="modal-close" onclick="closeModal('closingCountModal')"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-body" id="closingCountBody" style="padding: 1.5rem; max-height: 80vh; overflow-y: auto;">
                            <!-- Content populated via JS -->
                        </div>
                    </div>
                </div>

                <!-- Manager PIN Modal -->
                <div class="modal" id="managerPinModal">
                    <div class="modal-content" style="max-width: 400px;">
                        <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <h2><i class="fas fa-user-shield"></i> Manager PIN Required</h2>
                            <button class="modal-close" onclick="closeModal('managerPinModal')"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-body" style="padding: 1.5rem;">
                            <form id="managerPinForm" onsubmit="validateManagerPins(event)">
                                <div class="form-group">
                                    <label for="managerPin">Enter Manager PIN</label>
                                    <input type="password" id="managerPin" class="form-control" required maxlength="10" placeholder="Enter PIN">
                                </div>
                                <input type="hidden" id="managerPinAction" value="">
                                <input type="hidden" id="managerPinData" value="">
                                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                                    <button type="button" class="btn btn-secondary" onclick="closeModal('managerPinModal')" style="flex: 1;">Cancel</button>
                                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                                        <i class="fas fa-check"></i> Validate
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="billing-footer">
                    <div class="billing-section">
                        <div class="billing-row">
                            <span>Subtotal</span>
                            <span id="subtotalDisplay">₱0.00</span>
                        </div>
                        <div class="billing-row" style="color: var(--pos-text-muted);">
                            <span>Items (<span id="totalCount">0</span>)</span>
                            <span id="itemCountDetails"></span>
                        </div>
                        <div class="billing-row total">
                            <span>Total</span>
                            <span id="totalDueDisplay">₱0.00</span>
                        </div>
                    </div>

                    <div class="billing-section">
                        <div class="payment-input-group">
                            <label style="font-size: 0.75rem; color: var(--pos-text-muted);">Amount Tendered</label>
                            <input type="number" step="0.01" class="payment-input" id="amountTendered" placeholder="0.00">
                        </div>
                        
                        <div class="cash-shortcuts">
                            <button type="button" class="cash-shortcut-btn" onclick="addCashAmount(20)">20</button>
                            <button type="button" class="cash-shortcut-btn" onclick="addCashAmount(50)">50</button>
                            <button type="button" class="cash-shortcut-btn" onclick="addCashAmount(100)">100</button>
                            <button type="button" class="cash-shortcut-btn" onclick="addCashAmount(200)">200</button>
                            <button type="button" class="cash-shortcut-btn" onclick="addCashAmount(500)">500</button>
                            <button type="button" class="cash-shortcut-btn" onclick="addCashAmount(1000)">1K</button>
                            <button type="button" class="cash-shortcut-btn exact" onclick="setExactAmount()">Exact</button>
                        </div>
                        
                        <div class="change-display" id="changeWrapper">
                            <span class="label">Change</span>
                            <span class="amount" id="changeDisplay">₱0.00</span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn-clear-order" onclick="clearCart()" style="flex: 1;">
                            <i class="fas fa-trash"></i> Clear
                        </button>
                        <?php if (!hasRole(1)): ?>
                        <button class="btn-checkout" id="btnCheckout" disabled onclick="processSale()" style="flex: 1;">
                            <i class="fas fa-check-circle"></i>
                            Complete Sale
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if (hasRole(1)): ?>
                    <div class="alert alert-info" style="font-size: 0.8rem; margin-top: 0.5rem;">
                        <i class="fas fa-info-circle"></i> View-only mode for Owner.
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <!-- Create Sale from Delivery Modal -->
    <div id="saleFromDeliveryModal" class="modal">
        <div class="modal-content" style="max-width: 1100px; width: 95vw;">
            <div class="modal-header">
                <h2><i class="fas fa-truck"></i> Record Sale from Delivery</h2>
                <button class="modal-close" onclick="closeSaleFromDeliveryModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div style="background: #fef3c7; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem; border-left: 4px solid #f59e0b; font-size: 0.875rem; color: #92400e;">
                    <strong><i class="fas fa-info-circle"></i> Delivery Confirmation:</strong>
                    <p style="margin: 0.25rem 0 0 0;">
                        Update received quantities as reported by the rider. Inventory will be reduced upon confirmation.
                    </p>
                </div>
                <form id="saleFromDeliveryForm" method="POST" action="../api/sales_backend.php">
                    <input type="hidden" name="action" value="create_sale_from_delivery">
                    <input type="hidden" name="delivery_id" id="delivery_id">
                    <input type="hidden" name="post_to_ar" id="post_to_ar_hidden" value="0">
                    <?php echo csrfTokenField(); ?>
                    
                    <div id="delivery_details_container"></div>

                    <?php if ($can_cashier_ar_sales): ?>
                    <div class="ar-section" style="margin-top: 1.5rem;">
                        <button type="button" id="post_to_ar_btn" class="btn-ar-post" onclick="togglePostToAR()">
                            <i class="fas fa-file-invoice-dollar"></i> Post to Accounts Receivable (AR)
                        </button>
                        <p style="font-size: 0.8125rem; color: #64748b; margin: 0.5rem 0 0 0;">
                            Click if customer pays partially. The balance will be recorded in their AR account.
                        </p>
                        
                        <div id="ar_payment_details" style="display: none; margin-top: 1.25rem; padding: 1.25rem; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <h4 style="margin: 0 0 1rem 0; font-size: 1rem; color: #1e293b;"><i class="fas fa-money-bill-wave"></i> Partial Payment Details</h4>
                            <p style="font-size: 0.8rem; color: #475569; margin: 0 0 0.75rem 0;">
                                Due date is automatic based on the customer's aging days.
                            </p>
                            <div class="payment-input-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="amount_paid">Amount Paid Now (₱) *</label>
                                    <input type="number" id="amount_paid" name="amount_paid" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label>Balance to AR (₱)</label>
                                    <input type="text" id="ar_balance" class="form-control" readonly value="0.00" style="background: #f1f5f9; font-weight: 600;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group" style="margin-top: 1.5rem;">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" class="form-control" rows="2" placeholder="e.g., Partial payment received..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f1f5f9;">
                        <button type="button" onclick="closeSaleFromDeliveryModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Confirm & Record Sale
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Product Selector Modal Content -->
    <template id="productGridTemplate">
        <div class="p-grid">
            <?php foreach($products_res as $p): ?>
                <div class="p-card <?php echo $p['available_stock'] <= 0 ? 'out-of-stock' : ''; ?>" onclick='addItemFromGrid(<?php echo json_encode($p); ?>)'>
                    <h5><?php echo htmlspecialchars($p['product_name']); ?></h5>
                    <p style="font-size: 0.6rem; color: #64748b; margin: 2px 0;"><?php echo htmlspecialchars($p['unit_name'] ?? 'Unit'); ?></p>
                    <div style="display:flex; align-items:center; justify-content:space-between; gap: 0.5rem; margin-top: 0.35rem;">
                        <span style="font-weight: 800;">₱<?php echo number_format($p['retail_price'], 2); ?></span>
                        <?php
                        $stock = (float)$p['available_stock'];
                        if ($stock <= 0) {
                            $badgeStyle = 'background:#dc2626; color:white;';
                            $badgeText = 'OUT OF STOCK';
                        } elseif ($stock <= 5) {
                            $badgeStyle = 'background:#f59e0b; color:white;';
                            $badgeText = 'LOW: ' . rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.');
                        } else {
                            $badgeStyle = 'background:#10b981; color:white;';
                            $badgeText = 'STOCK: ' . rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.');
                        }
                        ?>
                        <span style="font-size: 0.6rem; padding: 4px 10px; border-radius: 999px; font-weight: 800; letter-spacing: 0.3px; <?php echo $badgeStyle; ?>">
                            <?php echo $badgeText; ?>
                        </span>
                    </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </template>

