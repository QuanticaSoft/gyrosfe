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

$uuid          = trim((string) ($_POST['uuid']           ?? ''));
$nombrecompleto = trim((string) ($_POST['nombrecompleto'] ?? ''));
$ci            = trim((string) ($_POST['ci']             ?? ''));
$numerocelular = trim((string) ($_POST['numerocelular']  ?? ''));
$sector        = trim((string) ($_POST['sector']         ?? ''));
$isActive      = ($_POST['isActive'] ?? '1') === '1';

if ($uuid === '')           jsonErr('UUID requerido');
if ($nombrecompleto === '') jsonErr('El nombre completo es requerido');
if ($ci === '')             jsonErr('La cédula es requerida');

require_once __DIR__ . '/../lib/db_connect.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    jsonErr('Error de conexión a BD', 500);
}

try {
    $upd = $pdo->prepare('
        UPDATE "Cliente"
        SET
            nombrecompleto = :nombre,
            ci             = :ci,
            numerocelular  = :celular,
            sector         = :sector,
            "isActive"     = :activo
        WHERE uuid = :uuid
    ');
    $upd->execute([
        ':uuid'    => $uuid,
        ':nombre'  => $nombrecompleto,
        ':ci'      => $ci,
        ':celular' => $numerocelular,
        ':sector'  => $sector,
        ':activo'  => $isActive ? 'true' : 'false',
    ]);

    if ($upd->rowCount() === 0) {
        jsonErr('Cliente no encontrado', 404);
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    jsonErr('Error al actualizar: ' . $e->getMessage(), 500);
}
