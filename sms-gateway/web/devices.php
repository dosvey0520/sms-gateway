<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/layout.php';
$uid = require_login();

$pair = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'pair') {
        // A fresh key dedicated to the phone, shown once. The phone registers itself with it.
        $name = trim((string)($_POST['name'] ?? '')) ?: 'Phone';
        $pair = ['url' => rtrim((string)cfg('base_url'), '/'), 'key' => create_api_key($uid, 'device: ' . mb_substr($name, 0, 80))];
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM devices WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $uid]);
        flash_set('Device removed.');
        header('Location: devices.php'); exit;
    }
}

$st = db()->prepare('SELECT id, name, model, last_seen, created_at FROM devices WHERE user_id = ? ORDER BY id');
$st->execute([$uid]);
$devices = $st->fetchAll();

page_head('Devices');
flash_show();
?>
<div class="card">
  <h2>Add a device</h2>
  <?php if ($pair): ?>
    <div class="secret">
      <strong>Enter these two values in the Android app, then tap Connect.</strong> The key is shown only once.
      <p>Server URL<br><span class="mono"><?= h($pair['url']) ?></span></p>
      <p>API key<br><span class="mono"><?= h($pair['key']) ?></span></p>
    </div>
    <p class="muted">The phone must be on the same Wi-Fi/LAN as this PC. If the URL above is wrong, edit <code>base_url</code> in config.php.</p>
  <?php endif; ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="pair">
    <div><label>Device name</label><input name="name" placeholder="e.g. Office phone" maxlength="80"></div>
    <div><button>Generate pairing details</button></div>
  </form>
</div>

<div class="card">
  <h2>Your devices</h2>
  <table>
    <thead><tr><th>Name</th><th>Model</th><th>Status</th><th>Last seen (UTC)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d): $on = device_online($d['last_seen']); ?>
      <tr>
        <td><?= h($d['name']) ?> <span class="muted">#<?= (int)$d['id'] ?></span></td>
        <td><?= h($d['model'] ?? '') ?></td>
        <td><span class="badge <?= $on ? 'online' : 'offline' ?>"><?= $on ? 'online' : 'offline' ?></span></td>
        <td><?= h($d['last_seen'] ?? 'never') ?></td>
        <td><form method="post" onsubmit="return confirm('Remove this device and its messages?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="danger">Remove</button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td colspan="5" class="muted">No devices registered yet. Pair the phone, and it will appear here.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="muted">A device counts as online if it contacted the server in the last 30 seconds.</p>
</div>
<?php page_foot();
