<?php
require_once __DIR__ . '/includes/bootstrap.php';
$config = require __DIR__ . '/config.php';
try {
    $connection = new PDO("mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_password']);
    if (!(int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn()) { redirect('setup.php'); }
} catch (PDOException $e) { redirect('setup.php'); }
$user = current_user();
redirect($user ? dashboard_path($user) : 'login.php');
