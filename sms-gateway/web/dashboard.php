<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/layout.php';
$uid = require_login();

$st = db()->prepare('SELECT id, name, last_seen FROM devices WHERE user_id = ? ORDER BY id');
$st->execute([$uid]);
$devices = $st->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $numbers = preg_split('/[\s,;]+/', (string)($_POST['recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $r = enqueue_messages($uid, (int)($_POST['device_id'] ?? 0), $numbers, (string)($_POST['message'] ?? ''));
    $r['ok'] ? flash_set('Queued ' . count($r['ids']) . ' message(s). The phone will pick them up within a few seconds.')
             : flash_set($r['error'], 'err');
    header('Location: dashboard.php'); exit;
}

$filter = (string)($_GET['status'] ?? '');
page_head('Messages');
flash_show();
?>
<div class="card">
  <h2>Send a test SMS</h2>
  <?php if (!$devices): ?>
    <p class="muted">No device yet. <a href="devices.php">Add a device</a> first.</p>
  <?php else: ?>
  <form method="post"><?= csrf_field() ?>
    <div class="row">
      <div><label>Device</label>
        <select name="device_id">
          <?php foreach ($devices as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?> (<?= device_online($d['last_seen']) ? 'online' : 'offline' ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Recipient(s), E.164, separated by comma or space</label>
        <input name="recipients" placeholder="+639171234567" required></div>
    </div>
    <label>Message</label><textarea name="message" maxlength="1600" required></textarea>
    <p><button>Send</button></p>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="row">
    <h2 style="flex:3">Message log <span class="muted" id="live">(auto-refresh every 5 s)</span></h2>
    <div><select id="filter">
      <?php foreach (['' => 'All statuses', 'pending' => 'Pending', 'processing' => 'Processing', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed'] as $v => $l): ?>
        <option value="<?= h($v) ?>" <?= $v === $filter ? 'selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach; ?>
    </select></div>
  </div>
  <table>
    <thead><tr><th>ID</th><th>To</th><th>Message</th><th>Status</th><th>Created (UTC)</th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
  <p class="muted" id="empty" hidden>No messages.</p>
</div>

<script>
const rows = document.getElementById('rows'), empty = document.getElementById('empty'), sel = document.getElementById('filter');
function cell(tr, text, cls) { const td = tr.insertCell(); td.textContent = text; if (cls) td.className = cls; return td; }
async function load() {
  try {
    const r = await fetch('messages_json.php?status=' + encodeURIComponent(sel.value), {cache: 'no-store'});
    if (r.status === 401) { location.href = 'login.php'; return; }
    const data = (await r.json()).messages;
    rows.textContent = '';
    for (const m of data) {
      const tr = rows.insertRow();
      cell(tr, m.id); cell(tr, m.recipient, 'mono');
      cell(tr, m.body.length > 80 ? m.body.slice(0, 80) + '…' : m.body);
      const td = tr.insertCell(), b = document.createElement('span');
      b.className = 'badge s-' + m.status; b.textContent = m.status; td.appendChild(b);
      if (m.error) { const e = document.createElement('div'); e.className = 'muted'; e.textContent = m.error; td.appendChild(e); }
      cell(tr, m.created_at);
    }
    empty.hidden = data.length > 0;
  } catch (e) { /* network hiccup: try again on the next tick */ }
}
sel.addEventListener('change', load);
load(); setInterval(load, 5000);
</script>
<?php page_foot();
