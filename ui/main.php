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
            width: 700px;
            max-width: 95vw;
            height: 580px;
            max-height: 92vh;
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
                    <!-- 📝 Registrado: abrir modal de info/edición -->
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
                    <!-- 📲 Registrado: abrir modal de registro/expediente del cliente -->
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
                    <div class="tab-placeholder">
                        <span>💳</span>
                        Módulo de Préstamos — próximamente
                    </div>
                </div>

                <!-- ── PAGOS ─────────────────────────────────────── -->
                <div id="tab-pagos" class="tab-panel">
                    <div class="tab-placeholder">
                        <span>💵</span>
                        Módulo de Pagos — próximamente
                    </div>
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
