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

$idPago      = trim((string) ($_POST['id_pago']      ?? ''));
$fechaPago   = trim((string) ($_POST['fecha_pago']   ?? ''));
$estado      = trim((string) ($_POST['estado']       ?? ''));
$montoPago   = isset($_POST['monto_pago'])   ? (float) $_POST['monto_pago']   : null;
$metodo      = trim((string) ($_POST['metodo_pago']  ?? ''));
$transferencia = isset($_POST['transferencia']) ? (float) $_POST['transferencia'] : null;
$userId      = (int) ($_SESSION['user_id'] ?? 0);

if ($idPago === '') {
    echo json_encode(['ok' => false, 'error' => 'id_pago requerido']);
    exit;
}

// Estados válidos
$estadosValidos = ['pendiente', 'pagado', 'vencido', 'parcial'];
if ($estado !== '' && !in_array($estado, $estadosValidos, true)) {
    echo json_encode(['ok' => false, 'error' => 'Estado inválido']);
    exit;
}

try {
    $pdo = db_connect();

    $sets   = ['"updated_at" = now()'];
    $params = [':id' => $idPago];

    if ($fechaPago !== '') {
        $sets[]           = '"fecha_pago" = :fpago';
        $params[':fpago'] = $fechaPago;
    }
    if ($estado !== '') {
        $sets[]            = '"estado" = :estado';
        $params[':estado'] = $estado;
    }
    if ($montoPago !== null) {
        $sets[]            = '"monto_pago" = :mpago';
        $params[':mpago']  = $montoPago;
    }
    if ($metodo !== '') {
        $sets[]             = '"metodo_pago" = :metodo';
        $params[':metodo']  = $metodo;
    }
    if ($transferencia !== null) {
        $sets[]            = '"transferencia" = :transf';
        $params[':transf'] = $transferencia;
    }

    if ($estado === 'pagado' || $estado === 'parcial') {
        $sets[] = '"fecha_de_debito" = CURRENT_DATE';
        if ($userId > 0) {
            $sets[]          = '"userId" = :uid';
            $params[':uid']  = $userId;
        }

        // Calcular dias_real y valores reales de amortización
        $stmtInfo = $pdo->prepare('
            SELECT pg."mes", pg."saldo_inicial", pg."cuota_fija",
                   pg."prestamoIdPrestamo",
                   pr."tasa_interes", pr."fecha_prestamo"
            FROM "pago" pg
            JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
            WHERE pg."id_pago" = :id
            LIMIT 1
        ');
        $stmtInfo->execute([':id' => $idPago]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($info) {
            $today = new DateTimeImmutable('today');

            if ((int) $info['mes'] === 1) {
                $refDate = new DateTimeImmutable($info['fecha_prestamo']);
            } else {
                $stmtPrev = $pdo->prepare('
                    SELECT "fecha_de_debito" FROM "pago"
                    WHERE "prestamoIdPrestamo" = :pid AND mes = :prevmes
                    LIMIT 1
                ');
                $stmtPrev->execute([
                    ':pid'     => $info['prestamoidprestamo'],
                    ':prevmes' => (int) $info['mes'] - 1,
                ]);
                $prev    = $stmtPrev->fetch(PDO::FETCH_ASSOC);
                $refDate = new DateTimeImmutable(
                    (!empty($prev['fecha_de_debito'])) ? $prev['fecha_de_debito'] : $info['fecha_prestamo']
                );
            }

            $diasReal    = (int) $today->diff($refDate)->days;
            $tasaDiaria  = (float) $info['tasa_interes'] / 100.0 / 30.0;
            $saldoIni    = (float) $info['saldo_inicial'];
            $cuotaFija   = (float) $info['cuota_fija'];
            $interesReal = round($saldoIni * $tasaDiaria * $diasReal, 2);
            $capitalReal = round(max(0.0, $cuotaFija - $interesReal), 2);
            $saldoReal   = round(max(0.0, $saldoIni - $capitalReal), 2);

            $sets[]                    = '"dias_real"         = :diasreal';
            $sets[]                    = '"interes_real"      = :interesreal';
            $sets[]                    = '"capital_real"      = :capitalreal';
            $sets[]                    = '"saldo_deudor_real" = :saldoreal';
            $params[':diasreal']       = $diasReal;
            $params[':interesreal']    = $interesReal;
            $params[':capitalreal']    = $capitalReal;
            $params[':saldoreal']      = $saldoReal;
        }
    }

    $sql  = 'UPDATE "pago" SET ' . implode(', ', $sets) . ' WHERE "id_pago" = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
