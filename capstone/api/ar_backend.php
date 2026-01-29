<?php
/**
 * Accounts Receivable Backend API
 * Uses existing table structure:
 * - account_receivable: AR_ID, Sale_ID, Customer_ID, amount_due, due_date, status, invoice_date, invoice_amount, opening_balance
 * - ar_payment: payment_ID, payment_date, amount_paid, remaining_balance, collected_by
 * - ar_retry_attempt: Retry_ID, Payment_ID, retried_by, attempt_no, status, remarks
 * - singil: Singl_ID, AR_ID, Payment_ID (junction table)
 */

// Ensure only JSON is output (no PHP notices/warnings as HTML)
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// Handle API requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 1;

try {
switch ($action) {
    case 'create_ar':
        createAR($conn, $user_id);
        break;
    case 'record_payment':
        recordPayment($conn, $user_id);
        break;
    case 'add_retry_attempt':
        addRetryAttempt($conn, $user_id);
        break;
    case 'get_customer_ar':
        getCustomerAR($conn);
        break;
    case 'get_ar_details':
        getARDetails($conn);
        break;
    case 'get_ar_summary':
        getARSummary($conn);
        break;
    case 'get_all_open_ar':
        getAllOpenAR($conn);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}

/**
 * Get a default Customer_ID for placeholder sale (e.g. first customer or walk-in).
 */
function getDefaultCustomerIdForPlaceholder($conn) {
    $r = $conn->query("SELECT Customer_ID FROM customers ORDER BY Customer_ID ASC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        return (int) $row['Customer_ID'];
    }
    return 1;
}

/**
 * Create a placeholder sale row for manual AR (when no sale is linked).
 * Dynamically builds INSERT from SHOW COLUMNS so required fields get values and we avoid future "no default" errors.
 */
function createPlaceholderSaleId($conn, $user_id) {
    $columns_result = $conn->query("SHOW COLUMNS FROM sales");
    if (!$columns_result) {
        throw new Exception("Could not read sales table structure");
    }
    $column_info = []; // Field => [Null, Default, Type, Extra]
    while ($row = $columns_result->fetch_assoc()) {
        $column_info[$row['Field']] = $row;
    }
    $columns_result->close();

    $insert_fields = [];
    $insert_values = [];
    $bind_params = [];
    $bind_types = "";

    $default_customer_id = null;

    foreach ($column_info as $field => $info) {
        $null = $info['Null'] ?? '';
        $default = $info['Default'] ?? null;
        $extra = $info['Extra'] ?? '';
        $type = strtoupper($info['Type'] ?? '');

        // Skip if nullable or has default or is auto_increment
        if (strtoupper($null) === 'YES') continue;
        if ($default !== null && $default !== '') continue;
        if (stripos($extra, 'auto_increment') !== false) continue;

        // Already added below with specific logic
        if (in_array($field, $insert_fields)) continue;

        // User_ID / user_id / created_by
        if (in_array($field, ['User_ID', 'user_id', 'created_by'])) {
            $insert_fields[] = $field;
            $insert_values[] = '?';
            $bind_params[] = (int) $user_id;
            $bind_types .= "i";
            continue;
        }

        // Customer_ID / customer_id
        if (in_array($field, ['Customer_ID', 'customer_id'])) {
            if ($default_customer_id === null) {
                $default_customer_id = getDefaultCustomerIdForPlaceholder($conn);
            }
            $insert_fields[] = $field;
            $insert_values[] = '?';
            $bind_params[] = $default_customer_id;
            $bind_types .= "i";
            continue;
        }

        // status (enum or string)
        if ($field === 'status') {
            $insert_fields[] = $field;
            $insert_values[] = "'Completed'";
            continue;
        }

        // Numeric columns
        if (stripos($type, 'INT') !== false || stripos($type, 'DECIMAL') !== false || stripos($type, 'FLOAT') !== false) {
            $insert_fields[] = $field;
            $insert_values[] = '0';
            continue;
        }

        // Date/datetime
        if (stripos($type, 'DATE') !== false) {
            $insert_fields[] = $field;
            $insert_values[] = 'CURDATE()';
            continue;
        }

        // Timestamp
        if (stripos($type, 'TIMESTAMP') !== false) {
            $insert_fields[] = $field;
            $insert_values[] = 'CURRENT_TIMESTAMP';
            continue;
        }

        // String/varchar/text – use empty string
        $insert_fields[] = $field;
        $insert_values[] = "''";
    }

    if (empty($insert_fields)) {
        $conn->query("INSERT INTO sales () VALUES ()");
    } else {
        $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Placeholder sale prepare failed: " . $conn->error);
        }
        if (!empty($bind_types)) {
            $stmt->bind_param($bind_types, ...$bind_params);
        }
        if (!$stmt->execute()) {
            throw new Exception("Placeholder sale insert failed: " . $stmt->error);
        }
        $stmt->close();
    }
    return (int) $conn->insert_id;
}

/**
 * Create a new AR record
 * When no Sale_ID is provided (manual AR), use a placeholder sale row so Sale_ID is never NULL.
 */
function createAR($conn, $user_id) {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $sale_id = intval($_POST['sale_id'] ?? 0);
    $invoice_amount = floatval($_POST['invoice_amount'] ?? 0);
    $amount_due = floatval($_POST['amount_due'] ?? 0);
    $invoice_date = trim($_POST['invoice_date'] ?? date('Y-m-d'));
    $due_date = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $status = trim($_POST['status'] ?? 'Open');
    
    if ($customer_id <= 0 || $invoice_amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Customer and invoice amount are required']);
        return;
    }
    
    if ($amount_due <= 0) {
        $amount_due = $invoice_amount;
    }
    
    $opening_balance = $amount_due;
    
    // Sale_ID is NOT NULL in DB - use real Sale_ID or create a placeholder sale row with required columns
    if ($sale_id <= 0) {
        $sale_id = createPlaceholderSaleId($conn, $user_id);
    }
    
    $stmt = $conn->prepare("INSERT INTO account_receivable 
        (Sale_ID, Customer_ID, invoice_date, invoice_amount, opening_balance, amount_due, due_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        return;
    }
    
    $stmt->bind_param("iisdddss",
        $sale_id, $customer_id, $invoice_date, $invoice_amount,
        $opening_balance, $amount_due, $due_date, $status);
    
    if ($stmt->execute()) {
        $ar_id = (int) $conn->insert_id;
        echo json_encode([
            'success' => true,
            'ar_id' => $ar_id,
            'message' => 'AR record created successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create AR record: ' . $stmt->error]);
    }
    $stmt->close();
}

/**
 * Record a payment and apply it using FIFO method
 */
function recordPayment($conn, $user_id) {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $ar_id = intval($_POST['ar_id'] ?? 0); // Optional - specific AR to pay
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    
    if ($customer_id <= 0 || $amount_paid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Customer and payment amount are required']);
        return;
    }
    
    $conn->begin_transaction();
    
    try {
        $remaining_payment = $amount_paid;
        $applications = [];
        
        // Get all open ARs for this customer, ordered by invoice_date (FIFO)
        // If specific AR is provided, prioritize that one
        if ($ar_id > 0) {
            $ar_query = $conn->prepare("SELECT AR_ID, amount_due, invoice_amount 
                FROM account_receivable 
                WHERE AR_ID = ? AND Customer_ID = ? AND amount_due > 0
                UNION
                SELECT AR_ID, amount_due, invoice_amount 
                FROM account_receivable 
                WHERE Customer_ID = ? AND amount_due > 0 AND AR_ID != ?
                ORDER BY AR_ID ASC");
            $ar_query->bind_param("iiii", $ar_id, $customer_id, $customer_id, $ar_id);
        } else {
            $ar_query = $conn->prepare("SELECT AR_ID, amount_due, invoice_amount 
                FROM account_receivable 
                WHERE Customer_ID = ? AND amount_due > 0 AND status NOT IN ('Paid', 'Closed')
                ORDER BY invoice_date ASC, AR_ID ASC");
            $ar_query->bind_param("i", $customer_id);
        }
        
        $ar_query->execute();
        $ar_result = $ar_query->get_result();
        
        while ($ar = $ar_result->fetch_assoc()) {
            if ($remaining_payment <= 0) break;
            
            $current_ar_id = $ar['AR_ID'];
            $ar_balance = floatval($ar['amount_due']);
            
            // Determine how much to apply to this AR
            $apply_amount = min($remaining_payment, $ar_balance);
            $new_balance = $ar_balance - $apply_amount;
            $remaining_payment -= $apply_amount;
            
            // Create payment record
            $pay_stmt = $conn->prepare("INSERT INTO ar_payment 
                (payment_date, amount_paid, remaining_balance, collected_by)
                VALUES (?, ?, ?, ?)");
            $pay_stmt->bind_param("sddi", $payment_date, $apply_amount, $new_balance, $user_id);
            
            if (!$pay_stmt->execute()) {
                throw new Exception("Failed to create payment record: " . $pay_stmt->error);
            }
            
            $payment_id = $conn->insert_id;
            $pay_stmt->close();
            
            // Link payment to AR via singil (junction table)
            $link_stmt = $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)");
            $link_stmt->bind_param("ii", $current_ar_id, $payment_id);
            $link_stmt->execute();
            $link_stmt->close();
            
            // Update AR record
            $new_status = $new_balance <= 0 ? 'Paid' : 'Partial';
            $update_stmt = $conn->prepare("UPDATE account_receivable 
                SET amount_due = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE AR_ID = ?");
            $update_stmt->bind_param("dsi", $new_balance, $new_status, $current_ar_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $applications[] = [
                'ar_id' => $current_ar_id,
                'applied' => $apply_amount,
                'new_balance' => $new_balance,
                'status' => $new_status
            ];
        }
        $ar_query->close();
        
        // Handle overpayment as credit (stored but not applied yet)
        $credit_balance = $remaining_payment;
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'amount_paid' => $amount_paid,
            'applications' => $applications,
            'credit_balance' => $credit_balance,
            'message' => 'Payment recorded and applied successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Add a collection retry attempt for an AR
 * Note: Your ar_retry_attempt uses Payment_ID, but we'll link via the most recent payment for this AR
 */
function addRetryAttempt($conn, $user_id) {
    $ar_id = intval($_POST['ar_id'] ?? 0);
    $status = $_POST['status'] ?? 'Contacted';
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($ar_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'AR ID is required']);
        return;
    }
    
    // Get the most recent payment_id for this AR (or use NULL/0 if none)
    $payment_id = null;
    $pay_result = $conn->query("SELECT Payment_ID FROM singil WHERE AR_ID = $ar_id ORDER BY Payment_ID DESC LIMIT 1");
    if ($pay_result && $pay_row = $pay_result->fetch_assoc()) {
        $payment_id = $pay_row['Payment_ID'];
    }
    
    // Get the next attempt number for this AR (via payment link or overall)
    $count_result = $conn->query("SELECT COALESCE(MAX(attempt_no), 0) + 1 as next_no 
        FROM ar_retry_attempt ra 
        INNER JOIN singil st ON ra.Payment_ID = st.Payment_ID 
        WHERE st.AR_ID = $ar_id");
    $attempt_no = 1;
    if ($count_result && $count_row = $count_result->fetch_assoc()) {
        $attempt_no = intval($count_row['next_no']);
    }
    
    // If no payment exists yet, we might need to create a dummy entry or handle differently
    // For now, we'll create a payment record with 0 amount just to link the retry
    if (!$payment_id) {
        $stmt = $conn->prepare("INSERT INTO ar_payment (payment_date, amount_paid, remaining_balance, collected_by) VALUES (CURDATE(), 0, 0, ?)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $payment_id = $conn->insert_id;
        $stmt->close();
        
        // Link to AR
        $link_stmt = $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)");
        $link_stmt->bind_param("ii", $ar_id, $payment_id);
        $link_stmt->execute();
        $link_stmt->close();
    }
    
    $stmt = $conn->prepare("INSERT INTO ar_retry_attempt 
        (Payment_ID, retried_by, attempt_no, status, remarks)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiss", $payment_id, $user_id, $attempt_no, $status, $remarks);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'retry_id' => $conn->insert_id,
            'attempt_no' => $attempt_no,
            'message' => 'Retry attempt recorded'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to record retry attempt: ' . $stmt->error]);
    }
    $stmt->close();
}

/**
 * Get all AR records for a specific customer
 */
function getCustomerAR($conn) {
    $customer_id = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    
    if ($customer_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Customer ID is required']);
        return;
    }
    
    $query = "SELECT ar.* 
              FROM account_receivable ar
              WHERE ar.Customer_ID = ?
              ORDER BY ar.invoice_date ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $records = [];
    $total_outstanding = 0;
    $open_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
        if (!in_array($row['status'], ['Paid', 'Closed']) && $row['amount_due'] > 0) {
            $total_outstanding += floatval($row['amount_due']);
            $open_count++;
        }
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'customer_id' => $customer_id,
        'records' => $records,
        'total_outstanding' => $total_outstanding,
        'open_count' => $open_count
    ]);
}

/**
 * Get detailed information for a specific AR
 */
function getARDetails($conn) {
    $ar_id = intval($_GET['ar_id'] ?? $_POST['ar_id'] ?? 0);
    
    if ($ar_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'AR ID is required']);
        return;
    }
    
    // Get AR details with customer info
    $ar_query = $conn->prepare("SELECT ar.*, c.customer_name, c.phone_number, c.address
        FROM account_receivable ar
        LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
        WHERE ar.AR_ID = ?");
    $ar_query->bind_param("i", $ar_id);
    $ar_query->execute();
    $ar_result = $ar_query->get_result();
    $ar = $ar_result->fetch_assoc();
    $ar_query->close();
    
    if (!$ar) {
        echo json_encode(['success' => false, 'error' => 'AR not found']);
        return;
    }
    
    // Get payments linked to this AR via singil
    $payments = [];
    $payments_query = $conn->prepare("SELECT p.*, u.user_name as collected_by_name
        FROM ar_payment p
        INNER JOIN singil st ON p.payment_ID = st.Payment_ID
        LEFT JOIN app_users u ON p.collected_by = u.User_ID
        WHERE st.AR_ID = ? AND p.amount_paid > 0
        ORDER BY p.payment_date ASC");
    $payments_query->bind_param("i", $ar_id);
    $payments_query->execute();
    $payments_result = $payments_query->get_result();
    while ($row = $payments_result->fetch_assoc()) {
        $payments[] = $row;
    }
    $payments_query->close();
    
    // Get retry attempts linked to this AR
    $retries = [];
    $retries_query = $conn->prepare("SELECT ra.*, u.user_name as retried_by_name
        FROM ar_retry_attempt ra
        INNER JOIN singil st ON ra.Payment_ID = st.Payment_ID
        LEFT JOIN app_users u ON ra.retried_by = u.User_ID
        WHERE st.AR_ID = ?
        ORDER BY ra.created_at DESC");
    $retries_query->bind_param("i", $ar_id);
    $retries_query->execute();
    $retries_result = $retries_query->get_result();
    while ($row = $retries_result->fetch_assoc()) {
        $retries[] = $row;
    }
    $retries_query->close();
    
    echo json_encode([
        'success' => true,
        'ar' => $ar,
        'payments' => $payments,
        'retries' => $retries
    ]);
}

/**
 * Get AR summary statistics
 */
function getARSummary($conn) {
    $summary = [
        'total_outstanding' => 0,
        'total_overdue' => 0,
        'open_count' => 0,
        'overdue_count' => 0,
        'collected_this_month' => 0
    ];
    
    // Total outstanding
    $outstanding_query = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total 
        FROM account_receivable WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($outstanding_query) {
        $summary['total_outstanding'] = floatval($outstanding_query->fetch_assoc()['total']);
    }
    
    // Total overdue
    $overdue_query = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total, COUNT(*) as count
        FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($overdue_query) {
        $row = $overdue_query->fetch_assoc();
        $summary['total_overdue'] = floatval($row['total']);
        $summary['overdue_count'] = intval($row['count']);
    }
    
    // Count open
    $open_query = $conn->query("SELECT COUNT(*) as count FROM account_receivable 
        WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($open_query) {
        $summary['open_count'] = intval($open_query->fetch_assoc()['count']);
    }
    
    // Collected this month
    $month_start = date('Y-m-01');
    $collected_query = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM ar_payment WHERE payment_date >= '$month_start' AND amount_paid > 0");
    if ($collected_query) {
        $summary['collected_this_month'] = floatval($collected_query->fetch_assoc()['total']);
    }
    
    echo json_encode(['success' => true, 'summary' => $summary]);
}

/**
 * Get all open AR records
 */
function getAllOpenAR($conn) {
    $query = "SELECT ar.*, c.customer_name, c.phone_number,
                     DATEDIFF(CURDATE(), ar.due_date) as days_overdue
              FROM account_receivable ar
              LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
              WHERE ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0
              ORDER BY ar.due_date ASC";
    
    $result = $conn->query($query);
    $records = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'records' => $records]);
}

$conn->close();
?>
