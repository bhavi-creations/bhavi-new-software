<?php
declare(strict_types=1);

$config = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'bhavi_team_portal',
    'db_user' => 'root',
    'db_password' => '',
];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}
foreach (array_keys($config) as $key) {
    $environment = getenv('BHAVI_' . strtoupper($key));
    if ($environment !== false) { $config[$key] = $environment; }
}
return $config;
