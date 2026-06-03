<?php
declare(strict_types=1);

/**
 * Lightweight forecast when Python/statsmodels is unavailable.
 * Mirrors the JSON shape produced by scripts/forecast/run_forecast.py enough for the UI.
 */
function vip_forecast_std_sample(array $values): float
{
    $n = count($values);
    if ($n < 2) {
        return max((float) (end($values) ?: 0) * 0.12, 1.0);
    }
    $mean = array_sum($values) / $n;
    $v = 0.0;
    foreach ($values as $x) {
        $v += ($x - $mean) ** 2;
    }

    return sqrt($v / ($n - 1));
}

/**
 * @param float[] $y
 * @return float[]
 */
function vip_linear_extrapolate(array $y, int $steps): array
{
    $n = count($y);
    if ($n === 0) {
        return array_fill(0, $steps, 0.0);
    }
    if ($n === 1) {
        $v = max(0.0, (float) $y[0]);

        return array_fill(0, $steps, $v);
    }
    $sumX = 0.0;
    $sumY = 0.0;
    $sumXY = 0.0;
    $sumX2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sumX += $i;
        $sumY += $y[$i];
        $sumXY += $i * $y[$i];
        $sumX2 += $i * $i;
    }
    $denom = $n * $sumX2 - $sumX * $sumX;
    if (abs($denom) < 1e-12) {
        $m = 0.0;
        $b = $sumY / $n;
    } else {
        $m = ($n * $sumXY - $sumX * $sumY) / $denom;
        $b = ($sumY - $m * $sumX) / $n;
    }
    $out = [];
    for ($h = 0; $h < $steps; $h++) {
        $out[] = max(0.0, $m * ($n + $h) + $b);
    }

    return $out;
}

/**
 * @param array $daily list of [ 'date' => 'Y-m-d', 'revenue' => float ]
 * @return array<string, mixed>
 */
function vip_forecast_sales_php(array $daily, int $horizonW, int $horizonM): array
{
    if ($daily === []) {
        return ['success' => false, 'error' => 'No daily revenue rows.'];
    }

    $byDate = [];
    foreach ($daily as $row) {
        $d = trim((string) ($row['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $byDate[$d] = round((float) ($row['revenue'] ?? 0), 4);
    }
    ksort($byDate);
    if ($byDate === []) {
        return ['success' => false, 'error' => 'No valid daily rows (dates must be YYYY-MM-DD).'];
    }
    $dateKeys = array_keys($byDate);
    try {
        $min = new DateTimeImmutable($dateKeys[0]);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Invalid date range in training data.'];
    }
    try {
        $max = new DateTimeImmutable(end($dateKeys));
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Invalid date range in training data.'];
    }
    for ($cur = $min; $cur <= $max; $cur = $cur->modify('+1 day')) {
        $k = $cur->format('Y-m-d');
        if (!isset($byDate[$k])) {
            $byDate[$k] = 0.0;
        }
    }
    ksort($byDate);

    $dailyChart = [];
    foreach ($byDate as $dt => $rev) {
        $dailyChart[] = ['date' => $dt, 'revenue' => round((float) $rev, 2)];
    }

    // Weekly buckets: Monday start (align with Python W-MON label=left)
    $weekly = [];
    foreach ($byDate as $dt => $rev) {
        $t = new DateTimeImmutable($dt);
        $dow = (int) $t->format('N');
        $monday = $t->modify('-' . ($dow - 1) . ' days');
        $wk = $monday->format('Y-m-d');
        if (!isset($weekly[$wk])) {
            $weekly[$wk] = 0.0;
        }
        $weekly[$wk] += (float) $rev;
    }
    ksort($weekly);

    $wVals = array_values($weekly);
    $wKeys = array_keys($weekly);
    $wStd = vip_forecast_std_sample($wVals);
    $fcW = vip_linear_extrapolate($wVals, $horizonW);
    $lastW = $wKeys !== [] ? new DateTimeImmutable(end($wKeys)) : new DateTimeImmutable('today');

    $weeklyForecast = [];
    for ($i = 0; $i < $horizonW; $i++) {
        $start = $lastW->modify('+' . ($i + 1) . ' weeks');
        $pt = $fcW[$i];
        $margin = 1.28 * $wStd;
        $weeklyForecast[] = [
            'period_start' => $start->format('Y-m-d'),
            'yhat' => round($pt, 2),
            'yhat_low' => round(max(0.0, $pt - $margin), 2),
            'yhat_high' => round($pt + $margin, 2),
        ];
    }

    $weeklyHist = [];
    foreach ($weekly as $start => $rev) {
        $weeklyHist[] = [
            'period_start' => $start,
            'revenue' => round((float) $rev, 2),
        ];
    }

    // Monthly calendar totals
    $monthly = [];
    foreach ($byDate as $dt => $rev) {
        $m = substr($dt, 0, 7);
        if (!isset($monthly[$m])) {
            $monthly[$m] = 0.0;
        }
        $monthly[$m] += (float) $rev;
    }
    ksort($monthly);

    $mVals = array_values($monthly);
    $mKeys = array_keys($monthly);
    $mStd = vip_forecast_std_sample($mVals);
    $fcM = vip_linear_extrapolate($mVals, $horizonM);
    $lastMKey = $mKeys !== [] ? end($mKeys) : date('Y-m');

    $monthlyForecast = [];
    for ($i = 0; $i < $horizonM; $i++) {
        $t = new DateTimeImmutable($lastMKey . '-01');
        $monthStart = $t->modify('+' . ($i + 1) . ' months');
        $pt = $fcM[$i];
        $margin = 1.28 * max($mStd, $pt * 0.1 + 1.0);
        $monthlyForecast[] = [
            'month' => $monthStart->format('Y-m'),
            'yhat' => round($pt, 2),
            'yhat_low' => round(max(0.0, $pt - $margin), 2),
            'yhat_high' => round($pt + $margin, 2),
        ];
    }

    $monthlyHist = [];
    foreach ($monthly as $m => $rev) {
        $monthlyHist[] = [
            'month' => $m,
            'revenue' => round((float) $rev, 2),
        ];
    }

    return [
        'success' => true,
        'meta' => [
            'training_days' => count($byDate),
            'weekly_observations' => count($weekly),
            'monthly_observations' => count($monthly),
            'weekly_method' => 'php_linear_trend',
            'monthly_method' => 'php_linear_trend',
            'engine' => 'php_fallback',
        ],
        'notes' => [
            'PHP fallback: linear trend on weekly / monthly totals (no Python).',
            'Bands use a simple residual-style spread; install Python + Prophet for primary bag forecasts.',
        ],
        'daily' => $dailyChart,
        'weekly' => [
            'history' => $weeklyHist,
            'forecast' => $weeklyForecast,
        ],
        'monthly' => [
            'history' => $monthlyHist,
            'forecast' => $monthlyForecast,
        ],
    ];
}

/**
 * @param array<string, float> $byDate sorted Y-m-d => value
 *
 * @return array{yhat: float, yhat_low: float, yhat_high: float}
 */
function vip_forecast_next_day_from_series(array $byDate): array
{
    if ($byDate === []) {
        return ['yhat' => 0.0, 'yhat_low' => 0.0, 'yhat_high' => 0.0];
    }
    $vals = array_values($byDate);
    $n = count($vals);
    $last = (float) $vals[$n - 1];
    if ($n === 1) {
        $yhat = $last;
    } else {
        $drift = (float) $vals[$n - 1] - (float) $vals[$n - 2];
        $yhat = max(0.0, $last + $drift);
    }
    $std = vip_forecast_std_sample(array_map('floatval', $vals));
    $margin = 1.28 * max($std, $yhat * 0.1 + 0.5);

    return [
        'yhat' => round($yhat, 2),
        'yhat_low' => round(max(0.0, $yhat - $margin), 2),
        'yhat_high' => round($yhat + $margin, 2),
    ];
}

/**
 * @param array<string, float> $sparse
 *
 * @return array<string, float>
 */
function vip_forecast_fill_calendar(array $sparse, string $first, string $last): array
{
    try {
        $min = new DateTimeImmutable($first);
        $max = new DateTimeImmutable($last);
    } catch (Exception $e) {
        return $sparse;
    }
    $out = [];
    for ($cur = $min; $cur <= $max; $cur = $cur->modify('+1 day')) {
        $k = $cur->format('Y-m-d');
        $out[$k] = $sparse[$k] ?? 0.0;
    }

    return $out;
}

/**
 * @param array<string, float> $byDate non-empty
 */
function vip_forecast_next_calendar_date(array $byDate): string
{
    $keys = array_keys($byDate);
    sort($keys);
    $last = (string) end($keys);
    try {
        return (new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d', strtotime('+1 day'));
    }
}

/**
 * Demand forecast when Prophet/Python is unavailable: total bags + revenue from avg ₱/bag.
 *
 * @param array<int, array{date: string, total_bags: float, revenue?: float}> $demandDaily
 *
 * @return array<string, mixed>
 */
function vip_forecast_demand_php(array $demandDaily, int $horizonW, int $horizonM): array
{
    if ($demandDaily === []) {
        return ['success' => false, 'error' => 'No daily demand rows.'];
    }

    $mapForWeekly = [];
    $sumRev = 0.0;
    $sumBags = 0.0;
    foreach ($demandDaily as $r) {
        $d = trim((string) ($r['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $mapForWeekly[] = [
            'date' => $d,
            'revenue' => round((float) ($r['total_bags'] ?? 0), 4),
        ];
        $sumRev += (float) ($r['revenue'] ?? 0);
        $sumBags += (float) ($r['total_bags'] ?? 0);
    }

    if ($mapForWeekly === []) {
        return ['success' => false, 'error' => 'No valid daily demand rows.'];
    }

    $base = vip_forecast_sales_php($mapForWeekly, $horizonW, $horizonM);
    if (empty($base['success'])) {
        return $base;
    }

    foreach ($base['weekly']['history'] as &$h) {
        $h['bags'] = $h['revenue'];
    }
    unset($h);
    foreach ($base['monthly']['history'] as &$h) {
        $h['bags'] = $h['revenue'];
    }
    unset($h);

    $byTotal = [];
    $revByDate = [];
    foreach ($demandDaily as $r) {
        $d = trim((string) ($r['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $byTotal[$d] = round((float) ($r['total_bags'] ?? 0), 4);
        $revByDate[$d] = round((float) ($r['revenue'] ?? 0), 2);
    }
    ksort($byTotal);
    if ($byTotal === []) {
        return ['success' => false, 'error' => 'No valid total_bags series.'];
    }

    reset($byTotal);
    $first = (string) key($byTotal);
    end($byTotal);
    $last = (string) key($byTotal);
    $filledTotal = vip_forecast_fill_calendar($byTotal, $first, $last);

    $pTotal = vip_forecast_next_day_from_series($filledTotal);
    $fd = vip_forecast_next_calendar_date($filledTotal);

    $avg = $sumBags > 0 ? $sumRev / $sumBags : 0.0;
    $est = [
        'yhat' => round($pTotal['yhat'] * $avg, 2),
        'yhat_low' => round($pTotal['yhat_low'] * $avg, 2),
        'yhat_high' => round($pTotal['yhat_high'] * $avg, 2),
    ];

    $newDaily = [];
    foreach ($base['daily'] as $row) {
        $d = (string) ($row['date'] ?? '');
        $newDaily[] = [
            'date' => $d,
            'total_bags' => round((float) ($row['revenue'] ?? 0), 2),
            'revenue' => $revByDate[$d] ?? 0.0,
        ];
    }

    $base['daily'] = $newDaily;
    $base['daily_forecast'] = [
        [
            'date' => $fd,
            'total_bags' => $pTotal,
            'estimated_revenue' => $est,
        ],
    ];
    $base['headline'] = [
        'forecast_date' => $fd,
        'total_bags' => $pTotal,
        'estimated_revenue' => $est,
    ];
    $base['meta']['engine'] = 'php_fallback';
    $base['meta']['avg_price_per_bag'] = round($avg, 4);
    $base['meta']['weekly_method'] = 'php_linear_trend_bags';
    $base['meta']['monthly_method'] = 'php_linear_trend_bags';
    $base['notes'][] = 'PHP fallback: next-day bags from drift on filled daily series; revenue uses average ₱/bag in the window.';

    return $base;
}
