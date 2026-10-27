<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/gateway.php';
$uid = current_user_id();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$uid) { http_response_code(401); echo '{"error":"not logged in"}'; exit; }
echo json_encode(['messages' => fetch_messages($uid, $_GET['status'] ?? null, 100)], JSON_UNESCAPED_UNICODE);
