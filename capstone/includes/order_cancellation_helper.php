<?php
declare(strict_types=1);

if (!function_exists('vipParseOrderEnumValues')) {
    function vipParseOrderEnumValues(string $columnType): array
    {
        if (!preg_match("/^enum\((.*)\)$/i", trim($columnType), $matches)) {
            return [];
        }

        $inner = $matches[1];
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $inner, $valueMatches);
        $values = [];
        foreach ($valueMatches[1] ?? [] as $rawValue) {
            $values[] = stripcslashes($rawValue);
        }

        return array_values(array_filter($values, static function ($value) {
            return $value !== '';
        }));
    }
}

if (!function_exists('getOrderCancellationDefaultOptions')) {
    function getOrderCancellationDefaultOptions(): array
    {
        return [
            'Customer Change Mind',
            'No Longer Needed',
            'Others',
        ];
    }
}

if (!function_exists('getOrderCancellationReasonMigrationMap')) {
    function getOrderCancellationReasonMigrationMap(): array
    {
        return [
            'CUSTOMER_CHANGED_MIND' => 'Customer Change Mind',
            'NO_LONGER_NEEDED' => 'No Longer Needed',
            'OTHERS' => 'Others',
            'Customer unavailable' => 'No Longer Needed',
            'Customer requested cancellation' => 'Customer Change Mind',
            'Unable to contact customer' => 'No Longer Needed',
            'Other' => 'Others',
        ];
    }
}

if (!function_exists('ensureOrderCancellationSchema')) {
    function ensureOrderCancellationSchema(PDO $conn): void
    {
        $desiredOptions = getOrderCancellationDefaultOptions();
        $desiredEnumSql = "'" . implode("','", array_map(static function ($value) {
            return str_replace("'", "\\'", $value);
        }, $desiredOptions)) . "'";

        $columns = [];
        $stmt = $conn->query("SHOW COLUMNS FROM orders");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[(string)($column['Field'] ?? '')] = (string)($column['Type'] ?? '');
            }
        }

        if (!isset($columns['cancellation_reason'])) {
            $afterReason = isset($columns['cancelled_at']) ? ' AFTER cancelled_at' : '';
            // Use VARCHAR to avoid MySQL ENUM strict-mode migration failures on existing data.
            $conn->exec("ALTER TABLE orders ADD COLUMN cancellation_reason VARCHAR(255) NULL{$afterReason}");
        } else {
            $currentOptions = vipParseOrderEnumValues((string)$columns['cancellation_reason']);
            if ($currentOptions !== $desiredOptions) {
                // Convert to VARCHAR and keep it as VARCHAR to avoid runtime fatals caused by ENUM coercion.
                $conn->exec("ALTER TABLE orders MODIFY COLUMN cancellation_reason VARCHAR(255) NULL");

                try {
                    $migrateStmt = $conn->prepare("UPDATE orders SET cancellation_reason = ? WHERE TRIM(cancellation_reason) = ?");
                    foreach (getOrderCancellationReasonMigrationMap() as $oldValue => $newValue) {
                        $migrateStmt->execute([$newValue, $oldValue]);
                    }
                    $fallback = getOrderCancellationDefaultOptions()[0] ?? 'Customer Change Mind';
                    $normalizeStmt = $conn->prepare("UPDATE orders SET cancellation_reason = ? WHERE cancellation_reason IS NOT NULL AND TRIM(cancellation_reason) NOT IN ({$desiredEnumSql})");
                    $normalizeStmt->execute([$fallback]);
                } catch (Throwable $e) {
                    error_log("Order cancellation migration failed: " . $e->getMessage());
                }
            }
        }

        if (!isset($columns['cancellation_remarks'])) {
            $afterRemarks = isset($columns['cancellation_reason']) ? ' AFTER cancellation_reason' : '';
            $conn->exec("ALTER TABLE orders ADD COLUMN cancellation_remarks VARCHAR(255) NULL{$afterRemarks}");
        }
    }
}

if (!function_exists('getOrderCancellationReasonOptions')) {
    function getOrderCancellationReasonOptions(PDO $conn): array
    {
        ensureOrderCancellationSchema($conn);
        $stmt = $conn->query("SHOW COLUMNS FROM orders LIKE 'cancellation_reason'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            return [];
        }

        $options = vipParseOrderEnumValues((string)($column['Type'] ?? ''));
        return !empty($options) ? $options : getOrderCancellationDefaultOptions();
    }
}

if (!function_exists('normalizeOrderCancellationReasonValue')) {
    function normalizeOrderCancellationReasonValue(PDO $conn, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $options = getOrderCancellationReasonOptions($conn);
        foreach ($options as $option) {
            if (strcasecmp($option, $value) === 0) {
                return $option;
            }
        }

        return '';
    }
}
