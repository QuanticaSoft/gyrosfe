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

// -------------------------------------------------------------------------------------
// Query: dispositivos ACTUALMENTE CONECTADOS al USB del agente
// — Fuente de verdad: UsbDeviceState (estado en tiempo real, actualizado por el agente)
// — Solo status = 'connected'
// — Solo agentes con lastSeen dentro del umbral (ONLINE)
// — Estado válido: lastChangeAt >= arranque del agente (lastSeen - uptimeSec)
//   Esto descarta estados obsoletos de sesiones anteriores del agente
// -------------------------------------------------------------------------------------
$sqlUsb = <<<'SQL'
SELECT
  uds.serial                               AS ev_serial,
  uds.vendor                               AS ev_vendor,
  uds.product                              AS ev_product,
  uds."lastChangeAt"                       AS ev_at,

  a."agentId"                              AS ag_agentId,
  a.hostname                               AS ag_hostname,

  cl.uuid                                  AS cl_uuid,
  cl.nombrecompleto                        AS cl_nombre,
  cl.ci                                    AS cl_ci,
  cl.numerocelular                         AS cl_celular,
  cl.numerofijo                            AS cl_fijo,
  cl.vtotarjeta                            AS cl_vtotarjeta,
  cl.codigo                                AS cl_codigo,
  cl.sector                                AS cl_sector,
  cl."isActive"                            AS cl_activo,
  cl.garantenombre                         AS cl_garantenombre,
  cl.garantecelular                        AS cl_garantecelular,
  cl.observaciones                         AS cl_observaciones,
  cl.fecharegistro                         AS cl_fecharegistro

FROM "UsbDeviceState" uds
JOIN "Agent" a ON a.id = uds."agentId"
LEFT JOIN LATERAL (
    SELECT "uptimeSec"
    FROM "Heartbeat"
    WHERE "agentId" = a.id
    ORDER BY "createdAt" DESC
    LIMIT 1
) hb ON true
LEFT JOIN "Cliente" cl ON cl.dispositivo = uds.serial

WHERE
    -- Dispositivo físicamente conectado AHORA
    lower(uds.status) = 'connected'
    -- Agente ONLINE: lastSeen dentro del umbral
    AND EXTRACT(EPOCH FROM (now() - a."lastSeen"))::int <= :threshold
    -- Estado reportado en la sesión actual del agente (no es un estado obsoleto de sesiones anteriores)
    AND uds."lastChangeAt" >= (a."lastSeen" - COALESCE(hb."uptimeSec", 0) * interval '1 second')

ORDER BY uds."lastChangeAt" DESC
SQL;

$stmtUsb = $pdo->prepare($sqlUsb);
$stmtUsb->execute([':threshold' => $onlineThresholdSec]);
$usbRows = $stmtUsb->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/gyrosfe/assets/app.css?v=1">
    <style>
        /* ── Modales ────────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }

        .modal-box {
            background: #1e2130;
            border: 1px solid #33374d;
            border-radius: 10px;
            padding: 28px 32px;
            min-width: 360px;
            max-width: 520px;
            width: 100%;
            color: #e0e4f0;
            position: relative;
        }
        .modal-box h2 { margin: 0 0 20px; font-size: 1.1rem; }
        .modal-close {
            position: absolute;
            top: 14px; right: 18px;
            background: none; border: none;
            color: #888; font-size: 1.4rem;
            cursor: pointer;
        }
        .modal-close:hover { color: #fff; }

        .modal-form label {
            display: block;
            font-size: .78rem;
            color: #9aa0b8;
            margin-bottom: 3px;
            margin-top: 12px;
        }
        .modal-form input, .modal-form select {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #33374d;
            background: #131624;
            color: #e0e4f0;
            font-size: .9rem;
        }
        .modal-form input[readonly] {
            opacity: .6;
            cursor: not-allowed;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .btn-primary {
            padding: 8px 20px;
            border-radius: 6px;
            border: none;
            background: #4f6ef7;
            color: #fff;
            font-size: .9rem;
            cursor: pointer;
        }
        .btn-primary:hover { background: #3a57e8; }
        .btn-cancel {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #33374d;
            background: transparent;
            color: #9aa0b8;
            font-size: .9rem;
            cursor: pointer;
        }
        .btn-cancel:hover { color: #fff; border-color: #888; }

        /* info-grid dentro del modal 📝 */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-top: 4px; }
        .info-cell label { font-size:.75rem; color:#9aa0b8; }
        .info-cell p { margin:2px 0 0; font-size:.9rem; }

        /* ── Badge online en toolbar ───────────────────────────── */
        .badge-online {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(52, 211, 153, .15);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, .35);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .04em;
        }
        .badge-online::before {
            content: '';
            display: inline-block;
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 6px #34d399;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50%       { opacity: .4; }
        }

        /* ── Botones de acción en la tabla ─────────────────────── */
        .btn-accion {
            width: auto;
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            line-height: 1;
            padding: 2px 4px;
            border-radius: 4px;
            transition: background .15s;
        }
        .btn-accion:hover { background: rgba(255,255,255,.1); }

        /* ── Info del dispositivo en modal ────────────────────── */
        .device-info-bar {
            background: #131624;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: .82rem;
            color: #9aa0b8;
        }
        .device-info-bar span { color: #c8d0ea; margin-left: 4px; }

        /* ── Layout de 2 columnas dentro del modal ──────────────── */
        .form-row {
            display: grid;
            gap: 0 14px;
        }
        .form-row.col2 { grid-template-columns: 1fr 1fr; }
        .form-row.col3 { grid-template-columns: 1fr 1fr 1fr; }

        /* ── Separador de sección dentro del modal ──────────────── */
        .form-section {
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px solid #33374d;
            font-size: .78rem;
            font-weight: 700;
            color: #c8d0ea;
            letter-spacing: .06em;
        }

        /* modal más ancho para caber los nuevos campos */
        #modal-registrar .modal-box,
        #modal-editar .modal-box {
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
        }

        /* ── Modal 📲 Registro Cliente (tabbed) ────────────────── */
        #modal-registro .modal-box {
            width: 1020px;
            max-width: 97vw;
            height: 680px;
            max-height: 94vh;
            display: flex;
            flex-direction: column;
            padding: 0;
            overflow: hidden;
        }
        .registro-header {
            flex-shrink: 0;
            padding: 20px 28px 0;
            border-bottom: 1px solid #33374d;
        }
        .registro-header h2 {
            margin: 0 0 14px;
            font-size: 1.05rem;
            color: #e0e4f0;
            padding-right: 32px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .registro-header h2 span {
            color: #7b93ff;
        }

        /* Tabs nav */
        .tab-nav {
            display: flex;
            gap: 0;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .tab-nav li {
            padding: 9px 22px;
            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .07em;
            color: #9aa0b8;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            user-select: none;
            transition: color .15s, border-color .15s;
        }
        .tab-nav li:hover { color: #c8d0ea; }
        .tab-nav li.active {
            color: #7b93ff;
            border-bottom-color: #7b93ff;
        }

        /* Tab panels — cuerpo de altura fija con scroll interno */
        .tab-body {
            flex: 1;
            overflow-y: auto;
            padding: 22px 28px 28px;
        }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* General info grid */
        .gen-section {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .07em;
            color: #7b93ff;
            text-transform: uppercase;
            margin: 18px 0 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #2a2f45;
        }
        .gen-section:first-child { margin-top: 0; }
        .gen-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
        }
        .gen-grid.col3 { grid-template-columns: 1fr 1fr 1fr; }
        .gen-cell label {
            display: block;
            font-size: .72rem;
            color: #9aa0b8;
            margin-bottom: 2px;
        }
        .gen-cell p {
            margin: 0;
            font-size: .9rem;
            color: #e0e4f0;
            word-break: break-word;
        }
        .gen-cell p.empty { color: #555c7a; font-style: italic; }

        /* Toggle switch activo */
        .toggle-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 2px;
        }
        .toggle-switch {
            position: relative;
            width: 42px;
            height: 22px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute;
            inset: 0;
            border-radius: 22px;
            background: #33374d;
            cursor: pointer;
            transition: background .2s;
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            top: 3px; left: 3px;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: #9aa0b8;
            transition: transform .2s, background .2s;
        }
        .toggle-switch input:checked + .toggle-track {
            background: rgba(52,211,153,.35);
            border: 1px solid rgba(52,211,153,.5);
        }
        .toggle-switch input:checked + .toggle-track::after {
            transform: translateX(20px);
            background: #34d399;
        }
        .toggle-switch input:disabled + .toggle-track { opacity: .5; cursor: not-allowed; }
        .toggle-label {
            font-size: .88rem;
            font-weight: 600;
            transition: color .2s;
        }
        .toggle-label.activo  { color: #34d399; }
        .toggle-label.inactivo { color: #f87171; }
        .toggle-saving { font-size: .75rem; color: #9aa0b8; margin-left: 4px; display: none; }

        /* Placeholder tabs */
        .tab-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            min-height: 340px;
            color: #555c7a;
            font-size: .9rem;
            gap: 10px;
        }
        .tab-placeholder span { font-size: 2.2rem; }

        /* ── Tabla de Préstamos ─────────────────────────────────── */
        .prest-table-wrap { overflow-x: auto; }
        .prest-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
        }
        .prest-table th {
            background: #1a1f35;
            color: #7b93ff;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 2px solid #2a2f45;
            white-space: nowrap;
        }
        .prest-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #1e2235;
            color: #c8d0ea;
            vertical-align: middle;
        }
        .prest-table tr:hover td { background: rgba(123,147,255,.06); }
        .prest-table .td-add {
            text-align: center;
            color: #555c7a;
            font-style: italic;
            font-size: .8rem;
        }
        .prest-table .td-num { text-align: right; font-family: monospace; }

        /* ── Sección Pagos (por préstamo) ───────────────────────── */
        .pago-bloque { margin-bottom: 28px; }
        .pago-bloque-header {
            background: #1a1f35;
            border-left: 3px solid #7b93ff;
            padding: 8px 14px;
            margin-bottom: 8px;
            border-radius: 4px;
            font-size: .82rem;
            color: #c8d0ea;
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }
        .pago-bloque-header strong { color: #7b93ff; }
        .pago-table-wrap { overflow-x: auto; }
        .pago-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .78rem;
        }
        .pago-table th {
            background: #131624;
            color: #9aa0b8;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: center;
            border-bottom: 2px solid #2a2f45;
            white-space: nowrap;
        }
        .pago-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #1a1e2f;
            color: #c8d0ea;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        .pago-table tr:hover td { background: rgba(255,255,255,.03); }
        /* Estados */
        .estado-pagado   { color: #34d399; font-weight: 700; }
        .estado-pendiente{ color: #fbbf24; font-weight: 700; }
        .estado-vencido  { color: #f87171; font-weight: 700; }
        .estado-parcial  { color: #60a5fa; font-weight: 700; }
        .estado-default  { color: #9aa0b8; }

        /* ── Modal genérico flotante (préstamos) ────────────────── */
        .modal-float {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.6);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-float.active { display: flex; }
        .modal-float-box {
            background: #1e2130;
            border: 1px solid #33374d;
            border-radius: 10px;
            padding: 26px 30px;
            width: 400px;
            max-width: 96vw;
            color: #e0e4f0;
            position: relative;
        }
        .modal-float-box h3 { margin: 0 0 18px; font-size: 1rem; }
        .mf-close {
            position: absolute; top: 12px; right: 14px;
            background: none; border: none; color: #888;
            font-size: 1.3rem; cursor: pointer;
        }
        .mf-close:hover { color: #fff; }
        .mf-label {
            display: block;
            font-size: .75rem;
            color: #9aa0b8;
            margin: 10px 0 3px;
        }
        .mf-input {
            width: 100%; box-sizing: border-box;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #33374d;
            background: #131624;
            color: #e0e4f0;
            font-size: .9rem;
        }
        .mf-note {
            font-size: .72rem;
            color: #555c7a;
            margin-top: 3px;
        }
        .mf-actions {
            display: flex; gap: 10px;
            justify-content: flex-end;
            margin-top: 18px;
        }
    </style>
</head>

<body>

    <div class="dashboard">
        <!-- Header -->
        <header class="header">
          <div class="header-left">
            <span class="user-label"><?= esc($userName) ?></span>
          </div>
          <div class="header-right">
            <button class="icon-btn" title="Salir" onclick="window.location.href='/gyrosfe/ui/logout.php'">
              <span class="logout-btn">❗</span>
            </button>
          </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
          <section class="top-cards"></section>
          <section class="clientes-section">
            <div class="card lista-clientes-card"></div>
          </section>
        </main>
    </div>

    <!-- Buscar -->
    <div class="toolbar">
        <input id="q" type="search" placeholder="Buscar por agente, serial, dispositivo, cliente..." oninput="filterRows()" />
        <a href="" title="Refrescar">Refrescar</a>
        <span class="badge-online"><?= count($usbRows) ?> online</span>
    </div>

    <!-- ── Tabla: dispositivos ONLINE + conectados ahora ─────────── -->
    <table id="tbl">
        <thead>
            <tr>
                <th>Última conexión</th>
                <th>Agente</th>
                <th>Dispositivo</th>
                <th>Cliente</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($usbRows)): ?>
            <tr><td colspan="5" style="text-align:center;color:#9aa0b8;padding:32px">No hay dispositivos online y conectados en este momento.</td></tr>
        <?php endif; ?>
        <?php foreach ($usbRows as $r):
            // ── Datos del dispositivo ──────────────────────────────
            $evAt    = !empty($r['ev_at'])
                ? (new DateTimeImmutable($r['ev_at'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                : '—';
            $agente    = esc((string) ($r['ag_hostname'] ?? $r['ag_agentid'] ?? '—'));
            $vendor    = esc((string) ($r['ev_vendor']  ?? ''));
            $product   = esc((string) ($r['ev_product'] ?? ''));
            $serial    = esc((string) ($r['ev_serial']  ?? ''));
            $dispLabel = trim("$vendor $product") ?: '—';

            // ── ¿Registrado en Cliente? ────────────────────────────
            $clienteUuid = (string) ($r['cl_uuid'] ?? '');
            $registrado  = ($clienteUuid !== '');

            // ── Datos cliente (para modal 📝) ──────────────────────
            $clNombre        = esc((string) ($r['cl_nombre']        ?? ''));
            $clCi            = esc((string) ($r['cl_ci']            ?? ''));
            $clCelular       = esc((string) ($r['cl_celular']       ?? ''));
            $clFijo          = esc((string) ($r['cl_fijo']          ?? ''));
            $clVtoTarjeta    = esc((string) ($r['cl_vtotarjeta']    ?? ''));
            $clCodigo        = esc((string) ($r['cl_codigo']        ?? ''));
            $clSector        = esc((string) ($r['cl_sector']        ?? ''));
            $clActivo        = !empty($r['cl_activo']) ? 'Sí' : 'No';
            $clGaranteNombre = esc((string) ($r['cl_garantenombre'] ?? ''));
            $clGaranteCel    = esc((string) ($r['cl_garantecelular']?? ''));
            $clObservaciones = esc((string) ($r['cl_observaciones'] ?? ''));
            $clFecha         = !empty($r['cl_fecharegistro'])
                ? (new DateTimeImmutable($r['cl_fecharegistro'], new DateTimeZone('UTC')))->format('Y-m-d H:i')
                : '—';
        ?>
            <tr>
                <!-- Última conexión -->
                <td class="mono"><?= esc($evAt) ?></td>

                <!-- Agente -->
                <td><?= $agente ?></td>

                <!-- Dispositivo -->
                <td>
                    <div><?= $dispLabel ?></div>
                    <?php if ($serial !== ''): ?>
                        <div class="small mono"><?= $serial ?></div>
                    <?php endif; ?>
                </td>

                <!-- Cliente -->
                <td>
                <?php if ($registrado): ?>
                    <div><?= $clNombre ?></div>
                    <div class="small mono">CI: <?= $clCi ?></div>
                <?php else: ?>
                    <span style="color:#9aa0b8">—</span>
                <?php endif; ?>
                </td>

                <!-- Acción -->
                <td>
                <?php if (!$registrado): ?>
                    <!-- ➕ No registrado: abrir modal de registro -->
                    <button
                        class="btn-accion"
                        title="Registrar dispositivo"
                        onclick="abrirModalRegistrar(
                            '<?= $serial ?>',
                            '<?= addslashes($dispLabel) ?>',
                            '<?= addslashes((string)($r['ag_agentid'] ?? '')) ?>'
                        )"
                    >➕</button>
                <?php else: ?>
                    <div style="display:inline-flex;align-items:center;gap:2px">
                        <!-- 📲 Registro/expediente del cliente — izquierda -->
                        <button
                            class="btn-accion"
                            title="Registro del cliente"
                            onclick="abrirModalRegistro(<?= htmlspecialchars(json_encode([
                                'uuid'           => $clienteUuid,
                                'nombre'         => $r['cl_nombre']         ?? '',
                                'ci'             => $r['cl_ci']             ?? '',
                                'celular'        => $r['cl_celular']        ?? '',
                                'fijo'           => $r['cl_fijo']           ?? '',
                                'vtotarjeta'     => $r['cl_vtotarjeta']     ?? '',
                                'codigo'         => $r['cl_codigo']         ?? '',
                                'sector'         => $r['cl_sector']         ?? '',
                                'activo'         => !empty($r['cl_activo']),
                                'garantenombre'  => $r['cl_garantenombre']  ?? '',
                                'garantecelular' => $r['cl_garantecelular'] ?? '',
                                'observaciones'  => $r['cl_observaciones']  ?? '',
                                'fecharegistro'  => $clFecha,
                                'serial'         => $r['ev_serial']         ?? '',
                                'dispositivo'    => trim(($r['ev_vendor'] ?? '') . ' ' . ($r['ev_product'] ?? '')),
                            ]), ENT_QUOTES, 'UTF-8') ?>)"
                        >📲</button>
                        <!-- 📝 Ver / Editar cliente — derecha -->
                        <button
                            class="btn-accion"
                            title="Ver / Editar cliente"
                            onclick="abrirModalEditar(
                                '<?= $clienteUuid ?>',
                                '<?= $serial ?>',
                                '<?= addslashes($dispLabel) ?>',
                                '<?= $clNombre ?>',
                                '<?= $clCi ?>',
                                '<?= $clCelular ?>',
                                '<?= $clFijo ?>',
                                '<?= $clVtoTarjeta ?>',
                                '<?= $clCodigo ?>',
                                '<?= $clSector ?>',
                                '<?= $clActivo ?>',
                                '<?= addslashes($clGaranteNombre) ?>',
                                '<?= $clGaranteCel ?>',
                                '<?= addslashes($clObservaciones) ?>',
                                '<?= $clFecha ?>'
                            )"
                        >📝</button>
                    </div>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>


    <!-- ════════════════════════════════════════════════════════════
         MODAL ➕  Registrar dispositivo con cliente nuevo
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-registrar" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-registrar')" title="Cerrar">✕</button>
            <h2>➕ Registrar Dispositivo</h2>

            <div class="device-info-bar" id="reg-device-info"></div>

            <form class="modal-form" id="form-registrar" onsubmit="submitRegistrar(event)">
                <input type="hidden" id="reg-serial" name="serial">
                <input type="hidden" id="reg-agentId" name="agentId">

                <label>Nombre Completo *</label>
                <input type="text" id="reg-nombre" name="nombrecompleto" required maxlength="255" placeholder="Ej: Juan Pérez">

                <div class="form-row col3">
                    <div>
                        <label>CI / Cédula *</label>
                        <input type="text" id="reg-ci" name="ci" required maxlength="50" placeholder="Ej: 3456289">
                    </div>
                    <div>
                        <label>Celular *</label>
                        <input type="text" id="reg-celular" name="numerocelular" required maxlength="20" placeholder="Ej: 69123456">
                    </div>
                    <div>
                        <label>Fijo</label>
                        <input type="text" id="reg-fijo" name="numerofijo" maxlength="20" placeholder="Ej: 2123456">
                    </div>
                </div>

                <div class="form-row col3">
                    <div>
                        <label>Vto. Tarjeta *</label>
                        <input type="text" id="reg-vtotarjeta" name="vtotarjeta" required maxlength="10" placeholder="MM/AAAA">
                    </div>
                    <div>
                        <label>Sector(Grupo) *</label>
                        <select id="reg-sector" name="sector" required>
                            <option value="">Seleccionar...</option>
                            <option value="Magisterio">Magisterio</option>
                            <option value="Salud">Salud</option>
                        </select>
                    </div>
                    <div>
                        <label>Código *</label>
                        <input type="text" id="reg-codigo" name="codigo" required maxlength="50" placeholder="Ej: COD-001">
                    </div>
                </div>

                <div class="form-section">GARANTE</div>

                <div class="form-row col2">
                    <div>
                        <label>Nombre Completo *</label>
                        <input type="text" id="reg-garantenombre" name="garantenombre" required maxlength="255" placeholder="Ej: María López">
                    </div>
                    <div>
                        <label>Celular *</label>
                        <input type="text" id="reg-garantecelular" name="garantecelular" required maxlength="20" placeholder="Ej: 69987654">
                    </div>
                </div>

                <label>Observaciones</label>
                <textarea id="reg-observaciones" name="observaciones" maxlength="1000" rows="3"
                    style="width:100%;box-sizing:border-box;padding:7px 10px;border-radius:6px;border:1px solid #33374d;background:#131624;color:#e0e4f0;font-size:.9rem;resize:vertical;"
                    placeholder="Notas adicionales..."></textarea>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modal-registrar')">Cancelar</button>
                    <button type="submit" class="btn-primary" id="btn-reg-submit">Registrar</button>
                </div>
            </form>
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════
         MODAL 📝  Ver / Editar cliente registrado
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-editar" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-editar')" title="Cerrar">✕</button>
            <h2>📝 Información del Cliente</h2>

            <div class="device-info-bar" id="edit-device-info"></div>

            <form class="modal-form" id="form-editar" onsubmit="submitEditar(event)">
                <input type="hidden" id="edit-uuid" name="uuid">
                <input type="hidden" id="edit-serial" name="serial">

                <label>Nombre Completo *</label>
                <input type="text" id="edit-nombre" name="nombrecompleto" required maxlength="255">

                <div class="form-row col3">
                    <div>
                        <label>CI / Cédula *</label>
                        <input type="text" id="edit-ci" name="ci" required maxlength="50">
                    </div>
                    <div>
                        <label>Celular *</label>
                        <input type="text" id="edit-celular" name="numerocelular" required maxlength="20">
                    </div>
                    <div>
                        <label>Fijo</label>
                        <input type="text" id="edit-fijo" name="numerofijo" maxlength="20">
                    </div>
                </div>

                <div class="form-row col3">
                    <div>
                        <label>Vto. Tarjeta *</label>
                        <input type="text" id="edit-vtotarjeta" name="vtotarjeta" required maxlength="10">
                    </div>
                    <div>
                        <label>Sector(Grupo) *</label>
                        <select id="edit-sector" name="sector" required>
                            <option value="">Seleccionar...</option>
                            <option value="Magisterio">Magisterio</option>
                            <option value="Salud">Salud</option>
                        </select>
                    </div>
                    <div>
                        <label>Código *</label>
                        <input type="text" id="edit-codigo" name="codigo" required maxlength="50">
                    </div>
                </div>

                <div class="form-row col2">
                    <div>
                        <label>Activo</label>
                        <select id="edit-activo" name="isActive">
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div>
                        <label>Fecha Registro</label>
                        <input type="text" id="edit-fecharegistro" readonly style="opacity:.6;cursor:not-allowed;">
                    </div>
                </div>

                <div class="form-section">GARANTE</div>

                <div class="form-row col2">
                    <div>
                        <label>Nombre Completo *</label>
                        <input type="text" id="edit-garantenombre" name="garantenombre" required maxlength="255">
                    </div>
                    <div>
                        <label>Celular *</label>
                        <input type="text" id="edit-garantecelular" name="garantecelular" required maxlength="20">
                    </div>
                </div>

                <label>Observaciones</label>
                <textarea id="edit-observaciones" name="observaciones" maxlength="1000" rows="3"
                    style="width:100%;box-sizing:border-box;padding:7px 10px;border-radius:6px;border:1px solid #33374d;background:#131624;color:#e0e4f0;font-size:.9rem;resize:vertical;"></textarea>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modal-editar')">Cancelar</button>
                    <button type="submit" class="btn-primary" id="btn-edit-submit">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════
         MODAL 📲  Registro / Expediente del cliente  (tab menu)
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-registro" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-registro')" title="Cerrar">✕</button>

            <!-- Cabecera con título dinámico + tabs nav -->
            <div class="registro-header">
                <h2>Registro Cliente &mdash; <span id="reg-tab-nombre"></span></h2>
                <ul class="tab-nav" id="reg-tabs">
                    <li class="active" data-tab="tab-general">GENERAL</li>
                    <li data-tab="tab-prestamos">PRESTAMOS</li>
                    <li data-tab="tab-pagos">PAGOS</li>
                    <li data-tab="tab-banco">BANCO</li>
                </ul>
            </div>

            <!-- Cuerpo con panels -->
            <div class="tab-body">

                <!-- ── GENERAL ──────────────────────────────────── -->
                <div id="tab-general" class="tab-panel active">

                    <!-- Dispositivo -->
                    <div class="gen-section">Dispositivo</div>
                    <div class="gen-grid col3">
                        <div class="gen-cell">
                            <label>Serial</label>
                            <p id="gen-serial"></p>
                        </div>
                        <div class="gen-cell" style="grid-column:span 2">
                            <label>Dispositivo</label>
                            <p id="gen-dispositivo"></p>
                        </div>
                    </div>

                    <!-- Datos personales -->
                    <div class="gen-section">Datos Personales</div>
                    <div class="gen-grid">
                        <div class="gen-cell" style="grid-column:span 2">
                            <label>Nombre Completo</label>
                            <p id="gen-nombre"></p>
                        </div>
                    </div>
                    <div class="gen-grid col3" style="margin-top:10px">
                        <div class="gen-cell">
                            <label>CI / Cédula</label>
                            <p id="gen-ci"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Celular</label>
                            <p id="gen-celular"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Teléfono Fijo</label>
                            <p id="gen-fijo"></p>
                        </div>
                    </div>
                    <div class="gen-grid col3" style="margin-top:10px">
                        <div class="gen-cell">
                            <label>Sector / Grupo</label>
                            <p id="gen-sector"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Código</label>
                            <p id="gen-codigo"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Vto. Tarjeta</label>
                            <p id="gen-vtotarjeta"></p>
                        </div>
                    </div>
                    <div class="gen-grid" style="margin-top:10px">
                        <div class="gen-cell">
                            <label>Estado del Cliente</label>
                            <div class="toggle-wrap">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="gen-activo-chk" onchange="toggleActivo(this)">
                                    <span class="toggle-track"></span>
                                </label>
                                <span class="toggle-label" id="gen-activo-label">—</span>
                                <span class="toggle-saving" id="gen-activo-saving">Guardando…</span>
                            </div>
                        </div>
                        <div class="gen-cell">
                            <label>Fecha de Registro</label>
                            <p id="gen-fecha"></p>
                        </div>
                    </div>

                    <!-- Garante -->
                    <div class="gen-section">Garante</div>
                    <div class="gen-grid">
                        <div class="gen-cell">
                            <label>Nombre Completo</label>
                            <p id="gen-garantenombre"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Celular</label>
                            <p id="gen-garantecelular"></p>
                        </div>
                    </div>

                    <!-- Observaciones -->
                    <div class="gen-section">Observaciones</div>
                    <div class="gen-cell">
                        <p id="gen-observaciones" style="white-space:pre-wrap"></p>
                    </div>

                </div><!-- /tab-general -->

                <!-- ── PRESTAMOS ─────────────────────────────────── -->
                <div id="tab-prestamos" class="tab-panel">
                    <div id="prest-loading" class="tab-placeholder" style="display:none">
                        <span>⏳</span>Cargando préstamos…
                    </div>
                    <div id="prest-content"></div>
                </div>

                <!-- ── PAGOS ─────────────────────────────────────── -->
                <div id="tab-pagos" class="tab-panel">
                    <div id="pagos-loading" class="tab-placeholder" style="display:none">
                        <span>⏳</span>Cargando pagos…
                    </div>
                    <div id="pagos-content"></div>
                </div>

                <!-- ── BANCO ─────────────────────────────────────── -->
                <div id="tab-banco" class="tab-panel">
                    <div class="tab-placeholder">
                        <span>🏦</span>
                        Módulo Bancario — próximamente
                    </div>
                </div>

            </div><!-- /tab-body -->
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════
         MODAL ➕  Nuevo Préstamo
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-nuevo-prest" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box">
            <button class="mf-close" onclick="cerrarModalFloat('modal-nuevo-prest')">✕</button>
            <h3>➕ Nuevo Préstamo</h3>
            <label class="mf-label">Fecha de Préstamo *</label>
            <input type="date" id="np-fecha" class="mf-input">
            <label class="mf-label">Monto Prestado (Bs.) *</label>
            <input type="number" id="np-monto" class="mf-input" min="0.01" step="0.01" placeholder="0.00" oninput="recalcCuota()">
            <label class="mf-label">Tasa de Interés Mensual (%) *</label>
            <input type="number" id="np-tasa" class="mf-input" min="0" step="0.01" placeholder="0.00" oninput="recalcCuota()">
            <label class="mf-label">Meses *</label>
            <input type="number" id="np-meses" class="mf-input" min="1" step="1" placeholder="12" oninput="recalcCuota()">
            <label class="mf-label">Cuota Mensual (Bs.) — calculado automáticamente</label>
            <input type="text" id="np-cuota" class="mf-input" readonly style="opacity:.6;cursor:not-allowed;">
            <div class="mf-note" id="np-totales"></div>
            <div class="mf-actions">
                <button class="btn-cancel" onclick="cerrarModalFloat('modal-nuevo-prest')">Cancelar</button>
                <button class="btn-primary" id="btn-crear-prest" onclick="crearPrestamo()">Crear Préstamo</button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL ✏️  Editar Préstamo
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-editar-prest" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box">
            <button class="mf-close" onclick="cerrarModalFloat('modal-editar-prest')">✕</button>
            <h3>✏️ Editar Préstamo</h3>
            <input type="hidden" id="ep-id">
            <label class="mf-label">Monto Prestado (Bs.) *</label>
            <input type="number" id="ep-monto" class="mf-input" min="0.01" step="0.01" oninput="recalcCuotaEdit()">
            <label class="mf-label">Tasa de Interés Mensual (%) *</label>
            <input type="number" id="ep-tasa" class="mf-input" min="0" step="0.01" oninput="recalcCuotaEdit()">
            <label class="mf-label">Meses *</label>
            <input type="number" id="ep-meses" class="mf-input" min="1" step="1" oninput="recalcCuotaEdit()">
            <label class="mf-label">Cuota Mensual (Bs.) — calculado automáticamente</label>
            <input type="text" id="ep-cuota" class="mf-input" readonly style="opacity:.6;cursor:not-allowed;">
            <div class="mf-note" id="ep-totales"></div>
            <div class="mf-note" style="color:#f87171;margin-top:6px">⚠️ Se regenerarán las cuotas pendientes.</div>
            <div class="mf-actions">
                <button class="btn-cancel" onclick="cerrarModalFloat('modal-editar-prest')">Cancelar</button>
                <button class="btn-primary" id="btn-guardar-prest" onclick="guardarPrestamo()">Guardar Cambios</button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL 💸  Registrar Pago
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-reg-pago" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box">
            <button class="mf-close" onclick="cerrarModalFloat('modal-reg-pago')">✕</button>
            <h3>💸 Registrar Pago</h3>
            <input type="hidden" id="rp-id">
            <label class="mf-label">Fecha de Pago *</label>
            <input type="date" id="rp-fecha" class="mf-input">
            <label class="mf-label">Monto Pagado (Bs.) *</label>
            <input type="number" id="rp-monto" class="mf-input" min="0.01" step="0.01">
            <label class="mf-label">Monto Transferencia (Bs.)</label>
            <input type="number" id="rp-transf" class="mf-input" min="0" step="0.01">
            <label class="mf-label">Método de Pago</label>
            <select id="rp-metodo" class="mf-input">
                <option value="">— Seleccionar —</option>
                <option value="Transferencia">Transferencia</option>
                <option value="Efectivo">Efectivo</option>
                <option value="QR">QR</option>
                <option value="Débito">Débito</option>
            </select>
            <label class="mf-label">Estado</label>
            <select id="rp-estado" class="mf-input">
                <option value="pagado">Pagado</option>
                <option value="parcial">Parcial</option>
                <option value="vencido">Vencido</option>
                <option value="pendiente">Pendiente</option>
            </select>
            <div class="mf-actions">
                <button class="btn-cancel" onclick="cerrarModalFloat('modal-reg-pago')">Cancelar</button>
                <button class="btn-primary" id="btn-guardar-pago" onclick="guardarPago()">Guardar Pago</button>
            </div>
        </div>
    </div>

    <!-- ── Scripts ───────────────────────────────────────────────── -->
    <script>
    // ── Filtro de búsqueda ───────────────────────────────────────
    function filterRows() {
        const q = document.getElementById('q').value.toLowerCase().trim();
        const rows = document.querySelectorAll('#tbl tbody tr');
        rows.forEach(tr => {
            const txt = tr.innerText.toLowerCase();
            tr.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
        });
    }

    // ── Helpers de modal ─────────────────────────────────────────
    function abrirModal(id) {
        document.getElementById(id).classList.add('active');
    }
    function cerrarModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    // Cerrar al hacer click en el overlay
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.classList.remove('active');
        });
    });
    // Cerrar con Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        }
    });

    // ── Modal ➕ Registrar ───────────────────────────────────────
    function abrirModalRegistrar(serial, dispositivo, agentId) {
        document.getElementById('reg-serial').value  = serial;
        document.getElementById('reg-agentId').value = agentId;
        document.getElementById('reg-device-info').innerHTML =
            `Serial: <span>${serial}</span> &nbsp;|&nbsp; Dispositivo: <span>${dispositivo}</span> &nbsp;|&nbsp; Agente: <span>${agentId}</span>`;
        document.getElementById('form-registrar').reset();
        // Restaurar serial e agentId que reset() limpió
        document.getElementById('reg-serial').value  = serial;
        document.getElementById('reg-agentId').value = agentId;
        abrirModal('modal-registrar');
    }

    async function submitRegistrar(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-reg-submit');
        btn.disabled = true;
        btn.textContent = 'Guardando…';

        const data = new FormData(document.getElementById('form-registrar'));
        try {
            const res  = await fetch('/gyrosfe/api/cliente_registrar.php', { method: 'POST', body: data });
            const json = await res.json();
            if (json.ok) {
                cerrarModal('modal-registrar');
                location.reload();
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo registrar'));
            }
        } catch (err) {
            alert('Error de red: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Registrar';
        }
    }

    // ── Modal 📝 Editar ─────────────────────────────────────────
    function abrirModalEditar(uuid, serial, dispositivo, nombre, ci, celular, fijo, vtotarjeta, codigo, sector, activo, garantenombre, garantecelular, observaciones, fecharegistro) {
        document.getElementById('edit-uuid').value           = uuid;
        document.getElementById('edit-serial').value         = serial;
        document.getElementById('edit-nombre').value         = nombre;
        document.getElementById('edit-ci').value             = ci;
        document.getElementById('edit-celular').value        = celular;
        document.getElementById('edit-fijo').value           = fijo;
        document.getElementById('edit-vtotarjeta').value     = vtotarjeta;
        document.getElementById('edit-codigo').value         = codigo;
        document.getElementById('edit-sector').value         = sector;
        document.getElementById('edit-activo').value         = activo === 'Sí' ? '1' : '0';
        document.getElementById('edit-garantenombre').value  = garantenombre;
        document.getElementById('edit-garantecelular').value = garantecelular;
        document.getElementById('edit-observaciones').value  = observaciones;
        document.getElementById('edit-fecharegistro').value  = fecharegistro;
        document.getElementById('edit-device-info').innerHTML =
            `Serial: <span>${serial}</span> &nbsp;|&nbsp; Dispositivo: <span>${dispositivo}</span>`;
        abrirModal('modal-editar');
    }

    async function submitEditar(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-edit-submit');
        btn.disabled = true;
        btn.textContent = 'Guardando…';

        const data = new FormData(document.getElementById('form-editar'));
        try {
            const res  = await fetch('/gyrosfe/api/cliente_editar.php', { method: 'POST', body: data });
            const json = await res.json();
            if (json.ok) {
                cerrarModal('modal-editar');
                location.reload();
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo actualizar'));
            }
        } catch (err) {
            alert('Error de red: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Guardar cambios';
        }
    }

    // ── Estado del modal Registro ────────────────────────────────
    let _regUuid   = '';   // UUID del cliente actualmente abierto
    let _regCodigo = '';   // Código del cliente

    // ── Modal 📲 Registro / Expediente del cliente ──────────────
    function txt(val) {
        const s = (val ?? '').toString().trim();
        return s !== '' ? s : null;
    }
    function setGen(id, val, emptyLabel) {
        const el = document.getElementById(id);
        if (!el) return;
        const v = txt(val);
        if (v) {
            el.textContent = v;
            el.classList.remove('empty');
        } else {
            el.textContent = emptyLabel ?? '—';
            el.classList.add('empty');
        }
    }

    function abrirModalRegistro(data) {
        // Guardar UUID y código del cliente actual
        _regUuid   = (data.uuid   ?? '').toString().trim();
        _regCodigo = (data.codigo ?? '').toString().trim();

        // Limpiar contenido de tabs dinámicos al abrir
        document.getElementById('prest-content').innerHTML = '';
        document.getElementById('pagos-content').innerHTML = '';
        document.getElementById('prest-loading').style.display = 'none';
        document.getElementById('pagos-loading').style.display = 'none';

        // Título de cabecera
        document.getElementById('reg-tab-nombre').textContent = txt(data.nombre) ?? '(sin nombre)';

        // Dispositivo
        setGen('gen-serial',      data.serial,      '—');
        setGen('gen-dispositivo', data.dispositivo, '—');

        // Datos personales
        setGen('gen-nombre',      data.nombre,      '—');
        setGen('gen-ci',          data.ci,          '—');
        setGen('gen-celular',     data.celular,      '—');
        setGen('gen-fijo',        data.fijo,         '—');
        setGen('gen-sector',      data.sector,       '—');
        setGen('gen-codigo',      data.codigo,       '—');
        setGen('gen-vtotarjeta',  data.vtotarjeta,   '—');
        setGen('gen-fecha',       data.fecharegistro,'—');

        // Toggle activo — guarda UUID para usarlo en toggleActivo()
        const chk = document.getElementById('gen-activo-chk');
        if (chk) {
            chk.checked = !!data.activo;
            chk.dataset.uuid = data.uuid;
            actualizarToggleLabel(!!data.activo);
        }

        // Garante
        setGen('gen-garantenombre',   data.garantenombre,   '—');
        setGen('gen-garantecelular',  data.garantecelular,  '—');

        // Observaciones
        setGen('gen-observaciones', data.observaciones, 'Sin observaciones');

        // Activar primer tab
        activarTab('tab-general');
        abrirModal('modal-registro');
    }

    // ── Tab switching ────────────────────────────────────────────
    function activarTab(tabId) {
        document.querySelectorAll('#modal-registro .tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('#reg-tabs li').forEach(li => li.classList.remove('active'));
        const panel = document.getElementById(tabId);
        if (panel) panel.classList.add('active');
        const navItem = document.querySelector(`#reg-tabs [data-tab="${tabId}"]`);
        if (navItem) navItem.classList.add('active');

        // Cargar datos dinámicos según tab
        if (tabId === 'tab-prestamos' && _regUuid) cargarPrestamos(_regUuid);
        if (tabId === 'tab-pagos'     && _regUuid) cargarPagos(_regUuid);
    }

    document.getElementById('reg-tabs').addEventListener('click', function(e) {
        const li = e.target.closest('li[data-tab]');
        if (!li) return;
        activarTab(li.dataset.tab);
    });

    // ── Toggle activo/inactivo ───────────────────────────────────
    function actualizarToggleLabel(activo) {
        const lbl = document.getElementById('gen-activo-label');
        if (!lbl) return;
        if (activo) {
            lbl.textContent = 'Activo';
            lbl.className = 'toggle-label activo';
        } else {
            lbl.textContent = 'Inactivo';
            lbl.className = 'toggle-label inactivo';
        }
    }

    // ════════════════════════════════════════════════════════════════
    // PRÉSTAMOS
    // ════════════════════════════════════════════════════════════════

    // ── Helpers ─────────────────────────────────────────────────────
    function fmtMoney(v) {
        if (v === null || v === undefined || v === '') return '—';
        return 'Bs ' + parseFloat(v).toFixed(2);
    }
    function fmtDate(d) {
        if (!d) return '—';
        // d puede ser "YYYY-MM-DD" o ISO
        const s = d.toString().substring(0, 10);
        const [y, m, dd] = s.split('-');
        return `${dd}/${m}/${y}`;
    }
    function calcCuota(monto, tasa, meses) {
        if (!meses || !monto) return 0;
        const r = tasa / 100;
        if (r === 0) return monto / meses;
        const pot = Math.pow(1 + r, meses);
        return monto * (r * pot) / (pot - 1);
    }
    function calcTotales(monto, tasa, meses) {
        const cuota = calcCuota(monto, tasa, meses);
        let r = tasa / 100, saldo = monto, totalInt = 0;
        for (let i = 0; i < meses; i++) {
            const int = saldo * r;
            totalInt += int;
            saldo = Math.max(0, saldo - (cuota - int));
        }
        return { cuota, totalPagar: cuota * meses, totalInt };
    }

    // ── Helpers de modal flotante ────────────────────────────────────
    function abrirModalFloat(id) { document.getElementById(id).classList.add('active'); }
    function cerrarModalFloat(id) { document.getElementById(id).classList.remove('active'); }

    // Cerrar flotantes con overlay-click
    document.querySelectorAll('.modal-float').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
    });

    // ── Recalcular cuota en tiempo real ──────────────────────────────
    function recalcCuota() {
        const monto = parseFloat(document.getElementById('np-monto').value) || 0;
        const tasa  = parseFloat(document.getElementById('np-tasa').value)  || 0;
        const meses = parseInt(document.getElementById('np-meses').value)   || 0;
        const t     = calcTotales(monto, tasa, meses);
        document.getElementById('np-cuota').value = t.cuota.toFixed(2);
        document.getElementById('np-totales').textContent =
            meses > 0 && monto > 0
            ? `Total a pagar: Bs ${t.totalPagar.toFixed(2)}  |  Total intereses: Bs ${t.totalInt.toFixed(2)}`
            : '';
    }
    function recalcCuotaEdit() {
        const monto = parseFloat(document.getElementById('ep-monto').value) || 0;
        const tasa  = parseFloat(document.getElementById('ep-tasa').value)  || 0;
        const meses = parseInt(document.getElementById('ep-meses').value)   || 0;
        const t     = calcTotales(monto, tasa, meses);
        document.getElementById('ep-cuota').value = t.cuota.toFixed(2);
        document.getElementById('ep-totales').textContent =
            meses > 0 && monto > 0
            ? `Total a pagar: Bs ${t.totalPagar.toFixed(2)}  |  Total intereses: Bs ${t.totalInt.toFixed(2)}`
            : '';
    }

    // ── Cargar y renderizar tab Préstamos ────────────────────────────
    async function cargarPrestamos(uuid) {
        const loading = document.getElementById('prest-loading');
        const content = document.getElementById('prest-content');
        content.innerHTML = '';
        loading.style.display = 'flex';
        try {
            const res  = await fetch(`/gyrosfe/api/prestamos_listar.php?uuid=${encodeURIComponent(uuid)}`);
            const json = await res.json();
            loading.style.display = 'none';
            if (!json.ok) { content.innerHTML = `<p style="color:#f87171;padding:20px">Error: ${json.error}</p>`; return; }
            content.innerHTML = renderPrestamosTabla(json.data);
            // Eventos
            content.querySelectorAll('.btn-edit-prest').forEach(btn => {
                btn.addEventListener('click', () => abrirEditarPrestamo(btn.dataset));
            });
            content.querySelectorAll('.btn-del-prest').forEach(btn => {
                btn.addEventListener('click', () => eliminarPrestamo(btn.dataset.id));
            });
        } catch(err) {
            loading.style.display = 'none';
            content.innerHTML = `<p style="color:#f87171;padding:20px">Error de red: ${err.message}</p>`;
        }
    }

    function renderPrestamosTabla(rows) {
        const hoy = new Date().toISOString().split('T')[0];
        const filas = rows.map((p, i) => {
            const cuota        = calcCuota(parseFloat(p.monto_prestado), parseFloat(p.tasa_interes), parseInt(p.plazo_meses));
            const fechaPrest   = (p.fecha_prestamo || '').toString().substring(0, 10);
            const btnEliminar  = fechaPrest === hoy
                ? `<button class="btn-accion btn-del-prest"
                        data-id="${p.id_prestamo}"
                        title="Eliminar préstamo">❌</button>`
                : '';
            return `<tr>
                <td>${_regCodigo || '—'}</td>
                <td>${fmtDate(p.fecha_prestamo)}</td>
                <td class="td-num">${fmtMoney(p.monto_prestado)}</td>
                <td class="td-num">Bs ${cuota.toFixed(2)}</td>
                <td style="text-align:center">${p.plazo_meses}</td>
                <td class="td-num">${fmtMoney(p.total_a_pagar)}</td>
                <td class="td-num">${fmtMoney(p.total_interes)}</td>
                <td style="text-align:center">
                    <button class="btn-accion btn-edit-prest"
                        data-id="${p.id_prestamo}"
                        data-monto="${p.monto_prestado}"
                        data-tasa="${p.tasa_interes}"
                        data-meses="${p.plazo_meses}"
                        data-fecha="${p.fecha_prestamo}"
                        title="Editar préstamo">✏️</button>
                    ${btnEliminar}
                </td>
            </tr>`;
        }).join('');

        const totalMonto = rows.reduce((sum, p) => sum + parseFloat(p.monto_prestado || 0), 0);

        const filaTotales = `<tr style="border-top:2px solid #2a2f45">
            <td colspan="2" style="text-align:right;font-size:.72rem;font-weight:700;letter-spacing:.06em;color:#7b93ff;text-transform:uppercase;padding-right:12px">Total Préstamos:</td>
            <td class="td-num" style="color:#e0e4f0;font-weight:700">Bs ${totalMonto.toFixed(2)}</td>
            <td colspan="5"></td>
        </tr>`;

        const filaAgregar = `<tr>
            <td colspan="7" class="td-add">Agregar nuevo Préstamo</td>
            <td style="text-align:center">
                <button class="btn-accion" onclick="abrirNuevoPrestamo()" title="Agregar nuevo préstamo">➕</button>
            </td>
        </tr>`;

        return `<div class="prest-table-wrap">
            <table class="prest-table">
                <thead><tr>
                    <th>Código Cliente</th>
                    <th>Fecha Préstamo</th>
                    <th>Monto Prestado</th>
                    <th>Cuota (Bs.)</th>
                    <th style="text-align:center">Meses</th>
                    <th>Total Pagar</th>
                    <th>Total Intereses</th>
                    <th style="text-align:center">Acciones</th>
                </tr></thead>
                <tbody>${filas}${rows.length > 0 ? filaTotales : ''}${filaAgregar}</tbody>
            </table>
        </div>`;
    }

    // ── Abrir modal Nuevo Préstamo ────────────────────────────────────
    function abrirNuevoPrestamo() {
        const hoy = new Date().toISOString().split('T')[0];
        document.getElementById('np-fecha').value = hoy;
        document.getElementById('np-monto').value = '';
        document.getElementById('np-tasa').value  = '';
        document.getElementById('np-meses').value = '';
        document.getElementById('np-cuota').value = '';
        document.getElementById('np-totales').textContent = '';
        abrirModalFloat('modal-nuevo-prest');
    }

    async function crearPrestamo() {
        const btn   = document.getElementById('btn-crear-prest');
        const fecha = document.getElementById('np-fecha').value;
        const monto = document.getElementById('np-monto').value;
        const tasa  = document.getElementById('np-tasa').value;
        const meses = document.getElementById('np-meses').value;
        if (!fecha || !monto || !meses) { alert('Completa todos los campos obligatorios'); return; }
        btn.disabled = true; btn.textContent = 'Creando…';
        const fd = new FormData();
        fd.append('clienteUuid',    _regUuid);
        fd.append('fecha_prestamo', fecha);
        fd.append('monto_prestado', monto);
        fd.append('tasa_interes',   tasa || '0');
        fd.append('plazo_meses',    meses);
        try {
            const res  = await fetch('/gyrosfe/api/prestamo_crear.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                cerrarModalFloat('modal-nuevo-prest');
                await cargarPrestamos(_regUuid);
                // Recargar pagos si el tab de pagos ya fue visitado
                if (document.getElementById('tab-pagos').classList.contains('active')) {
                    await cargarPagos(_regUuid);
                } else {
                    // Limpiar cache de pagos para forzar recarga al cambiar
                    document.getElementById('pagos-content').innerHTML = '';
                }
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo crear'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Crear Préstamo'; }
    }

    // ── Eliminar Préstamo ────────────────────────────────────────────
    async function eliminarPrestamo(id) {
        if (!confirm('¿Está seguro que desea eliminar este préstamo?\n\nSe eliminarán también todas las cuotas generadas. Esta acción no se puede deshacer.')) return;
        const fd = new FormData();
        fd.append('id_prestamo', id);
        try {
            const res  = await fetch('/gyrosfe/api/prestamo_eliminar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                await cargarPrestamos(_regUuid);
                if (document.getElementById('tab-pagos').classList.contains('active')) {
                    await cargarPagos(_regUuid);
                } else {
                    document.getElementById('pagos-content').innerHTML = '';
                }
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo eliminar'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
    }

    // ── Abrir modal Editar Préstamo ──────────────────────────────────
    function abrirEditarPrestamo(ds) {
        document.getElementById('ep-id').value    = ds.id;
        document.getElementById('ep-monto').value = ds.monto;
        document.getElementById('ep-tasa').value  = ds.tasa;
        document.getElementById('ep-meses').value = ds.meses;
        recalcCuotaEdit();
        abrirModalFloat('modal-editar-prest');
    }

    async function guardarPrestamo() {
        const btn   = document.getElementById('btn-guardar-prest');
        const id    = document.getElementById('ep-id').value;
        const monto = document.getElementById('ep-monto').value;
        const tasa  = document.getElementById('ep-tasa').value;
        const meses = document.getElementById('ep-meses').value;
        if (!id || !monto || !meses) { alert('Datos inválidos'); return; }
        btn.disabled = true; btn.textContent = 'Guardando…';
        const fd = new FormData();
        fd.append('id_prestamo',   id);
        fd.append('monto_prestado', monto);
        fd.append('tasa_interes',   tasa || '0');
        fd.append('plazo_meses',    meses);
        try {
            const res  = await fetch('/gyrosfe/api/prestamo_editar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                cerrarModalFloat('modal-editar-prest');
                await cargarPrestamos(_regUuid);
                document.getElementById('pagos-content').innerHTML = '';
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo actualizar'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Guardar Cambios'; }
    }

    // ════════════════════════════════════════════════════════════════
    // PAGOS
    // ════════════════════════════════════════════════════════════════

    async function cargarPagos(uuid) {
        const loading = document.getElementById('pagos-loading');
        const content = document.getElementById('pagos-content');
        content.innerHTML = '';
        loading.style.display = 'flex';
        try {
            const res  = await fetch(`/gyrosfe/api/pagos_listar.php?uuid=${encodeURIComponent(uuid)}`);
            const json = await res.json();
            loading.style.display = 'none';
            if (!json.ok) { content.innerHTML = `<p style="color:#f87171;padding:20px">Error: ${json.error}</p>`; return; }
            if (json.data.length === 0) {
                content.innerHTML = '<div class="tab-placeholder"><span>💵</span>No hay préstamos registrados.</div>';
                return;
            }
            content.innerHTML = json.data.map((item, idx) => renderPagosBloque(item, idx + 1)).join('');
            // Eventos en botones de pago
            content.querySelectorAll('.btn-reg-pago').forEach(btn => {
                btn.addEventListener('click', () => abrirRegPago(btn.dataset));
            });
        } catch(err) {
            loading.style.display = 'none';
            content.innerHTML = `<p style="color:#f87171;padding:20px">Error de red: ${err.message}</p>`;
        }
    }

    function estadoClass(estado) {
        const m = { pagado: 'estado-pagado', pendiente: 'estado-pendiente',
                    vencido: 'estado-vencido', parcial: 'estado-parcial' };
        return m[estado] ?? 'estado-default';
    }
    function estadoLabel(estado) {
        const m = { pagado: 'Pagado', pendiente: 'Pendiente',
                    vencido: 'Vencido', parcial: 'Parcial' };
        return m[estado] ?? estado;
    }

    function renderPagosBloque(item, num) {
        const p = item.prestamo;
        const cuotas = item.cuotas;
        const header = `<div class="pago-bloque-header">
            <span><strong>PRÉSTAMO #${num}</strong></span>
            <span>Fecha: <strong>${fmtDate(p.fecha_prestamo)}</strong></span>
            <span>Monto: <strong>${fmtMoney(p.monto_prestado)}</strong></span>
            <span>Plazo: <strong>${p.plazo_meses} meses</strong></span>
            <span>Tasa: <strong>${parseFloat(p.tasa_interes).toFixed(2)}%</strong></span>
        </div>`;

        if (!cuotas || cuotas.length === 0) {
            return `<div class="pago-bloque">${header}<p style="color:#555c7a;padding:10px">Sin cuotas generadas.</p></div>`;
        }

        // Calcular DIAS entre pagos
        const filas = cuotas.map((c, i) => {
            let dias = '—';
            if (i === 0) {
                // Días desde fecha_prestamo hasta fecha_pago del mes 1
                if (p.fecha_prestamo && c.fecha_pago) {
                    const d0 = new Date(p.fecha_prestamo);
                    const d1 = new Date(c.fecha_pago);
                    dias = Math.round((d1 - d0) / 86400000);
                }
            } else {
                const prev = cuotas[i - 1];
                if (prev.fecha_pago && c.fecha_pago) {
                    const d0 = new Date(prev.fecha_pago);
                    const d1 = new Date(c.fecha_pago);
                    dias = Math.round((d1 - d0) / 86400000);
                }
            }
            const saldoFin = parseFloat(c.saldo_deudor ?? 0).toFixed(2);
            return `<tr>
                <td>${c.mes}</td>
                <td>${fmtDate(c.fecha_pago)}</td>
                <td>${fmtMoney(c.cuota_fija)}</td>
                <td>${fmtMoney(c.monto_a_interes)}</td>
                <td>${fmtMoney(c.monto_a_devolucion_kapital)}</td>
                <td>${fmtMoney(c.saldo_deudor)}</td>
                <td style="text-align:center">${dias}</td>
                <td>${fmtMoney(c.saldo_inicial)} / Bs ${saldoFin}</td>
                <td>${c.transferencia != null ? fmtMoney(c.transferencia) : '—'}</td>
                <td class="${estadoClass(c.estado)}">${estadoLabel(c.estado)}</td>
                <td style="text-align:center">
                    <button class="btn-accion btn-reg-pago"
                        data-id="${c.id_pago}"
                        data-fecha="${c.fecha_pago ?? ''}"
                        data-estado="${c.estado}"
                        title="Registrar pago">💸</button>
                </td>
            </tr>`;
        }).join('');

        const totalInteres = cuotas.reduce((s, c) => s + parseFloat(c.monto_a_interes || 0), 0);
        const totalCapital = cuotas.reduce((s, c) => s + parseFloat(c.monto_a_devolucion_kapital || 0), 0);

        const filaTotalesPago = `<tr style="border-top:2px solid #2a2f45">
            <td colspan="3" style="text-align:right;font-size:.68rem;font-weight:700;letter-spacing:.05em;color:#7b93ff;text-transform:uppercase;padding-right:10px">Totales:</td>
            <td style="text-align:center;color:#e0e4f0;font-weight:700">Bs ${totalInteres.toFixed(2)}</td>
            <td style="text-align:center;color:#e0e4f0;font-weight:700">Bs ${totalCapital.toFixed(2)}</td>
            <td colspan="6"></td>
        </tr>`;

        return `<div class="pago-bloque">
            ${header}
            <div class="pago-table-wrap">
                <table class="pago-table">
                    <thead><tr>
                        <th>MES</th>
                        <th>FECHA PAGO</th>
                        <th>CUOTA FIJA</th>
                        <th>INTERÉS</th>
                        <th>CAPITAL</th>
                        <th>SALDO</th>
                        <th>DÍAS</th>
                        <th>INICIAL/FINAL</th>
                        <th>TRANSF.</th>
                        <th>ESTADO</th>
                        <th>ACCIONES</th>
                    </tr></thead>
                    <tbody>${filas}${filaTotalesPago}</tbody>
                </table>
            </div>
        </div>`;
    }

    // ── Abrir modal Registrar Pago ───────────────────────────────────
    function abrirRegPago(ds) {
        document.getElementById('rp-id').value     = ds.id;
        document.getElementById('rp-fecha').value  = ds.fecha || new Date().toISOString().split('T')[0];
        document.getElementById('rp-monto').value  = '';
        document.getElementById('rp-transf').value = '';
        document.getElementById('rp-metodo').value = '';
        document.getElementById('rp-estado').value = ds.estado === 'pagado' ? 'pagado' : 'pagado';
        abrirModalFloat('modal-reg-pago');
    }

    async function guardarPago() {
        const btn    = document.getElementById('btn-guardar-pago');
        const id     = document.getElementById('rp-id').value;
        const fecha  = document.getElementById('rp-fecha').value;
        const monto  = document.getElementById('rp-monto').value;
        const transf = document.getElementById('rp-transf').value;
        const metodo = document.getElementById('rp-metodo').value;
        const estado = document.getElementById('rp-estado').value;
        if (!id || !fecha) { alert('Completa todos los campos obligatorios'); return; }
        btn.disabled = true; btn.textContent = 'Guardando…';
        const fd = new FormData();
        fd.append('id_pago',     id);
        fd.append('fecha_pago',  fecha);
        fd.append('estado',      estado);
        if (monto)  fd.append('monto_pago',    monto);
        if (transf) fd.append('transferencia', transf);
        if (metodo) fd.append('metodo_pago',   metodo);
        try {
            const res  = await fetch('/gyrosfe/api/pago_actualizar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                cerrarModalFloat('modal-reg-pago');
                await cargarPagos(_regUuid);
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo guardar'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Guardar Pago'; }
    }

    async function toggleActivo(chk) {
        const uuid    = chk.dataset.uuid ?? '';
        const activo  = chk.checked;
        const saving  = document.getElementById('gen-activo-saving');

        if (!uuid) { chk.checked = !activo; return; }

        chk.disabled = true;
        if (saving) saving.style.display = 'inline';
        actualizarToggleLabel(activo);

        const fd = new FormData();
        fd.append('uuid',     uuid);
        fd.append('isActive', activo ? '1' : '0');

        try {
            const res  = await fetch('/gyrosfe/api/cliente_toggle_activo.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) {
                // Revertir si falló
                chk.checked = !activo;
                actualizarToggleLabel(!activo);
                alert('Error al cambiar estado: ' + (json.error ?? 'Desconocido'));
            }
        } catch (err) {
            chk.checked = !activo;
            actualizarToggleLabel(!activo);
            alert('Error de red: ' + err.message);
        } finally {
            chk.disabled = false;
            if (saving) saving.style.display = 'none';
        }
    }
    </script>

</body>
</html>
