<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'add';

    if ($action === 'add') {
        $customer_name = trim($_POST['customer_name']);
        $phone_number = trim($_POST['phone_number']);
        $address = trim($_POST['address']);
        $type = trim($_POST['type']);

        // Basic validation
        $errors = [];
        if (empty($customer_name)) $errors[] = "Customer name is required.";
        if (empty($phone_number)) $errors[] = "Phone number is required.";

        if (empty($errors)) {
            // Insert into database
            $stmt = $conn->prepare("INSERT INTO customers (customer_name, phone_number, address, type) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $customer_name, $phone_number, $address, $type);

            if ($stmt->execute()) {
                header("Location: ../pages/users.php?success=1");
                exit();
            } else {
                $errors[] = "Error adding customer: " . $conn->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'edit') {
        $customer_id = trim($_POST['customer_id']);
        $customer_name = trim($_POST['customer_name']);
        $phone_number = trim($_POST['phone_number']);
        $address = trim($_POST['address']);
        $type = trim($_POST['type']);

        // Basic validation
        $errors = [];
        if (empty($customer_id)) $errors[] = "Customer ID is required.";
        if (empty($customer_name)) $errors[] = "Customer name is required.";
        if (empty($phone_number)) $errors[] = "Phone number is required.";

        if (empty($errors)) {
            // Update customer in database
            $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, phone_number = ?, address = ?, type = ? WHERE Customer_ID = ?");
            $stmt->bind_param("ssssi", $customer_name, $phone_number, $address, $type, $customer_id);

            if ($stmt->execute()) {
                header("Location: ../pages/users.php?success=2");
                exit();
            } else {
                $errors[] = "Error updating customer: " . $conn->error;
            }
            $stmt->close();
        }
    }
}
?>
