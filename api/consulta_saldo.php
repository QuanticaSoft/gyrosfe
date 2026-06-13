<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();

header('Content-Type: application/json; charset=utf-8');

function jsonErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonErr('Método no permitido', 405);
}

$uuid = trim((string) ($_POST['uuid'] ?? ''));
if ($uuid === '') {
    jsonErr('UUID requerido');
}

require_once __DIR__ . '/../lib/db_connect.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    jsonErr('Error de conexión a BD', 500);
}

try {
    // 1. Obtener serial ADB del dispositivo asignado al cliente
    $stmtCl = $pdo->prepare('SELECT dispositivo FROM "Cliente" WHERE uuid = :uuid LIMIT 1');
    $stmtCl->execute([':uuid' => $uuid]);
    $cliente = $stmtCl->fetch(PDO::FETCH_ASSOC);

    if (!$cliente || empty($cliente['dispositivo'])) {
        jsonErr('Cliente no encontrado o sin dispositivo asignado', 404);
    }

    // 2. Obtener cuenta bancaria con nickname = 'pago'
    $stmtBc = $pdo->prepare("
        SELECT banco, nombre, \"noCta\", usuario, key
        FROM banco_cliente
        WHERE \"clienteIdCliente\" = :uuid
          AND lower(nickname) = 'pago'
          AND \"isActive\" = true
        LIMIT 1
    ");
    $stmtBc->execute([':uuid' => $uuid]);
    $cuenta = $stmtBc->fetch(PDO::FETCH_ASSOC);

    if (!$cuenta) {
        jsonErr('No se encontró cuenta bancaria con nickname "pago"', 404);
    }

    // 3. Llamar al agente
    $payload = json_encode([
        'usuario'        => $cuenta['usuario']  ?? '',
        'password'       => $cuenta['key']       ?? '',
        'nombre_titular' => $cuenta['nombre']    ?? '',
        'dispositivo'    => $cliente['dispositivo'],
    ]);

    $ch = curl_init('http://127.0.0.1:8080/consultar-saldo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp    = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $resp === '') {
        jsonErr('No se pudo contactar al agente: ' . $curlErr, 502);
    }

    $agentData = json_decode($resp, true);
    if (!($agentData['ok'] ?? false)) {
        jsonErr($agentData['error'] ?? 'Error desconocido en agente', 500);
    }

    // 4. Normalizar saldo (puede venir como "1.250,50" o "1250.50")
    $saldoRaw = (string) ($agentData['saldo'] ?? '0');
    $saldoRaw = str_replace(',', '.', $saldoRaw);
    $saldoRaw = preg_replace('/\.(?=.*\.)/', '', $saldoRaw);
    $saldo    = round((float) $saldoRaw, 2);

    // 5. Insertar en tabla saldo con fecha y hora exacta
    $stmtIns = $pdo->prepare('
        INSERT INTO saldo (cliente_id, saldo, fecha_hora)
        VALUES (:cid, :saldo, NOW())
        RETURNING fecha_hora
    ');
    $stmtIns->execute([':cid' => $uuid, ':saldo' => $saldo]);
    $row = $stmtIns->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'         => true,
        'saldo'      => $saldo,
        'fecha_hora' => $row['fecha_hora'] ?? '',
    ]);

} catch (Throwable $e) {
    jsonErr('Error interno: ' . $e->getMessage(), 500);
}
