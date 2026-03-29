<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$uuid     = trim((string) ($_POST['clienteUuid'] ?? ''));
$rawAccts = trim((string) ($_POST['accounts']    ?? '[]'));

if ($uuid === '') {
    echo json_encode(['ok' => false, 'error' => 'clienteUuid requerido']);
    exit;
}

$accounts = json_decode($rawAccts, true);
if (!is_array($accounts)) {
    echo json_encode(['ok' => false, 'error' => 'accounts inválido']);
    exit;
}

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    $del = $pdo->prepare('DELETE FROM "banco_cliente" WHERE "clienteIdCliente" = :uuid');
    $del->execute([':uuid' => $uuid]);

    $ins = $pdo->prepare(
        'INSERT INTO "banco_cliente"
            ("id_banco_cliente","clienteIdCliente","banco","nombre","moneda",
             "usuario","key","nickname","nota","noCta","isActive")
         VALUES
            (gen_random_uuid(),:uuid,:banco,:nombre,:moneda,
             :usuario,:key,:nickname,:nota,:nocta,:isactive)'
    );

    foreach (array_slice($accounts, 0, 2) as $acc) {
        $banco = trim((string) ($acc['banco'] ?? ''));
        if ($banco === '') continue;

        $ins->execute([
            ':uuid'     => $uuid,
            ':banco'    => $banco,
            ':nombre'   => trim((string) ($acc['nombre']   ?? '')),
            ':moneda'   => trim((string) ($acc['moneda']   ?? 'BOB')),
            ':usuario'  => trim((string) ($acc['usuario']  ?? '')),
            ':key'      => trim((string) ($acc['key']      ?? '')),
            ':nickname' => trim((string) ($acc['nickname'] ?? '')),
            ':nota'     => trim((string) ($acc['nota']     ?? '')),
            ':nocta'    => trim((string) ($acc['noCta']    ?? '')),
            ':isactive' => !empty($acc['isActive']),
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
