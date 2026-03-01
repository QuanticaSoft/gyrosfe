<?php
declare(strict_types=1);

function db_connect(): PDO
{
    $configPath = '/webs/quanticasoft/_private/db.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException("Missing DB config at $configPath");
    }

    $db = include $configPath;
    if (!is_array($db) || empty($db['dsn']) || empty($db['user'])) {
        throw new RuntimeException("Invalid DB config");
    }

    return new PDO(
        $db['dsn'],
        $db['user'],
        $db['pass'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
