# GyrosFE — CLAUDE.md

## Descripción del Proyecto

**GyrosFE** es el frontend/backend de gestión de clientes para **Quantica Soft**. Aplicación web PHP que gestiona la asignación de dispositivos USB (módems) a clientes, con monitoreo en tiempo real de agentes remotos.

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
│   ├── cliente_registrar.php   # POST: registrar nuevo cliente + dispositivo
│   └── cliente_editar.php      # POST: editar cliente existente
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
| `Agent` | Agentes remotos registrados (`agentId`, `hostname`, `lastSeen`) |
| `Cliente` | Clientes registrados con su dispositivo asignado |
| `Dispositivos` | Catálogo de dispositivos |
| `Heartbeat` | Latidos de los agentes (estado online) |
| `UsbDeviceState` | Estado actual de cada dispositivo USB por agente |
| `UsbEvent` | Historial de eventos USB (connect/disconnect) |
| `User` | Usuarios del sistema |

**Conexión:** `pgsql:host=127.0.0.1;port=5432;dbname=gyros`
**Connection string MCP:** `postgresql://marco@127.0.0.1:5432/gyros`

> Los nombres de tablas y columnas usan comillas dobles en SQL (case-sensitive en PostgreSQL).
> PDO devuelve claves de columnas en **minúsculas** en `FETCH_ASSOC` — tener en cuenta al acceder a aliases como `ag_agentid` (no `ag_agentId`).

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
