<?php
/**
 * Logger helper for VIP Ice Plant System
 * Records user actions into the activity_logs table
 */

if (!function_exists('logActivity')) {
    function logActivity($type, $details, $refId = null) {
        global $conn;
        
        // Ensure connection is available
        if (!$conn) {
            require_once __DIR__ . '/db.php';
        }

        try {
            // Get current user ID from session
            $userId = $_SESSION['user_id'] ?? null;
            
            // If no user ID (e.g. login attempt, or system action), use 0 or skip
            // For now, we only log if a user is authenticated or it's a critical system event
            if (!$userId && $type !== 'SYSTEM' && $type !== 'AUTH') {
                return false;
            }

            $stmt = $conn->prepare("INSERT INTO activity_logs (User_ID, Activity_Type, Action_Details, Reference_ID, Log_Time) 
                                   VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
            return $stmt->execute([
                $userId,
                $type,
                $details,
                $refId
            ]);
        } catch (Exception $e) {
            error_log("Logging error: " . $e->getMessage());
            return false;
        }
    }
}
?>
