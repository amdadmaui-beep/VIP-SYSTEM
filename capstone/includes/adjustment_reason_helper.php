<?php
declare(strict_types=1);

function getAdjustmentReasonOptions(PDO $conn): array
{
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM adjustment_details LIKE 'reason'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $typeDefinition = (string)($column['Type'] ?? '');

        if ($typeDefinition !== '' && preg_match("/^enum\\((.*)\\)$/i", $typeDefinition, $matches)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $enumMatches);
            $options = array_values(array_filter(array_map('stripcslashes', $enumMatches[1] ?? []), static function ($value) {
                return $value !== '';
            }));

            if (!empty($options)) {
                return $options;
            }
        }

        // VARCHAR column or non-ENUM: return curated list
        return ['Melted', 'Damage Packaging', 'Contaminated', 'Physical Discrepancy', 'Others'];
    } catch (Throwable $e) {
        // Leave empty when schema metadata is unavailable.
    }

    return [];
}

function normalizeAdjustmentReasonValue(PDO $conn, string $reason): string
{
    $reason = trim($reason);
    if ($reason === '') {
        return '';
    }

    foreach (getAdjustmentReasonOptions($conn) as $option) {
        if (strcasecmp($option, $reason) === 0) {
            return $option;
        }
    }

    return $reason;
}

function isAdjustmentOtherReason(string $reason): bool
{
    return strcasecmp(trim($reason), 'Others') === 0;
}

function normalizeAdjustmentNotes(string $notes): string
{
    $notes = trim($notes);
    return $notes === 'Manual inventory adjustment' ? '' : $notes;
}
