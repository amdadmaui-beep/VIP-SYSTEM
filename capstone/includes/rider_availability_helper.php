<?php
declare(strict_types=1);

function riderWorkflowHasColumn(PDO $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            $cache[$key] = false;
            return false;
        }
        $escapedColumn = str_replace("'", "''", $column);
        $stmt = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escapedColumn}'");
        $cache[$key] = $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function ensureRiderWorkflowSchema(PDO $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (!riderWorkflowHasColumn($conn, 'user', 'rider_availability_status')) {
        try {
            $conn->exec("ALTER TABLE user ADD COLUMN rider_availability_status ENUM('Available','On Delivery','Off Duty') NOT NULL DEFAULT 'Available'");
        } catch (Throwable $e) {
            // Keep the page usable even if schema changes are blocked.
        }
    }

    // returned_to_store_at and returned_to_store_by_user_id columns removed from schema

    $ensured = true;
}

function riderWorkflowActiveDeliveryCondition(string $deliveryAlias = 'd'): string
{
    $alias = trim($deliveryAlias) !== '' ? trim($deliveryAlias) : 'd';

    return "(" . $alias . ".delivery_status IN ('Scheduled', 'In Transit', 'Delivered', 'Remitted', 'Returning'))";
}

function riderGetIdentity(PDO $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['full_name' => '', 'user_name' => '', 'names' => []];
    }

    try {
        $stmt = $conn->prepare("SELECT COALESCE(full_name, '') AS full_name, COALESCE(user_name, '') AS user_name FROM user WHERE User_ID = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    $fullName = trim((string)($row['full_name'] ?? ''));
    $userName = trim((string)($row['user_name'] ?? ''));
    $names = array_values(array_unique(array_filter([$fullName, $userName], static function ($value): bool {
        return trim((string)$value) !== '';
    })));

    return [
        'full_name' => $fullName,
        'user_name' => $userName,
        'names' => $names,
    ];
}

function riderBuildOwnershipCondition(PDO $conn, string $deliveryAlias, int $userId, array &$params): string
{
    $alias = trim($deliveryAlias) !== '' ? trim($deliveryAlias) : 'd';
    $conditions = [];
    $hasIdOwnershipColumn = false;

    if (riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id')) {
        $conditions[] = "{$alias}.assigned_rider_id = ?";
        $params[] = $userId;
        $hasIdOwnershipColumn = true;
    }

    if (riderWorkflowHasColumn($conn, 'delivery', 'delivered_by_user_id')) {
        $conditions[] = "{$alias}.delivered_by_user_id = ?";
        $params[] = $userId;
        $hasIdOwnershipColumn = true;
    }

    if (!$hasIdOwnershipColumn && riderWorkflowHasColumn($conn, 'delivery', 'delivered_by')) {
        $identity = riderGetIdentity($conn, $userId);
        foreach ($identity['names'] as $name) {
            $conditions[] = "TRIM(COALESCE({$alias}.delivered_by, '')) = ?";
            $params[] = $name;
        }
    }

    return !empty($conditions) ? '(' . implode(' OR ', $conditions) . ')' : '0 = 1';
}

function riderGetAvailabilityStatus(PDO $conn, int $userId): string
{
    ensureRiderWorkflowSchema($conn);
    if ($userId <= 0 || !riderWorkflowHasColumn($conn, 'user', 'rider_availability_status')) {
        return 'Available';
    }

    try {
        $stmt = $conn->prepare("SELECT rider_availability_status FROM user WHERE User_ID = ? LIMIT 1");
        $stmt->execute([$userId]);
        $status = (string)($stmt->fetchColumn() ?: 'Available');
        return in_array($status, ['Available', 'On Delivery', 'Off Duty'], true) ? $status : 'Available';
    } catch (Throwable $e) {
        return 'Available';
    }
}

function riderHasActiveDeliveries(PDO $conn, int $userId, ?int $excludeDeliveryId = null): bool
{
    ensureRiderWorkflowSchema($conn);
    if ($userId <= 0) {
        return false;
    }

    $params = [];
    $ownershipCondition = riderBuildOwnershipCondition($conn, 'd', $userId, $params);
    $sql = "SELECT COUNT(*) FROM delivery d WHERE {$ownershipCondition} AND " . riderWorkflowActiveDeliveryCondition('d');
    if ($excludeDeliveryId !== null && $excludeDeliveryId > 0) {
        $sql .= " AND d.Delivery_ID <> ?";
        $params[] = $excludeDeliveryId;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

function riderGetAssignmentCounts(PDO $conn, int $userId): array
{
    ensureRiderWorkflowSchema($conn);
    if ($userId <= 0) {
        return [
            'active_delivery_count' => 0,
            'scheduled_delivery_count' => 0,
            'in_progress_delivery_count' => 0,
            'returning_delivery_count' => 0,
        ];
    }

    $params = [];
    $ownershipCondition = riderBuildOwnershipCondition($conn, 'd', $userId, $params);
    $sql = "SELECT
                SUM(CASE WHEN d.delivery_status = 'Scheduled' THEN 1 ELSE 0 END) AS scheduled_delivery_count,
                SUM(CASE WHEN d.delivery_status IN ('In Transit', 'Delivered', 'Remitted') THEN 1 ELSE 0 END) AS in_progress_delivery_count,
                SUM(CASE WHEN d.delivery_status = 'Returning' THEN 1 ELSE 0 END) AS returning_delivery_count,
                SUM(CASE WHEN " . riderWorkflowActiveDeliveryCondition('d') . " THEN 1 ELSE 0 END) AS active_delivery_count
            FROM delivery d
            WHERE {$ownershipCondition}";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    return [
        'active_delivery_count' => (int)($row['active_delivery_count'] ?? 0),
        'scheduled_delivery_count' => (int)($row['scheduled_delivery_count'] ?? 0),
        'in_progress_delivery_count' => (int)($row['in_progress_delivery_count'] ?? 0),
        'returning_delivery_count' => (int)($row['returning_delivery_count'] ?? 0),
    ];
}

function syncRiderAvailabilityForUser(PDO $conn, int $userId): string
{
    ensureRiderWorkflowSchema($conn);
    if ($userId <= 0 || !riderWorkflowHasColumn($conn, 'user', 'rider_availability_status')) {
        return 'Available';
    }

    $currentStatus = riderGetAvailabilityStatus($conn, $userId);
    $hasActiveDeliveries = riderHasActiveDeliveries($conn, $userId);
    $nextStatus = $hasActiveDeliveries ? 'On Delivery' : ($currentStatus === 'Off Duty' ? 'Off Duty' : 'Available');

    if ($nextStatus !== $currentStatus) {
        $stmt = $conn->prepare("UPDATE user SET rider_availability_status = ? WHERE User_ID = ?");
        $stmt->execute([$nextStatus, $userId]);
    }

    return $nextStatus;
}

function syncAllRiderAvailability(PDO $conn, array $riderRoleIds): void
{
    ensureRiderWorkflowSchema($conn);
    if (empty($riderRoleIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($riderRoleIds), '?'));
    $stmt = $conn->prepare("SELECT User_ID FROM user WHERE Role_ID IN ($placeholders) AND is_active = 1");
    $stmt->execute($riderRoleIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        syncRiderAvailabilityForUser($conn, (int)($row['User_ID'] ?? 0));
    }
}

function riderGetUserIdByDeliveryId(PDO $conn, int $deliveryId): int
{
    if ($deliveryId <= 0) {
        return 0;
    }

    // 1. Try assigned_rider_id if exists
    if (riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id')) {
        $stmt = $conn->prepare("SELECT assigned_rider_id FROM delivery WHERE Delivery_ID = ? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $val = (int)$stmt->fetchColumn();
        if ($val > 0) {
            return $val;
        }
    }

    // 2. Try delivered_by_user_id if exists
    if (riderWorkflowHasColumn($conn, 'delivery', 'delivered_by_user_id')) {
        $stmt = $conn->prepare("SELECT delivered_by_user_id FROM delivery WHERE Delivery_ID = ? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $val = (int)$stmt->fetchColumn();
        if ($val > 0) {
            return $val;
        }
    }

    // 3. Fall back to delivered_by name matching
    if (riderWorkflowHasColumn($conn, 'delivery', 'delivered_by')) {
        $stmt = $conn->prepare("SELECT delivered_by FROM delivery WHERE Delivery_ID = ? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $name = trim((string)$stmt->fetchColumn());
        if ($name !== '') {
            $uStmt = $conn->prepare("SELECT User_ID FROM user WHERE (TRIM(full_name) = ? OR TRIM(user_name) = ?) AND is_active = 1 LIMIT 1");
            $uStmt->execute([$name, $name]);
            return (int)($uStmt->fetchColumn() ?: 0);
        }
    }

    return 0;
}

function riderCanBeAssignedToTarget(PDO $conn, int $riderId, ?int $deliveryId = null, ?int $orderId = null): bool
{
    ensureRiderWorkflowSchema($conn);
    if ($riderId <= 0) {
        return false;
    }

    $status = syncRiderAvailabilityForUser($conn, $riderId);
    if ($status !== 'Off Duty') {
        return true;
    }

    if ($deliveryId !== null && $deliveryId > 0) {
        $params = [$deliveryId];
        $ownership = riderBuildOwnershipCondition($conn, 'd', $riderId, $params);
        $stmt = $conn->prepare(
            "SELECT 1
             FROM delivery d
             WHERE d.Delivery_ID = ?
               AND {$ownership}
               AND " . riderWorkflowActiveDeliveryCondition('d') . "
             LIMIT 1"
        );
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    if ($orderId !== null && $orderId > 0) {
        $params = [$orderId];
        $ownership = riderBuildOwnershipCondition($conn, 'd', $riderId, $params);
        $stmt = $conn->prepare(
            "SELECT 1
             FROM delivery d
             WHERE d.Order_ID = ?
               AND {$ownership}
               AND " . riderWorkflowActiveDeliveryCondition('d') . "
             ORDER BY d.Delivery_ID DESC
             LIMIT 1"
        );
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}


function setManualRiderAvailability(PDO $conn, int $riderId, string $status): array
{
    ensureRiderWorkflowSchema($conn);
    $status = trim($status);
    if ($riderId <= 0) {
        return ['success' => false, 'message' => 'Invalid rider selected.'];
    }
    if (!in_array($status, ['Available', 'Off Duty'], true)) {
        return ['success' => false, 'message' => 'Invalid rider availability status.'];
    }
    if (riderHasActiveDeliveries($conn, $riderId)) {
        $busyStatus = syncRiderAvailabilityForUser($conn, $riderId);
        return ['success' => false, 'message' => "This rider is currently {$busyStatus} and cannot be changed manually."];
    }

    $stmt = $conn->prepare("UPDATE user SET rider_availability_status = ? WHERE User_ID = ?");
    $stmt->execute([$status, $riderId]);

    return ['success' => true, 'message' => "Rider marked as {$status}.", 'status' => $status];
}

function getRiderAvailabilityRows(PDO $conn, array $riderRoleIds): array
{
    ensureRiderWorkflowSchema($conn);
    syncAllRiderAvailability($conn, $riderRoleIds);

    if (empty($riderRoleIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($riderRoleIds), '?'));
    try {
        $stmt = $conn->prepare(
            "SELECT User_ID, COALESCE(full_name, user_name) AS name, COALESCE(user_name, '') AS user_name, rider_availability_status
             FROM user
             WHERE Role_ID IN ({$placeholders}) AND is_active = 1
             ORDER BY FIELD(rider_availability_status, 'Available', 'On Delivery', 'Off Duty'),
                      COALESCE(full_name, user_name), user_name"
        );
        $stmt->execute($riderRoleIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as &$row) {
        $counts = riderGetAssignmentCounts($conn, (int)($row['User_ID'] ?? 0));
        $row = array_merge($row, $counts);
    }
    unset($row);

    return $rows;
}

function getAvailableRidersForAssignment(PDO $conn, array $riderRoleIds): array
{
    $rows = getRiderAvailabilityRows($conn, $riderRoleIds);
    return array_values(array_filter($rows, static function (array $row): bool {
        return (string)($row['rider_availability_status'] ?? '') !== 'Off Duty';
    }));
}
