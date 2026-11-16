<?php
declare(strict_types=1);
// Smoke test: MD sends a client request straight to an employee (task + client files as task-1-file-N).
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
    // Client files go to a private folder outside the website (like bhavi-private/requirements on the live server).
    $reqDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bhavi-req-' . bin2hex(random_bytes(4)); mkdir($reqDir); $env['BHAVI_REQUIREMENT_DIR'] = $reqDir;
    $log = tempnam(sys_get_temp_dir(), 'bl'); $tmp[] = $log;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;



    echo "Client sends a request with 2 files\n";
    $cl = new B($base);
    $cl->login('kiran', 'Secret123!');
    $cl->req('client-reuirement.php');
    $mk = function (string $name, string $data) use (&$tmp): CURLFile { $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rq' . bin2hex(random_bytes(4)) . '-' . $name; file_put_contents($p, $data); $tmp[] = $p; return new CURLFile($p, '', $name); };
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $due = date('Y-m-d', strtotime('+3 days'));
    $r = $cl->req('client-reuirement.php', ['csrf' => $cl->csrf, 'action' => 'submit_requirement', 'tasks[0][type]' => 'poster', 'tasks[0][remark]' => 'Diwali poster with 15% offer', 'tasks[0][due_date]' => $due, 'task_files_0[0]' => $mk('My Logo.png', $png), 'task_files_0[1]' => $mk('offer text.pdf', $pdf)]);
    ok($r['status'] === 303, 'client request sent (' . $r['status'] . ')');
    $req = $pdo->query('SELECT * FROM client_requirements ORDER BY id DESC LIMIT 1')->fetch();
    ok((int) val('SELECT COUNT(*) FROM requirement_files WHERE requirement_id=?', [$req['id']]) === 2, 'request has 2 files');

    echo "MD sends it to an employee\n";
    $admin = new B($base);
    $admin->login('md', 'Secret123!');
    $page = $admin->req('client-reuirement.php')['body'];
    ok(str_contains($page, 'Send this request to an employee') && str_contains($page, 'name="action" value="assign_requirement"'), 'request card has Send to employee box');
    ok(str_contains($page, '>Ravi Kumar') && str_contains($page, 'task-1-file-1 … task-1-file-2'), 'employee list + file note shown');
    $send = fn(int $emp, string $date = '') => $admin->post('client-reuirement.php', ['action' => 'assign_requirement', 'requirement_id' => $req['id'], 'employee_id' => $emp, 'work_date' => $date ?: $due, 'priority' => 'high', 'note' => 'Use gold colours']);
    $r = $send($ravi, date('Y-m-d', strtotime('-1 day')));
    ok($r['status'] === 200 && str_contains($r['body'], 'today or later'), 'past work date rejected');
    $r = $send($ravi);
    ok($r['status'] === 303, 'sent to Ravi (' . $r['status'] . ')');
    $a = $pdo->query("SELECT * FROM work_assignments WHERE requirement_id=" . (int) $req['id'])->fetch();
    ok($a && (int) $a['employee_id'] === $ravi && (int) $a['client_id'] === $clientId && $a['work_date'] === $due && $a['priority'] === 'high' && $a['editing_type'] === 'poster', 'task created (employee, client, date, priority, type)');
    ok(str_starts_with($a['title'], 'Poster · ') && str_contains($a['description'], 'Diwali poster with 15% offer') && str_contains($a['description'], 'Use gold colours'), 'title + client message + MD note in task');
    $names = $pdo->query('SELECT original_name FROM work_assignment_attachments WHERE assignment_id=' . (int) $a['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    ok($names === ['task-1-file-1.png', 'task-1-file-2.pdf'], 'client files copied as ' . implode(', ', $names));
    ok(val('SELECT status FROM client_requirements WHERE id=?', [$req['id']]) === 'in_progress' && (int) val('SELECT COUNT(*) FROM requirement_assignments WHERE requirement_id=? AND employee_id=?', [$req['id'], $ravi]) === 1, 'request now In progress + linked to Ravi');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='1 task assigned'", [$ravi]) === 1, 'Ravi notified');
    ok($send($ravi)['status'] === 200, 'cannot send the same request to Ravi twice');
    $page = $admin->req('client-reuirement.php')['body'];
    ok(str_contains($page, 'Sent to <b>Ravi Kumar</b>') && str_contains($page, 'Send to another employee') && str_contains($page, '(already sent)'), 'card shows Sent to Ravi + send another');
    ok($send($sita)['status'] === 303 && (int) val('SELECT COUNT(*) FROM work_assignments WHERE requirement_id=?', [$req['id']]) === 2, 'can also send to Sita');

    echo "Employee gets the task\n";
    $emp = new B($base);
    $emp->login('ravi', 'Secret123!');
    $page = $emp->req('employee-assigned-work.php?task=' . (int) $a['id'])['body'];
    ok(str_contains($page, 'task-1-file-1.png') && str_contains($page, 'Download all (2 files)'), 'employee sees files + Download all');
    $zip = $emp->req('download-assignment-files.php?id=' . (int) $a['id']);
    ok($zip['status'] === 200 && str_contains($zip['body'], 'task-1-file-2.pdf') && str_contains($zip['body'], $pdf) && str_contains($zip['body'], $png), 'ZIP has the client files');
    $c = $cl->req('client-reuirement.php')['body'];
    ok(!str_contains($c, 'assign_requirement') && !str_contains($c, 'Ravi Kumar'), 'client does not see the assign box or employee names');
    ok($cl->post('client-reuirement.php', ['action' => 'assign_requirement', 'requirement_id' => $req['id'], 'employee_id' => $ravi, 'work_date' => $due])['status'] === 403, 'client cannot assign');

    echo "Download client files\n";
    $f1 = $pdo->query('SELECT * FROM requirement_files WHERE requirement_id=' . (int) $req['id'] . ' ORDER BY id')->fetchAll();
    $d = $admin->req('download-requirement-file.php?id=' . $f1[0]['id']);
    ok($d['status'] === 200 && $d['body'] === $png && str_contains($d['headers']['content-disposition'] ?? '', 'filename="My Logo.png"') && str_contains($d['headers']['content-disposition'] ?? '', 'My%20Logo.png'), 'MD downloads the client file with the same name');
    ok(($d['headers']['content-type'] ?? '') === 'image/png', 'same file type as the client sent (image/png)');
    $page = $admin->req('client-reuirement.php')['body'];
    ok(str_contains($page, 'download-requirement-files.php?id=' . $req['id']) && str_contains($page, 'Download all (2 files)'), 'Download all button under the client files');
    $zip = $admin->req('download-requirement-files.php?id=' . $req['id']);
    ok($zip['status'] === 200 && str_starts_with($zip['body'], "PK\x03\x04") && str_contains($zip['body'], 'My Logo.png') && str_contains($zip['body'], 'offer text.pdf') && str_contains($zip['body'], $pdf) && str_contains($zip['body'], $png), 'ZIP has both files with the client\'s names');
    ok($cl->req('download-requirement-files.php?id=' . $req['id'])['status'] === 200, 'client can download all own files');
    ok($emp->req('download-requirement-file.php?id=' . $f1[1]['id'])['body'] === $pdf, 'assigned employee downloads it too');
    ok($cl->req('download-requirement-file.php?id=' . $f1[1]['id'])['body'] === $pdf, 'client downloads own file');
    // Saved path points to an old folder (server move) but the file is still in storage/requirements.
    $pdo->prepare('UPDATE requirement_files SET file_path=? WHERE id=?')->execute(['old-site/storage/requirements/' . basename($f1[0]['file_path']), $f1[0]['id']]);
    ok($admin->req('download-requirement-file.php?id=' . $f1[0]['id'])['body'] === $png, 'file found even when the saved folder is wrong');
    // File deleted from the server folder: it comes back from the database backup copy.
    ok((int) val('SELECT COUNT(*) FROM requirement_file_chunks WHERE file_id=?', [$f1[1]['id']]) >= 1, 'database backup copy saved at upload');
    ok(is_file($reqDir . '/' . basename($f1[1]['file_path'])) && !is_file(dirname(__DIR__) . '/' . $f1[1]['file_path']), 'client file saved outside the website folder');
    $disk = $reqDir . '/' . basename($f1[1]['file_path']);
    unlink($disk);
    $d = $admin->req('download-requirement-file.php?id=' . $f1[1]['id']);
    ok($d['status'] === 200 && $d['body'] === $pdf && is_file($disk), 'deleted file still downloads (restored from the backup)');
    $page = $admin->req('client-reuirement.php')['body'];
    unlink($disk);
    ok(str_contains($admin->req('client-reuirement.php')['body'], 'Download all (2 files)') && is_file($disk), 'Download all still shown: files come back from the backup');
    ok($admin->req('download-requirement-files.php?id=' . $req['id'])['status'] === 200, 'Download all works after a file was deleted');
    // No backup and no file: clear message instead of an error page.
    $pdo->prepare('DELETE FROM requirement_file_chunks WHERE file_id=?')->execute([$f1[1]['id']]);
    $moved = $disk . '.bak'; rename($disk, $moved);
    $d = $admin->req('download-requirement-file.php?id=' . $f1[1]['id']);
    ok($d['status'] === 303 && !str_contains($d['headers']['location'] ?? '', '#'), 'really missing: goes back to the top of the page');
    $page = $admin->req('client-reuirement.php')['body'];
    ok(str_contains($page, 'is not on the server any more') && str_contains($page, 'Details: this server') && str_contains($page, 'uploaded on 127.0.0.1') && str_contains($page, '<h4>Attached files</h4><ul class="requirement-file-list">'), 'clear message with the reason for the MD; old list look');
    ok(!str_contains($page, 'download-requirement-files.php?id=' . $req['id']), 'Download all hidden when fewer than 2 files are on the server');
    rename($moved, $disk);
    echo "\nAll $checks checks passed.\n";
} finally {
    if ($process) { proc_terminate($process); proc_close($process); }
    try { foreach ($pdo->query("SELECT file_path FROM `$database`.work_assignment_attachments")->fetchAll(PDO::FETCH_COLUMN) as $fp) @unlink(dirname(__DIR__) . '/includes/' . $fp); } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT file_path FROM `$database`.requirement_files")->fetchAll(PDO::FETCH_COLUMN) as $fp) @unlink(dirname(__DIR__) . '/' . $fp); } catch (Throwable $e) {}
    if (!empty($reqDir)) { foreach (glob($reqDir . '/*') ?: [] as $x) @unlink($x); @rmdir($reqDir); }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($tmp as $f) @unlink($f);
}
