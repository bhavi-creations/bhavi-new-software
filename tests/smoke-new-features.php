<?php
declare(strict_types=1);
// Smoke test for Tools/Apps, Vendors, Wishes popup, split timings, MD label, client amount hiding.
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

    $sock = stream_socket_server('tcp://127.0.0.1:0'); $addr = stream_socket_get_name($sock, false); fclose($sock);
    $env = getenv(); foreach ($config as $k => $v) $env['BHAVI_' . strtoupper($k)] = (string) $v; $env['BHAVI_APP_ENV'] = 'local'; $env['BHAVI_DB_NAME'] = $database;
    $log = tempnam(sys_get_temp_dir(), 'bl'); $tmp[] = $log;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;

    echo "Managing Director / Team workspace\n";
    $admin = new B($base);
    $dash = $admin->login('md', 'Secret123!');
    ok(str_contains($dash['body'], 'Managing Director'), 'admin badge says Managing Director');
    ok(stripos($dash['body'], 'team workspace') === false, 'no "Team workspace" on admin page');
    foreach (['tools.php' => 'Tools / Apps', 'vendors.php' => 'Vendors', 'wishes.php' => 'Wishes'] as $href => $label) ok(str_contains($dash['body'], 'href="' . $href . '"'), "sidebar has $label");

    echo "Tools / Apps\n";
    $admin->req('tools.php');
    $r = $admin->post('tools.php', ['action' => 'save_tool', 'id' => 0, 'tool_name' => 'Claude AI', 'tool_url' => 'claude.ai', 'login_email' => 'office@gmail.com', 'username' => 'bhavi', 'password' => 'p@ss-123', 'plan_name' => 'Pro', 'renewal_date' => '2026-12-01', 'notes' => 'shared']);
    ok($r['status'] === 303, 'tool saved');
    $row = $pdo->query("SELECT * FROM premium_tools WHERE tool_name='Claude AI'")->fetch();
    ok($row && $row['login_email'] === 'office@gmail.com' && $row['tool_url'] === 'https://claude.ai' && $row['password_cipher'] && !str_contains($row['password_cipher'], 'p@ss'), 'tool stored in DB with encrypted password');
    $page = $admin->req('tools.php')['body'];
    ok(str_contains($page, 'data-secret="p@ss-123"') && str_contains($page, 'tl-item'), 'saved tool listed with reveal/copy');
    $admin->post('tools.php', ['action' => 'save_tool', 'id' => $row['id'], 'tool_name' => 'Claude AI', 'login_email' => 'office@gmail.com', 'username' => 'bhavi2', 'password' => '']);
    ok(val('SELECT username FROM premium_tools WHERE id=?', [$row['id']]) === 'bhavi2' && val('SELECT password_cipher FROM premium_tools WHERE id=?', [$row['id']]) === $row['password_cipher'], 'edit keeps password when left empty');
    $bad = $admin->post('tools.php', ['action' => 'save_tool', 'id' => 0, 'tool_name' => 'X', 'login_email' => 'not-an-email']);
    ok($bad['status'] === 200 && str_contains($bad['body'], 'valid email'), 'invalid email rejected');

    echo "Vendors\n";
    $admin->req('vendors.php?add=1');
    $r = $admin->post('vendors.php?add=1', ['action' => 'save_vendor', 'vendor_name' => 'Sai Printers', 'service_type' => 'Printing', 'vendor_status' => 'active', 'phone' => '9999', 'email' => 'sai@x.com', 'payment_total' => '5000', 'login_password' => 'vend-pw', 'payments' => [['date' => '2026-11-01', 'amount' => '1500', 'note' => 'adv']]]);
    ok($r['status'] === 303, 'vendor saved');
    $v = $pdo->query("SELECT * FROM vendors WHERE vendor_name='Sai Printers'")->fetch();
    ok($v && (float) $v['paid_amount'] === 1500.0 && (float) $v['payment_total'] === 5000.0 && $v['login_password_cipher'], 'vendor + payment in DB');
    $list = $admin->req('vendors.php')['body'];
    ok(str_contains($list, 'Sai Printers') && str_contains($list, '₹3,500'), 'vendor list shows balance');
    ok($admin->req('vendors.php?id=' . $v['id'])['status'] === 200, 'vendor edit page renders');

    echo "Office timings (12-hour + split)\n";
    $ot = $admin->req('office-timings.php')['body'];
    ok(str_contains($ot, 'name="office_start[ap]"') && !str_contains($ot, 'type="time"'), 'office timings use 12-hour picker');
    $admin->post('office-timings.php', ['action' => 'save_default', 'office_start' => ['h' => '9', 'm' => '30', 'ap' => 'AM'], 'office_end' => ['h' => '6', 'm' => '30', 'ap' => 'PM']]);
    ok(val("SELECT setting_value FROM portal_settings WHERE setting_key='office_start'") === '09:30' && val("SELECT setting_value FROM portal_settings WHERE setting_key='office_end'") === '18:30', 'default timing saved from 12-hour input');
    $admin->post('office-timings.php', ['action' => 'save_shift', 'employee_id' => $ravi, 'slots' => [['start' => ['h' => '7', 'm' => '00', 'ap' => 'PM'], 'end' => ['h' => '10', 'm' => '00', 'ap' => 'PM']], ['start' => ['h' => '5', 'm' => '00', 'ap' => 'AM'], 'end' => ['h' => '8', 'm' => '00', 'ap' => 'AM']], ['start' => ['h' => '', 'm' => '', 'ap' => 'AM'], 'end' => ['h' => '', 'm' => '', 'ap' => 'AM']]], 'shift_hours' => '', 'shift_label' => '']);
    $p = $pdo->query("SELECT shift_start,shift_end,shift_slots,shift_hours,shift_label FROM employee_profiles WHERE user_id=$ravi")->fetch();
    ok($p['shift_slots'] === '[["19:00","22:00"],["05:00","08:00"]]' && $p['shift_label'] === 'Split timing', 'split timing saved');
    $ot = $admin->req('office-timings.php')['body'];
    ok(str_contains($ot, '7:00 PM – 10:00 PM + 5:00 AM – 8:00 AM · 6 hrs a day'), 'split timing shown with hours');
    $admin->post('office-timings.php', ['action' => 'save_shift', 'employee_id' => $sita, 'slots' => [['start' => ['h' => '9', 'm' => '00', 'ap' => 'PM'], 'end' => ['h' => '6', 'm' => '00', 'ap' => 'AM']]], 'shift_hours' => '7.5', 'shift_label' => 'Night']);
    ok(val("SELECT shift_hours FROM employee_profiles WHERE user_id=$sita") === '7.50' && val("SELECT shift_slots FROM employee_profiles WHERE user_id=$sita") === null, 'single shift with own hours saved');
    $ef = $admin->req('admin-add-employee.php?id=' . $ravi)['body'];
    ok(str_contains($ef, 'value="split" selected') && str_contains($ef, 'name="shift_start[ap]"'), 'employee form keeps split timing + 12-hour picker');
    foreach (['employee-attendance.php', 'attendance-approvals.php', 'employee-salary.php?employee=' . $ravi, 'employee-attendance.php?employee=' . $ravi, 'admin-desk.php', 'admin-dashboard.php'] as $pg) ok($admin->req($pg)['status'] === 200, "$pg renders");

    echo "Wishes\n";
    $admin->req('wishes.php');
    $r = $admin->post('wishes.php', ['action' => 'send_wish', 'audience' => 'employee', 'recipients' => ['employee' => [$ravi]], 'title' => 'Happy Children’s Day', 'message' => 'Have a great day!', 'tone' => 'wish']);
    ok($r['status'] === 303, 'wish sent to one employee');
    $png = tempnam(sys_get_temp_dir(), 'bp'); $tmp[] = $png;
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $r = $admin->post('wishes.php', ['action' => 'send_wish', 'audience' => 'client', 'send_all' => '1', 'title' => 'Payment reminder', 'message' => 'Please clear dues', 'tone' => 'danger', 'poster' => new CURLFile($png, 'image/png', 'p.png')]);
    ok($r['status'] === 303, 'warning with poster sent to all clients');
    $none = $admin->post('wishes.php', ['action' => 'send_wish', 'audience' => 'employee', 'message' => 'x', 'tone' => 'wish']);
    ok(str_contains($none['body'], 'Select at least one employee'), 'no recipient rejected');
    $wishId = (int) val("SELECT id FROM wishes WHERE audience='employee'");
    ok((int) val('SELECT COUNT(*) FROM wish_recipients WHERE wish_id=?', [$wishId]) === 1, 'only selected employee is a recipient');
    $hist = $admin->req('wishes.php')['body'];
    ok(str_contains($hist, 'ws-item') && str_contains($hist, 'Seen by 0 / 1'), 'history lists sent messages');

    $emp = new B($base);
    $d = $emp->login('ravi', 'Secret123!');
    ok(str_contains($d['body'], 'data-wish-dock') && str_contains($d['body'], 'Happy Children’s Day') && str_contains($d['body'], 'data-wish-close'), 'employee dashboard shows wish popup with ✕');
    ok(stripos($d['body'], 'team workspace') === false, 'no "Team workspace" for employee');
    ok(str_contains($d['body'], '7:00 PM – 10:00 PM + 5:00 AM – 8:00 AM'), 'employee sees split timing');
    ok((int) val('SELECT show_count FROM wish_recipients WHERE wish_id=? AND user_id=?', [$wishId, $ravi]) === 1, 'first visit counted');
    $d = $emp->req('employee-profile.php');
    ok(str_contains($d['body'], 'data-wish-dock') && (int) val('SELECT show_count FROM wish_recipients WHERE wish_id=?', [$wishId]) === 1, 'still shows on other pages in same visit without extra count');
    $emp->post('wish-seen.php', ['id' => $wishId]);
    ok(!str_contains($emp->req('employee-profile.php')['body'], 'data-wish-dock'), '✕ closes it for this visit');
    for ($i = 2; $i <= 5; $i++) { $emp->logout(); $d = $emp->login('ravi', 'Secret123!'); ok(str_contains($d['body'], 'data-wish-dock'), "shown again on log-in $i"); }
    $emp->logout(); $d = $emp->login('ravi', 'Secret123!');
    ok(!str_contains($d['body'], 'data-wish-dock') && (int) val('SELECT show_count FROM wish_recipients WHERE wish_id=?', [$wishId]) === 5, 'not shown after 5 log-ins');
    $sitaB = new B($base); ok(!str_contains($sitaB->login('sita', 'Secret123!')['body'], 'data-wish-dock'), 'other employee does not get it');

    echo "Client\n";
    $cl = new B($base);
    $cd = $cl->login('kiran', 'Secret123!');
    ok(str_contains($cd['body'], 'wish-card is-danger') && str_contains($cd['body'], 'wish-poster.php?id='), 'client gets red warning popup with poster');
    preg_match('/wish-poster\.php\?id=(\d+)/', $cd['body'], $m);
    $img = $cl->req('wish-poster.php?id=' . $m[1]);
    ok($img['status'] === 200 && str_starts_with($img['headers']['content-type'] ?? '', 'image/png'), 'poster image served to recipient');
    ok($sitaB->req('wish-poster.php?id=' . $m[1])['status'] === 403, 'poster blocked for non-recipient');
    ok(!str_contains($cd['body'], '200000') && !str_contains($cd['body'], '2,00,000'), 'client dashboard + sidebar hide the amount');
    ok(!str_contains($cl->req('client-details.php')['body'], '200000'), 'project details hide the amount');
    ok(str_contains($cl->req('client-payments.php')['body'], '2,00,000'), 'payments page still shows the amount');
    ok(stripos($cd['body'], 'team workspace') === false, 'no "Team workspace" for client');
    echo "Task attachment streaming\n";
    $dir = dirname(__DIR__) . '/includes/uploads/work-assignments/smoketest';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $video = str_repeat('0123456789', 300000); // 3 MB fake video
    file_put_contents($dir . '/clip.mp4', $video);
    $clientId = (int) val('SELECT id FROM clients LIMIT 1');
    $pdo->prepare("INSERT INTO work_assignments (employee_id,department_id,client_id,title,work_date,assigned_by) VALUES (?,?,?,'Edit reel',CURDATE(),?)")->execute([$ravi, $dept, $clientId, $md]);
    $taskId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO work_assignment_attachments (assignment_id,original_name,stored_name,file_path,mime_type,file_size,uploaded_by) VALUES (?,'clip.mp4','clip.mp4','uploads/work-assignments/smoketest/clip.mp4','video/mp4',?,?)")->execute([$taskId, strlen($video), $md]);
    $fid = (int) $pdo->lastInsertId();
    $full = $admin->req('assignment-file.php?id=' . $fid);
    ok($full['status'] === 200 && strlen($full['body']) === strlen($video) && str_starts_with($full['headers']['content-type'] ?? '', 'video/mp4'), 'staff opens task video');
    $h = curl_init($base . '/assignment-file.php?id=' . $fid);
    $empJar = (new ReflectionProperty($emp, 'jar'))->getValue($emp);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $empJar, CURLOPT_HTTPHEADER => ['Range: bytes=100-199'], CURLOPT_HEADER => true]);
    $resp = (string) curl_exec($h); $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE); $body = substr($resp, curl_getinfo($h, CURLINFO_HEADER_SIZE));
    ok($code === 206 && $body === substr($video, 100, 100) && str_contains($resp, 'Content-Range: bytes 100-199/' . strlen($video)), 'employee gets byte ranges (video seeks without buffering)');
    ok($sitaB->req('assignment-file.php?id=' . $fid)['status'] === 404, 'other employee cannot open it');
    $form = $admin->req('manager-assign-work.php?id=' . $taskId)['body'];
    ok(!str_contains($form, 'href="uploads/work-assignments') && str_contains($form, 'data-upload-progress'), 'assign form links via streaming + has upload progress');
    @unlink($dir . '/clip.mp4'); @rmdir($dir);

    echo "\nAll $checks checks passed.\n";
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    if (isset($log) && is_file($log)) echo "--- server log ---\n" . substr((string) file_get_contents($log), -3000) . "\n";
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (preg_match('/^bhavi_portal_test_[a-f0-9]{12}$/D', $database)) $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach (array_merge(glob(dirname(__DIR__) . '/uploads/wishes/*') ?: [], glob(dirname(__DIR__) . '/uploads/photos/wish-*') ?: []) ?: [] as $f) if (filemtime($f) >= time() - 600 && filesize($f) < 200) @unlink($f);
    foreach ($tmp as $t) @unlink($t);
}
exit(!empty($failed) ? 1 : 0);
