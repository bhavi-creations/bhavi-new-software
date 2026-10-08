<?php
declare(strict_types=1);

// Web requests choose their profile by hostname. CLI defaults to local;
// set BHAVI_APP_ENV=live when running the installer on your hosting server.
$environment = strtolower(trim((string) (getenv('BHAVI_APP_ENV') ?: '')));
if ($environment === '') {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = rtrim((string) parse_url('http://' . $host, PHP_URL_HOST), '.');
    $local = $host === '' || $host === 'localhost' || substr($host, -10) === '.localhost'
        || $host === '[::1]' || $host === '::1' || preg_match('/^127(?:\.\d{1,3}){3}$/D', $host);
    $environment = $local ? 'local' : 'live';
}
if (!in_array($environment, ['local', 'live'], true)) {
    throw new RuntimeException('BHAVI_APP_ENV must be local or live.');
}

$config = [
    'db_host' => $environment === 'local' ? '127.0.0.1' : 'localhost',
    'db_port' => '3306',
    'db_name' => $environment === 'local' ? 'bhavi_team_portal' : 'bhavi_new_software',
    'db_user' => $environment === 'local' ? 'root' : 'bhavicreations',
    'db_password' => $environment === 'local' ? '' : 'd8Az75YlgmyBnVM',
];
$profile = __DIR__ . '/config.' . $environment . '.php';
if (is_file($profile)) {
    $overrides = require $profile;
    if (!is_array($overrides)) {
        throw new RuntimeException(basename($profile) . ' must return a configuration array.');
    }
    $config = array_replace($config, array_intersect_key($overrides, $config));
}
foreach (array_keys($config) as $key) {
    $override = getenv('BHAVI_' . strtoupper($key));
    if ($override !== false) {
        $config[$key] = $override;
    }
}
return $config;
