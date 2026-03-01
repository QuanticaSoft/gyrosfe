<?php
declare(strict_types=1);

// ====== CONFIG DB (AJUSTA) ======

// (Opcional) proteger con clave simple:
// $KEY = 'MI_CLAVE';
// if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit('Forbidden'); }

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

date_default_timezone_set('UTC');

$agentId = trim((string)($_GET['agent'] ?? ''));
$limit   = (int)($_GET['limit'] ?? 200);
$hours   = (int)($_GET['hours'] ?? 24);

if ($limit <= 0) $limit = 200;
if ($limit > 1000) $limit = 1000;
if ($hours <= 0) $hours = 24;
if ($hours > 720) $hours = 720; // max 30 días


require_once __DIR__ . '/../lib/db_connect.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connection failed";
    exit;
}


// Buscar lista de agentes (para selector)
$agents = $pdo->query('SELECT id, "agentId" FROM "Agent" ORDER BY "agentId" ASC')->fetchAll(PDO::FETCH_ASSOC);

// Resolver agent seleccionado a Agent.id
$agentDbId = null;
$agentRow = null;

if ($agentId !== '') {
    $stmt = $pdo->prepare('SELECT id, "agentId", "hostname", "lastSeen", "lastIp", "version" FROM "Agent" WHERE "agentId" = :aid');
    $stmt->execute([':aid' => $agentId]);
    $agentRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($agentRow) $agentDbId = (int)$agentRow['id'];
}

// Si no vino agentId, elige el primero (si existe)
if ($agentDbId === null && !empty($agents)) {
    $agentId = (string)$agents[0]['agentId'];
    $stmt = $pdo->prepare('SELECT id, "agentId", "hostname", "lastSeen", "lastIp", "version" FROM "Agent" WHERE "agentId" = :aid');
    $stmt->execute([':aid' => $agentId]);
    $agentRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($agentRow) $agentDbId = (int)$agentRow['id'];
}

// Cargar eventos
$events = [];
if ($agentDbId !== null) {
    $stmt = $pdo->prepare('
	SELECT id, "createdAt", "action", "path", "vendor", "product", "serial"
        FROM "UsbEvent"
        WHERE "agentId" = :agentDbId
          AND "createdAt" > now() - (:hours || \' hours\')::interval
        ORDER BY "createdAt" DESC
        LIMIT :lim
    ');
    $stmt->bindValue(':agentDbId', $agentDbId, PDO::PARAM_INT);
    $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="3">
  <title>Gyros - USB Events</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    h1 { margin: 0 0 8px; }
    .meta { color:#555; margin-bottom: 16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .muted { background:#f2f2f2; color:#444; }
    .ok { background:#e7f7ee; color:#0c6b2f; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border-bottom: 1px solid #eee; padding: 10px 8px; text-align: left; vertical-align: top; }
    th { font-size: 12px; color: #333; text-transform: uppercase; letter-spacing: .04em; }
    .toolbar { display:flex; gap:10px; align-items:center; margin: 10px 0 16px; flex-wrap:wrap; }
    select, input { padding: 8px 10px; border:1px solid #ddd; border-radius: 10px; }
    a { color:#0b5; text-decoration:none; font-weight:700; }
    .small { font-size: 12px; color:#666; }
  </style>
</head>
<body>
  <h1>USB Events</h1>

  <div class="meta">
    <span class="pill muted">Agent: <span class="mono"><?= esc($agentId) ?></span></span>
    <span class="pill muted">Last <?= (int)$hours ?>h</span>
    <span class="pill muted">Limit <?= (int)$limit ?></span>
    <span class="pill ok"><?= (int)count($events) ?> events</span>
    <span class="small">UTC: <span class="mono"><?= esc(gmdate('Y-m-d H:i:s')) ?></span></span>
    <a href="/gyrosfe/ui/main.php">Main</a>
  </div>

  <form class="toolbar" method="get" action="">
    <label class="small">Agente</label>
    <select name="agent">
      <?php foreach ($agents as $a): ?>
        <?php $aid = (string)$a['agentId']; ?>
        <option value="<?= esc($aid) ?>" <?= $aid === $agentId ? 'selected' : '' ?>>
          <?= esc($aid) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label class="small">Horas</label>
    <input name="hours" type="number" min="1" max="720" value="<?= (int)$hours ?>" style="width:120px">

    <label class="small">Límite</label>
    <input name="limit" type="number" min="1" max="1000" value="<?= (int)$limit ?>" style="width:120px">

    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:10px;background:#fff;cursor:pointer;font-weight:700;">
      Filtrar
    </button>
  </form>

  <?php if (!$agentRow): ?>
    <p>No se encontró el agente o no hay agentes registrados.</p>
  <?php else: ?>
    <div class="small" style="margin-bottom:12px;">
      <b>Host:</b> <?= esc((string)($agentRow['hostname'] ?? '')) ?> ·
      <b>IP:</b> <span class="mono"><?= esc((string)($agentRow['lastIp'] ?? '')) ?></span> ·
      <b>Version:</b> <span class="mono"><?= esc((string)($agentRow['version'] ?? '')) ?></span> ·
      <b>LastSeen:</b> <span class="mono"><?= esc((string)($agentRow['lastSeen'] ?? '')) ?></span>
    </div>

    <table>

      <thead>
  	<tr>
   	  <th>When (UTC)</th>
    	  <th>Action</th>
    	  <th>Vendor/Product</th>
    	  <th>Serial</th>
    	  <th>Path</th>
    	  <th>ID</th>
  	</tr>
      </thead>

      <tbody id="usbBody">

        <?php if (empty($events)): ?>
          <tr><td colspan="5" class="small">No hay eventos en este rango.</td></tr>
        <?php else: ?>
          <?php foreach ($events as $e):
            $when = !empty($e['createdAt'])
              ? (new DateTimeImmutable((string)$e['createdAt'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
              : '—';
            $vp = trim(((string)($e['vendor'] ?? '')).' '.((string)($e['product'] ?? '')));
            $serial = (string)($e['serial'] ?? '');
            $act = (string)($e['action'] ?? '');
            $path = (string)($e['path'] ?? '');
          ?>
          <tr>
            <td class="mono"><?= esc($when) ?></td>
            <td><?= $vp !== '' ? esc($vp) : '<span class="small">—</span>' ?></td>
            <td class="mono"><?= $serial !== '' ? esc($serial) : '<span class="small">—</span>' ?></td>
            <td class="mono"><?= $act !== '' ? esc(strtoupper($act)) : '<span class="small">—</span>' ?></td>
            <td class="mono"><?= $path !== '' ? esc($path) : '<span class="small">—</span>' ?></td>
            <td class="mono"><?= (int)$e['id'] ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

<script>
const agent = new URLSearchParams(location.search).get('agent') || 'agent-01';
const limit = new URLSearchParams(location.search).get('limit') || '200';
const hours = new URLSearchParams(location.search).get('hours') || '24';

async function loadUsb() {
  try {
    const url = `/gyrosfe/agent/usb_events.json.php?agent=${encodeURIComponent(agent)}&limit=${encodeURIComponent(limit)}&hours=${encodeURIComponent(hours)}`;
    const res = await fetch(url, { cache: 'no-store' });
    const data = await res.json();

    if (!data.ok) throw new Error(data.error || 'JSON not ok');

    const tbody = document.getElementById('usbBody');
    tbody.innerHTML = '';

    for (const e of data.events) {
      const when = (e.createdAt || '').replace('T',' ').replace('Z','');
      const action = (e.action || '—').toString().toUpperCase();
      const vp = `${e.vendor || ''} ${e.product || ''}`.trim() || '—';
      const serial = e.serial || '—';
      const path = e.path || '—';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="mono">${when}</td>
        <td class="mono">${action}</td>
        <td>${vp}</td>
        <td class="mono">${serial}</td>
        <td class="mono">${path}</td>
        <td class="mono">${e.id}</td>
      `;
      tbody.appendChild(tr);
    }
  } catch (err) {
    console.error(err);
  }
}

// Carga inicial + refresco cada 3s
loadUsb();
setInterval(loadUsb, 3000);
</script>


</body>
</html>
