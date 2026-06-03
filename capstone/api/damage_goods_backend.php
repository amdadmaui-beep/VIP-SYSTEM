<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/logger.php';
require_once '../includes/module_access.php';
require_once '../includes/roles_helper.php';
require_once __DIR__ . '/../includes/damage_type_helper.php';

// Only Managers (2, 4) or Owners (1) can access
$allowed_roles = [1, 2, 4];
requireRole($allowed_roles);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_damage'])) {
    requireCsrfToken(false, false);

    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity_raw = trim((string)($_POST['quantity'] ?? ''));
    $quantity = filter_var($quantity_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $damage_types = getDamageTypeOptions();
    $damage_type = trim((string)($_POST['damage_type'] ?? ''));
    $reason = trim($_POST['reason'] ?? '');
    $user_id = intval($_SESSION['user_id'] ?? 0);

    if ($product_id <= 0 || $quantity === false) {
        $_SESSION['error'] = "Invalid product or quantity.";
        header('Location: ../pages/damage_goods.php');
        exit;
    }

    if ($damage_type === '' || !in_array($damage_type, $damage_types, true)) {
        $_SESSION['error'] = "Invalid damage reason selected.";
        header('Location: ../pages/damage_goods.php');
        exit;
    }

    $conn->beginTransaction();
    try {
        // 1. Get current inventory
        $stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC, Inventory_ID DESC LIMIT 1");
        $stmt->execute([$product_id]);
        $inv = $stmt->fetch();

        if (!$inv || $inv['quantity'] < $quantity) {
            throw new Exception("Insufficient stock to report this damage.");
        }

        $old_qty = $inv['quantity'];
        $new_qty = $old_qty - $quantity;
        $inventory_id = $inv['Inventory_ID'];

        // 2. Create manual adjustment record
        $stmt = $conn->prepare("INSERT INTO manual_adjustment (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)");
        $stmt->execute(["Damage Report: $damage_type", $user_id]);
        $adjustment_id = $conn->lastInsertId();

        // 3. Create adjustment detail record
        $stmt = $conn->prepare("INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, 'decrease', ?)");
        $stmt->execute([$product_id, $adjustment_id, $old_qty, $new_qty, $reason]);

        // 4. Create damage_goods record
        $stmt = $conn->prepare("INSERT INTO damage_goods (Inventory_ID, Adjustment_ID, quantity, reported_by, reason, damage_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$inventory_id, $adjustment_id, $quantity, $user_id, $reason, $damage_type]);

        // 5. Update inventory
        $stmt = $conn->prepare("UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?");
        $stmt->execute([$new_qty, $inventory_id]);

        // 6. LOG TO LEDGER
        $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, 'DAMAGE LOSS', ?, ?, ?, ?, ?)");
        $ledger_stmt->execute([
            $product_id,
            $adjustment_id,
            -$quantity,
            $new_qty,
            $user_id,
            "Damage Loss: $damage_type - $reason"
        ]);

        $conn->commit();
        logActivity('INVENTORY', "Damage reported for Product #$product_id: $quantity units ($damage_type)", $adjustment_id);
        
        $_SESSION['success'] = "Damage report saved successfully.";
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }

    header('Location: ../pages/damage_goods.php');
    exit;
}
