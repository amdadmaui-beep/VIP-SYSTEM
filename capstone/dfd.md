# VIP System — Data Flow Diagrams (DFD)

**System:** Villanueva Ice Plant Management System  
**Database:** `vip_db`  
**Platform:** PHP + MySQL + WebSocket  
**Location:** `C:\laragon\www\VIP-system\capstone`

---

## 1. Context Diagram (Level 0)

The system is viewed as a single process. External entities interact with it via data flows.

```mermaid
flowchart TD
    CUSTOMER([Customer])
    OWNER([Owner])
    MANAGER([Manager])
    CASHIER([Cashier])
    INV_STAFF([Inventory Staff])
    RIDER([Delivery Rider])

    SYS["VIP SYSTEM\n(Villanueva Ice Plant\nManagement System)"]

    CUSTOMER -->|"Order Request"| SYS
    CUSTOMER -->|"Payment (COD / Cash)"| SYS
    CUSTOMER -->|"AR Payment"| SYS
    SYS -->|"Order Confirmation"| CUSTOMER
    SYS -->|"Delivery of Products"| CUSTOMER
    SYS -->|"Invoice / AR Statement"| CUSTOMER

    OWNER -->|"User & Role Management"| SYS
    OWNER -->|"Module Access Config"| SYS
    OWNER -->|"System Settings"| SYS
    SYS -->|"Dashboard & Reports"| OWNER
    SYS -->|"Activity & Session Logs"| OWNER

    MANAGER -->|"Order Management"| SYS
    MANAGER -->|"Delivery Scheduling"| SYS
    MANAGER -->|"Report Requests"| SYS
    SYS -->|"Order & Delivery Status"| MANAGER
    SYS -->|"Analytics & Forecasts"| MANAGER

    CASHIER -->|"Sale Record (POS)"| SYS
    CASHIER -->|"Payment Processing"| SYS
    CASHIER -->|"Shift Start / End"| SYS
    SYS -->|"Product Prices & Stock"| CASHIER
    SYS -->|"Customer Information"| CASHIER
    SYS -->|"Z-Read / X-Read Reports"| CASHIER

    INV_STAFF -->|"Production Records"| SYS
    INV_STAFF -->|"Manual Stock Adjustments"| SYS
    INV_STAFF -->|"Prep Task Updates"| SYS
    INV_STAFF -->|"Damage Review"| SYS
    SYS -->|"Stock Levels & Limits"| INV_STAFF
    SYS -->|"Preparation Queue"| INV_STAFF
    SYS -->|"Stock Ledger"| INV_STAFF

    RIDER -->|"Delivery Status Update"| SYS
    RIDER -->|"COD Collection"| SYS
    RIDER -->|"Damage Report"| SYS
    RIDER -->|"Availability Change"| SYS
    SYS -->|"Delivery Assignments"| RIDER
    SYS -->|"Customer & Route Info"| RIDER
    SYS -->|"Collections Summary"| RIDER
```

---

## 2. Level 1 DFD

The system is decomposed into 9 major processes. Data stores represent logical groupings of database tables.

### Process Summary

| Process | Name | Description |
|---------|------|-------------|
| P1 | Production Management | Record ice production batches |
| P2 | Inventory Management | Track stock, adjustments, availability |
| P3 | Order Management | Create, confirm, schedule customer orders |
| P4 | Delivery Management | Assign riders, dispatch, record delivery |
| P5 | Sales Management | Record sales (walk-in & delivery), manage shifts |
| P6 | Accounts Receivable | Manage outstanding balances, FIFO payments |
| P7 | User & Security | Authentication, roles, module access, sessions |
| P8 | Reporting & Analytics | Dashboards, forecasts, exports |
| P9 | Real-time Notifications | WebSocket events for UI updates |

### Data Store Summary

| Store | Tables | Description |
|-------|--------|-------------|
| D1 | user, roles, user_module_access, user_sessions, manager_pins | User & security |
| D2 | products, product_categories, units | Product catalog |
| D3 | customers | Customer master |
| D4 | productions, stockin_inventory, adjustments, adjustment_details | Production & inventory |
| D5 | orders, order_details, order_status_history, order_preparation_tasks | Orders & prep |
| D6 | delivery, delivery_detail, delivery_assignments, delivery_damage_report, damage_types | Delivery & damage |
| D7 | sales, sale_details, sale_source, cash_shifts, shift_activity_log, shift_reviews, cash_shift_movements, cash_session_entries | Sales & shifts |
| D8 | account_receivable, ar_payment, singil, ar_retry_attempt | Accounts receivable |
| D9 | activity_logs | Audit trail |

```mermaid
flowchart TD
    %% External Entities (repeated from Context)
    CUST([Customer])
    CASH([Cashier])
    RID([Delivery Rider])
    INV_S([Inventory Staff])
    MGR([Manager])
    OWN([Owner])

    %% Processes
    P1["P1\nProduction\nManagement"]
    P2["P2\nInventory\nManagement"]
    P3["P3\nOrder\nManagement"]
    P4["P4\nDelivery\nManagement"]
    P5["P5\nSales\nManagement"]
    P6["P6\nAccounts\nReceivable"]
    P7["P7\nUser &\nSecurity"]
    P8["P8\nReporting &\nAnalytics"]
    P9["P9\nReal-time\nNotifications"]

    %% Data Stores
    D1[("D1\nUser &\nSecurity")]
    D2[("D2\nProduct\nCatalog")]
    D3[("D3\nCustomer\nData")]
    D4[("D4\nProduction &\nInventory")]
    D5[("D5\nOrder\nData")]
    D6[("D6\nDelivery\nData")]
    D7[("D7\nSales &\nShifts")]
    D8[("D8\nAccounts\nReceivable")]
    D9[("D9\nActivity\nLogs")]

    %% External → Process flows
    INV_S -->|"Production Data"| P1
    INV_S -->|"Stock Adjustments"| P2
    CUST -->|"Order Request"| P3
    MGR -->|"Manage Orders"| P3
    MGR -->|"Schedule Delivery"| P4
    RID -->|"Status / COD / Damage"| P4
    CASH -->|"Sale / Payment / Shift"| P5
    CUST -->|"AR Payment"| P6
    OWN -->|"User / Role Config"| P7

    %% Process → External flows
    P1 -->|"Stock Added"| INV_S
    P2 -->|"Stock View"| INV_S
    P3 -->|"Order Status"| MGR
    P3 -->|"Order Confirmation"| CUST
    P4 -->|"Delivery Assignments"| RID
    P5 -->|"Shift Summary"| CASH
    P5 -->|"Sale Receipt"| CUST
    P6 -->|"AR Statement"| CUST
    P7 -->|"Access Granted/Denied"| OWN

    %% Process ↔ Data Store flows
    P1 --> D4
    P2 --> D4
    P2 --> D2
    P3 --> D3
    P3 --> D5
    P4 --> D5
    P4 --> D6
    P5 --> D6
    P5 --> D7
    P5 --> D4
    P5 --> D2
    P6 --> D7
    P6 --> D8
    P7 --> D1
    P8 --> D1
    P8 --> D2
    P8 --> D3
    P8 --> D4
    P8 --> D5
    P8 --> D6
    P8 --> D7
    P8 --> D8
    P8 --> D9

    %% Inter-process flows (key business flows)
    P1 -.->|"Stock Increase"| P2
    P2 -.->|"Available Stock"| P3
    P3 -.->|"Confirmed Order"| P4
    P4 -.->|"Delivered Items"| P5
    P5 -.->|"Partial Payment"| P6
    P3 -.->|"Order Activity"| P9
    P4 -.->|"Delivery Events"| P9
    P5 -.->|"Record Activity"| D9
```

---

## 3. Level 2 DFDs — Process Decompositions

### 3.1 P3 — Order Management

**Functions:** Create order, check credit, confirm, schedule, track status.

```
Order Status Lifecycle:
Requested → Confirmed → Scheduled for Delivery → Out for Delivery → Delivered → Completed
```

```mermaid
flowchart TD
    CUST([Customer])
    MGR([Manager])
    INV_S([Inventory Staff])

    subgraph P3_Order["P3 — Order Management"]
        P3A["P3.1\nCreate Order"]
        P3B["P3.2\nCheck Credit &\nAvailability"]
        P3C["P3.3\nConfirm Order"]
        P3D["P3.4\nSchedule &\nAssign Rider"]
        P3E["P3.5\nTrack Status &\nProcess Cancellation"]
    end

    D2[("D2\nProducts")]
    D3[("D3\nCustomers")]
    D4[("D4\nStock\n(available qty)")]
    D5[("D5\nOrders +\nOrder Details +\nStatus History")]
    D1[("D1\nUsers\n(staff/rider)")]

    CUST -->|"Order Request"| P3A
    P3A -->|"Customer Lookup"| D3
    P3A -->|"Product Lookup"| D2
    P3A --> P3B
    P3B -->|"Check Balance"| D3
    P3B -->|"Check Stock"| D4
    P3B --> P3C
    P3C -->|"Write Order"| D5
    P3C -->|"Confirmation"| CUST
    P3D -->|"Read Orders"| D5
    P3D -->|"Read Riders"| D1
    P3D -->|"Create Prep Task"| D5
    P3D -->|"Notify Rider"| MGR
    MGR -->|"Schedule Command"| P3D
    P3E -->|"Update Status"| D5
    P3E -->|"Cancel Order"| D5
    CUST -->|"Cancel Request"| P3E
    INV_S -->|"Prep Ready"| P3D
```

**Data flows in P3:**

| Flow | From | To | Data |
|------|------|----|------|
| Customer Lookup | P3.1 | D3 | customer_id, name, phone, address, credit_limit |
| Product Lookup | P3.1 | D2 | product_id, name, wholesale_price, retail_price |
| Check Balance | P3.2 | D3 | outstanding_balance, credit_limit_remaining |
| Check Stock | P3.2 | D4 | product_id, available_quantity |
| Write Order | P3.3 | D5 | order_id, customer_id, products, quantities, prices, status |

---

### 3.2 P4 — Delivery Management

**Functions:** Assign rider, dispatch, record delivery outcomes, damage reporting.

```
Delivery Status Lifecycle:
Scheduled → In Transit → Delivered / Returning → Remitted → Completed
```

```mermaid
flowchart TD
    RID([Delivery Rider])
    MGR([Manager])
    INV_S([Inventory Staff])

    subgraph P4_Delivery["P4 — Delivery Management"]
        P4A["P4.1\nAssign Rider &\nSchedule"]
        P4B["P4.2\nDispatch &\nOut for Delivery"]
        P4C["P4.3\nRecord Delivery\n(received/damaged qty)"]
        P4D["P4.4\nSubmit Damage\nReport"]
        P4E["P4.5\nRemit COD\nPayment"]
    end

    D5[("D5\nOrders +\nPrep Tasks")]
    D6[("D6\nDelivery +\nDelivery Detail +\nAssignments +\nDamage Reports")]
    D1[("D1\nUsers (riders)")]

    MGR -->|"Assign Rider"| P4A
    P4A -->|"Read Orders"| D5
    P4A -->|"Read Available Riders"| D1
    P4A -->|"Write Assignment"| D6
    P4A -->|"Update Prep Task"| D5

    RID -->|"Start Trip"| P4B
    P4B -->|"Update Status: In Transit"| D6

    RID -->|"Deliver Items"| P4C
    P4C -->|"Record Received Qty"| D6
    P4C -->|"Record Damaged Qty"| D6

    RID -->|"Submit Damage"| P4D
    P4D -->|"Write Damage Report"| D6

    INV_S -->|"Review Damage"| P4D
    P4D -->|"Update Review Status"| D6

    RID -->|"Remit Payment"| P4E
    P4E -->|"Record Remittance"| D6
    P4E -->|"Update Status: Remitted"| D6
```

**Data flows in P4:**

| Flow | From | To | Data |
|------|------|----|------|
| Read Orders | P4.1 | D5 | order_id, delivery_address, items, quantities |
| Write Assignment | P4.1 | D6 | assignment_id, order_id, rider_id, vehicle, schedule_date |
| Record Received Qty | P4.3 | D6 | delivery_detail_id, received_qty |
| Record Damaged Qty | P4.3 | D6 | delivery_detail_id, damage_qty, remarks |
| Write Damage Report | P4.4 | D6 | report_id, delivery_id, item_id, damaged_qty, reason, photo |

---

### 3.3 P5 — Sales Management

**Functions:** Record walk-in sale, record delivery-based sale, process payment, manage cashier shifts.

```mermaid
flowchart TD
    CASH([Cashier])
    CUST([Customer])

    subgraph P5_Sales["P5 — Sales Management"]
        P5A["P5.1\nRecord Walk-in\nSale (Retail)"]
        P5B["P5.2\nRecord Delivery\nSale (Wholesale)"]
        P5C["P5.3\nProcess\nPayment"]
        P5D["P5.4\nManage Cashier\nShift"]
        P5E["P5.5\nVoid\nTransaction"]
    end

    D2[("D2\nProducts")]
    D4[("D4\nStock\nInventory")]
    D6[("D6\nDelivery\nData")]
    D7[("D7\nSales +\nSale Details +\nSale Source +\nCash Shifts")]
    D3[("D3\nCustomers")]
    D8[("D8\nAccounts\nReceivable")]

    CASH -->|"Select Products"| P5A
    P5A -->|"Read Products (retail price)"| D2
    P5A -->|"Write Sale"| D7
    P5A -->|"Deduct Stock"| D4

    CASH -->|"Select Delivery"| P5B
    P5B -->|"Read Delivery Detail"| D6
    P5B -->|"Read Products (wholesale price)"| D2
    P5B -->|"Write Sale"| D7
    P5B -->|"Link Sale Source"| D7
    P5B -->|"Deduct Stock"| D4

    CASH -->|"Process Payment"| P5C
    P5C -->|"Record Payment"| D7
    P5C -->|"Update Order Status: Completed"| D6
    CUST -->|"Payment"| P5C
    P5C -->|"Partial Payment"| P5E2["P6 (AR)"]
    P5C -->|"Sale Receipt"| CUST

    CASH -->|"Start / End Shift"| P5D
    P5D -->|"Record Starting Cash"| D7
    P5D -->|"Calculate Z-Read"| D7
    P5D -->|"Shift Summary"| CASH
    P5D -->|"Record Discrepancy"| D7

    CASH -->|"Void Request"| P5E
    P5E -->|"Reverse Stock Deduction"| D4
    P5E -->|"Mark Sale Voided"| D7
```

**Sales vs Delivery relationship:**

```mermaid
flowchart LR
    subgraph Walk_in["Walk-in Sale"]
        direction TB
        WI1["Customer walks in"]
        WI2["Cashier selects products"]
        WI3["Uses retail price"]
        WI4["Stock deducted immediately"]
    end

    subgraph Delivery_Sale["Delivery-based Sale"]
        direction TB
        DS1["Order placed (wholesale)"]
        DS2["Delivery completed"]
        DS3["Rider remits payment"]
        DS4["Cashier records sale"]
        DS5["Uses wholesale price"]
        DS6["Stock deducted now"]
    end
```

---

### 3.4 Full Order-to-Payment Pipeline (P3 → P4 → P5 → P6)

This shows the end-to-end flow from order creation through to payment and AR.

```mermaid
flowchart TD
    CUST([Customer])
    CASH([Cashier])
    RID([Delivery Rider])

    P3["P3\nOrder\nManagement"]
    P4["P4\nDelivery\nManagement"]
    P5["P5\nSales\nManagement"]
    P6["P6\nAccounts\nReceivable"]

    D4[("D4\nStock\n(Physical -\nReserved)")]
    D5[("D5\nOrders")]
    D6[("D6\nDeliveries")]
    D7[("D7\nSales")]
    D8[("D8\nAR")]

    CUST -->|"1. Order Request"| P3
    P3 -->|"2. Check Stock"| D4
    P3 -->|"3. Reserve Stock"| D5
    P3 -->|"4. Confirmed Order"| P4
    P4 -->|"5. Assign Rider"| RID
    RID -->|"6. Deliver"| P4
    P4 -->|"7. Delivery Complete"| P5
    P5 -->|"8. Record Sale"| D7
    P5 -->|"9. Deduct Stock"| D4
    P5 -->|"10a. Full Payment → Completed"| D5
    P5 -->|"10b. Partial Payment"| P6
    P6 -->|"11. Create AR Record"| D8
    CUST -->|"12. AR Payment"| P6
    P6 -->|"13. Apply FIFO"| D8
    P6 -->|"14. Update Balance"| D8

    %% Inventory reservation note
    D4 -.->|"Note: Stock available =\nPhysical stock -\nReserved (active orders)"| P3
```

**Step-by-step data flow:**

| Step | Process | Action | Data Impact |
|------|---------|--------|-------------|
| 1 | P3 | Customer requests order | Order created (status: Requested) |
| 2 | P3 | Check available stock | Read D4 (physical - reserved) |
| 3 | P3 | Reserve quantities | Write D5 (order_details with qty) |
| 4 | P3 | Confirm & schedule | Write D5 (status: Scheduled), D6 |
| 5 | P4 | Assign delivery rider | Write D6 (assignment) |
| 6 | RID | Rider delivers | Update D6 (status: In Transit → Delivered) |
| 7 | P4 | Delivery complete | Update D6 (received_qty, damage_qty) |
| 8 | P5 | Cashier records sale | Write D7 (sale record) |
| 9 | P5 | Deduct inventory | Update D4 (decrease quantity) |
| 10a | P5 | Full payment → completed | Update D5 (status: Completed) |
| 10b | P5 | Partial payment | Trigger P6 |
| 11 | P6 | Create AR record | Write D8 (amount_due, due_date) |
| 12 | CUST | Customer pays AR | Payment to P6 |
| 13 | P6 | Apply FIFO | Oldest AR paid first via D8 |
| 14 | P6 | Update balance | Write D8 (remaining_balance) |

---

## 4. Entity Relationship Summary

```mermaid
erDiagram
    user ||--o{ orders : creates
    user ||--o{ adjustments : authorizes
    user ||--o{ delivery : assigned_rider
    user ||--o{ user_sessions : has
    user ||--o{ cash_shifts : operates
    roles ||--o{ user : defines_role

    products ||--o{ stockin_inventory : tracks
    products ||--o{ order_details : ordered_in
    products ||--o{ sale_details : sold_in
    products ||--o{ adjustment_details : adjusted_in
    products ||--o{ product_categories : categorized_in

    customers ||--o{ orders : places
    customers ||--o{ account_receivable : owes

    orders ||--o{ order_details : contains
    orders ||--o{ delivery : shipped_as
    orders ||--o{ order_status_history : logs
    orders ||--o{ order_preparation_tasks : prepared_by

    delivery ||--o{ delivery_detail : line_items
    delivery ||--o{ delivery_damage_report : damaged_items
    delivery ||--o{ delivery_assignments : assigned_to

    sales ||--o{ sale_details : line_items
    sales ||--o{ sale_source : links_to_delivery
    sales ||--o{ account_receivable : creates_ar

    account_receivable ||--o{ singil : has
    ar_payment ||--o{ singil : applied_in
```

---

## 5. Key Business Rules (for DFD annotations)

| Rule | Description |
|------|-------------|
| R1 | Inventory is NOT deducted at order creation — only when sale is recorded (payment received) |
| R2 | Available Stock = Physical Stock (D4) — Reserved Stock (active orders in D5) |
| R3 | Outstanding customer balance (D3) blocks new orders |
| R4 | Walk-in sales use retail price; delivery-based sales use wholesale price |
| R5 | AR payments applied FIFO (oldest invoice paid first) |
| R6 | Damage reports (by rider) must be reviewed & approved before stock adjustment |
| R7 | Each delivery creates a separate AR record |
| R8 | Real-time events published via WebSocket on order.scheduled and delivery.ready |

---

## 6. Glossary

| Term | Definition |
|------|------------|
| COD | Cash on Delivery — payment collected by rider at delivery |
| POS | Point of Sale — cashier transaction terminal |
| AR | Accounts Receivable — outstanding customer credit |
| FIFO | First In, First Out — payment applied to oldest AR invoice |
| Z-Read | End-of-shift report summarizing cashier sales and discrepancies |
| Prep Task | Inventory staff task to prepare an order for loading |
| Singil | Junction table linking AR records to payment records |
| DFD | Data Flow Diagram — graphical representation of data movement |
