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
$userId    = $_SERVER['HTTP_X_USER_ID'] ?? '';
$validShort = preg_match('/^[A-Z0-9]{5}$/i', $userId);
$validUUID  = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId);
if (!$validShort && !$validUUID) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

$allowed = ['ledger:games', 'ledger:profile'];
$dataDir = __DIR__ . '/ledger-data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
    file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
}

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
    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key    = hash('sha256', strtolower($userId) . '|' . $ip);
    $rlFile = $rlDir . '/' . $key . '.json';
    $now    = time();
    $window = 60;
    $limits = ['GET' => 60, 'POST' => 20];
    $max    = $limits[$method] ?? 60;

    $state = file_exists($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?? []) : [];

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

// ---- SQLite setup ----
function get_db(string $dataDir): PDO {
    $dbPath = $dataDir . '/ledger.db';
    $db     = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // WAL mode: allows concurrent reads alongside writes, reduces locking
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');

    $db->exec('CREATE TABLE IF NOT EXISTS user_data (
        user_id    TEXT NOT NULL,
        key        TEXT NOT NULL,
        value      TEXT,
        updated_at INTEGER DEFAULT (strftime(\'%s\',\'now\')),
        PRIMARY KEY (user_id, key)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS visitors (
        user_id    TEXT PRIMARY KEY,
        first_seen INTEGER DEFAULT (strftime(\'%s\',\'now\'))
    )');

    migrate_json_to_sqlite($dataDir, $db);

    return $db;
}

// One-time migration: imports existing per-user JSON files into SQLite.
// Writes a _migrated flag file on completion so it never runs again.
function migrate_json_to_sqlite(string $dataDir, PDO $db): void {
    $flagFile = $dataDir . '/_migrated';
    if (file_exists($flagFile)) return;

    $db->beginTransaction();
    try {
        // Migrate user data files
        $upsert = $db->prepare(
            'INSERT OR REPLACE INTO user_data (user_id, key, value) VALUES (?, ?, ?)'
        );
        foreach (glob($dataDir . '/*.json') as $jsonFile) {
            $base = basename($jsonFile, '.json');
            if ($base[0] === '_') continue; // skip _visitors.json, _migrated, etc.

            $data   = json_decode(file_get_contents($jsonFile), true) ?? [];
            $uid    = strtolower($base);
            foreach ($data as $k => $v) {
                // Values are already JSON strings as stored by the old save() calls
                $upsert->execute([$uid, $k, is_string($v) ? $v : json_encode($v)]);
            }
        }

        // Migrate visitor list
        $vFile = $dataDir . '/_visitors.json';
        if (file_exists($vFile)) {
            $visitors = json_decode(file_get_contents($vFile), true) ?? [];
            $addVisitor = $db->prepare('INSERT OR IGNORE INTO visitors (user_id) VALUES (?)');
            foreach (array_keys($visitors['seen'] ?? []) as $uid) {
                $addVisitor->execute([strtolower($uid)]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        // Migration failed — flag not written, will retry on next request
        return;
    }

    file_put_contents($flagFile, date('c'));
}

// ---- visitor counter ----
if (($_GET['action'] ?? '') === 'visit') {
    $db  = get_db($dataDir);
    $uid = strtolower($userId);

    $check = $db->prepare('SELECT 1 FROM visitors WHERE user_id = ?');
    $check->execute([$uid]);
    $isNew = $check->fetchColumn() === false;

    if ($isNew) {
        $db->prepare('INSERT OR IGNORE INTO visitors (user_id) VALUES (?)')->execute([$uid]);
    }

    $count = $db->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
    echo json_encode(['count' => (int)$count]);
    exit;
}

// ---- read / write ----
if ($method === 'GET') {
    $key = $_GET['key'] ?? '';
    if (!in_array($key, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }

    $db   = get_db($dataDir);
    $stmt = $db->prepare('SELECT value FROM user_data WHERE user_id = ? AND key = ?');
    $stmt->execute([strtolower($userId), $key]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['value' => $row ? $row['value'] : null]);

} elseif ($method === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $key   = $body['key']   ?? '';
    $value = $body['value'] ?? null;
    if (!in_array($key, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }

    $db   = get_db($dataDir);
    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO user_data (user_id, key, value, updated_at)
         VALUES (?, ?, ?, strftime(\'%s\',\'now\'))'
    );
    $stmt->execute([strtolower($userId), $key, $value]);

    echo json_encode(['ok' => true]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
