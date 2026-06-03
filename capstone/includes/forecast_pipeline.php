<?php
declare(strict_types=1);

/**
 * Shared forecast auth, DB training pull, Python/PHP pipeline, JSON output.
 */

require_once __DIR__ . '/roles_helper.php';
require_once __DIR__ . '/forecast_php_fallback.php';

function vip_forecast_user_allowed(PDO $conn): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $role = (int) ($_SESSION['user_role'] ?? 0);
    if (in_array($role, [1, 2, 4], true)) {
        return true;
    }
    $mgmt = getManagementRoleIds($conn);

    return $mgmt !== [] && in_array($role, $mgmt, true);
}

/**
 * Daily total bags (sum of line quantities) + revenue from live sales.
 *
 * @return array<int, array{date: string, total_bags: float, revenue: float}>
 */
function vip_forecast_daily_demand_from_sales_db(PDO $conn, int $days): array
{
    $daysSql = max(1, min((int) $days, 366));
    $tablesRes = $conn->query('SHOW TABLES');
    if ($tablesRes === false) {
        return [];
    }
    $tables = array_map('strtolower', $tablesRes->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!in_array('sales', $tables, true) || !in_array('sale_details', $tables, true)) {
        return [];
    }

    $sql = "SELECT DATE(s.created_at) AS d,
            COALESCE(SUM(sd.quantity), 0) AS total_bags,
            COALESCE(SUM(sd.subtotal), 0) AS revenue
            FROM sales s
            INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
            WHERE s.created_at >= DATE_SUB(CURDATE(), INTERVAL {$daysSql} DAY)";
    $colsRes = $conn->query('SHOW COLUMNS FROM sales');
    $cols = $colsRes ? $colsRes->fetchAll(PDO::FETCH_COLUMN) : [];
    if (in_array('status', $cols, true)) {
        $sql .= " AND (s.status IS NULL OR s.status != 'Cancelled')";
    }
    $sql .= ' GROUP BY DATE(s.created_at) ORDER BY d ASC';

    $stmt = $conn->prepare($sql);
    if ($stmt === false || !$stmt->execute()) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $daily = [];
    foreach ($rows as $r) {
        $d = (string) ($r['d'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) {
            continue;
        }
        $daily[] = [
            'date' => substr($d, 0, 10),
            'total_bags' => round((float) ($r['total_bags'] ?? 0), 2),
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
        ];
    }

    return $daily;
}

/**
 * @deprecated Use vip_forecast_daily_demand_from_sales_db(); kept for narrow compatibility.
 *
 * @return array<int, array{date: string, revenue: float}>
 */
function vip_forecast_daily_from_sales_db(PDO $conn, int $days): array
{
    $demand = vip_forecast_daily_demand_from_sales_db($conn, $days);

    return array_map(static function (array $r): array {
        return [
            'date' => $r['date'],
            'revenue' => $r['revenue'],
        ];
    }, $demand);
}

/**
 * Parse CSV: date + total_bags (or bags/qty) + optional revenue.
 *
 * @return array<int, array{date: string, total_bags: float, revenue: float}>
 */
function vip_forecast_parse_daily_csv(string $raw): array
{
    $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw);
    $lines = preg_split("/\r\n|\r|\n/", $raw);
    $daily = [];
    $dateCol = 0;
    $totalBagsCol = -1;
    $revCol = -1;
    $headerDone = false;

    $parseNum = static function (array $row, int $col): float {
        if ($col < 0 || !isset($row[$col])) {
            return 0.0;
        }

        return max(0.0, (float) str_replace([',', '₱', ' '], '', (string) $row[$col]));
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $row = str_getcsv($line);
        if ($row === false || $row === []) {
            continue;
        }

        if (!$headerDone) {
            $first = trim((string) ($row[0] ?? ''));
            $firstLooksLikeDate = (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $first);
            if ($firstLooksLikeDate) {
                $dateCol = 0;
                $revCol = count($row) >= 2 ? 1 : -1;
                $totalBagsCol = -1;
            } else {
                foreach ($row as $ci => $cell) {
                    $h = strtolower(trim((string) $cell));
                    if (in_array($h, ['date', 'day', 'sale_date', 'd'], true)) {
                        $dateCol = $ci;
                    }
                    if (in_array($h, ['total_bags', 'bags', 'total bags', 'qty', 'quantity'], true)) {
                        $totalBagsCol = $ci;
                    }
                    if (in_array($h, ['revenue', 'amount', 'total', 'sales', 'subtotal'], true)) {
                        $revCol = $ci;
                    }
                }
            }
            $headerDone = true;
            if (!$firstLooksLikeDate) {
                continue;
            }
        }

        $ds = isset($row[$dateCol]) ? trim((string) $row[$dateCol]) : '';
        $ds = preg_replace('/T.*$/', '', $ds);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $ds)) {
            continue;
        }
        $ds = substr($ds, 0, 10);

        $rev = $parseNum($row, $revCol);
        $tb = $totalBagsCol >= 0 ? $parseNum($row, $totalBagsCol) : 0.0;
        if ($tb <= 0 && $rev > 0) {
            $tb = max(0.0, round($rev / 25.0, 2));
        }

        $daily[] = [
            'date' => $ds,
            'total_bags' => round($tb, 2),
            'revenue' => round($rev, 2),
        ];
    }

    return $daily;
}

/**
 * @return array{ok: bool, stdout?: string, error?: string}
 */
function vip_run_forecast_python(string $jsonIn): array
{
    $script = realpath(__DIR__ . '/../scripts/forecast/run_forecast.py');
    if (!$script || !is_file($script)) {
        return ['ok' => false, 'error' => 'Missing run_forecast.py'];
    }

    $candidates = [];
    $envPy = getenv('VIP_PYTHON');
    if ($envPy !== false && $envPy !== '' && is_file($envPy)) {
        $candidates[] = [$envPy, $script];
    }
    if (PHP_OS_FAMILY === 'Windows') {
        // Prefer real installs — PATH often has WindowsApps\python.exe (Store stub) first.
        $local = getenv('LOCALAPPDATA');
        if ($local !== false && $local !== '') {
            $pyRoot = $local . DIRECTORY_SEPARATOR . 'Programs' . DIRECTORY_SEPARATOR . 'Python';
            if (is_dir($pyRoot)) {
                foreach (['Python313', 'Python312', 'Python311', 'Python310', 'Python39'] as $dir) {
                    $exe = $pyRoot . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'python.exe';
                    if (is_file($exe)) {
                        $candidates[] = [$exe, $script];
                    }
                }
            }
        }
        $pf = getenv('ProgramFiles');
        if ($pf !== false && $pf !== '') {
            foreach (['Python313', 'Python312', 'Python311', 'Python310', 'Python39'] as $dir) {
                $exe = $pf . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'python.exe';
                if (is_file($exe)) {
                    $candidates[] = [$exe, $script];
                }
            }
        }
        $candidates[] = ['py', '-3', $script];
        $candidates[] = ['python', $script];
        $candidates[] = ['python3', $script];
    } else {
        $candidates[] = ['python3', $script];
        $candidates[] = ['python', $script];
    }

    $seen = [];
    $unique = [];
    foreach ($candidates as $cmd) {
        $key = implode("\0", $cmd);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $cmd;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cwd = dirname($script);
    $proc = null;
    $pipes = null;
    foreach ($unique as $cmd) {
        try {
            $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, null);
        } catch (Throwable $e) {
            $proc = null;
            continue;
        }
        if (is_resource($proc)) {
            break;
        }
        $proc = null;
    }

    if (!is_resource($proc) || !is_array($pipes)) {
        return [
            'ok' => false,
            'error' => 'Python not found. Install from python.org or winget, or set VIP_PYTHON to the full path of python.exe (avoid the Microsoft Store stub in PATH).',
        ];
    }

    try {
        fwrite($pipes[0], $jsonIn);
        fclose($pipes[0]);
        $pipes[0] = null;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes[1] = $pipes[2] = null;
        $code = proc_close($proc);
        $proc = null;
    } catch (Throwable $e) {
        if (is_array($pipes)) {
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    @fclose($p);
                }
            }
        }
        if (is_resource($proc)) {
            @proc_close($proc);
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if ($code !== 0) {
        return [
            'ok' => false,
            'error' => trim((string) $stderr) ?: ('Python exited ' . $code),
        ];
    }

    return ['ok' => true, 'stdout' => (string) $stdout];
}

/**
 * Run Prophet (Python) then PHP demand fallback.
 *
 * @param array<int, array{date: string, total_bags?: float, revenue?: float}> $demandDaily
 *
 * @return array<string, mixed>
 */
function vip_forecast_pipeline(array $demandDaily, int $horizonW, int $horizonM, int $horizonDays = 14): array
{
    $horizonW = max(1, min($horizonW, 12));
    $horizonM = max(1, min($horizonM, 12));
    $horizonDays = max(1, min($horizonDays, 90));

    $normalized = [];
    foreach ($demandDaily as $r) {
        $normalized[] = [
            'date' => (string) ($r['date'] ?? ''),
            'total_bags' => (float) ($r['total_bags'] ?? 0),
            'revenue' => (float) ($r['revenue'] ?? 0),
        ];
    }

    $payload = json_encode([
        'daily' => $normalized,
        'horizon_weeks' => $horizonW,
        'horizon_months' => $horizonM,
        'horizon_days' => $horizonDays,
    ]);
    if ($payload === false) {
        return ['success' => false, 'error' => 'Could not build forecast payload.'];
    }

    $result = vip_run_forecast_python($payload);
    $decoded = null;
    if (($result['ok'] ?? false) && ($result['stdout'] ?? '') !== '') {
        $decoded = json_decode((string) $result['stdout'], true);
    }

    if (is_array($decoded) && !empty($decoded['success'])) {
        $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
        if (empty($meta['engine'])) {
            $meta['engine'] = 'prophet';
        }
        $decoded['meta'] = $meta;

        return $decoded;
    }

    $pyErr = is_array($decoded) && !empty($decoded['error'])
        ? (string) $decoded['error']
        : ($result['error'] ?? 'Prophet did not return a valid forecast.');

    return [
        'success' => false,
        'error' => 'Prophet forecast required (PHP linear fallback disabled).',
        'details' => $pyErr,
        'hint' => 'Install Python 3.9+, run: pip install -r capstone/scripts/forecast/requirements.txt. On Windows set VIP_PYTHON to python.exe if needed. Ensure at least 2 days of non-zero bag history.',
    ];
}

function vip_forecast_json_out(array $data): void
{
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $j = json_encode($data, $flags);
    if ($j === false) {
        echo json_encode(['success' => false, 'error' => 'JSON encoding failed.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo $j;
}

/**
 * Build a single CSV string: training + weekly + monthly sections.
 *
 * @param array<string, mixed> $forecastPayload success payload from pipeline
 */
function vip_forecast_bundle_to_csv(array $forecastPayload, string $sourceLabel): string
{
    $lines = [];
    $lines[] = '# VIP Ice Plant — forecast export';
    $lines[] = '# source,' . vip_csv_escape($sourceLabel);
    $lines[] = '';
    $lines[] = '## daily_training';
    $lines[] = 'date,total_bags,revenue';
    foreach ($forecastPayload['daily'] ?? [] as $r) {
        $lines[] = vip_csv_escape($r['date'] ?? '') . ','
            . vip_csv_escape((string) ($r['total_bags'] ?? '')) . ','
            . vip_csv_escape((string) ($r['revenue'] ?? ''));
    }
    $lines[] = '';
    $lines[] = '## weekly';
    $lines[] = 'row_type,period_start,revenue_actual,forecast_point,forecast_low,forecast_high';
    foreach ($forecastPayload['weekly']['history'] ?? [] as $r) {
        $bags = $r['bags'] ?? $r['revenue'] ?? '';
        $lines[] = 'history,' . vip_csv_escape($r['period_start'] ?? '') . ',' . vip_csv_escape((string) $bags) . ',,,';
    }
    foreach ($forecastPayload['weekly']['forecast'] ?? [] as $r) {
        $lines[] = 'forecast,' . vip_csv_escape($r['period_start'] ?? '') . ',,' . vip_csv_escape((string) ($r['yhat'] ?? '')) . ',' . vip_csv_escape((string) ($r['yhat_low'] ?? '')) . ',' . vip_csv_escape((string) ($r['yhat_high'] ?? ''));
    }
    $lines[] = '';
    $lines[] = '## monthly';
    $lines[] = 'row_type,month,revenue_actual,forecast_point,forecast_low,forecast_high';
    foreach ($forecastPayload['monthly']['history'] ?? [] as $r) {
        $bags = $r['bags'] ?? $r['revenue'] ?? '';
        $lines[] = 'history,' . vip_csv_escape($r['month'] ?? '') . ',' . vip_csv_escape((string) $bags) . ',,,';
    }
    foreach ($forecastPayload['monthly']['forecast'] ?? [] as $r) {
        $lines[] = 'forecast,' . vip_csv_escape($r['month'] ?? '') . ',,' . vip_csv_escape((string) ($r['yhat'] ?? '')) . ',' . vip_csv_escape((string) ($r['yhat_low'] ?? '')) . ',' . vip_csv_escape((string) ($r['yhat_high'] ?? ''));
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

function vip_csv_escape(string $s): string
{
    if (strpos($s, '"') !== false || strpos($s, ',') !== false || strpos($s, "\n") !== false) {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    return $s;
}
