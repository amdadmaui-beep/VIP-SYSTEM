<?php
/**
 * Accounts Receivable Backend API
 * Uses existing table structure:
 * - account_receivable: AR_ID, Sale_ID, Customer_ID, amount_due, due_date, status, invoice_date, invoice_amount, opening_balance
 * - ar_payment: payment_ID, payment_date, amount_paid, remaining_balance, collected_by
 * - ar_retry_attempt: Retry_ID, Payment_ID, retried_by, attempt_no, status, remarks
 * - singil: Singl_ID, AR_ID, Payment_ID (junction table)
 * 
 * SECURITY UPDATE: Added CSRF protection for state-changing operations
 * Location: capstone/api/ar_backend.php
 */

// Ensure only JSON is output (no PHP notices/warnings as HTML)
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/ar_reminder_helper.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/cash_session_helper.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/csrf.php'; // CSRF Protection - Security Fix

// Accessible to Owner (1), Manager (2/4), and Cashier (3)
requireRole([1, 2, 3, 4]);

header('Content-Type: application/json; charset=utf-8');

// Handle API requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 1;

// CSRF Protection: Validate token for state-changing POST actions - Security Fix
$state_changing_actions = ['create_ar', 'record_payment', 'add_retry_attempt', 'send_ar_reminder_email', 'send_ar_reminder_sms'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $state_changing_actions)) {
    requireCsrfToken(true, false);
}

// Module-access compatibility:
// - Older/new AR pages use the accounts_receivable module.
// - Sales-embedded AR flow uses cashier_ar_sales.
// Allow access if either permission is granted.
$allow_ar_page = isModuleAllowedForUser($conn, (int)$user_id, 'accounts_receivable', true);
$allow_ar_in_sales = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_ar_sales', true);
if (!$allow_ar_page && !$allow_ar_in_sales) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accounts receivable access is currently restricted for your account.']);
    exit();
}

// Restriction: Owner (Role_ID 1) is restricted to view-only mode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1) {
    if (in_array($action, ['create_ar', 'record_payment', 'add_retry_attempt'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account (Owner) is restricted to view-only access. AR operations are not allowed."]);
        exit();
    }
}

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
    case 'send_ar_reminder_email':
        sendARReminderEmail($conn, $user_id);
        break;
    case 'send_ar_reminder_sms':
        sendARReminderSms($conn, $user_id);
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
    case 'get_aging_report':
        getAgingReport($conn);
        break;
    case 'get_ar_history':
        getARHistory($conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
} catch (Throwable $e) {
    http_response_code(500);
    error_log('AR backend error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
    exit;
}

/**
 * Get a default Customer_ID for placeholder sale (e.g. first customer or walk-in).
 */
function getDefaultCustomerIdForPlaceholder($conn) {
    $row = $conn->query("SELECT Customer_ID FROM customers WHERE deleted_at IS NULL ORDER BY Customer_ID ASC LIMIT 1")->fetch();
    return $row ? (int) $row['Customer_ID'] : 1;
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
    $column_info = $columns_result->fetchAll();

    $insert_fields = [];
    $insert_values = [];
    $bind_params = [];

    $default_customer_id = null;

    foreach ($column_info as $info) {
        $field = $info['Field'];
        $null = $info['Null'] ?? '';
        $default = $info['Default'] ?? null;
        $extra = $info['Extra'] ?? '';
        $type = strtoupper($info['Type'] ?? '');

        if (strtoupper($null) === 'YES') continue;
        if ($default !== null && $default !== '') continue;
        if (stripos($extra, 'auto_increment') !== false) continue;
        if (in_array($field, $insert_fields)) continue;

        if (in_array($field, ['User_ID', 'user_id', 'created_by'])) {
            $insert_fields[] = $field;
            $insert_values[] = '?';
            $bind_params[] = (int) $user_id;
            continue;
        }

        if (in_array($field, ['Customer_ID', 'customer_id'])) {
            if ($default_customer_id === null) {
                $default_customer_id = getDefaultCustomerIdForPlaceholder($conn);
            }
            $insert_fields[] = $field;
            $insert_values[] = '?';
            $bind_params[] = $default_customer_id;
            continue;
        }

        if ($field === 'status') {
            $insert_fields[] = $field;
            $insert_values[] = "'Completed'";
            continue;
        }

        if (stripos($type, 'INT') !== false || stripos($type, 'DECIMAL') !== false || stripos($type, 'FLOAT') !== false) {
            $insert_fields[] = $field;
            $insert_values[] = '0';
            continue;
        }

        if (stripos($type, 'DATE') !== false) {
            $insert_fields[] = $field;
            $insert_values[] = 'CURDATE()';
            continue;
        }

        if (stripos($type, 'TIMESTAMP') !== false) {
            $insert_fields[] = $field;
            $insert_values[] = 'CURRENT_TIMESTAMP';
            continue;
        }

        $insert_fields[] = $field;
        $insert_values[] = "''";
    }

    if (empty($insert_fields)) {
        $conn->query("INSERT INTO sales () VALUES ()");
    } else {
        $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
        $stmt = $conn->prepare($sql);
        if (!$stmt->execute($bind_params)) {
            throw new Exception("Placeholder sale insert failed");
        }
    }
    return (int) $conn->lastInsertId();
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
    $status = trim($_POST['status'] ?? 'Open');
    
    // Comprehensive validation
    $errors = [];
    
    // Customer ID validation
    if (empty($customer_id) || $customer_id <= 0) {
        $errors[] = "Customer is required.";
    } else {
        $customer_check = $conn->prepare("SELECT Customer_ID, customer_name FROM customers WHERE Customer_ID = ?");
        $customer_check->execute([$customer_id]);
        $customer_data = $customer_check->fetch(PDO::FETCH_ASSOC);
        if (!$customer_data) {
            $errors[] = "Customer does not exist.";
        } else {
            $customer_name = $customer_data['customer_name'];
        }
    }
    
    // Sale ID validation (if provided)
    if ($sale_id > 0) {
        $sale_check = $conn->prepare("SELECT Sale_ID FROM sales WHERE Sale_ID = ?");
        $sale_check->execute([$sale_id]);
        if (!$sale_check->fetch()) {
            $errors[] = "Sale does not exist.";
        }
    }
    
    // Invoice date validation
    if (empty($invoice_date)) {
        $errors[] = "Invoice date is required.";
    } else {
        $date_parts = explode('-', $invoice_date);
        if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
            $errors[] = "Invalid invoice date format.";
        }
    }
    
    // Invoice amount validation
    if ($invoice_amount <= 0) {
        $errors[] = "Invoice amount must be greater than 0.";
    }
    if ($invoice_amount > 99999999) {
        $errors[] = "Invoice amount exceeds maximum (₱99,999,999).";
    }
    
    // Amount due validation
    if ($amount_due < 0) {
        $errors[] = "Amount due cannot be negative.";
    }
    if ($amount_due > $invoice_amount) {
        $errors[] = "Amount due cannot exceed invoice amount.";
    }
    if ($amount_due > 99999999) {
        $errors[] = "Amount due exceeds maximum (₱99,999,999).";
    }
    
    // Status validation
    $valid_statuses = ['Open', 'Partial', 'Paid', 'Overdue', 'Closed', 'Pending'];
    if (!in_array($status, $valid_statuses)) {
        $errors[] = "Invalid status. Must be one of: " . implode(', ', $valid_statuses);
    }
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'error' => implode(' | ', $errors)]);
        return;
    }
    
    if ($amount_due <= 0) {
        $amount_due = $invoice_amount;
    }

    // Check Credit Limit and get aging_days
    $credit_query = $conn->prepare("SELECT credit_limit, customer_name, aging_days, email FROM customers WHERE Customer_ID = ?");
    $credit_query->execute([$customer_id]);
    $customer_data = $credit_query->fetch();
    $credit_limit = floatval($customer_data['credit_limit'] ?? 0);
    $aging_days = intval($customer_data['aging_days'] ?? 30);
    $customer_name = $customer_data['customer_name'] ?? $customer_name ?? 'Unknown';
    
    // Calculate due_date based on customer's aging_days (if not provided in POST)
    if (isset($_POST['due_date']) && !empty($_POST['due_date'])) {
        $due_date = trim($_POST['due_date']);
    } else {
        $due_date = date('Y-m-d', strtotime("+{$aging_days} days"));
    }

    $outstanding_query = $conn->prepare("SELECT AR_ID, invoice_amount, amount_due, invoice_date, due_date 
                                       FROM account_receivable 
                                       WHERE Customer_ID = ? AND status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    $outstanding_query->execute([$customer_id]);
    $outstanding_invoices = $outstanding_query->fetchAll();
    
    $total_outstanding = 0;
    foreach ($outstanding_invoices as $row) {
        $total_outstanding += floatval($row['amount_due']);
    }

    // Combined credit check: allow AR as long as total outstanding + new AR doesn't exceed credit limit
    $total_after_ar = $total_outstanding + $amount_due;
    if ($credit_limit > 0 && $total_after_ar > $credit_limit) {
        logActivity('AR', "Credit cap blocked AR for customer {$customer_name} (ID: {$customer_id}). " .
            "Outstanding: {$total_outstanding}, Limit: {$credit_limit}, Attempted AR: {$amount_due}", $customer_id);
        
        echo json_encode([
            'success' => false,
            'error' => 'Adding this AR (₱' . number_format($amount_due, 2) . ') would bring the total to ₱' . number_format($total_after_ar, 2) . ', exceeding the credit limit of ₱' . number_format($credit_limit, 2) . '.',
            'details' => [
                'credit_limit' => $credit_limit,
                'current_outstanding' => $total_outstanding,
                'new_ar_amount' => $amount_due,
                'total_after_ar' => $total_after_ar,
                'excess_amount' => $total_after_ar - $credit_limit,
                'outstanding_invoices' => $outstanding_invoices
            ],
            'recommendation' => 'Reduce the AR amount so that total outstanding (₱' . number_format($total_outstanding, 2) . ') + new AR stays within the credit limit of ₱' . number_format($credit_limit, 2) . '.'
        ]);
        return;
    }
    
    $opening_balance = $amount_due;
    
    // Sale_ID is NOT NULL in DB - use real Sale_ID or create a placeholder sale row with required columns
    if ($sale_id <= 0) {
        $sale_id = createPlaceholderSaleId($conn, $user_id);
    }
    
    $stmt = $conn->prepare("INSERT INTO account_receivable 
        (Sale_ID, Customer_ID, invoice_date, invoice_amount, opening_balance, amount_due, due_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([
        $sale_id, $customer_id, $invoice_date, $invoice_amount,
        $opening_balance, $amount_due, $due_date, $status])) {
        $ar_id = (int) $conn->lastInsertId();
        logActivity('AR', "Created AR record for customer ID: $customer_id, Invoice: " . number_format($invoice_amount, 2), $ar_id);

        $email_sent = false;
        $customer_email = trim((string)($customer_data['email'] ?? ''));
        if ($customer_email !== '' && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
            try {
                $mailResult = sendARCreatedEmail(
                    $customer_email,
                    $customer_name,
                    $ar_id,
                    $invoice_amount,
                    $amount_due,
                    $due_date,
                    $sale_id
                );
                $email_sent = $mailResult['ok'] ?? false;
            } catch (Throwable $e) {
                error_log('AR create email failed: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'ar_id' => $ar_id,
            'email_sent' => $email_sent,
            'message' => 'AR record created successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create AR record']);
    }
}

/**
 * Record a payment and apply it using FIFO method
 */
function recordPayment($conn, $user_id) {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $ar_id = intval($_POST['ar_id'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    
    // Comprehensive validation
    $errors = [];
    
    // Customer ID validation
    if (empty($customer_id) || $customer_id <= 0) {
        $errors[] = "Customer is required.";
    } else {
        $customer_check = $conn->prepare("SELECT Customer_ID FROM customers WHERE Customer_ID = ?");
        $customer_check->execute([$customer_id]);
        if (!$customer_check->fetch()) {
            $errors[] = "Customer does not exist.";
        }
    }
    
    // AR ID validation (if provided)
    if ($ar_id > 0) {
        $ar_check = $conn->prepare("SELECT AR_ID FROM account_receivable WHERE AR_ID = ? AND Customer_ID = ?");
        $ar_check->execute([$ar_id, $customer_id]);
        if (!$ar_check->fetch()) {
            $errors[] = "AR record does not exist or does not belong to this customer.";
        }
    }
    
    // Payment amount validation
    if ($amount_paid <= 0) {
        $errors[] = "Payment amount must be greater than 0.";
    }
    if ($amount_paid > 99999999) {
        $errors[] = "Payment amount exceeds maximum (₱99,999,999).";
    }
    
    // Payment date validation
    if (empty($payment_date)) {
        $errors[] = "Payment date is required.";
    } else {
        $date_parts = explode('-', $payment_date);
        if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
            $errors[] = "Invalid payment date format.";
        }
    }
    
    // User ID validation
    if (empty($user_id) || $user_id <= 0) {
        $errors[] = "Invalid user session. Please log in again.";
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'error' => implode(' | ', $errors)]);
        return;
    }

    $openShift = getOpenCashShiftForUser($conn, (int) $user_id);
    if (!$openShift) {
        echo json_encode(['success' => false, 'error' => 'Open a cashier shift before recording AR payments.']);
        return;
    }
    
    $conn->beginTransaction();
    
    try {
        $remaining_payment = $amount_paid;
        $applications = [];
        
        if ($ar_id > 0) {
            $ar_query = $conn->prepare("SELECT AR_ID, amount_due, invoice_amount, invoice_date, due_date, 0 AS sort_order
                FROM account_receivable 
                WHERE AR_ID = ? AND Customer_ID = ? AND amount_due > 0 AND status NOT IN ('Paid', 'Closed')
                UNION
                SELECT AR_ID, amount_due, invoice_amount, invoice_date, due_date, 1 AS sort_order
                FROM account_receivable 
                WHERE Customer_ID = ? AND amount_due > 0 AND status NOT IN ('Paid', 'Closed') AND AR_ID != ?
                ORDER BY sort_order ASC, invoice_date ASC, AR_ID ASC");
            $ar_query->execute([$ar_id, $customer_id, $customer_id, $ar_id]);
        } else {
            $ar_query = $conn->prepare("SELECT AR_ID, amount_due, invoice_amount, invoice_date, due_date
                FROM account_receivable 
                WHERE Customer_ID = ? AND amount_due > 0 AND status NOT IN ('Paid', 'Closed')
                ORDER BY invoice_date ASC, AR_ID ASC");
            $ar_query->execute([$customer_id]);
        }
        
        $ar_results = $ar_query->fetchAll();
        
        foreach ($ar_results as $ar) {
            if ($remaining_payment <= 0) break;
            
            $current_ar_id = $ar['AR_ID'];
            $ar_balance = floatval($ar['amount_due']);
            
            $apply_amount = min($remaining_payment, $ar_balance);
            $new_balance = $ar_balance - $apply_amount;
            $remaining_payment -= $apply_amount;
            
            $pay_stmt = $conn->prepare("INSERT INTO ar_payment 
                (payment_date, amount_paid, remaining_balance, collected_by)
                VALUES (?, ?, ?, ?)");
            if (!$pay_stmt->execute([$payment_date, $apply_amount, $new_balance, $user_id])) {
                throw new Exception("Failed to create payment record");
            }
            
            $payment_id = $conn->lastInsertId();

            recordCashSessionEntry($conn, [
                'shift_id' => (int) $openShift['shift_id'],
                'entry_type' => 'ar_collection',
                'source_label' => 'AR Collection',
                'payment_id' => (int) $payment_id,
                'gross_amount' => $apply_amount,
                'cash_received' => $apply_amount,
                'change_given' => 0,
                'net_cash' => $apply_amount,
                'User_ID' => (int) $user_id,
            ]);
            
            $link_stmt = $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)");
            $link_stmt->execute([$current_ar_id, $payment_id]);
            
            $new_status = $new_balance <= 0 ? 'Paid' : 'Partial';
            $update_stmt = $conn->prepare("UPDATE account_receivable
                SET amount_due = ?, status = ?, updated_at = NOW()
                WHERE AR_ID = ?");
            $update_stmt->execute([$new_balance, $new_status, $current_ar_id]);
            
            $applications[] = [
                'ar_id' => $current_ar_id,
                'applied' => $apply_amount,
                'new_balance' => $new_balance,
                'status' => $new_status,
                'new_due_date' => null,
                'due_date_extended' => false
            ];
        }
        
        $credit_balance = $remaining_payment;
        $conn->commit();
        cacheInvalidateTable('account_receivable');
        cacheInvalidateTable('ar_payment');

        logActivity('AR', "Recorded payment of " . number_format($amount_paid, 2) . " for customer ID: $customer_id", $customer_id);

        $email_sent = false;
        $cust_stmt = $conn->prepare("SELECT customer_name, email FROM customers WHERE Customer_ID = ?");
        $cust_stmt->execute([$customer_id]);
        $cust_row = $cust_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $customer_email = trim((string)($cust_row['email'] ?? ''));
        $customer_name = trim((string)($cust_row['customer_name'] ?? 'Customer'));

        if ($customer_email !== '' && filter_var($customer_email, FILTER_VALIDATE_EMAIL) && !empty($applications)) {
            try {
                $primary = $applications[0];
                foreach ($applications as $app) {
                    if ($ar_id > 0 && (int)$app['ar_id'] === $ar_id) {
                        $primary = $app;
                        break;
                    }
                }
                $primary_ar_id = (int)($primary['ar_id'] ?? 0);
                $applied_amount = (float)($primary['applied'] ?? $amount_paid);
                $remaining_balance = (float)($primary['new_balance'] ?? 0);
                $fully_paid = ($primary['status'] ?? '') === 'Paid' || $remaining_balance <= 0;

                $invoice_amount = 0.0;
                if ($primary_ar_id > 0) {
                    $inv_stmt = $conn->prepare("SELECT invoice_amount FROM account_receivable WHERE AR_ID = ?");
                    $inv_stmt->execute([$primary_ar_id]);
                    $invoice_amount = (float)($inv_stmt->fetchColumn() ?: 0);
                }

                $mailResult = sendARPaymentEmail(
                    $customer_email,
                    $customer_name,
                    $primary_ar_id,
                    $applied_amount,
                    $remaining_balance,
                    $fully_paid,
                    $invoice_amount,
                    $primary['new_due_date'] ?? null
                );
                $email_sent = $mailResult['ok'] ?? false;
            } catch (Throwable $e) {
                error_log('AR payment email failed: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'amount_paid' => $amount_paid,
            'applications' => $applications,
            'credit_balance' => $credit_balance,
            'email_sent' => $email_sent,
            'message' => 'Payment recorded and applied successfully'
        ]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        error_log('AR createAR error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
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
    
    // Get the most recent payment_id for this AR
    $pay_stmt = $conn->prepare("SELECT Payment_ID FROM singil WHERE AR_ID = ? ORDER BY Payment_ID DESC LIMIT 1");
    $pay_stmt->execute([$ar_id]);
    $payment_id = $pay_stmt->fetchColumn() ?: null;
    
    // Get the next attempt number
    $count_stmt = $conn->prepare("SELECT COALESCE(MAX(attempt_no), 0) + 1 as next_no 
        FROM ar_retry_attempt ra 
        INNER JOIN singil st ON ra.Payment_ID = st.Payment_ID 
        WHERE st.AR_ID = ?");
    $count_stmt->execute([$ar_id]);
    $attempt_no = (int) $count_stmt->fetchColumn() ?: 1;
    
    // If no payment exists yet, create a dummy entry
    if (!$payment_id) {
        $stmt = $conn->prepare("INSERT INTO ar_payment (payment_date, amount_paid, remaining_balance, collected_by) VALUES (CURDATE(), 0, 0, ?)");
        $stmt->execute([$user_id]);
        $payment_id = $conn->lastInsertId();
        
        $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)")->execute([$ar_id, $payment_id]);
    }
    
    $stmt = $conn->prepare("INSERT INTO ar_retry_attempt 
        (Payment_ID, retried_by, attempt_no, status, remarks)
        VALUES (?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$payment_id, $user_id, $attempt_no, $status, $remarks])) {
        echo json_encode([
            'success' => true,
            'retry_id' => $conn->lastInsertId(),
            'attempt_no' => $attempt_no,
            'message' => 'Retry attempt recorded'
        ]);
        logActivity('AR', "Recorded collection retry attempt #$attempt_no for AR ID: $ar_id", $ar_id);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to record retry attempt']);
    }
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
    
    $query = "SELECT ar.*, c.credit_limit, c.customer_name, c.aging_days
              FROM account_receivable ar
              JOIN customers c ON ar.Customer_ID = c.Customer_ID
              WHERE ar.Customer_ID = ?
              ORDER BY ar.invoice_date ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$customer_id]);
    $records = $stmt->fetchAll();
    
    $total_outstanding = 0;
    $open_count = 0;
    $credit_limit = 0;
    $customer_name = '';
    $aging_days = 30;
    
    if ($records) {
        foreach ($records as $row) {
            $credit_limit = floatval($row['credit_limit']);
            $customer_name = $row['customer_name'];
            $aging_days = intval($row['aging_days'] ?? 30);
            if (!in_array($row['status'], ['Paid', 'Closed']) && floatval($row['amount_due']) > 0) {
                $total_outstanding += floatval($row['amount_due']);
                $open_count++;
            }
        }
    } else {
        $c_stmt = $conn->prepare("SELECT customer_name, credit_limit, aging_days FROM customers WHERE Customer_ID = ?");
        $c_stmt->execute([$customer_id]);
        $c_res = $c_stmt->fetch();
        if ($c_res) {
            $customer_name = $c_res['customer_name'];
            $credit_limit = floatval($c_res['credit_limit']);
            $aging_days = intval($c_res['aging_days'] ?? 30);
        }
    }
    
    echo json_encode([
        'success' => true,
        'customer_id' => $customer_id,
        'customer_name' => $customer_name,
        'credit_limit' => $credit_limit,
        'aging_days' => $aging_days,
        'records' => $records,
        'total_outstanding' => $total_outstanding,
        'open_count' => $open_count,
        'remaining_credit' => max(0, $credit_limit - $total_outstanding)
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
    
    // Get AR details
    $ar_stmt = $conn->prepare("SELECT ar.*, c.customer_name, c.phone_number, c.address
        FROM account_receivable ar
        LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
        WHERE ar.AR_ID = ?");
    $ar_stmt->execute([$ar_id]);
    $ar = $ar_stmt->fetch();
    
    if (!$ar) {
        echo json_encode(['success' => false, 'error' => 'AR not found']);
        return;
    }
    
    // Get payments
    $pay_stmt = $conn->prepare("SELECT p.*, u.user_name as collected_by_name
        FROM ar_payment p
        INNER JOIN singil st ON p.payment_ID = st.Payment_ID
        LEFT JOIN user u ON p.collected_by = u.User_ID
        WHERE st.AR_ID = ? AND p.amount_paid > 0
        ORDER BY p.payment_date ASC");
    $pay_stmt->execute([$ar_id]);
    $payments = $pay_stmt->fetchAll();
    
    // Get retries (optional table in older databases)
    $retries = [];
    $has_retry_table = (bool) $conn->query("SHOW TABLES LIKE 'ar_retry_attempt'")->fetchColumn();
    if ($has_retry_table) {
        $retry_stmt = $conn->prepare("SELECT ra.*, u.user_name as retried_by_name
            FROM ar_retry_attempt ra
            INNER JOIN singil st ON ra.Payment_ID = st.Payment_ID
            LEFT JOIN user u ON ra.retried_by = u.User_ID
            WHERE st.AR_ID = ?
            ORDER BY ra.created_at DESC");
        $retry_stmt->execute([$ar_id]);
        $retries = $retry_stmt->fetchAll();
    }
    
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
    $summary['total_outstanding'] = (float) $conn->query("SELECT SUM(amount_due) FROM account_receivable WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0")->fetchColumn() ?: 0;
    
    // Overdue
    $overdue = $conn->query("SELECT SUM(amount_due) as total, COUNT(*) as count FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid', 'Closed') AND amount_due > 0")->fetch();
    $summary['total_overdue'] = (float) ($overdue['total'] ?? 0);
    $summary['overdue_count'] = (int) ($overdue['count'] ?? 0);
    
    // Open count
    $summary['open_count'] = (int) $conn->query("SELECT COUNT(*) FROM account_receivable WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0")->fetchColumn() ?: 0;
    
    // Collected this month
    $month_start = date('Y-m-01');
    $summary['collected_this_month'] = (float) $conn->prepare("SELECT SUM(amount_paid) FROM ar_payment WHERE payment_date >= ? AND amount_paid > 0")->execute([$month_start]) ? $conn->query("SELECT SUM(amount_paid) FROM ar_payment WHERE payment_date >= '$month_start' AND amount_paid > 0")->fetchColumn() : 0;
    // Wait, let's fix that last one
    $summary['collected_this_month'] = (float) $conn->query("SELECT SUM(amount_paid) FROM ar_payment WHERE payment_date >= '" . date('Y-m-01') . "' AND amount_paid > 0")->fetchColumn() ?: 0;
    
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
    
    $records = $conn->query($query)->fetchAll();
    echo json_encode(['success' => true, 'records' => $records]);
}

/**
 * Generate Customer Aging Report
 */
function getAgingReport($conn) {
    $query = "
        SELECT 
            c.Customer_ID,
            c.customer_name,
            c.credit_limit,
            ar.AR_ID,
            ar.Sale_ID,
            ar.invoice_date,
            ar.due_date,
            ar.invoice_amount,
            ar.amount_due,
            DATEDIFF(CURDATE(), ar.due_date) as days_outstanding
        FROM customers c
        INNER JOIN account_receivable ar ON c.Customer_ID = ar.Customer_ID
        WHERE ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0
        ORDER BY c.customer_name ASC, ar.due_date ASC
    ";
    
    try {
        $results = $conn->query($query)->fetchAll();
        
        $report = [];
        foreach ($results as $row) {
            $cid = $row['Customer_ID'];
            if (!isset($report[$cid])) {
                $report[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $row['customer_name'],
                    'credit_limit' => floatval($row['credit_limit']),
                    'total_outstanding' => 0,
                    'buckets' => [
                        'current' => ['total' => 0, 'invoices' => []],
                        '1_30' => ['total' => 0, 'invoices' => []],
                        '31_60' => ['total' => 0, 'invoices' => []],
                        '61_90' => ['total' => 0, 'invoices' => []],
                        '90_plus' => ['total' => 0, 'invoices' => []]
                    ]
                ];
            }
            
            $days = intval($row['days_outstanding']);
            $bucket = '';
            if ($days <= 0) $bucket = 'current';
            elseif ($days <= 30) $bucket = '1_30';
            elseif ($days <= 60) $bucket = '31_60';
            elseif ($days <= 90) $bucket = '61_90';
            else $bucket = '90_plus';
            
            $invoice = [
                'ar_id' => $row['AR_ID'],
                'sale_id' => $row['Sale_ID'],
                'invoice_date' => $row['invoice_date'],
                'due_date' => $row['due_date'],
                'invoice_amount' => floatval($row['invoice_amount']),
                'amount_due' => floatval($row['amount_due']),
                'days_outstanding' => $days
            ];
            
            $report[$cid]['buckets'][$bucket]['invoices'][] = $invoice;
            $report[$cid]['buckets'][$bucket]['total'] += $invoice['amount_due'];
            $report[$cid]['total_outstanding'] += $invoice['amount_due'];
        }
        
        foreach ($report as &$customer) {
            $customer['is_over_limit'] = $customer['total_outstanding'] > $customer['credit_limit'];
            $customer['near_limit'] = !$customer['is_over_limit'] && ($customer['total_outstanding'] > ($customer['credit_limit'] * 0.9));
            
            $recommendations = [];
            if ($customer['is_over_limit']) {
                $recommendations[] = "Credit limit exceeded! Immediately restrict further credit sales and request payment of at least ₱" . number_format($customer['total_outstanding'] - $customer['credit_limit'], 2) . ".";
            } elseif ($customer['near_limit']) {
                $recommendations[] = "Customer is approaching credit limit (90%+ used). Advise caution for new credit requests.";
            }
            
            if ($customer['buckets']['90_plus']['total'] > 0) {
                $recommendations[] = "Severe delinquency (90+ days). Send final demand letter and consider legal action.";
            } elseif ($customer['buckets']['61_90']['total'] > 0) {
                $recommendations[] = "High delinquency (61-90 days). Escalate collection efforts and call customer directly.";
            } elseif ($customer['buckets']['31_60']['total'] > 0) {
                $recommendations[] = "Moderate delinquency (31-60 days). Send formal payment reminder.";
            } elseif ($customer['buckets']['1_30']['total'] > 0) {
                $recommendations[] = "Slightly overdue (1-30 days). Send friendly reminder.";
            }
            
            $customer['recommendations'] = $recommendations;
        }
        
        echo json_encode(['success' => true, 'report' => array_values($report)]);
    } catch (Exception $e) {
        http_response_code(500);
        error_log('AR aging report error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
    }
}

/**
 * Get AR history (all records) with optional date filter and pagination
 * Supports date_from, date_to (GET params) - filter by invoice_date
 * Supports page, per_page (GET params) - pagination
 * 
 * Performance Update: Added pagination to prevent memory issues with large datasets
 */
function getARHistory($conn) {
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    // Pagination parameters (Performance Fix)
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20))); // Max 100 per page
    $offset = ($page - 1) * $per_page;
    
    $where = ["1=1"];
    $params = [];
    
    if (!empty($date_from)) {
        $where[] = "ar.invoice_date >= ?";
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $where[] = "ar.invoice_date <= ?";
        $params[] = $date_to;
    }
    
    // Get total count for pagination (Performance Fix)
    $count_sql = "SELECT COUNT(*) FROM account_receivable ar WHERE " . implode(" AND ", $where);
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
    
    // Main query with pagination
    $sql = "SELECT ar.*, c.customer_name, c.phone_number,
                   DATEDIFF(CURDATE(), ar.due_date) as days_overdue
            FROM account_receivable ar
            LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
            WHERE " . implode(" AND ", $where) . "
            ORDER BY ar.invoice_date DESC, ar.AR_ID DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true, 
        'records' => $records,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ]
    ]);
}

function sendARReminderEmail($conn, $user_id) {
    $ar_id = intval($_POST['ar_id'] ?? 0);
    if ($ar_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'AR ID is required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT ar.AR_ID, ar.amount_due, ar.due_date, c.customer_name, c.email
        FROM account_receivable ar
        LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
        WHERE ar.AR_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$ar_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'AR record not found.']);
        return;
    }
    $email = trim((string)($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Customer has no valid email address.']);
        return;
    }

    $send = sendARBalanceReminderEmail(
        $email,
        (string)($row['customer_name'] ?? 'Customer'),
        (int)$row['AR_ID'],
        (float)$row['amount_due'],
        (string)$row['due_date']
    );

    if (!$send['ok']) {
        echo json_encode(['success' => false, 'error' => $send['message']]);
        return;
    }

    // Persist reminder timestamp for AR badge display.
    try {
        arReminderRecordEmail(
            $conn,
            $ar_id,
            $email,
            'manual',
            (string)$row['due_date'],
            (float)$row['amount_due'],
            (int)$user_id
        );
    } catch (Throwable $e) {
        // Non-blocking: reminder email already sent.
    }

    // Log action note (non-blocking for reminder send success).
    logActivity('AR', "Sent AR reminder email for AR ID: $ar_id", $ar_id);
    echo json_encode(['success' => true, 'message' => 'Reminder email sent successfully.']);
}

function sendARReminderSms($conn, $user_id) {
    $ar_id = intval($_POST['ar_id'] ?? 0);
    if ($ar_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'AR ID is required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT ar.AR_ID, ar.amount_due, ar.due_date, c.customer_name, c.phone_number
        FROM account_receivable ar
        LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
        WHERE ar.AR_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$ar_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'AR record not found.']);
        return;
    }

    $phone = trim((string)($row['phone_number'] ?? ''));
    if ($phone === '') {
        echo json_encode(['success' => false, 'error' => 'Customer has no phone number on file.']);
        return;
    }

    $send = sendARBalanceReminderSms(
        $phone,
        (string)($row['customer_name'] ?? 'Customer'),
        (int)$row['AR_ID'],
        (float)$row['amount_due'],
        (string)$row['due_date']
    );

    if (!$send['ok']) {
        echo json_encode(['success' => false, 'error' => $send['message']]);
        return;
    }

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS ar_sms_reminders (
                reminder_id INT AUTO_INCREMENT PRIMARY KEY,
                AR_ID INT NOT NULL,
                customer_phone VARCHAR(50) NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_by INT NULL,
                provider VARCHAR(50) NULL,
                INDEX idx_ar_sms_sent_at (AR_ID, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ins = $conn->prepare("INSERT INTO ar_sms_reminders (AR_ID, customer_phone, sent_by, provider) VALUES (?, ?, ?, ?)");
        $ins->execute([$ar_id, $phone, $user_id, (string)($send['provider'] ?? '')]);
    } catch (Throwable $e) {
        // Non-blocking logging only.
    }

    logActivity('AR', "Sent AR reminder SMS for AR ID: $ar_id", $ar_id);
    echo json_encode(['success' => true, 'message' => 'Reminder SMS sent successfully.']);
}
?>
