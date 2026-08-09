<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
    <title>AR Payments - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" href="data:,">
    <style>
        :root {
            --bg-primary: #f8fafc; /* Sleek Slate-50 */
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --bg-card-hover: #f1f5f9; /* Slate-100 */
            --bg-selected: #eff6ff; /* Soft active blue */
            --border: #e2e8f0; /* Slate-200 */
            --text-primary: #0f172a; /* Slate-900 */
            --text-secondary: #475569; /* Slate-600 */
            --text-muted: #94a3b8; /* Slate-400 */
            --accent-blue: #2563eb; /* Royal blue-600 */
            --accent-blue-hover: #1d4ed8; /* Blue-700 */
            --status-pending: #d97706; /* Amber-600 */
            --status-pending-bg: #fef3c7; /* Amber-100 */
            --status-collected: #059669; /* Emerald-600 */
            --status-collected-bg: #d1fae5; /* Emerald-100 */
            --danger: #dc2626; /* Red-600 */
            --danger-bg: #fee2e2; /* Red-100 */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.03), 0 4px 6px -4px rgba(0, 0, 0, 0.03);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .remittance-header {
            background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.25);
            z-index: 10;
            position: relative;
            overflow: hidden;
        }

        .remittance-header::before {
            content: '';
            position: absolute;
            right: -60px;
            top: -40px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
            pointer-events: none;
        }

        .remittance-header h1 {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: white;
            position: relative;
            z-index: 1;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            position: relative;
            z-index: 1;
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
            backdrop-filter: blur(4px);
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }

        .container {
            display: flex;
            gap: 2rem;
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
            flex: 1;
            height: calc(100vh - 65px);
            overflow: hidden;
        }

        /* Left Panel - Customer List */
        .customer-list-panel {
            width: 350px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .panel-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.075em;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        #customerListContainer {
            overflow-y: auto;
            flex: 1;
            padding-right: 0.5rem;
        }

        .customer-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 0.875rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid transparent;
        }

        .customer-card:hover {
            background: var(--bg-card-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .customer-card.selected {
            background: var(--bg-selected);
            border-color: var(--accent-blue);
            border-left-color: var(--accent-blue);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.06);
        }

        .customer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .customer-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .customer-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.35rem;
            font-weight: 500;
        }

        .customer-balance {
            font-size: 1rem;
            font-weight: 700;
            color: var(--accent-blue);
            margin-top: 0.5rem;
            text-align: left;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.725rem;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .status-badge.status-pending {
            background: var(--status-pending-bg);
            color: var(--status-pending);
        }

        .status-badge.status-collected {
            background: var(--status-collected-bg);
            color: var(--status-collected);
        }

        .status-badge.status-overdue {
            background: var(--danger-bg);
            color: var(--danger);
        }

        /* Right Panel - Detail Wrapper */
        .detail-panel-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .detail-panel {
            flex: 1;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-md);
        }

        .detail-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }

        .customer-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-blue), #6366f1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.15);
        }

        .detail-header-info h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .detail-header-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .detail-content {
            padding: 1.75rem;
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
        }

        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.075em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            text-align: left;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            padding: 0.75rem 0.5rem;
            border-bottom: 1.5px solid var(--border);
        }

        .items-table th.text-right {
            text-align: right;
        }

        .items-table th.text-center {
            text-align: center;
        }

        .items-table td {
            padding: 0.875rem 0.5rem;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
        }

        .items-table td.text-right {
            text-align: right;
        }

        .items-table td.text-center {
            text-align: center;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        /* Cash Input Section */
        .cash-section {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .cash-label {
            font-size: 0.725rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .cash-input-wrapper {
            position: relative;
            width: 100%;
        }

        .currency-symbol {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            pointer-events: none;
        }

        .cash-input {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 1.875rem;
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 700;
            font-family: inherit;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
        }

        .cash-input:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .cash-input::placeholder {
            color: var(--text-muted);
            font-weight: 500;
        }

        .btn-confirm {
            width: 100%;
            padding: 0.6rem 1rem;
            background: var(--accent-blue);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.15);
        }

        .btn-confirm:hover:not(:disabled) {
            background: var(--accent-blue-hover);
            box-shadow: 0 6px 12px -1px rgba(37, 99, 235, 0.25);
            transform: translateY(-1px);
        }

        .btn-confirm:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-confirm:disabled {
            opacity: 0.5;
            background: var(--text-muted);
            box-shadow: none;
            cursor: not-allowed;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
            text-align: center;
            padding: 3rem;
        }

        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 1.25rem;
            color: var(--text-muted);
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .loading-state {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
        }

        .loading-state i {
            font-size: 1.75rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Scrollbar */
        .detail-content::-webkit-scrollbar,
        #customerListContainer::-webkit-scrollbar {
            width: 8px;
        }

        .detail-content::-webkit-scrollbar-track,
        #customerListContainer::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .detail-content::-webkit-scrollbar-thumb,
        #customerListContainer::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
            transition: background 0.2s ease;
        }

        .detail-content::-webkit-scrollbar-thumb:hover,
        #customerListContainer::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .overdue-text {
            color: var(--danger);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <header class="remittance-header">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: white; flex-shrink: 0;">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 800; color: white; margin: 0;">Accounts Receivable Payments</h1>
                <div style="font-size: 0.75rem; color: rgba(255,255,255,0.75); font-weight: 500; margin-top: 0.1rem;">
                    <i class="fas fa-user-circle" style="margin-right: 0.25rem;"></i> <?php echo htmlspecialchars($full_name); ?>
                    <?php if ($is_view_only): ?>
                        <span style="margin-left: 0.5rem; background: rgba(255,255,255,0.2); border-radius: 99px; padding: 0.1rem 0.5rem; font-size: 0.65rem; font-weight: 700;">View Only</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn-back" onclick="window.location.href='cashier_view.php'">
                <i class="fas fa-arrow-left"></i> Back to POS
            </button>
        </div>
    </header>

    <div class="container">
        <!-- Left Panel - Customer List -->
        <div class="customer-list-panel">
            <div class="panel-title">PENDING AR CUSTOMERS</div>
            <div id="customerListContainer">
                <div class="loading-state">
                    <i class="fas fa-spinner"></i>
                </div>
            </div>
        </div>

        <!-- Right Panel - Detail Wrapper -->
        <div class="detail-panel-wrapper">
            <div class="panel-title">PAYMENT DETAIL</div>
            <div class="detail-panel">
                <div id="detailContent">
                    <div class="empty-state">
                        <i class="fas fa-hand-pointer"></i>
                        <p>Select a customer to view accounts receivable payment details</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        let allCustomers = [];
        let selectedCustomerId = null;
        let selectedCustomerData = null;
        const isViewOnly = <?php echo $is_view_only ? 'true' : 'false'; ?>;

        // Load all customers with pending AR on page load
        async function loadAllCustomers() {
            const container = document.getElementById('customerListContainer');
            
            try {
                console.log('Fetching AR customers...');
                const response = await fetch('../api/get_customer_ar_remittance.php');
                const data = await response.json();
                console.log('Customers response:', data);
                
                if (!data.success || !data.customers || data.customers.length === 0) {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-users-slash"></i><p>No customers with pending AR balances</p></div>';
                    return;
                }

                allCustomers = data.customers;
                renderCustomerList(allCustomers);
            } catch (error) {
                console.error('Error loading customers:', error);
                container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading customers</p></div>';
            }
        }

        function renderCustomerList(customers) {
            const container = document.getElementById('customerListContainer');
            
            if (customers.length === 0) {
                container.innerHTML = '<div class="empty-state"><i class="fas fa-users-slash"></i><p>No customers with pending AR balances</p></div>';
                return;
            }

            let html = '';
            customers.forEach(cust => {
                const isSelected = selectedCustomerId === cust.Customer_ID;
                const statusClass = 'status-pending';
                const statusText = 'Unpaid';
                const statusIcon = 'fa-clock';
                
                html += `
                    <div class="customer-card ${isSelected ? 'selected' : ''}" onclick="selectCustomer(${cust.Customer_ID}, this)">
                        <div class="customer-card-header">
                            <div>
                                <div class="customer-name">${escapeHtml(cust.customer_name || 'Unknown Customer')}</div>
                                <div class="customer-meta">${cust.pending_count} pending account${cust.pending_count > 1 ? 's' : ''}</div>
                                <div class="customer-balance">₱${parseFloat(cust.pending_balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                            </div>
                            <span class="status-badge ${statusClass}">
                                <i class="fas ${statusIcon}"></i> ${statusText}
                            </span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function selectCustomer(customerId, element) {
            selectedCustomerId = customerId;
            const customer = allCustomers.find(c => c.Customer_ID === customerId);
            
            if (!customer) return;

            // Update list selection UI
            document.querySelectorAll('.customer-card').forEach(card => {
                card.classList.remove('selected');
            });
            if (element) element.classList.add('selected');

            // Show loading state on details
            document.getElementById('detailContent').innerHTML = `
                <div class="loading-state">
                    <i class="fas fa-spinner"></i>
                </div>
            `;

            // Fetch fresh customer details
            console.log(`Fetching details for customer ${customerId}...`);
            fetch(`../api/get_customer_ar_remittance.php?customer_id=${customerId}`)
                .then(res => res.json())
                .then(data => {
                    console.log('Customer details response:', data);
                    if (data.success && data.ar_records && data.ar_records.length > 0) {
                        selectedCustomerData = data;
                        renderDetail(data);
                    } else {
                        document.getElementById('detailContent').innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No pending accounts receivable for this customer</p>
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    document.getElementById('detailContent').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Error loading details</p>
                        </div>
                    `;
                });
        }

        function renderDetail(data) {
            const cust = data.customer;
            const initials = cust.customer_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
            
            let totalDue = data.total_due;
            const creditLimit = parseFloat(cust.credit_limit || 0);
            const remainingCredit = Math.max(0, creditLimit - totalDue);

            let arRecordsHtml = '';

            data.ar_records.forEach((record, index) => {
                let itemsHtml = '';
                let recordTotal = 0;

                if (record.items && record.items.length > 0) {
                    record.items.forEach(item => {
                        recordTotal += item.subtotal;
                        itemsHtml += `
                            <tr>
                                <td style="font-weight: 600; padding: 0.6rem 0.5rem; text-align: left;">${escapeHtml(item.product_name)}</td>
                                <td class="text-center" style="font-weight: 500; padding: 0.6rem 0.5rem;">${item.quantity} ${escapeHtml(item.unit_name)}</td>
                                <td class="text-right" style="color: var(--text-secondary); padding: 0.6rem 0.5rem;">₱${item.unit_price.toFixed(2)}</td>
                                <td class="text-right" style="font-weight: 700; color: var(--text-primary); padding: 0.6rem 0.5rem;">₱${item.subtotal.toFixed(2)}</td>
                            </tr>
                        `;
                    });
                } else {
                    itemsHtml = `
                        <tr>
                            <td colspan="4" class="text-center" style="color: var(--text-muted); font-style: italic; padding: 1rem 0.5rem;">
                                No item details available (Manual Entry AR)
                            </td>
                        </tr>
                    `;
                }

                // Overdue status check
                const isOverdue = new Date(record.due_date) < new Date() && record.amount_due > 0;
                const statusClass = isOverdue ? 'status-overdue' : 'status-pending';
                const statusText = isOverdue ? 'Overdue' : 'Open';
                const statusIcon = isOverdue ? 'fa-exclamation-triangle' : 'fa-clock';

                let paymentsHtml = '';
                if (record.payments && record.payments.length > 0) {
                    record.payments.forEach(pay => {
                        paymentsHtml += `
                            <tr>
                                <td style="padding: 0.6rem 0.5rem; font-weight: 500; font-size: 0.825rem; text-align: left; border-bottom: 1px solid var(--border); color: var(--text-secondary);">${escapeHtml(pay.payment_date)}</td>
                                <td class="text-right" style="padding: 0.6rem 0.5rem; font-weight: 600; color: var(--status-collected); font-size: 0.825rem; border-bottom: 1px solid var(--border);">₱${pay.amount_paid.toFixed(2)}</td>
                                <td class="text-right" style="padding: 0.6rem 0.5rem; font-weight: 600; color: var(--text-primary); font-size: 0.825rem; border-bottom: 1px solid var(--border);">₱${pay.remaining_balance.toFixed(2)}</td>
                            </tr>
                        `;
                    });
                } else {
                    paymentsHtml = `
                        <tr>
                            <td colspan="3" class="text-center" style="color: var(--text-muted); font-style: italic; font-size: 0.8rem; padding: 1rem 0.5rem;">
                                No payments recorded yet for this invoice.
                            </td>
                        </tr>
                    `;
                }

                arRecordsHtml += `
                    <!-- AR Record Card -->
                    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md); margin-bottom: 1rem; box-shadow: var(--shadow-sm); overflow: hidden;">
                        <!-- Collapsible Header -->
                        <div id="ar-header-${record.AR_ID}" 
                             onclick="toggleARBlock(${record.AR_ID})" 
                             style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 1rem 1.25rem; transition: all 0.2s ease;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: #eff6ff; color: var(--accent-blue);">
                                    <i class="fas fa-file-invoice"></i>
                                </span>
                                <div style="text-align: left;">
                                    <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary);">
                                        AR ID: <span style="color: var(--accent-blue);">#${record.AR_ID}</span>
                                        ${record.Order_ID ? `<span style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 500; margin-left: 0.5rem;">(Order #${record.Order_ID})</span>` : ''}
                                    </div>
                                    <div style="font-size: 0.775rem; color: var(--text-muted); font-weight: 500; margin-top: 0.15rem;">
                                        <span>Invoice Date: <strong>${record.invoice_date}</strong></span>
                                        <span style="margin-left: 0.75rem;">Due Date: <strong class="${isOverdue ? 'overdue-text' : ''}">${record.due_date}</strong></span>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <div style="text-align: right;">
                                    <div style="font-size: 0.675rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Outstanding Balance</div>
                                    <div style="font-weight: 700; font-size: 0.95rem; color: var(--danger);">₱${record.amount_due.toFixed(2)}</div>
                                </div>
                                <span class="status-badge ${statusClass}" style="padding: 0.15rem 0.5rem; font-size: 0.65rem;">
                                    <i class="fas ${statusIcon}"></i> ${statusText}
                                </span>
                                <span id="chevron-${record.AR_ID}" style="color: var(--text-muted); font-size: 0.9rem; transition: transform 0.2s ease;">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Collapsible Body (Hidden by default) -->
                        <div id="ar-body-${record.AR_ID}" style="display: none; padding: 1.25rem; background: #ffffff; border-top: 1px solid var(--border);">
                            <!-- Items table -->
                            <div style="overflow-x: auto; margin-bottom: 1.25rem;">
                                <div style="font-size: 0.725rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fas fa-shopping-basket" style="color: var(--accent-blue);"></i> Items Purchased
                                </div>
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); text-align: left; padding: 0.5rem;">Product Name</th>
                                            <th class="text-center" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); padding: 0.5rem;">Qty Sold</th>
                                            <th class="text-right" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); padding: 0.5rem;">Unit Price</th>
                                            <th class="text-right" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); padding: 0.5rem;">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsHtml}
                                    </tbody>
                                </table>
                            </div>

                            <!-- Payment History table -->
                            <div style="overflow-x: auto; margin-bottom: 1.25rem; border-top: 1px solid #f1f5f9; padding-top: 1rem;">
                                <div style="font-size: 0.725rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                                    <i class="fas fa-history" style="color: var(--accent-blue);"></i> Payment History
                                </div>
                                <table class="items-table" style="min-width: 100%;">
                                    <thead>
                                        <tr>
                                            <th style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); text-align: left; padding: 0.5rem;">Payment Date</th>
                                            <th class="text-right" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); padding: 0.5rem;">Amount Paid</th>
                                            <th class="text-right" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); padding: 0.5rem;">Balance After</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${paymentsHtml}
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Financial Details & Cash Entry Section -->
                            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1.25rem; margin-top: 1rem;">
                                <!-- Left side: Financial summaries -->
                                <div style="display: flex; flex-direction: column; gap: 0.5rem; justify-content: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-sm); padding: 1rem;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-secondary); font-weight: 500;">
                                        <span>Original Invoice Amt</span>
                                        <span style="font-weight: 600; color: var(--text-primary);">₱${record.invoice_amount.toFixed(2)}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-secondary); font-weight: 500;">
                                        <span>Total Payments Applied</span>
                                        <span style="font-weight: 600; color: var(--status-collected);">₱${(record.invoice_amount - record.amount_due).toFixed(2)}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem; font-weight: 700; color: var(--text-primary); border-top: 1px dashed #e2e8f0; padding-top: 0.5rem; margin-top: 0.25rem;">
                                        <span>Amount Outstanding</span>
                                        <span style="color: var(--danger);">₱${record.amount_due.toFixed(2)}</span>
                                    </div>
                                </div>
                                
                                <!-- Right side: Cash input & confirm button -->
                                <div class="cash-section">
                                    <?php if ($is_view_only): ?>
                                        <div style="color: var(--text-muted); font-size: 0.8rem; font-style: italic; text-align: center; padding: 1rem; border: 1px dashed var(--border); border-radius: var(--radius-md); background: #f8fafc;">
                                            Payments cannot be recorded in View Only mode
                                        </div>
                                    <?php else: ?>
                                        <div>
                                            <label class="cash-label" style="display: block; font-size: 0.7rem; color: var(--text-secondary);">
                                                Actual Cash Received for AR #${record.AR_ID}
                                            </label>
                                            <div class="cash-input-wrapper">
                                                <span class="currency-symbol">₱</span>
                                                <input type="number" 
                                                       id="cashInput-${record.AR_ID}" 
                                                       class="cash-input" 
                                                       step="0.01" 
                                                       min="0.01" 
                                                       placeholder="0.00"
                                                       style="padding: 0.4rem 0.75rem 0.4rem 1.6rem; font-size: 1.1rem; border-radius: var(--radius-md);"
                                                       oninput="validateCashInput(${record.AR_ID})">
                                            </div>
                                        </div>
                                        
                                        <button class="btn-confirm" 
                                                id="confirmBtn-${record.AR_ID}" 
                                                style="padding: 0.5rem 1rem; font-size: 0.85rem; border-radius: var(--radius-md);"
                                                onclick="confirmPaymentForAR(${record.AR_ID}, ${cust.Customer_ID}, ${record.amount_due}, ${index})" 
                                                disabled>
                                            <i class="fas fa-check-circle"></i> Confirm Receipt
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            document.getElementById('detailContent').innerHTML = `
                <div class="detail-header">
                    <div style="display: flex; align-items: center; gap: 1rem; flex: 1;">
                        <div class="customer-avatar">${initials}</div>
                        <div class="detail-header-info">
                            <h2>${escapeHtml(cust.customer_name)}</h2>
                            <div class="detail-header-meta">
                                <span style="font-weight: 500; font-size: 0.8rem; color: var(--text-secondary);">
                                    <i class="fas fa-phone" style="margin-right: 0.25rem;"></i> ${escapeHtml(cust.phone_number || 'No phone')}
                                </span>
                                <span style="font-weight: 500; font-size: 0.8rem; color: var(--text-secondary);">
                                    <i class="fas fa-map-marker-alt" style="margin-right: 0.25rem;"></i> ${escapeHtml(cust.address || 'No address')}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-content" style="max-height: calc(100vh - 180px); overflow-y: auto; padding-right: 0.5rem;">
                    <!-- Overall Customer AR Summary -->
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-primary); text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-calculator" style="color: var(--accent-blue); font-size: 0.85rem;"></i> Customer AR Summary
                            </div>
                            <span class="status-badge status-pending" style="font-size: 0.7rem; padding: 0.2rem 0.6rem; border-radius: 9999px;">
                                <i class="fas fa-clock"></i> ${data.ar_records.length} Open Invoices
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 700; color: var(--text-primary); padding-top: 0.25rem;">
                            <span>Total Pending Balance</span>
                            <span style="color: var(--danger); font-size: 1.35rem;">₱${totalDue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    </div>

                    <div style="margin-bottom: 0.5rem;">
                        <div class="section-title">OUTSTANDING INVOICES LIST</div>
                        ${arRecordsHtml}
                    </div>
                </div>
            `;
        }

        function toggleARBlock(arId) {
            const body = document.getElementById(`ar-body-${arId}`);
            const chevron = document.getElementById(`chevron-${arId}`);
            const header = document.getElementById(`ar-header-${arId}`);
            
            if (body.style.display === 'none') {
                body.style.display = 'block';
                chevron.style.transform = 'rotate(180deg)';
                header.style.borderBottomLeftRadius = '0';
                header.style.borderBottomRightRadius = '0';
                header.style.background = '#f1f5f9';
            } else {
                body.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
                header.style.borderBottomLeftRadius = 'var(--radius-md)';
                header.style.borderBottomRightRadius = 'var(--radius-md)';
                header.style.background = '#f8fafc';
            }
        }

        function validateCashInput(arId) {
            const inputField = document.getElementById(`cashInput-${arId}`);
            const inputVal = parseFloat(inputField.value) || 0;
            const btn = document.getElementById(`confirmBtn-${arId}`);
            btn.disabled = inputVal <= 0;
        }

        async function confirmPaymentForAR(arId, customerId, arBalance, recordIndex) {
            if (!selectedCustomerData) return;
            
            const record = selectedCustomerData.ar_records[recordIndex];
            if (!record) return;

            const cashReceived = parseFloat(document.getElementById(`cashInput-${arId}`).value) || 0;

            if (cashReceived <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Amount',
                    text: 'Payment amount must be greater than zero.',
                    confirmButtonColor: 'var(--accent-blue)'
                });
                return;
            }

            const confirmResult = await Swal.fire({
                title: 'Confirm AR Payment?',
                html: `
                    <div style="text-align: left; font-size: 0.9rem; padding: 0.5rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary);">
                        <p><strong>Customer:</strong> ${escapeHtml(selectedCustomerData.customer.customer_name)}</p>
                        <p><strong>AR ID:</strong> #${arId}</p>
                        ${record.Order_ID ? `<p><strong>Linked Order:</strong> #${record.Order_ID}</p>` : ''}
                        <p><strong>Outstanding Balance:</strong> ₱${arBalance.toFixed(2)}</p>
                        <p style="border-top: 1px dashed var(--border); padding-top: 0.5rem; margin-top: 0.25rem;">
                            <strong>Cash Received:</strong> <span style="font-size: 1.1rem; color: var(--status-collected); font-weight: 700;">₱${cashReceived.toFixed(2)}</span>
                        </p>
                        ${cashReceived > arBalance ? `<p style="font-size: 0.8rem; color: var(--status-pending); font-style: italic;">
                            * Excess cash (₱${(cashReceived - arBalance).toFixed(2)}) will automatically be allocated to other unpaid invoices of this customer using the FIFO method.
                        </p>` : ''}
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Confirm and Apply',
                cancelButtonText: 'Cancel',
                confirmButtonColor: 'var(--accent-blue)',
                cancelButtonColor: 'var(--text-muted)'
            });

            if (!confirmResult.isConfirmed) return;

            Swal.fire({
                title: 'Recording AR payment...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const formData = new FormData();
                formData.append('action', 'record_payment');
                formData.append('customer_id', customerId);
                formData.append('ar_id', arId);
                formData.append('amount_paid', cashReceived);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('../api/ar_backend.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    let appsHtml = '';
                    if (result.applications && result.applications.length > 0) {
                        result.applications.forEach(app => {
                            appsHtml += `<li>AR ID #${app.ar_id}: Applied <strong>₱${app.applied.toFixed(2)}</strong> (New Bal: ₱${app.new_balance.toFixed(2)})</li>`;
                        });
                    }

                    await Swal.fire({
                        icon: 'success',
                        title: 'Payment Successful',
                        html: `
                            <div style="text-align: left; font-size: 0.875rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary);">
                                <p>Successfully recorded payment of <strong>₱${result.amount_paid.toFixed(2)}</strong>.</p>
                                <p style="font-weight: 600; margin-top: 0.25rem;">Allocation breakdown:</p>
                                <ul style="padding-left: 1.25rem; display: flex; flex-direction: column; gap: 0.25rem;">
                                    ${appsHtml}
                                </ul>
                                ${result.credit_balance > 0 ? `<p style="color: var(--status-pending); font-style: italic; margin-top: 0.25rem;">
                                    * Remainder of ₱${result.credit_balance.toFixed(2)} left as credit balance for customer.
                                </p>` : ''}
                                ${result.email_sent ? `<p style="color: var(--status-collected); margin-top: 0.35rem;"><i class="fas fa-envelope"></i> Payment confirmation email sent to customer.</p>` : `<p style="color: var(--text-muted); margin-top: 0.35rem;"><i class="fas fa-envelope-open"></i> No customer email on file — payment notice not sent.</p>`}
                            </div>
                        `,
                        confirmButtonText: 'Done',
                        confirmButtonColor: 'var(--accent-blue)'
                    });

                    const prevSelectedId = selectedCustomerId;
                    await loadAllCustomers();

                    if (prevSelectedId) {
                        const stillHasPending = allCustomers.some(c => c.Customer_ID === prevSelectedId);
                        if (stillHasPending) {
                            // Find element and re-select customer
                            const updatedCard = document.querySelector(`.customer-card[onclick*="selectCustomer(${prevSelectedId}"]`);
                            selectCustomer(prevSelectedId, updatedCard);
                        } else {
                            document.getElementById('detailContent').innerHTML = `
                                <div class="empty-state">
                                    <i class="fas fa-hand-pointer"></i>
                                    <p>Select a customer to view accounts receivable payment details</p>
                                </div>
                            `;
                            selectedCustomerId = null;
                            selectedCustomerData = null;
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: result.error || 'Failed to record payment',
                        confirmButtonColor: 'var(--accent-blue)'
                    });
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while recording payment',
                    confirmButtonColor: 'var(--accent-blue)'
                });
            }
        }

        function escapeHtml(string) {
            if (!string) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return string.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Initialize on load
        loadAllCustomers();
    </script>
</body>
</html>
