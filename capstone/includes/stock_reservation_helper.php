<?php
declare(strict_types=1);

function normalizePositiveIntArray(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $n = (int)$id;
        if ($n > 0) {
            $out[$n] = true;
        }
    }
    return array_keys($out);
}

function sqlPlaceholders(int $n): string
{
    if ($n <= 0) return '';
    return implode(',', array_fill(0, $n, '?'));
}

function getPhysicalStock(PDO $conn, int $productId): float
{
    if ($productId <= 0) {
        return 0.0;
    }

    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM products");
    if ($stmt) {
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (in_array('quantity', $columns, true)) {
        $q = $conn->prepare("SELECT COALESCE(quantity, 0) FROM products WHERE Product_ID = ?");
        $q->execute([$productId]);
        return (float)($q->fetchColumn() ?: 0);
    }

    $fallback = $conn->prepare("SELECT COALESCE(quantity, 0) FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC, Inventory_ID DESC LIMIT 1");
    $fallback->execute([$productId]);
    return (float)($fallback->fetchColumn() ?: 0);
}

/**
 * @return array<int,float> map of Product_ID => physical stock
 */
function getPhysicalStockByProductIds(PDO $conn, array $productIds): array
{
    $ids = normalizePositiveIntArray($productIds);
    if (empty($ids)) return [];

    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM products");
    if ($stmt) {
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $out = array_fill_keys($ids, 0.0);
    $in = sqlPlaceholders(count($ids));

    if (in_array('quantity', $columns, true)) {
        $q = $conn->prepare("SELECT Product_ID, COALESCE(quantity, 0) AS qty FROM products WHERE Product_ID IN ($in)");
        $q->execute($ids);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)($row['Product_ID'] ?? 0);
            if ($pid > 0) $out[$pid] = (float)($row['qty'] ?? 0);
        }
        return $out;
    }

    // Fallback: use latest stockin row quantity per product (updated_at DESC, Inventory_ID DESC).
    $fallback = $conn->prepare("
        SELECT si.Product_ID, COALESCE(si.quantity, 0) AS qty
        FROM stockin_inventory si
        WHERE si.Product_ID IN ($in)
          AND si.Inventory_ID = (
            SELECT si2.Inventory_ID
            FROM stockin_inventory si2
            WHERE si2.Product_ID = si.Product_ID
            ORDER BY si2.updated_at DESC, si2.Inventory_ID DESC
            LIMIT 1
          )
    ");
    $fallback->execute($ids);
    while ($row = $fallback->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($row['Product_ID'] ?? 0);
        if ($pid > 0) $out[$pid] = (float)($row['qty'] ?? 0);
    }
    return $out;
}

function getReservedStock(PDO $conn, int $productId, ?int $excludeOrderId = null): float
{
    if ($productId <= 0) {
        return 0.0;
    }

    $statusColumns = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    $orderStatusCol = 'order_status';
    if ($statusColumns && $statusColumns->rowCount() > 0) {
        $orderStatusCol = (string)($statusColumns->fetch(PDO::FETCH_ASSOC)['Field'] ?? 'order_status');
    }

    $sql = "
        SELECT COALESCE(SUM(od.ordered_qty), 0)
        FROM order_details od
        INNER JOIN orders o ON o.Order_ID = od.Order_ID
        LEFT JOIN (
            SELECT d.Order_ID, d.delivery_status
            FROM delivery d
            INNER JOIN (
                SELECT Order_ID, MAX(Delivery_ID) AS last_delivery_id
                FROM delivery
                GROUP BY Order_ID
            ) latest ON latest.last_delivery_id = d.Delivery_ID
        ) d ON d.Order_ID = o.Order_ID
        WHERE od.Product_ID = ?
          AND LOWER(COALESCE(o.{$orderStatusCol}, '')) IN (
            'pending',
            'requested',
            'confirmed',
            'scheduled',
            'scheduled for delivery',
            'preparing',
            'ready',
            'ready_for_pickup',
            'ready_to_pickup',
            'out for delivery',
            'out_for_delivery',
            'in transit',
            'in_transit'
          )
          AND (
            d.delivery_status IS NULL
            OR LOWER(d.delivery_status) NOT IN ('cancelled', 'completed', 'delivered', 'remitted')
          )
    ";

    $params = [$productId];
    if ($excludeOrderId !== null && $excludeOrderId > 0) {
        $sql .= " AND o.Order_ID <> ?";
        $params[] = $excludeOrderId;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return (float)($stmt->fetchColumn() ?: 0);
}

/**
 * Reservation logic matches getReservedStock() but in bulk.
 *
 * @return array<int,float> map of Product_ID => reserved stock
 */
function getReservedStockByProductIds(PDO $conn, array $productIds, ?int $excludeOrderId = null): array
{
    $ids = normalizePositiveIntArray($productIds);
    if (empty($ids)) return [];

    $statusColumns = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    $orderStatusCol = 'order_status';
    if ($statusColumns && $statusColumns->rowCount() > 0) {
        $orderStatusCol = (string)($statusColumns->fetch(PDO::FETCH_ASSOC)['Field'] ?? 'order_status');
    }

    $out = array_fill_keys($ids, 0.0);
    $in = sqlPlaceholders(count($ids));

    $sql = "
        SELECT
            od.Product_ID,
            COALESCE(SUM(od.ordered_qty), 0) AS reserved_qty
        FROM order_details od
        INNER JOIN orders o ON o.Order_ID = od.Order_ID
        LEFT JOIN (
            SELECT d.Order_ID, d.delivery_status
            FROM delivery d
            INNER JOIN (
                SELECT Order_ID, MAX(Delivery_ID) AS last_delivery_id
                FROM delivery
                GROUP BY Order_ID
            ) latest ON latest.last_delivery_id = d.Delivery_ID
        ) d ON d.Order_ID = o.Order_ID
        WHERE od.Product_ID IN ($in)
          AND LOWER(COALESCE(o.{$orderStatusCol}, '')) IN (
            'pending',
            'requested',
            'confirmed',
            'scheduled',
            'scheduled for delivery',
            'preparing',
            'ready',
            'ready_for_pickup',
            'ready_to_pickup',
            'out for delivery',
            'out_for_delivery',
            'in transit',
            'in_transit'
          )
          AND (
            d.delivery_status IS NULL
            OR LOWER(d.delivery_status) NOT IN ('cancelled', 'completed', 'delivered', 'remitted')
          )
    ";

    $params = $ids;
    if ($excludeOrderId !== null && $excludeOrderId > 0) {
        $sql .= " AND o.Order_ID <> ?";
        $params[] = $excludeOrderId;
    }

    $sql .= " GROUP BY od.Product_ID";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($row['Product_ID'] ?? 0);
        if ($pid > 0) $out[$pid] = (float)($row['reserved_qty'] ?? 0);
    }

    return $out;
}

function getAvailableStock(PDO $conn, int $productId, ?int $excludeOrderId = null): float
{
    $physical = getPhysicalStock($conn, $productId);
    $reserved = getReservedStock($conn, $productId, $excludeOrderId);
    return max(0.0, $physical - $reserved);
}

