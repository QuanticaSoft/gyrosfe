# GyrosFE — CLAUDE.md

## Descripción del Proyecto

**GyrosFE** es el frontend/backend de gestión de clientes para **Quantica Soft**. Aplicación web PHP que gestiona la asignación de dispositivos USB (módems) a clientes, con monitoreo en tiempo real de agentes remotos, y un módulo financiero de préstamos, cuotas, cuentas bancarias y débitos automáticos vía agente externo.

## Tecnologías

- **Backend:** PHP 8.0+ con `declare(strict_types=1)` obligatorio en todos los archivos
- **Base de datos:** PostgreSQL (via PDO) — base de datos: `gyros`
- **Frontend:** HTML5, CSS3, JavaScript vanilla (sin frameworks)
- **Servidor web:** Apache/Nginx — base URL: `/gyrosfe/`

## Estructura del Proyecto

```
gyrosfe/
├── index.php              # Punto de entrada (redirige a login o dashboard)
├── assets/
│   └── app.css            # Estilos globales
├── lib/
│   ├── auth.php           # Autenticación y gestión de sesiones
│   └── db_connect.php     # Conexión PDO a PostgreSQL
├── ui/
│   ├── login.php          # Formulario de login
│   ├── logout.php         # Cierre de sesión
│   ├── dashboard.php      # Vista principal (redirige a main.php)
│   └── main.php           # Dashboard con tabla de dispositivos y modales
├── api/
│   ├── cliente_registrar.php          # POST: registrar nuevo cliente + dispositivo
│   ├── cliente_editar.php             # POST: editar cliente existente
│   ├── cliente_toggle_activo.php      # POST: activar/desactivar cliente
│   ├── banco_guardar.php              # POST: guardar hasta 2 cuentas bancarias de un cliente
│   ├── banco_listar.php               # GET:  listar cuentas bancarias de un cliente
│   ├── prestamo_crear.php             # POST: crear préstamo (nuevo o migrado) + genera cuotas
│   ├── prestamo_editar.php            # POST: editar préstamo, regenera cuotas pendientes
│   ├── prestamo_eliminar.php          # POST: eliminar préstamo (solo si se creó hoy)
│   ├── prestamos_listar.php           # GET:  listar préstamos de un cliente
│   ├── pagos_listar.php               # GET:  listar préstamos + cuotas anidadas de un cliente
│   ├── pago_actualizar.php            # POST: actualizar estado/fecha/monto de una cuota
│   ├── pago_fecha.php                 # POST: cambiar fecha de una cuota, recalcula en cascada
│   ├── consulta_saldo.php             # POST: consulta saldo bancario vía agente externo
│   ├── debitar.php                    # POST: ejecuta débito ACH vía agente externo
│   └── migration_pago_columnas_reales.php  # POST: migración de schema (columnas *_real en pago)
└── agent/
    ├── heartbeat.php           # POST: recibe heartbeat de agentes remotos
    ├── usb_event.php           # POST: recibe eventos USB (connect/disconnect)
    ├── usb_events.php          # GET:  lista eventos USB de un agente
    └── devices.json.php        # GET:  lista dispositivos activos en JSON
```

## Base de Datos

**Archivo de configuración:** `/webs/quanticasoft/_private/db.php` (fuera del repo)

**Tablas:**
| Tabla | Descripción |
|-------|-------------|
| `Agent` | Agentes remotos registrados (`agentId`, `hostname`, `lastSeen`, `tunnelPort` — puerto local del túnel SSH reverso de ese agente, usado por `consulta_saldo.php`/`debitar.php`) |
| `Cliente` | Clientes registrados con su dispositivo asignado |
| `Dispositivos` | Catálogo de dispositivos |
| `Heartbeat` | Latidos de los agentes (estado online) |
| `UsbDeviceState` | Estado actual de cada dispositivo USB por agente |
| `UsbEvent` | Historial de eventos USB (connect/disconnect) |
| `User` | Usuarios del sistema |
| `banco_cliente` | Cuentas bancarias del cliente (máx. 2), con `nickname` para identificar su uso (ver más abajo) |
| `prestamo` | Préstamos otorgados a un cliente: monto, tasa, plazo, fecha, totales |
| `pago` | Cuotas de cada préstamo: valores programados y valores reales (`*_real`) |
| `saldo` | Historial de consultas de saldo bancario (agente externo), con fecha/hora exacta |

**Conexión:** `pgsql:host=127.0.0.1;port=5432;dbname=gyros`
**Connection string MCP:** `postgresql://marco@127.0.0.1:5432/gyros`

> Los nombres de tablas y columnas usan comillas dobles en SQL (case-sensitive en PostgreSQL).
> PDO devuelve claves de columnas en **minúsculas** en `FETCH_ASSOC` — tener en cuenta al acceder a aliases como `ag_agentid` (no `ag_agentId`) o `prestamoidprestamo` (no `prestamoIdPrestamo`).

## Convenciones de Código

- Todos los archivos PHP deben comenzar con `<?php declare(strict_types=1);`
- Escapado HTML obligatorio con la función `esc()` definida en `main.php`
- Prepared statements en **todas** las consultas SQL (nunca interpolación directa)
- Las respuestas de las APIs (`/api/*.php`) devuelven siempre JSON: `{"ok": true}` o `{"ok": false, "error": "..."}`
- Umbral de agente "online": 120 segundos desde `lastSeen`

## Flujo Principal (main.php)

1. Consulta dispositivos **online** (agente visto en últimos 120s) y **conectados** (status = 'connected' en UsbDeviceState)
2. Muestra tabla con: fecha, agente, dispositivo/serial, cliente asignado
3. Si no tiene cliente → botón ➕ abre modal de registro
4. Si tiene cliente → botón 📝 abre modal de edición

## Módulo Financiero (préstamos y cuotas)

- **Creación (`prestamo_crear.php`):** dos modos —
  - `nuevo`: genera el plan de amortización completo (francesa, cuota fija) desde el mes 1.
  - `migrar`: para préstamos ya existentes fuera del sistema; recibe `numero_cuota_actual` y `saldo_pendiente_actual`, y genera solo las cuotas restantes desde ese punto.
- **Edición (`prestamo_editar.php`):** recalcula monto/tasa/plazo y **regenera únicamente las cuotas en estado `pendiente`** (no toca cuotas ya pagadas).
- **Eliminación (`prestamo_eliminar.php`):** solo permite borrar un préstamo si `fecha_prestamo` es la fecha de hoy (evita borrar préstamos históricos por error).
- **Estados de cuota (`pago.estado`):** `pendiente`, `pagado`, `vencido`, `parcial`.
- **Valores reales vs. programados:** cada cuota tiene columnas programadas (`cuota_fija`, `saldo_inicial`, `monto_a_interes`, `monto_a_devolucion_kapital`, `saldo_deudor`, `dias_programado`) y columnas reales (`dias_real`, `interes_real`, `capital_real`, `saldo_deudor_real`), calculadas al momento de marcar la cuota `pagado`/`parcial` en `pago_actualizar.php`, o al ejecutar el débito en `debitar.php`.
- **Cambiar fecha de una cuota (`pago_fecha.php`):** recalcula en cascada el interés/capital/saldo de esa cuota **y todas las posteriores** del mismo préstamo, usando interés diario (`tasa_interes / 100 / 30`) sobre los días reales transcurridos.

## Integración con Agentes Externos (Saldo y Débito)

`consulta_saldo.php` y `debitar.php` no hablan directo con el banco: delegan a un **agente externo** que corre en el dispositivo Android/USB asignado al cliente. **Desde 2026-09-09 puede haber más de un agente activo en paralelo** (hoy: `cbb01` en Cochabamba, `scz01` en Santa Cruz, cada uno con su propio teléfono) — el puerto ya **no está hardcodeado**, se resuelve en runtime:

```php
SELECT a."tunnelPort" FROM "UsbDeviceState" uds
JOIN "Agent" a ON a.id = uds."agentId"
WHERE uds.serial = :serial AND uds.status = 'connected'
ORDER BY uds."lastChangeAt" DESC LIMIT 1
```

es decir: el serial del dispositivo del cliente (`Cliente.dispositivo`) determina, vía `UsbDeviceState` (actualizada en vivo por `agent/usb_event.php`), qué agente lo tiene conectado *ahora mismo* y por ende a qué `http://127.0.0.1:<tunnelPort>/consultar-saldo` o `/debitar` llamar. Si el mismo teléfono se mueve físicamente de un agente a otro, el ruteo lo sigue automáticamente.

- **Cómo llega el tráfico a `127.0.0.1:<tunnelPort>`:** un túnel SSH reverso desde cada agente hacia este servidor, cada uno a su propio puerto (`Agent.tunnelPort`: `cbb01`=8080, `scz01`=8081) — ver historial de commits `f704e5b`, `e9c2774` (reemplazó un enfoque anterior con IP de Tailscale) y `migrations/2026_agent_tunnel_port.sql` (soporte multi-agente).
- **Requisito:** el cliente debe tener una cuenta en `banco_cliente` con `nickname = 'pago'` y `isActive = true`; ahí se guardan `usuario`/`key` (credenciales de banca móvil) y `nombre` (nombre del titular).
- **Timeouts:** 180s para consulta de saldo, 280s para débito — el agente puede tardar (interactúa con una app bancaria real).
- **Historial:** cada consulta de saldo exitosa se inserta en la tabla `saldo` con `fecha_hora` exacta; cada débito exitoso graba `nro_envio_transferencia` en la cuota y la marca `pagado` (además de calcular sus valores reales).
- **Supervisión del túnel:** cada agente lo corre bajo `systemd` (`Restart=always`) con un script de limpieza de sesiones huérfanas (`gyros-tunnel-cleanup.sh`) — no es un proceso manual sin supervisión. Limitación real conocida: ese script no puede identificar por puerto qué sesión SSH huérfana matar en flamenco (`ss`/`lsof` no exponen esa info a un usuario sin privilegios en este host), así que solo actúa cuando hay una única sesión huérfana candidata; con ambigüedad (2+ agentes con problemas a la vez) no hace nada y depende de `Restart=always` + timeout de TCP. Sigue sin haber alerta activa si un túnel cae — una caída se manifiesta como que `consulta_saldo`/`debitar` empiezan a fallar con "No se pudo contactar al agente" para los clientes de ese agente específico. El servidor ya corre `zabbix-agent` para otro monitoreo — candidato natural para agregar un chequeo por agente/puerto (sigue sin implementarse).

## URLs del Sistema

- Login: `/gyrosfe/` o `/gyrosfe/ui/login.php`
- Dashboard: `/gyrosfe/ui/main.php`
- API registro: `POST /gyrosfe/api/cliente_registrar.php`
- API edición: `POST /gyrosfe/api/cliente_editar.php`
- Agent heartbeat: `POST /gyrosfe/agent/heartbeat.php`
- Agent USB event: `POST /gyrosfe/agent/usb_event.php`

## Campos del Formulario Cliente

| Campo | Tipo | Notas |
|-------|------|-------|
| `nombrecompleto` | text | Requerido, max 120 |
| `ci` | text | Requerido, max 20 |
| `numerocelular` | text | Opcional, max 20 |
| `sector` | select | Opciones: `Magisterio`, `Salud` |
| `serial` | hidden | Serial del dispositivo USB |
| `agentId` | hidden | ID del agente que reportó el dispositivo |
