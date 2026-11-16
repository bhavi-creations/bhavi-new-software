<?php
declare(strict_types=1);
// Smoke test: assigned task files are named task-N-file-M and the employee can "Download all" as a ZIP.
// Runs against a disposable database that is dropped at the end.
date_default_timezone_set('Asia/Kolkata');
require dirname(__DIR__) . '/database/install.php';
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
    $clientId = (int) $pdo->lastInsertId();

    $sock = stream_socket_server('tcp://127.0.0.1:0'); $addr = stream_socket_get_name($sock, false); fclose($sock);
    $env = getenv(); foreach ($config as $k => $v) $env['BHAVI_' . strtoupper($k)] = (string) $v; $env['BHAVI_APP_ENV'] = 'local'; $env['BHAVI_DB_NAME'] = $database;
    $log = tempnam(sys_get_temp_dir(), 'bl'); $tmp[] = $log;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;


    echo "Assign 2 tasks with files\n";
    $admin = new B($base);
    $admin->login('md', 'Secret123!');
    $admin->req('manager-assign-work.php');
    $mk = function (string $name, string $data) use (&$tmp): CURLFile { $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aw' . bin2hex(random_bytes(4)) . '-' . $name; file_put_contents($p, $data); $tmp[] = $p; return new CURLFile($p, '', $name); };
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $post = ['csrf' => $admin->csrf, 'department_id' => $dept, 'notify_present' => '1',
        'tasks[0][employee_id]' => $ravi, 'tasks[0][client_id]' => $clientId, 'tasks[0][work_date]' => date('Y-m-d'), 'tasks[0][editing_type]' => 'poster', 'tasks[0][title]' => 'Diwali poster', 'tasks[0][description]' => 'x', 'tasks[0][reference_link]' => '', 'tasks[0][priority]' => 'normal',
        'tasks[0][attachments][0]' => $mk('Photo ONE.png', $png), 'tasks[0][attachments][1]' => $mk('brief.pdf', $pdf), 'tasks[0][attachments][2]' => $mk('logo final.png', $png),
        'tasks[1][employee_id]' => $ravi, 'tasks[1][client_id]' => $clientId, 'tasks[1][work_date]' => date('Y-m-d'), 'tasks[1][editing_type]' => 'reel', 'tasks[1][title]' => 'Reel edit', 'tasks[1][description]' => 'y', 'tasks[1][reference_link]' => '', 'tasks[1][priority]' => 'normal',
        'tasks[1][attachments][0]' => $mk('a.png', $png), 'tasks[1][attachments][1]' => $mk('b.pdf', $pdf)];
    $r = $admin->req('manager-assign-work.php', $post);
    ok($r['status'] === 303, 'two tasks saved (' . $r['status'] . ')');
    $t1 = (int) val("SELECT id FROM work_assignments WHERE title='Diwali poster'"); $t2 = (int) val("SELECT id FROM work_assignments WHERE title='Reel edit'");
    $names = fn(int $id) => $pdo->query('SELECT original_name FROM work_assignment_attachments WHERE assignment_id=' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    ok($names($t1) === ['task-1-file-1.png', 'task-1-file-2.pdf', 'task-1-file-3.png'], 'task 1 files: ' . implode(', ', $names($t1)));
    ok($names($t2) === ['task-2-file-1.png', 'task-2-file-2.pdf'], 'task 2 files: ' . implode(', ', $names($t2)));

    echo "Edit task 2, add one more file\n";
    $admin->req('manager-assign-work.php?edit=' . $t2);
    $r = $admin->req('manager-assign-work.php?edit=' . $t2, ['csrf' => $admin->csrf, 'department_id' => $dept, 'notify_present' => '1',
        'tasks[0][employee_id]' => $ravi, 'tasks[0][client_id]' => $clientId, 'tasks[0][work_date]' => date('Y-m-d'), 'tasks[0][editing_type]' => 'reel', 'tasks[0][title]' => 'Reel edit', 'tasks[0][description]' => 'y', 'tasks[0][reference_link]' => '', 'tasks[0][priority]' => 'normal',
        'tasks[0][attachments][0]' => $mk('c.png', $png)]);
    ok($r['status'] === 303, 'edit saved (' . $r['status'] . ')');
    ok($names($t2) === ['task-2-file-1.png', 'task-2-file-2.pdf', 'task-2-file-3.png'], 'edited task keeps task-2 and continues: ' . implode(', ', $names($t2)));

    echo "Employee: files + Download all\n";
    $emp = new B($base);
    $emp->login('ravi', 'Secret123!');
    $page = $emp->req('employee-assigned-work.php?task=' . $t1)['body'];
    ok(str_contains($page, 'task-1-file-1.png') && str_contains($page, 'task-1-file-3.png'), 'employee sees renamed files');
    ok(str_contains($page, 'download-assignment-files.php?id=' . $t1) && str_contains($page, 'Download all (3 files)'), 'employee sees Download all button');
    $zip = $emp->req('download-assignment-files.php?id=' . $t1);
    ok($zip['status'] === 200 && str_starts_with($zip['headers']['content-type'] ?? '', 'application/zip') && str_starts_with($zip['body'], "PK\x03\x04"), 'ZIP downloaded');
    ok(str_contains($zip['body'], 'task-1-file-1.png') && str_contains($zip['body'], 'task-1-file-2.pdf') && str_contains($zip['body'], 'task-1-file-3.png') && str_contains($zip['body'], $pdf), 'ZIP has all 3 files');
    $zp = sys_get_temp_dir() . '/awzip' . bin2hex(random_bytes(3)) . '.zip'; file_put_contents($zp, $zip['body']); $tmp[] = $zp;
    if (class_exists('ZipArchive')) { $za = new ZipArchive(); ok($za->open($zp) === true && $za->numFiles === 3 && $za->getFromName('task-1-file-2.pdf') === $pdf, 'ZIP opens correctly'); $za->close(); }
    $pdo->prepare("INSERT INTO work_assignments (employee_id,department_id,client_id,title,work_date,assigned_by) VALUES (?,?,?,'Other',CURDATE(),?)")->execute([$sita, $dept, $clientId, $md]);
    ok($emp->req('download-assignment-files.php?id=' . (int) $pdo->lastInsertId())['status'] === 404, 'cannot download another employee\'s task');
    $sitaB = new B($base); $sitaB->login('sita', 'Secret123!');
    ok($sitaB->req('download-assignment-files.php?id=' . $t1)['status'] === 404, 'other employee blocked');
    ok($admin->req('download-assignment-files.php?id=' . $t2)['status'] === 200, 'MD can download all too');
    ok(str_contains($admin->req('manager-assigned-status.php')['body'], 'download-assignment-files.php?id=' . $t1), 'MD status dialog has Download all');
    echo "\nAll $checks checks passed.\n";
} finally {
    if ($process) { proc_terminate($process); proc_close($process); }
    try { foreach ($pdo->query("SELECT file_path FROM `$database`.work_assignment_attachments")->fetchAll(PDO::FETCH_COLUMN) as $fp) @unlink(dirname(__DIR__) . '/includes/' . $fp); } catch (Throwable $e) {}
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($tmp as $f) @unlink($f);
}
