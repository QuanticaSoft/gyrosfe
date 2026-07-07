<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$uuid     = trim((string) ($_POST['clienteUuid']  ?? ''));
$modo     = trim((string) ($_POST['modo']          ?? 'nuevo'));
$monto    = (float)  ($_POST['monto_prestado']     ?? 0);
$tasa     = (float)  ($_POST['tasa_interes']        ?? 0);
$plazo    = (int)    ($_POST['plazo_meses']          ?? 0);
$fechaStr = trim((string) ($_POST['fecha_prestamo']   ?? ''));
$userId   = (int) ($_SESSION['user_id'] ?? 0);

if ($modo !== 'nuevo' && $modo !== 'migrar') {
    echo json_encode(['ok' => false, 'error' => 'Modo inválido']);
    exit;
}
if ($uuid === '' || $monto <= 0 || $plazo <= 0 || $fechaStr === '') {
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos o inválidos']);
    exit;
}

$esMigrado          = ($modo === 'migrar');
$numeroCuotaActual  = 1;
$saldoPendiente     = $monto;

if ($esMigrado) {
    $cuotaActualStr = trim((string) ($_POST['numero_cuota_actual']    ?? ''));
    $saldoStr       = trim((string) ($_POST['saldo_pendiente_actual'] ?? ''));

    if (
        $cuotaActualStr === '' || !ctype_digit($cuotaActualStr) || (int) $cuotaActualStr < 1 || (int) $cuotaActualStr > $plazo
        || $saldoStr === '' || !is_numeric($saldoStr) || (float) $saldoStr < 0
    ) {
        echo json_encode(['ok' => false, 'error' => 'Para migrar: N° de Cuota Actual (1-' . $plazo . ') y Saldo Pendiente Actual son obligatorios y deben ser válidos']);
        exit;
    }
    $numeroCuotaActual = (int) $cuotaActualStr;
    $saldoPendiente    = round((float) $saldoStr, 2);
}

// Validar fecha
try {
    $fechaPrestamo = new DateTimeImmutable($fechaStr);
} catch (Throwable) {
    echo json_encode(['ok' => false, 'error' => 'Fecha inválida']);
    exit;
}

// ── Calcular cuota con amortización francesa (cuota fija) ────────────
function calcularCuotaMensual(float $p, float $tasaPct, int $n): float
{
    if ($n === 0 || $p === 0.0) return 0.0;
    $r = $tasaPct / 100.0;
    if ($r === 0.0) return $p / $n;
    $pot = pow(1 + $r, $n);
    return $p * ($r * $pot) / ($pot - 1);
}

// ── Generar tabla de amortización desde un mes/saldo de partida ──────
// mesInicio=1 y saldoInicio=monto reproduce el plan completo (préstamo nuevo).
function generarAmortizacion(float $monto, float $tasa, int $plazo, int $mesInicio, float $saldoInicio): array
{
    $cuota = calcularCuotaMensual($monto, $tasa, $plazo);
    $r     = $tasa / 100.0;
    $saldo = $saldoInicio;
    $filas = [];

    for ($i = $mesInicio; $i <= $plazo; $i++) {
        if ($saldo <= 0.0 && $i > $mesInicio) {
            // Saldo ya cancelado antes del plazo (puede pasar en migraciones con saldo real menor al teórico)
            break;
        }
        $saldoInicial = $saldo;
        $interes      = $saldoInicial * $r;
        $capital      = $cuota - $interes;
        $saldoFinal   = max(0.0, $saldoInicial - $capital);
        // último mes: ajustar capital por redondeo
        if ($i === $plazo) {
            $capital    = $saldoInicial;
            $saldoFinal = 0.0;
        }
        $saldo = $saldoFinal;

        $filas[] = [
            'mes'                        => $i,
            'cuota_fija'                 => round($cuota, 2),
            'saldo_inicial'              => round($saldoInicial, 2),
            'monto_a_interes'            => round($interes, 2),
            'monto_a_devolucion_kapital' => round($capital, 2),
            'saldo_deudor'               => round($saldoFinal, 2),
            'dias_programado'            => 30,
        ];
    }
    return $filas;
}

// Plan completo (mes 1 en adelante) — siempre se usa para los totales del préstamo
$amortCompleto = generarAmortizacion($monto, $tasa, $plazo, 1, $monto);
$totalInteres  = array_sum(array_column($amortCompleto, 'monto_a_interes'));
$cuota         = calcularCuotaMensual($monto, $tasa, $plazo);
$totalAPagar   = round($cuota * $plazo, 2);

// Plan a registrar en "pago" — completo si es nuevo, o solo lo restante si es migración
$amortAInsertar = $esMigrado
    ? generarAmortizacion($monto, $tasa, $plazo, $numeroCuotaActual, $saldoPendiente)
    : $amortCompleto;

// Fecha de vencimiento = fecha_prestamo + plazo meses
$fechaVenc = $fechaPrestamo->modify("+{$plazo} months")->format('Y-m-d');

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // Insertar préstamo
    $stmtP = $pdo->prepare(
        'INSERT INTO "prestamo"
            ("clienteIdCliente","userId","monto_prestado","tasa_interes","plazo_meses",
             "fecha_prestamo","fecha_vencimiento","total_a_pagar","total_interes","isActive",
             "es_migrado","numero_cuota_actual","saldo_pendiente_actual")
         VALUES
            (:uuid, :uid, :monto, :tasa, :plazo,
             :fprest, :fvenc, :total, :tint, TRUE,
             :esmigrado, :cuotaactual, :saldopendiente)
         RETURNING "id_prestamo"'
    );
    $stmtP->execute([
        ':uuid'           => $uuid,
        ':uid'            => $userId ?: null,
        ':monto'          => $monto,
        ':tasa'           => $tasa,
        ':plazo'          => $plazo,
        ':fprest'         => $fechaPrestamo->format('Y-m-d'),
        ':fvenc'          => $fechaVenc,
        ':total'          => $totalAPagar,
        ':tint'           => round($totalInteres, 2),
        ':esmigrado'      => $esMigrado ? 't' : 'f',
        ':cuotaactual'    => $numeroCuotaActual,
        ':saldopendiente' => $saldoPendiente,
    ]);
    $prestamoId = $stmtP->fetchColumn();

    // Insertar cuotas (pago)
    $stmtCuota = $pdo->prepare(
        'INSERT INTO "pago"
            ("prestamoIdPrestamo","userId","mes","cuota_fija","saldo_inicial",
             "monto_a_interes","monto_a_devolucion_kapital","saldo_deudor",
             "fecha_pago","estado","isActive","dias_programado")
         VALUES
            (:pid, :uid, :mes, :cuota, :sinicial,
             :interes, :capital, :saldo,
             :fpago, \'pendiente\', TRUE, :diasp)'
    );

    foreach ($amortAInsertar as $fila) {
        // fecha de pago = fecha_prestamo + N meses (mismo cronograma original)
        $fpago = $fechaPrestamo->modify("+{$fila['mes']} months")->format('Y-m-d');
        $stmtCuota->execute([
            ':pid'      => $prestamoId,
            ':uid'      => $userId ?: null,
            ':mes'      => $fila['mes'],
            ':cuota'    => $fila['cuota_fija'],
            ':sinicial' => $fila['saldo_inicial'],
            ':interes'  => $fila['monto_a_interes'],
            ':capital'  => $fila['monto_a_devolucion_kapital'],
            ':saldo'    => $fila['saldo_deudor'],
            ':fpago'    => $fpago,
            ':diasp'    => $fila['dias_programado'],
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'id_prestamo' => $prestamoId]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
