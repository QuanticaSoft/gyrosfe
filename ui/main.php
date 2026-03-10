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
  cl.sector                                AS cl_sector,
  cl."isActive"                            AS cl_activo,
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
            $clNombre  = esc((string) ($r['cl_nombre']       ?? ''));
            $clCi      = esc((string) ($r['cl_ci']           ?? ''));
            $clCelular = esc((string) ($r['cl_celular']      ?? ''));
            $clSector  = esc((string) ($r['cl_sector']       ?? ''));
            $clActivo  = !empty($r['cl_activo']) ? 'Sí' : 'No';
            $clFecha   = !empty($r['cl_fecharegistro'])
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
                            '<?= $clSector ?>',
                            '<?= $clActivo ?>',
                            '<?= $clFecha ?>'
                        )"
                    >📝</button>
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
                <input type="text" id="reg-nombre" name="nombrecompleto" required maxlength="120" placeholder="Ej: Juan Pérez">

                <label>CI / Cédula *</label>
                <input type="text" id="reg-ci" name="ci" required maxlength="20" placeholder="Ej: 3456289">

                <label>Número Celular</label>
                <input type="text" id="reg-celular" name="numerocelular" maxlength="20" placeholder="Ej: 12345678">

                <label>Sector</label>
                <select id="reg-sector" name="sector">
                    <option value="">Seleccione un sector</option>
                    <option value="Magisterio">Magisterio</option>
                    <option value="Salud">Salud</option>
                </select>

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
                <input type="text" id="edit-nombre" name="nombrecompleto" required maxlength="120">

                <label>CI / Cédula *</label>
                <input type="text" id="edit-ci" name="ci" required maxlength="20">

                <label>Número Celular</label>
                <input type="text" id="edit-celular" name="numerocelular" maxlength="20">

                <label>Sector</label>
                <input type="text" id="edit-sector" name="sector" maxlength="80">

                <label>Activo</label>
                <select id="edit-activo" name="isActive">
                    <option value="1">Sí</option>
                    <option value="0">No</option>
                </select>

                <label>Fecha Registro</label>
                <input type="text" id="edit-fecharegistro" name="fecharegistro" readonly>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modal-editar')">Cancelar</button>
                    <button type="submit" class="btn-primary" id="btn-edit-submit">Guardar cambios</button>
                </div>
            </form>
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
    function abrirModalEditar(uuid, serial, dispositivo, nombre, ci, celular, sector, activo, fecharegistro) {
        document.getElementById('edit-uuid').value          = uuid;
        document.getElementById('edit-serial').value        = serial;
        document.getElementById('edit-nombre').value        = nombre;
        document.getElementById('edit-ci').value            = ci;
        document.getElementById('edit-celular').value       = celular;
        document.getElementById('edit-sector').value        = sector;
        document.getElementById('edit-activo').value        = activo === 'Sí' ? '1' : '0';
        document.getElementById('edit-fecharegistro').value = fecharegistro;
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
    </script>

</body>
</html>
