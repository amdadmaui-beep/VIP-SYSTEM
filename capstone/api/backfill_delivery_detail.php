<?php
/**
 * Backfill delivery_detail rows for existing deliveries.
 *
 * Why this exists:
 * - Opening .sql in the browser downloads it; it does NOT execute it.
 * - This endpoint runs the backfill query using the app's DB connection.
 *
 * Safety:
 * - Requires logged-in user (same as other API endpoints).
 * - INSERT is idempotent (LEFT JOIN ... WHERE dd is NULL).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/roles_helper.php';

header('Content-Type: text/html; charset=utf-8');

$dashboardRoleIds = getDashboardRoleIds($conn);
requireRole(empty($dashboardRoleIds) ? [1] : $dashboardRoleIds);

// Basic diagnostics first
$counts = [
    'delivery' => 0,
    'order_details' => 0,
    'delivery_detail' => 0,
    'missing_pairs' => 0,
];

try {
    $counts['delivery'] = (int) $conn->query("SELECT COUNT(*) FROM delivery")->fetchColumn();
    $counts['order_details'] = (int) $conn->query("SELECT COUNT(*) FROM order_details")->fetchColumn();
    $counts['delivery_detail'] = (int) $conn->query("SELECT COUNT(*) FROM delivery_detail")->fetchColumn();

    // How many delivery_detail rows are missing (per delivery + order_detail)
    $missingSql = "
        SELECT COUNT(*)
        FROM delivery d
        INNER JOIN order_details od ON od.Order_ID = d.Order_ID
        LEFT JOIN delivery_detail dd
          ON dd.Delivery_ID = d.Delivery_ID AND dd.Order_detail_ID = od.Order_detail_ID
        WHERE d.Order_ID IS NOT NULL
          AND dd.Delivery_Detail_ID IS NULL
    ";
    $counts['missing_pairs'] = (int) $conn->query($missingSql)->fetchColumn();
} catch (Throwable $e) {
    // ignore; we'll show errors below if execution fails
}

echo "<h2>Backfill delivery_detail</h2>";
echo "<p>This will create missing <code>delivery_detail</code> rows for existing deliveries by copying items from <code>order_details</code>.</p>";
echo "<h3>Current counts</h3>";
echo "<ul>";
echo "<li><strong>delivery</strong>: " . htmlspecialchars((string)$counts['delivery']) . "</li>";
echo "<li><strong>order_details</strong>: " . htmlspecialchars((string)$counts['order_details']) . "</li>";
echo "<li><strong>delivery_detail</strong>: " . htmlspecialchars((string)$counts['delivery_detail']) . "</li>";
echo "<li><strong>missing delivery_detail rows to create</strong>: " . htmlspecialchars((string)$counts['missing_pairs']) . "</li>";
echo "</ul>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken(false)) {
        http_response_code(403);
        echo "<div style='padding:12px;border:1px solid #ef4444;background:#fee2e2;color:#991b1b;border-radius:8px;'>";
        echo "<strong>Security check failed.</strong> Refresh the page and try again.";
        echo "</div>";
        exit;
    }

    $conn->beginTransaction();
    try {
        $sql = "
            INSERT INTO delivery_detail (Delivery_ID, Order_detail_ID, received_qty, damage_qty, status, created_at, updated_at)
            SELECT
                d.Delivery_ID,
                od.Order_detail_ID,
                od.ordered_qty,
                0,
                'Pending',
                NOW(),
                NOW()
            FROM delivery d
            INNER JOIN order_details od
                ON od.Order_ID = d.Order_ID
            LEFT JOIN delivery_detail dd
                ON dd.Delivery_ID = d.Delivery_ID
               AND dd.Order_detail_ID = od.Order_detail_ID
            WHERE d.Order_ID IS NOT NULL
              AND dd.Delivery_Detail_ID IS NULL
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $inserted = $stmt->rowCount();
        $conn->commit();

        echo "<div style='padding:12px;border:1px solid #10b981;background:#d1fae5;color:#065f46;border-radius:8px;'>";
        echo "<strong>Backfill complete.</strong> Inserted rows: " . htmlspecialchars((string)$inserted);
        echo "</div>";
        echo "<p><a href='backfill_delivery_detail.php'>Refresh counts</a></p>";
    } catch (Throwable $e) {
        $conn->rollBack();
        echo "<div style='padding:12px;border:1px solid #ef4444;background:#fee2e2;color:#991b1b;border-radius:8px;'>";
        echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
    }
} else {
    echo "<form method='POST' style='margin-top:16px;'>";
    echo csrfTokenField();
    echo "<button type='submit' style='padding:10px 14px;border:0;border-radius:8px;background:#3b82f6;color:white;cursor:pointer;'>Run backfill now</button>";
    echo "</form>";
    echo "<p style='margin-top:10px;color:#6b7280;'>Tip: If <em>missing delivery_detail rows</em> is 0, then your issue is not the backfill — it is likely mismatched keys (Order_ID/Order_detail_ID) or different table/column names.</p>";
}
?>

