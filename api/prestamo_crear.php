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

$uuid         = trim((string) ($_POST['clienteUuid']  ?? ''));
$monto        = (float)  ($_POST['monto_prestado']   ?? 0);
$tasa         = (float)  ($_POST['tasa_interes']      ?? 0);
$plazo        = (int)    ($_POST['plazo_meses']        ?? 0);
$fechaStr     = trim((string) ($_POST['fecha_prestamo'] ?? ''));
$userId       = (int) ($_SESSION['user_id'] ?? 0);

if ($uuid === '' || $monto <= 0 || $plazo <= 0 || $fechaStr === '') {
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos o inválidos']);
    exit;
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

// ── Generar tabla de amortización ────────────────────────────────────
function generarAmortizacion(float $monto, float $tasa, int $plazo): array
{
    $cuota  = calcularCuotaMensual($monto, $tasa, $plazo);
    $r      = $tasa / 100.0;
    $saldo  = $monto;
    $filas  = [];

    for ($i = 1; $i <= $plazo; $i++) {
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
        ];
    }
    return $filas;
}

$amort         = generarAmortizacion($monto, $tasa, $plazo);
$totalInteres  = array_sum(array_column($amort, 'monto_a_interes'));
$cuota         = calcularCuotaMensual($monto, $tasa, $plazo);
$totalAPagar   = round($cuota * $plazo, 2);

// Fecha de vencimiento = fecha_prestamo + plazo meses
$fechaVenc = $fechaPrestamo->modify("+{$plazo} months")->format('Y-m-d');

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // Insertar préstamo
    $stmtP = $pdo->prepare(
        'INSERT INTO "prestamo"
            ("clienteIdCliente","userId","monto_prestado","tasa_interes","plazo_meses",
             "fecha_prestamo","fecha_vencimiento","total_a_pagar","total_interes","isActive")
         VALUES
            (:uuid, :uid, :monto, :tasa, :plazo,
             :fprest, :fvenc, :total, :tint, TRUE)
         RETURNING "id_prestamo"'
    );
    $stmtP->execute([
        ':uuid'   => $uuid,
        ':uid'    => $userId ?: null,
        ':monto'  => $monto,
        ':tasa'   => $tasa,
        ':plazo'  => $plazo,
        ':fprest' => $fechaPrestamo->format('Y-m-d'),
        ':fvenc'  => $fechaVenc,
        ':total'  => $totalAPagar,
        ':tint'   => round($totalInteres, 2),
    ]);
    $prestamoId = $stmtP->fetchColumn();

    // Insertar cuotas (pago)
    $stmtCuota = $pdo->prepare(
        'INSERT INTO "pago"
            ("prestamoIdPrestamo","userId","mes","cuota_fija","saldo_inicial",
             "monto_a_interes","monto_a_devolucion_kapital","saldo_deudor",
             "fecha_pago","estado","isActive")
         VALUES
            (:pid, :uid, :mes, :cuota, :sinicial,
             :interes, :capital, :saldo,
             :fpago, \'pendiente\', TRUE)'
    );

    foreach ($amort as $fila) {
        // fecha de pago = fecha_prestamo + N meses
        $fpago = $fechaPrestamo->modify("+{$fila['mes']} months")->format('Y-m-d');
        $stmtCuota->execute([
            ':pid'     => $prestamoId,
            ':uid'     => $userId ?: null,
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
    echo json_encode(['ok' => true, 'id_prestamo' => $prestamoId]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
