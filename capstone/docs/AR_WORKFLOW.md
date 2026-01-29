# Accounts Receivable (AR) Workflow

## Overview

The Accounts Receivable module tracks customer balances, processes payments using FIFO (First-In-First-Out), and manages collection attempts.

## Database Tables (Existing Structure)

### `account_receivable`
| Column | Type | Description |
|--------|------|-------------|
| AR_ID | INT | Primary key |
| Sale_ID | INT | Foreign key to sales |
| Customer_ID | INT | Foreign key to customers |
| amount_due | DECIMAL | Current balance due |
| due_date | DATE | Payment due date |
| status | VARCHAR | Current status |
| invoice_date | DATE | Date AR was created |
| invoice_amount | DECIMAL | Original invoice amount |
| opening_balance | DECIMAL | Initial balance |
| created_at | TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | Last update time |

### `ar_payment`
| Column | Type | Description |
|--------|------|-------------|
| payment_ID | INT | Primary key |
| payment_date | DATE | When payment was received |
| amount_paid | DECIMAL | Payment amount |
| remaining_balance | DECIMAL | Balance after payment |
| collected_by | INT | User who collected |
| created_at | TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | Last update time |

### `singil` (Junction Table)
| Column | Type | Description |
|--------|------|-------------|
| Singl_ID | INT | Primary key |
| AR_ID | INT | Links to account_receivable |
| Payment_ID | INT | Links to ar_payment |
| created_at | TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | Last update time |

### `ar_retry_attempt`
| Column | Type | Description |
|--------|------|-------------|
| Retry_ID | INT | Primary key |
| Payment_ID | INT | Links via singil to AR |
| retried_by | INT | User who made attempt |
| attempt_no | INT | Attempt number |
| status | VARCHAR | Result of attempt |
| remarks | TEXT | Notes |
| created_at | TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | Last update time |

## Key Concepts

### 1. AR Record Creation
- When a delivery is completed without full payment, an AR record is created
- Each delivery creates a **separate AR record** (not merged with existing ones)
- The `amount_due` column tracks the current balance
- The `invoice_amount` stores the original amount

### 2. FIFO Payment Application
When a customer makes a payment:

1. Payment is recorded in `ar_payment`
2. System finds all open ARs for that customer, sorted by `invoice_date` (oldest first)
3. Payment is applied to the oldest AR first
4. Each payment-AR link is stored in `singil`
5. If payment exceeds the oldest AR's balance:
   - That AR's `amount_due` becomes 0 and `status` changes to "Paid"
   - Excess is applied to the next oldest AR
6. Process continues until payment is fully applied
7. Any remaining amount becomes **customer credit**

**Example:**
```
Customer has 3 ARs:
- AR-1: ₱500 due (oldest, invoice_date: Jan 1)
- AR-2: ₱1,000 due (invoice_date: Jan 15)
- AR-3: ₱300 due (invoice_date: Jan 20)

Customer pays ₱1,200:
- ₱500 applied to AR-1 → PAID (amount_due = 0)
- ₱700 applied to AR-2 → PARTIAL (amount_due = 300)
- AR-3: unchanged (amount_due = 300)

Result: 
- AR-1: Paid
- AR-2: ₱300 remaining
- AR-3: ₱300 remaining
- Total outstanding: ₱600
```

### 3. Collection Tracking
Each AR can have multiple collection attempts logged via `ar_retry_attempt`:
- Linked through `Payment_ID` → `singil` → `AR_ID`
- Tracks attempt number, status, and remarks
- Status options: Contacted, No Answer, Promise to Pay, Refused, Rescheduled

### 4. Status Definitions

| Status | Description |
|--------|-------------|
| **Open** | New AR, no payments yet |
| **Partial** | Some payment received, balance remains |
| **Paid** | Fully paid (amount_due = 0) |
| **Overdue** | Past due date, not fully paid |
| **Pending** | Awaiting processing |
| **Closed** | Closed/written off |

## API Endpoints

### `POST /api/ar_backend.php`

| Action | Parameters | Description |
|--------|------------|-------------|
| `create_ar` | customer_id, invoice_amount, amount_due, due_date | Create new AR |
| `record_payment` | customer_id, amount_paid, payment_date, ar_id (optional) | Record payment (FIFO) |
| `add_retry_attempt` | ar_id, status, remarks | Log collection attempt |

### `GET /api/ar_backend.php`

| Action | Parameters | Description |
|--------|------------|-------------|
| `get_customer_ar` | customer_id | Get all ARs for customer |
| `get_ar_details` | ar_id | Get AR with payments & retries |
| `get_ar_summary` | - | Get overall AR statistics |
| `get_all_open_ar` | - | Get all open/partial ARs |

## Usage Instructions

### Creating an AR Record
1. Go to **Accounts Receivable** page
2. Click **"New AR Record"**
3. Select customer, enter invoice amount and amount due
4. Set due date
5. Click **"Create AR Record"**

### Recording a Payment
1. Click **"Record Payment"** or click the pay icon on any AR row
2. Select customer (balance will be shown)
3. Enter payment amount
4. Click **"Record Payment (FIFO)"**
5. System shows which ARs were paid/partially paid

### Logging Collection Attempts
1. Click the phone icon on an AR row
2. Select result status
3. Add remarks
4. Click **"Log Attempt"**

### Viewing AR Details
- Click the eye icon on any AR row to see:
  - Full AR information
  - Payment history linked to that AR
  - Collection attempt history
