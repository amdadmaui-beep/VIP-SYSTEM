<?php
/**
 * Resolve a valid order_status value for the orders table.
 * The column may be ENUM with varying values - we pick the best match.
 * @param PDO $conn
 * @param string $preferred e.g. 'Delivered (Pending Cash Turnover)'
 * @param array $fallbacks e.g. ['Delivered', 'Completed', 'Out for Delivery']
 * @return string
 */
function getValidOrderStatus($conn, $preferred, $fallbacks = []) {
    $col = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if (!$col || $col->rowCount() === 0) return $preferred;
    $row = $col->fetch(PDO::FETCH_ASSOC);
    $col_name = $row['Field'];
    $type = $row['Type'] ?? '';

    if (stripos($type, 'enum') === false) {
        return $preferred; // VARCHAR or other - use as is
    }
    preg_match("/enum\s*\((.+)\)/i", $type, $m);
    if (empty($m[1])) return $preferred;
    $values = array_map(function($v) {
        return trim(trim($v), "'\"");
    }, explode(',', $m[1]));

    if (in_array($preferred, $values)) return $preferred;
    foreach ($fallbacks as $fb) {
        if (in_array($fb, $values)) return $fb;
    }
    // Case-insensitive match
    foreach ($values as $v) {
        if (strcasecmp($v, $preferred) === 0) return $v;
    }
    foreach ($values as $v) {
        if (stripos($v, 'Delivered') !== false || stripos($v, 'delivered') !== false) return $v;
    }
    foreach ($values as $v) {
        if (stripos($v, 'Completed') !== false || stripos($v, 'completed') !== false) return $v;
    }
    return $values[0] ?? $preferred;
}
