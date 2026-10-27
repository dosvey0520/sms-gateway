<?php
declare(strict_types=1);

// Config: config.php next to this folder, or the path in env SMSGW_CONFIG (used for tests).
$cfgFile = getenv('SMSGW_CONFIG') ?: __DIR__ . '/../config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("Missing config.php - copy config.example.php to config.php and edit it.\n");
}
$GLOBALS['CFG'] = require $cfgFile;

function cfg(string $key, $default = null) {
    return $GLOBALS['CFG'][$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// All timestamps are generated in PHP (UTC) so SQL stays portable and consistent.
function now(int $offsetSeconds = 0): string {
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function valid_phone(string $n): bool {
    return (bool)preg_match('/^\+[1-9]\d{6,14}$/', $n);   // E.164
}

function generate_api_key(): array {
    $key = 'sg_' . bin2hex(random_bytes(20));
    return [$key, substr($key, 0, 10), hash('sha256', $key)];
}

function create_api_key(int $userId, string $label): string {
    [$key, $prefix, $hash] = generate_api_key();
    db()->prepare('INSERT INTO api_keys (user_id,label,key_prefix,key_hash,created_at) VALUES (?,?,?,?,?)')
        ->execute([$userId, $label, $prefix, $hash, now()]);
    return $key;
}

function device_online(?string $lastSeen): bool {
    return $lastSeen !== null && strtotime($lastSeen . ' UTC') >= time() - 30;
}

// ---- dashboard session / CSRF ----
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function current_user_id(): ?int {
    start_session();
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function require_login(): int {
    $uid = current_user_id();
    if (!$uid) { header('Location: login.php'); exit; }
    return $uid;
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void {
    start_session();
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$ok) { http_response_code(403); exit('Invalid CSRF token'); }
}
