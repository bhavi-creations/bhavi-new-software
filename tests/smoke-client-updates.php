<?php
declare(strict_types=1);
// Smoke test: monthly client meeting (countdown, client request, MD change for 1 / N months / permanent),
// shoots only, quotations → invoice, GST per service, bank account per invoice, chat with clients and
// mixed groups, and the one-file-per-box upload on Request work.
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
        if ($status >= 500 || str_contains((string) $body, 'Fatal error') || str_contains((string) $body, 'Warning:') || str_contains((string) $body, 'Deprecated:') || str_contains((string) $body, 'Notice:')) throw new RuntimeException("$path returned $status: " . substr(strip_tags((string) $body), 0, 900));
        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }
    public function post(string $path, array $data): array { return $this->req($path, array_merge(['csrf' => $this->csrf], $data)); }
    public function login(string $u, string $p): array { $this->req('login.php'); $r = $this->post('authenticate.php', ['username' => $u, 'password' => $p]); ok($r['status'] === 303, "login $u"); return $this->req($r['headers']['location']); }
}
try {
    portal_install($pdo, $database);
    ok((int) val('SELECT MAX(version) FROM portal_schema_versions') === PORTAL_SCHEMA_VERSION, 'schema installed at v' . PORTAL_SCHEMA_VERSION);
    $hash = password_hash('Secret123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (full_name,username,password_hash,role,must_change_password) VALUES ('Boss MD','md',?,'admin',0),('Ravi Kumar','ravi',?,'employee',0),('Kiran Foods','kiran',?,'client',0)")->execute([$hash, $hash, $hash]);
    $md = (int) val("SELECT id FROM users WHERE username='md'"); $ravi = (int) val("SELECT id FROM users WHERE username='ravi'"); $kiran = (int) val("SELECT id FROM users WHERE username='kiran'");
    $dept = (int) val('SELECT id FROM departments ORDER BY id LIMIT 1');
    $pdo->prepare("INSERT INTO employee_profiles (user_id,department_id,designation) VALUES (?,?,'Designer')")->execute([$ravi, $dept]);
    $pdo->prepare("INSERT INTO clients (client_name,website_url,logo_path,is_active,created_by,user_id,package,payment_total,client_type,whatsapp,contact_person) VALUES ('Kiran Foods','','',1,?,?,'200000',200000,'premium','9876543210','Kiran')")->execute([$md, $kiran]);
    $kc = (int) val("SELECT id FROM clients WHERE client_name='Kiran Foods'");
    $pdo->prepare("INSERT INTO users (full_name,username,password_hash,role,must_change_password) VALUES ('Mona Manager','mona',?,'manager',0)")->execute([$hash]);
    $sock = stream_socket_server('tcp://127.0.0.1:0'); $addr = stream_socket_get_name($sock, false); fclose($sock);
    $env = getenv(); foreach ($config as $k => $v) $env['BHAVI_' . strtoupper($k)] = (string) $v; $env['BHAVI_APP_ENV'] = 'local'; $env['BHAVI_DB_NAME'] = $database;
    $log = tempnam(sys_get_temp_dir(), 'bl'); $tmp[] = $log;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', $addr, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 50 && !@stream_socket_client('tcp://' . $addr, $e1, $e2, 0.1); $i++) usleep(100000);
    $base = 'http://' . $addr;

    echo "Monthly meeting on the client form\n";
    $admin = new B($base); $admin->login('md', 'Secret123!');
    $form = $admin->req('admin-add-client.php?id=' . $kc);
    ok(str_contains($form['body'], 'name="meeting_date"') && str_contains($form['body'], 'Monthly meeting'), 'client form has the monthly meeting section');
    $target = strtotime('+3 days'); $day = (int) date('j', $target);
    $fields = ['client_profile' => '1', 'client_name' => 'Kiran Foods', 'website_url' => '', 'industry' => 'Food & restaurants', 'contact_person' => 'Kiran', 'phone' => '9876543210', 'whatsapp' => '9876543210', 'city' => 'Kakinada', 'services' => ['social_media'], 'starting_date' => date('Y-m-d'), 'payment_total' => '200000', 'billing_cycle' => 'monthly', 'client_status' => 'active', 'monthly_reels' => '4', 'monthly_posters' => '8', 'monthly_carousels' => '0', 'monthly_blogs' => '0', 'login_username' => 'kiran', 'meeting_date' => date('Y-m-d', $target), 'meeting_time' => '11:30', 'meeting_mode' => 'google_meet', 'meeting_place' => 'https://meet.google.com/abc-defg-hij'];
    $r = $admin->post('admin-add-client.php?id=' . $kc, $fields);
    if ($r['status'] !== 303 && preg_match('/class="alert error"[^>]*>(.*?)</s', $r['body'], $em)) echo '    error: ' . strip_tags($em[1]) . "\n";
    ok($r['status'] === 303 && (int) val('SELECT meeting_day FROM clients WHERE id=?', [$kc]) === $day && val('SELECT meeting_time FROM clients WHERE id=?', [$kc]) === '11:30:00', 'meeting day and time saved');
    $r = $admin->post('admin-add-client.php?id=' . $kc, ['meeting_date' => '2026-02-40', 'meeting_time' => '11:30'] + $fields);
    ok(str_contains($r['body'], 'valid monthly meeting date'), 'invalid meeting date rejected');
    $prof = $admin->req('client-profile.php?id=' . $kc);
    ok(str_contains($prof['body'], '3 days to go') && str_contains($prof['body'], 'Monthly meeting') && str_contains($prof['body'], date('d-m-Y', $target)), 'client details show next meeting and countdown');

    echo "Client sees the countdown and asks to move the meeting\n";
    $client = new B($base); $dash = $client->login('kiran', 'Secret123!');
    ok(str_contains($dash['body'], 'Your next monthly meeting') && str_contains($dash['body'], date('d-m-Y', $target)) && str_contains($dash['body'], 'days to go'), 'client dashboard shows countdown');
    ok(str_contains($dash['body'], 'Join Google Meet'), 'Google Meet link on dashboard');
    ok(str_contains($dash['body'], 'Change meeting date') && str_contains($dash['body'], 'name="back" value="client-dashboard.php"') && str_contains($dash['body'], 'Send to Managing Director'), 'dashboard card has Change meeting date form');
    $cardDate = date('Y-m-d', strtotime('+6 days'));
    $r = $client->post('client-monthly-meeting.php', ['action' => 'request_change', 'back' => 'client-dashboard.php', 'new_date' => $cardDate, 'new_time' => '15:00', 'scope' => 'once', 'reason' => 'Card request']);
    ok($r['status'] === 303 && str_starts_with($r['headers']['location'] ?? '', 'client-dashboard.php') && (int) val("SELECT COUNT(*) FROM client_meeting_changes WHERE reason='Card request' AND status='pending'") === 1, 'request from the card is saved and goes back to the dashboard');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title LIKE 'Monthly meeting change requested%' AND message LIKE '%Card request%'", [$md]) === 1, 'Managing Director is notified');
    $mdDash = $admin->req('admin-dashboard.php');
    ok(str_contains($mdDash['body'], 'Change requests from clients') && str_contains($mdDash['body'], 'Card request'), 'request shows on the MD dashboard');
    $dash = $client->req('client-dashboard.php');
    ok(str_contains($dash['body'], 'is waiting for the Managing Director') && str_contains($dash['body'], 'Change my request'), 'client sees it is waiting');
    $r = $client->post('client-monthly-meeting.php', ['action' => 'request_change', 'back' => 'client-dashboard.php', 'new_date' => $cardDate, 'new_time' => '15:00', 'scope' => 'once', 'reason' => '']);
    ok($r['status'] === 303 && str_contains($r['headers']['location'] ?? '', 'client-dashboard.php#mm-change-open'), 'error goes back to the card');
    $pdo->exec("DELETE FROM client_meeting_changes WHERE reason='Card request'"); $pdo->exec("DELETE FROM notifications WHERE message LIKE '%Card request%'");
    $pd = $client->req('client-details.php');
    ok(str_contains($pd['body'], 'Every month on the'), 'project details show the monthly rule');
    $mp = $client->req('client-monthly-meeting.php');
    ok(str_contains($mp['body'], 'Request a change') && str_contains($mp['body'], 'For some months'), 'client change form');
    $newDate = date('Y-m-d', strtotime('+5 days'));
    $r = $client->post('client-monthly-meeting.php', ['action' => 'request_change', 'new_date' => $newDate, 'new_time' => '16:00', 'scope' => 'once', 'reason' => '']);
    ok(str_contains($r['body'], 'is required') || str_contains($r['body'], 'Reason') , 'reason required');
    $r = $client->post('client-monthly-meeting.php', ['action' => 'request_change', 'new_date' => $newDate, 'new_time' => '16:00', 'scope' => 'once', 'reason' => 'Emergency work that day']);
    ok($r['status'] === 303 && (int) val("SELECT COUNT(*) FROM client_meeting_changes WHERE status='pending'") === 1, 'request saved as pending');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title LIKE 'Monthly meeting change requested%'", [$md]) === 1, 'MD notified');

    echo "MD approves; changes for N months and permanently\n";
    $list = $admin->req('client-monthly-meeting.php');
    ok(str_contains($list['body'], 'Emergency work that day') && str_contains($list['body'], 'Approve'), 'MD sees the request');
    $cid = (int) val("SELECT id FROM client_meeting_changes WHERE status='pending'");
    $r = $admin->post('client-monthly-meeting.php', ['action' => 'decide', 'id' => (string) $cid, 'decision' => 'approve', 'decision_note' => 'OK']);
    ok($r['status'] === 303 && val('SELECT status FROM client_meeting_changes WHERE id=?', [$cid]) === 'approved', 'request approved');
    $cp = $admin->req('client-monthly-meeting.php?client=' . $kc);
    $expectNext = date('Y-m', strtotime($newDate)) === date('Y-m', $target) ? date('d-m-Y', strtotime($newDate)) : date('d-m-Y', $target);
    ok(str_contains($cp['body'], $expectNext) && str_contains($cp['body'], 'Moved'), 'next meeting moved for that month only');
    ok((int) val('SELECT meeting_day FROM clients WHERE id=?', [$kc]) === $day, 'usual day unchanged by a one-month change');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Monthly meeting change approved'", [$kiran]) === 1, 'client told about approval');
    $r = $admin->post('client-monthly-meeting.php', ['action' => 'change', 'client_id' => (string) $kc, 'new_date' => date('Y-m-10', strtotime('first day of next month')), 'new_time' => '10:00', 'scope' => 'months', 'months' => '5', 'reason' => 'MD travelling', 'notify' => '1']);
    ok($r['status'] === 303 && (int) val("SELECT months FROM client_meeting_changes WHERE reason='MD travelling'") === 5, 'MD moves the meeting for 5 months');
    $cp = $admin->req('client-monthly-meeting.php?client=' . $kc);
    ok(str_contains($cp['body'], 'For 5 months'), 'five-month change listed');
    $r = $admin->post('client-monthly-meeting.php', ['action' => 'change', 'client_id' => (string) $kc, 'new_date' => date('Y-m-15', strtotime('first day of next month')), 'new_time' => '12:00', 'scope' => 'permanent', 'reason' => '']);
    ok($r['status'] === 303 && (int) val('SELECT meeting_day FROM clients WHERE id=?', [$kc]) === 15 && val('SELECT meeting_time FROM clients WHERE id=?', [$kc]) === '12:00:00', 'permanent change updates the usual day/time');
    $undo = (int) val("SELECT id FROM client_meeting_changes WHERE reason='MD travelling'");
    $r = $admin->post('client-monthly-meeting.php', ['action' => 'undo', 'id' => (string) $undo]);
    ok($r['status'] === 303 && val('SELECT status FROM client_meeting_changes WHERE id=?', [$undo]) === 'cancelled', 'temporary change can be removed');
    $r = $admin->post('client-monthly-meeting.php', ['action' => 'change', 'client_id' => (string) $kc, 'new_date' => date('Y-m-d', strtotime('-2 days')), 'new_time' => '12:00', 'scope' => 'once']);
    ok(str_contains($r['body'], 'today or a future date'), 'past date rejected');
    // Client form edit: the new date only for 3 months, then the usual 15th at 12:00 comes back.
    $ef = $admin->req('admin-add-client.php?id=' . $kc);
    ok(str_contains($ef['body'], 'name="meeting_months"') && str_contains($ef['body'], 'Usual date'), 'edit form: how many months + upcoming months list');
    $tmpDate = date('Y-m-23', strtotime('first day of next month'));
    $r = $admin->post('admin-add-client.php?id=' . $kc, ['meeting_date' => $tmpDate, 'meeting_time' => '16:00', 'meeting_scope' => 'months', 'meeting_months' => '3'] + $fields);
    $row = $pdo->query("SELECT * FROM client_meeting_changes WHERE reason='Changed on the client form'")->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 303 && $row && (int) $row['months'] === 3 && (int) $row['meeting_day'] === 23 && $row['start_month'] === date('Y-m-01', strtotime($tmpDate)) && $row['status'] === 'approved', 'edit: new date saved for 3 months from its month');
    ok((int) val('SELECT meeting_day FROM clients WHERE id=?', [$kc]) === 15 && val('SELECT meeting_time FROM clients WHERE id=?', [$kc]) === '12:00:00', 'edit: usual date stays for after the 3 months');
    $ef = $admin->req('admin-add-client.php?id=' . $kc);
    ok(str_contains($ef['body'], date('D, d-m-Y', strtotime($tmpDate))) && str_contains($ef['body'], 'For 3 months'), 'edit form lists the changed months');
    $r = $admin->post('admin-add-client.php?id=' . $kc, ['meeting_date' => date('Y-m-d', strtotime('-40 days')), 'meeting_time' => '16:00', 'meeting_scope' => 'months', 'meeting_months' => '2'] + $fields);
    ok(str_contains($r['body'], 'today or a future meeting date'), 'edit: past date for some months rejected');

    echo "Shoots only\n";
    $sf = $admin->req('client-meeting-form.php');
    ok(str_contains($sf['body'], 'New shoot') && !str_contains($sf['body'], 'name="meeting_type"') && !str_contains($sf['body'], 'No shoot'), 'shoot form has no meeting part');
    $r = $admin->post('client-meeting-form.php', ['client_id' => (string) $kc, 'title' => 'Dec shoot', 'shoot_type' => 'our_team', 'shoot_at' => date('Y-m-d\T10:00', strtotime('+4 days')), 'shoot_place' => 'Store', 'notify' => '1']);
    ok($r['status'] === 303 && val("SELECT meeting_type FROM client_engagements WHERE title='Dec shoot'") === 'none', 'shoot saved without meeting');
    $sl = $admin->req('client-meetings.php');
    ok(str_contains($sl['body'], 'Dec shoot') && !str_contains($sl['body'], '<th>Meeting</th>'), 'shoot list has no Meeting column');
    $cs = $client->req('client-meetings.php');
    ok(str_contains($cs['body'], 'Dec shoot') && str_contains($cs['body'], 'Shoots'), 'client sees shoots');

    echo "Dashboard: client meetings & shoots
";
    $reqDate = date('Y-m-d', strtotime('+2 days'));
    $r = $client->post('client-monthly-meeting.php', ['action' => 'request_change', 'new_date' => $reqDate, 'new_time' => '17:00', 'scope' => 'once', 'reason' => 'Out of town that week']);
    ok($r['status'] === 303, 'client asks to move the monthly meeting');
    $d = $admin->req('admin-dashboard.php');
    ok(str_contains($d['body'], 'Client meetings &amp; shoots') && str_contains($d['body'], 'Change requests from clients') && str_contains($d['body'], 'Out of town that week'), 'MD dashboard shows the client change request');
    $d = $admin->req('admin-dashboard.php?mtg_from=' . date('Y-m-01', strtotime($reqDate)) . '&mtg_to=' . date('Y-m-t', strtotime($reqDate)));
    ok(preg_match('#<li class="mtg-item is-meeting is-asked">.*?Kiran Foods.*?class="mtg-change-btn" href="client-monthly-meeting.php\?client=' . $kc . '">✎ Change the date</a>.*?Out of town that week#s', $d['body']) === 1, 'that month\'s meeting shows Client asked to change + Change the date');
    $rid = (int) val("SELECT id FROM client_meeting_changes WHERE reason='Out of town that week'");
    $r = $admin->post('client-monthly-meeting.php', ['action' => 'decide', 'id' => (string) $rid, 'decision' => 'approve', 'decision_note' => '', 'back' => 'dashboard']);
    ok($r['status'] === 303 && str_contains($r['headers']['location'] ?? '', 'admin-dashboard.php') && val('SELECT status FROM client_meeting_changes WHERE id=?', [$rid]) === 'approved', 'approve from the dashboard, back to the dashboard');
    $d = $admin->req('admin-dashboard.php?mtg_from=' . $reqDate . '&mtg_to=' . $reqDate);
    ok(str_contains($d['body'], 'Kiran Foods') && str_contains($d['body'], '5:00 PM') && str_contains($d['body'], 'Moved') && !str_contains($d['body'], 'Out of town that week'), 'date filter: that day shows the moved meeting');
    $shootDay = date('Y-m-d', strtotime('+4 days'));
    $d = $admin->req('admin-dashboard.php?mtg_from=' . date('Y-m-d') . '&mtg_to=' . date('Y-m-d', strtotime('+6 days')));
    ok(str_contains($d['body'], 'Dec shoot') && str_contains($d['body'], date('l, j M Y', strtotime($shootDay))) && str_contains($d['body'], 'is-meeting') && str_contains($d['body'], 'is-shoot'), 'one week: meetings and shoots both listed');
    $d = $admin->req('admin-dashboard.php?mtg_from=' . $shootDay . '&mtg_to=' . $shootDay);
    ok(str_contains($d['body'], 'Dec shoot') && !str_contains($d['body'], '5:00 PM'), 'single day shows only that day');
    $sid = (int) val("SELECT id FROM client_engagements WHERE title='Dec shoot'");
    $client->post('client-meetings.php', ['action' => 'respond', 'id' => (string) $sid, 'response' => 'change_requested', 'client_note' => 'Please make it Sunday']);
    $d = $admin->req('admin-dashboard.php?mtg_from=' . $shootDay . '&mtg_to=' . $shootDay);
    ok(str_contains($d['body'], 'is-shoot is-asked') && str_contains($d['body'], 'href="client-meeting-form.php?id=' . $sid . '">✎ Change the date') && str_contains($d['body'], 'Please make it Sunday'), 'shoot the client wants changed shows Change the date');

    echo "One client a day
";
    $pdo->exec("INSERT INTO clients (client_name,website_url,logo_path,is_active,created_by,package,payment_total,client_type) VALUES ('Ravi Textiles','','',1," . (int) $md . ",'50000',50000,'premium')");
    $rv = (int) val("SELECT id FROM clients WHERE client_name='Ravi Textiles'");
    $check = fn(string $date, string $scope = 'permanent') => json_decode($admin->req('admin-add-client.php?id=' . $rv . '&meeting_check=' . $date . '&scope=' . $scope)['body'], true);
    $c = $check($shootDay, 'months');
    ok($c['ok'] && str_contains(implode(' ', $c['clashes']), 'Kiran Foods — shoot'), 'day with another client\'s shoot is not available');
    $day15 = date('Y-m-15', strtotime('first day of +6 months'));
    $c = $check($day15);
    ok($c['ok'] && str_contains(implode(' ', $c['clashes']), 'Kiran Foods — monthly meeting on the 15th of every month'), 'same day of the month as another client is not available');
    $free = date('Y-m-27', strtotime('first day of +6 months'));
    ok($check($free)['clashes'] === [], 'free day has no clash');
    $rf = ['client_name' => 'Ravi Textiles', 'login_username' => '', 'contact_person' => 'Ravi', 'phone' => '9876500000', 'whatsapp' => '9876500000', 'meeting_mode' => 'live', 'meeting_place' => ''] + $fields;
    $r = $admin->post('admin-add-client.php?id=' . $rv, ['meeting_date' => $day15, 'meeting_time' => '10:00'] + $rf);
    ok($r['status'] === 200 && str_contains($r['body'], 'not available') && val('SELECT meeting_day FROM clients WHERE id=?', [$rv]) === null, 'save refused on a booked day');
    $r = $admin->post('admin-add-client.php?id=' . $rv, ['meeting_date' => $free, 'meeting_time' => '10:00'] + $rf);
    if ($r['status'] !== 303 && preg_match('/class="alert error"[^>]*>(.*?)</s', $r['body'], $em)) echo '    error: ' . strip_tags($em[1]) . "
";
    ok($r['status'] === 303 && (int) val('SELECT meeting_day FROM clients WHERE id=?', [$rv]) === 27, 'free day saved');
    $pdo->exec('DELETE FROM clients WHERE id=' . $rv);

    echo "Bank accounts, GST per service, quotations\n";
    $ba = $admin->req('bank-accounts.php');
    ok(str_contains($ba['body'], 'Add bank account'), 'bank accounts page');
    $r = $admin->post('bank-accounts.php', ['action' => 'save', 'label' => 'SBI current', 'bank_name' => 'SBI Kakinada', 'account_name' => 'Bhavi Creations', 'account_no' => '123456789012', 'ifsc' => 'SBIN0001234', 'upi_id' => 'bhavi@sbi']);
    $sbi = (int) val("SELECT id FROM bank_accounts WHERE label='SBI current'");
    ok($r['status'] === 303 && $sbi > 0, 'second bank account saved');
    $r = $admin->post('bank-accounts.php', ['action' => 'save', 'bank_name' => 'X', 'account_name' => 'Y', 'account_no' => '12345', 'ifsc' => 'bad']);
    ok(str_contains($r['body'], 'valid IFSC'), 'bad IFSC rejected');
    $nf = $admin->req('invoices.php?add=1');
    ok(str_contains($nf['body'], 'Bank account to print') && str_contains($nf['body'], '[gst_rate]') && str_contains($nf['body'], 'SBI current'), 'invoice form: bank account + GST per service');
    $r = $admin->post('invoices.php?add=1', ['action' => 'save_invoice', 'client_id' => (string) $kc, 'invoice_no' => '', 'status' => 'unpaid', 'invoice_date' => date('Y-m-d'), 'due_date' => '', 'gst_type' => 'cgst_sgst', 'bank_account_id' => (string) $sbi, 'advance' => '', 'notes' => '',
        'items' => [['service_name' => 'SEO', 'description' => '', 'quantity' => '1', 'rate' => '10000', 'discount_percent' => '0', 'gst_rate' => '18'], ['service_name' => 'Printing', 'description' => '', 'quantity' => '2', 'rate' => '500', 'discount_percent' => '0', 'gst_rate' => '0']]]);
    $inv = $pdo->query("SELECT * FROM invoices WHERE doc_type='invoice' ORDER BY id DESC LIMIT 1")->fetch();
    ok($r['status'] === 303 && $inv && $inv['subtotal'] === '11000.00' && $inv['tax_amount'] === '1800.00' && $inv['total'] === '12800.00' && (int) $inv['bank_account_id'] === $sbi, 'GST only on the 18% service; bank saved');
    ok(val('SELECT GROUP_CONCAT(gst_rate ORDER BY sort_order) FROM invoice_items WHERE invoice_id=?', [$inv['id']]) === '18.00,0.00', 'each line keeps its GST %');
    ok(str_contains($nf['body'], 'type="number" name="items[0][gst_rate]"') && str_contains($nf['body'], 'list="inv-gst-rates"'), 'GST % can be typed by hand');
    $r = $admin->post('invoices.php?add=1', ['action' => 'save_invoice', 'client_id' => (string) $kc, 'invoice_no' => 'MANUAL-GST-1', 'status' => 'unpaid', 'invoice_date' => date('Y-m-d'), 'gst_type' => 'igst', 'bank_account_id' => (string) $sbi,
        'items' => [['service_name' => 'Custom', 'description' => '', 'quantity' => '1', 'rate' => '1000', 'discount_percent' => '0', 'gst_rate' => '7.5']]]);
    ok($r['status'] === 303 && val("SELECT tax_amount FROM invoices WHERE invoice_no='MANUAL-GST-1'") === '75.00' && val("SELECT i.gst_rate FROM invoice_items i JOIN invoices v ON v.id=i.invoice_id WHERE v.invoice_no='MANUAL-GST-1'") === '7.50', 'manual GST 7.5% saved and calculated');
    $r = $admin->post('invoices.php?add=1', ['action' => 'save_invoice', 'client_id' => (string) $kc, 'invoice_no' => 'MANUAL-GST-2', 'status' => 'unpaid', 'invoice_date' => date('Y-m-d'), 'gst_type' => 'igst',
        'items' => [['service_name' => 'Bad', 'description' => '', 'quantity' => '1', 'rate' => '1000', 'discount_percent' => '0', 'gst_rate' => '150']]]);
    ok(str_contains($r['body'], 'too large'), 'GST above 100% rejected');
    $pr = $admin->req('invoice-print.php?id=' . $inv['id']);
    ok(str_contains($pr['body'], 'SBI Kakinada') && str_contains($pr['body'], '123456789012') && str_contains($pr['body'], '0%') && str_contains($pr['body'], 'CGST') && str_contains($pr['body'], '900.00'), 'invoice prints chosen bank and GST split');
    $r = $admin->post('invoices.php?add=1&type=quotation', ['action' => 'save_invoice', 'client_id' => (string) $kc, 'invoice_no' => '', 'status' => 'pending', 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+15 days')), 'gst_type' => 'igst', 'notes' => 'Valid 15 days', 'notify' => '1',
        'items' => [['service_name' => 'Website', 'description' => '5 pages', 'quantity' => '1', 'rate' => '20000', 'discount_percent' => '10', 'gst_rate' => '18']]]);
    $q = $pdo->query("SELECT * FROM invoices WHERE doc_type='quotation' ORDER BY id DESC LIMIT 1")->fetch();
    ok($r['status'] === 303 && $q && str_contains($q['invoice_no'], '_QT_') && $q['total'] === '21240.00' && $q['bank_account_id'] === null, 'quotation saved with its own number, no bank');
    $ql = $admin->req('invoices.php?type=quotation');
    ok(str_contains($ql['body'], $q['invoice_no']) && str_contains($ql['body'], 'Convert to invoice'), 'quotation list with convert button');
    $il = $admin->req('invoices.php');
    ok(!str_contains($il['body'], $q['invoice_no']), 'quotations are not in the invoice list');
    $qp = $client->req('invoice-print.php?id=' . $q['id']);
    ok(str_contains($qp['body'], 'QUOTATION') && !str_contains($qp['body'], 'Payment details') && !str_contains($qp['body'], '123456789012') && str_contains($qp['body'], 'IGST'), 'quotation prints without bank details');
    $cpay = $client->req('client-payments.php');
    ok(str_contains($cpay['body'], $q['invoice_no']) && str_contains($cpay['body'], 'Accept'), 'client sees quotation with Accept');
    $r = $client->post('quotation-respond.php', ['id' => (string) $q['id'], 'response' => 'accepted']);
    ok($r['status'] === 303 && val('SELECT status FROM invoices WHERE id=?', [$q['id']]) === 'accepted', 'client accepted quotation');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title LIKE 'Quotation % accepted by Kiran Foods'", [$md]) === 1, 'MD notified of acceptance');
    $r = $admin->post('invoices.php?type=quotation', ['action' => 'convert_quotation', 'id' => (string) $q['id']]);
    $newId = (int) val('SELECT converted_invoice_id FROM invoices WHERE id=?', [$q['id']]);
    ok($r['status'] === 303 && $newId > 0 && str_contains($r['headers']['location'], 'invoices.php?id=' . $newId), 'converted to an invoice and opened for editing');
    $ni = $pdo->query('SELECT * FROM invoices WHERE id=' . $newId)->fetch();
    ok($ni['doc_type'] === 'invoice' && $ni['total'] === '21240.00' && !str_contains($ni['invoice_no'], '_QT_') && (int) val('SELECT COUNT(*) FROM invoice_items WHERE invoice_id=?', [$newId]) === 1 && val('SELECT status FROM invoices WHERE id=?', [$q['id']]) === 'converted', 'invoice copies items; quotation marked converted');
    $ef = $admin->req('invoices.php?id=' . $newId);
    ok(str_contains($ef['body'], 'Made from quotation') && str_contains($ef['body'], 'Website'), 'converted invoice is editable');
    $r = $admin->post('invoices.php?type=quotation', ['action' => 'convert_quotation', 'id' => (string) $q['id']]);
    ok((int) val("SELECT COUNT(*) FROM invoices WHERE from_quotation_id=?", [$q['id']]) === 1, 'second convert does not duplicate');

    echo "Chat: MD ↔ employee, MD ↔ client, mixed group\n";
    $chat = $admin->req('chat.php');
    ok(str_contains($chat['body'], 'name="chat_kind" value="client"') && str_contains($chat['body'], 'Employee chat') && str_contains($chat['body'], 'Kiran Foods'), 'MD new-chat dialog has employee / client / group');
    $r = $admin->post('chat.php', ['action' => 'create_conversation', 'chat_kind' => 'client', 'user_ids' => [(string) $kiran]]);
    $clientRoom = (int) val("SELECT c.id FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE c.conversation_type='private'", [$kiran]);
    ok($r['status'] === 303 && $clientRoom > 0, 'MD starts a client chat');
    $admin->req('chat.php?id=' . $clientRoom);
    $admin->post('chat.php?id=' . $clientRoom, ['action' => 'send_message', 'conversation_id' => (string) $clientRoom, 'body' => 'Hello Kiran']);
    $cc = $client->req('chat.php');
    ok(str_contains($cc['body'], 'Chat with Bhavi') && str_contains($cc['body'], 'Hello Kiran'), 'client sees MD message');
    $client->post('chat.php?id=' . $clientRoom, ['action' => 'send_message', 'conversation_id' => (string) $clientRoom, 'body' => 'Thanks sir']);
    ok((int) val('SELECT COUNT(*) FROM chat_messages WHERE conversation_id=?', [$clientRoom]) === 2, 'client replies');

    echo "Chat notifications\n";
    $nId = (int) val('SELECT id FROM notifications WHERE recipient_id=? AND chat_conversation_id=? AND read_at IS NULL', [$md, $clientRoom]);
    ok($nId > 0 && val('SELECT title FROM notifications WHERE id=?', [$nId]) === '💬 Kiran Foods' && val('SELECT message FROM notifications WHERE id=?', [$nId]) === 'Thanks sir', 'MD gets a notification with the sender and the message');
    $client->post('chat.php?id=' . $clientRoom, ['action' => 'send_message', 'conversation_id' => (string) $clientRoom, 'body' => 'One more thing']);
    ok((int) val('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND chat_conversation_id=? AND read_at IS NULL', [$md, $clientRoom]) === 1 && val('SELECT message FROM notifications WHERE id=?', [$nId]) === "Thanks sir
One more thing", 'one notification per chat, showing the latest messages');
    $poll = json_decode($admin->req('notifications-poll.php?after=0')['body'], true);
    ok($poll['ok'] && $poll['chat_unread'] === 2 && $poll['unread'] >= 1 && str_contains(json_encode($poll['items'], JSON_UNESCAPED_UNICODE), '💬 Kiran Foods') && str_contains(json_encode($poll['items'], JSON_UNESCAPED_UNICODE), 'One more thing'), 'live poll gives chat unread count and the popup item');
    $dashMd = $admin->req('admin-dashboard.php');
    ok(str_contains($dashMd['body'], 'data-live-poll="notifications-poll.php"') && preg_match('#href="chat.php"[^>]*>.*?<em class="nav-badge">2</em>#s', $dashMd['body']) === 1, 'sidebar Chat shows unread badge');
    $open = $admin->req('notification-open.php?id=' . $nId);
    ok(str_contains($open['headers']['location'] ?? '', 'chat.php?id=' . $clientRoom), 'notification opens the chat');
    $admin->req('chat.php?id=' . $clientRoom);
    ok(val('SELECT read_at FROM notifications WHERE id=?', [$nId]) !== null && json_decode($admin->req('notifications-poll.php')['body'], true)['chat_unread'] === 0, 'opening the chat clears it');
    $cn = $client->req('manager-notification.php');
    ok($cn['status'] === 200 && str_contains($cn['body'], '💬 Boss MD'), 'client has a Notifications page with chat alerts');
    $r = $client->post('chat.php', ['action' => 'create_conversation', 'chat_kind' => 'group', 'user_ids' => [(string) $md], 'title' => 'x']);
    ok(str_contains($r['body'], 'Only the Managing Director can create groups'), 'client cannot create groups');
    $r = $client->post('chat.php', ['action' => 'create_conversation', 'chat_kind' => 'employee', 'user_ids' => [(string) $ravi]]);
    ok(str_contains($r['body'], 'unavailable'), 'client cannot chat an employee directly');
    $r = $admin->post('chat.php', ['action' => 'create_conversation', 'chat_kind' => 'group', 'title' => 'Kiran project', 'user_ids' => [(string) $ravi, (string) $kiran]]);
    $group = (int) val("SELECT id FROM chat_conversations WHERE title='Kiran project'");
    ok($r['status'] === 303 && (int) val('SELECT COUNT(*) FROM chat_participants WHERE conversation_id=?', [$group]) === 3, 'MD makes a group with an employee and a client');
    $emp = new B($base); $emp->login('ravi', 'Secret123!');
    $ec = $emp->req('chat.php?id=' . $group);
    ok(str_contains($ec['body'], 'Kiran project') && !str_contains($ec['body'], 'name="chat_kind" value="client"'), 'employee sees the group; no client chat option');
    $r = $emp->post('chat.php', ['action' => 'create_conversation', 'chat_kind' => 'employee', 'user_ids' => [(string) $kiran]]);
    ok(str_contains($r['body'], 'unavailable'), 'employee cannot start a private chat with a client');
    $cg = $client->req('chat.php?id=' . $group);
    ok(str_contains($cg['body'], 'Kiran project'), 'client sees the group');

    echo "Request work: one file per box\n";
    $rw = $client->req('client-reuirement.php');
    ok(str_contains($rw['body'], 'data-cr-upload-more') && str_contains($rw['body'], 'Add more') && !str_contains($rw['body'], 'data-cr-drop'), 'upload boxes with Add more');
    $f1 = tempnam(sys_get_temp_dir(), 'r1'); file_put_contents($f1, 'reel one'); $tmp[] = $f1;
    $f2 = tempnam(sys_get_temp_dir(), 'r2'); file_put_contents($f2, 'reel two'); $tmp[] = $f2;
    $r = $client->post('client-reuirement.php', ['action' => 'submit_requirement', 'tasks[0][type]' => 'reel', 'tasks[0][due_date]' => date('Y-m-d', strtotime('+2 days')), 'tasks[0][remark]' => 'Two reels', 'task_files_0[0]' => new CURLFile($f1, 'text/plain', 'reel-1.txt'), 'task_files_0[1]' => new CURLFile($f2, 'text/plain', 'reel-2.txt')]);
    ok($r['status'] === 303 && (int) val('SELECT COUNT(*) FROM requirement_files') === 2, 'two files from two boxes saved');

    echo "Client request: assigned → MD told; finished → MD told; Done → client sees Completed\n";
    $reqId = (int) val("SELECT id FROM client_requirements WHERE brief LIKE '%Two reels%' ORDER BY id DESC LIMIT 1");
    $mgr = new B($base); $mgr->login('mona', 'Secret123!');
    $mgr->req('client-reuirement.php');
    $r = $mgr->post('client-reuirement.php', ['action' => 'assign_requirement', 'requirement_id' => (string) $reqId, 'employee_id' => (string) $ravi, 'work_date' => date('Y-m-d'), 'priority' => 'normal', 'note' => '']);
    $taskId = (int) val('SELECT id FROM work_assignments WHERE requirement_id=?', [$reqId]);
    ok($r['status'] === 303 && $taskId > 0, 'manager assigns the client request to an employee');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Client request assigned · Kiran Foods' AND message LIKE '%Ravi Kumar%by Mona Manager%'", [$md]) === 1, 'MD told who assigned it to whom');
    $rq = $admin->req('client-reuirement.php');
    ok(str_contains($rq['body'], 'complete_requirement') && str_contains($rq['body'], 'When the work is finished'), 'MD sees the Done button');
    $emp->req('employee-assigned-work.php');
    $emp->post('employee-assigned-work.php', ['action' => 'start_assignment', 'assignment_id' => (string) $taskId]);
    $emp->post('employee-assigned-work.php', ['action' => 'end_assignment', 'assignment_id' => (string) $taskId, 'remark' => 'Both reels ready']);
    ok(val('SELECT status FROM work_assignments WHERE id=?', [$taskId]) === 'completed', 'employee finishes the task');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Client work done · Kiran Foods'", [$md]) === 1 && (int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Client work done · Kiran Foods'", [(int) val("SELECT id FROM users WHERE username='mona'")]) === 1, 'MD and the manager who assigned it are told the work is done');
    $rq = $admin->req('client-reuirement.php');
    ok(str_contains($rq['body'], 'Work finished by the team'), 'request shows work finished, ready for Done');
    $r = $admin->post('client-reuirement.php', ['action' => 'complete_requirement', 'requirement_id' => (string) $reqId, 'note' => 'Reels uploaded to Work reports']);
    ok($r['status'] === 303 && val('SELECT status FROM client_requirements WHERE id=?', [$reqId]) === 'completed', 'Done marks the request completed');
    ok((int) val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND title='Your request is completed ✓'", [$kiran]) === 1, 'client notified: completed');
    $crq = $client->req('client-reuirement.php');
    ok(str_contains($crq['body'], 'Completed ✓') && str_contains($crq['body'], 'Reels uploaded to Work reports'), 'client sees Completed with the message');
    $r = $admin->post('client-reuirement.php', ['action' => 'complete_requirement', 'requirement_id' => (string) $reqId]);
    ok(str_contains($r['body'], 'already completed'), 'cannot complete twice');

    echo "Pages load\n";
    foreach (['admin-dashboard.php', 'client-monthly-meeting.php', 'add-client.php', 'invoices.php?add=1&type=quotation'] as $page) { $p = $admin->req($page); ok($p['status'] === 200, "MD $page"); }
    foreach (['client-dashboard.php', 'client-monthly-meeting.php', 'client-details.php', 'client-payments.php'] as $page) { $p = $client->req($page); ok($p['status'] === 200, "client $page"); }
    if ($dump = getenv('CU_DUMP')) { foreach (['dash' => $dash, 'mm' => $cp, 'inv' => $nf, 'chat' => $chat, 'rw' => $rw, 'pr' => $pr, 'qp' => $qp] as $k => $v) file_put_contents($dump . "-$k.html", $v['body']); }

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
