<?php
declare(strict_types=1);
// Smoke test for Zoom meetings (add, list, join page, employee view, delete).
// Runs against a disposable database that is dropped at the end.
date_default_timezone_set('Asia/Kolkata');
require dirname(__DIR__) . '/database/install.php';
require dirname(__DIR__) . '/includes/zoom.php';
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
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;
    echo "Zoom meetings\n";
    $admin = new B($base);
    $dash = $admin->login('md', 'Secret123!');
    ok(str_contains($dash['body'], 'href="zoom-meetings.php"'), 'admin sidebar has Zoom meetings');
    $page = $admin->req('zoom-meetings.php');
    ok(str_contains($page['body'], 'Add a meeting'), 'admin sees add form');
    $r = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'title' => 'Team sync', 'join_url' => 'https://us05web.zoom.us/j/81234567890?pwd=AbC123.1', 'passcode' => '4321', 'starts_at' => date('Y-m-d\TH:i', time() + 300), 'duration_minutes' => '60', 'notify' => '1']);
    ok($r['status'] === 303, 'meeting saved');
    ok(val("SELECT meeting_number FROM zoom_meetings WHERE title='Team sync'") === '81234567890', 'meeting number parsed from link');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE title='Zoom meeting: Team sync'") === 2, 'team notified');
    $bad = $admin->post('zoom-meetings.php', ['action' => 'add_meeting', 'title' => 'Bad', 'join_url' => 'https://example.com/x', 'starts_at' => date('Y-m-d\TH:i')]);
    ok(str_contains($bad['body'], 'Zoom'), 'non-Zoom link rejected');
    $list = $admin->req('zoom-meetings.php');
    ok(str_contains($list['body'], 'Team sync') && str_contains($list['body'], 'Live now') && str_contains($list['body'], 'zoom-join.php?id='), 'meeting listed as live with Join here');
    $id = (int) val("SELECT id FROM zoom_meetings WHERE title='Team sync'");
    $join = $admin->req('zoom-join.php?id=' . $id);
    ok(str_contains($join['body'], 'zoom-meeting-' . ZOOM_SDK_VERSION . '.min.js') && str_contains($join['body'], '"meetingNumber":"81234567890"') && str_contains($join['body'], '"passWord":"4321"'), 'join page loads SDK with meeting details');
    ok(!str_contains($join['body'], zoom_config()['client_secret'] ?: 'no-secret-configured-x'), 'client secret never sent to the browser');
    $admin->logout();
    $emp = new B($base);
    $emp->login('ravi', 'Secret123!');
    $ep = $emp->req('zoom-meetings.php');
    ok(str_contains($ep['body'], 'Team sync') && !str_contains($ep['body'], 'Add a meeting'), 'employee sees meeting, no add form');
    $deny = $emp->post('zoom-meetings.php', ['action' => 'delete_meeting', 'id' => (string) $id]);
    ok((int) val('SELECT COUNT(*) FROM zoom_meetings WHERE id=? AND deleted_at IS NULL', [$id]) === 1, 'employee cannot delete');
    $emp->logout();
    $admin->login('md', 'Secret123!'); $zp = $admin->req('zoom-meetings.php');
    $admin->post('zoom-meetings.php', ['action' => 'delete_meeting', 'id' => (string) $id]);
    ok((int) val('SELECT COUNT(*) FROM zoom_meetings WHERE id=? AND deleted_at IS NULL', [$id]) === 0, 'admin deletes meeting');

    echo "Instant meetings + chat\n";
    if (zoom_ready()) {
        ok(str_contains($zp['body'], 'Connect Zoom account') && str_contains($zp['body'], $base . '/zoom-connect.php'), 'admin sees Connect Zoom with redirect URL');
        $conn = $admin->req('zoom-connect.php');
        $loc = $conn['headers']['location'] ?? '';
        ok($conn['status'] === 303 && str_starts_with($loc, 'https://zoom.us/oauth/authorize?') && str_contains($loc, 'redirect_uri=' . rawurlencode($base . '/zoom-connect.php')) && str_contains($loc, 'state='), 'connect redirects to Zoom OAuth with redirect URL and state');
        $badState = $admin->req('zoom-connect.php?code=abc&state=wrong');
        ok($badState['status'] === 400, 'OAuth callback with wrong state is rejected');
    }
    $emp2 = new B($base); $emp2->login('ravi', 'Secret123!');
    ok(($emp2->req('zoom-connect.php')['status']) !== 303 || !str_contains($emp2->req('zoom-connect.php')['headers']['location'] ?? '', 'zoom.us'), 'employee cannot start Zoom connection');
    $emp2->logout();
    $admin->req('chat.php');
    $chat = $admin->post('chat.php', ['action' => 'create_conversation', 'conversation_type' => 'private', 'user_ids' => [(string) $ravi]]);
    $chatId = (int) val("SELECT MAX(id) FROM chat_conversations");
    $room = $admin->req('chat.php?id=' . $chatId);
    ok(!str_contains($room['body'], 'value="start_zoom"'), 'no Zoom button in chat until Zoom is connected');
    $admin->post('chat.php?id=' . $chatId, ['action' => 'send_message', 'conversation_id' => (string) $chatId, 'body' => 'See https://example.com/x?a=1&b=2. <script>alert(1)</script>']);
    $room = $admin->req('chat.php?id=' . $chatId);
    ok(str_contains($room['body'], '<a href="https://example.com/x?a=1&amp;b=2" target="_blank"') && str_contains($room['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'chat links are clickable and HTML stays escaped');
    $noZoom = $admin->post('chat.php?id=' . $chatId, ['action' => 'start_zoom', 'conversation_id' => (string) $chatId]);
    ok(str_contains($noZoom['body'], 'not connected'), 'start_zoom explains Zoom is not connected');

    echo "Zoom details saved in the portal (no config.zoom.php, like a fresh subdomain)\n";
    $cfgFile = dirname(__DIR__) . '/config.zoom.php'; $cfgBak = $cfgFile . '.testbak';
    if (is_file($cfgFile)) rename($cfgFile, $cfgBak);
    try {
        $np = $admin->req('zoom-meetings.php');
        ok(str_contains($np['body'], 'Zoom app details') && str_contains($np['body'], 'value="save_zoom_credentials"') && !str_contains($np['body'], 'config.zoom.php'), 'admin sees Zoom details form instead of the file error');
        $badKeys = $admin->post('zoom-meetings.php', ['action' => 'save_zoom_credentials', 'client_id' => 'x', 'client_secret' => 'y']);
        ok(str_contains($badKeys['body'], 'Client ID exactly'), 'invalid Client ID rejected');
        $admin->post('zoom-meetings.php', ['action' => 'save_zoom_credentials', 'client_id' => 'TestClientId123', 'client_secret' => 'TestSecretValue4567890']);
        ok(val("SELECT setting_value FROM portal_settings WHERE setting_key='zoom_client_id'") === 'TestClientId123', 'Client ID saved');
        ok(!str_contains((string) val("SELECT setting_value FROM portal_settings WHERE setting_key='zoom_client_secret'"), 'TestSecretValue'), 'Client Secret saved encrypted');
        $after = $admin->req('zoom-meetings.php');
        ok(str_contains($after['body'], 'Saved · Client ID TestClientId123') && str_contains($after['body'], 'Connect Zoom account') && !str_contains($after['body'], 'TestSecretValue'), 'page shows Zoom ready, secret not shown');
        $conn2 = $admin->req('zoom-connect.php');
        ok(str_contains($conn2['headers']['location'] ?? '', 'client_id=TestClientId123'), 'Zoom connect uses the saved Client ID');
        $emp3 = new B($base); $emp3->login('ravi', 'Secret123!');
        $ek = $emp3->post('zoom-meetings.php', ['action' => 'save_zoom_credentials', 'client_id' => 'HackerId12345', 'client_secret' => 'HackerSecret12345']);
        ok(val("SELECT setting_value FROM portal_settings WHERE setting_key='zoom_client_id'") === 'TestClientId123', 'employee cannot change Zoom details');
    } finally {
        if (is_file($cfgBak)) rename($cfgBak, $cfgFile);
    }

    echo "\nAll $checks checks passed.\n";
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    if (isset($log) && is_file($log)) echo "--- server log ---\n" . substr((string) file_get_contents($log), -3000) . "\n";
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (preg_match('/^bhavi_portal_test_[a-f0-9]{12}$/D', $database)) $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($tmp as $t) @unlink($t);
}
exit(!empty($failed) ? 1 : 0);
