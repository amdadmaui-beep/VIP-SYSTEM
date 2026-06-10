<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
    <meta name="user-role-label" content="Cashier">
    <title>Delivery Remittance - VIP Villanueva Ice Plant</title>
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
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(217, 119, 6, 0.3);
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
            background: rgba(255,255,255,0.07);
            pointer-events: none;
        }

        .remittance-header::after {
            content: '';
            position: absolute;
            left: -30px;
            bottom: -50px;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: rgba(0,0,0,0.08);
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

        /* Left Panel - Rider List */
        .rider-list-panel {
            width: 340px;
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

        #riderListContainer {
            overflow-y: auto;
            flex: 1;
            padding-right: 0.5rem;
        }

        .rider-card {
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

        .rider-card:hover {
            background: var(--bg-card-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .rider-card.selected {
            background: var(--bg-selected);
            border-color: var(--accent-blue);
            border-left-color: var(--accent-blue);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.06);
        }

        .rider-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .rider-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-primary);
        }

        .rider-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
            font-weight: 500;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-badge.status-pending {
            background: var(--status-pending-bg);
            color: var(--status-pending);
        }

        .status-badge.status-collected {
            background: var(--status-collected-bg);
            color: var(--status-collected);
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

        .rider-avatar {
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
            gap: 0.5rem;
        }

        .btn-more {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 1.1rem;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .btn-more:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
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
            padding: 1rem 0.5rem;
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

        .damage-value {
            color: var(--danger);
            font-weight: 700;
            background: var(--danger-bg);
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.8rem;
            display: inline-block;
        }

        /* Summary Section */
        .summary-section {
            padding-top: 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--text-secondary);
            padding: 0.25rem 0;
            font-weight: 500;
        }

        .summary-row.damage {
            color: var(--danger);
            font-weight: 600;
        }

        .summary-row.damage i {
            margin-right: 0.375rem;
        }

        .summary-row.total {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            padding: 1.25rem 0 0.5rem 0;
            border-top: 1px dashed var(--border);
            margin-top: 0.5rem;
        }

        /* Cash Input Section */
        .cash-section {
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }

        .cash-label {
            font-size: 0.725rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }

        .cash-input-wrapper {
            position: relative;
            width: 100%;
            margin-bottom: 1.25rem;
        }

        .currency-symbol {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-primary);
            pointer-events: none;
        }

        .cash-input {
            width: 100%;
            padding: 1rem 1.25rem 1rem 2.5rem;
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 1.4rem;
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
            padding: 1rem;
            background: var(--accent-blue);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 1rem;
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
        #riderListContainer::-webkit-scrollbar {
            width: 8px;
        }

        .detail-content::-webkit-scrollbar-track,
        #riderListContainer::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .detail-content::-webkit-scrollbar-thumb,
        #riderListContainer::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
            transition: background 0.2s ease;
        }

        .detail-content::-webkit-scrollbar-thumb:hover,
        #riderListContainer::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body>
    <header class="remittance-header">
        <div style="display: flex; align-items: center; gap: 1rem; position: relative; z-index: 1;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: white; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <i class="fas fa-truck-loading"></i>
            </div>
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 800; color: white; margin: 0; text-shadow: 0 1px 2px rgba(0,0,0,0.1);">Delivery Remittance</h1>
                <div style="font-size: 0.75rem; color: rgba(255,255,255,0.8); font-weight: 500; margin-top: 0.1rem;">
                    <i class="fas fa-user-circle" style="margin-right: 0.25rem;"></i> <?php echo htmlspecialchars($full_name); ?>
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
        <!-- Left Panel - Rider List -->
        <div class="rider-list-panel">
            <div class="panel-title">PENDING REMITTANCES</div>
            <div id="riderListContainer">
                <div class="loading-state">
                    <i class="fas fa-spinner"></i>
                </div>
            </div>
        </div>

        <!-- Right Panel - Detail Wrapper -->
        <div class="detail-panel-wrapper">
            <div class="panel-title">REMITTANCE DETAIL</div>
            <div class="detail-panel">
                <div id="detailContent">
                    <div class="empty-state">
                        <i class="fas fa-hand-pointer"></i>
                        <p>Select a rider to view remittance details</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        let allRiders = [];
        let selectedRiderId = null;
        let selectedRiderData = null;

        // Load all riders with pending remittances on page load
        async function loadAllRiders() {
            const container = document.getElementById('riderListContainer');
            
            try {
                console.log('Fetching riders...');
                const ridersResponse = await fetch('../api/get_riders.php');
                const ridersData = await ridersResponse.json();
                console.log('Riders response:', ridersData);
                
                if (!ridersData.success || !ridersData.riders || ridersData.riders.length === 0) {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-users-slash"></i><p>No riders found</p></div>';
                    return;
                }

                allRiders = ridersData.riders;
                console.log('Found riders:', allRiders);
                
                // Fetch deliveries for each rider
                const ridersWithDeliveries = [];
                
                for (const rider of allRiders) {
                    console.log(`Fetching deliveries for rider ${rider.User_ID}...`);
                    const response = await fetch(`../api/get_rider_deliveries.php?rider_id=${rider.User_ID}`);
                    const data = await response.json();
                    console.log(`Deliveries for rider ${rider.User_ID}:`, data);
                    
                    const deliveries = data.deliveries || [];
                    const totalItems = deliveries.reduce((sum, d) => sum + (d.items ? d.items.length : 0), 0);
                    
                    ridersWithDeliveries.push({
                        ...rider,
                        deliveries: deliveries,
                        totals: data.totals || { expected_remittance: 0, total_damaged_value: 0, amount_to_collect: 0 },
                        totalItems: totalItems,
                        hasPending: deliveries.length > 0
                    });
                }

                console.log('Riders with deliveries:', ridersWithDeliveries);
                renderRiderList(ridersWithDeliveries);
            } catch (error) {
                console.error('Error loading riders:', error);
                container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading riders</p></div>';
            }
        }

        function renderRiderList(riders) {
            const container = document.getElementById('riderListContainer');
            
            console.log('All riders to render:', riders);
            
            if (riders.length === 0) {
                container.innerHTML = '<div class="empty-state"><i class="fas fa-users-slash"></i><p>No riders found</p></div>';
                return;
            }

            let html = '';
            riders.forEach(rider => {
                const initials = (rider.rider_name || 'U').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
                const isSelected = selectedRiderId === rider.User_ID;
                const statusClass = rider.hasPending ? 'status-pending' : 'status-collected';
                const statusText = rider.hasPending ? 'Pending' : 'Collected';
                const statusIcon = rider.hasPending ? 'fa-clock' : 'fa-check';
                
                html += `
                    <div class="rider-card ${isSelected ? 'selected' : ''}" onclick="selectRider(${rider.User_ID}, this)">
                        <div class="rider-card-header">
                            <div>
                                <div class="rider-name">${rider.rider_name || 'Unknown Rider'}</div>
                                <div class="rider-meta">${rider.totalItems} items</div>
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

        function selectRider(riderId, element) {
            selectedRiderId = riderId;
            const rider = allRiders.find(r => r.User_ID === riderId);
            
            if (!rider) return;

            // Update rider list selection
            document.querySelectorAll('.rider-card').forEach(card => {
                card.classList.remove('selected');
            });
            if (element) element.classList.add('selected');

            // Show loading state
            document.getElementById('detailContent').innerHTML = `
                <div class="loading-state">
                    <i class="fas fa-spinner"></i>
                </div>
            `;

            // Fetch fresh delivery data
            console.log(`Fetching details for rider ${riderId}...`);
            fetch(`../api/get_rider_deliveries.php?rider_id=${riderId}`)
                .then(res => res.json())
                .then(data => {
                    console.log('Rider details:', data);
                    if (data.success && data.deliveries && data.deliveries.length > 0) {
                        selectedRiderData = data;
                        renderDetail(rider, data);
                    } else {
                        document.getElementById('detailContent').innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No pending deliveries for this rider</p>
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

        function renderDetail(rider, data) {
            const initials = rider.rider_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
            const hasPending = data.deliveries.length > 0;
            const statusClass = hasPending ? 'status-pending' : 'status-collected';
            const statusText = hasPending ? 'Pending collection' : 'Collected';
            const statusIcon = hasPending ? 'fa-clock' : 'fa-check-circle';
            
            let grandExpected = 0;
            let grandDamaged = 0;
            let deliveriesHtml = '';

            data.deliveries.forEach((delivery, dIndex) => {
                let deliveryExpected = 0;
                let deliveryDamaged = 0;
                let deliveryItemsHtml = '';
                
                if (delivery.items) {
                    delivery.items.forEach(item => {
                        const collected = (item.ordered_qty || 0) - (item.damage_qty || 0);
                        const subtotal = collected * (item.unit_price || 0);
                        const damageQty = item.damage_qty || 0;
                        
                        const damageHtml = damageQty > 0 
                            ? `<span class="damage-value">${damageQty}</span>` 
                            : `<span style="color: var(--text-muted); font-weight: 500;">0</span>`;
                            
                        deliveryExpected += (item.ordered_qty || 0) * (item.unit_price || 0);
                        deliveryDamaged += (item.damage_qty || 0) * (item.unit_price || 0);
                        
                        deliveryItemsHtml += `
                            <tr>
                                <td style="font-weight: 600; padding: 0.75rem 0.5rem; text-align: left;">${item.product_name || 'Unknown'}</td>
                                <td class="text-center" style="font-weight: 500; padding: 0.75rem 0.5rem;">${item.ordered_qty || 0}</td>
                                <td class="text-center" style="padding: 0.75rem 0.5rem;">${damageHtml}</td>
                                <td class="text-center" style="font-weight: 500; padding: 0.75rem 0.5rem;">${collected}</td>
                                <td class="text-right" style="color: var(--text-secondary); padding: 0.75rem 0.5rem;">₱${(item.unit_price || 0).toFixed(2)}</td>
                                <td class="text-right" style="font-weight: 700; color: var(--text-primary); padding: 0.75rem 0.5rem;">₱${subtotal.toFixed(2)}</td>
                            </tr>
                        `;
                    });
                }
                
                const deliveryCollectible = deliveryExpected - deliveryDamaged;
                grandExpected += deliveryExpected;
                grandDamaged += deliveryDamaged;
                
                deliveriesHtml += `
                    <!-- Order block -->
                    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md); margin-bottom: 1rem; box-shadow: var(--shadow-sm); overflow: hidden;">
                        <!-- Collapsible Header -->
                        <div id="order-header-${delivery.Delivery_ID}" 
                             onclick="toggleOrderBlock(${delivery.Delivery_ID})" 
                             style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 1rem 1.25rem; transition: all 0.2s ease;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: #eff6ff; color: var(--accent-blue);">
                                    <i class="fas fa-shopping-bag"></i>
                                </span>
                                <div style="text-align: left;">
                                    <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary);">Order #${delivery.Order_ID}</div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-user" style="margin-right: 0.25rem;"></i> ${delivery.customer_name || 'Walk-in Customer'}
                                        ${delivery.is_ar ? `<span style="display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; font-size: 0.65rem; font-weight: 700; background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc;"><i class="fas fa-file-invoice-dollar"></i> AR</span>` : ''}
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <div style="text-align: right;">
                                    <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Amount to Collect</div>
                                    <div style="font-weight: 700; font-size: 0.95rem; color: var(--accent-blue);">₱${deliveryCollectible.toFixed(2)}</div>
                                </div>
                                <span id="chevron-${delivery.Delivery_ID}" style="color: var(--text-muted); font-size: 0.9rem; transition: transform 0.2s ease;">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Collapsible Body (Hidden by default) -->
                        <div id="order-body-${delivery.Delivery_ID}" style="display: none; padding: 1.25rem; background: #ffffff; border-top: 1px solid var(--border);">
                            <!-- Items table -->
                            <div style="overflow-x: auto; margin-bottom: 1.25rem;">
                                <table class="items-table" style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr>
                                            <th style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); border-bottom: 1.5px solid #e2e8f0; padding: 0.5rem; text-align: left;">Product</th>
                                            <th class="text-center" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); border-bottom: 1.5px solid #e2e8f0; padding: 0.5rem;">Qty Out</th>
                                            <th class="text-center" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); border-bottom: 1.5px solid #e2e8f0; padding: 0.5rem;">Damaged</th>
                                            <th class="text-center" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); border-bottom: 1.5px solid #e2e8f0; padding: 0.5rem;">Collected</th>
                                            <th class="text-right" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); border-bottom: 1.5px solid #e2e8f0; padding: 0.5rem;">Unit</th>
                                            <th class="text-right" style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); border-bottom: 1.5px solid #e2e8f0; padding: 0.5rem;">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${deliveryItemsHtml}
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Financial Summary & Cash Entry Section -->
                            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1.25rem; margin-top: 1rem;">
                                <!-- Left side: Local calculations -->
                                <div style="display: flex; flex-direction: column; gap: 0.5rem; justify-content: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-sm); padding: 1rem;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-secondary); font-weight: 500;">
                                        <span>Gross amount</span>
                                        <span style="font-weight: 600; color: var(--text-primary);">₱${deliveryExpected.toFixed(2)}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--danger); font-weight: 600;">
                                        <span><i class="fas fa-exclamation-triangle" style="margin-right: 0.25rem;"></i> Damage deduction${delivery.has_pending_damage ? ' <span style="font-weight:400;color:#d97706;font-size:0.7rem;">(pending review)</span>' : ''}</span>
                                        <span>-₱${deliveryDamaged.toFixed(2)}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem; font-weight: 700; color: var(--text-primary); border-top: 1px dashed #e2e8f0; padding-top: 0.5rem; margin-top: 0.25rem;">
                                        <span>${delivery.is_ar ? 'AR Receivable' : 'Expected remittance'}</span>
                                        <span style="color: var(--accent-blue);">₱${deliveryCollectible.toFixed(2)}</span>
                                    </div>
                                </div>
                                
                                <!-- Right side: Cash input & confirm button -->
                                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                    <div>
                                        <label class="cash-label" style="margin-bottom: 0.35rem; display: block; font-size: 0.725rem; color: var(--text-secondary);">${delivery.is_ar ? 'Cash Collected from Customer' : 'Actual Cash Received from Rider'}</label>
                                        <div class="cash-input-wrapper" style="margin-bottom: 0;">
                                            <span class="currency-symbol" style="font-size: 1.1rem; left: 0.875rem;">₱</span>
                                            <input type="number" 
                                                   id="cashInput-${delivery.Delivery_ID}" 
                                                   class="cash-input" 
                                                   step="0.01" 
                                                   min="0" 
                                                   placeholder="0.00"
                                                   style="padding: 0.5rem 1rem 0.5rem 1.875rem; font-size: 1.1rem; border-radius: var(--radius-md);"
                                                   oninput="validateCashInput(${delivery.Delivery_ID}, ${deliveryCollectible}, ${!!delivery.is_ar})"
                                                   ${delivery.rider_collected_amount != null && delivery.rider_collected_amount > 0 ? `value="${delivery.rider_collected_amount.toFixed(2)}"` : ''}>
                                         </div>
                                     </div>
                                     
                                     ${delivery.is_ar ? `
                                    <button class="btn-confirm" 
                                            id="confirmBtn-${delivery.Delivery_ID}" 
                                            style="padding: 0.6rem 1rem; font-size: 0.9rem; border-radius: var(--radius-md); background: #6366f1;"
                                            onclick="postToAR(${dIndex})">
                                        <i class="fas fa-file-invoice-dollar"></i> Post to AR
                                    </button>
                                    ` : `
                                    <button class="btn-confirm" 
                                            id="confirmBtn-${delivery.Delivery_ID}" 
                                            style="padding: 0.6rem 1rem; font-size: 0.9rem; border-radius: var(--radius-md);"
                                            onclick="confirmRemittanceForDelivery(${dIndex})" 
                                            disabled>
                                        <i class="fas fa-check-circle"></i> Confirm receipt
                                    </button>
                                    `}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            const grandCollectible = grandExpected - grandDamaged;

            document.getElementById('detailContent').innerHTML = `
                <div class="detail-header">
                    <div style="display: flex; align-items: center; gap: 1rem; flex: 1;">
                        <div class="rider-avatar">${initials}</div>
                        <div class="detail-header-info">
                            <h2>${rider.rider_name}</h2>
                            <div class="detail-header-meta">
                                <span class="status-badge ${statusClass}">
                                    <i class="fas ${statusIcon}"></i> ${statusText}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-content" style="max-height: calc(100vh - 180px); overflow-y: auto; padding-right: 0.5rem;">
                    <!-- Overall Remittance Summary Card -->
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-primary); text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-calculator" style="color: var(--accent-blue); font-size: 0.85rem;"></i> Overall Remittance Summary
                            </div>
                            <span class="status-badge status-pending" style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 9999px;">
                                <i class="fas fa-clock"></i> ${data.deliveries.length} Pending Orders
                            </span>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-secondary); font-weight: 500;">
                                <span>Overall Gross Amount</span>
                                <span style="font-weight: 600; color: var(--text-primary);">₱${grandExpected.toFixed(2)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--danger); font-weight: 600;">
                                <span><i class="fas fa-exclamation-triangle" style="margin-right: 0.25rem;"></i> Overall Damage Deduction</span>
                                <span>-₱${grandDamaged.toFixed(2)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 700; color: var(--text-primary); border-top: 1px dashed var(--border); padding-top: 0.75rem; margin-top: 0.25rem;">
                                <span>Total Pending Remittance</span>
                                <span style="color: var(--accent-blue); font-size: 1.35rem;">₱${grandCollectible.toFixed(2)}</span>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 0.5rem;">
                        <div class="section-title">DELIVERIES LIST</div>
                        ${deliveriesHtml}
                    </div>
                </div>
            `;

            // Store data for selected rider context
            selectedRiderData = data;
        }

        function toggleOrderBlock(deliveryId) {
            const body = document.getElementById(`order-body-${deliveryId}`);
            const chevron = document.getElementById(`chevron-${deliveryId}`);
            const header = document.getElementById(`order-header-${deliveryId}`);
            
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

        function validateCashInput(deliveryId, collectible, isAr) {
            const inputVal = parseFloat(document.getElementById(`cashInput-${deliveryId}`).value) || 0;
            const btn = document.getElementById(`confirmBtn-${deliveryId}`);
            // AR orders allow partial collection (remainder goes to AR balance)
            btn.disabled = isAr ? false : (inputVal < collectible - 0.01 || collectible <= 0);
        }

        async function confirmRemittanceForDelivery(deliveryIndex) {
            if (!selectedRiderData) return;
            
            const delivery = selectedRiderData.deliveries[deliveryIndex];
            if (!delivery) return;
            
            const cashReceived = parseFloat(document.getElementById(`cashInput-${delivery.Delivery_ID}`).value) || 0;
            const toCollect = delivery.delivery_amount_to_collect;
            
            if (cashReceived < toCollect - 0.01) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Cash received is less than expected remittance',
                    confirmButtonColor: 'var(--accent-blue)'
                });
                return;
            }
            
            const confirmResult = await Swal.fire({
                title: 'Confirm Receipt?',
                html: `
                    <div style="text-align: left; font-size: 0.9rem; padding: 0.5rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary);">
                        <p><strong>Rider:</strong> ${selectedRiderData.rider.rider_name}</p>
                        <p><strong>Order ID:</strong> #${delivery.Order_ID}</p>
                        <p><strong>Expected Remittance:</strong> ₱${toCollect.toFixed(2)}</p>
                        <p><strong>Cash Received:</strong> ₱${cashReceived.toFixed(2)}</p>
                        <p style="border-top: 1px dashed var(--border); padding-top: 0.5rem; margin-top: 0.25rem;"><strong>Variance:</strong> ₱${(cashReceived - toCollect).toFixed(2)}</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Confirm',
                cancelButtonText: 'Cancel',
                confirmButtonColor: 'var(--accent-blue)',
                cancelButtonColor: 'var(--text-muted)'
            });
            
            if (!confirmResult.isConfirmed) return;
            
            Swal.fire({
                title: 'Recording remittance...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            try {
                const formData = new FormData();
                formData.append('rider_id', selectedRiderData.rider.User_ID);
                
                const deliveriesPayload = [{
                    delivery_id: delivery.Delivery_ID,
                    order_id: delivery.Order_ID,
                    items: delivery.items.map(item => ({
                        delivery_detail_id: item.delivery_detail_id,
                        order_detail_id: item.order_detail_id,
                        product_id: item.product_id,
                        ordered_qty: item.ordered_qty,
                        damage_qty: item.damage_qty
                    }))
                }];
                
                formData.append('deliveries', JSON.stringify(deliveriesPayload));
                formData.append('cash_received', cashReceived);
                formData.append('csrf_token', CSRF_TOKEN);
                
                const response = await fetch('../api/record_delivery_remittance.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Remittance Success',
                        html: `
                            <div style="text-align: left; font-size: 0.9rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary);">
                                <p>Sale #${data.sale_id} has been recorded for Order #${delivery.Order_ID}.</p>
                                <p><strong>Expected:</strong> ₱${data.expected_remittance.toFixed(2)}</p>
                                <p><strong>Damaged:</strong> ₱${data.damaged_value.toFixed(2)}</p>
                                <p><strong>Collected:</strong> ₱${data.cash_received.toFixed(2)}</p>
                                <p><strong>Variance:</strong> ₱${data.variance.toFixed(2)} (${data.remittance_status})</p>
                            </div>
                        `,
                        confirmButtonText: 'Done',
                        confirmButtonColor: 'var(--accent-blue)'
                    });
                    
                    const originalRiderId = selectedRiderId;
                    await loadAllRiders();
                    
                    if (originalRiderId) {
                        const updatedRider = allRiders.find(r => r.User_ID === originalRiderId);
                        if (updatedRider && updatedRider.hasPending) {
                            selectRider(originalRiderId);
                        } else {
                            document.getElementById('detailContent').innerHTML = `
                                <div class="empty-state">
                                    <i class="fas fa-hand-pointer"></i>
                                    <p>Select a rider to view remittance details</p>
                                </div>
                            `;
                            selectedRiderId = null;
                            selectedRiderData = null;
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to record remittance',
                        confirmButtonColor: 'var(--accent-blue)'
                    });
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while recording remittance',
                    confirmButtonColor: 'var(--accent-blue)'
                });
            }
        }

        // Post delivery to AR
        async function postToAR(deliveryIndex) {
            if (!selectedRiderData) return;

            const delivery = selectedRiderData.deliveries[deliveryIndex];
            if (!delivery) return;

            const cashReceived = parseFloat(document.getElementById(`cashInput-${delivery.Delivery_ID}`).value) || 0;
            const deliveryCollectible = delivery.delivery_amount_to_collect;

            const arBalance = deliveryCollectible - cashReceived;

            const result = await Swal.fire({
                title: 'Post to Accounts Receivable?',
                html: `
                    <div style="text-align: left; font-size: 0.9rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary);">
                        <p><strong>Order #${delivery.Order_ID}</strong> — ${delivery.customer_name}</p>
                        <hr style="border-color: var(--border);">
                        <p><strong>Gross:</strong> ₱${deliveryCollectible.toFixed(2)}</p>
                        <p><strong>Cash Collected:</strong> ₱${cashReceived.toFixed(2)}</p>
                        <p style="border-top: 1px dashed var(--border); padding-top: 0.5rem; color: #b45309;"><strong>AR Balance:</strong> ₱${arBalance.toFixed(2)}</p>
                        <p style="font-size: 0.8rem; color: var(--text-muted);">
                            <i class="fas fa-info-circle"></i> A sale will be recorded for inventory deduction.
                            The AR record will be created for the remaining balance.
                            ${delivery.customer_email ? 'A receipt will be sent to the customer email.' : ''}
                        </p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-file-invoice-dollar"></i> Post to AR',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#6366f1',
                cancelButtonColor: 'var(--text-muted)'
            });

            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Posting to AR...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const formData = new FormData();
                formData.append('rider_id', selectedRiderData.rider.User_ID);
                formData.append('delivery_id', delivery.Delivery_ID);
                formData.append('order_id', delivery.Order_ID);
                formData.append('cash_received', cashReceived);

                const deliveriesPayload = [{
                    delivery_id: delivery.Delivery_ID,
                    order_id: delivery.Order_ID,
                    items: delivery.items.map(item => ({
                        delivery_detail_id: item.delivery_detail_id,
                        order_detail_id: item.order_detail_id,
                        product_id: item.product_id,
                        ordered_qty: item.ordered_qty,
                        damage_qty: item.damage_qty
                    }))
                }];
                formData.append('deliveries', JSON.stringify(deliveriesPayload));
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch('../api/delivery_ar_post.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'AR Recorded',
                        html: `
                            <div style="text-align: left; font-size: 0.9rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary);">
                                <p>Order #${delivery.Order_ID} has been posted as AR.</p>
                                <p><strong>Sale #${data.sale_id}</strong> recorded for inventory.</p>
                                ${data.ar_id ? `<p><strong>AR Reference:</strong> AR-${data.ar_id}</p>` : ''}
                                <p><strong>AR Balance:</strong> ₱${data.ar_balance.toFixed(2)}</p>
                                ${data.email_sent ? '<p style="color: #059669;"><i class="fas fa-check-circle"></i> Receipt sent to customer email.</p>' : '<p style="color: var(--text-muted);">No email on file — receipt not sent.</p>'}
                                ${data.ar_notice_sent ? '<p style="color: #059669;"><i class="fas fa-check-circle"></i> AR notice sent to customer email.</p>' : '<p style="color: var(--text-muted);">AR notice email was not sent.</p>'}
                            </div>
                        `,
                        confirmButtonText: 'Done',
                        confirmButtonColor: '#6366f1'
                    });

                    const originalRiderId = selectedRiderId;
                    await loadAllRiders();

                    if (originalRiderId) {
                        const updatedRider = allRiders.find(r => r.User_ID === originalRiderId);
                        if (updatedRider && updatedRider.hasPending) {
                            selectRider(originalRiderId);
                        } else {
                            document.getElementById('detailContent').innerHTML = `
                                <div class="empty-state">
                                    <i class="fas fa-hand-pointer"></i>
                                    <p>Select a rider to view remittance details</p>
                                </div>
                            `;
                            selectedRiderId = null;
                            selectedRiderData = null;
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to post to AR',
                        confirmButtonColor: 'var(--accent-blue)'
                    });
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while posting to AR',
                    confirmButtonColor: 'var(--accent-blue)'
                });
            }
        }

        // Initialize
        loadAllRiders();
    </script>
</body>
</html>
