<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/gateway.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) out(400, ['error' => 'invalid JSON body']);
    return $d;
}

// ---- authenticate (x-api-key header) ----
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($key === '') out(401, ['error' => 'missing x-api-key header']);
$st = db()->prepare('SELECT id, user_id FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
$st->execute([hash('sha256', $key)]);
$auth = $st->fetch();
if (!$auth) out(401, ['error' => 'invalid or revoked API key']);
$keyId = (int)$auth['id'];
$userId = (int)$auth['user_id'];

// ---- route ----
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
$pos = strpos($path, '/api/v1/');
if ($pos === false) out(404, ['error' => 'not found']);
$route = trim(substr($path, $pos + 8), '/');
$method = $_SERVER['REQUEST_METHOD'];

function rate_limit(int $keyId): void {
    $limit = (int)cfg('send_rate_limit_per_minute', 60);
    $win = intdiv(time(), 60);
    $pdo = db();
    $pdo->prepare('DELETE FROM rate_limits WHERE window_min < ?')->execute([$win - 5]);
    $up = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE api_key_id = ? AND window_min = ?');
    $up->execute([$keyId, $win]);
    if ($up->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO rate_limits (api_key_id, window_min, hits) VALUES (?,?,1)')->execute([$keyId, $win]);
        } catch (PDOException $e) {
            $up->execute([$keyId, $win]);   // concurrent insert won the race
        }
    }
    $s = $pdo->prepare('SELECT hits FROM rate_limits WHERE api_key_id = ? AND window_min = ?');
    $s->execute([$keyId, $win]);
    if ((int)$s->fetchColumn() > $limit) {
        header('Retry-After: ' . (60 - (time() % 60)));
        out(429, ['error' => 'rate limit exceeded']);
    }
}

function owned_device(int $id, int $userId): array {
    $st = db()->prepare('SELECT * FROM devices WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $d = $st->fetch();
    if (!$d) out(404, ['error' => 'device not found']);
    return $d;
}

// POST /devices/register
if ($method === 'POST' && $route === 'devices/register') {
    $b = body_json();
    $name = trim((string)($b['name'] ?? ''));
    $model = trim((string)($b['model'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) out(422, ['error' => 'name is required (max 100 chars)']);
    // Re-registration (e.g. after reinstall) reuses an existing device the key's owner already has.
    if (isset($b['device_id'])) {
        $d = owned_device((int)$b['device_id'], $userId);
        db()->prepare('UPDATE devices SET name=?, model=?, last_seen=? WHERE id=?')
            ->execute([$name, mb_substr($model, 0, 100), now(), $d['id']]);
        out(200, ['id' => (int)$d['id'], 'name' => $name]);
    }
    db()->prepare('INSERT INTO devices (user_id,name,model,last_seen,created_at) VALUES (?,?,?,?,?)')
        ->execute([$userId, $name, mb_substr($model, 0, 100), now(), now()]);
    out(201, ['id' => (int)db()->lastInsertId(), 'name' => $name]);
}

// GET /devices/{id}/pending
if ($method === 'GET' && preg_match('#^devices/(\d+)/pending$#', $route, $m)) {
    $dev = owned_device((int)$m[1], $userId);
    $pdo = db();
    $pdo->prepare('UPDATE devices SET last_seen = ? WHERE id = ?')->execute([now(), $dev['id']]);
    // Messages stuck in "processing" for over 2 minutes go back to the queue.
    $pdo->prepare("UPDATE messages SET status='pending', processing_at=NULL
                   WHERE device_id=? AND status='processing' AND processing_at < ?")
        ->execute([$dev['id'], now(-120)]);

    $sel = $pdo->prepare("SELECT id, recipient, body FROM messages
                          WHERE device_id=? AND status='pending' ORDER BY id ASC LIMIT 20");
    $sel->execute([$dev['id']]);
    $claim = $pdo->prepare("UPDATE messages SET status='processing', processing_at=?
                            WHERE id=? AND status='pending'");
    $result = [];
    foreach ($sel->fetchAll() as $row) {
        $claim->execute([now(), $row['id']]);
        if ($claim->rowCount() === 1) {   // only return rows this call actually claimed
            $result[] = ['id' => (int)$row['id'], 'recipient' => $row['recipient'], 'body' => $row['body']];
        }
    }
    out(200, ['messages' => $result]);
}

// POST /messages/{id}/status
if ($method === 'POST' && preg_match('#^messages/(\d+)/status$#', $route, $m)) {
    $b = body_json();
    $status = (string)($b['status'] ?? '');
    if (!in_array($status, ['sent', 'delivered', 'failed'], true)) {
        out(422, ['error' => 'status must be sent, delivered or failed']);
    }
    $error = $status === 'failed' ? mb_substr((string)($b['error'] ?? 'unknown error'), 0, 255) : null;
    $st = db()->prepare('SELECT id, status FROM messages WHERE id=? AND user_id=?');
    $st->execute([(int)$m[1], $userId]);
    $msg = $st->fetch();
    if (!$msg) out(404, ['error' => 'message not found']);
    if ($msg['status'] === 'delivered') out(200, ['ok' => true, 'status' => 'delivered']);   // never downgrade
    if ($msg['status'] === 'failed' && $status !== 'failed') out(200, ['ok' => true, 'status' => 'failed']);

    if ($status === 'sent') {
        db()->prepare('UPDATE messages SET status=?, sent_at=?, error=NULL WHERE id=?')->execute(['sent', now(), $msg['id']]);
    } elseif ($status === 'delivered') {
        db()->prepare('UPDATE messages SET status=?, delivered_at=?, sent_at=COALESCE(sent_at, ?), error=NULL WHERE id=?')
            ->execute(['delivered', now(), now(), $msg['id']]);
    } else {
        db()->prepare('UPDATE messages SET status=?, error=? WHERE id=?')->execute(['failed', $error, $msg['id']]);
    }
    out(200, ['ok' => true, 'status' => $status]);
}

// POST /gateway/devices/{id}/send-sms
if ($method === 'POST' && preg_match('#^gateway/devices/(\d+)/send-sms$#', $route, $m)) {
    rate_limit($keyId);
    $b = body_json();
    $recipients = $b['recipients'] ?? [];
    if (!is_array($recipients)) out(422, ['error' => 'recipients must be an array']);
    $r = enqueue_messages($userId, (int)$m[1], $recipients, (string)($b['message'] ?? ''));
    if (!$r['ok']) out($r['code'], ['error' => $r['error']]);
    out(202, ['queued' => count($r['ids']), 'message_ids' => $r['ids']]);
}

// GET /messages
if ($method === 'GET' && $route === 'messages') {
    $rows = fetch_messages($userId, $_GET['status'] ?? null, (int)($_GET['limit'] ?? 50), (int)($_GET['offset'] ?? 0));
    out(200, ['messages' => $rows]);
}

out(404, ['error' => 'not found']);
