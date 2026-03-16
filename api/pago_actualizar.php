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
        $sets[]                = '"fecha_pago" = :fpago';
        $params[':fpago']      = $fechaPago;
    }
    if ($estado !== '') {
        $sets[]                = '"estado" = :estado';
        $params[':estado']     = $estado;
    }
    if ($montoPago !== null) {
        $sets[]                = '"monto_pago" = :mpago';
        $params[':mpago']      = $montoPago;
    }
    if ($metodo !== '') {
        $sets[]                = '"metodo_pago" = :metodo';
        $params[':metodo']     = $metodo;
    }
    if ($transferencia !== null) {
        $sets[]                = '"transferencia" = :transf';
        $params[':transf']     = $transferencia;
    }
    if ($estado === 'pagado' || $estado === 'parcial') {
        $sets[]                = '"fecha_de_debito" = CURRENT_DATE';
        if ($userId > 0) {
            $sets[]            = '"userId" = :uid';
            $params[':uid']    = $userId;
        }
    }

    $sql  = 'UPDATE "pago" SET ' . implode(', ', $sets) . ' WHERE "id_pago" = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
