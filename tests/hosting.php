<?php
declare(strict_types=1);

// Configuration fixtures and a disposable database/user; no portal records
// or actual hosting credentials are printed or changed.
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/database/install.php';

function hosting_config(string $path): array { return require $path; }
function hosting_expect(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}

$connectionConfig = hosting_config(dirname(__DIR__) . '/config.php');
$checks = 0;
$fixture = sys_get_temp_dir() . '/bhavi_config_test_' . bin2hex(random_bytes(6));
$database = 'bhavi_hosting_test_' . bin2hex(random_bytes(6));
$username = 'bhavi_host_test_' . bin2hex(random_bytes(6));
$pdo = null;
$userCreated = false;
$savedServer = $_SERVER;
$savedEnvironment = [];
$environmentKeys = ['BHAVI_APP_ENV', 'BHAVI_DB_HOST', 'BHAVI_DB_PORT', 'BHAVI_DB_NAME', 'BHAVI_DB_USER', 'BHAVI_DB_PASSWORD'];
$savedErrorLog = ini_get('error_log');

try {
    mkdir($fixture, 0700);
    copy(dirname(__DIR__) . '/config.php', $fixture . '/config.php');
    foreach ($environmentKeys as $key) {
        $savedEnvironment[$key] = getenv($key);
        putenv($key);
    }
    $_SERVER['HTTP_HOST'] = 'portal.example.test';
    $config = hosting_config($fixture . '/config.php');
    hosting_expect($config['db_name'] !== '' && $config['db_user'] !== '' && $config['db_user'] !== 'root', 'A live hostname selects hosting defaults without needing a separate profile');
    file_put_contents($fixture . '/config.live.php', "<?php return ['db_name'=>'live_fixture','db_user'=>'live_user','db_password'=>'live_fixture_secret'];");
    file_put_contents($fixture . '/config.local.php', "<?php return ['db_name'=>'local_fixture','db_user'=>'local_user'];");
    foreach (['localhost', 'localhost:8080', 'LOCALHOST.', '127.0.0.1', '127.0.0.2:8080', '[::1]', '[::1]:8080', 'app.localhost'] as $host) {
        $_SERVER['HTTP_HOST'] = $host;
        hosting_expect(hosting_config($fixture . '/config.php')['db_name'] === 'local_fixture', 'Local configuration selected for ' . $host);
    }
    $_SERVER['HTTP_HOST'] = 'portal.example.test:443';
    $config = hosting_config($fixture . '/config.php');
    hosting_expect($config['db_name'] === 'live_fixture' && $config['db_user'] === 'live_user', 'Subdomain uses the live profile even if a local override was uploaded');
    putenv('BHAVI_DB_NAME=environment_fixture');
    putenv('BHAVI_DB_PASSWORD=');
    $config = hosting_config($fixture . '/config.php');
    hosting_expect($config['db_name'] === 'environment_fixture' && $config['db_password'] === '', 'Environment variables override live profile including an empty password');
    putenv('BHAVI_DB_NAME');
    putenv('BHAVI_DB_PASSWORD');
    putenv('BHAVI_APP_ENV=local');
    hosting_expect(hosting_config($fixture . '/config.php')['db_name'] === 'local_fixture', 'Explicit local environment supports development hostnames');
    $_SERVER['HTTP_HOST'] = 'localhost';
    putenv('BHAVI_APP_ENV=live');
    hosting_expect(hosting_config($fixture . '/config.php')['db_name'] === 'live_fixture', 'Explicit live environment supports hosting CLI installs');
    putenv('BHAVI_APP_ENV=invalid');
    $invalidRejected = false;
    try { hosting_config($fixture . '/config.php'); } catch (RuntimeException $e) { $invalidRejected = true; }
    hosting_expect($invalidRejected, 'Invalid environment fails clearly');

    // Import exactly the original schema into an isolated, pre-created DB.
    $pdo = portal_connect($connectionConfig, false);
    $sql = str_replace('bhavi_team_portal', $database, file_get_contents(dirname(__DIR__) . '/database/Bhavi_Team_Portal_Full_Database.sql'));
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    $passwordHash = password_hash('Legacy-fixture-password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (full_name,username,password_hash,role) VALUES ('Existing account','legacy.fixture',?,'admin')")->execute([$passwordHash]);
    foreach (['v_employee_totals', 'v_client_totals', 'v_employee_directory', 'v_daily_work_export'] as $view) $pdo->exec('DROP VIEW ' . $view);

    // Mimic hosting: only table permissions on this one database, no views
    // and no server-wide database grants.
    $testPassword = bin2hex(random_bytes(24));
    $pdo->exec("CREATE USER '$username'@'localhost' IDENTIFIED BY " . $pdo->quote($testPassword));
    $userCreated = true;
    $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON `$database`.* TO '$username'@'localhost'");
    $restricted = portal_connect(array_replace($connectionConfig, ['db_name' => $database, 'db_user' => $username, 'db_password' => $testPassword]));
    ini_set('error_log', $fixture . '/expected-view-errors.log');
    portal_ensure_schema($restricted, $database);
    hosting_expect((int) $restricted->query('SELECT MAX(version) FROM portal_schema_versions')->fetchColumn() === PORTAL_SCHEMA_VERSION, 'Original imported database automatically reaches the latest schema with hosting table permissions');
    hosting_expect($restricted->query("SELECT password_hash FROM users WHERE username='legacy.fixture'")->fetchColumn() === $passwordHash, 'Existing login and password hash survive migration');
    foreach (['payslips', 'employee_documents', 'employee_salary_history', 'client_payments', 'client_social_links', 'employee_attendance_sessions', 'employee_attendance_days', 'work_assignments'] as $table) {
        hosting_expect((int) $restricted->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 0, 'New table is ready: ' . $table);
    }
    hosting_expect((int) $pdo->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn() === 0, 'Unavailable optional view permission does not prevent table migrations');
    portal_install($restricted, $database);
    hosting_expect((int) $restricted->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1 && (int) $restricted->query('SELECT COUNT(*) FROM departments')->fetchColumn() === 5, 'Repeat upgrade preserves records and seed departments');
    hosting_expect((bool) $restricted->query("SHOW COLUMNS FROM work_assignments LIKE 'time_spent_minutes'")->fetch(), 'Latest work time column exists');
    foreach (['employee_profiles','employee_salary_history'] as $table) {
        $benefitColumn=$restricted->query("SHOW COLUMNS FROM $table LIKE 'other_benefit_amount'")->fetch();
        hosting_expect($benefitColumn && (float)$benefitColumn['Default']===0.0, 'Hosted benefits amount column starts at zero: '.$table);
    }
    echo "PASS: $checks hosting checks (local/live profiles, environment overrides, original SQL upgrade, existing accounts and restricted table permissions).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    ini_set('error_log', $savedErrorLog);
    $_SERVER = $savedServer;
    foreach ($savedEnvironment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    if ($pdo instanceof PDO) {
        if ($userCreated && preg_match('/^bhavi_host_test_[a-f0-9]{12}$/D', $username)) $pdo->exec("DROP USER '$username'@'localhost'");
        if (preg_match('/^bhavi_hosting_test_[a-f0-9]{12}$/D', $database)) $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    }
    foreach (['config.php', 'config.live.php', 'config.local.php', 'expected-view-errors.log'] as $name) {
        if (is_file($fixture . '/' . $name)) unlink($fixture . '/' . $name);
    }
    if (is_dir($fixture)) rmdir($fixture);
}
exit(isset($failed) ? 1 : 0);
