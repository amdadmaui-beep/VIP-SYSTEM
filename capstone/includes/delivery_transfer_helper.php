<?php
declare(strict_types=1);

require_once __DIR__ . '/rider_availability_helper.php';

/**
 * Ensure delivery_transfers audit table exists (idempotent).
 */
function ensureDeliveryTransfersTable(PDO $conn): void
{
    $conn->exec("
        CREATE TABLE IF NOT EXISTS delivery_transfers (
            transfer_id INT AUTO_INCREMENT PRIMARY KEY,
            Delivery_ID INT NOT NULL,
            from_rider_id INT NULL,
            to_rider_id INT NOT NULL,
            transferred_by_user_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_delivery_transfers_delivery (Delivery_ID),
            INDEX idx_delivery_transfers_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Statuses that must stay with the original rider (remittance / finished).
 */
function deliveryStatusesExcludedFromTransfer(): array
{
    return ['Completed', 'Cancelled', 'Delivered', 'Remitted'];
}

function deliveryIsTransferEligible(string $status): bool
{
    return !in_array($status, deliveryStatusesExcludedFromTransfer(), true);
}

function deliveryTransferExcludedSqlPlaceholders(): string
{
    $count = count(deliveryStatusesExcludedFromTransfer());
    return implode(',', array_fill(0, $count, '?'));
}

function deliveryTransferExcludedSqlParams(): array
{
    return deliveryStatusesExcludedFromTransfer();
}

function deliveryFormatTransferReason(array $row): string
{
    $bits = [];
    if (!empty($row['cancellation_reason'])) {
        $bits[] = (string)$row['cancellation_reason'];
    }
    if (!empty($row['cancellation_remarks'])) {
        $bits[] = (string)$row['cancellation_remarks'];
    }

    return implode(' - ', $bits);
}

/**
 * Rider user IDs that have at least one Returning + Vehicle issue delivery.
 */
function deliveryFindVehicleIssueRiderIds(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT Delivery_ID
         FROM delivery
         WHERE delivery_status = 'Returning'
           AND cancellation_reason = 'Vehicle issue'"
    );
    if (!$stmt) {
        return [];
    }

    $riderIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $deliveryId = (int)($row['Delivery_ID'] ?? 0);
        if ($deliveryId <= 0) {
            continue;
        }
        $riderId = riderGetUserIdByDeliveryId($conn, $deliveryId);
        if ($riderId > 0) {
            $riderIds[$riderId] = true;
        }
    }

    return array_map('intval', array_keys($riderIds));
}

/**
 * Deliveries a manager can bulk-transfer away from a rider (ownership-aware).
 */
function deliveryFetchTransferableForRider(PDO $conn, int $riderId): array
{
    if ($riderId <= 0) {
        return [];
    }

    $params = [];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $riderId, $params);
    if ($ownership === '0 = 1') {
        return [];
    }

    $excluded_ph = deliveryTransferExcludedSqlPlaceholders();
    $excluded_params = deliveryTransferExcludedSqlParams();
    $sql = "SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, d.cancellation_reason, d.cancellation_remarks,
                   COALESCE(o.customer_name_snapshot, c.customer_name) AS customer_name,
                   d.schedule_date
            FROM delivery d
            LEFT JOIN orders o ON d.Order_ID = o.Order_ID
            LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
            WHERE {$ownership}
              AND d.delivery_status NOT IN ({$excluded_ph})
            ORDER BY d.Delivery_ID";

    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge($params, $excluded_params));

    $deliveries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['delivery_status'] ?? '');
        if (!deliveryIsTransferEligible($status)) {
            continue;
        }
        $deliveries[] = [
            'delivery_id' => (int)$row['Delivery_ID'],
            'order_id' => (int)($row['Order_ID'] ?? 0),
            'customer_name' => (string)($row['customer_name'] ?? 'Unknown'),
            'status' => $status,
            'schedule_date' => $row['schedule_date'] ?? null,
            'reason' => deliveryFormatTransferReason($row),
            'eligible' => true,
        ];
    }

    return $deliveries;
}

function deliveryRiderHasVehicleIssueDeliveries(PDO $conn, int $riderId): bool
{
    if ($riderId <= 0) {
        return false;
    }

    $params = [];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $riderId, $params);
    if ($ownership === '0 = 1') {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM delivery d
         WHERE {$ownership}
           AND d.delivery_status = 'Returning'
           AND d.cancellation_reason = 'Vehicle issue'
         LIMIT 1"
    );
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

/**
 * @return array{data: array<int, array>, banner: array<int, array>, total: int}
 */
function deliveryBuildBulkTransferData(PDO $conn): array
{
    $data = [];
    $banner = [];
    $total = 0;

    foreach (deliveryFindVehicleIssueRiderIds($conn) as $riderId) {
        $deliveries = deliveryFetchTransferableForRider($conn, $riderId);
        if ($deliveries === []) {
            continue;
        }

        $nameStmt = $conn->prepare('SELECT COALESCE(full_name, user_name) FROM user WHERE User_ID = ? LIMIT 1');
        $nameStmt->execute([$riderId]);
        $riderName = trim((string)($nameStmt->fetchColumn() ?: ''));
        if ($riderName === '') {
            $riderName = 'User #' . $riderId;
        }

        $count = count($deliveries);
        $data[$riderId] = [
            'rider_name' => $riderName,
            'deliveries' => $deliveries,
        ];
        $banner[] = [
            'id' => $riderId,
            'name' => $riderName,
            'count' => $count,
        ];
        $total += $count;
    }

    return [
        'data' => $data,
        'banner' => $banner,
        'total' => $total,
    ];
}

/**
 * Reassign a delivery to a new rider after vehicle-issue transfer.
 */
function deliveryApplyRiderTransfer(PDO $conn, int $deliveryId, int $newRiderId, string $newRiderName): void
{
    if ($deliveryId <= 0 || $newRiderId <= 0) {
        return;
    }

    $del_cols = array_column($conn->query('SHOW COLUMNS FROM delivery')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $updates = ["delivery_status = 'Scheduled'", 'updated_at = NOW()'];
    $params = [];

    if (in_array('assigned_rider_id', $del_cols, true)) {
        $updates[] = 'assigned_rider_id = ?';
        $params[] = $newRiderId;
    }
    if (in_array('delivered_by_user_id', $del_cols, true)) {
        $updates[] = 'delivered_by_user_id = ?';
        $params[] = $newRiderId;
    }
    if (in_array('delivered_by', $del_cols, true)) {
        $updates[] = 'delivered_by = ?';
        $params[] = $newRiderName;
    }
    if (in_array('cancellation_reason', $del_cols, true)) {
        $updates[] = 'cancellation_reason = NULL';
    }
    if (in_array('cancellation_remarks', $del_cols, true)) {
        $updates[] = 'cancellation_remarks = NULL';
    }

    $params[] = $deliveryId;
    $conn->prepare('UPDATE delivery SET ' . implode(', ', $updates) . ' WHERE Delivery_ID = ?')->execute($params);
}
