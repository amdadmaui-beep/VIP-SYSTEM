<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
    <title>Pickup Orders - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQodUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" href="data:,">
    <style>
        body, .pickup-wrapper {
            font-family: 'Inter', sans-serif !important;
            background: #f1f5f9;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .pickup-header {
            background: linear-gradient(135deg, #047857 0%, #065f46 100%);
            padding: 0.875rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            color: white;
            box-shadow: 0 2px 12px rgba(2, 44, 34, 0.18);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .pickup-header h1 {
            font-size: 1.1rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-weight: 700;
        }

        .pickup-header h1 i {
            font-size: 1.25rem;
        }

        .header-actions {
            display: flex;
            gap: 0.6rem;
            align-items: center;
        }

        .btn-back {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 0.5rem 1.1rem;
            border-radius: 99px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            font-family: inherit;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }

        .pickup-container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 1.75rem 1.5rem 2.5rem;
            flex: 1;
        }

        .tabs-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 0.6rem 1.25rem;
            border-radius: 99px;
            border: 1px solid #e2e8f0;
            background: white;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn.active {
            background: #047857;
            border-color: #047857;
            color: white;
        }

        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .pickup-card {
            background: white;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
            padding: 1.25rem 1.5rem;
        }

        .pickup-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.9rem;
        }

        .pickup-card-head .order-id {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
        }

        .pickup-card-head .order-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: #64748b;
            flex-wrap: wrap;
        }

        .order-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.5rem;
        }

        .items-table th {
            text-align: left;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            padding: 0.4rem 0.5rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .items-table td {
            padding: 0.45rem 0.5rem;
            font-size: 0.86rem;
            color: #334155;
            border-bottom: 1px solid #f8fafc;
        }

        .items-table td:last-child, .items-table th:last-child {
            text-align: right;
        }

        .pickup-card-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f1f5f9;
        }

        .pickup-total {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
        }

        .btn-settle {
            background: #047857;
            color: white;
            border: none;
            padding: 0.65rem 1.4rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background 0.2s ease;
        }

        .btn-settle:hover {
            background: #065f46;
        }

        .btn-settle:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 3.5rem 1rem;
            color: #94a3b8;
            background: white;
            border-radius: 14px;
            border: 1px dashed #cbd5e1;
            font-size: 0.9rem;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.65rem;
            display: block;
            color: #cbd5e1;
        }

        /* Settle Modal */
        .pu-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 200;
            align-items: flex-start;
            justify-content: center;
            padding: 3vh 1rem;
            overflow-y: auto;
        }

        .pu-modal-overlay.open {
            display: flex;
        }

        .pu-modal {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 760px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
            overflow: hidden;
        }

        .pu-modal-header {
            background: linear-gradient(135deg, #047857, #065f46);
            color: white;
            padding: 1.15rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .pu-modal-header h2 {
            margin: 0;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .pu-modal-close {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            width: 34px;
            height: 34px;
            border-radius: 99px;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .pu-modal-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .pu-items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pu-items-table th {
            text-align: left;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            padding: 0.45rem 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .pu-items-table td {
            padding: 0.55rem 0.5rem;
            font-size: 0.86rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .pu-items-table td:last-child, .pu-items-table th:last-child {
            text-align: right;
        }

        .pu-price-input {
            width: 110px;
            padding: 0.45rem 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.86rem;
            font-weight: 600;
            font-family: inherit;
            text-align: right;
            background: #f8fafc;
        }

        .pu-qty-plain {
            font-weight: 700;
            color: #0f172a;
        }

        .pu-totals {
            margin-top: 1rem;
            text-align: right;
        }

        .pu-totals .row {
            display: flex;
            justify-content: flex-end;
            gap: 1.5rem;
            padding: 0.3rem 0;
            font-size: 0.9rem;
            color: #475569;
        }

        .pu-totals .grand {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            border-top: 2px solid #e2e8f0;
            margin-top: 0.4rem;
            padding-top: 0.6rem;
        }

        .ar-post-box {
            margin-top: 1.25rem;
            padding: 1.1rem 1.25rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 0.9rem;
        }

        .field-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 0.35rem;
        }

        .field-input {
            width: 100%;
            padding: 0.55rem 0.7rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.88rem;
            font-family: inherit;
            box-sizing: border-box;
        }

        .field-input[readonly] {
            background: #f1f5f9;
            color: #334155;
            font-weight: 600;
        }

        .btn-ar-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: white;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .btn-ar-toggle.active {
            background: #eef2ff;
            border-color: #6366f1;
            color: #4338ca;
        }

        .pu-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.1rem 1.5rem;
            border-top: 1px solid #f1f5f9;
            background: #fbfdff;
        }

        .btn-cancel-soft {
            padding: 0.6rem 1.3rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-confirm-settle {
            padding: 0.65rem 1.6rem;
            border-radius: 10px;
            border: none;
            background: #047857;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-confirm-settle:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }

        .pickup-note {
            margin-top: 1.1rem;
            padding: 0.85rem 1rem;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #9a3412;
        }
    </style>
</head>
<body>
    <div class="pickup-wrapper">
        <header class="pickup-header">
            <h1><i class="fas fa-shopping-basket"></i> Pickup Orders</h1>
            <div class="header-actions">
                <button class="btn-back" onclick="loadPickupOrders('open')" title="Refresh list" id="refreshBtn">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button class="btn-back" onclick="window.location.href='cashier_view.php'" title="Back to POS">
                    <i class="fas fa-arrow-left"></i> Back to POS
                </button>
            </div>
        </header>

        <main class="pickup-container">
            <div class="tabs-bar">
                <button class="tab-btn active" id="tabOpen" onclick="setPickupTab('open')"><i class="fas fa-clock"></i> Open Pickup Orders</button>
                <button class="tab-btn" id="tabCompleted" onclick="setPickupTab('completed')"><i class="fas fa-check-double"></i> Completed</button>
            </div>
            <div class="orders-list" id="ordersList">
                <div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading pickup orders...</div>
            </div>
        </main>
    </div>

    <!-- Settle Pickup Modal -->
    <div class="pu-modal-overlay" id="settleModal">
        <div class="pu-modal">
            <div class="pu-modal-header">
                <h2><i class="fas fa-money-bill-wave"></i> Settle Pickup Order <span id="settleOrderIdLabel"></span></h2>
                <button class="pu-modal-close" onclick="closeSettleModal()"><i class="fas fa-times"></i></button>
            </div>
            <form id="settleForm">
                <div class="pu-modal-body">
                    <input type="hidden" name="action" value="create_pickup_sale">
                    <input type="hidden" name="order_id" id="settleOrderId">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="csrf_token" id="settleCsrf" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">

                    <div style="font-size: 0.85rem; color: #475569; margin-bottom: 0.9rem;">
                        <strong>Customer:</strong> <span id="settleCustomerName"></span> &nbsp;·&nbsp; <span id="settleCustomerPhone"></span>
                    </div>

                    <table class="pu-items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th style="text-align:center;width:80px;">Qty</th>
                                <th style="width:150px;">Unit Price (₱)</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="settleItemsBody">
                        </tbody>
                    </table>

                    <div class="pu-totals">
                        <div class="row"><span>Subtotal</span><span id="settleSubtotal">₱0.00</span></div>
                        <div class="row grand"><span>Total Due</span><span id="settleGrandTotal">₱0.00</span></div>
                    </div>
                    <input type="hidden" name="sale_total" id="settleSaleTotal">
                    <input type="hidden" name="discount_amount" value="0">

                    <div class="pickup-note">
                        <i class="fas fa-info-circle"></i> The full ordered quantity is settled. Unit prices are pre-filled with the product's wholesale price and may be adjusted by the cashier.
                    </div>

                    <?php if ($can_cashier_ar_sales): ?>
                    <div class="ar-post-box">
                        <button type="button" class="btn-ar-toggle" id="arToggleBtn" onclick="togglePickupAr()">
                            <i class="fas fa-file-invoice-dollar"></i> Post balance to Accounts Receivable (AR)
                        </button>
                        <div id="arPartialBox" style="display: none;">
                            <div class="form-grid-2">
                                <div>
                                    <label class="field-label" for="amountPaidInput">Amount Paid Now (₱)</label>
                                    <input type="number" class="field-input" id="amountPaidInput" name="amount_paid" step="0.01" min="0" value="0">
                                </div>
                                <div>
                                    <label class="field-label">Balance to AR (₱)</label>
                                    <input type="text" class="field-input" id="arBalanceDisplay" readonly>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="post_to_ar" id="postToArHidden" value="0">
                    </div>
                    <?php endif; ?>

                    <div style="margin-top: 1.25rem;">
                        <label class="field-label" for="settleRemarks">Remarks</label>
                        <textarea class="field-input" id="settleRemarks" name="remarks" rows="2" placeholder="Optional notes..."></textarea>
                    </div>

                    <div style="margin-top: 1.25rem;">
                        <label class="field-label" for="cashReceivedInput">Cash Received (₱)</label>
                        <input type="number" class="field-input" id="cashReceivedInput" name="cash_received" step="0.01" min="0" placeholder="0.00">
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">Leave blank for exact payment.</div>
                    </div>
                </div>
                <div class="pu-modal-footer">
                    <button type="button" class="btn-cancel-soft" onclick="closeSettleModal()"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="btn-confirm-settle" id="confirmSettleBtn"><i class="fas fa-check"></i> Confirm &amp; Record Sale</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>