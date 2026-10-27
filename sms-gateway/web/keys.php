<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/layout.php';
$uid = require_login();

$newKey = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'create') {
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 100) {
            flash_set('Give the key a label (max 100 characters).', 'err');
            header('Location: keys.php'); exit;
        }
        $newKey = create_api_key($uid, $label);   // shown once below, never stored in plain text
    } elseif (($_POST['action'] ?? '') === 'revoke') {
        db()->prepare('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL')
            ->execute([now(), (int)($_POST['id'] ?? 0), $uid]);
        flash_set('Key revoked.');
        header('Location: keys.php'); exit;
    }
}

$st = db()->prepare('SELECT id, label, key_prefix, created_at, revoked_at FROM api_keys WHERE user_id = ? ORDER BY id DESC');
$st->execute([$uid]);
$keys = $st->fetchAll();

page_head('API keys');
flash_show();
?>
<div class="card">
  <h2>Create an API key</h2>
  <?php if ($newKey): ?>
    <div class="secret"><strong>Copy this key now. It will not be shown again.</strong><div class="mono"><?= h($newKey) ?></div></div>
  <?php endif; ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div><label>Label</label><input name="label" placeholder="e.g. PHP system" required maxlength="100"></div>
    <div><button>Generate key</button></div>
  </form>
</div>
<div class="card">
  <h2>Your keys</h2>
  <table>
    <thead><tr><th>Label</th><th>Key</th><th>Created (UTC)</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= h($k['label']) ?></td><td class="mono"><?= h($k['key_prefix']) ?>…</td><td><?= h($k['created_at']) ?></td>
        <td><?= $k['revoked_at'] ? '<span class="badge s-failed">revoked</span>' : '<span class="badge s-delivered">active</span>' ?></td>
        <td><?php if (!$k['revoked_at']): ?>
          <form method="post" onsubmit="return confirm('Revoke this key? Anything using it will stop working.')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button class="danger">Revoke</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$keys): ?><tr><td colspan="5" class="muted">No keys yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php page_foot();
