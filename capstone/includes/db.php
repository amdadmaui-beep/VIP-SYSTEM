<?php
/**
 * Database Connection - Secure Configuration
 * Updated: Security Fix - Moved credentials to .env file
 * Location: capstone/includes/db.php
 */

// Set timezone to match database server (Asia/Manila for Philippines, adjust as needed)
date_default_timezone_set('Asia/Manila');

// Load secure configuration (must be before DB constants are used)
require_once __DIR__ . '/config.php';

// Create database connection using PDO with secure credentials
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Log error securely (don't expose details to users in production)
    error_log("Database connection error: " . $e->getMessage());
    
    // Show generic error in production, detailed in development
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        die("Database connection error: " . $e->getMessage());
    } else {
        die("Unable to connect to database. Please contact system administrator.");
    }
}

if (function_exists('enforceAuthenticatedUserIsActive') && isset($_SESSION['user_id'])) {
    enforceAuthenticatedUserIsActive($conn);
}

// Enforce manager-controlled per-user module access for authenticated users.
require_once __DIR__ . '/module_access.php';
enforceCurrentRequestModuleAccess($conn);
?>
