<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$uuid = trim((string) ($_GET['uuid'] ?? ''));
if ($uuid === '') {
    echo json_encode(['ok' => false, 'error' => 'uuid requerido']);
    exit;
}

try {
    $pdo = db_connect();

    // Cargar préstamos del cliente
    $stmtP = $pdo->prepare(
        'SELECT * FROM "prestamo"
         WHERE "clienteIdCliente" = :uuid
         ORDER BY "fecha_prestamo" ASC, "created_at" ASC'
    );
    $stmtP->execute([':uuid' => $uuid]);
    $prestamos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    if (empty($prestamos)) {
        echo json_encode(['ok' => true, 'data' => []]);
        exit;
    }

    // Para cada préstamo, cargar sus pagos
    $stmtCuotas = $pdo->prepare(
        'SELECT * FROM "pago"
         WHERE "prestamoIdPrestamo" = :pid
         ORDER BY "mes" ASC'
    );

    $resultado = [];
    foreach ($prestamos as $p) {
        $stmtCuotas->execute([':pid' => $p['id_prestamo']]);
        $cuotas = $stmtCuotas->fetchAll(PDO::FETCH_ASSOC);
        $resultado[] = [
            'prestamo' => $p,
            'cuotas'   => $cuotas,
        ];
    }

    echo json_encode(['ok' => true, 'data' => $resultado]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
