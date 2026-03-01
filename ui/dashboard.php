<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$userName = trim((string) ($_SESSION['full_name'] ?? ''));
if ($userName === '') {
    $userName = (string) ($_SESSION['email'] ?? 'Usuario');
}

// -------------------------------------------------------------------------------------
// Umbral online (segundos)
$onlineThresholdSec = 120; // 2 min

// Campana si hubo disconnect reciente (minutos)
$disconnectMinutes = 10;

date_default_timezone_set('UTC');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

require_once __DIR__ . '/../lib/db_connect.php';


try {
    $pdo = db_connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connection failed";
    exit;
}

$sql = "
SELECT
  a.id,
  a.\"agentId\",
  a.\"hostname\",
  a.\"lastIp\",
  a.\"version\",
  a.\"lastSeen\",
  EXTRACT(EPOCH FROM (now() - a.\"lastSeen\"))::int AS \"ageSec\",
  a.\"createdAt\",
  a.\"updatedAt\",

  hb.\"createdAt\"  AS \"hbAt\",
  hb.\"uptimeSec\"  AS \"hbUptimeSec\",
  hb.\"localIp\"    AS \"hbLocalIp\",

  COALESCE(usb24.cnt, 0) AS \"usb24Count\",

  COALESCE(usbst.connected_cnt, 0) AS \"usbConnectedNow\",
  usbst.last_change AS \"usbStateLastChange\",

  ulast.\"createdAt\" AS \"usbLastAt\",
  ulast.\"action\"    AS \"usbLastAction\",
  ulast.\"vendor\"    AS \"usbLastVendor\",
  ulast.\"product\"   AS \"usbLastProduct\",
  ulast.\"serial\"    AS \"usbLastSerial\"

FROM \"Agent\" a

LEFT JOIN LATERAL (
  SELECT h.\"createdAt\", h.\"uptimeSec\", h.\"localIp\"
  FROM \"Heartbeat\" h
  WHERE h.\"agentId\" = a.id
  ORDER BY h.\"createdAt\" DESC
  LIMIT 1
) hb ON true

LEFT JOIN LATERAL (
  SELECT COUNT(*)::int AS cnt
  FROM \"UsbEvent\" u
  WHERE u.\"agentId\" = a.id
    AND u.\"createdAt\" > now() - interval '24 hours'
) usb24 ON true

LEFT JOIN LATERAL (
  SELECT u.\"createdAt\", u.\"action\", u.\"vendor\", u.\"product\", u.\"serial\"
  FROM \"UsbEvent\" u
  WHERE u.\"agentId\" = a.id
  ORDER BY u.\"createdAt\" DESC
  LIMIT 1
) ulast ON true

LEFT JOIN LATERAL (
  SELECT
    COUNT(*)::int AS connected_cnt,
    MAX(s.\"lastChangeAt\") AS last_change
  FROM \"UsbDeviceState\" s
  WHERE s.\"agentId\" = a.id
    AND s.\"status\" = 'connected'
) usbst ON true

ORDER BY a.\"lastSeen\" DESC NULLS LAST, a.id DESC
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Conteos online/offline (usando ageSec desde SQL)
$onlineCount = 0;
foreach ($rows as $r) {
    $age = isset($r['ageSec']) ? (int) $r['ageSec'] : PHP_INT_MAX;
    if ($age <= $onlineThresholdSec)
        $onlineCount++;
}
$total = count($rows);
$offlineCount = $total - $onlineCount;

// -------------------------------------------------------------------------------------


header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/gyrosfe/assets/app.css?v=1">
</head>

<body>

        <div class="dashboard">
        <!-- Header -->
        <header class="header">

          <div class="header-left">
            <span class="user-label"><?= esc($userName) ?></span>
          </div>

          <div class="header-right">

            <!-- <button class="icon-btn" id="btn-settings" title="Configuración">
              <span class="settings-btn">⚙️</span>
            </button> -->
            <button class="icon-btn" title="Salir" onclick="window.location.href='/gyrosfe/ui/logout.php'">
              <span class="logout-btn">❗</span>
            </button>
          </div>

        </header>

        <!-- Main Content -->
        <main class="main-content">
          <!-- Top Cards Section -->
            <section class="top-cards">

                <!-- Cliente Card -->
                <div class="card cliente-card">
                    <!-- header -->
                    <div class="card-header">
                        <div class="card-header-left">
                            <h3>CLIENTE</h3>
                        </div>
                        <div class="card-header-right">
                            <button class="icon-btn-link" title="Buscar cliente">
                                <span class="icon-placeholder">📲</span>
                            </button>
                            <button class="icon-btn-link" title="Agregar nuevo cliente">
                                <span class="icon-placeholder">➕</span>
                            </button>
                        </div>
                    </div>
                    <!-- body -->
                    <div class="card-body">
                        <div class="input-group">
                            <label>CI</label>
                            <div class="input-container">
                                <input type="text" id="ci-input" placeholder="Ingrese CI">
                                <span class="icon-placeholder">🔍</span>
                            </div>
                        </div>
                    </div>
                </div>

            </section>

          <!-- Lista de Clientes Section -->
          <section class="clientes-section">
            <div class="card lista-clientes-card">
                <!-- Aquí se cargará la tabla de clientes dinámicamente -->
            </div>
          </section>
        </main>
        </div>


    <div class="meta">
        <span class="pill ok">Online: <?= (int) $onlineCount ?></span>
        <span class="pill bad">Offline: <?= (int) $offlineCount ?></span>
        <span class="pill muted">Total: <?= (int) $total ?></span>
        <span class="small">Threshold: <?= (int) $onlineThresholdSec ?>s · UTC:
            <?= esc($now->format('Y-m-d H:i:s')) ?></span>
    </div>

    <div class="toolbar">
        <input id="q" type="search" placeholder="Buscar por agentId, hostname, IP, versión..." oninput="filterRows()" />
        <a href="" title="Refrescar">Refrescar</a>
    </div>


    <table id="tbl">
        <thead>
            <tr>
                <th>Status</th>
                <th>Agent</th>
                <th>Last Seen</th>
                <th>IPs</th>
                <th>Version</th>
                <th>Last Heartbeat</th>
                <th class="right">Connected</th>
                <th class="right">USB 24h</th>
                <th>Last USB</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $age = isset($r['ageSec']) ? (int) $r['ageSec'] : PHP_INT_MAX;
                $status = ($age <= $onlineThresholdSec) ? 'online' : 'offline';
                $ageTxt = ($age === PHP_INT_MAX) ? '—' : ($age . 's');

                $pillClass = ($status === 'online') ? 'ok' : 'bad';
                $pillText = ($status === 'online') ? 'ONLINE' : 'OFFLINE';

                $agentId = (string) ($r['agentId'] ?? '');
                $host = (string) ($r['hostname'] ?? '');
                $lastIp = (string) ($r['lastIp'] ?? '');
                $hbLocal = (string) ($r['hbLocalIp'] ?? '');
                $ver = (string) ($r['version'] ?? '');

                $lastSeenStr = !empty($r['lastSeen'])
                    ? (new DateTimeImmutable($r['lastSeen'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    : '—';

                $hbAtStr = !empty($r['hbAt'])
                    ? (new DateTimeImmutable($r['hbAt'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    : '—';

                $hbUp = isset($r['hbUptimeSec']) && $r['hbUptimeSec'] !== null ? (string) $r['hbUptimeSec'] : '';

                $usbNow = (int) ($r['usbConnectedNow'] ?? 0);
                $usb24 = (int) ($r['usb24Count'] ?? 0);

                $usbLastAtFmt = !empty($r['usbLastAt'])
                    ? (new DateTimeImmutable($r['usbLastAt'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    : '';

                $usbLastAction = (string) ($r['usbLastAction'] ?? '');
                $usbVendor = (string) ($r['usbLastVendor'] ?? '');
                $usbProduct = (string) ($r['usbLastProduct'] ?? '');
                $usbSerial = (string) ($r['usbLastSerial'] ?? '');
                $usbSummary = trim($usbVendor . ' ' . $usbProduct);
                // 🔔 Campana si último evento fue disconnect y es reciente
                $disconnectBell = false;
                if ($usbLastAction === 'disconnect' && !empty($r['usbLastAt'])) {
                    $t = new DateTimeImmutable($r['usbLastAt'], new DateTimeZone('UTC'));
                    $bellAge = $now->getTimestamp() - $t->getTimestamp();
                    if ($bellAge >= 0 && $bellAge <= ($disconnectMinutes * 60))
                        $disconnectBell = true;
                }
                ?>
                <tr>
                    <td>
                        <span class="pill <?= esc($pillClass) ?>">
                            <?= esc($pillText) ?>
                        </span>
                        <div class="small">age:
                            <?= esc($ageTxt) ?>
                        </div>
                    </td>

                    <td>
                        <div class="mono">
                            <?= esc($agentId) ?>
                            <?php if ($disconnectBell): ?>
                                <span title="Disconnect reciente (<= <?= (int) $disconnectMinutes ?> min)"> 🔔</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($host !== ''): ?>
                            <div class="small">
                                <?= esc($host) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="mono">
                            <?= esc($lastSeenStr) ?>
                        </div>
                    </td>

                    <td>
                        <?php if ($lastIp !== ''): ?>
                            <div><span class="small">public:</span> <span class="mono">
                                    <?= esc($lastIp) ?>
                                </span></div>
                        <?php endif; ?>
                        <?php if ($hbLocal !== ''): ?>
                            <div><span class="small">local:</span> <span class="mono">
                                    <?= esc($hbLocal) ?>
                                </span></div>
                        <?php endif; ?>
                        <?php if ($lastIp === '' && $hbLocal === ''): ?>
                            <span class="small">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="mono">
                        <?= $ver !== '' ? esc($ver) : '—' ?>
                    </td>

                    <td>
                        <div class="mono">
                            <?= esc($hbAtStr) ?>
                        </div>
                        <?php if ($hbUp !== ''): ?>
                            <div class="small">uptimeSec: <span class="mono">
                                    <?= esc($hbUp) ?>
                                </span></div>
                        <?php endif; ?>
                    </td>

                    <td class="right">
                        <span class="pill <?= $usbNow > 0 ? 'ok' : 'muted' ?>">
                            <?= (int) $usbNow ?>
                        </span>
                    </td>

                    <td class="right">
                        <span class="pill <?= $usb24 > 0 ? 'ok' : 'muted' ?>">
                            <?= (int) $usb24 ?>
                        </span>
                    </td>

                    <td>
                        <?php if ($usbLastAtFmt !== ''): ?>
                            <div class="mono">
                                <?= esc($usbLastAtFmt) ?>
                            </div>
                            <div class="small">
                                Action: <span class="mono">
                                    <?= esc(strtoupper($usbLastAction !== '' ? $usbLastAction : '—')) ?>
                                </span>
                            </div>
                            <div class="small">
                                <?= $usbSummary !== '' ? esc($usbSummary) : '—' ?>
                                <?php if ($usbSerial !== ''): ?>
                                    · <span class="mono">
                                        <?= esc($usbSerial) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="small">—</span>
                        <?php endif; ?>

                        <div class="small" style="margin-top:4px;">
                            <a href="/gyrosfe/agent/usb_events.php?agent=<?= urlencode($agentId) ?>">Ver USB</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
        function filterRows() {
            const q = document.getElementById('q').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#tbl tbody tr');
            rows.forEach(tr => {
                const txt = tr.innerText.toLowerCase();
                tr.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
            });
        }
    </script>




</body>

</html>


