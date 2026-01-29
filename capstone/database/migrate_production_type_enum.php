<?php
/**
 * Migration: Update production_type ENUM values
 * Changes 'stock'/'order' to 'stockin'/'orders'
 */

require_once '../includes/db.php';

try {
    // Check current column type
    $result = $conn->query("SHOW COLUMNS FROM productions WHERE Field = 'production_type'");
    $row = $result->fetch_assoc();
    $currentType = $row['Type'];
    
    echo "Current production_type: " . $currentType . "\n";
    
    // If it's already the new values, skip
    if (strpos($currentType, 'stockin') !== false && strpos($currentType, 'orders') !== false) {
        echo "Column already has correct values. No migration needed.\n";
        $conn->close();
        exit(0);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    // First, update existing records if any (convert old values to new)
    // We'll temporarily change to VARCHAR to avoid ENUM constraint issues
    echo "Step 1: Converting column to VARCHAR temporarily...\n";
    $conn->query("ALTER TABLE productions MODIFY COLUMN production_type VARCHAR(20) NOT NULL DEFAULT 'stockin'");
    
    echo "Step 2: Updating existing records...\n";
    $conn->query("UPDATE productions SET production_type = 'stockin' WHERE production_type = 'stock'");
    $conn->query("UPDATE productions SET production_type = 'orders' WHERE production_type = 'order'");
    
    echo "Step 3: Converting back to ENUM with new values...\n";
    // Now change to ENUM with new values
    $conn->query("ALTER TABLE productions MODIFY COLUMN production_type ENUM('stockin', 'orders') NOT NULL DEFAULT 'stockin'");
    
    // Commit transaction
    $conn->commit();
    
    echo "Migration successful! production_type column updated to use 'stockin' and 'orders'.\n";
    
    // Verify
    $result = $conn->query("SHOW COLUMNS FROM productions WHERE Field = 'production_type'");
    $row = $result->fetch_assoc();
    echo "New production_type: " . $row['Type'] . "\n";
    
} catch (Exception $e) {
    // Rollback on error
    if ($conn->in_transaction) {
        $conn->rollback();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

$conn->close();
?>
