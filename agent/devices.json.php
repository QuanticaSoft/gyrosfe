<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/db_connect.php';

$agentId = trim((string)($_GET['agent'] ?? ''));
if ($agentId === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing agent']); exit; }

$pdo = db_connect();

// agentId -> Agent.id
$st = $pdo->prepare('SELECT id FROM "Agent" WHERE "agentId"=:a');
$st->execute([':a'=>$agentId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Agent not found']); exit; }

$aid = (int)$row['id'];

$st = $pdo->prepare('
  SELECT "serial","status","lastChangeAt","vendor","product","path"
  FROM "UsbDeviceState"
  WHERE "agentId"=:aid
  ORDER BY "status" ASC, "lastChangeAt" DESC
');
$st->execute([':aid'=>$aid]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok'=>true,'agent'=>$agentId,'devices'=>$items], JSON_UNESCAPED_UNICODE);
