# Sales and Delivery Workflow Documentation

## Overview
This document describes the complete sales and delivery workflow for the VIP Villanueva Ice Plant system. The key principle is that **inventory is only reduced when payment is received and a sale is recorded**, not at delivery.

## Workflow Summary

### 1. Production & Inventory
- Ice is produced and stored in cold storage
- Production is recorded in the `productions` table
- Inventory is tracked in `stockin_inventory` table

### 2. Order Creation
- Customer places a pre-order (phone call) or walks in
- System records the order in `orders` and `order_details` tables
- **No inventory is deducted at this point**

### 3. Delivery
- Delivery is scheduled for pre-orders
- Delivery information is recorded in `delivery` table
- Each product in the delivery is tracked in `delivery_detail`, including:
  - Received quantities
  - Damaged quantities
  - Delivery status (Scheduled, In Transit, Delivered)
- **Inventory is still NOT reduced at delivery**

### 4. Payment & Sale
- Customer pays the delivery rider (at delivery location)
- **Delivery rider returns to the plant with the payment**
- **Cashier receives payment from delivery rider** ← **SALES ARE RECORDED HERE**
- **Only at this point is inventory reduced**
- Sale is recorded in `sales` table with line items in `sale_details`
- `sale_source` table indicates whether sale is from:
  - **Pre-order (wholesale pricing)** - linked via Delivery_ID
  - **Walk-in (retail pricing)** - no Delivery_ID

**CRITICAL:** Sales do NOT happen during delivery or order completion. Sales happen ONLY when the cashier receives payment from the delivery rider.

### 5. Handling Damages & Partial Delivery
- Damaged items and partial deliveries are tracked in `delivery_detail`
- These are reflected in `sales` and `sale_details` with adjustments
- Only the actual received quantity minus damages is sold and reduces inventory

## Database Tables

### delivery
- `Delivery_ID` (PK)
- `Order_ID` (FK to orders, nullable for walk-ins)
- `delivery_address`
- `schedule_date`
- `actual_date_arrived`
- `delivery_status` (Scheduled, In Transit, Delivered)
- `delivered_by`
- `delivered_to`

### delivery_detail
- `Delivery_Detail_ID` (PK)
- `Delivery_ID` (FK)
- `Order_detail_ID` (FK)
- `Damage_ID` (nullable)
- `received_qty`
- `damage_qty`
- `remarks`
- `status` (Pending, Delivered, Partial, Damaged)

### sales
- `Sale_ID` (PK)
- `Delivery_Detail_ID` (nullable)
- `Delivery_ID` (nullable)
- `Order_detail_ID` (nullable)
- `Damage_ID` (nullable)
- `received_qty`
- `damage_qty`
- `remarks`
- `status` (Pending, Completed, Cancelled)

### sale_details
- `Sale_detail_ID` (PK)
- `Sale_ID` (FK)
- `Product_ID` (FK)
- `quantity` (actual sold quantity = received - damaged)
- `unit_price` (wholesale for pre-orders, retail for walk-ins)
- `subtotal`

### sale_source
- `Sale_delivery_ID` (PK)
- `Delivery_ID` (nullable - NULL means walk-in)
- `Sale_ID` (FK)
- Used to determine pricing: if Delivery_ID exists = wholesale, if NULL = retail

## Key Features

### Pricing Logic
- **Pre-orders (wholesale)**: Uses `unit_price` from `order_details` table
- **Walk-ins (retail)**: Uses `retail_price` from `products` table

### Inventory Reduction
- Inventory is **only** reduced in `sales_backend.php` when:
  1. Sale is created from delivery (payment received)
  2. Walk-in sale is recorded (immediate payment)
- The `deductInventory()` function in `sales_backend.php` handles this

### Order Status Flow
```
Requested → Confirmed → Scheduled for Delivery → Out for Delivery → 
Delivered (Pending Cash Turnover) → Completed (after sale recorded)
```

## Files Created/Modified

### New Files
1. `database/sales_delivery_schema.sql` - Database schema for sales and delivery tables
2. `api/sales_backend.php` - Handles sale creation and inventory deduction
3. `api/delivery_backend.php` - Handles delivery creation and status updates
4. `api/get_delivery_details.php` - API endpoint to fetch delivery details
5. `pages/sales.php` - Sales management page
6. `pages/delivery.php` - Delivery management page

### Modified Files
1. `api/orders_backend.php` - Removed inventory deduction on order completion
2. `pages/orders.php` - Added link to create sales from delivered orders

## Usage Instructions

### Recording a Sale from Delivery (Pre-order)
**IMPORTANT:** Only record sale when delivery rider has returned with payment and handed it to cashier.

1. Delivery rider returns to plant with payment from customer
2. Cashier receives payment from delivery rider
3. Go to **Sales** page
4. Find the delivery in "Deliveries Ready for Sale" section
5. Click "Record Sale (Payment Received)" button
6. Review delivery items, adjust received/damaged quantities if needed
7. Confirm payment was received
8. Click "Confirm Payment Received & Record Sale"
9. System will:
   - Create sale record
   - Create sale_details with wholesale pricing
   - Link to delivery via sale_source
   - **Reduce inventory** (only at this point)
   - Update order status to "Completed"

### Recording a Walk-in Sale
1. Go to **Sales** page
2. Click "Record Walk-in Sale" button
3. Select customer (optional)
4. Add items (uses retail pricing automatically)
5. Click "Record Sale & Reduce Inventory"
6. System will:
   - Create sale record
   - Create sale_details with retail pricing
   - **Reduce inventory immediately**

### Managing Deliveries
1. Go to **Delivery** page
2. View all deliveries
3. Update delivery status (Scheduled → In Transit → Delivered)
4. Once status is "Delivered", go to Sales page to record the sale

## Important Notes

1. **Inventory is NEVER reduced at delivery** - only when sale is recorded
2. **Walk-ins use retail pricing**, pre-orders use **wholesale pricing**
3. **Damaged items** are tracked but don't reduce inventory (only received - damaged = sold)
4. **Partial deliveries** are supported - only the received quantity is sold
5. The `sale_source` table is critical for determining pricing and reporting

## Sample Flow Example

**January 27, 2026:**
1. Plant produces 13 blocks of ice → stored in cold storage
2. Customer John places Order #12 for 10 blocks (no inventory reduction)
3. Delivery rider delivers 10 blocks to customer (no inventory reduction)
4. Customer pays delivery rider at delivery location
5. **Delivery rider returns to plant with payment**
6. **Cashier receives payment from delivery rider** ← **SALE RECORDED HERE**
7. Cashier records sale in system → **Inventory reduced by 10 blocks**
8. Order status updated to "Completed"
9. Sale recorded in `sales` and `sale_details` tables

If 1 block was damaged during delivery:
- Received: 10 blocks
- Damaged: 1 block
- Sold: 9 blocks (inventory reduced by 9)
- 1 block remains in inventory (not sold)
