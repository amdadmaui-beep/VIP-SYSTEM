<?php
/**
 * Shift Management API
 * 
 * Handles shift operations:
 * - Open shift with starting cash
 * - X-Read (mid-shift summary)
 * - Close shift
 * - Manager PIN validation
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/cash_session_helper.php';

header('Content-Type: application/json');

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

// CSRF validation
$csrfExemptActions = ['validate_manager_pins', 'get_current_shift'];
if (!in_array($action, $csrfExemptActions, true) && !validateCsrfToken(false)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired security token. Please refresh the page and try again.',
        'csrf_token' => getCsrfToken(),
    ]);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;

try {
    ensureCashSessionWorkflowSchema($conn);

    switch ($action) {
        case 'open_shift':
            handleOpenShift($conn, $user_id);
            break;
        case 'x_read':
            handleXRead($conn, $user_id);
            break;
        case 'close_shift':
            handleCloseShift($conn, $user_id);
            break;
        case 'validate_manager_pins':
            handleManagerPinsValidation($conn);
            break;
        case 'get_current_shift':
            handleGetCurrentShift($conn, $user_id);
            break;
        case 'record_movement':
            handleRecordMovement($conn, $user_id);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('Shift management error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}

/**
 * Open a new shift
 */
function handleOpenShift($conn, $user_id) {
    $starting_cash = floatval($_POST['starting_cash'] ?? 0);
    $denominations = json_decode($_POST['denominations'] ?? '[]', true);
    
    if ($starting_cash < 0) {
        throw new Exception('Starting cash cannot be negative');
    }
    
    // Check if user already has an open shift
    $existingShift = getOpenCashShiftForUser($conn, $user_id);
    
    if ($existingShift) {
        throw new Exception('You already have an open shift');
    }
    
    $conn->beginTransaction();
    
    try {
        // Create new shift
        $insertShift = $conn->prepare("
            INSERT INTO cash_shifts (
                User_ID,
                shift_date,
                shift_start_time,
                starting_cash,
                tolerance_amount
            ) 
            VALUES (?, CURDATE(), NOW(), ?, ?)
        ");
        $insertShift->execute([$user_id, $starting_cash, 50.00]);
        $shift_id = $conn->lastInsertId();
        
        // Log activity
        $logActivity = $conn->prepare("
            INSERT INTO shift_activity_log (shift_id, User_ID, activity_type, description) 
            VALUES (?, ?, 'Open', 'Shift opened with starting cash')
        ");
        $logActivity->execute([$shift_id, $user_id]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Shift opened successfully',
            'shift_id' => $shift_id,
            'starting_cash' => $starting_cash
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * X-Read - Mid-shift summary
 */
function handleXRead($conn, $user_id) {
    $manager_pins = $_POST['manager_pins'] ?? '';
    
    // Validate manager PIN
    if (!validateManagerPins($conn, $manager_pins)) {
        throw new Exception('Invalid manager PIN');
    }
    
    // Get current shift
    $shift = getOpenCashShiftForUser($conn, $user_id);
    if (!$shift) {
        throw new Exception('No open shift found');
    }
    
    // Calculate current totals
    $totals = calculateShiftTotals($conn, $shift['shift_id']);
    
    // Log X-Read activity
    $logActivity = $conn->prepare("
        INSERT INTO shift_activity_log (shift_id, User_ID, activity_type, description) 
        VALUES (?, ?, 'X-Read', 'Mid-shift summary requested')
    ");
    $logActivity->execute([$shift['shift_id'], $user_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'X-Read summary generated',
        'shift' => $shift,
        'totals' => $totals
    ]);
}

/**
 * Close shift
 */
function handleCloseShift($conn, $user_id) {
    $ending_cash = floatval($_POST['ending_cash'] ?? 0);
    $manager_pins = $_POST['manager_pins'] ?? '';
    $denominations = json_decode($_POST['denominations'] ?? '[]', true);
    $tolerance_amount = floatval($_POST['tolerance_amount'] ?? 50);
    
    // Validate manager PIN
    $managerApproval = resolveManagerApproval($conn, $manager_pins);
    if (!$managerApproval) {
        throw new Exception('Invalid manager PIN');
    }
    
    // Get current shift
    $shift = getOpenCashShiftForUser($conn, $user_id);
    if (!$shift) {
        throw new Exception('No open shift found');
    }
    
    // Calculate final totals
    $totals = calculateShiftTotalsDetailed($conn, (int) $shift['shift_id']);
    $expected_cash = floatval($totals['expected_cash'] ?? 0);
    $variance = $ending_cash - $expected_cash;
    $classification = classifyCashShiftDiscrepancy($variance, $tolerance_amount);
    
    $conn->beginTransaction();
    
    try {
        // Update shift
        $updateShift = $conn->prepare("
            UPDATE cash_shifts 
            SET shift_end_time = NOW(), 
                ending_cash = ?, 
                gross_sales = ?, 
                cash_sales = ?, 
                credit_sales = ?, 
                void_count = ?, 
                void_amount = ?, 
                expected_cash = ?,
                discrepancy_amount = ?,
                tolerance_amount = ?,
                status = 'Closed'
            WHERE shift_id = ?
        ");
        $updateShift->execute([
            $ending_cash,
            $totals['gross_sales'],
            $totals['cash_sales'],
            $totals['credit_sales'],
            $totals['void_count'],
            $totals['void_amount'],
            $expected_cash,
            $variance,
            $tolerance_amount,
            $shift['shift_id']
        ]);
        
        // Insert into new shift_reviews table
        $insertReview = $conn->prepare("
            INSERT INTO shift_reviews (shift_id, review_status, reviewed_by, reviewed_at, review_notes)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $insertReview->execute([
            $shift['shift_id'],
            $classification['review_status'],
            (int) ($managerApproval['User_ID'] ?? 0),
            $classification['review_notes']
        ]);
        
        // Log activity
        $logActivity = $conn->prepare("
            INSERT INTO shift_activity_log (shift_id, User_ID, activity_type, description) 
            VALUES (?, ?, 'Close', 'Shift closed')
        ");
        $logActivity->execute([$shift['shift_id'], $user_id]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Shift closed successfully',
            'shift_id' => $shift['shift_id'],
            'report' => [
                'expected_cash' => $expected_cash,
                'actual_cash' => $ending_cash,
                'variance' => $variance,
                'starting_cash' => floatval($shift['starting_cash']),
                'cash_sales' => floatval($totals['cash_sales']),
                'walk_in_cash' => floatval($totals['walk_in_cash']),
                'delivery_remittance_cash' => floatval($totals['delivery_remittance_cash']),
                'ar_collection_cash' => floatval($totals['ar_collection_cash']),
                'cash_in_total' => floatval($totals['cash_in_total']),
                'cash_out_total' => floatval($totals['cash_out_total']),
                'change_given_total' => floatval($totals['change_given_total']),
                'review_status' => $classification['review_status'],
                'review_notes' => $classification['review_notes'],
                'tolerance_amount' => $tolerance_amount,
                'source_breakdown' => $totals['source_breakdown']
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Validate manager PIN
 */
function handleManagerPinsValidation($conn) {
    $pin = $_POST['pin'] ?? '';
    
    $managerApproval = resolveManagerApproval($conn, $pin);
    if ($managerApproval) {
        $name = trim((string)($managerApproval['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($managerApproval['user_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Manager';
        }
        echo json_encode([
            'success' => true,
            'message' => 'PIN validated',
            'manager_name' => $name,
            'csrf_token' => rotateCsrfToken(),
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid PIN',
            'csrf_token' => getCsrfToken(),
        ]);
    }
}

/**
 * Get current shift info
 */
function handleGetCurrentShift($conn, $user_id) {
    $shift = getOpenCashShiftForUser($conn, $user_id);
    
    if ($shift) {
        $totals = calculateShiftTotalsDetailed($conn, (int) $shift['shift_id']);
        echo json_encode([
            'success' => true,
            'shift' => $shift,
            'totals' => $totals,
            'csrf_token' => getCsrfToken(),
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No open shift',
            'csrf_token' => getCsrfToken(),
        ]);
    }
}

function handleRecordMovement($conn, $user_id) {
    $movementType = trim($_POST['movement_type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($movementType, ['cash_in', 'cash_out'], true)) {
        throw new Exception('Invalid cash movement type');
    }
    if ($amount <= 0) {
        throw new Exception('Amount must be greater than zero');
    }
    if ($reason === '') {
        throw new Exception('Reason is required');
    }

    $shift = getOpenCashShiftForUser($conn, $user_id);
    if (!$shift) {
        throw new Exception('Open a shift before recording cash movements');
    }

    recordCashShiftMovement($conn, (int) $shift['shift_id'], $movementType, $amount, $reason, $user_id);

    echo json_encode([
        'success' => true,
        'message' => $movementType === 'cash_in' ? 'Cash-in recorded successfully' : 'Cash-out recorded successfully'
    ]);
}

/**
 * Helper Functions
 */

function validateManagerPins($conn, $pin) {
    return (bool) resolveManagerApproval($conn, $pin);
}
?>
