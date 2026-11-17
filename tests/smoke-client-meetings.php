<?php
declare(strict_types=1);
// Smoke test for client shoots (MD plans a shoot, client confirms or asks for a change). Monthly meetings: smoke-client-updates.php.
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
    $pdo->prepare("INSERT INTO clients (client_name,website_url,logo_path,is_active,created_by,user_id,package,payment_total,client_type,whatsapp,contact_person) VALUES ('Kiran Foods','','',1,?,?,'200000',200000,'premium','9876543210','Kiran'),('Other Co','','',1,?,NULL,'0',0,'non-premium','','')")->execute([$md, $kiran, $md]);
    $kc = (int) val("SELECT id FROM clients WHERE client_name='Kiran Foods'"); $oc = (int) val("SELECT id FROM clients WHERE client_name='Other Co'");
    $sock = stream_socket_server('tcp://127.0.0.1:0'); $addr = stream_socket_get_name($sock, false); fclose($sock);
    $env = getenv(); foreach ($config as $k => $v) $env['BHAVI_' . strtoupper($k)] = (string) $v; $env['BHAVI_APP_ENV'] = 'local'; $env['BHAVI_DB_NAME'] = $database;
    $log = tempnam(sys_get_temp_dir(), 'bl'); $tmp[] = $log;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;
    $tomorrow = date('Y-m-d\T11:00', strtotime('+1 day')); $later = date('Y-m-d\T10:00', strtotime('+3 days'));

    echo "Managing Director plans a shoot\n";
    $admin = new B($base); $admin->login('md', 'Secret123!');
    $p = $admin->req('client-meeting-form.php');
    ok(str_contains($p['body'], 'New shoot') && str_contains($p['body'], 'Kiran Foods') && str_contains($p['body'], 'Client will shoot') && !str_contains($p['body'], 'name="meeting_type"'), 'form shows client dropdown and shoot options only');
    $dash = $admin->req('admin-dashboard.php'); ok(str_contains($dash['body'], 'client-meetings.php') && str_contains($dash['body'], 'client-monthly-meeting.php'), 'sidebar links for shoots and monthly meetings');
    $r = $admin->post('client-meeting-form.php', ['client_id' => '', 'shoot_type' => 'our_team', 'shoot_at' => $later]);
    ok(str_contains($r['body'], 'Select a client') || str_contains($r['body'], 'valid'), 'client required');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $kc, 'shoot_type' => 'other', 'shoot_other' => '', 'shoot_at' => $later]);
    ok(str_contains($r['body'], 'you chose “Other”'), 'Other shoot needs typed text');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $kc, 'shoot_type' => 'none']);
    ok(str_contains($r['body'], 'Choose who will shoot'), 'cannot save without a shoot');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $kc, 'shoot_type' => 'our_team', 'shoot_at' => '']);
    ok(str_contains($r['body'], 'Choose the shoot date and time'), 'shoot date required');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $kc, 'title' => 'Dec planning', 'shoot_type' => 'other', 'shoot_other' => 'Freelance photographer', 'shoot_at' => $later, 'shoot_place' => 'Store <b>Ameerpet</b>', 'notes' => 'Keep products ready', 'notify' => '1']);
    ok($r['status'] === 303, 'shoot saved');
    $e = $pdo->query("SELECT * FROM client_engagements WHERE title='Dec planning'")->fetch();
    ok($e['meeting_type'] === 'none' && $e['shoot_other'] === 'Freelance photographer' && $e['client_response'] === 'pending', 'saved as a shoot with Other text');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Shoot planned: Dec planning'", [$kiran]) === 1, 'client notified');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $oc, 'shoot_type' => 'our_team', 'shoot_at' => date('Y-m-d\T15:00', strtotime('+1 day')), 'shoot_place' => 'Our office']);
    ok($r['status'] === 303 && (int) val('SELECT COUNT(*) FROM client_engagements WHERE client_id=?', [$oc]) === 1, 'second client shoot saved at a free time');
    $list = $admin->req('client-meetings.php');
    ok(str_contains($list['body'], 'Store &lt;b&gt;Ameerpet&lt;/b&gt;') && str_contains($list['body'], 'wa.me/919876543210') && str_contains($list['body'], 'calendar.google.com') && !str_contains($list['body'], '<th>Meeting</th>'), 'list escapes HTML, has WhatsApp and calendar links, no meeting column');
    $edit = $admin->req('client-meeting-form.php?id=' . $e['id']);
    ok(str_contains($edit['body'], 'Edit shoot') && str_contains($edit['body'], 'value="Freelance photographer"'), 'edit form prefilled');
    $admin->logout();

    echo "Client view\n";
    $cl = new B($base); $cl->login('kiran', 'Secret123!');
    $cp = $cl->req('client-meetings.php');
    ok(str_contains($cp['body'], 'Dec planning') && !str_contains($cp['body'], 'Our office') && !str_contains($cp['body'], 'New shoot'), 'client sees only own plan, no form');
    $cd = $cl->req('client-dashboard.php'); ok(str_contains($cd['body'], 'client-meetings.php'), 'client dashboard links to it');
    $other = (int) val('SELECT id FROM client_engagements WHERE client_id=?', [$oc]);
    $r = $cl->post('client-meetings.php', ['action' => 'respond', 'id' => (string) $other, 'response' => 'confirmed']);
    ok(str_contains($r['body'], 'no longer open') && val('SELECT client_response FROM client_engagements WHERE id=?', [$other]) === 'pending', 'client cannot touch another client plan');
    $cl->post('client-meetings.php', ['action' => 'delete', 'id' => (string) $e['id']]);
    ok(val('SELECT deleted_at FROM client_engagements WHERE id=?', [$e['id']]) === null, 'client cannot delete');
    $r = $cl->post('client-meetings.php', ['action' => 'respond', 'id' => (string) $e['id'], 'response' => 'change_requested', 'client_note' => '']);
    ok(str_contains($r['body'], 'what you would like to change'), 'change request needs a note');
    $r = $cl->post('client-meetings.php', ['action' => 'respond', 'id' => (string) $e['id'], 'response' => 'change_requested', 'client_note' => 'Saturday please']);
    ok($r['status'] === 303 && val('SELECT client_response FROM client_engagements WHERE id=?', [$e['id']]) === 'change_requested', 'change requested');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Shoot change requested by Kiran Foods'", [$md]) === 1, 'MD notified of change');
    $cl->logout();

    echo "MD reschedules, client confirms\n";
    $admin->login('md', 'Secret123!');
    $ml = $admin->req('client-meetings.php');
    ok(str_contains($ml['body'], '⚠ Change requested') && str_contains($ml['body'], 'Saturday please') && str_contains($ml['body'], 'asked for a change') && str_contains($ml['body'], 'client-meeting-form.php?id=' . $e['id']), 'MD list shows the change request, the reason and an Edit button');
    $nid = (int) val("SELECT id FROM notifications WHERE recipient_id=? AND title='Shoot change requested by Kiran Foods'", [$md]);
    $no = $admin->req('notification-open.php?id=' . $nid); ok(($no['headers']['location'] ?? '') === 'client-meetings.php?tab=change', 'notification opens the change-requested tab');
    $sat = date('Y-m-d\T10:00', strtotime('next saturday'));
    $admin->post('client-meeting-form.php', ['id' => (string) $e['id'], 'client_id' => (string) $kc, 'title' => 'Dec planning', 'shoot_type' => 'our_team', 'shoot_at' => $sat, 'shoot_place' => 'Store', 'notes' => 'Keep products ready', 'notify' => '1', 'allow_overlap' => '1']);
    ok(val('SELECT client_response FROM client_engagements WHERE id=?', [$e['id']]) === 'pending' && val('SELECT shoot_type FROM client_engagements WHERE id=?', [$e['id']]) === 'our_team', 'edit saved and asks client again');
    $admin->logout();
    $cl->login('kiran', 'Secret123!'); $cl->req('client-meetings.php');
    $cl->post('client-meetings.php', ['action' => 'respond', 'id' => (string) $e['id'], 'response' => 'confirmed']);
    ok(val('SELECT client_response FROM client_engagements WHERE id=?', [$e['id']]) === 'confirmed', 'client confirmed');
    $cl->logout();
    $admin->login('md', 'Secret123!');
    $ml = $admin->req('client-meetings.php');
    ok(str_contains($ml['body'], '✓ Confirmed by client') && str_contains($ml['body'], 'Recently confirmed'), 'MD sees the client confirmation');
    $ct = $admin->req('client-meetings.php?tab=confirmed'); ok(str_contains($ct['body'], 'Dec planning') && !str_contains($ct['body'], 'Our office'), 'confirmed tab lists only confirmed plans');
    $admin->post('client-meetings.php', ['action' => 'set_status', 'id' => (string) $e['id'], 'status' => 'completed']);
    ok(val('SELECT status FROM client_engagements WHERE id=?', [$e['id']]) === 'completed', 'marked done');
    $admin->post('client-meetings.php', ['action' => 'delete', 'id' => (string) $other]);
    ok(val('SELECT deleted_at FROM client_engagements WHERE id=?', [$other]) !== null, 'deleted');
    $hp = $admin->req('client-meetings.php?tab=completed'); ok(str_contains($hp['body'], 'Dec planning') && !str_contains($hp['body'], 'Our office'), 'completed tab shows done plan, deleted plan gone');
    $all = $admin->req('client-meetings.php'); ok(str_contains($all['body'], 'Dec planning'), 'All tab still shows completed plans on the same page');
    $admin->logout();
    $emp = new B($base); $emp->login('ravi', 'Secret123!'); $ep = $emp->req('client-meetings.php'); ok($ep['status'] === 403 || !str_contains($ep['body'], 'Dec planning'), 'employees cannot open it');
    $emp->logout();

    echo "Same time for two clients\n";
    // Client A (Kiran Foods): shoot day 2 at 10:00 (3 h). Client B (Other Co) tries that time.
    $d1 = date('Y-m-d', strtotime('+5 days')); $d2 = date('Y-m-d', strtotime('+6 days'));
    $admin->login('md', 'Secret123!'); $admin->req('client-meeting-form.php');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $kc, 'title' => 'Plan A', 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T10:00']);
    ok($r['status'] === 303, 'client A shoot saved');
    $form = $admin->req('client-meeting-form.php');
    ok(str_contains($form['body'], '"day":"' . $d2 . '"') && str_contains($form['body'], 'Another client'), 'form knows the booked slots for the live warning');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $oc, 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T12:00']);
    ok(str_contains($r['body'], 'Another client shoot in this time: Kiran Foods'), 'B shoot at A shoot time is blocked');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $oc, 'shoot_type' => 'client', 'shoot_at' => $d2 . 'T11:00']);
    ok($r['status'] === 303, '"Client will shoot" does not block the team');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $oc, 'title' => 'B later', 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T13:00']);
    ok($r['status'] === 303, 'free right after the 3-hour shoot');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $oc, 'title' => 'B forced', 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T11:00', 'allow_overlap' => '1']);
    ok($r['status'] === 303, '"Book anyway" saves despite the clash');
    $aId = (int) val("SELECT id FROM client_engagements WHERE title='Plan A'");
    $admin->post('client-meetings.php', ['action' => 'delete', 'id' => (string) val("SELECT id FROM client_engagements WHERE title='B forced'")]);
    $r = $admin->post('client-meeting-form.php', ['id' => (string) $aId, 'client_id' => (string) $kc, 'title' => 'Plan A edited', 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T10:00']);
    ok($r['status'] === 303 && val('SELECT title FROM client_engagements WHERE id=?', [$aId]) === 'Plan A edited', 'editing a plan does not clash with itself');
    $form = $admin->req('client-meeting-form.php'); ok(!str_contains($form['body'], 'shoot_minutes') && !str_contains($form['body'], '>Duration<'), 'no duration field on the form');
    $admin->post('client-meetings.php', ['action' => 'set_status', 'id' => (string) $aId, 'status' => 'cancelled']);
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $oc, 'title' => 'B after cancel', 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T10:00', 'shoot_place' => 'Our office, Hyderabad']);
    ok($r['status'] === 303, 'cancelled plan frees its time');
    $bId = (int) val("SELECT id FROM client_engagements WHERE title='B after cancel'");
    $admin->post('client-meeting-form.php', ['id' => (string) $bId, 'client_id' => (string) $oc, 'title' => 'B after cancel', 'shoot_type' => 'our_team', 'shoot_at' => $d2 . 'T10:00', 'shoot_place' => 'Our office, Hyderabad', 'notes' => 'Agenda / what to keep ready: a very long note about products, menu cards, brand colours and the reel script that should wrap neatly instead of pushing the table sideways.']);
    $lp = $admin->req('client-meetings.php');
    ok(str_contains($lp['body'], 'client-meeting-view.php?id=' . $bId) && !str_contains($lp['body'], 'should wrap neatly'), 'list has the eye button; long note is not in the table');
    $vp = $admin->req('client-meeting-view.php?id=' . $bId);
    ok(str_contains($vp['body'], 'Other Co') && str_contains($vp['body'], 'Our office, Hyderabad') && str_contains($vp['body'], 'should wrap neatly') && str_contains($vp['body'], 'Waiting for client') && str_contains($vp['body'], 'client-meeting-form.php?id=' . $bId) && !str_contains($vp['body'], '<h2>Meeting</h2>'), 'details page shows the shoot with Edit, no meeting box');
    ok(str_contains($vp['body'], 'Other clients booked on the same day'), 'details page lists same-day bookings');
    $gone = $admin->req('client-meeting-view.php?id=999999'); ok($gone['status'] === 303, 'missing plan goes back to the list');
    if ($dump = getenv('CM_DUMP')) { file_put_contents($dump . '-list.html', $lp['body']); file_put_contents($dump . '-view.html', $vp['body']); }
    ok(str_contains($lp['body'], '· 10:00 AM') && !str_contains($lp['body'], '10:00 AM – 1:00 PM'), 'list shows start time only');

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
