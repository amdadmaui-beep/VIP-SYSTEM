<?php

require_once __DIR__ . '/roles_helper.php';

function cashSessionTableExists(PDO $conn, string $tableName): bool
{
    try {
        $stmt = $conn->query("SHOW TABLES LIKE " . $conn->quote($tableName));
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function cashSessionColumnExists(PDO $conn, string $tableName, string $columnName): bool
{
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE " . $conn->quote($columnName));
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ensureCashSessionWorkflowSchema(PDO $conn): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    if (cashSessionTableExists($conn, 'cash_shifts')) {
        $shiftColumns = [
            'expected_cash' => "ALTER TABLE cash_shifts ADD COLUMN expected_cash DECIMAL(12,2) NULL AFTER ending_cash",
            'discrepancy_amount' => "ALTER TABLE cash_shifts ADD COLUMN discrepancy_amount DECIMAL(12,2) NULL AFTER expected_cash",
            'tolerance_amount' => "ALTER TABLE cash_shifts ADD COLUMN tolerance_amount DECIMAL(12,2) NOT NULL DEFAULT 50.00 AFTER discrepancy_amount",
        ];

        foreach ($shiftColumns as $columnName => $sql) {
            if (!cashSessionColumnExists($conn, 'cash_shifts', $columnName)) {
                $conn->exec($sql);
            }
        }
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS shift_reviews (
            review_id INT AUTO_INCREMENT PRIMARY KEY,
            shift_id INT NOT NULL,
            review_status VARCHAR(50) NOT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            review_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_shift_reviews_shift FOREIGN KEY (shift_id) REFERENCES cash_shifts(shift_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS cash_shift_movements (
            movement_id INT AUTO_INCREMENT PRIMARY KEY,
            shift_id INT NOT NULL,
            movement_type ENUM('cash_in', 'cash_out') NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reason VARCHAR(255) NOT NULL,
            User_ID INT UNSIGNED NOT NULL,

            INDEX idx_user_entry (User_ID),
            CONSTRAINT fk_ensure_csm_user FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS cash_session_entries (
            entry_id INT AUTO_INCREMENT PRIMARY KEY,
            shift_id INT NOT NULL,
            entry_type VARCHAR(50) NOT NULL,
            source_label VARCHAR(100) NOT NULL,
            sale_id INT NULL,
            payment_id INT NULL,
            delivery_id INT NULL,
            order_id INT NULL,
            gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cash_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            change_given DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            User_ID INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_shift_entry (shift_id, entry_type, created_at),
            INDEX idx_sale_entry (sale_id),
            INDEX idx_payment_entry (payment_id),
            INDEX idx_delivery_entry (delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $initialized = true;
}

function getOpenCashShiftForUser(PDO $conn, int $userId): ?array
{
    ensureCashSessionWorkflowSchema($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM cash_shifts
        WHERE User_ID = ? AND status = 'Open'
        ORDER BY shift_start_time DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    return $shift ?: null;
}

function resolveManagerApproval(PDO $conn, string $pin)
{
    ensureCashSessionWorkflowSchema($conn);

    if ($pin === '') {
        return false;
    }

    $managementRoleIds = getManagementRoleIds($conn);
    if (empty($managementRoleIds)) {
        return false;
    }

    // Validate PIN from manager_pins and join with active management user
    $managementRoleIds = getManagementRoleIds($conn);
    $placeholders = implode(',', array_fill(0, count($managementRoleIds), '?'));
    
    $stmt = $conn->prepare("
        SELECT u.User_ID, u.full_name, u.user_name, u.Role_ID, mp.pin_hash
        FROM manager_pins mp
        JOIN user u ON mp.user_id = u.User_ID
        WHERE u.is_active = 1 
          AND mp.is_active = 1
          AND u.Role_ID IN ($placeholders)
    ");
    $stmt->execute($managementRoleIds);
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($managers as $m) {
        if (!empty($m['pin_hash']) && password_verify($pin, (string)$m['pin_hash'])) {
            return [
                'User_ID' => (int)$m['User_ID'],
                'full_name' => $m['full_name'],
                'user_name' => $m['user_name'],
                'Role_ID' => (int)$m['Role_ID'],
            ];
        }
    }

    return false;
}

function recordCashSessionEntry(PDO $conn, array $entry): void
{
    ensureCashSessionWorkflowSchema($conn);

    $stmt = $conn->prepare("
        INSERT INTO cash_session_entries (
            shift_id,
            entry_type,
            source_label,
            sale_id,
            payment_id,
            delivery_id,
            order_id,
            gross_amount,
            cash_received,
            change_given,
            net_cash,
            User_ID
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (int) ($entry['shift_id'] ?? 0),
        (string) ($entry['entry_type'] ?? 'unknown'),
        (string) ($entry['source_label'] ?? 'Unknown Source'),
        isset($entry['sale_id']) ? (int) $entry['sale_id'] : null,
        isset($entry['payment_id']) ? (int) $entry['payment_id'] : null,
        isset($entry['delivery_id']) ? (int) $entry['delivery_id'] : null,
        isset($entry['order_id']) ? (int) $entry['order_id'] : null,
        round((float) ($entry['gross_amount'] ?? 0), 2),
        round((float) ($entry['cash_received'] ?? 0), 2),
        round((float) ($entry['change_given'] ?? 0), 2),
        round((float) ($entry['net_cash'] ?? 0), 2),
        (int) ($entry['User_ID'] ?? 0),
    ]);
}

function recordCashShiftMovement(PDO $conn, int $shiftId, string $movementType, float $amount, string $reason, int $recordedBy): void
{
    ensureCashSessionWorkflowSchema($conn);

    $stmt = $conn->prepare("
        INSERT INTO cash_shift_movements (shift_id, movement_type, amount, reason, User_ID)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $shiftId,
        $movementType,
        round($amount, 2),
        mb_substr(trim($reason), 0, 255),
        $recordedBy,
    ]);
}

function classifyCashShiftDiscrepancy(float $variance, float $toleranceAmount): array
{
    if (abs($variance) < 0.00001) {
        return [
            'review_status' => 'Balanced',
            'review_notes' => 'No discrepancy detected.',
        ];
    }

    if (abs($variance) <= $toleranceAmount) {
        return [
            'review_status' => 'Approved Minor Variance',
            'review_notes' => 'Discrepancy is within the allowed tolerance.',
        ];
    }

    return [
        'review_status' => 'Flagged for Review',
        'review_notes' => 'Discrepancy exceeded the allowed tolerance and requires follow-up.',
    ];
}

function calculateShiftTotalsDetailed(PDO $conn, int $shiftId): array
{
    ensureCashSessionWorkflowSchema($conn);

    $totals = [
        'total_count' => 0,
        'gross_sales' => 0.0,
        'cash_sales' => 0.0,
        'credit_sales' => 0.0,
        'void_count' => 0,
        'void_amount' => 0.0,
        'walk_in_cash' => 0.0,
        'delivery_remittance_cash' => 0.0,
        'ar_collection_cash' => 0.0,
        'cash_in_total' => 0.0,
        'cash_out_total' => 0.0,
        'cash_received_total' => 0.0,
        'change_given_total' => 0.0,
        'net_sales_cash' => 0.0,
        'expected_cash' => 0.0,
        'source_breakdown' => [],
    ];

    $shiftStmt = $conn->prepare("SELECT * FROM cash_shifts WHERE shift_id = ? LIMIT 1");
    $shiftStmt->execute([$shiftId]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        return $totals;
    }

    $entryStmt = $conn->prepare("
        SELECT
            entry_type,
            source_label,
            COUNT(*) AS entry_count,
            COALESCE(SUM(gross_amount), 0) AS gross_amount,
            COALESCE(SUM(cash_received), 0) AS cash_received,
            COALESCE(SUM(change_given), 0) AS change_given,
            COALESCE(SUM(net_cash), 0) AS net_cash
        FROM cash_session_entries
        WHERE shift_id = ?
        GROUP BY entry_type, source_label
        ORDER BY source_label ASC
    ");
    $entryStmt->execute([$shiftId]);
    $entryRows = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($entryRows as $row) {
        $entryType = (string) ($row['entry_type'] ?? '');
        $grossAmount = (float) ($row['gross_amount'] ?? 0);
        $cashReceived = (float) ($row['cash_received'] ?? 0);
        $changeGiven = (float) ($row['change_given'] ?? 0);
        $netCash = (float) ($row['net_cash'] ?? 0);
        $entryCount = (int) ($row['entry_count'] ?? 0);

        $totals['source_breakdown'][] = [
            'entry_type' => $entryType,
            'source_label' => (string) ($row['source_label'] ?? 'Unknown Source'),
            'entry_count' => $entryCount,
            'gross_amount' => $grossAmount,
            'cash_received' => $cashReceived,
            'change_given' => $changeGiven,
            'net_cash' => $netCash,
        ];

        if (in_array($entryType, ['walk_in_sale', 'delivery_remittance'], true)) {
            $totals['total_count'] += $entryCount;
            $totals['gross_sales'] += $grossAmount;
            $totals['cash_received_total'] += $cashReceived;
            $totals['change_given_total'] += $changeGiven;
            $totals['net_sales_cash'] += $netCash;
            $totals['cash_sales'] += $netCash;
            $totals['credit_sales'] += max(0, $grossAmount - $netCash);
        }

        if ($entryType === 'walk_in_sale') {
            $totals['walk_in_cash'] += $netCash;
        } elseif ($entryType === 'delivery_remittance') {
            $totals['delivery_remittance_cash'] += $netCash;
        } elseif ($entryType === 'ar_collection') {
            $totals['ar_collection_cash'] += $netCash;
        }
    }

    $movementStmt = $conn->prepare("
        SELECT movement_type, COALESCE(SUM(amount), 0) AS total_amount
        FROM cash_shift_movements
        WHERE shift_id = ?
        GROUP BY movement_type
    ");
    $movementStmt->execute([$shiftId]);
    foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $movementType = (string) ($row['movement_type'] ?? '');
        $amount = (float) ($row['total_amount'] ?? 0);
        if ($movementType === 'cash_in') {
            $totals['cash_in_total'] += $amount;
        } elseif ($movementType === 'cash_out') {
            $totals['cash_out_total'] += $amount;
        }
    }

    try {
        $salesSummaryStmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN s.status = 'Voided' THEN s.Sale_ID END) AS void_count,
                COALESCE(SUM(CASE WHEN s.status = 'Voided' THEN details.sale_total ELSE 0 END), 0) AS void_amount
            FROM sales s
            LEFT JOIN (
                SELECT Sale_ID, COALESCE(SUM(subtotal), 0) AS sale_total
                FROM sale_details
                GROUP BY Sale_ID
            ) details ON details.Sale_ID = s.Sale_ID
            WHERE s.created_at >= ?
              AND s.created_at <= COALESCE(?, NOW())
              AND COALESCE(s.User_ID, 0) = ?
        ");
        $salesSummaryStmt->execute([
            $shift['shift_start_time'],
            $shift['shift_end_time'] ?? null,
            $shift['User_ID'],
        ]);
        $salesSummary = $salesSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totals['void_count'] = (int) ($salesSummary['void_count'] ?? 0);
        $totals['void_amount'] = (float) ($salesSummary['void_amount'] ?? 0);
    } catch (Throwable $e) {
        $totals['void_count'] = 0;
        $totals['void_amount'] = 0.0;
    }

    $startingCash = (float) ($shift['starting_cash'] ?? 0);
    $totals['expected_cash'] = round(
        $startingCash
        + $totals['cash_sales']
        + $totals['ar_collection_cash']
        + $totals['cash_in_total']
        - $totals['cash_out_total'],
        2
    );

    return $totals;
}
