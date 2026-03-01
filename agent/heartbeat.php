<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/* =====================
   Helpers
===================== */
function get_headers_safe(): array {
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        // Normalizar a un array simple
        return is_array($h) ? $h : [];
    }

    $h = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $h[$name] = $v;
        }
    }
    return $h;
}

function header_value(array $headers, string $key): string {
    // Acceso case-insensitive
    foreach ($headers as $k => $v) {
        if (strcasecmp((string)$k, $key) === 0) return trim((string)$v);
    }
    return '';
}

function client_ip(): ?string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function json_fail(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================
   Method
===================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, ['ok' => false, 'error' => 'Method not allowed']);
}

/* =====================
   Headers
===================== */
$headers = get_headers_safe();

$agentId = header_value($headers, 'X-Agent-Id');
$agentToken = header_value($headers, 'X-Agent-Token');

if ($agentId === '' || $agentToken === '') {
    json_fail(401, [
        'ok' => false,
        'error' => 'Missing headers',
        'need' => ['X-Agent-Id', 'X-Agent-Token'],
        'seen_headers' => array_keys($headers),
    ]);
}

/* =====================
   Body
===================== */
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) $body = [];

$uptime  = (int)($body['uptimeSec'] ?? 0);
$version = (string)($body['version'] ?? '');
$localIp = (string)($body['localIp'] ?? '');

/* =====================
   DB
===================== */
require_once __DIR__ . '/../lib/db_connect.php';

$pdo = null;

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // 1) Buscar agente
    $stmt = $pdo->prepare('SELECT id, token FROM "Agent" WHERE "agentId" = :agentId');
    $stmt->execute([':agentId' => $agentId]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent || !isset($agent['token']) || !hash_equals((string)$agent['token'], $agentToken)) {
        $pdo->rollBack();
        json_fail(403, ['ok' => false, 'error' => 'Invalid agent or token']);
    }

    $agentDbId = (int)$agent['id'];
    $nowIp = client_ip();

    // 2) Update Agent
    $stmt = $pdo->prepare(
        'UPDATE "Agent"
         SET "lastSeen"  = now(),
             "lastIp"    = :ip,
             "version"   = :version,
             "updatedAt" = now()
         WHERE id = :id'
    );
    $stmt->execute([
        ':ip'      => $nowIp,
        ':version' => $version,
        ':id'      => $agentDbId,
    ]);

    // 3) Insert Heartbeat
    $stmt = $pdo->prepare(
        'INSERT INTO "Heartbeat"
         ("agentId","uptimeSec","localIp","createdAt")
         VALUES (:aid,:uptime,:localIp,now())'
    );
    $stmt->execute([
        ':aid'     => $agentDbId,
        ':uptime'  => $uptime,
        ':localIp' => $localIp,
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'ok'      => true,
        'agentId' => $agentId,
        'ts'      => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    json_fail(500, ['ok' => false, 'error' => 'Server error']);
}