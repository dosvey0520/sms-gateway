<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Queue one "pending" message per recipient for a device.
 * Returns ['ok'=>true,'ids'=>[...]] or ['ok'=>false,'error'=>'...','code'=>int].
 */
function enqueue_messages(int $userId, int $deviceId, array $recipients, string $message): array {
    $message = trim($message);
    if ($message === '' || mb_strlen($message) > 1600) {
        return ['ok' => false, 'error' => 'message must be 1-1600 characters', 'code' => 422];
    }
    $recipients = array_values(array_unique(array_map(fn($r) => trim((string)$r), $recipients)));
    if (!$recipients || count($recipients) > 50) {
        return ['ok' => false, 'error' => 'recipients must contain 1-50 numbers', 'code' => 422];
    }
    foreach ($recipients as $r) {
        if (!valid_phone($r)) {
            return ['ok' => false, 'error' => "invalid phone number (use E.164, e.g. +639171234567): $r", 'code' => 422];
        }
    }
    $st = db()->prepare('SELECT id FROM devices WHERE id=? AND user_id=?');
    $st->execute([$deviceId, $userId]);
    if (!$st->fetch()) {
        return ['ok' => false, 'error' => 'device not found', 'code' => 404];
    }

    $ids = [];
    $ins = db()->prepare('INSERT INTO messages (user_id,device_id,recipient,body,status,created_at) VALUES (?,?,?,?,?,?)');
    db()->beginTransaction();
    try {
        foreach ($recipients as $r) {
            $ins->execute([$userId, $deviceId, $r, $message, 'pending', now()]);
            $ids[] = (int)db()->lastInsertId();
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return ['ok' => true, 'ids' => $ids];
}

function fetch_messages(int $userId, ?string $status, int $limit = 50, int $offset = 0): array {
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $sql = 'SELECT m.id, m.device_id, m.recipient, m.body, m.status, m.error, m.created_at, m.sent_at, m.delivered_at
            FROM messages m WHERE m.user_id = ?';
    $args = [$userId];
    $allowed = ['pending', 'processing', 'sent', 'delivered', 'failed'];
    if ($status !== null && $status !== '' && in_array($status, $allowed, true)) {
        $sql .= ' AND m.status = ?';
        $args[] = $status;
    }
    $sql .= ' ORDER BY m.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;   // ints already sanitized
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
