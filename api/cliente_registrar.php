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

$serial         = trim((string) ($_POST['serial']         ?? ''));
$nombrecompleto = trim((string) ($_POST['nombrecompleto'] ?? ''));
$ci             = trim((string) ($_POST['ci']             ?? ''));
$numerocelular  = trim((string) ($_POST['numerocelular']  ?? ''));
$numerofijo     = trim((string) ($_POST['numerofijo']     ?? ''));
$vtotarjeta     = trim((string) ($_POST['vtotarjeta']     ?? ''));
$codigo         = trim((string) ($_POST['codigo']         ?? ''));
$sector         = trim((string) ($_POST['sector']         ?? ''));
$garantenombre  = trim((string) ($_POST['garantenombre']  ?? ''));
$garantecelular = trim((string) ($_POST['garantecelular'] ?? ''));
$observaciones  = trim((string) ($_POST['observaciones']  ?? ''));

if ($serial === '')         jsonErr('El serial del dispositivo es requerido');
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
    $pdo->beginTransaction();

    // 1) Verificar que el serial no esté ya registrado en Cliente
    $chk = $pdo->prepare('SELECT uuid FROM "Cliente" WHERE dispositivo = :serial LIMIT 1');
    $chk->execute([':serial' => $serial]);
    if ($chk->fetch()) {
        $pdo->rollBack();
        jsonErr('Este dispositivo ya está registrado con un cliente');
    }

    // 2) Insertar nuevo Cliente
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $ins = $pdo->prepare('
        INSERT INTO "Cliente"
            (uuid, nombrecompleto, ci, numerocelular, numerofijo, vtotarjeta, codigo,
             "isActive", dispositivo, sector, garantenombre, garantecelular, observaciones, fecharegistro)
        VALUES
            (:uuid, :nombre, :ci, :celular, :fijo, :vtotarjeta, :codigo,
             true, :serial, :sector, :garantenombre, :garantecelular, :observaciones, NOW())
    ');
    $ins->execute([
        ':uuid'           => $uuid,
        ':nombre'         => $nombrecompleto,
        ':ci'             => $ci,
        ':celular'        => $numerocelular,
        ':fijo'           => $numerofijo !== '' ? $numerofijo : null,
        ':vtotarjeta'     => $vtotarjeta,
        ':codigo'         => $codigo,
        ':serial'         => $serial,
        ':sector'         => $sector,
        ':garantenombre'  => $garantenombre,
        ':garantecelular' => $garantecelular,
        ':observaciones'  => $observaciones !== '' ? $observaciones : null,
    ]);

    // 3) Upsert en Dispositivos (por si el serial aún no existe allí)
    $upsert = $pdo->prepare('
        INSERT INTO "Dispositivos" (serial, status, registro, "updatedAt")
        VALUES (:serial, \'registered\', NOW(), NOW())
        ON CONFLICT (serial) DO UPDATE
            SET status = \'registered\', "updatedAt" = NOW()
    ');
    $upsert->execute([':serial' => $serial]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'uuid' => $uuid]);

} catch (Throwable $e) {
    $pdo->rollBack();
    jsonErr('Error al registrar: ' . $e->getMessage(), 500);
}
