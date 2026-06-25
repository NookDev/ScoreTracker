<?php
// Disable error display in responses; log to server error log instead
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// CORS: allow only the app's own origin — no wildcard
$allowedOrigins = ['https://scoretracker.icu', 'http://scoretracker.icu'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
// (Same-origin requests carry no Origin header; no CORS header needed.)
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validate X-User-ID from header only — never from the request body.
// Anchors \A..\z prevent leading/trailing-newline bypass without needing the D modifier.
// Accepted: legacy 5-char, new 12-char (additive — no migration), UUID v4.
$userId    = $_SERVER['HTTP_X_USER_ID'] ?? '';
$validId   = preg_match(
    '/\A(?:[A-Z0-9]{5}|[A-Z0-9]{12}|[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\z/i',
    $userId
);
if (!$validId) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
    exit;
}

// xxh128 is faster and available on PHP ≥8.1 (production, ea-php82).
// Fall back to sha256 on older runtimes (local MAMP PHP 7.4).
define('RL_HASH_ALGO', in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256');

$allowed = ['ledger:games', 'ledger:profile'];
$dataDir = __DIR__ . '/ledger-data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
// Write (or re-write) the .htaccess guard so it exists even if the directory
// predates this version of api.php.
$htaccess = $dataDir . '/.htaccess';
if (!file_exists($htaccess) || trim(file_get_contents($htaccess)) !== 'Require all denied') {
    file_put_contents($htaccess, "Require all denied\n");
}

// ---- rate limiting ----
// Two layers:
//   1. Per-IP global limit (120 req/min)   — blocks brute-force enumeration
//   2. Per-user+IP limit (60 GET / 20 POST per min) — blocks per-user abuse
// Plus a global circuit breaker (3000 req/min total → 503).
// All read-modify-write operations are protected with flock to prevent undercounting.
// Rate-limit filenames are sha256 hashes so raw IPs/IDs never appear on disk.
// Stale files (> 2 windows old) are GC'd on ~1% of requests.
function check_rate_limit(string $dataDir, string $userId, string $method): void {
    $rlDir = $dataDir . '/_rl';
    if (!is_dir($rlDir)) {
        mkdir($rlDir, 0755, true);
    }

    // LiteSpeed direct: REMOTE_ADDR is the real client IP; do not trust X-Forwarded-For.
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();

    // -- Global circuit breaker: total req/min across all IPs --
    $globalFile = $rlDir . '/_global.json';
    $gFp = fopen($globalFile, 'c+');
    if ($gFp) {
        flock($gFp, LOCK_EX);
        rewind($gFp);
        $gState = json_decode(stream_get_contents($gFp), true) ?? [];
        if (($gState['window_start'] ?? 0) + 60 <= $now) {
            $gState = ['window_start' => $now, 'count' => 0];
        }
        $gState['count'] = ($gState['count'] ?? 0) + 1;
        $exceeded = $gState['count'] > 3000;
        rewind($gFp);
        fwrite($gFp, json_encode($gState));
        ftruncate($gFp, ftell($gFp));
        fflush($gFp);
        flock($gFp, LOCK_UN);
        fclose($gFp);
        if ($exceeded) {
            http_response_code(503);
            echo json_encode(['error' => 'Service temporarily unavailable']);
            exit;
        }
    }

    // -- GC: clean stale rate-limit files on ~1% of requests --
    if (mt_rand(1, 100) === 1) {
        foreach (glob($rlDir . '/*.json') ?: [] as $f) {
            if (basename($f) === '_global.json') continue;
            if (($now - @filemtime($f)) > 120) {
                @unlink($f);
            }
        }
    }

    // -- Layer 1: per-IP global limit (120 req/min) --
    $ipKey  = hash(RL_HASH_ALGO, 'ip|' . $ip);
    $ipFile = $rlDir . '/' . $ipKey . '.json';
    $ipFp   = fopen($ipFile, 'c+');
    if ($ipFp) {
        flock($ipFp, LOCK_EX);
        rewind($ipFp);
        $ipState = json_decode(stream_get_contents($ipFp), true) ?? [];
        if (($ipState['window_start'] ?? 0) + 60 <= $now) {
            $ipState = ['window_start' => $now, 'count' => 0];
        }
        $ipState['count'] = ($ipState['count'] ?? 0) + 1;
        $exceeded = $ipState['count'] > 120;
        rewind($ipFp);
        fwrite($ipFp, json_encode($ipState));
        ftruncate($ipFp, ftell($ipFp));
        fflush($ipFp);
        flock($ipFp, LOCK_UN);
        fclose($ipFp);
        if ($exceeded) {
            $retry = max(1, ($ipState['window_start'] + 60) - $now);
            header('Retry-After: ' . $retry);
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests']);
            exit;
        }
    }

    // -- Layer 2: per-user+IP limit (60 GET / 20 POST per min) --
    $key    = hash(RL_HASH_ALGO, strtolower($userId) . '|' . $ip);
    $rlFile = $rlDir . '/' . $key . '.json';
    $limits = ['GET' => 60, 'POST' => 20];
    $max    = $limits[$method] ?? 60;

    $fp = fopen($rlFile, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        rewind($fp);
        $state = json_decode(stream_get_contents($fp), true) ?? [];
        if (($state['window_start'] ?? 0) + 60 <= $now) {
            $state = ['window_start' => $now, 'GET' => 0, 'POST' => 0];
        }
        $state[$method] = ($state[$method] ?? 0) + 1;
        $exceeded = $state[$method] > $max;
        rewind($fp);
        fwrite($fp, json_encode($state));
        ftruncate($fp, ftell($fp));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($exceeded) {
            $retry = max(1, ($state['window_start'] + 60) - $now);
            header('Retry-After: ' . $retry);
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests']);
            exit;
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' || $method === 'POST') {
    check_rate_limit($dataDir, $userId, $method);
}

// ---- SQLite setup ----
function get_db(string $dataDir): PDO {
    $dbPath = $dataDir . '/ledger.db';
    try {
        $db = new PDO('sqlite:' . $dbPath);
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
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
        exit;
    }

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
        foreach (glob($dataDir . '/*.json') ?: [] as $jsonFile) {
            $base = basename($jsonFile, '.json');
            if ($base[0] === '_') continue; // skip _visitors.json etc.

            $data = json_decode(file_get_contents($jsonFile), true) ?? [];
            $uid  = strtolower($base);
            foreach ($data as $k => $v) {
                $upsert->execute([$uid, $k, is_string($v) ? $v : json_encode($v)]);
            }
        }

        // Migrate visitor list
        $vFile = $dataDir . '/_visitors.json';
        if (file_exists($vFile)) {
            $visitors   = json_decode(file_get_contents($vFile), true) ?? [];
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
    try {
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
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
    }
    exit;
}

// ---- read / write ----
if ($method === 'GET') {
    $key = $_GET['key'] ?? '';
    if (!in_array($key, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request']);
        exit;
    }

    try {
        $db   = get_db($dataDir);
        $stmt = $db->prepare('SELECT value FROM user_data WHERE user_id = ? AND key = ?');
        $stmt->execute([strtolower($userId), $key]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['value' => $row ? $row['value'] : null]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
    }

} elseif ($method === 'POST') {
    // Enforce 256 KB body cap before any parsing
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if (strlen($raw) > 262144) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request']);
        exit;
    }

    $body  = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request']);
        exit;
    }

    // Key must be in the allowlist
    $key   = $body['key'] ?? '';
    $value = $body['value'] ?? null;   // Note: user_id is NEVER read from the body
    if (!in_array($key, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request']);
        exit;
    }

    // Validate value shape: ledger:games must be a JSON array, ledger:profile a JSON object
    if (is_string($value)) {
        $decoded = json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad request']);
            exit;
        }
        if ($key === 'ledger:games' && !is_array($decoded)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad request']);
            exit;
        }
        if ($key === 'ledger:profile' && !is_object($decoded)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad request']);
            exit;
        }
    }

    try {
        $db   = get_db($dataDir);
        $stmt = $db->prepare(
            'INSERT OR REPLACE INTO user_data (user_id, key, value, updated_at)
             VALUES (?, ?, ?, strftime(\'%s\',\'now\'))'
        );
        $stmt->execute([strtolower($userId), $key, $value]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
