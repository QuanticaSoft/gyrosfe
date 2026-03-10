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

$uuid           = trim((string) ($_POST['uuid']           ?? ''));
$nombrecompleto = trim((string) ($_POST['nombrecompleto'] ?? ''));
$ci             = trim((string) ($_POST['ci']             ?? ''));
$numerocelular  = trim((string) ($_POST['numerocelular']  ?? ''));
$numerofijo     = trim((string) ($_POST['numerofijo']     ?? ''));
$vtotarjeta     = trim((string) ($_POST['vtotarjeta']     ?? ''));
$codigo         = trim((string) ($_POST['codigo']         ?? ''));
$sector         = trim((string) ($_POST['sector']         ?? ''));
$isActive       = ($_POST['isActive'] ?? '1') === '1';
$garantenombre  = trim((string) ($_POST['garantenombre']  ?? ''));
$garantecelular = trim((string) ($_POST['garantecelular'] ?? ''));
$observaciones  = trim((string) ($_POST['observaciones']  ?? ''));

if ($uuid === '')           jsonErr('UUID requerido');
if ($nombrecompleto === '') jsonErr('El nombre completo es requerido');
if ($ci === '')             jsonErr('La cédula es requerida');
if ($numerocelular === '')  jsonErr('El celular es requerido');
if ($vtotarjeta === '')     jsonErr('El vencimiento de tarjeta es requerido');
if ($sector === '')         jsonErr('El sector es requerido');
if ($codigo === '')         jsonErr('El código es requerido');
if ($garantenombre === '')  jsonErr('El nombre del garante es requerido');
if ($garantecelular === '') jsonErr('El celular del garante es requerido');

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
            nombrecompleto  = :nombre,
            ci              = :ci,
            numerocelular   = :celular,
            numerofijo      = :fijo,
            vtotarjeta      = :vtotarjeta,
            codigo          = :codigo,
            sector          = :sector,
            "isActive"      = :activo,
            garantenombre   = :garantenombre,
            garantecelular  = :garantecelular,
            observaciones   = :observaciones
        WHERE uuid = :uuid
    ');
    $upd->execute([
        ':uuid'           => $uuid,
        ':nombre'         => $nombrecompleto,
        ':ci'             => $ci,
        ':celular'        => $numerocelular,
        ':fijo'           => $numerofijo !== '' ? $numerofijo : null,
        ':vtotarjeta'     => $vtotarjeta,
        ':codigo'         => $codigo,
        ':sector'         => $sector,
        ':activo'         => $isActive ? 'true' : 'false',
        ':garantenombre'  => $garantenombre,
        ':garantecelular' => $garantecelular,
        ':observaciones'  => $observaciones !== '' ? $observaciones : null,
    ]);

    if ($upd->rowCount() === 0) {
        jsonErr('Cliente no encontrado', 404);
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    jsonErr('Error al actualizar: ' . $e->getMessage(), 500);
}
