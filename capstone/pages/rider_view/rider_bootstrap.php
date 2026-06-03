<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/delivery_cancellation_helper.php';
require_once __DIR__ . '/../../includes/rider_availability_helper.php';
require_once __DIR__ . '/../../includes/preparation_tasks_helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/** Live maps (queue mini-map + full-screen tracker). Set true to turn maps back on. */
$rider_maps_enabled = false;

$rider_role_ids = getRiderRoleIds($conn);
requireRole(empty($rider_role_ids) ? [0] : $rider_role_ids);  // from DB, no hardcoding

$full_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Rider';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$can_rider_dashboard = isModuleAllowedForUser($conn, $user_id, 'rider_dashboard_tab', true);
$can_rider_queue = isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true);
$can_rider_history = isModuleAllowedForUser($conn, $user_id, 'rider_delivery_history', true);

$has_delivery_damage_reports = false;
$damage_table_name = '';
try {
    $td = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
    if ($td && $td->rowCount() > 0) {
        $has_delivery_damage_reports = true;
        $damage_table_name = 'delivery_damage_report';
    } else {
        $tdLegacy = $conn->query("SHOW TABLES LIKE 'delivery_damage_reports'");
        if ($tdLegacy && $tdLegacy->rowCount() > 0) {
            $has_delivery_damage_reports = true;
            $damage_table_name = 'delivery_damage_reports';
        }
    }
} catch (Exception $e) {
    $has_delivery_damage_reports = false;
    $damage_table_name = '';
}
$my_delivery_damage_reports = [];
if ($has_delivery_damage_reports && $damage_table_name !== '') {
    try {
        $ddrCols = [];
        $ddrColStmt = $conn->query("SHOW COLUMNS FROM {$damage_table_name}");
        if ($ddrColStmt) {
            $ddrCols = array_column($ddrColStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }

        $submittedByCol = in_array('submitted_by', $ddrCols, true) ? 'submitted_by' : (in_array('reported_by', $ddrCols, true) ? 'reported_by' : 'submitted_by');
        $orderDetailCol = in_array('Order_detail_ID', $ddrCols, true) ? 'Order_detail_ID' : (in_array('order_detail_id', $ddrCols, true) ? 'order_detail_id' : 'Order_detail_ID');
        $deliveryCol = in_array('Delivery_ID', $ddrCols, true) ? 'Delivery_ID' : (in_array('delivery_id', $ddrCols, true) ? 'delivery_id' : 'Delivery_ID');
        $reportIdCol = in_array('report_id', $ddrCols, true) ? 'report_id' : (in_array('Report_ID', $ddrCols, true) ? 'Report_ID' : 'report_id');
        $reasonCol = in_array('reason', $ddrCols, true) ? 'reason' : (in_array('damage_reason', $ddrCols, true) ? 'damage_reason' : 'reason');
        $statusCol = in_array('status', $ddrCols, true) ? 'status' : (in_array('review_status', $ddrCols, true) ? 'review_status' : 'status');
        $qtyCol = in_array('damaged_qty', $ddrCols, true) ? 'damaged_qty' : (in_array('damage_qty', $ddrCols, true) ? 'damage_qty' : 'damaged_qty');
        $submittedAtCol = in_array('submitted_at', $ddrCols, true) ? 'submitted_at' : (in_array('created_at', $ddrCols, true) ? 'created_at' : 'submitted_at');
        $photoCol = in_array('photo_path', $ddrCols, true) ? 'photo_path' : (in_array('photo', $ddrCols, true) ? 'photo' : 'photo_path');
        $reviewedAtSelect = in_array('reviewed_at', $ddrCols, true) ? "r.reviewed_at" : "NULL";
        $staffNotesSelect = in_array('staff_notes', $ddrCols, true) ? "r.staff_notes" : "NULL";

        $mr = $conn->prepare(
            "SELECT r.{$reportIdCol} AS report_id,
                    r.{$deliveryCol} AS Delivery_ID,
                    d.Order_ID AS Order_ID,
                    r.{$qtyCol} AS damaged_qty,
                    r.{$reasonCol} AS reason,
                    COALESCE(rev.status, 'pending_review') AS status,
                    r.{$submittedAtCol} AS submitted_at,
                    rev.reviewed_at AS reviewed_at,
                    rev.staff_notes AS staff_notes,
                    r.{$photoCol} AS photo_path,
                    p.product_name,
                    COALESCE(u.unit_name, '') AS unit_name
             FROM {$damage_table_name} r
             LEFT JOIN damage_report_reviews rev ON rev.report_id = r.{$reportIdCol}
             LEFT JOIN order_details od ON od.Order_detail_ID = r.{$orderDetailCol}
             LEFT JOIN delivery d ON d.Delivery_ID = r.{$deliveryCol}
             LEFT JOIN products p ON p.Product_ID = od.Product_ID
             LEFT JOIN units u ON u.unit_id = p.unit_id
             WHERE r.{$submittedByCol} = ?
             ORDER BY r.{$submittedAtCol} DESC
             LIMIT 25"
        );
        $mr->execute([$user_id]);
        $my_delivery_damage_reports = $mr->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $my_delivery_damage_reports = [];
    }
}

function wholeNumber($value): string {
    return number_format((float)$value, 0);
}

$damage_report_total = count($my_delivery_damage_reports);
$damage_report_pending = count(array_filter($my_delivery_damage_reports, static function ($report) {
    return ($report['status'] ?? '') === 'pending_review';
}));
$damage_report_approved = count(array_filter($my_delivery_damage_reports, static function ($report) {
    return ($report['status'] ?? '') === 'approved';
}));
$damage_report_rejected = count(array_filter($my_delivery_damage_reports, static function ($report) {
    return ($report['status'] ?? '') === 'rejected';
}));
$delivery_cancellation_reasons = getDeliveryCancellationReasonOptions($conn);
ensureRiderWorkflowSchema($conn);

// Rider ownership works via riderBuildOwnershipCondition — no hard dependency on assigned_rider_id column.
$has_assigned = true; // always true; riderBuildOwnershipCondition handles column detection internally.

$delivery_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$has_destination_lat = in_array('destination_lat', $delivery_cols, true);
$has_destination_lng = in_array('destination_lng', $delivery_cols, true);

// Check if orders has delivery_address and total_amount (schema may vary)
$orders_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$addr_sel = in_array('delivery_address', $orders_cols) ? "COALESCE(d.delivery_address, o.delivery_address)" : "d.delivery_address";
$amt_sel = in_array('total_amount', $orders_cols) ? 'o.total_amount' : '0 as total_amount';

// Build a unit label expression that matches your `products` schema.
$products_cols = array_column($conn->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$has_unit_col = in_array('unit', $products_cols);
$has_unit_id_col = in_array('unit_id', $products_cols);

$unit_join_sql = '';
$unit_col_for_label_expr = "'Units'";
try {
    if ($has_unit_id_col) {
        // If products uses unit_id, try to show a human-readable unit_name from `units` table.
        $units_table_exists = false;
        $t = $conn->query("SHOW TABLES LIKE 'units'");
        $units_table_exists = ($t && $t->rowCount() > 0);
        if ($units_table_exists) {
            $units_cols = array_column($conn->query("SHOW COLUMNS FROM units")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (in_array('unit_name', $units_cols) && in_array('unit_id', $units_cols)) {
                $unit_join_sql = 'LEFT JOIN units u ON p.unit_id = u.unit_id';
                $unit_col_for_label_expr = 'u.unit_name';
            } else {
                    $unit_col_for_label_expr = "'Units'";
            }
        } else {
                $unit_col_for_label_expr = "'Units'";
        }
    } elseif ($has_unit_col) {
        $unit_col_for_label_expr = 'p.unit';
    }
} catch (Exception $e) {
    // Fallback to generic units label
    $unit_col_for_label_expr = "'Units'";
    $unit_join_sql = '';
}

function geocodeDestinationServer($address) {
    $address = trim((string)$address);
    if ($address === '') return null;

    $queries = [];
    if (stripos($address, 'cagayan de oro') === false) {
        $queries[] = $address . ', Cagayan de Oro, Misamis Oriental, Philippines';
    }
    $queries[] = $address;
    if (stripos($address, 'philippines') === false) {
        $queries[] = $address . ', Philippines';
    }

    foreach ($queries as $q) {
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: VIP-Ice-Plant-Delivery-Tracker/1.0\r\nAccept: application/json\r\n"
            ]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) continue;

        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json[0])) continue;

        $lat = isset($json[0]['lat']) ? (float)$json[0]['lat'] : null;
        $lng = isset($json[0]['lon']) ? (float)$json[0]['lon'] : null;
        if ($lat === null || $lng === null) continue;
        $localHint = preg_match('/tablon|bugo|cagayan de oro|jasaan/i', $address) === 1;
        if ($localHint && !($lat >= 7.8 && $lat <= 9.2 && $lng >= 123.8 && $lng <= 125.6)) continue;

        return ['lat' => $lat, 'lng' => $lng];
    }

    // Fallback provider: Photon (komoot)
    foreach ($queries as $q) {
        $url = 'https://photon.komoot.io/api/?limit=1&q=' . rawurlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: VIP-Ice-Plant-Delivery-Tracker/1.0\r\nAccept: application/json\r\n"
            ]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) continue;
        $json = json_decode($resp, true);
        $coords = $json['features'][0]['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) continue;
        $lng = isset($coords[0]) ? (float)$coords[0] : null;
        $lat = isset($coords[1]) ? (float)$coords[1] : null;
        if ($lat === null || $lng === null) continue;
        $localHint = preg_match('/tablon|bugo|cagayan de oro|jasaan/i', $address) === 1;
        if ($localHint && !($lat >= 7.8 && $lat <= 9.2 && $lng >= 123.8 && $lng <= 125.6)) continue;
        return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
}

// Fetch active deliveries for this rider — assigned by ID or by name (fallback for legacy/name-only assignment)
$deliveries = [];
$has_prep_tasks = false;
try {
    $tPrep = $conn->query("SHOW TABLES LIKE 'order_preparation_tasks'");
    $has_prep_tasks = $tPrep && $tPrep->rowCount() > 0;
} catch (Exception $e) {
    $has_prep_tasks = false;
}
if ($has_assigned) {
    $delivery_owner_params = [];
    $where_assign = riderBuildOwnershipCondition($conn, 'd', $user_id, $delivery_owner_params);
    $tracking_select = '';
    if ($has_destination_lat) $tracking_select .= ', d.destination_lat';
    if ($has_destination_lng) $tracking_select .= ', d.destination_lng';
    $prepSelect = $has_prep_tasks ? ", COALESCE(opt.status, 'not_started') AS prep_status" : ", 'not_started' AS prep_status";
    $prepJoin = $has_prep_tasks ? " LEFT JOIN order_preparation_tasks opt ON opt.Order_ID = o.Order_ID " : "";
    $deliveries_query = "SELECT d.Delivery_ID, d.delivery_status, d.schedule_date, d.delivered_by, d.delivered_to, d.actual_date_arrived,
                                o.Order_ID, o.is_ar, o.order_date, {$addr_sel} as delivery_address, {$amt_sel}{$tracking_select},
                                COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name, COALESCE(o.customer_phone_snapshot, c.phone_number) as phone_number, c.email, COALESCE(o.customer_address_snapshot, c.address) as customer_address,
                                COALESCE(oq.total_units, 0) AS total_units,
                                COALESCE(oq.unit_label, 'Units') AS unit_label,
                                CASE 
                                    WHEN d.delivery_status = 'In Transit' THEN GREATEST(TIMESTAMPDIFF(MINUTE, COALESCE(d.updated_at, d.schedule_date), NOW()), 0)
                                    ELSE 0
                                END AS transit_minutes
                                {$prepSelect}
                         FROM delivery d
                         LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                         LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                         {$prepJoin}
                         LEFT JOIN (
                            SELECT od.Order_ID,
                                   SUM(COALESCE(od.ordered_qty, 0)) AS total_units,
                                   CASE
                                       WHEN COUNT(DISTINCT COALESCE({$unit_col_for_label_expr}, '')) = 1 THEN MAX(COALESCE({$unit_col_for_label_expr}, 'Units'))
                                       ELSE 'Units'
                                   END AS unit_label
                            FROM order_details od
                            LEFT JOIN products p ON od.Product_ID = p.Product_ID
                            {$unit_join_sql}
                            GROUP BY od.Order_ID
                         ) oq ON oq.Order_ID = o.Order_ID
                         WHERE {$where_assign}
                           AND d.delivery_status IN ('Scheduled', 'In Transit', 'Delivered', 'Remitted', 'Returning')
                         ORDER BY CASE d.delivery_status WHEN 'Scheduled' THEN 1 WHEN 'In Transit' THEN 2 END, d.schedule_date ASC
                         LIMIT 100";
    $stmt = $conn->prepare($deliveries_query);
    $stmt->execute($delivery_owner_params);
    $deliveries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Server-side geocode fallback: avoids browser CORS errors to Nominatim.
    if (!empty($deliveries) && $has_destination_lat && $has_destination_lng) {
        foreach ($deliveries as &$row) {
            $dLat = isset($row['destination_lat']) ? (float)$row['destination_lat'] : 0.0;
            $dLng = isset($row['destination_lng']) ? (float)$row['destination_lng'] : 0.0;
            if ($dLat != 0.0 || $dLng != 0.0) continue;

            $address = trim((string)($row['delivery_address'] ?? $row['customer_address'] ?? ''));
            if ($address === '') continue;

            $coords = geocodeDestinationServer($address);
            if (!$coords) continue;

            try {
                $upd = $conn->prepare("UPDATE delivery SET destination_lat = ?, destination_lng = ?, updated_at = NOW() WHERE Delivery_ID = ?");
                $upd->execute([$coords['lat'], $coords['lng'], (int)$row['Delivery_ID']]);
                $row['destination_lat'] = $coords['lat'];
                $row['destination_lng'] = $coords['lng'];
            } catch (Throwable $e) {
                // Keep page usable even if cache update fails.
            }
        }
        unset($row);
    }
}
// Rider deliveries fetched above via riderBuildOwnershipCondition.

$cancelled_deliveries = [];
if ($has_assigned) {
    $cancelled_owner_params = [];
    $where_assign = riderBuildOwnershipCondition($conn, 'd', $user_id, $cancelled_owner_params);
    $cancelled_query = "SELECT d.Delivery_ID, d.delivery_status, d.schedule_date, d.delivered_by, d.delivered_to, d.updated_at,
                               d.cancellation_reason, d.cancellation_remarks,
                               o.Order_ID, o.order_date, {$addr_sel} as delivery_address, {$amt_sel},
                               c.customer_name, c.phone_number, c.email, c.address as customer_address,
                               COALESCE(oq.total_units, 0) AS total_units,
                               COALESCE(oq.unit_label, 'Units') AS unit_label
                        FROM delivery d
                        LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                        LEFT JOIN (
                            SELECT od.Order_ID,
                                   SUM(COALESCE(od.ordered_qty, 0)) AS total_units,
                                   CASE
                                       WHEN COUNT(DISTINCT COALESCE({$unit_col_for_label_expr}, '')) = 1 THEN MAX(COALESCE({$unit_col_for_label_expr}, 'Units'))
                                       ELSE 'Units'
                                   END AS unit_label
                            FROM order_details od
                            LEFT JOIN products p ON od.Product_ID = p.Product_ID
                            {$unit_join_sql}
                            GROUP BY od.Order_ID
                        ) oq ON oq.Order_ID = o.Order_ID
                        WHERE {$where_assign}
                          AND d.delivery_status = 'Cancelled'
                        ORDER BY COALESCE(d.updated_at, d.schedule_date) DESC, d.Delivery_ID DESC
                        LIMIT 100";
    $stmt = $conn->prepare($cancelled_query);
    $stmt->execute($cancelled_owner_params);
    $cancelled_deliveries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

// Fetch completed today for collections summary
$col_amt = in_array('total_amount', $orders_cols) ? 'o.total_amount' : '0';
$col_q_base = "SELECT COALESCE(SUM({$col_amt}), 0) as total
          FROM delivery d
          LEFT JOIN orders o ON d.Order_ID = o.Order_ID
          WHERE d.delivery_status IN ('Delivered', 'Returning', 'Completed') AND DATE(d.actual_date_arrived) = CURDATE()";
$col_owner_params = [];
$col_ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $col_owner_params);
$col_stmt = $conn->prepare($col_q_base . " AND {$col_ownership}");
$col_stmt->execute($col_owner_params);
$collections_today = $col_stmt ? (float)$col_stmt->fetchColumn() : 0;

// Dashboard stats
$count_scheduled = count(array_filter($deliveries, function($d) { return ($d['delivery_status'] ?? '') === 'Scheduled'; }));
$count_transit = count(array_filter($deliveries, function($d) { return ($d['delivery_status'] ?? '') === 'In Transit'; }));
$count_delivered = count(array_filter($deliveries, function($d) { return ($d['delivery_status'] ?? '') === 'Delivered'; }));
$count_returning = count(array_filter($deliveries, function($d) { return ($d['delivery_status'] ?? '') === 'Returning'; }));
$count_active = count($deliveries);
// Pending = all assigned to you that still need to be delivered (Scheduled + In Transit)
$count_pending = $count_scheduled + $count_transit;
$expected_remittance = 0.0;
foreach ($deliveries as $d) {
    $expected_remittance += (float)($d['total_amount'] ?? 0);
}
$completed_today_q = "SELECT COUNT(*) FROM delivery d WHERE d.delivery_status IN ('Delivered', 'Returning', 'Completed') AND DATE(d.actual_date_arrived) = CURDATE()";
$ct_owner_params = [];
$ct_ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $ct_owner_params);
$ct_stmt = $conn->prepare($completed_today_q . " AND {$ct_ownership}");
$ct_stmt->execute($ct_owner_params);
$completed_today = $ct_stmt ? (int)$ct_stmt->fetchColumn() : 0;

$rider_profile_picture_db = '';
$rider_has_profile_pic = false;
$rider_profile_pic_src = '';
$capstoneRoot = dirname(__DIR__, 2);
try {
    $stmt = $conn->prepare('SELECT profile_picture FROM User_Profile WHERE User_ID = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['profile_picture'])) {
        $rider_profile_picture_db = (string)$row['profile_picture'];
    }
} catch (Throwable $e) {
    // User_Profile optional in some deployments
}
if ($rider_profile_picture_db !== '' && is_file($capstoneRoot . '/' . $rider_profile_picture_db)) {
    $rider_has_profile_pic = true;
    $rider_profile_pic_src = '../' . $rider_profile_picture_db . '?v=' . time();
}

// Fetch rider availability status
$rider_availability_status = 'Available';
try {
    $avStmt = $conn->prepare("SELECT rider_availability_status FROM user WHERE User_ID = ?");
    $avStmt->execute([$user_id]);
    $avRow = $avStmt->fetch(PDO::FETCH_ASSOC);
    if ($avRow && !empty($avRow['rider_availability_status'])) {
        $rider_availability_status = (string)$avRow['rider_availability_status'];
    }
} catch (Throwable $e) {
    $rider_availability_status = 'Available';
}

