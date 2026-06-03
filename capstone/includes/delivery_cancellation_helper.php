<?php
declare(strict_types=1);

if (!function_exists('vipParseEnumValues')) {
    function vipParseEnumValues(string $columnType): array
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

if (!function_exists('getDeliveryCancellationDefaultReasons')) {
    function getDeliveryCancellationDefaultReasons(): array
    {
        return [
            'Customer unavailable',
            'Customer requested cancellation',
            'Wrong address',
            'Vehicle issue',
            'Reschedule',
            'Other',
        ];
    }
}

if (!function_exists('getManagerCancellationReasons')) {
    function getManagerCancellationReasons(): array
    {
        return [
            'Customer unavailable',
            'Reschedule',
            'Customer Change Mind',
            'No Longer Needed',
        ];
    }
}

if (!function_exists('ensureDeliveryCancellationSchema')) {
    function ensureDeliveryCancellationSchema(PDO $conn): void
    {
        $desiredOptions = getDeliveryCancellationDefaultReasons();

        $columns = [];
        $stmt = $conn->query("SHOW COLUMNS FROM delivery");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[(string)($column['Field'] ?? '')] = (string)($column['Type'] ?? '');
            }
        }

        if (!isset($columns['cancellation_reason'])) {
            $conn->exec(
                "ALTER TABLE delivery
                 ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER delivery_status"
            );
        } else {
            $type = (string)($columns['cancellation_reason'] ?? '');
            if (stripos($type, 'enum') === 0) {
                $conn->exec("ALTER TABLE delivery MODIFY COLUMN cancellation_reason VARCHAR(255) NULL");
            }
        }

        if (!isset($columns['cancellation_remarks'])) {
            $conn->exec("ALTER TABLE delivery ADD COLUMN cancellation_remarks VARCHAR(255) NULL AFTER cancellation_reason");
        }

        // Cleanup: remove is_permanent_cancel column if it was created by a previous version
        if (isset($columns['is_permanent_cancel'])) {
            try {
                $conn->exec("ALTER TABLE delivery DROP COLUMN is_permanent_cancel");
            } catch (Throwable $e) {
                // Best effort cleanup
            }
        }
    }
}

if (!function_exists('getDeliveryCancellationReasonOptions')) {
    function getDeliveryCancellationReasonOptions(PDO $conn): array
    {
        ensureDeliveryCancellationSchema($conn);
        return getDeliveryCancellationDefaultReasons();
    }
}
