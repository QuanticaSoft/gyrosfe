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
    $pdo  = db_connect();
    $stmt = $pdo->prepare(
        'SELECT * FROM "prestamo"
         WHERE "clienteIdCliente" = :uuid
         ORDER BY "fecha_prestamo" ASC, "created_at" ASC'
    );
    $stmt->execute([':uuid' => $uuid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
