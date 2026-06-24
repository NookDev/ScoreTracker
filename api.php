<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// UUID v4 validation — prevents path traversal attacks
$userId = $_SERVER['HTTP_X_USER_ID'] ?? '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId)) {
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
