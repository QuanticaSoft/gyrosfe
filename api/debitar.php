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
               pg."mes", pg."saldo_inicial", pg."cuota_fija",
               pg."prestamoIdPrestamo",
               pr."clienteIdCliente" AS uuid,
               pr."tasa_interes", pr."fecha_prestamo"
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

    // 4. Resolver el agente activo para este dispositivo (soporta múltiples
    //    agentes en paralelo, cada uno tunelado a un puerto local distinto)
    $stmtPort = $pdo->prepare('
        SELECT a."tunnelPort"
        FROM "UsbDeviceState" uds
        JOIN "Agent" a ON a.id = uds."agentId"
        WHERE uds.serial = :serial AND uds.status = \'connected\'
        ORDER BY uds."lastChangeAt" DESC
        LIMIT 1
    ');
    $stmtPort->execute([':serial' => $cliente['dispositivo']]);
    $agentRow = $stmtPort->fetch(PDO::FETCH_ASSOC);

    if (!$agentRow || empty($agentRow['tunnelPort'])) {
        jsonErr('Dispositivo no está conectado a ningún agente activo', 502);
    }
    $agentPort = (int) $agentRow['tunnelPort'];

    // 5. Llamar al agente
    $payload = json_encode([
        'usuario'        => $cuenta['usuario']  ?? '',
        'password'       => $cuenta['key']      ?? '',
        'nombre_titular' => $cuenta['nombre']   ?? '',
        'dispositivo'    => $cliente['dispositivo'],
        'monto'          => $monto,
        'fecha_pago'     => $pago['fecha_pago'],
    ]);

    $ch = curl_init("http://127.0.0.1:{$agentPort}/debitar");
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

    // 6. Calcular dias_real y valores reales de amortización
    $today = new DateTimeImmutable('today');

    if ((int) $pago['mes'] === 1) {
        $refDate = new DateTimeImmutable($pago['fecha_prestamo']);
    } else {
        // Fecha de referencia = fecha_de_debito de la cuota anterior
        $stmtPrev = $pdo->prepare('
            SELECT "fecha_de_debito" FROM "pago"
            WHERE "prestamoIdPrestamo" = :pid AND mes = :prevmes
            LIMIT 1
        ');
        $stmtPrev->execute([
            ':pid'     => $pago['prestamoidprestamo'],
            ':prevmes' => (int) $pago['mes'] - 1,
        ]);
        $prev    = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        $refDate = new DateTimeImmutable(
            (!empty($prev['fecha_de_debito'])) ? $prev['fecha_de_debito'] : $pago['fecha_prestamo']
        );
    }

    $diasReal    = (int) $today->diff($refDate)->days;
    $tasaDiaria  = (float) $pago['tasa_interes'] / 100.0 / 30.0;
    $saldoIni    = (float) $pago['saldo_inicial'];
    $cuotaFija   = (float) $pago['cuota_fija'];
    $interesReal = round($saldoIni * $tasaDiaria * $diasReal, 2);
    $capitalReal = round(max(0.0, $cuotaFija - $interesReal), 2);
    $saldoReal   = round(max(0.0, $saldoIni - $capitalReal), 2);

    // 7. Registrar el débito con valores reales en la cuota
    $stmtUpd = $pdo->prepare('
        UPDATE "pago"
        SET "nro_envio_transferencia" = :nro,
            "transferencia"           = :monto,
            "estado"                  = \'pagado\',
            "fecha_de_debito"         = CURRENT_DATE,
            "dias_real"               = :diasreal,
            "interes_real"            = :interesreal,
            "capital_real"            = :capitalreal,
            "saldo_deudor_real"       = :saldoreal,
            "updated_at"              = NOW()
        WHERE "id_pago" = :id
    ');
    $stmtUpd->execute([
        ':nro'         => $numeroEnvio,
        ':monto'       => $monto,
        ':id'          => $idPago,
        ':diasreal'    => $diasReal,
        ':interesreal' => $interesReal,
        ':capitalreal' => $capitalReal,
        ':saldoreal'   => $saldoReal,
    ]);

    echo json_encode([
        'ok'           => true,
        'numero_envio' => $numeroEnvio,
        'monto'        => $monto,
    ]);

} catch (Throwable $e) {
    jsonErr('Error interno: ' . $e->getMessage(), 500);
}
