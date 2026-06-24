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

$idPago = trim((string) ($_POST['id_pago'] ?? ''));
$monto  = trim((string) ($_POST['monto']   ?? ''));

if ($idPago === '' || $monto === '') {
    jsonErr('Parámetros requeridos: id_pago, monto');
}
if (!is_numeric($monto) || (float) $monto <= 0) {
    jsonErr('Monto inválido');
}

require_once __DIR__ . '/../lib/db_connect.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    jsonErr('Error de conexión a BD', 500);
}

try {
    // 1. Obtener la cuota, su prestamo y el cliente duenio
    $stmtPago = $pdo->prepare('
        SELECT pg."id_pago", pg."fecha_pago", pg."nro_envio_transferencia",
               pr."clienteIdCliente" AS uuid
        FROM "pago" pg
        JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
        WHERE pg."id_pago" = :id
        LIMIT 1
    ');
    $stmtPago->execute([':id' => $idPago]);
    $pago = $stmtPago->fetch(PDO::FETCH_ASSOC);

    if (!$pago) {
        jsonErr('Cuota no encontrada', 404);
    }
    if (!empty($pago['nro_envio_transferencia'])) {
        jsonErr('Esta cuota ya fue debitada. N° envío: ' . $pago['nro_envio_transferencia'], 409);
    }

    $uuid = $pago['uuid'];

    // 2. Obtener serial ADB del dispositivo asignado al cliente
    $stmtCl = $pdo->prepare('SELECT dispositivo FROM "Cliente" WHERE uuid = :uuid LIMIT 1');
    $stmtCl->execute([':uuid' => $uuid]);
    $cliente = $stmtCl->fetch(PDO::FETCH_ASSOC);

    if (!$cliente || empty($cliente['dispositivo'])) {
        jsonErr('Cliente no encontrado o sin dispositivo asignado', 404);
    }

    // 3. Obtener cuenta bancaria con nickname = 'pago'
    $stmtBc = $pdo->prepare("
        SELECT usuario, key, nombre
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

    // 4. Llamar al agente
    $payload = json_encode([
        'usuario'        => $cuenta['usuario']  ?? '',
        'password'       => $cuenta['key']      ?? '',
        'nombre_titular' => $cuenta['nombre']   ?? '',
        'dispositivo'    => $cliente['dispositivo'],
        'monto'          => $monto,
        'fecha_pago'     => $pago['fecha_pago'],
    ]);

    $ch = curl_init('http://127.0.0.1:8080/debitar');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 280,
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

    $numeroEnvio = (string) ($agentData['numero_envio'] ?? '');

    // 5. Registrar el debito en la cuota
    $stmtUpd = $pdo->prepare('
        UPDATE "pago"
        SET "nro_envio_transferencia" = :nro,
            "transferencia"           = :monto,
            "estado"                  = \'pagado\',
            "fecha_de_debito"         = CURRENT_DATE,
            "updated_at"              = NOW()
        WHERE "id_pago" = :id
    ');
    $stmtUpd->execute([
        ':nro'   => $numeroEnvio,
        ':monto' => $monto,
        ':id'    => $idPago,
    ]);

    echo json_encode([
        'ok'           => true,
        'numero_envio' => $numeroEnvio,
        'monto'        => $monto,
    ]);

} catch (Throwable $e) {
    jsonErr('Error interno: ' . $e->getMessage(), 500);
}
