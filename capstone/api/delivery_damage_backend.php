<?php
/**
 * Delivery damage reports API — rider submit, staff approve/reject.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/adjustment_reason_helper.php';
require_once __DIR__ . '/../realtime/publish_event.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function ddrTableExists(PDO $conn): bool {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $t = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
        $ok = $t && $t->rowCount() > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function getDamageReviewRoleIds(PDO $conn): array {
    $staff = getInventoryStaffRoleIds($conn);
    $mgmt = [1, 2, 4];
    return array_values(array_unique(array_merge($staff, $mgmt)));
}

function userHasDamageReviewRole(PDO $conn, int $roleId): bool {
    return in_array($roleId, getDamageReviewRoleIds($conn), true);
}

function riderOwnsDelivery(PDO $conn, int $riderUserId, int $deliveryId): bool {
    require_once __DIR__ . '/../includes/rider_availability_helper.php';
    $params = [$deliveryId];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $riderUserId, $params);
    $sql = "SELECT 1 FROM delivery d WHERE d.Delivery_ID = ? AND {$ownership} LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function getAlreadyReportedQty(PDO $conn, int $orderDetailId): float {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(r.damaged_qty), 0) 
         FROM delivery_damage_report r
         LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
         WHERE r.Order_detail_ID = ? AND COALESCE(rev.status, 'pending_review') IN ('pending_review', 'approved')"
    );
    $stmt->execute([$orderDetailId]);
    return (float) $stmt->fetchColumn();
}

function getAlreadyReportedQtyForDelivery(PDO $conn, int $deliveryId, int $orderDetailId): float {
    if ($deliveryId <= 0 || $orderDetailId <= 0) {
        return 0.0;
    }
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(r.damaged_qty), 0)
         FROM delivery_damage_report r
         LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
         WHERE r.Delivery_ID = ?
           AND r.Order_detail_ID = ?
           AND COALESCE(rev.status, 'pending_review') IN ('pending_review', 'approved')"
    );
    $stmt->execute([$deliveryId, $orderDetailId]);
    return (float) $stmt->fetchColumn();
}

function wholeQty($value): int {
    return (int) round((float) $value);
}

require_once __DIR__ . '/../includes/damage_type_helper.php';

function getDefaultDamageType(): string {
    $options = getDamageTypeOptions();
    return $options[0] ?? 'Melted';
}

function resolveApprovedDamageAdjustmentReason(PDO $conn, ?string $preferredDamageType = null): ?string
{
    $validReasons = getAdjustmentReasonOptions($conn);
    if (empty($validReasons)) {
        return null;
    }

    if ($preferredDamageType !== null) {
        $normalized = normalizeAdjustmentReasonValue($conn, $preferredDamageType);
        if (in_array($normalized, $validReasons, true)) {
            return $normalized;
        }
    }

    $damageTypeOptions = getDamageTypeOptions();
    foreach ($damageTypeOptions as $damageType) {
        $normalized = normalizeAdjustmentReasonValue($conn, $damageType);
        if (in_array($normalized, $validReasons, true)) {
            return $normalized;
        }
    }

    foreach ($validReasons as $reason) {
        if (stripos($reason, 'damage') !== false || stripos($reason, 'melt') !== false || stripos($reason, 'spill') !== false || stripos($reason, 'contamin') !== false || stripos($reason, 'torn') !== false) {
            return $reason;
        }
    }

    $otherReason = normalizeAdjustmentReasonValue($conn, 'Other (with remarks)');
    if (in_array($otherReason, $validReasons, true)) {
        return $otherReason;
    }

    return $validReasons[0] ?? null;
}

function saveDamagePhotoUpload(int $reportId): ?string {
    if (empty($_FILES['photo']) || empty($_FILES['photo']['name'][0])) {
        return null;
    }
    $dir = __DIR__ . '/../uploads/damage_reports';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create upload directory.');
        }
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $paths = [];
    $total = count($_FILES['photo']['name']);
    for ($i = 0; $i < $total; $i++) {
        if (empty($_FILES['photo']['tmp_name'][$i]) || !is_uploaded_file($_FILES['photo']['tmp_name'][$i])) {
            continue;
        }
        $f = [];
        foreach (['name', 'tmp_name', 'size', 'error'] as $k) {
            $f[$k] = $_FILES['photo'][$k][$i];
        }
        if (!empty($f['error']) && $f['error'] !== UPLOAD_ERR_OK) {
            continue;
        }
        if (($f['size'] ?? 0) > 4 * 1024 * 1024) {
            throw new RuntimeException('Each photo must be 4MB or smaller.');
        }
        $mime = null;
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
            }
        }
        if (!$mime || !isset($allowed[$mime])) {
            throw new RuntimeException('Photo must be JPEG, PNG, or WebP.');
        }
        $ext = $allowed[$mime];
        $name = 'ddr_' . $reportId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new RuntimeException('Failed to save photo.');
        }
        $paths[] = 'uploads/damage_reports/' . $name;
    }
    return empty($paths) ? null : implode(',', $paths);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!ddrTableExists($conn)) {
    jsonOut(['success' => false, 'error' => 'Delivery damage reports are not installed. Run database migration.'], 503);
}

$riderIds = getRiderRoleIds($conn);
$reviewRoleIds = getDamageReviewRoleIds($conn);
$uid = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['user_role'] ?? 0);

// ——— GET ———
if ($method === 'GET') {
    if ($action === 'order_lines') {
        if (empty($riderIds) || !in_array($roleId, $riderIds, true)) {
            jsonOut(['success' => false, 'error' => 'Forbidden'], 403);
        }
        $deliveryId = (int)($_GET['delivery_id'] ?? 0);
        if ($deliveryId <= 0 || !riderOwnsDelivery($conn, $uid, $deliveryId)) {
            jsonOut(['success' => false, 'error' => 'Invalid delivery.'], 400);
        }
        $st = $conn->prepare(
            "SELECT d.Order_ID FROM delivery d WHERE d.Delivery_ID = ? LIMIT 1"
        );
        $st->execute([$deliveryId]);
        $orderId = (int) ($st->fetchColumn() ?: 0);
        if ($orderId <= 0) {
            jsonOut(['success' => false, 'error' => 'Order not found for delivery.'], 400);
        }
        $items = $conn->prepare(
            "SELECT od.Order_detail_ID, od.Product_ID, od.ordered_qty, p.product_name,
                    COALESCE(u.unit_name, '') AS unit_name
             FROM order_details od
             INNER JOIN products p ON p.Product_ID = od.Product_ID
             LEFT JOIN units u ON p.unit_id = u.unit_id
             WHERE od.Order_ID = ?
             ORDER BY od.Order_detail_ID"
        );
        $items->execute([$orderId]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['already_reported'] = wholeQty(getAlreadyReportedQtyForDelivery($conn, $deliveryId, (int)$r['Order_detail_ID']));
            $r['ordered_qty'] = wholeQty($r['ordered_qty'] ?? 0);
            $r['remaining_qty'] = max(0, $r['ordered_qty'] - $r['already_reported']);
        }
        unset($r);
        jsonOut(['success' => true, 'order_id' => $orderId, 'items' => $rows]);
    }

    if ($action === 'my_reports') {
        if (empty($riderIds) || !in_array($roleId, $riderIds, true)) {
            jsonOut(['success' => false, 'error' => 'Forbidden'], 403);
        }
        $stmt = $conn->prepare(
            "SELECT r.report_id, r.Delivery_ID, od.Order_ID, r.damaged_qty, r.reason, 
                    COALESCE(rev.status, 'pending_review') AS status,
                    r.submitted_at, rev.reviewed_at, rev.staff_notes,
                    p.product_name, o.Order_ID AS ord_display
             FROM delivery_damage_report r
             LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
             INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID
             INNER JOIN products p ON p.Product_ID = od.Product_ID
             INNER JOIN orders o ON o.Order_ID = od.Order_ID
             WHERE r.submitted_by = ?
             ORDER BY r.submitted_at DESC
             LIMIT 100"
        );
        $stmt->execute([$uid]);
        jsonOut(['success' => true, 'reports' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'list_pending') {
        if (!userHasDamageReviewRole($conn, $roleId)) {
            jsonOut(['success' => false, 'error' => 'Forbidden'], 403);
        }
        $stmt = $conn->query(
            "SELECT r.report_id, r.Delivery_ID, od.Order_ID, r.Order_detail_ID, od.Product_ID,
                    r.damaged_qty, r.reason, r.photo_path, 
                    COALESCE(rev.status, 'pending_review') AS status, 
                    r.submitted_at, r.submitted_by,
                    p.product_name, od.ordered_qty,
                    u.full_name AS rider_name, u.user_name AS rider_username
             FROM delivery_damage_report r
             LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
             INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID
             INNER JOIN products p ON p.Product_ID = od.Product_ID
             INNER JOIN user u ON u.User_ID = r.submitted_by
             WHERE COALESCE(rev.status, 'pending_review') = 'pending_review'
             ORDER BY r.submitted_at ASC"
        );
        jsonOut(['success' => true, 'pending' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonOut(['success' => false, 'error' => 'Unknown action'], 400);
}

// ——— POST ———
if ($method !== 'POST') {
    jsonOut(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (!validateCsrfToken(false)) {
    jsonOut(['success' => false, 'error' => 'Invalid or expired security token.'], 403);
}

if ($action === 'submit') {
    if (empty($riderIds) || !in_array($roleId, $riderIds, true)) {
        jsonOut(['success' => false, 'error' => 'Forbidden'], 403);
    }
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $orderDetailId = (int)($_POST['order_detail_id'] ?? 0);
    $damagedQtyRaw = trim((string)($_POST['damaged_qty'] ?? ''));
    $damagedQty = filter_var($damagedQtyRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($deliveryId <= 0 || $orderDetailId <= 0 || $damagedQty === false || $reason === '') {
        jsonOut(['success' => false, 'error' => 'Delivery, order line, quantity, and reason are required.'], 400);
    }
    if (!riderOwnsDelivery($conn, $uid, $deliveryId)) {
        jsonOut(['success' => false, 'error' => 'You are not assigned to this delivery.'], 403);
    }

    $dstmt = $conn->prepare('SELECT Order_ID FROM delivery WHERE Delivery_ID = ? LIMIT 1');
    $dstmt->execute([$deliveryId]);
    $orderId = (int)($dstmt->fetchColumn() ?: 0);
    if ($orderId <= 0) {
        jsonOut(['success' => false, 'error' => 'Invalid delivery record.'], 400);
    }

    $od = $conn->prepare(
        'SELECT Order_detail_ID, Product_ID, ordered_qty FROM order_details WHERE Order_detail_ID = ? AND Order_ID = ? LIMIT 1'
    );
    $od->execute([$orderDetailId, $orderId]);
    $line = $od->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        jsonOut(['success' => false, 'error' => 'Order line does not belong to this delivery order.'], 400);
    }

    $orderedQty = wholeQty($line['ordered_qty'] ?? 0);
    $already = wholeQty(getAlreadyReportedQtyForDelivery($conn, $deliveryId, $orderDetailId));
    if ($damagedQty + $already > $orderedQty) {
        jsonOut([
            'success' => false,
            'error' => 'Damaged quantity cannot exceed remaining quantity for this line (' . ($orderedQty - $already) . ' remaining).',
        ], 400);
    }

    $productId = (int)$line['Product_ID'];

    $conn->beginTransaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO delivery_damage_report (
                Delivery_ID, Order_detail_ID, damaged_qty, reason, submitted_by, submitted_at
            ) VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $ins->execute([$deliveryId, $orderDetailId, $damagedQty, $reason, $uid]);
        $reportId = (int)$conn->lastInsertId();

        // Initialize review record
        $insRev = $conn->prepare("INSERT INTO damage_report_reviews (report_id, status) VALUES (?, 'pending_review')");
        $insRev->execute([$reportId]);

        $photoPath = null;
        if (!empty($_FILES['photo']) && !empty($_FILES['photo']['name'][0])) {
            $photoPath = saveDamagePhotoUpload($reportId);
            $up = $conn->prepare('UPDATE delivery_damage_report SET photo_path = ? WHERE report_id = ?');
            $up->execute([$photoPath, $reportId]);
        }

        $conn->commit();

        if (function_exists('publishRealtimeEvent')) {
            publishRealtimeEvent([
                'event' => 'delivery.damage_report',
                'data' => [
                    'report_id' => $reportId,
                    'order_id' => $orderId,
                    'delivery_id' => $deliveryId,
                    'message' => "New damage report #$reportId submitted for Order #$orderId."
                ]
            ]);
        }

        logActivity('DELIVERY', "Damage report #$reportId pending review: Order #$orderId, {$damagedQty} units", $reportId);
        jsonOut(['success' => true, 'report_id' => $reportId, 'photo_path' => $photoPath]);
    } catch (Throwable $e) {
        $conn->rollBack();
        jsonOut(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($action === 'reject') {
    if (!userHasDamageReviewRole($conn, $roleId)) {
        jsonOut(['success' => false, 'error' => 'Forbidden'], 403);
    }
    $reportId = (int)($_POST['report_id'] ?? 0);
    $notes = trim((string)($_POST['staff_notes'] ?? ''));
    if ($reportId <= 0) {
        jsonOut(['success' => false, 'error' => 'Report required.'], 400);
    }

    $conn->beginTransaction();
    try {
        $rep = $conn->prepare('SELECT r.*, rev.status, od.Order_ID 
                               FROM delivery_damage_report r 
                               LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                               INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID 
                               WHERE r.report_id = ? FOR UPDATE');
        $rep->execute([$reportId]);
        $row = $rep->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? 'pending_review') !== 'pending_review') {
            throw new RuntimeException('Report not found or not pending.');
        }
        if ((int)$row['submitted_by'] === $uid) {
            throw new RuntimeException('You cannot reject your own damage report.');
        }
        $conn->prepare(
            "INSERT INTO damage_report_reviews (report_id, status, reviewed_by, reviewed_at, staff_notes)
             VALUES (?, 'rejected', ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE status = 'rejected', reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at), staff_notes = VALUES(staff_notes)"
        )->execute([$reportId, $uid, $notes !== '' ? $notes : null]);
        $conn->commit();
        logActivity('DELIVERY', "Damage report #$reportId rejected for Order #{$row['Order_ID']}", $reportId);
        publishRealtimeEvent([
            'event' => 'damage_report.reviewed',
            'data' => [
                'report_id' => $reportId,
                'status' => 'rejected',
                'order_id' => (int)$row['Order_ID'],
                'delivery_id' => (int)$row['Delivery_ID'],
                'rider_user_id' => (int)$row['submitted_by'],
                'staff_notes' => $notes
            ]
        ]);
        jsonOut(['success' => true]);
    } catch (Throwable $e) {
        $conn->rollBack();
        jsonOut(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($action === 'approve') {
    if (!userHasDamageReviewRole($conn, $roleId)) {
        jsonOut(['success' => false, 'error' => 'Forbidden'], 403);
    }
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        jsonOut(['success' => false, 'error' => 'Report required.'], 400);
    }

    $approverStmt = $conn->prepare('SELECT full_name, user_name FROM user WHERE User_ID = ? LIMIT 1');
    $approverStmt->execute([$uid]);
    $approverRow = $approverStmt->fetch(PDO::FETCH_ASSOC);
    $approverName = trim((string)($approverRow['full_name'] ?? $approverRow['user_name'] ?? 'Staff'));

    $conn->beginTransaction();
    try {
        $rep = $conn->prepare('SELECT r.*, rev.status, od.Order_ID, od.Product_ID 
                               FROM delivery_damage_report r 
                               LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                               INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID 
                               WHERE r.report_id = ? FOR UPDATE');
        $rep->execute([$reportId]);
        $row = $rep->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? 'pending_review') !== 'pending_review') {
            throw new RuntimeException('Report not found or not pending.');
        }
        if ((int)$row['submitted_by'] === $uid) {
            throw new RuntimeException('You cannot approve your own damage report.');
        }

        $productId = (int)$row['Product_ID'];
        $quantity = wholeQty($row['damaged_qty'] ?? 0);
        $orderId = (int)$row['Order_ID'];
        $reportedReason = trim((string)($row['reason'] ?? ''));
        $riderReporter = (int)$row['submitted_by'];

        $stmt = $conn->prepare(
            'SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC, Inventory_ID DESC LIMIT 1'
        );
        $stmt->execute([$productId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv || (float)$inv['quantity'] < $quantity) {
            throw new RuntimeException('Insufficient stock to approve this damage.');
        }

        $old_qty = (float)$inv['quantity'];
        $new_qty = $old_qty - $quantity;
        $inventory_id = (int)$inv['Inventory_ID'];

        $damageType = getDefaultDamageType();
        $adjustmentReason = resolveApprovedDamageAdjustmentReason($conn, $damageType);
        if ($adjustmentReason === null) {
            throw new RuntimeException('No valid adjustment reason is configured for delivery damage approval.');
        }
        $adjNotes = "Delivery damage approved — Order #$orderId — Approved by: $approverName";
        if ($reportedReason !== '') {
            $adjNotes .= " — Rider reason: $reportedReason";
        }

        $stmt = $conn->prepare('INSERT INTO manual_adjustment (adjustment_date, notes, created_by) VALUES (CURDATE(), ?, ?)');
        $stmt->execute([$adjNotes, $uid]);
        $adjustment_id = (int)$conn->lastInsertId();

        $stmt = $conn->prepare(
            'INSERT INTO adjustment_details (Product_ID, Adjustment_ID, old_quantity, new_quantity, adjustment_type, reason) VALUES (?, ?, ?, ?, \'decrease\', ?)'
        );
        $stmt->execute([$productId, $adjustment_id, $old_qty, $new_qty, $adjustmentReason]);

        $stmt = $conn->prepare(
            'INSERT INTO damage_goods (Inventory_ID, Adjustment_ID, quantity, reported_by, reason, damage_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$inventory_id, $adjustment_id, $quantity, $riderReporter, $reportedReason !== '' ? $reportedReason : $adjustmentReason, $damageType]);
        $damageGoodsId = (int)$conn->lastInsertId();

        $stmt = $conn->prepare('UPDATE stockin_inventory SET quantity = ?, updated_at = NOW() WHERE Inventory_ID = ?');
        $stmt->execute([$new_qty, $inventory_id]);

        $ledgerNotes = "Damage loss — Order #$orderId — Delivery ref — Approved by: $approverName — $adjustmentReason";
        if ($reportedReason !== '') {
            $ledgerNotes .= " — Rider reason: $reportedReason";
        }
        $ledger_stmt = $conn->prepare(
            'INSERT INTO inventory_ledger (product_id, transaction_type, transaction_id, quantity_change, balance_after, handled_by, notes) VALUES (?, \'DAMAGE LOSS\', ?, ?, ?, ?, ?)'
        );
        $ledger_stmt->execute([
            $productId,
            $adjustment_id,
            -$quantity,
            $new_qty,
            $uid,
            $ledgerNotes,
        ]);

        $conn->prepare(
            "INSERT INTO damage_report_reviews (report_id, status, reviewed_by, reviewed_at, Adjustment_ID, Damage_ID)
             VALUES (?, 'approved', ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE status = 'approved', reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at), 
                                     Adjustment_ID = VALUES(Adjustment_ID), Damage_ID = VALUES(Damage_ID)"
        )->execute([$reportId, $uid, $adjustment_id, $damageGoodsId]);

        $conn->commit();
        logActivity('INVENTORY', "Delivery damage report #$reportId approved — Order #$orderId — {$quantity} units", $adjustment_id);
        publishRealtimeEvent([
            'event' => 'damage_report.reviewed',
            'data' => [
                'report_id' => $reportId,
                'status' => 'approved',
                'order_id' => (int)$row['Order_ID'],
                'delivery_id' => (int)$row['Delivery_ID'],
                'rider_user_id' => (int)$row['submitted_by'],
                'adjustment_id' => (int)$adjustment_id
            ]
        ]);
        jsonOut(['success' => true, 'Damage_ID' => $damageGoodsId, 'adjustment_id' => $adjustment_id]);
    } catch (Throwable $e) {
        $conn->rollBack();
        jsonOut(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

jsonOut(['success' => false, 'error' => 'Unknown action'], 400);
