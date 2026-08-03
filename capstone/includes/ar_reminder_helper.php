<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function arReminderEnsureTracking(PDO $conn): void
{
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ar_email_reminders (
            reminder_id INT AUTO_INCREMENT PRIMARY KEY,
            AR_ID INT NOT NULL,
            customer_email VARCHAR(191) NOT NULL,
            reminder_type VARCHAR(40) NOT NULL DEFAULT 'manual',
            due_date_snapshot DATE NULL,
            amount_due_snapshot DECIMAL(12,2) NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_by INT NULL,
            INDEX idx_ar_sent_at (AR_ID, sent_at),
            INDEX idx_ar_type_due (AR_ID, reminder_type, due_date_snapshot)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = $conn->query("SHOW COLUMNS FROM ar_email_reminders")->fetchAll(PDO::FETCH_COLUMN);
    $missing = [
        'reminder_type' => "ALTER TABLE ar_email_reminders ADD COLUMN reminder_type VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER customer_email",
        'due_date_snapshot' => "ALTER TABLE ar_email_reminders ADD COLUMN due_date_snapshot DATE NULL AFTER reminder_type",
        'amount_due_snapshot' => "ALTER TABLE ar_email_reminders ADD COLUMN amount_due_snapshot DECIMAL(12,2) NULL AFTER due_date_snapshot",
    ];

    foreach ($missing as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $conn->exec($sql);
        }
    }

    try {
        $conn->exec("CREATE INDEX idx_ar_type_due ON ar_email_reminders (AR_ID, reminder_type, due_date_snapshot)");
    } catch (Throwable $e) {
        // Index already exists on most databases. Tracking still works without raising.
    }
}

function arReminderRecordEmail(
    PDO $conn,
    int $arId,
    string $customerEmail,
    string $reminderType = 'manual',
    ?string $dueDate = null,
    ?float $amountDue = null,
    ?int $sentBy = null
): void {
    arReminderEnsureTracking($conn);

    $stmt = $conn->prepare("
        INSERT INTO ar_email_reminders
            (AR_ID, customer_email, reminder_type, due_date_snapshot, amount_due_snapshot, sent_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $arId,
        $customerEmail,
        $reminderType,
        $dueDate ?: null,
        $amountDue,
        $sentBy,
    ]);
}

function arReminderSendEmailForRow(PDO $conn, array $row, string $reminderType, ?int $sentBy = null): array
{
    $arId = (int)($row['AR_ID'] ?? 0);
    $email = trim((string)($row['email'] ?? ''));
    $customerName = (string)($row['customer_name'] ?? 'Customer');
    $amountDue = (float)($row['amount_due'] ?? 0);
    $dueDate = (string)($row['due_date'] ?? '');

    if ($arId <= 0 || $amountDue <= 0 || $dueDate === '') {
        return ['ok' => false, 'message' => 'Invalid AR reminder payload.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Customer has no valid email address.'];
    }

    $send = sendARBalanceReminderEmail($email, $customerName, $arId, $amountDue, $dueDate);
    if (!($send['ok'] ?? false)) {
        return $send;
    }

    arReminderRecordEmail($conn, $arId, $email, $reminderType, $dueDate, $amountDue, $sentBy);

    return ['ok' => true, 'message' => 'Reminder email sent successfully.'];
}

function arReminderSendDueReminders(PDO $conn, ?string $targetDueDate = null, int $limit = 200): array
{
    arReminderEnsureTracking($conn);

    $targetDueDate = $targetDueDate ?: date('Y-m-d', strtotime('+7 days'));
    $limit = max(1, min(1000, $limit));
    $reminderType = 'week_before_due';

    $stmt = $conn->prepare("
        SELECT ar.AR_ID, ar.amount_due, ar.due_date, c.customer_name, c.email
        FROM account_receivable ar
        LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
        LEFT JOIN (
            SELECT AR_ID, due_date_snapshot, MAX(sent_at) AS last_sent_at
            FROM ar_email_reminders
            WHERE reminder_type = ?
            GROUP BY AR_ID, due_date_snapshot
        ) sent ON sent.AR_ID = ar.AR_ID AND sent.due_date_snapshot = ar.due_date
        WHERE ar.status NOT IN ('Paid', 'Closed')
          AND ar.amount_due > 0
          AND ar.due_date = ?
          AND sent.last_sent_at IS NULL
        ORDER BY ar.due_date ASC, ar.AR_ID ASC
        LIMIT {$limit}
    ");
    $stmt->execute([$reminderType, $targetDueDate]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'success' => true,
        'target_due_date' => $targetDueDate,
        'checked' => count($records),
        'sent' => 0,
        'failed' => 0,
        'failures' => [],
    ];

    foreach ($records as $record) {
        $send = arReminderSendEmailForRow($conn, $record, $reminderType, null);
        if ($send['ok'] ?? false) {
            $result['sent']++;
            continue;
        }

        $result['failed']++;
        $result['failures'][] = [
            'ar_id' => (int)($record['AR_ID'] ?? 0),
            'message' => (string)($send['message'] ?? 'Failed to send reminder.'),
        ];
    }

    return $result;
}
