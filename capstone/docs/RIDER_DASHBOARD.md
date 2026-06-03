# Delivery Rider Dashboard

## Overview

Mobile-responsive dashboard for Delivery Riders (Role_ID = 3) to manage active deliveries, confirm COD collections, and view their daily remittance summary.

## Setup

### 1. Run Migrations

```bash
cd capstone
php database/run_rider_migrations.php
```

Or via MySQL:
```bash
mysql -u root -p vip_db < capstone/database/rider_dashboard_migrations.sql
```

### 2. Login

- Riders log in at `login.php` with `is_active = 1` and `Role_ID = 3`
- Successful login redirects to `pages/rider_view.php`

## Features

### Delivery Queue (Home)

- **Active Deliveries**: Scheduled and In Transit deliveries
- **Card Display**: Delivery ID, Customer Name, Address, COD Amount (Total_Amount)
- **Start Trip**: Updates `delivery_status` → "In Transit" and `order_status` → "Out for Delivery"
- **View Details / Complete Delivery**: Opens detail modal with:
  - Customer name and phone
  - Delivery address
  - Item checklist (Product, Ordered Qty) — read-only from order_details
  - Received Qty, Damage Qty (editable per item)
  - Delivered To (required)
  - Remarks (optional)
  - **Confirm Delivery & Collect Payment** button

### COD Confirmation Flow

1. Rider fills in Delivered To, optional Remarks, and item quantities
2. Clicks "Confirm Delivery & Collect Payment"
3. Modal shows Total_Amount from Orders table
4. Rider must confirm "Yes, I have the cash"
5. System:
   - Updates `delivery_status` → Delivered
   - Sets `actual_date_arrived` = NOW()
   - Updates `delivery_detail` (received_qty, damage_qty, remarks)
   - Sets `delivered_to`, `delivered_by_user_id`
   - Updates `order_status` → "Delivered (Pending Cash Turnover)"
   - Logs to `activity_logs`

### My Collections Tab

- Sum of `total_amount` for all deliveries completed **today** by this rider
- List of today's completed deliveries with customer and amount

### Audit Trail

- Every status change and delivery confirmation creates a row in `activity_logs`:
  - `User_ID`, `Activity` (string), `Time` (auto)

## Database Changes

| Table / Column | Purpose |
|----------------|---------|
| `activity_logs` | Audit trail (User_ID, Activity, Time) |
| `damage_types` | Melted, Broken (for Damage_ID reference) |
| `delivery.assigned_rider_id` | Optional: filter deliveries by assigned rider |
| `delivery.delivered_by_user_id` | Set on confirm; used for My Collections |

## Security

- **Access**: Restricted to `Role_ID = 3` (Delivery Rider)
- **Read-only**: Rider cannot edit `unit_price` or `total_amount`; these come from Orders/Order_details
- **User check**: Login enforces `is_active = 1`

## Files

- `pages/rider_view.php` — Main rider UI
- `api/rider_dashboard_backend.php` — confirm_delivery, get_collections
- `api/delivery_backend.php` — Start Trip (update status), activity logging
- `api/get_delivery_details.php` — Delivery items + total_amount
