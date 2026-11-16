<?php
declare(strict_types=1);
// Smoke test: "Add a meeting" creates the Zoom link, Meeting ID and passcode automatically (fake Zoom API server).
// Runs against a disposable database that is dropped at the end.
date_default_timezone_set('Asia/Kolkata');
require dirname(__DIR__) . '/database/install.php';
require dirname(__DIR__) . '/includes/credentials.php';
$config = require dirname(__DIR__) . '/config.php';
$database = 'bhavi_portal_test_' . bin2hex(random_bytes(6));
$pdo = new PDO("mysql:host={$config['db_host']};port={$config['db_port']};charset=utf8mb4", $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+05:30'");
$checks = 0; $process = null; $tmp = [];
function ok(bool $c, string $label): void { global $checks; if (!$c) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "  ✓ $label\n"; }
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
final class B {
    public string $csrf = ''; private string $jar;
    public function __construct(private string $base) { global $tmp; $this->jar = tempnam(sys_get_temp_dir(), 'bj'); $tmp[] = $this->jar; }
    public function req(string $path, ?array $data = null): array {
        $h = curl_init($this->base . '/' . $path); $headers = [];
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HEADERFUNCTION => function ($x, $line) use (&$headers) { $p = explode(':', $line, 2); if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); }]);
        if ($data !== null) { curl_setopt($h, CURLOPT_POST, true); curl_setopt($h, CURLOPT_POSTFIELDS, array_filter($data, fn($v) => $v instanceof CURLFile) ? $data : http_build_query($data)); }
        $body = curl_exec($h); $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        if (preg_match('/name="csrf" value="([a-f0-9]{64})"/', (string) $body, $m)) $this->csrf = $m[1];
        if ($status >= 500 || str_contains((string) $body, 'Fatal error') || str_contains((string) $body, 'Warning:') || str_contains((string) $body, 'Deprecated:') || str_contains((string) $body, 'Notice:')) throw new RuntimeException("$path returned $status: " . substr(strip_tags((string) $body), 0, 600));
        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }
    public function post(string $path, array $data): array { return $this->req($path, array_merge(['csrf' => $this->csrf], $data)); }
    public function login(string $u, string $p): array { $this->req('login.php'); $r = $this->post('authenticate.php', ['username' => $u, 'password' => $p]); ok($r['status'] === 303, "login $u"); return $this->req($r['headers']['location']); }
    public function logout(): void { $this->post('logout.php', []); }
}
try {
    portal_install($pdo, $database);
    ok((int) val('SELECT MAX(version) FROM portal_schema_versions') === PORTAL_SCHEMA_VERSION, 'schema installed at v' . PORTAL_SCHEMA_VERSION);
    $hash = password_hash('Secret123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (full_name,username,password_hash,role,must_change_password) VALUES ('Boss MD','md',?,'admin',0),('Ravi Kumar','ravi',?,'employee',0),('Sita Devi','sita',?,'employee',0),('Kiran Raju','kiran',?,'client',0)")->execute([$hash, $hash, $hash, $hash]);
    $md = (int) val("SELECT id FROM users WHERE username='md'"); $ravi = (int) val("SELECT id FROM users WHERE username='ravi'"); $sita = (int) val("SELECT id FROM users WHERE username='sita'"); $kiran = (int) val("SELECT id FROM users WHERE username='kiran'");
    $dept = (int) val('SELECT id FROM departments ORDER BY id LIMIT 1');
    $pdo->prepare("INSERT INTO employee_profiles (user_id,department_id,designation,joining_date) VALUES (?,?,'Designer','2025-01-01'),(?,?,'Editor','2025-01-01')")->execute([$ravi, $dept, $sita, $dept]);
    $pdo->prepare("INSERT INTO clients (client_name,website_url,logo_path,is_active,created_by,user_id,package,payment_total,client_type) VALUES ('Kiran Raju','','',1,?,?,'200000',200000,'premium')")->execute([$md, $kiran]);

    $sock = stream_socket_server('tcp://127.0.0.1:0'); $addr = stream_socket_get_name($sock, false); fclose($sock);
    $env = getenv(); foreach ($config as $k => $v) $env['BHAVI_' . strtoupper($k)] = (string) $v; $env['BHAVI_APP_ENV'] = 'local'; $env['BHAVI_DB_NAME'] = $database;
    $log = tempnam(sys_get_temp_dir(), 'bl'); $tmp[] = $log;
    // Fake Zoom API: records each call and answers like Zoom.
    $mockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zmock' . bin2hex(random_bytes(4)); mkdir($mockDir); $calls = $mockDir . '/calls.log';
    file_put_contents($mockDir . '/router.php', '<?php $b = file_get_contents("php://input"); file_put_contents(' . var_export($calls, true) . ', json_encode(["m" => $_SERVER["REQUEST_METHOD"], "p" => $_SERVER["REQUEST_URI"], "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? "", "b" => json_decode($b ?: "null", true)]) . "\n", FILE_APPEND);
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_SERVER["REQUEST_URI"] === "/users/me/meetings") { $in = json_decode($b, true); if (($in["topic"] ?? "") === "Fail me") { http_response_code(400); echo json_encode(["message" => "Invalid field."]); return; } http_response_code(201); header("Content-Type: application/json"); echo json_encode(["id" => 85512345678, "password" => "Zx91Ab", "join_url" => "https://us06web.zoom.us/j/85512345678?pwd=TokenXyz.1", "topic" => $in["topic"], "start_time" => $in["start_time"]]); return; }
if ($_SERVER["REQUEST_METHOD"] === "DELETE") { http_response_code(204); return; }
http_response_code(404); echo "{}";');
    $msock = stream_socket_server('tcp://127.0.0.1:0'); $maddr = stream_socket_get_name($msock, false); fclose($msock);
    $mock = proc_open([PHP_BINARY, '-S', $maddr, $mockDir . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $mockDir . '/out.log', 'a'], 2 => ['file', $mockDir . '/out.log', 'a']], $mp, $mockDir);
    $env['BHAVI_ZOOM_API_BASE'] = 'http://' . $maddr;
    $env['BHAVI_ZOOM_CLIENT_ID'] = 'TestClientId123'; $env['BHAVI_ZOOM_CLIENT_SECRET'] = 'TestClientSecret123';
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $maddr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;
    $callList = fn() => is_file($calls) ? array_map(fn($l) => json_decode($l, true), file($calls, FILE_IGNORE_NEW_LINES)) : [];

    echo "Not connected: only the paste option\n";
    $admin = new B($base);
    $admin->login('md', 'Secret123!');
    $page = $admin->req('zoom-meetings.php')['body'];
    ok(str_contains($page, 'value="auto" data-zm-mode  disabled') && str_contains($page, 'Connect the Zoom account above'), 'automatic option disabled until Zoom is connected');
    $r = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'zoom_mode' => 'auto', 'title' => 'X', 'starts_at' => '2026-11-19T11:00', 'duration_minutes' => '60']);
    ok($r['status'] === 200 && str_contains($r['body'], 'not connected'), 'auto refused while not connected');

    echo "Connected: create automatically\n";
    $set = $pdo->prepare('INSERT INTO portal_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach (['zoom_access_token' => credential_encrypt('access-abc'), 'zoom_refresh_token' => credential_encrypt('refresh-abc'), 'zoom_token_expires' => (string) (time() + 3000), 'zoom_account_email' => 'office@example.com'] as $k => $v) $set->execute([$k, $v]);
    $page = $admin->req('zoom-meetings.php')['body'];
    ok(str_contains($page, 'value="auto" data-zm-mode checked') && str_contains($page, 'Create meeting') && str_contains($page, 'office@example.com'), 'automatic option is the default when connected');
    $r = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'zoom_mode' => 'auto', 'title' => 'Client review', 'starts_at' => '2026-11-19T11:00', 'duration_minutes' => '45', 'notes' => 'Bring the reel', 'notify' => '1']);
    ok($r['status'] === 303 && str_contains($r['headers']['location'] ?? '', '#meeting-'), 'meeting created (' . $r['status'] . ')');
    $c = $callList();
    ok(count($c) === 1 && $c[0]['m'] === 'POST' && $c[0]['p'] === '/users/me/meetings' && $c[0]['auth'] === 'Bearer access-abc', 'Zoom API called once with the account token');
    ok($c[0]['b']['type'] === 2 && $c[0]['b']['start_time'] === '2026-11-19T11:00:00' && $c[0]['b']['timezone'] === 'Asia/Kolkata' && $c[0]['b']['duration'] === 45 && $c[0]['b']['topic'] === 'Client review', 'scheduled for 19-11-2026 11:00 AM IST, 45 min');
    $m = $pdo->query("SELECT * FROM zoom_meetings WHERE title='Client review'")->fetch();
    ok($m['meeting_number'] === '85512345678' && $m['passcode'] === 'Zx91Ab' && $m['join_url'] === 'https://us06web.zoom.us/j/85512345678?pwd=TokenXyz.1' && (int) $m['auto_created'] === 1 && $m['starts_at'] === '2026-11-19 11:00:00', 'link, Meeting ID and passcode saved from Zoom');
    ok(str_contains((string) val("SELECT message FROM notifications WHERE recipient_id=? AND title='Zoom meeting: Client review'", [$ravi]), 'Meeting ID 8551 2345 678 · Passcode Zx91Ab'), 'employee notification has ID + passcode');
    $flash = $admin->req('zoom-meetings.php')['body'];
    ok(str_contains($flash, 'Zoom meeting created') && str_contains($flash, 'Zoom link ready'), 'MD sees confirmation');

    echo "Employee sees it\n";
    $emp = new B($base);
    $emp->login('ravi', 'Secret123!');
    $ep = $emp->req('zoom-meetings.php')['body'];
    ok(str_contains($ep, 'Client review') && str_contains($ep, 'Thu, 19 Nov · 11:00 AM') && str_contains($ep, '8551 2345 678') && str_contains($ep, 'Zx91Ab'), 'employee sees date, Meeting ID, passcode');
    ok(str_contains($ep, 'https://us06web.zoom.us/j/85512345678?pwd=TokenXyz.1') && str_contains($ep, 'Copy invite') && str_contains($ep, 'zoom-join.php?id=' . $m['id']), 'employee sees link, Copy invite, Join here');
    ok(!str_contains($ep, 'name="zoom_mode"'), 'employee has no create form');

    echo "Errors and delete\n";
    $r = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'zoom_mode' => 'auto', 'title' => 'Fail me', 'starts_at' => '2026-11-20T11:00', 'duration_minutes' => '60']);
    ok($r['status'] === 200 && str_contains($r['body'], 'Invalid field') && !val("SELECT COUNT(*) FROM zoom_meetings WHERE title='Fail me'"), 'Zoom error shown, nothing saved');
    $r = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'zoom_mode' => 'auto', 'title' => 'Old', 'starts_at' => '2020-01-01T11:00', 'duration_minutes' => '60']);
    ok($r['status'] === 200 && str_contains($r['body'], 'future'), 'past date refused');
    $r = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'zoom_mode' => 'manual', 'title' => 'Pasted', 'join_url' => 'https://zoom.us/j/81234567890?pwd=a', 'passcode' => '11', 'starts_at' => '2026-11-21T10:00', 'duration_minutes' => '60']);
    ok($r['status'] === 303 && (int) val("SELECT auto_created FROM zoom_meetings WHERE title='Pasted'") === 0, 'paste option still works');
    $before = count($callList());
    ok($admin->post('zoom-meetings.php', ['action' => 'delete_meeting', 'id' => $m['id']])['status'] === 303, 'meeting deleted');
    $c = $callList();
    ok(count($c) === $before + 1 && end($c)['m'] === 'DELETE' && end($c)['p'] === '/meetings/85512345678', 'deleted on Zoom too');
    $admin->post('zoom-meetings.php', ['action' => 'delete_meeting', 'id' => val("SELECT id FROM zoom_meetings WHERE title='Pasted'")]);
    ok(count($callList()) === $before + 1, 'pasted meeting is not deleted on Zoom');
    echo "\nAll $checks checks passed.\n";
} finally {
    if ($process) { proc_terminate($process); proc_close($process); }
    if (!empty($mock)) { proc_terminate($mock); proc_close($mock); }
    if (!empty($mockDir)) { foreach (glob($mockDir . '/*') ?: [] as $f) @unlink($f); @rmdir($mockDir); }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($tmp as $f) @unlink($f);
}
