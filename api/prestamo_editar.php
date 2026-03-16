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

$id      = trim((string) ($_POST['id_prestamo']    ?? ''));
$monto   = (float)  ($_POST['monto_prestado']  ?? 0);
$tasa    = (float)  ($_POST['tasa_interes']     ?? 0);
$plazo   = (int)    ($_POST['plazo_meses']       ?? 0);
$fecha   = trim((string) ($_POST['fecha_prestamo'] ?? ''));

if ($id === '' || $monto <= 0 || $plazo <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

function calcularCuotaMensual(float $p, float $tasaPct, int $n): float
{
    if ($n === 0 || $p === 0.0) return 0.0;
    $r = $tasaPct / 100.0;
    if ($r === 0.0) return $p / $n;
    $pot = pow(1 + $r, $n);
    return $p * ($r * $pot) / ($pot - 1);
}

function generarAmortizacion(float $monto, float $tasa, int $plazo): array
{
    $cuota = calcularCuotaMensual($monto, $tasa, $plazo);
    $r     = $tasa / 100.0;
    $saldo = $monto;
    $filas = [];
    for ($i = 1; $i <= $plazo; $i++) {
        $saldoInicial = $saldo;
        $interes      = $saldoInicial * $r;
        $capital      = $cuota - $interes;
        $saldoFinal   = max(0.0, $saldoInicial - $capital);
        if ($i === $plazo) { $capital = $saldoInicial; $saldoFinal = 0.0; }
        $saldo = $saldoFinal;
        $filas[] = [
            'mes'                        => $i,
            'cuota_fija'                 => round($cuota, 2),
            'saldo_inicial'              => round($saldoInicial, 2),
            'monto_a_interes'            => round($interes, 2),
            'monto_a_devolucion_kapital' => round($capital, 2),
            'saldo_deudor'               => round($saldoFinal, 2),
        ];
    }
    return $filas;
}

$cuota        = calcularCuotaMensual($monto, $tasa, $plazo);
$amort        = generarAmortizacion($monto, $tasa, $plazo);
$totalInteres = array_sum(array_column($amort, 'monto_a_interes'));
$totalAPagar  = round($cuota * $plazo, 2);

try {
    $pdo = db_connect();

    // Obtener fecha_prestamo actual si no se envía
    if ($fecha === '') {
        $r2 = $pdo->prepare('SELECT "fecha_prestamo" FROM "prestamo" WHERE "id_prestamo" = :id');
        $r2->execute([':id' => $id]);
        $fecha = (string) ($r2->fetchColumn() ?? date('Y-m-d'));
    }
    $fechaPrestamo = new DateTimeImmutable($fecha);
    $fechaVenc     = $fechaPrestamo->modify("+{$plazo} months")->format('Y-m-d');

    $pdo->beginTransaction();

    // Actualizar préstamo
    $pdo->prepare(
        'UPDATE "prestamo" SET
            "monto_prestado"  = :monto,
            "tasa_interes"    = :tasa,
            "plazo_meses"     = :plazo,
            "fecha_vencimiento" = :fvenc,
            "total_a_pagar"   = :total,
            "total_interes"   = :tint,
            "updated_at"      = now()
         WHERE "id_prestamo"  = :id'
    )->execute([
        ':monto' => $monto, ':tasa'  => $tasa,  ':plazo' => $plazo,
        ':fvenc' => $fechaVenc, ':total' => $totalAPagar,
        ':tint'  => round($totalInteres, 2), ':id' => $id,
    ]);

    // Eliminar cuotas existentes con estado pendiente y regenerar
    $pdo->prepare(
        'DELETE FROM "pago" WHERE "prestamoIdPrestamo" = :pid AND "estado" = \'pendiente\''
    )->execute([':pid' => $id]);

    $stmtCuota = $pdo->prepare(
        'INSERT INTO "pago"
            ("prestamoIdPrestamo","mes","cuota_fija","saldo_inicial",
             "monto_a_interes","monto_a_devolucion_kapital","saldo_deudor",
             "fecha_pago","estado","isActive")
         VALUES
            (:pid, :mes, :cuota, :sinicial,
             :interes, :capital, :saldo,
             :fpago, \'pendiente\', TRUE)'
    );
    foreach ($amort as $fila) {
        $fpago = $fechaPrestamo->modify("+{$fila['mes']} months")->format('Y-m-d');
        $stmtCuota->execute([
            ':pid'     => $id,
            ':mes'     => $fila['mes'],
            ':cuota'   => $fila['cuota_fija'],
            ':sinicial'=> $fila['saldo_inicial'],
            ':interes' => $fila['monto_a_interes'],
            ':capital' => $fila['monto_a_devolucion_kapital'],
            ':saldo'   => $fila['saldo_deudor'],
            ':fpago'   => $fpago,
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
