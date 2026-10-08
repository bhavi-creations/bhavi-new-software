<?php
declare(strict_types=1);

function portal_connect(array $config, bool $selectDatabase = true): PDO
{
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Enable the PDO MySQL extension in your PHP settings.');
    }
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', (string) $config['db_name'])) {
        throw new RuntimeException('Set a valid database name in the active database configuration.');
    }
    if ((string) $config['db_user'] === '' || (string) $config['db_host'] === '') {
        throw new RuntimeException('Set the database host and username in the active database configuration.');
    }
    $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};charset=utf8mb4";
    if ($selectDatabase) {
        $dsn .= ';dbname=' . $config['db_name'];
    }
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
}
