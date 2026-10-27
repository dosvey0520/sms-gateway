<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/layout.php';
start_session();
if (!cfg('allow_registration', true)) { http_response_code(403); exit('Registration is disabled.'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pw = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $err = 'Enter a valid email address.';
    } elseif (strlen($pw) < 8) {
        $err = 'Password must be at least 8 characters.';
    } else {
        $st = db()->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            $err = 'That email is already registered.';
        } else {
            db()->prepare('INSERT INTO users (email,password_hash,created_at) VALUES (?,?,?)')
                ->execute([$email, password_hash($pw, PASSWORD_DEFAULT), now()]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            header('Location: dashboard.php'); exit;
        }
    }
}
page_head('Register', false);
?>
<div class="auth card">
  <h1>Create account</h1>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <label>Email</label><input type="email" name="email" required autofocus>
    <label>Password (min 8 characters)</label><input type="password" name="password" required minlength="8">
    <p><button>Register</button></p>
  </form>
  <p class="muted">Already registered? <a href="login.php">Log in</a></p>
</div>
<?php page_foot();
