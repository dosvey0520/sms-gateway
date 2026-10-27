<?php
declare(strict_types=1);
require_once __DIR__ . '/gateway.php';

function page_head(string $title, bool $nav = true): void {
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - SMS Gateway</title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<?php if ($nav): ?>
<header class="top"><strong>SMS Gateway</strong>
  <nav><a href="dashboard.php">Messages</a><a href="devices.php">Devices</a><a href="keys.php">API keys</a></nav>
  <form method="post" action="logout.php"><?= csrf_field() ?><button class="link">Log out</button></form>
</header>
<?php endif; ?>
<main>
<?php
}

function page_foot(): void {
    echo "</main></body></html>";
}

function flash_set(string $msg, string $type = 'ok'): void {
    start_session();
    $_SESSION['flash'] = [$msg, $type];
}

function flash_show(): void {
    start_session();
    if (!empty($_SESSION['flash'])) {
        [$m, $t] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash ' . h($t) . '">' . h($m) . '</div>';
    }
}
