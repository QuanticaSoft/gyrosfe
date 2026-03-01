<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/db_connect.php';

function h(string $name): string {
  $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return $_SERVER[$k] ?? '';
}

function out(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

$agentIdHeader = h('x-agent-id');
$tokenHeader   = h('x-agent-token');

if ($agentIdHeader === '' || $tokenHeader === '') out(401, ['ok'=>false,'error'=>'Missing headers']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok'=>false,'error'=>'Method not allowed']);

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) $body = [];

$action  = (string)($body['action'] ?? ''); // connect|disconnect
$path    = (string)($body['path'] ?? '');
$vendor  = (string)($body['vendor'] ?? '');
$product = (string)($body['product'] ?? '');
$serial  = (string)($body['serial'] ?? '');

if ($action !== 'connect' && $action !== 'disconnect') out(400, ['ok'=>false,'error'=>'Invalid action']);
if ($serial === '') out(400, ['ok'=>false,'error'=>'Missing serial']); // importante para estado actual

try {
  $pdo = db_connect();
  $pdo->beginTransaction();

  // validar agente + token
  $st = $pdo->prepare('SELECT id, "token" FROM "Agent" WHERE "agentId" = :aid');
  $st->execute([':aid' => $agentIdHeader]);
  $agent = $st->fetch(PDO::FETCH_ASSOC);

  if (!$agent || !isset($agent['token']) || !hash_equals((string)$agent['token'], $tokenHeader)) {
    $pdo->rollBack();
    out(403, ['ok'=>false,'error'=>'Invalid agent or token']);
  }
  $agentDbId = (int)$agent['id'];

  // 1) insertar evento historico
  $st = $pdo->prepare('
    INSERT INTO "UsbEvent" ("agentId","createdAt","action","path","vendor","product","serial")
    VALUES (:aid, now(), :action, :path, :vendor, :product, :serial)
  ');
  $st->execute([
    ':aid'     => $agentDbId,
    ':action'  => $action,
    ':path'    => $path    !== '' ? $path    : null,
    ':vendor'  => $vendor  !== '' ? $vendor  : null,
    ':product' => $product !== '' ? $product : null,
    ':serial'  => $serial,
  ]);

  // 2) actualizar estado actual (upsert)
  $status = ($action === 'connect') ? 'connected' : 'disconnected';

  $st = $pdo->prepare('
    INSERT INTO "UsbDeviceState"
      ("agentId","serial","status","lastChangeAt","vendor","product","path","updatedAt")
    VALUES
      (:aid,:serial,:status,now(),:vendor,:product,:path,now())
    ON CONFLICT ("agentId","serial") DO UPDATE
    SET "status" = EXCLUDED."status",
        "lastChangeAt" = EXCLUDED."lastChangeAt",
        "vendor" = EXCLUDED."vendor",
        "product" = EXCLUDED."product",
        "path" = EXCLUDED."path",
        "updatedAt" = now()
  ');
  $st->execute([
    ':aid'     => $agentDbId,
    ':serial'  => $serial,
    ':status'  => $status,
    ':vendor'  => $vendor  !== '' ? $vendor  : null,
    ':product' => $product !== '' ? $product : null,
    ':path'    => $path    !== '' ? $path    : null,
  ]);

  $pdo->commit();
  out(201, ['ok'=>true]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  out(500, ['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}
