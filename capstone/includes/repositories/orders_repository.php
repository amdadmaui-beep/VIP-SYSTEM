<?php
declare(strict_types=1);

function ordersRepoTableExists(PDO $conn, string $tableName): bool
{
    $stmt = $conn->query("SHOW TABLES LIKE " . $conn->quote($tableName));
    return $stmt && $stmt->rowCount() > 0;
}

function ordersRepoGetColumns(PDO $conn, string $tableName): array
{
    $stmt = $conn->query("SHOW COLUMNS FROM {$tableName}");
    if (!$stmt) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function ordersRepoResolveDeliveryPerson(PDO $conn, string $deliveryPersonRaw): array
{
    $deliveryPersonRaw = trim($deliveryPersonRaw);
    if ($deliveryPersonRaw === '') {
        return ['id' => null, 'name' => ''];
    }

    if (!ctype_digit($deliveryPersonRaw)) {
        return ['id' => null, 'name' => $deliveryPersonRaw];
    }

    $id = (int)$deliveryPersonRaw;
    $nameStmt = $conn->prepare("SELECT COALESCE(full_name, user_name) AS name FROM user WHERE User_ID = ?");
    $nameStmt->execute([$id]);
    $name = (string)($nameStmt->fetchColumn() ?: '');

    return ['id' => $id, 'name' => $name];
}

function ordersRepoEnsureDeliveryDetails(PDO $conn, int $deliveryId, int $orderId): void
{
    if ($deliveryId <= 0 || $orderId <= 0) {
        return;
    }

    if (!ordersRepoTableExists($conn, 'delivery_detail') || !ordersRepoTableExists($conn, 'order_details')) {
        return;
    }

    $sql = "
        INSERT INTO delivery_detail (Delivery_ID, Order_detail_ID, received_qty, damage_qty, status, created_at, updated_at)
        SELECT
            ?, od.Order_detail_ID, od.ordered_qty, 0, 'Pending', NOW(), NOW()
        FROM order_details od
        LEFT JOIN delivery_detail dd
          ON dd.Delivery_ID = ? AND dd.Order_detail_ID = od.Order_detail_ID
        WHERE od.Order_ID = ? AND dd.Delivery_Detail_ID IS NULL
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("ordersRepoEnsureDeliveryDetails prepare failed");
        return;
    }

    if (!$stmt->execute([$deliveryId, $deliveryId, $orderId])) {
        error_log("ordersRepoEnsureDeliveryDetails execute failed");
    }
}

function ordersRepoLogStatusChange(PDO $conn, int $orderId, ?string $oldStatus, string $newStatus, int $userId, string $notes = ''): void
{
    if (!ordersRepoTableExists($conn, 'order_status_history')) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO order_status_history (Order_ID, old_status, new_status, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$orderId, $oldStatus, $newStatus, $userId, $notes]);
}
