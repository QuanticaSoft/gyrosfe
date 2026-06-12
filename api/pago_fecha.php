<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$idPago    = trim((string) ($_POST['id_pago']    ?? ''));
$fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));

if ($idPago === '' || $fechaPago === '') {
    echo json_encode(['ok' => false, 'error' => 'Parámetros requeridos']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
    echo json_encode(['ok' => false, 'error' => 'Formato de fecha inválido']);
    exit;
}

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // ── 1. Obtener el pago y su préstamo ─────────────────────────────
    $stmtPago = $pdo->prepare(
        'SELECT pg.*, pr."tasa_interes", pr."fecha_prestamo", pr."plazo_meses"
         FROM "pago" pg
         JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
         WHERE pg."id_pago" = :id'
    );
    $stmtPago->execute([':id' => $idPago]);
    $pago = $stmtPago->fetch(PDO::FETCH_ASSOC);

    if (!$pago) {
        echo json_encode(['ok' => false, 'error' => 'Pago no encontrado']);
        $pdo->rollBack();
        exit;
    }

    $prestamoId    = $pago['prestamoIdPrestamo'];
    $mesModificado = (int) $pago['mes'];
    $tasa          = (float) $pago['tasa_interes'];   // % mensual
    $tasaDiaria    = $tasa / 100.0 / 30.0;            // tasa diaria equivalente
    $fechaPrestamo = new DateTimeImmutable($pago['fecha_prestamo']);

    // ── 2. Actualizar la fecha del pago modificado ────────────────────
    $stmtUpd = $pdo->prepare(
        'UPDATE "pago" SET "fecha_pago" = :fecha, "updated_at" = NOW()
         WHERE "id_pago" = :id'
    );
    $stmtUpd->execute([':fecha' => $fechaPago, ':id' => $idPago]);

    // ── 3. Obtener TODOS los pagos del préstamo ordenados por mes ─────
    $stmtAll = $pdo->prepare(
        'SELECT "id_pago","mes","fecha_pago","cuota_fija","saldo_inicial",
                "monto_a_interes","monto_a_devolucion_kapital","saldo_deudor"
         FROM "pago"
         WHERE "prestamoIdPrestamo" = :pid
         ORDER BY "mes" ASC'
    );
    $stmtAll->execute([':pid' => $prestamoId]);
    $cuotas = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    // Aplicar la fecha actualizada en memoria
    foreach ($cuotas as &$c) {
        if ($c['id_pago'] === $idPago) {
            $c['fecha_pago'] = $fechaPago;
        }
    }
    unset($c);

    // ── 4. Recalcular en cascada desde el mes modificado ─────────────
    $stmtRec = $pdo->prepare(
        'UPDATE "pago"
         SET "monto_a_interes"            = :interes,
             "monto_a_devolucion_kapital" = :capital,
             "saldo_deudor"               = :saldo,
             "saldo_inicial"              = :sinicial,
             "updated_at"                 = NOW()
         WHERE "id_pago" = :id'
    );

    $totalMeses = count($cuotas);

    for ($i = 0; $i < $totalMeses; $i++) {
        $c   = &$cuotas[$i];
        $mes = (int) $c['mes'];

        if ($mes < $mesModificado) {
            // Filas anteriores: no se recalcula interes/capital, pero saldo_inicial
            // ya está correcto en BD; solo necesitamos el saldo_deudor para la siguiente.
            continue;
        }

        // Calcular días reales
        if ($i === 0) {
            $fechaAnterior = $fechaPrestamo;
        } else {
            $fechaAnterior = new DateTimeImmutable($cuotas[$i - 1]['fecha_pago']);
        }
        $fechaActual = new DateTimeImmutable($c['fecha_pago']);
        $dias        = (int) $fechaAnterior->diff($fechaActual)->days;
        if ($dias < 0) $dias = 0;

        // saldo_inicial de esta fila = saldo_deudor de la anterior (o monto original si es mes 1)
        if ($i === 0) {
            $saldoInicial = (float) $c['saldo_inicial']; // no cambia para el primer mes
        } else {
            $saldoInicial = (float) $cuotas[$i - 1]['saldo_deudor'];
        }

        $cuotaFija = (float) $c['cuota_fija'];
        $interes   = round($saldoInicial * $tasaDiaria * $dias, 2);
        $capital   = round($cuotaFija - $interes, 2);

        // Último mes: pagar exactamente el saldo restante
        if ($mes === $totalMeses) {
            $capital   = round($saldoInicial, 2);
            $interes   = round($cuotaFija - $capital, 2);
        }

        $saldoFinal = round(max(0.0, $saldoInicial - $capital), 2);

        // Actualizar en memoria para la siguiente iteración
        $c['saldo_inicial']              = $saldoInicial;
        $c['monto_a_interes']            = $interes;
        $c['monto_a_devolucion_kapital'] = $capital;
        $c['saldo_deudor']               = $saldoFinal;

        $stmtRec->execute([
            ':interes'  => $interes,
            ':capital'  => $capital,
            ':saldo'    => $saldoFinal,
            ':sinicial' => $saldoInicial,
            ':id'       => $c['id_pago'],
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
