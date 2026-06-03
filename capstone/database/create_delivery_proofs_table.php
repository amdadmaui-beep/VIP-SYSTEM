<?php
/**
 * Migration: Create delivery_proofs table (3NF normalized proof of delivery)
 *
 * This script is idempotent — safe to run multiple times.
 * Run once via browser or CLI to apply the schema.
 *
 * Table: delivery_proofs
 *   proof_id     PK  AUTO_INCREMENT
 *   delivery_id  FK  → delivery(Delivery_ID) ON DELETE CASCADE
 *   file_path    VARCHAR(500) — relative path e.g. uploads/delivery_proofs/xxx.jpg
 *   captured_by  FK  → user(User_ID)  — the rider who confirmed delivery
 *   captured_at  DATETIME — timestamp of upload (defaults to NOW())
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$steps = [];

// ──────────────────────────────────────────────────────────
// 1. Create the table
// ──────────────────────────────────────────────────────────
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS delivery_proofs (
            proof_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            delivery_id  INT UNSIGNED NOT NULL,
            file_path    VARCHAR(500)  NOT NULL,
            captured_by  INT UNSIGNED NOT NULL,
            captured_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (proof_id),
            INDEX idx_dp_delivery    (delivery_id),
            INDEX idx_dp_captured_by (captured_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = "[OK] delivery_proofs table created (or already exists).";
} catch (Throwable $e) {
    $steps[] = "[FAIL] Could not create delivery_proofs: " . $e->getMessage();
}

// ──────────────────────────────────────────────────────────
// 2. Add FK → delivery (best-effort — skip if constraint exists)
// ──────────────────────────────────────────────────────────
try {
    // Check if FK already exists
    $fk = $conn->query("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'delivery_proofs'
          AND CONSTRAINT_NAME = 'fk_dp_delivery'
    ")->fetchColumn();

    if (!$fk) {
        $conn->exec("
            ALTER TABLE delivery_proofs
            ADD CONSTRAINT fk_dp_delivery
            FOREIGN KEY (delivery_id) REFERENCES delivery(Delivery_ID)
            ON DELETE CASCADE ON UPDATE CASCADE
        ");
        $steps[] = "[OK] FK fk_dp_delivery added.";
    } else {
        $steps[] = "[SKIP] FK fk_dp_delivery already exists.";
    }
} catch (Throwable $e) {
    $steps[] = "[WARN] FK fk_dp_delivery skipped: " . $e->getMessage();
}

// ──────────────────────────────────────────────────────────
// 3. Add FK → user (best-effort)
// ──────────────────────────────────────────────────────────
try {
    $fk2 = $conn->query("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'delivery_proofs'
          AND CONSTRAINT_NAME = 'fk_dp_captured_by'
    ")->fetchColumn();

    if (!$fk2) {
        $conn->exec("
            ALTER TABLE delivery_proofs
            ADD CONSTRAINT fk_dp_captured_by
            FOREIGN KEY (captured_by) REFERENCES user(User_ID)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ");
        $steps[] = "[OK] FK fk_dp_captured_by added.";
    } else {
        $steps[] = "[SKIP] FK fk_dp_captured_by already exists.";
    }
} catch (Throwable $e) {
    $steps[] = "[WARN] FK fk_dp_captured_by skipped: " . $e->getMessage();
}

// ──────────────────────────────────────────────────────────
// 4. Backfill: copy existing proof paths from delivery table
//    into delivery_proofs so old records are not orphaned.
// ──────────────────────────────────────────────────────────
try {
    // Check if delivery has proof_of_delivery_path column
    $d_cols = array_column(
        $conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );

    if (in_array('proof_of_delivery_path', $d_cols, true)) {
        // Use user_id 1 as placeholder for captured_by
        $inserted = $conn->exec("
            INSERT INTO delivery_proofs (delivery_id, file_path, captured_by, captured_at)
            SELECT d.Delivery_ID,
                   d.proof_of_delivery_path,
                   1,
                   COALESCE(d.actual_date_arrived, d.updated_at, NOW())
            FROM delivery d
            WHERE d.proof_of_delivery_path IS NOT NULL
              AND d.proof_of_delivery_path <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM delivery_proofs dp WHERE dp.delivery_id = d.Delivery_ID
              )
        ");
        $steps[] = "[OK] Backfill: {$inserted} row(s) migrated from delivery.proof_of_delivery_path.";
    } else {
        $steps[] = "[SKIP] Backfill: delivery.proof_of_delivery_path column not found. Nothing to migrate.";
    }
} catch (Throwable $e) {
    $steps[] = "[WARN] Backfill skipped: " . $e->getMessage();
}

echo implode("\n", $steps) . "\n\nMigration complete.\n";
