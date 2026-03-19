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

$idPrestamo = trim((string) ($_POST['id_prestamo'] ?? ''));
if ($idPrestamo === '' || !preg_match('/^[0-9a-f\-]{36}$/i', $idPrestamo)) {
    echo json_encode(['ok' => false, 'error' => 'id_prestamo inválido']);
    exit;
}

try {
    $pdo = db_connect();

    // Verificar que el préstamo existe y que su fecha_prestamo es hoy
    $stmt = $pdo->prepare(
        'SELECT "fecha_prestamo" FROM "prestamo" WHERE "id_prestamo" = :id'
    );
    $stmt->execute([':id' => $idPrestamo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Préstamo no encontrado']);
        exit;
    }

    $fechaPrestamo = substr((string) $row['fecha_prestamo'], 0, 10);
    $hoy           = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

    if ($fechaPrestamo !== $hoy) {
        echo json_encode(['ok' => false, 'error' => 'Solo se pueden eliminar préstamos creados hoy']);
        exit;
    }

    $pdo->beginTransaction();

    // Eliminar pagos asociados primero (FK)
    $pdo->prepare('DELETE FROM "pago" WHERE "prestamoIdPrestamo" = :id')
        ->execute([':id' => $idPrestamo]);

    // Eliminar el préstamo
    $pdo->prepare('DELETE FROM "prestamo" WHERE "id_prestamo" = :id')
        ->execute([':id' => $idPrestamo]);

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
