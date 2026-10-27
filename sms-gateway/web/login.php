<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/layout.php';
start_session();
if (current_user_id()) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $st->execute([strtolower(trim((string)($_POST['email'] ?? '')))]);
    $u = $st->fetch();
    if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        header('Location: dashboard.php'); exit;
    }
    $err = 'Invalid email or password.';
}
page_head('Log in', false);
?>
<div class="auth card">
  <h1>Log in</h1>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <label>Email</label><input type="email" name="email" required autofocus>
    <label>Password</label><input type="password" name="password" required>
    <p><button>Log in</button></p>
  </form>
  <?php if (cfg('allow_registration', true)): ?><p class="muted">No account? <a href="register.php">Register</a></p><?php endif; ?>
</div>
<?php page_foot();
