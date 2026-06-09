<?php
/**
 * Migration: Schema Hardening — ENUM→VARCHAR, collations, type fixes, FKs
 *
 * Batch 1: Drop duplicate FK constraints
 * Batch 2: Convert remaining ENUMs to VARCHAR(50)
 * Batch 3: Unify collations to utf8mb4_unicode_ci
 * Batch 4: Fix BIGINT→INT UNSIGNED type mismatches
 * Batch 5: Fix signed INT→INT UNSIGNED for User_ID references
 * Batch 6: Add missing FK constraints
 * Batch 7: Add missing indexes
 */

$migration = [
    'name' => 'Schema hardening: ENUMs, types, collations, FKs',

    // Batch 1: Drop duplicate FK constraints on order_details
    'drop_dup_fk' => [
        "ALTER TABLE order_details DROP FOREIGN KEY order_details_order_id_foreign",
        "ALTER TABLE order_details DROP FOREIGN KEY order_details_product_id_foreign",
    ],

    // Batch 2: Convert ENUMs to VARCHAR(50)
    'enum_to_varchar' => [
        "ALTER TABLE adjustment_details MODIFY reason VARCHAR(50) NOT NULL DEFAULT 'Other (with remarks)'",
        "ALTER TABLE cash_shift_movements MODIFY movement_type VARCHAR(50) NOT NULL DEFAULT 'cash_in'",
        "ALTER TABLE cash_shifts MODIFY status VARCHAR(50) NOT NULL DEFAULT 'Open'",
        "ALTER TABLE damage_goods MODIFY damage_type VARCHAR(50) NOT NULL DEFAULT 'Spilled'",
        "ALTER TABLE damage_report_reviews MODIFY status VARCHAR(50) DEFAULT 'pending_review'",
        "ALTER TABLE delivery_detail MODIFY status VARCHAR(50) NOT NULL DEFAULT 'pending'",
        "ALTER TABLE inventory_ledger MODIFY transaction_type VARCHAR(50) NOT NULL",
        "ALTER TABLE order_preparation_tasks MODIFY status VARCHAR(50) NOT NULL DEFAULT 'not_started'",
        "ALTER TABLE rider_remittance_tracking MODIFY remittance_status VARCHAR(50) DEFAULT 'Exact'",
        "ALTER TABLE sales MODIFY remittance_status VARCHAR(50) DEFAULT 'Exact'",
        "ALTER TABLE shift_activity_log MODIFY activity_type VARCHAR(50) NOT NULL",
        // Removed: rider_availability_status moved to rider_settings table
    ],

    // Batch 3: Unify collations
    'fix_collation' => [
        "ALTER TABLE activity_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE inventory_ledger CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE units CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE user_module_access CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE user_profile CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE user_sessions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE audit_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],

    // Batch 4: Fix BIGINT→INT UNSIGNED
    'fix_bigint' => [
        "ALTER TABLE cash_shifts MODIFY User_ID INT UNSIGNED NOT NULL",
        "ALTER TABLE cash_shift_movements MODIFY recorded_by INT UNSIGNED NOT NULL",
        "ALTER TABLE shift_activity_log MODIFY User_ID INT UNSIGNED NOT NULL",
        "ALTER TABLE shift_reviews MODIFY reviewed_by INT UNSIGNED DEFAULT NULL",
    ],

    // Batch 5: Fix signed INT→INT UNSIGNED for User_ID references
    'fix_signed_int' => [
        "ALTER TABLE password_change_codes MODIFY User_ID INT UNSIGNED NOT NULL",
        "ALTER TABLE password_reset_codes MODIFY User_ID INT UNSIGNED NOT NULL",
        "ALTER TABLE user_module_access MODIFY User_ID INT UNSIGNED NOT NULL",
        "ALTER TABLE user_sessions MODIFY User_ID INT UNSIGNED NOT NULL",
        "ALTER TABLE sales MODIFY rider_user_id INT UNSIGNED DEFAULT NULL",
        "ALTER TABLE ar_email_reminders MODIFY sent_by INT UNSIGNED DEFAULT NULL",
        "ALTER TABLE ar_sms_reminders MODIFY sent_by INT UNSIGNED DEFAULT NULL",
        "ALTER TABLE order_preparation_tasks MODIFY started_by INT UNSIGNED DEFAULT NULL",
        "ALTER TABLE order_preparation_tasks MODIFY ready_by INT UNSIGNED DEFAULT NULL",
    ],

    // Batch 6: Add missing FK constraints
    'add_fk' => [
        "ALTER TABLE cash_shifts ADD CONSTRAINT fk_cash_shifts_user FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE",
        "ALTER TABLE cash_shift_movements ADD CONSTRAINT fk_csm_recorded_by FOREIGN KEY (recorded_by) REFERENCES user(User_ID)",
        "ALTER TABLE shift_activity_log ADD CONSTRAINT fk_shift_activity_user FOREIGN KEY (User_ID) REFERENCES user(User_ID)",
        "ALTER TABLE shift_reviews ADD CONSTRAINT fk_shift_reviews_reviewer FOREIGN KEY (reviewed_by) REFERENCES user(User_ID)",
        "ALTER TABLE password_change_codes ADD CONSTRAINT fk_pwd_change_user FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE",
        "ALTER TABLE password_reset_codes ADD CONSTRAINT fk_pwd_reset_user FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE",
        "ALTER TABLE user_module_access ADD CONSTRAINT fk_uma_user FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE",
        "ALTER TABLE user_sessions ADD CONSTRAINT fk_user_sessions_user FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE",
        "ALTER TABLE ar_email_reminders ADD CONSTRAINT fk_ar_email_sent_by FOREIGN KEY (sent_by) REFERENCES user(User_ID)",
        "ALTER TABLE ar_sms_reminders ADD CONSTRAINT fk_ar_sms_sent_by FOREIGN KEY (sent_by) REFERENCES user(User_ID)",
        "ALTER TABLE order_preparation_tasks ADD CONSTRAINT fk_opt_started_by FOREIGN KEY (started_by) REFERENCES user(User_ID)",
        "ALTER TABLE order_preparation_tasks ADD CONSTRAINT fk_opt_ready_by FOREIGN KEY (ready_by) REFERENCES user(User_ID)",
    ],

    // Batch 7: Add missing index
    'add_index' => [
        "ALTER TABLE delivery_detail ADD INDEX idx_dd_damage_id (Damage_ID)",
    ],
];

// --- Execution ---
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Migration: {$migration['name']}\n";
    echo str_repeat('-', 60) . "\n";

    // Helper to run SQL batches with error handling
    $runBatch = function (array $sqls, string $label) use ($pdo) {
        echo "\n--- {$label} ---\n";
        foreach ($sqls as $sql) {
            try {
                $pdo->exec($sql);
                echo "  ✓ " . substr($sql, 0, 80) . "...\n";
            } catch (PDOException $e) {
                // If constraint already exists or column already fixed, skip
                if (strpos($e->getMessage(), 'already exists') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false ||
                    strpos($e->getMessage(), 'check that column/key exists') !== false) {
                    echo "  ~ (already applied) " . substr($sql, 0, 60) . "...\n";
                } else {
                    throw $e;
                }
            }
        }
    };

    $runBatch($migration['drop_dup_fk'], 'Batch 1: Drop duplicate FKs');
    $runBatch($migration['enum_to_varchar'], 'Batch 2: ENUM→VARCHAR');
    $runBatch($migration['fix_collation'], 'Batch 3: Fix collations');
    $runBatch($migration['fix_bigint'], 'Batch 4: Fix BIGINT→INT UNSIGNED');
    $runBatch($migration['fix_signed_int'], 'Batch 5: Fix signed INT→INT UNSIGNED');
    $runBatch($migration['add_fk'], 'Batch 6: Add missing FK constraints');
    $runBatch($migration['add_index'], 'Batch 7: Add missing indexes');

    echo str_repeat('-', 60) . "\n";
    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
