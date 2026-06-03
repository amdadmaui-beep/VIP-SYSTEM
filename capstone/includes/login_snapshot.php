<?php
declare(strict_types=1);

/**
 * Optional cold-storage display (°C). Set in config or here if you track temperature elsewhere.
 * When null, the login page hides the TEMP line.
 */
if (!defined('VIP_LOGIN_COLD_STORAGE_C')) {
    define('VIP_LOGIN_COLD_STORAGE_C', null);
}

function vip_db_table_exists(PDO $conn, string $table): bool
{
    try {
        $st = $conn->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Real metrics for the public login screen (no auth required).
 *
 * @return array{
 *   db_time:?string,
 *   production_tonnes_today:?float,
 *   production_bags_today:?int,
 *   production_rate_tph:?float,
 *   deliveries_today:?int,
 *   cold_storage_c:?float
 * }
 */
function vip_login_snapshot(PDO $conn): array
{
    $out = [
        'db_time' => null,
        'production_tonnes_today' => null,
        'production_bags_today' => null,
        'production_rate_tph' => null,
        'deliveries_today' => null,
        'cold_storage_c' => VIP_LOGIN_COLD_STORAGE_C,
    ];

    try {
        $out['db_time'] = (string) $conn->query('SELECT DATE_FORMAT(NOW(), \'%Y-%m-%d %H:%i:%s\')')->fetchColumn();

        if (vip_db_table_exists($conn, 'productions')) {
            $cols = $conn->query('SHOW COLUMNS FROM productions')->fetchAll(PDO::FETCH_COLUMN);
            $hasProduced = in_array('produced_qty', $cols, true);
            $hasBags = in_array('number_of_bags', $cols, true);
            if ($hasProduced) {
                $kg = (float) $conn->query(
                    'SELECT COALESCE(SUM(produced_qty), 0) FROM productions WHERE DATE(production_date) = CURDATE()'
                )->fetchColumn();
                $tonnes = round($kg / 1000, 2);
                $out['production_tonnes_today'] = $tonnes;
                if ($tonnes > 0) {
                    $elapsed = max(3600, time() - strtotime('today'));
                    $hours = $elapsed / 3600;
                    $out['production_rate_tph'] = round($tonnes / $hours, 2);
                }
            } elseif ($hasBags) {
                $bags = (int) $conn->query(
                    'SELECT COALESCE(SUM(number_of_bags), 0) FROM productions WHERE DATE(production_date) = CURDATE()'
                )->fetchColumn();
                $out['production_bags_today'] = $bags;
            }
        }

        if (vip_db_table_exists($conn, 'delivery')) {
            $cnt = (int) $conn->query(
                'SELECT COUNT(*) FROM delivery WHERE DATE(COALESCE(schedule_date, actual_date_arrived, created_at)) = CURDATE()'
            )->fetchColumn();
            $out['deliveries_today'] = $cnt;
        }
    } catch (Throwable $e) {
        // Leave nulls; page still loads.
    }

    return $out;
}
