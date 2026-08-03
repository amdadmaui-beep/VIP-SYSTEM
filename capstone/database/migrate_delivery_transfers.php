<?php
/**
 * Ensure delivery_transfers audit table exists.
 *
 * Usage (from capstone/): php database/migrate_delivery_transfers.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/delivery_transfer_helper.php';

try {
    ensureDeliveryTransfersTable($conn);
    echo "[OK] delivery_transfers table ready\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
