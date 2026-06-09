<?php
/**
 * Rider Dashboard Backend
 * Handles: confirm_delivery (COD + details), get_collections, activity logging
 * Access: Delivery Rider (from roles table)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/mailer.php';
require_once '../includes/sms.php';
require_once '../includes/module_access.php';
require_once '../includes/csrf.php';
require_once '../includes/delivery_cancellation_helper.php';
require_once '../includes/rider_availability_helper.php';

require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/order_status_helper.php';
$rider_ids = getRiderRoleIds($conn);
requireRole(empty($rider_ids) ? [0] : $rider_ids);  // Delivery Rider from DB

header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Detect order status column
$order_status_col = 'order_status';
$cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
if ($cols_res && $row = $cols_res->fetch(PDO::FETCH_ASSOC)) {
    $order_status_col = $row['Field'];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF Protection for state-changing POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $state_changing_actions = ['confirm_delivery', 'send_on_the_way_email', 'send_on_the_way_sms', 'cancel_delivery', 'acknowledge_return_to_store', 'rider_set_available'];
    if (in_array($action, $state_changing_actions, true)) {
        // Debug logging for CSRF issues
        $received_token = $_POST['csrf_token'] ?? 'NOT_PROVIDED';
        $session_token = $_SESSION['csrf_token'] ?? 'NOT_SET';
        error_log("CSRF Debug - Action: {$action}, Received: {$received_token}, Session: {$session_token}");
        
        if (!validateCsrfToken(false)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page.']);
            exit();
        }
    }
}

try {
    switch ($action) {
        case 'confirm_delivery':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            confirmDelivery($conn, $user_id, $order_status_col);
            break;
        case 'cancel_delivery':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            cancelDelivery($conn, $user_id, $order_status_col);
            break;
        case 'acknowledge_return_to_store':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            acknowledgeReturnToStore($conn, $user_id);
            break;
        case 'get_collections':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            getCollections($conn);
            break;
        case 'get_delivered_history':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_history', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery history access is restricted.']);
                break;
            }
            getDeliveredHistory($conn);
            break;
        case 'send_on_the_way_email':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            sendOnTheWayEmail($conn, $user_id);
            break;
        case 'check_new_deliveries':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            checkNewDeliveries($conn, $user_id);
            break;
        case 'check_realtime_updates':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            checkRealtimeUpdates($conn, $user_id);
            break;
        case 'send_on_the_way_sms':
            if (!isModuleAllowedForUser($conn, $user_id, 'rider_delivery_queue', true)) {
                echo json_encode(['success' => false, 'message' => 'Delivery queue access is restricted.']);
                break;
            }
            sendOnTheWaySms($conn, $user_id);
            break;
        case 'rider_set_available':
            $res = setManualRiderAvailability($conn, $user_id, 'Available');
            echo json_encode(['success' => $res['success'], 'message' => $res['message']]);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Rider backend fatal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Log activity to activity_logs
 */
function logActivity($conn, $user_id, $activity) {
    try {
        $stmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity) VALUES (?, ?)");
        $stmt->execute([$user_id, $activity]);
    } catch (Exception $e) {
        // activity_logs table may not exist; don't fail delivery confirmation
    }
}

/**
 * Confirm delivery: record details, update status, COD confirmation
 */
function confirmDelivery($conn, $user_id, $order_status_col) {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    $delivered_to = trim($_POST['delivered_to'] ?? '');
    $delivery_notes = trim($_POST['remarks'] ?? '');
    $delivery_details = json_decode($_POST['delivery_details'] ?? '[]', true);
    $amount_to_collect = isset($_POST['amount_to_collect']) ? (float)$_POST['amount_to_collect'] : null;

    if (!$delivery_id) {
        echo json_encode(['success' => false, 'message' => 'Delivery ID required']);
        return;
    }

    // Verify delivery exists and rider has access (assigned to them or unassigned)
    $o_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $amt_select = in_array('total_amount', $o_cols) ? 'o.total_amount' : '0 as total_amount';
    $check = $conn->prepare("SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, {$amt_select}, o.Customer_ID
                             FROM delivery d
                             LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                             WHERE d.Delivery_ID = ?");
    $check->execute([$delivery_id]);
    $delivery = $check->fetch(PDO::FETCH_ASSOC);

    if (!$delivery) {
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        return;
    }
    $cod_amount = ($amount_to_collect !== null && $amount_to_collect >= 0) ? $amount_to_collect : (float)($delivery['total_amount'] ?? 0);

    // Rider can only confirm deliveries assigned to them
    $params = [$delivery_id];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $params);
    $access = $conn->prepare("SELECT 1 FROM delivery d WHERE d.Delivery_ID = ? AND {$ownership}");
    $access->execute($params);
    if (!$access->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Access denied - delivery not assigned to you']);
        return;
    }

    if ($delivery['delivery_status'] === 'Delivered') {
        echo json_encode(['success' => false, 'message' => 'Delivery already completed']);
        return;
    }

    if (empty($delivered_to)) {
        echo json_encode(['success' => false, 'message' => 'Delivered To (name of person who paid) is required']);
        return;
    }

    // Proof of delivery photo(s) (required) - supports single or multiple uploads
    $proof_paths = [];
    $proof_names = $_FILES['proof_photo']['name'] ?? null;
    $proof_tmp_names = $_FILES['proof_photo']['tmp_name'] ?? null;
    $proof_sizes = $_FILES['proof_photo']['size'] ?? null;
    $proof_errors = $_FILES['proof_photo']['error'] ?? null;

    $has_multiple = is_array($proof_tmp_names);
    $upload_count = $has_multiple ? count($proof_tmp_names) : (!empty($proof_tmp_names) ? 1 : 0);

    if ($upload_count > 0) {
        $upload_dir = __DIR__ . '/../uploads/delivery_proofs';
        if (!is_dir($upload_dir)) {
            if (!@mkdir($upload_dir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'Could not create upload directory.']);
                return;
            }
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        for ($idx = 0; $idx < $upload_count; $idx++) {
            $tmp_name = $has_multiple ? ($proof_tmp_names[$idx] ?? '') : (string)$proof_tmp_names;
            $orig_name = $has_multiple ? ($proof_names[$idx] ?? '') : (string)$proof_names;
            $file_size = (int)($has_multiple ? ($proof_sizes[$idx] ?? 0) : $proof_sizes);
            $file_err = (int)($has_multiple ? ($proof_errors[$idx] ?? UPLOAD_ERR_NO_FILE) : $proof_errors);

            if ($file_err === UPLOAD_ERR_NO_FILE || $tmp_name === '' || !is_uploaded_file($tmp_name)) {
                continue;
            }
            if ($file_err !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Failed to upload one of the proof photos.']);
                return;
            }
            $mime = $finfo->file($tmp_name);
            if (!in_array($mime, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'Proof must be an image (JPEG, PNG, WebP, or GIF).']);
                return;
            }
            if ($file_size > 10 * 1024 * 1024) { // 10MB per file
                echo json_encode(['success' => false, 'message' => 'Each proof image must be under 10MB.']);
                return;
            }
            $ext = pathinfo($orig_name, PATHINFO_EXTENSION) ?: 'jpg';
            $safe_ext = in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp'], true) ? strtolower($ext) : 'jpg';
            $filename = 'delivery_' . $delivery_id . '_' . date('YmdHis') . '_' . $idx . '_' . substr(uniqid('', true), -8) . '.' . $safe_ext;
            $target = $upload_dir . '/' . $filename;
            if (!move_uploaded_file($tmp_name, $target)) {
                echo json_encode(['success' => false, 'message' => 'Failed to save proof photo.']);
                return;
            }
            $proof_paths[] = 'uploads/delivery_proofs/' . $filename;
        }
    }

    if (empty($proof_paths)) {
        echo json_encode(['success' => false, 'message' => 'Proof of delivery photo is required.']);
        return;
    }

    $conn->beginTransaction();
    try {
        // Update delivery_details (received_qty, damage_qty, remarks)
        foreach ($delivery_details as $d) {
            $dd_id = (int)($d['delivery_detail_id'] ?? 0);
            if ($dd_id <= 0) continue;

            $received_qty = (float)($d['received_qty'] ?? 0);
            $damage_qty = (float)($d['damage_qty'] ?? 0);
            $remarks = trim($d['remarks'] ?? '');
            $ordered_qty = (float)($d['ordered_qty'] ?? $received_qty);

            $status = 'delivered';

            $upd = $conn->prepare("UPDATE delivery_detail SET received_qty = ?, damage_qty = ?, remarks = ?, status = ?, updated_at = NOW() WHERE Delivery_Detail_ID = ? AND Delivery_ID = ?");
            $upd->execute([$received_qty, $damage_qty, $remarks, $status, $dd_id, $delivery_id]);
        }

        // Update delivery: status, actual_date_arrived, delivered_to, delivered_by, delivered_by_user_id
        // Also keep proof_of_delivery_path on delivery table if column exists (backward compat)
        $del_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $set_delivered_by = in_array('delivered_by_user_id', $del_cols) ? ', delivered_by_user_id = ?' : '';
        $set_delivered_by_name = in_array('delivered_by', $del_cols) ? ', delivered_by = ?' : '';
        $primary_proof_path = $proof_paths[0] ?? null;
        $set_proof_legacy = in_array('proof_of_delivery_path', $del_cols) && $primary_proof_path ? ', proof_of_delivery_path = ?' : '';
        $set_collected_amt = in_array('rider_collected_amount', $del_cols) ? ', rider_collected_amount = ?' : '';
        $params = [$delivered_to];
        if (in_array('delivered_by_user_id', $del_cols)) {
            $params[] = $user_id;
        }
        if (in_array('delivered_by', $del_cols)) {
            $rider_name = trim($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Rider');
            $params[] = $rider_name;
        }
        if ($set_proof_legacy) {
            $params[] = $primary_proof_path;
        }
        if (in_array('rider_collected_amount', $del_cols)) {
            $params[] = $cod_amount;
        }
        $params[] = $delivery_id;
        $upd_del = $conn->prepare("UPDATE delivery SET delivery_status = 'Delivered', actual_date_arrived = NOW(), delivered_to = ?{$set_delivered_by}{$set_delivered_by_name}{$set_proof_legacy}{$set_collected_amt}, updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_del->execute($params);

        // ── PRIMARY: Insert proof into delivery_proofs (3NF normalized table) ──
        if (!empty($proof_paths)) {
            $table_exists = $conn->query("SHOW TABLES LIKE 'delivery_proofs'")->rowCount();
            if ($table_exists > 0) {
                // Remove prior proofs for this delivery to stay idempotent on re-confirm
                $conn->prepare("DELETE FROM delivery_proofs WHERE delivery_id = ?")->execute([$delivery_id]);
                $insertProofStmt = $conn->prepare(
                    "INSERT INTO delivery_proofs (delivery_id, file_path, captured_by, captured_at) VALUES (?, ?, ?, NOW())"
                );
                foreach ($proof_paths as $proof_path) {
                    $insertProofStmt->execute([$delivery_id, $proof_path, $user_id]);
                }
            } else {
                error_log("rider_dashboard_backend: delivery_proofs table not found. Run database/create_delivery_proofs_table.php.");
            }

            // ── LEGACY FALLBACK: also write to delivery_detail.proof_delivery if column exists ──
            $dd_cols = array_column($conn->query("SHOW COLUMNS FROM delivery_detail")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (in_array('proof_delivery', $dd_cols, true)) {
                $conn->prepare("UPDATE delivery_detail SET proof_delivery = ? WHERE Delivery_ID = ?")->execute([$primary_proof_path, $delivery_id]);
            }
        }

        // Rider handoff completes the order automatically.
        $order_id = (int)$delivery['Order_ID'];
        if ($order_id > 0) {
            $new_order_status = getValidOrderStatus($conn, 'Completed', ['Completed', 'Delivered']);
            $upd_ord = $conn->prepare("UPDATE orders SET {$order_status_col} = ? WHERE Order_ID = ?");
            $upd_ord->execute([$new_order_status, $order_id]);
        }

        // Activity log
        logActivity($conn, $user_id, "Confirmed delivery #{$delivery_id}, Order #{$order_id}, delivered to: {$delivered_to}, COD: " . number_format($cod_amount, 2));

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Delivery confirmed successfully', 'total_amount' => $cod_amount]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("Rider confirm delivery error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to confirm delivery: ' . $e->getMessage()]);
    }
}

/**
 * Cancel a delivery
 */
function cancelDelivery($conn, $user_id, $order_status_col) {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$delivery_id) {
        echo json_encode(['success' => false, 'message' => 'Delivery ID required']);
        return;
    }

    $validReasons = getDeliveryCancellationReasonOptions($conn);
    if (empty($reason) || !in_array($reason, $validReasons, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid cancellation reason selected.']);
        return;
    }

    if ($reason === 'Other' && $remarks === '') {
        echo json_encode(['success' => false, 'message' => 'Remarks are required when selecting Other.']);
        return;
    }

    $check = $conn->prepare("SELECT Delivery_ID, Order_ID, delivery_status FROM delivery WHERE Delivery_ID = ?");
    $check->execute([$delivery_id]);
    $delivery = $check->fetch(PDO::FETCH_ASSOC);

    if (!$delivery) {
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        return;
    }

    if (!in_array((string)$delivery['delivery_status'], ['Scheduled', 'In Transit'], true)) {
        echo json_encode(['success' => false, 'message' => 'Delivery is already processed ($delivery[delivery_status])']);
        return;
    }

    // Auth validation
    $params = [$delivery_id];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $params);
    $access = $conn->prepare("SELECT 1 FROM delivery d WHERE d.Delivery_ID = ? AND {$ownership}");
    $access->execute($params);
    if (!$access->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Access denied - delivery not assigned to you']);
        return;
    }

    $conn->beginTransaction();
    try {
        $new_status = 'Returning';
        
        $upd_del = $conn->prepare("UPDATE delivery SET delivery_status = ?, cancellation_reason = ?, cancellation_remarks = ?, updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_del->execute([$new_status, $reason, ($remarks !== '' ? $remarks : null), $delivery_id]);
        
        $detailReasonNote = $reason . ($remarks !== '' ? ' - ' . $remarks : '');
        $upd_dd = $conn->prepare("UPDATE delivery_detail SET remarks = CONCAT(COALESCE(remarks, ''), CASE WHEN COALESCE(remarks, '') = '' THEN '' ELSE ' ' END, '[Returning: ', ?, ']'), updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_dd->execute([$detailReasonNote, $delivery_id]);

        $order_id = (int)$delivery['Order_ID'];
        if ($order_id > 0) {
            $new_order_status = getValidOrderStatus($conn, 'Confirmed', ['Confirmed', 'Requested']);
            $upd_ord = $conn->prepare("UPDATE orders SET {$order_status_col} = ? WHERE Order_ID = ?");
            $upd_ord->execute([$new_order_status, $order_id]);
        }

        logActivity($conn, $user_id, "Marked delivery #{$delivery_id} as Returning, Order #{$order_id}. Reason: {$detailReasonNote}");

        // ── Vehicle issue special handling ──
        if ($reason === 'Vehicle issue') {
            // Set rider to Off Duty — they're stuck waiting for truck repair
            ensureRiderWorkflowSchema($conn);
            $availStmt = $conn->prepare("INSERT INTO rider_settings (User_ID, availability_status, last_set_at)
                                         VALUES (?, 'Off Duty', NOW())
                                         ON DUPLICATE KEY UPDATE availability_status = 'Off Duty', last_set_at = NOW()");
            $availStmt->execute([$user_id]);

            // Notify all manager/owner users so they can reassign
            require_once __DIR__ . '/../includes/roles_helper.php';
            $managerRoleIds = getDashboardRoleIds($conn);
            $riderName = trim($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Rider');
            $notifMsg = "{$riderName} reported Vehicle issue on Delivery #{$delivery_id}, Order #{$order_id}. Needs rider transfer.";
            if (!empty($managerRoleIds)) {
                $placeholders = implode(',', array_fill(0, count($managerRoleIds), '?'));
                $userStmt = $conn->prepare("SELECT User_ID FROM user WHERE Role_ID IN ($placeholders) AND is_active = 1");
                $userStmt->execute($managerRoleIds);
                $managerUserIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($managerUserIds)) {
                    $notifStmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID, Log_Time) VALUES (?, 'NOTIFICATION', ?, ?, CURRENT_TIMESTAMP)");
                    foreach ($managerUserIds as $uid) {
                        $notifStmt->execute([(int)$uid, $notifMsg, $delivery_id]);
                    }
                }
            }
        } else {
            // Normal cancellation: sync rider availability (may become Available if no active deliveries)
            $assignedRiderId = riderGetUserIdByDeliveryId($conn, $delivery_id);
            if ($assignedRiderId > 0) {
                syncRiderAvailabilityForUser($conn, $assignedRiderId);
            }
        }

        $conn->commit();

        $responseMsg = ($reason === 'Vehicle issue')
            ? 'Vehicle issue reported. Manager has been notified and will assign another rider to continue the delivery.'
            : 'Delivery marked as Returning. Manager can now contact the customer and reschedule or cancel the order.';
        echo json_encode(['success' => true, 'message' => $responseMsg]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("Rider cancel delivery error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to cancel delivery: ' . $e->getMessage()]);
    }
}

function acknowledgeReturnToStore($conn, $user_id) {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    if ($delivery_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Delivery ID required']);
        return;
    }

    $del_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $select_assigned = in_array('assigned_rider_id', $del_cols) ? ", assigned_rider_id" : "";
    $check = $conn->prepare("SELECT Delivery_ID, delivery_status, delivered_by{$select_assigned} FROM delivery WHERE Delivery_ID = ?");
    $check->execute([$delivery_id]);
    $delivery = $check->fetch(PDO::FETCH_ASSOC);
    if (!$delivery) {
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        return;
    }
    if ((string)($delivery['delivery_status'] ?? '') !== 'Returning') {
        echo json_encode(['success' => false, 'message' => 'Only Returning deliveries can be marked as returned to store.']);
        return;
    }

    $assignedRiderId = riderGetUserIdByDeliveryId($conn, $delivery_id);
    $is_assigned = ($assignedRiderId === $user_id);
    if (!$is_assigned) {
        echo json_encode(['success' => false, 'message' => 'You are not assigned to this delivery']);
        return;
    }

    try {
        ensureRiderWorkflowSchema($conn);
        $hasReturnAt = riderWorkflowHasColumn($conn, 'delivery', 'returned_to_store_at');
        $hasReturnUser = riderWorkflowHasColumn($conn, 'delivery', 'returned_to_store_by_user_id');
        if ($hasReturnAt) {
            if ($hasReturnUser) {
                $stmt = $conn->prepare("UPDATE delivery SET returned_to_store_at = NOW(), returned_to_store_by_user_id = ?, updated_at = NOW() WHERE Delivery_ID = ?");
                $stmt->execute([$user_id, $delivery_id]);
            } else {
                $stmt = $conn->prepare("UPDATE delivery SET returned_to_store_at = NOW(), updated_at = NOW() WHERE Delivery_ID = ?");
                $stmt->execute([$delivery_id]);
            }
        } else {
            $stmt = $conn->prepare("UPDATE delivery SET delivery_status = 'Cancelled', updated_at = NOW() WHERE Delivery_ID = ?");
            $stmt->execute([$delivery_id]);
        }
        if ($assignedRiderId > 0) {
            syncRiderAvailabilityForUser($conn, $assignedRiderId);
        }
        logActivity($conn, $user_id, "Returned delivery #{$delivery_id} to store and handed off to manager.");
        echo json_encode(['success' => true, 'message' => 'Return to store recorded. You are now available for the next assignment.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update return-to-store status: ' . $e->getMessage()]);
    }
}

/**
 * Get My Collections: sum of total_amount for Completed deliveries by this rider for current shift (today)
 */
function getCollections($conn) {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['success' => false, 'collections' => 0, 'deliveries' => []]);
        return;
    }

    // Completed deliveries where actual_date_arrived is today
    // We consider "completed" = delivery_status = 'Delivered' and actual_date_arrived = CURDATE()
    // Rider is determined by: delivered_by could be rider name, or we use activity_logs. For simplicity,
    // we show all Delivered today - in production you'd filter by rider. Since we don't have "who delivered"
    // we use: deliveries where assigned_rider_id = user_id OR (assigned_rider_id IS NULL - any rider)
    // For "My Collections" we need: deliveries completed TODAY by THIS rider.
    // Option: Add delivered_by_user_id to delivery. For now we'll use assigned_rider_id as proxy:
    // Show collections for deliveries where assigned_rider_id = user_id AND delivery_status = Delivered AND actual_date_arrived = CURDATE()
    // If assigned_rider_id is NULL, we can't attribute - so we'll show all Delivered today (rider sees their shift total if they're the only one, or we add delivered_by_user_id)

    // Check orders for total_amount
    $o_cols2 = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $amt_sel2 = in_array('total_amount', $o_cols2) ? 'o.total_amount' : '0 as total_amount';

    $params = [];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $params);
    $q = "SELECT d.Delivery_ID, d.actual_date_arrived, {$amt_sel2}, c.customer_name, d.delivered_to
          FROM delivery d
          LEFT JOIN orders o ON d.Order_ID = o.Order_ID
          LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
          WHERE d.delivery_status IN ('Delivered', 'Returning', 'Completed') AND DATE(d.actual_date_arrived) = CURDATE() AND {$ownership}";

    $stmt = $conn->prepare($q);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    $list = [];
    foreach ($rows as $r) {
        $amt = (float)($r['total_amount'] ?? 0);
        $total += $amt;
        $list[] = [
            'delivery_id' => (int)$r['Delivery_ID'],
            'customer_name' => $r['customer_name'] ?? 'N/A',
            'delivered_to' => $r['delivered_to'] ?? '',
            'total_amount' => $amt,
            'actual_date' => $r['actual_date_arrived'] ?? null
        ];
    }

    echo json_encode([
        'success' => true,
        'total_collections' => round($total, 2),
        'delivery_count' => count($list),
        'deliveries' => $list
    ]);
}

/**
 * Check for newly assigned deliveries for notifications
 */
function checkNewDeliveries($conn, $user_id) {
    if (!$user_id) {
        echo json_encode(['success' => false, 'deliveries' => []]);
        return;
    }

    $params = [];
    $where_assign = riderBuildOwnershipCondition($conn, 'd', (int)$user_id, $params);

    if ($where_assign === '0 = 1') {
        echo json_encode(['success' => true, 'deliveries' => []]);
        return;
    }

    $has_prep_tasks = false;
    try {
        $tPrep = $conn->query("SHOW TABLES LIKE 'order_preparation_tasks'");
        $has_prep_tasks = $tPrep && $tPrep->rowCount() > 0;
    } catch (Exception $e) {
        $has_prep_tasks = false;
    }

    $prepSelect = $has_prep_tasks ? ", COALESCE(opt.status, 'not_started') AS prep_status" : ", 'not_started' AS prep_status";
    $prepJoin = $has_prep_tasks ? " LEFT JOIN order_preparation_tasks opt ON opt.Order_ID = d.Order_ID " : "";

    $q = "SELECT d.Delivery_ID, d.Order_ID{$prepSelect}
          FROM delivery d
          {$prepJoin}
          WHERE {$where_assign} AND d.delivery_status IN ('Scheduled', 'In Transit')";
          
    $stmt = $conn->prepare($q);
    $stmt->execute($params);
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'deliveries' => $rows
    ]);
}

function checkRealtimeUpdates($conn, $user_id) {
    if (!$user_id) {
        echo json_encode(['success' => false, 'updates' => []]);
        return;
    }

    $remittanceUpdates = [];
    $damageReviews = [];

    $d_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $params = [];
    $ownershipCondition = riderBuildOwnershipCondition($conn, 'd', (int)$user_id, $params);

    if ($ownershipCondition !== '0 = 1') {
        $deliveryStatusCol = in_array('delivery_status', $d_cols, true) ? 'delivery_status' : null;
        $updatedAtCol = in_array('updated_at', $d_cols, true) ? 'updated_at' : 'created_at';
        if ($deliveryStatusCol !== null) {
            $q = "SELECT d.Delivery_ID AS delivery_id,
                         d.Order_ID AS order_id,
                         d.{$deliveryStatusCol} AS status,
                         d.{$updatedAtCol} AS updated_at
                  FROM delivery d
                  WHERE {$ownershipCondition}
                    AND d.{$deliveryStatusCol} IN ('Remitted', 'Completed')
                  ORDER BY d.{$updatedAtCol} DESC, d.Delivery_ID DESC
                  LIMIT 20";
            $stmt = $conn->prepare($q);
            $stmt->execute($params);
            $remittanceUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    try {
        $stmt = $conn->prepare(
            "SELECT r.report_id,
                    od.Order_ID AS order_id,
                    r.Delivery_ID AS delivery_id,
                    r.status,
                    r.reviewed_at
             FROM delivery_damage_report r
             INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID
             WHERE r.submitted_by = ?
               AND r.status IN ('approved', 'rejected')
               AND r.reviewed_at IS NOT NULL
             ORDER BY r.reviewed_at DESC, r.report_id DESC
             LIMIT 20"
        );
        $stmt->execute([$user_id]);
        $damageReviews = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $damageReviews = [];
    }

    echo json_encode([
        'success' => true,
        'remittances' => $remittanceUpdates,
        'damage_reviews' => $damageReviews,
    ]);
}

/**
 * Delivered history: all Delivered/Returning/Completed deliveries for this rider (all-time, latest first)
 */
function getDeliveredHistory($conn) {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['success' => false, 'deliveries' => []]);
        return;
    }

    $o_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $d_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $c_cols = array_column($conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $amt_sel = in_array('total_amount', $o_cols) ? 'o.total_amount' : '0 as total_amount';

    $params = [];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $params);

    if ($ownership === '0 = 1') {
        echo json_encode(['success' => true, 'delivery_count' => 0, 'total_cod' => 0, 'deliveries' => []]);
        return;
    }

    // Check if delivery_address exists in either delivery or orders table
    $has_customer_addr = in_array('address', $c_cols);
    $has_delivery_addr_delivery = in_array('delivery_address', $d_cols);
    $has_delivery_addr_orders = in_array('delivery_address', $o_cols);

    if ($has_delivery_addr_delivery && $has_delivery_addr_orders && $has_customer_addr) {
        $addr_sel = "COALESCE(NULLIF(TRIM(d.delivery_address), ''), NULLIF(TRIM(o.delivery_address), ''), NULLIF(TRIM(c.address), '')) as delivery_address";
    } elseif ($has_delivery_addr_delivery && $has_delivery_addr_orders) {
        $addr_sel = "COALESCE(NULLIF(TRIM(d.delivery_address), ''), NULLIF(TRIM(o.delivery_address), '')) as delivery_address";
    } elseif ($has_delivery_addr_delivery && $has_customer_addr) {
        $addr_sel = "COALESCE(NULLIF(TRIM(d.delivery_address), ''), NULLIF(TRIM(c.address), '')) as delivery_address";
    } elseif ($has_delivery_addr_orders && $has_customer_addr) {
        $addr_sel = "COALESCE(NULLIF(TRIM(o.delivery_address), ''), NULLIF(TRIM(c.address), '')) as delivery_address";
    } elseif ($has_delivery_addr_delivery) {
        $addr_sel = "d.delivery_address";
    } elseif ($has_delivery_addr_orders) {
        $addr_sel = "o.delivery_address";
    } elseif ($has_customer_addr) {
        $addr_sel = "c.address as delivery_address";
    } else {
        $addr_sel = "'' as delivery_address";
    }

    $q = "SELECT
            d.Delivery_ID,
            d.Order_ID,
            d.delivery_status,
            d.actual_date_arrived,
            d.delivered_to,
            {$amt_sel},
            COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name,
            {$addr_sel}
          FROM delivery d
          LEFT JOIN orders o ON d.Order_ID = o.Order_ID
          LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
          WHERE d.delivery_status IN ('Delivered', 'Returning', 'Completed')
            AND ({$ownership})
          ORDER BY d.actual_date_arrived DESC, d.Delivery_ID DESC
          LIMIT 200";

    $stmt = $conn->prepare($q);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0.0;
    $list = [];
    foreach ($rows as $r) {
        $amt = (float)($r['total_amount'] ?? 0);
        $total += $amt;
        $list[] = [
            'delivery_id' => (int)($r['Delivery_ID'] ?? 0),
            'order_id' => (int)($r['Order_ID'] ?? 0),
            'status' => $r['delivery_status'] ?? '',
            'customer_name' => $r['customer_name'] ?? 'N/A',
            'delivered_to' => $r['delivered_to'] ?? '',
            'total_amount' => $amt,
            'actual_date' => $r['actual_date_arrived'] ?? null,
            'delivery_address' => $r['delivery_address'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'delivery_count' => count($list),
        'total_cod' => round($total, 2),
        'deliveries' => $list
    ]);
}

function sendOnTheWayEmail($conn, $user_id) {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    if ($delivery_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Delivery ID required']);
        return;
    }

    $orderCols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $amountSelect = in_array('total_amount', $orderCols) ? 'o.total_amount' : '0 as total_amount';

    // Verify access and fetch customer email/order data.
    $stmt = $conn->prepare("
        SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, c.customer_name, c.email, {$amountSelect}
        FROM delivery d
        LEFT JOIN orders o ON d.Order_ID = o.Order_ID
        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
        WHERE d.Delivery_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$delivery_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        return;
    }

    // Rider can only send for assigned deliveries.
    $params = [$delivery_id];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $params);
    $access = $conn->prepare("SELECT 1 FROM delivery d WHERE d.Delivery_ID = ? AND {$ownership}");
    $access->execute($params);
    if (!$access->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Access denied for this delivery']);
        return;
    }

    $email = trim((string)($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Customer has no valid email address']);
        return;
    }

    $send = sendOrderOnTheWayEmail(
        $email,
        (string)($row['customer_name'] ?? 'Customer'),
        (int)($row['Order_ID'] ?? 0),
        (int)$row['Delivery_ID'],
        (float)($row['total_amount'] ?? 0)
    );
    if (!$send['ok']) {
        echo json_encode(['success' => false, 'message' => $send['message']]);
        return;
    }

    logActivity($conn, $user_id, "Sent on-the-way email for Delivery #{$delivery_id}, Order #" . (int)($row['Order_ID'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'On-the-way email reminder sent.']);
}

function sendOnTheWaySms($conn, $user_id) {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    if ($delivery_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Delivery ID required']);
        return;
    }

    $orderCols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $amountSelect = in_array('total_amount', $orderCols) ? 'o.total_amount' : '0 as total_amount';

    $stmt = $conn->prepare("
        SELECT d.Delivery_ID, d.Order_ID, c.customer_name, c.phone_number, {$amountSelect}
        FROM delivery d
        LEFT JOIN orders o ON d.Order_ID = o.Order_ID
        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
        WHERE d.Delivery_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$delivery_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        return;
    }

    // Rider can only send for assigned deliveries.
    $params = [$delivery_id];
    $ownership = riderBuildOwnershipCondition($conn, 'd', $user_id, $params);
    $access = $conn->prepare("SELECT 1 FROM delivery d WHERE d.Delivery_ID = ? AND {$ownership}");
    $access->execute($params);
    if (!$access->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Access denied for this delivery']);
        return;
    }

    $phone = trim((string)($row['phone_number'] ?? ''));
    if ($phone === '') {
        echo json_encode(['success' => false, 'message' => 'Customer has no phone number on file']);
        return;
    }

    $send = sendOrderOnTheWaySms(
        $phone,
        (string)($row['customer_name'] ?? 'Customer'),
        (int)($row['Order_ID'] ?? 0),
        (int)$row['Delivery_ID'],
        (float)($row['total_amount'] ?? 0)
    );
    if (!$send['ok']) {
        echo json_encode(['success' => false, 'message' => $send['message']]);
        return;
    }

    logActivity($conn, $user_id, "Sent on-the-way SMS for Delivery #{$delivery_id}, Order #" . (int)($row['Order_ID'] ?? 0));
    $extra = [];
    if (isset($send['quotaRemaining'])) {
        $extra['quota_remaining'] = $send['quotaRemaining'];
    }
    echo json_encode(array_merge(['success' => true, 'message' => 'On-the-way SMS reminder sent.'], $extra));
}
