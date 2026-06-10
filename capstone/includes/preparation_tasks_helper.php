<?php
declare(strict_types=1);

function prepTasksGetDefaultStatuses(): array
{
    return ['not_started', 'preparing', 'ready', 'short_stock'];
}

function prepTasksGetValidStatuses(PDO $conn): array
{
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM order_preparation_tasks WHERE Field = 'status'");
        if (!$stmt || $stmt->rowCount() === 0) {
            return prepTasksGetDefaultStatuses();
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $type = (string)($row['Type'] ?? '');
        if (preg_match("/^enum\('(.+?)'\)$/i", $type, $m)) {
            return explode("','", $m[1]);
        }
    } catch (Throwable $e) {
    }
    return prepTasksGetDefaultStatuses();
}

function prepTasksEnsureSchema(PDO $conn): void
{
    $allStatuses = prepTasksGetDefaultStatuses();
    $enumStr = "'" . implode("', '", $allStatuses) . "'";

    if (!prepTasksTableExists($conn, 'order_preparation_tasks')) {
        $conn->exec("
            CREATE TABLE order_preparation_tasks (
                Prep_ID INT AUTO_INCREMENT PRIMARY KEY,
                Order_ID INT NOT NULL,
                status ENUM({$enumStr}) NOT NULL DEFAULT 'not_started',
                started_by INT NULL,
                ready_by INT NULL,
                started_at DATETIME NULL,
                ready_at DATETIME NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_order_preparation (Order_ID),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        $current = prepTasksGetValidStatuses($conn);
        $missing = array_diff($allStatuses, $current);
        if (!empty($missing)) {
            $merged = array_unique(array_merge($current, $missing));
            $mergedEnum = "'" . implode("', '", $merged) . "'";
            $conn->exec("ALTER TABLE order_preparation_tasks MODIFY COLUMN status ENUM({$mergedEnum}) NOT NULL DEFAULT 'not_started'");
        }
    }
}

function prepTasksGetOrderStatusColumn(PDO $conn): string
{
    $stmt = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if ($stmt && $stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (string)$row['Field'];
    }

    return 'order_status';
}

function prepTasksTableExists(PDO $conn, string $tableName): bool
{
    $stmt = $conn->query("SHOW TABLES LIKE " . $conn->quote($tableName));
    return $stmt && $stmt->rowCount() > 0;
}

function prepTasksGetColumns(PDO $conn, string $tableName): array
{
    $stmt = $conn->query("SHOW COLUMNS FROM {$tableName}");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

function prepTasksFormatQty(float $qty): string
{
    return number_format($qty, 0);
}

function prepTasksStatusLabel(string $status): array
{
    $statusKey = strtolower(str_replace(' ', '_', trim($status)));
    if ($statusKey === 'ready_for_pickup' || $statusKey === 'ready_to_pickup') {
        $statusKey = 'ready';
    }

    $labelOverrides = [
        'not_started' => 'Not Started',
        'preparing' => 'Preparing',
        'ready' => 'Ready for Pickup',
        'short_stock' => 'Short Stock',
    ];
    $icons = [
        'not_started' => 'fa-hourglass-half',
        'preparing' => 'fa-spinner',
        'ready' => 'fa-circle-check',
        'short_stock' => 'fa-triangle-exclamation',
    ];

    $label = $labelOverrides[$statusKey] ?? ucwords(str_replace('_', ' ', $statusKey));
    $icon = $icons[$statusKey] ?? 'fa-circle';
    $class = 'status-' . str_replace('_', '-', $statusKey);

    return ['label' => $label, 'icon' => $icon, 'class' => $class];
}

function prepTasksFetchItems(PDO $conn, array $orderIds): array
{
    if (empty($orderIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $items = [];

    $productsColumns = prepTasksGetColumns($conn, 'products');
    $hasUnitsTable = prepTasksTableExists($conn, 'units');
    $unitSelect = "'pcs' AS unit_name";
    $unitJoin = '';
    if ($hasUnitsTable && in_array('unit_id', $productsColumns, true)) {
        $unitSelect = "COALESCE(u.unit_name, 'pcs') AS unit_name";
        $unitJoin = "LEFT JOIN units u ON p.unit_id = u.unit_id";
    } elseif (in_array('unit', $productsColumns, true)) {
        $unitSelect = "COALESCE(p.unit, 'pcs') AS unit_name";
    }

    if (prepTasksTableExists($conn, 'order_details')) {
        $sql = "
            SELECT od.Order_ID, COALESCE(p.product_name, 'Unknown Product') AS product_name,
                   od.ordered_qty AS quantity, {$unitSelect}
            FROM order_details od
            LEFT JOIN products p ON od.Product_ID = p.Product_ID
            {$unitJoin}
            WHERE od.Order_ID IN ({$placeholders})
            ORDER BY od.Order_ID, p.product_name
        ";
    } elseif (prepTasksTableExists($conn, 'order_items') && !prepTasksTableExists($conn, 'order_details')) {
        $sql = "
            SELECT oi.Order_ID, COALESCE(p.product_name, 'Unknown Product') AS product_name,
                   oi.quantity, {$unitSelect}
            FROM order_items oi
            LEFT JOIN products p ON oi.Product_ID = p.Product_ID
            {$unitJoin}
            WHERE oi.Order_ID IN ({$placeholders})
            ORDER BY oi.Order_ID, p.product_name
        ";
    } else {
        return [];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute(array_values($orderIds));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $orderId = (int)$row['Order_ID'];
        $items[$orderId][] = [
            'product_name' => (string)$row['product_name'],
            'quantity' => (float)$row['quantity'],
            'unit' => (string)($row['unit_name'] ?? 'pcs'),
        ];
    }

    return $items;
}

function prepTasksFetchQueue(PDO $conn): array
{
    prepTasksEnsureSchema($conn);

    $orderStatusCol = prepTasksGetOrderStatusColumn($conn);
    $orderColumns = prepTasksGetColumns($conn, 'orders');
    $hasDeliveryDate = in_array('delivery_date', $orderColumns, true);
    $hasDeliveryTime = in_array('delivery_time', $orderColumns, true);
    $hasOrderTime = in_array('order_time', $orderColumns, true);
    $hasDeliveryAddress = in_array('delivery_address', $orderColumns, true);

    $deliveryDateSelect = $hasDeliveryDate ? 'o.delivery_date' : 'NULL';
    $deliveryTimeSelect = $hasDeliveryTime ? 'o.delivery_time' : ($hasOrderTime ? 'o.order_time' : 'NULL');
    $deliveryAddressSelect = $hasDeliveryAddress ? 'o.delivery_address' : "''";

    $deliveryColumns = prepTasksGetColumns($conn, 'delivery');
    $riderJoinSql = '';
    $riderNameSql = "COALESCE(NULLIF(d.delivered_by, ''), '')";
    if (in_array('assigned_rider_id', $deliveryColumns, true)) {
        $riderJoinSql = 'LEFT JOIN user rider ON rider.User_ID = d.assigned_rider_id';
        $riderNameSql = "COALESCE(NULLIF(d.delivered_by, ''), NULLIF(rider.full_name, ''), rider.user_name, '')";
    } elseif (in_array('delivered_by_user_id', $deliveryColumns, true)) {
        $riderJoinSql = 'LEFT JOIN user rider ON rider.User_ID = d.delivered_by_user_id';
        $riderNameSql = "COALESCE(NULLIF(d.delivered_by, ''), NULLIF(rider.full_name, ''), rider.user_name, '')";
    }

    $sql = "
        SELECT
            o.Order_ID,
            o.{$orderStatusCol} AS order_status,
            o.order_date,
            {$deliveryDateSelect} AS order_delivery_date,
            {$deliveryTimeSelect} AS delivery_time,
            {$deliveryAddressSelect} AS order_delivery_address,
            COALESCE(c.customer_name, 'Walk-in / Unnamed Customer') AS customer_name,
            c.phone_number,
            c.address AS customer_address,
            d.Delivery_ID,
            d.schedule_date,
            d.delivery_status,
            {$riderNameSql} AS rider_name,
            COALESCE(t.status, 'not_started') AS prep_status,
            t.started_at,
            t.ready_at
        FROM orders o
        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
        LEFT JOIN delivery d ON d.Delivery_ID = (
            SELECT d2.Delivery_ID
            FROM delivery d2
            WHERE d2.Order_ID = o.Order_ID
            ORDER BY d2.Delivery_ID DESC
            LIMIT 1
        )
        LEFT JOIN order_preparation_tasks t ON t.Order_ID = o.Order_ID
        {$riderJoinSql}
        WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
          AND (d.delivery_status IS NULL OR d.delivery_status != 'Cancelled')
        ORDER BY COALESCE(d.schedule_date, {$deliveryDateSelect}, o.order_date), COALESCE({$deliveryTimeSelect}, '23:59:59'), o.Order_ID
    ";

    try {
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('prepTasksFetchQueue failed: ' . $e->getMessage());
        $rows = [];
    }
    $orderIds = array_map(static fn(array $row): int => (int)$row['Order_ID'], $rows);
    $itemsByOrder = prepTasksFetchItems($conn, $orderIds);

    $today = new DateTimeImmutable(date('Y-m-d'));
    $tomorrow = $today->modify('+1 day');
    $futureStart = $today->modify('+2 days');
    $queue = [
        'urgent' => [],
        'tomorrow' => [],
        'upcoming' => [],
    ];

    foreach ($rows as $row) {
        $dateValue = $row['schedule_date'] ?: ($row['order_delivery_date'] ?: $row['order_date']);
        $deliveryDate = new DateTimeImmutable((string)$dateValue);
        $orderId = (int)$row['Order_ID'];
        $row['delivery_date_effective'] = $deliveryDate->format('Y-m-d');
        $row['items'] = $itemsByOrder[$orderId] ?? [];

        if ($deliveryDate <= $today) {
            $queue['urgent'][] = $row;
        } elseif ($deliveryDate->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
            $queue['tomorrow'][] = $row;
        } elseif ($deliveryDate >= $futureStart) {
            $queue['upcoming'][] = $row;
        }
    }

    return $queue;
}

function prepTasksUpdateStatus(PDO $conn, int $orderId, string $status, int $userId): void
{
    prepTasksEnsureSchema($conn);

    $allowed = prepTasksGetValidStatuses($conn);
    if ($orderId <= 0 || !in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Invalid preparation task update.');
    }

    $fields = 'Order_ID, status';
    $values = '?, ?';
    $params = [$orderId, $status];
    $updates = 'status = VALUES(status), updated_at = NOW()';

    if ($status === 'preparing') {
        $fields .= ', started_by, started_at';
        $values .= ', ?, NOW()';
        $params[] = $userId;
        $updates .= ', started_by = VALUES(started_by), started_at = COALESCE(started_at, VALUES(started_at))';
    } elseif ($status === 'ready') {
        $fields .= ', ready_by, ready_at';
        $values .= ', ?, NOW()';
        $params[] = $userId;
        $updates .= ', ready_by = VALUES(ready_by), ready_at = VALUES(ready_at)';
    }

    $stmt = $conn->prepare("
        INSERT INTO order_preparation_tasks ({$fields})
        VALUES ({$values})
        ON DUPLICATE KEY UPDATE {$updates}
    ");
    $stmt->execute($params);

    if ($status === 'ready') {
        try {
            $del_stmt = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
            $del_stmt->execute([$orderId]);
            $del = $del_stmt->fetch(PDO::FETCH_ASSOC);
            if ($del) {
                $del_id = (int)$del['Delivery_ID'];
                require_once __DIR__ . '/rider_availability_helper.php';
                $rider_id = riderGetUserIdByDeliveryId($conn, $del_id);
                
                require_once __DIR__ . '/../realtime/publish_event.php';
                if (function_exists('publishRealtimeEvent')) {
                    publishRealtimeEvent([
                        'event' => 'delivery.ready',
                        'data' => [
                            'delivery_id' => $del_id,
                            'order_id' => $orderId,
                            'prep_status' => 'ready',
                            'rider_user_id' => $rider_id
                        ]
                    ]);
                }

                // Log notification in activity_logs for the rider
                if ($rider_id > 0) {
                    try {
                        $riderNotif = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID) VALUES (?, 'NOTIFICATION', ?, ?)");
                        $riderNotif->execute([$rider_id, "Order #{$orderId} is now ready for delivery pickup.", $del_id]);
                    } catch (Throwable $e2) {
                        error_log("Failed to notify rider: " . $e2->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Failed to publish delivery.ready event: " . $e->getMessage());
        }
    }

    // Publish realtime event for prep task status update (concurrency prevention)
    try {
        require_once __DIR__ . '/../realtime/publish_event.php';
        if (function_exists('publishRealtimeEvent')) {
            $user_name = $_SESSION['user_name'] ?? 'Staff Member';
            publishRealtimeEvent([
                'event' => 'prep_task.status_updated',
                'data' => [
                    'order_id' => $orderId,
                    'status' => $status,
                    'user_id' => $userId,
                    'user_name' => $user_name
                ]
            ]);
        }
    } catch (Throwable $e) {
        error_log("Failed to publish prep_task.status_updated event: " . $e->getMessage());
    }
}
