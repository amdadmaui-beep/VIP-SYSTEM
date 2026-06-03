<?php
/**
 * Migration: Fix FK type mismatches
 * 
 * cash_shift_movements.User_ID and cash_session_entries.User_ID were
 * BIGINT UNSIGNED but user.User_ID is INT (signed). This prevents FK
 * creation and wastes space.
 * 
 * Run: php database/migrate_fix_fk_types.php (from capstone/)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

echo "Fixing FK type mismatches...\n\n";

// Helper: get column names for a table
function getTableColumns(PDO $conn, string $table): array {
    $cols = [];
    $r = $conn->query("SHOW COLUMNS FROM `{$table}`");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $cols[$row['Field']] = $row;
    }
    return $cols;
}

// ── cash_shift_movements ─────────────────────────────────────────────
$csmCols = getTableColumns($conn, 'cash_shift_movements');
if (!isset($csmCols['User_ID'])) {
    echo "1. cash_shift_movements: adding User_ID column...\n";
    try {
        $conn->exec("ALTER TABLE cash_shift_movements ADD COLUMN User_ID INT UNSIGNED NOT NULL AFTER reason");
        echo "   [OK] Added User_ID column\n";
    } catch (Exception $e) {
        echo "   [ERROR] " . $e->getMessage() . "\n";
    }
} elseif (stripos($csmCols['User_ID']['Type'], 'bigint') !== false || stripos($csmCols['User_ID']['Type'], 'unsigned') === false) {
    echo "1. cash_shift_movements.User_ID: fixing type...\n";
    try {
        $conn->exec("ALTER TABLE cash_shift_movements MODIFY COLUMN User_ID INT UNSIGNED NOT NULL");
        echo "   [OK]\n";
    } catch (Exception $e) {
        echo "   [ERROR] " . $e->getMessage() . "\n";
    }
} else {
    echo "1. cash_shift_movements.User_ID: type OK (" . $csmCols['User_ID']['Type'] . ")\n";
}

// ── cash_session_entries ─────────────────────────────────────────────
$cseCols = getTableColumns($conn, 'cash_session_entries');
if (isset($cseCols['User_ID']) && (stripos($cseCols['User_ID']['Type'], 'bigint') !== false || stripos($cseCols['User_ID']['Type'], 'unsigned') === false)) {
    echo "2. cash_session_entries.User_ID: fixing type...\n";
    try {
        $conn->exec("ALTER TABLE cash_session_entries MODIFY COLUMN User_ID INT UNSIGNED NOT NULL");
        echo "   [OK]\n";
    } catch (Exception $e) {
        echo "   [ERROR] " . $e->getMessage() . "\n";
    }
} else {
    echo "2. cash_session_entries.User_ID: type OK\n";
}

// ── Add FKs ──────────────────────────────────────────────────────────
echo "3. Adding FK: cash_shift_movements.User_ID → user.User_ID\n";
try {
    $conn->exec("
        ALTER TABLE cash_shift_movements
        ADD CONSTRAINT fk_csm_user
        FOREIGN KEY (User_ID) REFERENCES user(User_ID)
        ON DELETE CASCADE ON UPDATE CASCADE
    ");
    echo "   [OK]\n";
} catch (Exception $e) {
    echo "   [SKIP] " . $e->getMessage() . "\n";
}

echo "4. Adding FK: cash_session_entries.User_ID → user.User_ID\n";
try {
    $conn->exec("
        ALTER TABLE cash_session_entries
        ADD CONSTRAINT fk_cse_user
        FOREIGN KEY (User_ID) REFERENCES user(User_ID)
        ON DELETE CASCADE ON UPDATE CASCADE
    ");
    echo "   [OK]\n";
} catch (Exception $e) {
    echo "   [SKIP] " . $e->getMessage() . "\n";
}

echo "5. Adding FK: cash_shift_movements.shift_id → cash_shifts.shift_id\n";
try {
    $conn->exec("
        ALTER TABLE cash_shift_movements
        ADD CONSTRAINT fk_csm_shift
        FOREIGN KEY (shift_id) REFERENCES cash_shifts(shift_id)
        ON DELETE CASCADE ON UPDATE CASCADE
    ");
    echo "   [OK]\n";
} catch (Exception $e) {
    echo "   [SKIP] " . $e->getMessage() . "\n";
}

echo "\nMigration complete.\n";
