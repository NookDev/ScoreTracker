<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Accept 5-char alphanumeric short IDs or legacy UUID v4 — prevents path traversal
$userId = $_SERVER['HTTP_X_USER_ID'] ?? '';
$validShort = preg_match('/^[A-Z0-9]{5}$/i', $userId);
$validUUID  = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId);
if (!$validShort && !$validUUID) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

$allowed  = ['ledger:games', 'ledger:profile'];
$dataDir  = __DIR__ . '/ledger-data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
    // Block direct web access to data files
    file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
}

$file = $dataDir . '/' . strtolower($userId) . '.json';

// ---- rate limiting ----
// Limits: 60 GETs per minute, 20 POSTs per minute, per user ID + IP combo.
// Uses fixed 60-second windows stored as small JSON files in ledger-data/_rl/.
function check_rate_limit(string $dataDir, string $userId, string $method): void {
    $rlDir = $dataDir . '/_rl';
    if (!is_dir($rlDir)) {
        mkdir($rlDir, 0755, true);
    }

    // On LiteSpeed (direct, no upstream proxy), REMOTE_ADDR is the real client IP.
    // Do NOT trust X-Forwarded-For — clients can spoof it to bypass rate limits.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key     = hash('sha256', strtolower($userId) . '|' . $ip);
    $rlFile  = $rlDir . '/' . $key . '.json';
    $now     = time();
    $window  = 60; // seconds
    $limits  = ['GET' => 60, 'POST' => 20];
    $max     = $limits[$method] ?? 60;

    $state = file_exists($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?? []) : [];

    // Reset window if expired
    if (($state['window_start'] ?? 0) + $window <= $now) {
        $state = ['window_start' => $now, 'GET' => 0, 'POST' => 0];
    }

    $state[$method] = ($state[$method] ?? 0) + 1;

    if ($state[$method] > $max) {
        $retry = ($state['window_start'] + $window) - $now;
        header('Retry-After: ' . $retry);
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests', 'retry_after' => $retry]);
        exit;
    }

    file_put_contents($rlFile, json_encode($state));
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' || $method === 'POST') {
    check_rate_limit($dataDir, $userId, $method);
}

// ---- visitor counter ----
if (($_GET['action'] ?? '') === 'visit') {
    $vFile    = $dataDir . '/_visitors.json';
    $visitors = file_exists($vFile) ? (json_decode(file_get_contents($vFile), true) ?? []) : [];
    $count    = $visitors['count'] ?? 0;
    $seen     = $visitors['seen']  ?? [];
    $key      = strtolower($userId);
    if (!isset($seen[$key])) {
        $count++;
        $seen[$key] = true;
        file_put_contents($vFile, json_encode(['count' => $count, 'seen' => $seen]));
    }
    echo json_encode(['count' => $count]);
    exit;
}

if ($method === 'GET') {
    $key = $_GET['key'] ?? '';
    if (!in_array($key, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }
    if (!file_exists($file)) {
        echo json_encode(['value' => null]);
        exit;
    }
    $data = json_decode(file_get_contents($file), true) ?? [];
    echo json_encode(['value' => $data[$key] ?? null]);

} elseif ($method === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $key   = $body['key']   ?? '';
    $value = $body['value'] ?? null;
    if (!in_array($key, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }
    $data        = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    $data[$key]  = $value;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
