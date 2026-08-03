<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

// Accessible to Owner (1) and Manager (2, 4)
requireRole([1, 2, 4]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken(false)) {
        header("Location: ../pages/users.php?error=" . urlencode('Invalid or expired security token. Please refresh the page and try again.'));
        exit();
    }

    try {
        $action = isset($_POST['action']) ? $_POST['action'] : 'add';
        $errors = [];

    if ($action === 'add') {
        $customer_name = trim($_POST['customer_name']);
        $phone_number = trim($_POST['phone_number']);
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $aging_days = intval($_POST['aging_days'] ?? 0);
        $credit_limit = floatval($_POST['credit_limit'] ?? 0);

        // Auto-calculate credit limit from aging_days if not provided or zero
        if ($credit_limit <= 0) {
            $map = [15 => 2000, 30 => 3500, 40 => 4500, 60 => 6000];
            $credit_limit = $map[$aging_days] ?? 2000;
        }

        // Basic validation
        if (empty($customer_name)) {
            $errors[] = "Customer name is required.";
        } elseif (strlen($customer_name) < 2) {
            $errors[] = "Customer name must be at least 2 characters.";
        }
        
        if (empty($phone_number)) {
            $errors[] = "Phone number is required.";
        } elseif (!preg_match('/^[0-9\s\-\+\(\)]+$/', $phone_number)) {
            $errors[] = "Phone number contains invalid characters.";
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        
        if ($aging_days <= 0 || !in_array($aging_days, [15, 30, 40, 60])) {
            $errors[] = "Aging days must be 15, 30, 40, or 60 days.";
        }

        if (empty($errors)) {
            // Check for duplicate customer name (excluding soft-deleted)
            $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM customers WHERE customer_name = ? AND deleted_at IS NULL");
            $check_stmt->execute([$customer_name]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && intval($result['cnt']) > 0) {
                $errors[] = "A customer with this name already exists.";
            }
        }

        if (empty($errors)) {
            // Insert into database
            $stmt = $conn->prepare("INSERT INTO customers (customer_name, phone_number, email, address, aging_days, credit_limit) VALUES (?, ?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$customer_name, $phone_number, $email, $address, $aging_days, $credit_limit])) {
                header("Location: ../pages/users.php?success=1");
                exit();
            } else {
                $errors[] = "Error adding customer.";
            }
        }
    } elseif ($action === 'edit') {
        $customer_id = intval($_POST['customer_id']);
        $customer_name = trim($_POST['customer_name']);
        $phone_number = trim($_POST['phone_number']);
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $aging_days = intval($_POST['aging_days'] ?? 0);
        $credit_limit = floatval($_POST['credit_limit'] ?? 0);

        // Auto-calculate credit limit from aging_days if not provided or zero
        if ($credit_limit <= 0) {
            $map = [15 => 2000, 30 => 3500, 40 => 4500, 60 => 6000];
            $credit_limit = $map[$aging_days] ?? 2000;
        }

        // Basic validation
        if (empty($customer_id) || $customer_id <= 0) {
            $errors[] = "Invalid customer ID.";
        }
        
        if (empty($customer_name)) {
            $errors[] = "Customer name is required.";
        } elseif (strlen($customer_name) < 2) {
            $errors[] = "Customer name must be at least 2 characters.";
        }
        
        if (empty($phone_number)) {
            $errors[] = "Phone number is required.";
        } elseif (!preg_match('/^[0-9\s\-\+\(\)]+$/', $phone_number)) {
            $errors[] = "Phone number contains invalid characters.";
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        
        if ($aging_days <= 0 || !in_array($aging_days, [15, 30, 40, 60])) {
            $errors[] = "Aging days must be 15, 30, 40, or 60 days.";
        }

        if (empty($errors)) {
            // Check for duplicate customer name (excluding current customer and soft-deleted)
            $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM customers WHERE customer_name = ? AND deleted_at IS NULL AND Customer_ID != ?");
            $check_stmt->execute([$customer_name, $customer_id]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && intval($result['cnt']) > 0) {
                $errors[] = "A customer with this name already exists.";
            }
        }

        if (empty($errors)) {
            // Update customer in database
            $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, phone_number = ?, email = ?, address = ?, aging_days = ?, credit_limit = ? WHERE Customer_ID = ?");
            
            if ($stmt->execute([$customer_name, $phone_number, $email, $address, $aging_days, $credit_limit, $customer_id])) {
                header("Location: ../pages/users.php?success=2");
                exit();
            } else {
                $errors[] = "Error updating customer.";
            }
        }
    } elseif ($action === 'delete') {
        $customer_id = intval($_POST['customer_id']);
        if ($customer_id <= 0) {
            $errors[] = "Invalid customer ID.";
        } else {
            $stmt = $conn->prepare("UPDATE customers SET deleted_at = NOW() WHERE Customer_ID = ? AND deleted_at IS NULL");
            if ($stmt->execute([$customer_id])) {
                header("Location: ../pages/users.php?success=3");
                exit();
            } else {
                $errors[] = "Error deleting customer.";
            }
        }
    } elseif ($action === 'restore') {
        $customer_id = intval($_POST['customer_id']);
        if ($customer_id <= 0) {
            $errors[] = "Invalid customer ID.";
        } else {
            $stmt = $conn->prepare("UPDATE customers SET deleted_at = NULL WHERE Customer_ID = ?");
            if ($stmt->execute([$customer_id])) {
                header("Location: ../pages/users.php?success=4");
                exit();
            } else {
                $errors[] = "Error restoring customer.";
            }
        }
    }
        } catch (Throwable $e) {
        header("Location: ../pages/users.php?error=" . urlencode('Server error: ' . $e->getMessage()));
        exit();
    }
}
?>
