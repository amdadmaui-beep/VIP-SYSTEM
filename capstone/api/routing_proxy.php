<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/rider_availability_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

function readFloatParam($key, $default = null) {
    if (!isset($_GET[$key]) && !isset($_POST[$key])) return $default;
    $val = $_GET[$key] ?? $_POST[$key];
    if (!is_numeric($val)) return $default;
    return (float)$val;
}

function fetchJson($url) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: VIP-Ice-Routing-Proxy/1.0\r\nAccept: application/json\r\n"
        ]
    ]);
    $resp = @file_get_contents($url, false, $context);
    if ($resp === false) return null;
    $json = json_decode($resp, true);
    return is_array($json) ? $json : null;
}

function routingProvider() {
    $provider = strtolower((string)env('ROUTING_PROVIDER', 'osrm'));
    if (!in_array($provider, ['graphhopper', 'osrm', 'ors'], true)) {
        $provider = 'osrm';
    }
    return $provider;
}

function osrmBaseUrl() {
    return rtrim((string)env('OSRM_BASE_URL', 'https://router.project-osrm.org'), '/');
}

function graphhopperBaseUrl() {
    return rtrim((string)env('GRAPHHOPPER_BASE_URL', 'https://graphhopper.com/api/1'), '/');
}

function graphhopperKey() {
    return trim((string)env('GRAPHHOPPER_API_KEY', ''));
}

function orsBaseUrl() {
    return rtrim((string)env('OPENROUTESERVICE_BASE_URL', 'https://api.openrouteservice.org'), '/');
}

function orsKey() {
    return trim((string)env('OPENROUTESERVICE_API_KEY', ''));
}

function osrmNearest($lat, $lng) {
    $url = osrmBaseUrl() . '/nearest/v1/driving/' . $lng . ',' . $lat . '?number=1';
    return fetchJson($url);
}

function osrmRoute($fromLat, $fromLng, $toLat, $toLng) {
    $url = osrmBaseUrl() . '/route/v1/driving/' .
        $fromLng . ',' . $fromLat . ';' . $toLng . ',' . $toLat .
        '?overview=full&geometries=geojson&steps=true&alternatives=true&annotations=duration,distance,speed';
    return fetchJson($url);
}

function normalizeGraphhopperToOsrm($gh) {
    if (!is_array($gh) || empty($gh['paths']) || !is_array($gh['paths'])) return null;
    $routes = [];
    foreach ($gh['paths'] as $p) {
        $points = $p['points']['coordinates'] ?? null; // [lng, lat]
        if (!is_array($points) || count($points) < 2) continue;
        $instructions = $p['instructions'] ?? [];
        $steps = [];
        foreach ($instructions as $ins) {
            $text = (string)($ins['text'] ?? 'Continue');
            $distance = (float)($ins['distance'] ?? 0);
            $steps[] = [
                'maneuver' => ['type' => 'turn', 'modifier' => strtolower($text)],
                'name' => $text,
                'distance' => $distance
            ];
        }
        $routes[] = [
            'distance' => (float)($p['distance'] ?? 0),
            'duration' => ((float)($p['time'] ?? 0)) / 1000.0,
            'geometry' => ['coordinates' => $points],
            'legs' => [['steps' => $steps]]
        ];
    }
    if (empty($routes)) return null;
    return ['routes' => $routes];
}

function graphhopperRoute($fromLat, $fromLng, $toLat, $toLng) {
    $key = graphhopperKey();
    if ($key === '') return null;
    $url = graphhopperBaseUrl() . '/route'
        . '?point=' . rawurlencode($fromLat . ',' . $fromLng)
        . '&point=' . rawurlencode($toLat . ',' . $toLng)
        . '&profile=car'
        . '&points_encoded=false'
        . '&instructions=true'
        . '&calc_points=true'
        . '&key=' . rawurlencode($key);
    $raw = fetchJson($url);
    return normalizeGraphhopperToOsrm($raw);
}

function fetchJsonPost($url, $payload, $headers = []) {
    $defaultHeaders = [
        "User-Agent: VIP-Ice-Routing-Proxy/1.0",
        "Accept: application/json",
        "Content-Type: application/json"
    ];
    $allHeaders = array_merge($defaultHeaders, $headers);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 15,
            'header' => implode("\r\n", $allHeaders) . "\r\n",
            'content' => json_encode($payload)
        ]
    ]);
    $resp = @file_get_contents($url, false, $context);
    if ($resp === false) return null;
    $json = json_decode($resp, true);
    return is_array($json) ? $json : null;
}

function normalizeOrsToOsrm($ors) {
    if (!is_array($ors)) return null;
    $features = $ors['features'] ?? [];
    if (!is_array($features) || empty($features)) return null;
    $routes = [];
    foreach ($features as $f) {
        $coords = $f['geometry']['coordinates'] ?? null; // [lng,lat]
        if (!is_array($coords) || count($coords) < 2) continue;
        $summary = $f['properties']['summary'] ?? [];
        $segments = $f['properties']['segments'] ?? [];
        $steps = $segments[0]['steps'] ?? [];
        $normSteps = [];
        foreach ($steps as $s) {
            $instruction = (string)($s['instruction'] ?? 'Continue');
            $distance = (float)($s['distance'] ?? 0);
            $name = (string)($s['name'] ?? '');
            $normSteps[] = [
                'maneuver' => ['type' => 'turn', 'modifier' => strtolower($instruction)],
                'name' => $name !== '' ? $name : $instruction,
                'distance' => $distance
            ];
        }
        $routes[] = [
            'distance' => (float)($summary['distance'] ?? 0),
            'duration' => (float)($summary['duration'] ?? 0),
            'geometry' => ['coordinates' => $coords],
            'legs' => [['steps' => $normSteps]]
        ];
    }
    if (empty($routes)) return null;
    return ['routes' => $routes];
}

function orsRoute($fromLat, $fromLng, $toLat, $toLng) {
    $key = orsKey();
    if ($key === '') return null;
    $url = orsBaseUrl() . '/v2/directions/driving-car/geojson';
    $payload = [
        'coordinates' => [
            [(float)$fromLng, (float)$fromLat],
            [(float)$toLng, (float)$toLat]
        ],
        'instructions' => true
    ];
    $raw = fetchJsonPost($url, $payload, ["Authorization: {$key}"]);
    return normalizeOrsToOsrm($raw);
}

function cacheDir() {
    $dir = __DIR__ . '/../cache/routing';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function cacheRead($key, $ttlSeconds) {
    $file = cacheDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['ts']) || !isset($json['data'])) return null;
    if ((time() - (int)$json['ts']) > $ttlSeconds) return null;
    return $json['data'];
}

function cacheWrite($key, $data) {
    $file = cacheDir() . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    @file_put_contents($file, json_encode(['ts' => time(), 'data' => $data]));
}

function safeLower($text) {
    return function_exists('mb_strtolower') ? mb_strtolower((string)$text) : strtolower((string)$text);
}

function queryTokens($text) {
    $text = strtolower((string)$text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $parts = preg_split('/\s+/', $text);
    $stop = ['the','and','for','zone','purok','street','st','road','barangay','brgy','cagayan','oro','misamis','oriental','philippines','tablon'];
    $tokens = [];
    foreach ($parts as $p) {
        if ($p === '' || strlen($p) < 3) continue;
        if (in_array($p, $stop, true)) continue;
        $tokens[] = $p;
    }
    return array_values(array_unique($tokens));
}

function looksLikeMatch($query, $candidateText) {
    $tokens = queryTokens($query);
    if (empty($tokens)) return true;
    $hay = strtolower((string)$candidateText);
    $hits = 0;
    foreach ($tokens as $t) {
        if (strpos($hay, $t) !== false) $hits++;
    }
    // Require at least one strong token match for named places.
    return $hits >= 1;
}

function deliveryHasColumn($conn, $column) {
    static $cache = [];
    if (array_key_exists($column, $cache)) return $cache[$column];
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'delivery' AND COLUMN_NAME = ?");
    $stmt->execute([DB_NAME, $column]);
    $cache[$column] = ((int)$stmt->fetchColumn()) > 0;
    return $cache[$column];
}

function matchTokenCount($query, $candidateText) {
    $tokens = queryTokens($query);
    if (empty($tokens)) return 0;
    $hay = strtolower((string)$candidateText);
    $hits = 0;
    foreach ($tokens as $t) {
        if (strpos($hay, $t) !== false) $hits++;
    }
    return $hits;
}

function haversineKm($lat1, $lon1, $lat2, $lon2) {
    $toRad = function($x) { return $x * M_PI / 180.0; };
    $R = 6371.0;
    $dLat = $toRad($lat2 - $lat1);
    $dLon = $toRad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos($toRad($lat1)) * cos($toRad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'nearest') {
    $lat = readFloatParam('lat');
    $lng = readFloatParam('lng');
    if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
        exit;
    }

    $key = 'nearest_' . round($lat, 5) . '_' . round($lng, 5);
    $cached = cacheRead($key, 12);
    if ($cached !== null) {
        echo json_encode(['success' => true, 'data' => $cached, 'cached' => true]);
        exit;
    }

    // Nearest/snap remains OSRM-compatible response for frontend.
    $data = osrmNearest($lat, $lng);
    if (!$data) {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Routing provider unavailable']);
        exit;
    }
    cacheWrite($key, $data);
    echo json_encode(['success' => true, 'data' => $data, 'cached' => false]);
    exit;
}

if ($action === 'route') {
    $fromLat = readFloatParam('from_lat');
    $fromLng = readFloatParam('from_lng');
    $toLat = readFloatParam('to_lat');
    $toLng = readFloatParam('to_lng');
    $force = (string)($_GET['force'] ?? $_POST['force'] ?? '0') === '1';
    if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing coordinates']);
        exit;
    }

    $key = 'route_' . round($fromLat, 5) . '_' . round($fromLng, 5) . '_' . round($toLat, 5) . '_' . round($toLng, 5);
    if (!$force) {
        $cached = cacheRead($key, 30);
        if ($cached !== null) {
            echo json_encode(['success' => true, 'data' => $cached, 'cached' => true]);
            exit;
        }
    }

    $provider = routingProvider();
    $data = null;
    if ($provider === 'ors') {
        $data = orsRoute($fromLat, $fromLng, $toLat, $toLng);
        if (!$data) $data = osrmRoute($fromLat, $fromLng, $toLat, $toLng);
    } elseif ($provider === 'graphhopper') {
        $data = graphhopperRoute($fromLat, $fromLng, $toLat, $toLng);
        if (!$data) {
            // Fallback to OSRM when GraphHopper key is missing/unavailable.
            $data = osrmRoute($fromLat, $fromLng, $toLat, $toLng);
        }
    } else {
        $data = osrmRoute($fromLat, $fromLng, $toLat, $toLng);
    }
    if (!$data) {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Routing provider unavailable']);
        exit;
    }
    cacheWrite($key, $data);
    echo json_encode(['success' => true, 'data' => $data, 'cached' => false, 'provider' => $provider]);
    exit;
}

if ($action === 'geocode') {
    $qRaw = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    $nearLat = readFloatParam('near_lat');
    $nearLng = readFloatParam('near_lng');
    $hasNear = ($nearLat !== null && $nearLng !== null && $nearLat >= -90 && $nearLat <= 90 && $nearLng >= -180 && $nearLng <= 180);
    if ($qRaw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing query']);
        exit;
    }

    $queries = [];
    if (stripos($qRaw, 'cagayan de oro') === false) {
        $queries[] = $qRaw . ', Cagayan de Oro, Misamis Oriental, Philippines';
    }
    $queries[] = $qRaw;
    if (stripos($qRaw, 'philippines') === false) {
        $queries[] = $qRaw . ', Philippines';
    }

    foreach ($queries as $q) {
        $key = 'geocode_' . md5(safeLower($q) . ($hasNear ? ('|' . round($nearLat, 3) . ',' . round($nearLng, 3)) : ''));
        $cached = cacheRead($key, 86400);
        if ($cached !== null) {
            // Back-compat: previously cached entries may not include candidates list.
            echo json_encode(['success' => true, 'data' => $cached, 'cached' => true]);
            exit;
        }

        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=8&countrycodes=ph&q=' . rawurlencode($q);
        if ($hasNear) {
            $delta = 0.06; // bias around rider location (~6-7km)
            $left = $nearLng - $delta;
            $right = $nearLng + $delta;
            $top = $nearLat + $delta;
            $bottom = $nearLat - $delta;
            $url .= '&viewbox=' . rawurlencode($left . ',' . $top . ',' . $right . ',' . $bottom);
        }
        $data = fetchJson($url);
        if (!is_array($data) || empty($data)) {
            continue;
        }

        $best = null;
        $bestScore = -INF;
        $ranked = [];
        foreach ($data as $candidate) {
            $lat = isset($candidate['lat']) ? (float)$candidate['lat'] : null;
            $lng = isset($candidate['lon']) ? (float)$candidate['lon'] : null;
            if ($lat === null || $lng === null) continue;

            $localHint = preg_match('/tablon|bugo|cagayan de oro|jasaan/i', $qRaw) === 1;
            if ($localHint && !($lat >= 7.8 && $lat <= 9.2 && $lng >= 123.8 && $lng <= 125.6)) continue;

            $display = (string)($candidate['display_name'] ?? '');
            $hits = matchTokenCount($qRaw, $display);
            if (!looksLikeMatch($qRaw, $display)) continue;

            $distanceKm = null;
            $distancePenalty = 0.0;
            if ($hasNear) {
                $distanceKm = haversineKm($nearLat, $nearLng, $lat, $lng);
                $distancePenalty = min($distanceKm, 40.0) / 4.0;
            }
            $score = ($hits * 12.0) - $distancePenalty;
            $ranked[] = [
                'lat' => $lat,
                'lng' => $lng,
                'display_name' => $display,
                'source_query' => $q,
                'match_hits' => $hits,
                'distance_km' => $distanceKm,
                'score' => $score
            ];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'display_name' => $display,
                    'source_query' => $q,
                    'match_hits' => $hits,
                    'distance_km' => $distanceKm
                ];
            }
        }

        if (!$best) {
            continue;
        }

        // Sort candidates and compute per-candidate confidence labels.
        usort($ranked, function ($a, $b) {
            return ($b['score'] ?? -INF) <=> ($a['score'] ?? -INF);
        });
        $candidates = array_slice($ranked, 0, 5);
        foreach ($candidates as &$c) {
            $conf = 'low';
            if (($c['match_hits'] ?? 0) >= 2) $conf = 'medium';
            if (($c['match_hits'] ?? 0) >= 2 && (!$hasNear || (($c['distance_km'] ?? 0) <= 3.0))) $conf = 'high';
            $c['confidence'] = $conf;
            unset($c['score']);
        }
        unset($c);

        $confidence = 'low';
        if (($best['match_hits'] ?? 0) >= 2) $confidence = 'medium';
        if (($best['match_hits'] ?? 0) >= 2 && (!$hasNear || (($best['distance_km'] ?? 0) <= 3.0))) $confidence = 'high';
        $best['confidence'] = $confidence;
        $best['candidates'] = $candidates;

        cacheWrite($key, $best);
        echo json_encode(['success' => true, 'data' => $best, 'cached' => false]);
        exit;
    }

    // Return 200 to avoid noisy browser 404 logs while searching.
    echo json_encode(['success' => false, 'message' => 'No Nominatim result found']);
    exit;
}

if ($action === 'save_destination_pin') {
    $deliveryId = (int)($_POST['delivery_id'] ?? $_GET['delivery_id'] ?? 0);
    $lat = readFloatParam('lat');
    $lng = readFloatParam('lng');
    if ($deliveryId <= 0 || $lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid pin payload']);
        exit;
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    // Use direct DB connection here to keep API response strictly JSON
    // and avoid page-oriented middleware output.
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $conn = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    if (!deliveryHasColumn($conn, 'destination_lat') || !deliveryHasColumn($conn, 'destination_lng')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Destination columns missing. Run migrations first.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT Delivery_ID FROM delivery WHERE Delivery_ID = ?");
    $stmt->execute([$deliveryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        exit;
    }

    $assignedId = riderGetUserIdByDeliveryId($conn, $deliveryId);
    if ($assignedId !== 0 && $assignedId !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Delivery not assigned to this rider']);
        exit;
    }

    try {
        $upd = $conn->prepare("UPDATE delivery SET destination_lat = ?, destination_lng = ?, updated_at = NOW() WHERE Delivery_ID = ?");
        $upd->execute([$lat, $lng, $deliveryId]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save destination pin']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Destination pin saved',
        'delivery_id' => $deliveryId,
        'lat' => $lat,
        'lng' => $lng
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);

