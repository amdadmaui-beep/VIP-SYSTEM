# Ordering Module - System Documentation

## Overview
This document describes the Ordering Module for the VIP Villanueva Ice Plant system. The module handles the complete order lifecycle from phone call requests through delivery completion, with Cash on Delivery (COD) payment processing.

## Business Workflow

### Order Lifecycle
1. **Phone Call Received** → Staff receives order request from customer
2. **Order Entry** → Staff creates order in system with status "Requested"
3. **Availability Check** → Staff confirms inventory availability
4. **Order Confirmation** → Staff confirms order, status changes to "Confirmed"
5. **Delivery Scheduling** → Staff schedules delivery date/time, status changes to "Scheduled for Delivery"
6. **Delivery Assignment** → Staff assigns delivery personnel
7. **Dispatch** → Order status changes to "Out for Delivery"
8. **Delivery** → Order delivered to customer, status changes to "Delivered (Pending Cash Turnover)"
9. **Cash Turnover** → Delivery rider returns with cash payment
10. **Completion** → Cash handed to cashier, order status changes to "Completed", inventory deducted

### Key Distinction: Order vs Sale
- **Order**: Created when customer calls and requests products. Inventory is NOT deducted.
- **Sale**: Recorded separately AFTER delivery rider returns with cash payment. Only completed orders become sales.

## Order Status Flow

```
Requested
    ↓
Confirmed
    ↓
Scheduled for Delivery
    ↓
Out for Delivery
    ↓
Delivered (Pending Cash Turnover)
    ↓
Completed
```

**Alternative Path:**
- Any status (except Completed) → **Cancelled**

## Database Schema

### Orders Table
```sql
orders
├── Order_ID (PK)
├── order_number (UNIQUE) - Format: ORD-YYYYMMDD-XXXX
├── Customer_ID (FK → customers)
├── order_date
├── order_time
├── status (ENUM: Requested, Confirmed, Scheduled for Delivery, 
│            Out for Delivery, Delivered (Pending Cash Turnover), 
│            Completed, Cancelled)
├── total_amount
├── payment_method (Default: Cash on Delivery)
├── delivery_address
├── delivery_date
├── delivery_time
├── notes
├── created_by (FK → app_users)
├── confirmed_by (FK → app_users)
├── completed_at
├── cancelled_at
├── cancellation_reason
└── timestamps
```

### Order Items Table
```sql
order_items
├── OrderItem_ID (PK)
├── Order_ID (FK → orders)
├── Product_ID (FK → products)
├── quantity
├── unit_price (wholesale or retail based on customer type)
└── subtotal
```

### Delivery Assignments Table
```sql
delivery_assignments
├── Assignment_ID (PK)
├── Order_ID (FK → orders)
├── delivery_person_name
├── vehicle_info
├── assigned_at
├── dispatched_at
├── delivered_at
└── notes
```

### Order Status History Table (Audit Trail)
```sql
order_status_history
├── History_ID (PK)
├── Order_ID (FK → orders)
├── old_status
├── new_status
├── changed_by (FK → app_users)
├── notes
└── created_at
```

## Backend Logic

### Order Creation
1. Validate customer selection
2. Validate order items (at least one item required)
3. Calculate total amount based on customer type (wholesale/retail pricing)
4. Generate unique order number (ORD-YYYYMMDD-XXXX)
5. Insert order with status "Requested"
6. Insert order items
7. Log status change to history

### Status Updates
**Allowed Transitions:**
- Requested → Confirmed, Cancelled
- Confirmed → Scheduled for Delivery, Cancelled
- Scheduled for Delivery → Out for Delivery, Cancelled
- Out for Delivery → Delivered (Pending Cash Turnover), Cancelled
- Delivered (Pending Cash Turnover) → Completed, Cancelled

**Special Handling:**
- When status changes to "Confirmed": Record `confirmed_by` user
- When status changes to "Completed": 
  - Record `completed_at` timestamp
  - **Deduct inventory** for all order items
  - Inventory is only deducted at completion, not before

### Delivery Assignment
- Can be assigned when status is "Confirmed" or "Scheduled for Delivery"
- Records delivery person name, vehicle info, and notes
- Tracks dispatch and delivery timestamps

### Order Cancellation
- Can be cancelled from any status except "Completed"
- Requires cancellation reason
- Records cancellation timestamp and reason
- Does NOT deduct inventory

## Inventory Management

### Key Rule: Inventory Deduction Timing
- **Inventory is NOT deducted** when order is created
- **Inventory is NOT deducted** when order is confirmed
- **Inventory IS deducted** only when order status changes to "Completed"

This ensures:
- Available inventory reflects actual stock (not reserved)
- Multiple orders can be created even if total exceeds inventory
- Inventory is only reduced when payment is received (COD completed)

### Implementation
When order status changes to "Completed":
```php
1. Get all order items for the order
2. For each item:
   - Get current inventory quantity
   - Deduct ordered quantity
   - Update inventory record
```

## Edge Cases

### Cancelled Orders
- Can be cancelled from any status except "Completed"
- Cancellation reason is required
- No inventory deduction occurs
- Order remains in system for audit trail

### Partial Delivery
- Current system supports full order delivery only
- For future enhancement: Add partial delivery tracking with remaining quantities

### Out of Stock
- System does not prevent order creation if inventory is insufficient
- Staff must manually check availability before confirming order
- For future enhancement: Add inventory check during order confirmation

### Multiple Orders for Same Customer
- System allows multiple orders per customer
- Each order has unique order number
- Orders are tracked independently

## User Interface Features

### Orders List Page
- Filter by status (All, Requested, Confirmed, Scheduled, Out for Delivery, Delivered, Completed)
- Display order number, customer, dates, status, total amount, delivery person
- Action buttons: View Details, Update Status, Assign Delivery, Cancel Order

### Create Order Modal
- Customer selection dropdown
- Order date/time selection
- Delivery address and schedule
- Dynamic order items (add/remove items)
- Product selection with wholesale/retail pricing
- Quantity and price calculation
- Notes field

### Status Update
- Modal with next available statuses
- Notes field for status change reason
- Automatic validation of status transitions

### Delivery Assignment
- Delivery person name input
- Vehicle information (optional)
- Notes field
- Tracks dispatch and delivery times

## API Endpoints

### POST /api/orders_backend.php
**Actions:**
- `create_order` - Create new order
- `update_status` - Update order status
- `assign_delivery` - Assign delivery personnel
- `cancel_order` - Cancel an order

**Response:** Redirects to orders.php with success/error message

## Security Considerations

1. **SQL Injection Prevention**: All queries use prepared statements
2. **XSS Prevention**: All user input is escaped using `htmlspecialchars()`
3. **Authentication**: All pages require authentication via `includes/auth.php`
4. **Authorization**: User actions are logged with `created_by` and `changed_by` fields

## Future Enhancements

1. **Inventory Check**: Automatic inventory availability check during order confirmation
2. **Partial Delivery**: Support for partial order fulfillment
3. **Order Templates**: Save common orders as templates
4. **Bulk Operations**: Update multiple orders at once
5. **Delivery Routes**: Optimize delivery routes for multiple orders
6. **SMS Notifications**: Send SMS to customers on order status changes
7. **Order Reports**: Generate reports on order statistics, delivery performance
8. **Payment Integration**: Track cash turnover and payment reconciliation

## Testing Checklist

- [ ] Create order with single item
- [ ] Create order with multiple items
- [ ] Update order status through all stages
- [ ] Assign delivery personnel
- [ ] Cancel order at different stages
- [ ] Verify inventory deduction only on completion
- [ ] Verify inventory NOT deducted on cancellation
- [ ] Test order number generation (uniqueness)
- [ ] Test status filter on orders list
- [ ] Test form validation (required fields)
- [ ] Test price calculation (wholesale vs retail)

## Notes for Developers

1. **Order Number Format**: `ORD-YYYYMMDD-XXXX` (e.g., ORD-20250125-0001)
2. **Status Values**: Must match exactly (case-sensitive) with database ENUM values
3. **Inventory Deduction**: Only happens in `deductInventoryForOrder()` function when status = "Completed"
4. **Transaction Safety**: Order creation and status updates use database transactions
5. **Error Handling**: All errors are logged and user-friendly messages displayed
