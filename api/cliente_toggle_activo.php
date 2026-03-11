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

$uuid     = trim((string) ($_POST['uuid']     ?? ''));
$isActive = ($_POST['isActive'] ?? '') === '1';

if ($uuid === '') {
    jsonErr('UUID requerido');
}

require_once __DIR__ . '/../lib/db_connect.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    jsonErr('Error de conexión a BD', 500);
}

try {
    $stmt = $pdo->prepare('
        UPDATE "Cliente"
        SET "isActive" = :activo
        WHERE uuid = :uuid
    ');
    $stmt->execute([
        ':activo' => $isActive ? 'true' : 'false',
        ':uuid'   => $uuid,
    ]);

    if ($stmt->rowCount() === 0) {
        jsonErr('Cliente no encontrado', 404);
    }

    echo json_encode(['ok' => true, 'isActive' => $isActive]);

} catch (Throwable $e) {
    jsonErr('Error al actualizar: ' . $e->getMessage(), 500);
}
