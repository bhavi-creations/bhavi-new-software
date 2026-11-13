<?php
// My desk (admin only): to-dos, contract renewals, daily WhatsApp summary, profit & loss,
// money to collect from clients and payments to give — all from live portal data.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/office-timing.php';
$user = require_roles(['admin']);
$today = today();
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$error = null;

/** WhatsApp link for a phone number (adds 91 to 10-digit Indian numbers); null when there is no number. */
function desk_wa_link(?string $phone, string $text): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) === 10) $digits = '91' . $digits;
    if (strlen($digits) < 11) return null;
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
}
/** Short rupees for chart labels: 60000 → 60k, 150000 → 1.5L. Input in rupees. */
function desk_short(float $rupees): string
{
    $abs = abs($rupees);
    $text = $abs >= 100000 ? rtrim(rtrim(number_format($abs / 100000, 1), '0'), '.') . 'L' : ($abs >= 1000 ? round($abs / 1000) . 'k' : (string) round($abs));
    return ($rupees < 0 ? '−' : '') . $text;
}
function desk_setting(string $key, string $default): string
{
    return (string) (one('SELECT setting_value FROM portal_settings WHERE setting_key=?', [$key])['setting_value'] ?? $default);
}

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $anchor = '';
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_todo') {
            $anchor = '#todo';
            $title = text_input('title', 500);
            $when = (string) ($_POST['when'] ?? 'today');
            $due = match ($when) {
                'today' => $today,
                'tomorrow' => date('Y-m-d', strtotime('+1 day')),
                'week' => date('Y-m-d', strtotime('sunday this week')) < $today ? $today : date('Y-m-d', strtotime('sunday this week')),
                'none' => null,
                'date' => date_input('due_date'),
                default => throw new InvalidArgumentException('Choose when this is due.'),
            };
            $category = in_array($_POST['category'] ?? '', ['office', 'client', 'team'], true) ? $_POST['category'] : 'office';
            $clientId = ($_POST['client_id'] ?? '') === '' ? null : positive_id($_POST['client_id']);
            if ($clientId) { $category = 'client'; if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL', [$clientId])) throw new InvalidArgumentException('Choose a valid client.'); }
            $time = time12_value($_POST['due_time'] ?? null) === null ? null : office_time_input($_POST['due_time'], 'time');
            query('INSERT INTO todo_tasks (user_id,title,expected_date,category,client_id,due_time,is_urgent) VALUES (?,?,?,?,?,?,?)', [$user['id'], $title, $due, $category, $clientId, $time, isset($_POST['is_urgent']) ? 1 : 0]);
            flash('Added to your to-do list.');
        } elseif ($action === 'toggle_todo') {
            $anchor = '#todo';
            $task = one('SELECT id,status FROM todo_tasks WHERE id=? AND user_id=?', [positive_id($_POST['todo_id'] ?? null), $user['id']]);
            if (!$task) throw new InvalidArgumentException('That to-do no longer exists.');
            if ($task['status'] === 'open') query("UPDATE todo_tasks SET status='completed',completed_at=NOW(),end_date=COALESCE(end_date,CURDATE()) WHERE id=?", [$task['id']]);
            else query("UPDATE todo_tasks SET status='open',completed_at=NULL WHERE id=?", [$task['id']]);
        } elseif ($action === 'delete_todo') {
            $anchor = '#todo';
            query('DELETE FROM todo_tasks WHERE id=? AND user_id=?', [positive_id($_POST['todo_id'] ?? null), $user['id']]);
            flash('To-do removed.');
        } elseif ($action === 'add_payment') {
            $anchor = '#money-out';
            $title = text_input('title', 200);
            $category = in_array($_POST['category'] ?? '', ['vendor', 'freelancer', 'rent', 'utility', 'other'], true) ? $_POST['category'] : 'other';
            $amount = money_cents(is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '');
            if ($amount <= 0 || $amount > 99999999999) throw new InvalidArgumentException('Enter an amount greater than zero.');
            $due = date_input('due_date');
            query('INSERT INTO office_payments (title,category,note,amount,due_date,created_by) VALUES (?,?,?,?,?,?)', [$title, $category, text_input('note', 255, false), decimal_money($amount), $due, $user['id']]);
            flash('Payment added: ' . $title . ' · ' . rupees_label($amount / 100) . '.');
        } elseif ($action === 'pay_payment') {
            $anchor = '#money-out';
            if (($_POST['payment_id'] ?? '') === 'salary') {
                $salaryTotal = (int) round(100 * (float) (one("SELECT COALESCE(SUM(s.net_salary),0) AS t FROM employee_salary_history s JOIN users u ON u.id=s.employee_id AND u.account_status='active' AND u.deleted_at IS NULL WHERE s.effective_from<=? AND (s.effective_to IS NULL OR s.effective_to>=?)", [$today, $today])['t'] ?? 0));
                if ($salaryTotal <= 0) throw new InvalidArgumentException('No salaries are set for this month.');
                if (one("SELECT id FROM office_payments WHERE category='salary' AND due_date=?", [$monthEnd])) throw new InvalidArgumentException('Salaries are already marked paid for this month.');
                query("INSERT INTO office_payments (title,category,note,amount,due_date,paid_at,created_by) VALUES (?,?,?,?,?,NOW(),?)", ['Salaries · ' . date('F Y'), 'salary', 'Monthly salaries', decimal_money($salaryTotal), $monthEnd, $user['id']]);
                flash('Salaries marked paid for ' . date('F') . '.');
            } else {
                $payment = one('SELECT id,title FROM office_payments WHERE id=? AND paid_at IS NULL', [positive_id($_POST['payment_id'] ?? null)]);
                if (!$payment) throw new InvalidArgumentException('This payment was already marked paid.');
                query('UPDATE office_payments SET paid_at=NOW() WHERE id=?', [$payment['id']]);
                flash('Marked paid: ' . $payment['title'] . '.');
            }
        } elseif ($action === 'undo_payment') {
            $anchor = '#money-out';
            $payment = one('SELECT id,category FROM office_payments WHERE id=? AND paid_at IS NOT NULL', [positive_id($_POST['payment_id'] ?? null)]);
            if (!$payment) throw new InvalidArgumentException('This payment is not marked paid.');
            if ($payment['category'] === 'salary') query('DELETE FROM office_payments WHERE id=?', [$payment['id']]);
            else query('UPDATE office_payments SET paid_at=NULL WHERE id=?', [$payment['id']]);
            flash('Payment moved back to "to give".');
        } elseif ($action === 'delete_payment') {
            $anchor = '#money-out';
            query('DELETE FROM office_payments WHERE id=? AND paid_at IS NULL', [positive_id($_POST['payment_id'] ?? null)]);
            flash('Payment removed.');
        } elseif ($action === 'save_summary') {
            $anchor = '#summary';
            $time = office_time_input($_POST['wa_time'] ?? '', 'summary time');
            $items = array_values(array_intersect(['work', 'late', 'overdue', 'money'], (array) ($_POST['wa_items'] ?? [])));
            foreach (['wa_summary_enabled' => isset($_POST['wa_enabled']) ? '1' : '0', 'wa_summary_time' => $time, 'wa_summary_items' => implode(',', $items)] as $key => $value) {
                query('INSERT INTO portal_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', [$key, $value, $user['id']]);
            }
            flash('Daily summary settings saved.');
        } else {
            throw new InvalidArgumentException('Invalid request.');
        }
        redirect('admin-desk.php' . $anchor);
    } catch (Throwable $e) {
        $error = mutation_error($e);
    }
}

// ---------- Contract renewals (and their automatic to-do 30 days before the end date) ----------
$renewals = rows("SELECT id,client_name,phone,whatsapp,ending_date,starting_date FROM clients WHERE deleted_at IS NULL AND is_active=1 AND COALESCE(client_status,'active')<>'lead' AND (ending_date IS NULL OR ending_date BETWEEN ? AND ?) ORDER BY ending_date IS NULL, ending_date, client_name LIMIT 8", [date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('+30 days'))]);
foreach ($renewals as $client) {
    if (!$client['ending_date'] || $client['ending_date'] < $today) continue;
    query('INSERT IGNORE INTO todo_tasks (user_id,title,expected_date,category,client_id,auto_key) VALUES (?,?,?,?,?,?)', [$user['id'], 'Send renewal to ' . $client['client_name'] . ' (ends ' . date('j M', strtotime($client['ending_date'])) . ')', max($today, date('Y-m-d', strtotime($client['ending_date'] . ' -7 days'))), 'client', $client['id'], 'renewal:' . $client['id'] . ':' . $client['ending_date']]);
}

// ---------- To-dos ----------
$todos = rows("SELECT t.*,c.client_name FROM todo_tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.user_id=? AND (t.status='open' OR DATE(t.completed_at)=CURDATE()) ORDER BY t.expected_date IS NULL, t.expected_date, t.is_urgent DESC, t.due_time IS NULL, t.due_time, t.id", [$user['id']]);
$weekLast = date('Y-m-d', strtotime('+6 days'));
$groups = ['Today' => [], 'This week' => [], 'Later' => [], 'No date' => []];
foreach ($todos as $todo) {
    $d = $todo['expected_date'];
    if ($todo['status'] === 'completed' || ($d && $d <= $today)) $groups['Today'][] = $todo;
    elseif (!$d) $groups['No date'][] = $todo;
    elseif ($d <= $weekLast) $groups['This week'][] = $todo;
    else $groups['Later'][] = $todo;
}
$todayTotal = count($groups['Today']);
$todayDone = count(array_filter($groups['Today'], static fn($t) => $t['status'] === 'completed'));
$overdueTodos = count(array_filter($todos, static fn($t) => $t['status'] === 'open' && $t['expected_date'] && $t['expected_date'] < $today));
$deskClients = rows('SELECT id,client_name FROM clients WHERE deleted_at IS NULL ORDER BY client_name');

// ---------- Money in: what clients still owe ----------
$collect = rows("SELECT c.id,c.client_name,c.phone,c.whatsapp,c.remaining_amount,c.billing_cycle,(SELECT MAX(p.payment_date) FROM client_payments p WHERE p.client_id=c.id) AS last_paid FROM clients c WHERE c.deleted_at IS NULL AND c.remaining_amount>0 ORDER BY c.remaining_amount DESC");
$toCollect = (int) round(100 * array_sum(array_column($collect, 'remaining_amount')));
$receivedMonth = (int) round(100 * (float) (one('SELECT COALESCE(SUM(amount),0) AS t FROM client_payments WHERE payment_date BETWEEN ? AND ?', [$monthStart, $monthEnd])['t'] ?? 0));

// ---------- Money out: bills and salaries ----------
$unpaid = rows('SELECT * FROM office_payments WHERE paid_at IS NULL ORDER BY due_date,id');
$paidMonth = rows('SELECT * FROM office_payments WHERE paid_at>=? AND paid_at<? ORDER BY paid_at DESC', [$monthStart . ' 00:00:00', date('Y-m-d', strtotime($monthEnd . ' +1 day')) . ' 00:00:00']);
$salaryRows = rows("SELECT s.net_salary FROM employee_salary_history s JOIN users u ON u.id=s.employee_id AND u.account_status='active' AND u.deleted_at IS NULL WHERE s.effective_from<=? AND (s.effective_to IS NULL OR s.effective_to>=?)", [$today, $today]);
$salaryTotal = (int) round(100 * array_sum(array_column($salaryRows, 'net_salary')));
$salaryPaid = (bool) one("SELECT id FROM office_payments WHERE category='salary' AND due_date=?", [$monthEnd]);
$billsDue = 0;
foreach ($unpaid as $p) if ($p['due_date'] <= $monthEnd) $billsDue += (int) round(100 * (float) $p['amount']);
$toPay = $billsDue + ($salaryPaid ? 0 : $salaryTotal);
$paidMonthTotal = (int) round(100 * array_sum(array_column($paidMonth, 'amount')));
$overdueBills = count(array_filter($unpaid, static fn($p) => $p['due_date'] < $today));

// ---------- Profit & loss (expected for this month) ----------
$incomeExpected = $receivedMonth + $toCollect;
$expenseExpected = $paidMonthTotal + $toPay;
$profit = $incomeExpected - $expenseExpected;
$margin = $incomeExpected > 0 ? (int) round(100 * $profit / $incomeExpected) : 0;
$chart = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $income = (float) (one("SELECT COALESCE(SUM(amount),0) AS t FROM client_payments WHERE DATE_FORMAT(payment_date,'%Y-%m')=?", [$m])['t'] ?? 0);
    $expense = (float) (one("SELECT COALESCE(SUM(amount),0) AS t FROM office_payments WHERE paid_at IS NOT NULL AND DATE_FORMAT(paid_at,'%Y-%m')=?", [$m])['t'] ?? 0);
    if ($i === 0) { $income = $incomeExpected / 100; $expense = $expenseExpected / 100; }
    $chart[] = ['label' => date('M', strtotime($m . '-01')), 'income' => $income, 'expense' => $expense, 'current' => $i === 0];
}
$chartMax = max(1, ...array_map(static fn($c) => max($c['income'], $c['expense']), $chart));

// ---------- Daily WhatsApp summary (live preview) ----------
$waEnabled = desk_setting('wa_summary_enabled', '1') === '1';
$waTime = substr(desk_setting('wa_summary_time', '20:00'), 0, 5);
$waItems = array_filter(explode(',', desk_setting('wa_summary_items', 'work,late,overdue,money')));
$activeEmployees = count_value("SELECT COUNT(*) FROM users WHERE role='employee' AND account_status='active' AND deleted_at IS NULL");
$submittedToday = count_value("SELECT COUNT(DISTINCT s.employee_id) FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id AND u.account_status='active' AND u.deleted_at IS NULL WHERE s.work_date=? AND s.submission_status='submitted'", [$today]);
$loginsToday = rows("SELECT MIN(a.login_at) AS first_login,ep.shift_start,ep.shift_end,ep.shift_label,ep.shift_slots,ep.shift_hours FROM employee_attendance_sessions a JOIN users u ON u.id=a.employee_id AND u.account_status='active' AND u.deleted_at IS NULL JOIN employee_profiles ep ON ep.user_id=u.id WHERE a.login_at>=CURDATE() AND a.login_at<CURDATE()+INTERVAL 1 DAY GROUP BY a.employee_id,ep.shift_start,ep.shift_end,ep.shift_label,ep.shift_slots,ep.shift_hours");
$lateToday = count(array_filter($loginsToday, static fn($l) => login_late_seconds($l['first_login'], employee_timing($l)) > 0));
$notLoggedIn = max(0, $activeEmployees - count($loginsToday));
$summaryLines = ['Bhavi daily summary · ' . date('D j M')];
if (in_array('work', $waItems, true)) $summaryLines[] = '✅ Work submitted: ' . $submittedToday . ' of ' . $activeEmployees;
if (in_array('late', $waItems, true)) $summaryLines[] = '⏰ Late: ' . $lateToday . ' · Not logged in: ' . $notLoggedIn;
if (in_array('overdue', $waItems, true)) $summaryLines[] = '📌 Overdue to-dos: ' . $overdueTodos . ($overdueBills ? ' · Overdue bills: ' . $overdueBills : '');
if (in_array('money', $waItems, true)) $summaryLines[] = '💰 Collect ' . rupees_label($toCollect / 100) . ' · Pay ' . rupees_label($toPay / 100);
$summaryText = implode("\n", $summaryLines);

$money = static fn(int $paise): string => h(rupees_label($paise / 100));
$todoTag = static fn(array $t): string => ['client' => 'Client', 'team' => 'Team'][$t['category']] ?? 'Office';
$payCats = ['vendor' => 'Vendor', 'freelancer' => 'Freelancer', 'rent' => 'Rent', 'utility' => 'Utility bill', 'salary' => 'Salaries', 'other' => 'Other'];
$initials = static fn(string $n): string => strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2)))) ?: '?';

page_start('My desk', 'admin-desk.php');
error_message($error);
?>
<div class="to-do-section">
    <header class="td-head">
        <div>
            <p class="td-crumb">Dashboard / My desk</p>
            <h1 class="td-title">My desk</h1>
            <p class="td-sub">Your to-dos, money in &amp; out, and reminders — in one place. <?= h(date('D, j M Y')) ?></p>
        </div>
        <nav class="td-jump" aria-label="Jump to section">
            <a href="#todo">☑ To-do</a><a href="#money">₹ Money</a><a href="#reminders">⏰ Reminders</a><span class="td-private">🔒 Admin only</span>
        </nav>
    </header>

    <section class="td-stats" aria-label="Today at a glance">
        <a class="td-stat is-green" href="#todo"><span>Today's to-dos</span><strong><?= $todayDone ?> / <?= $todayTotal ?> done</strong></a>
        <a class="td-stat is-red" href="#todo"><span>Overdue</span><strong><?= $overdueTodos + $overdueBills ?></strong><small><?= $overdueTodos ?> task<?= $overdueTodos === 1 ? '' : 's' ?> · <?= $overdueBills ?> bill<?= $overdueBills === 1 ? '' : 's' ?></small></a>
        <a class="td-stat" href="#money-in"><span>↓ To collect</span><strong><?= $money($toCollect) ?></strong><small><?= count($collect) ?> client<?= count($collect) === 1 ? '' : 's' ?></small></a>
        <a class="td-stat is-gold" href="#money-out"><span>↑ To pay</span><strong><?= $money($toPay) ?></strong><small>Bills <?= $money($billsDue) ?> · Salaries <?= $money($salaryPaid ? 0 : $salaryTotal) ?></small></a>
        <a class="td-stat is-dark" href="#money"><span>Profit this month</span><strong><?= $money($profit) ?></strong><small>expected · <?= $margin ?>% margin</small></a>
    </section>

    <div class="td-row">
        <section class="td-card td-todo" id="todo">
            <p class="td-eyebrow">☑ To-do</p>
            <div class="td-card-head"><h2>Things to do</h2>
                <div class="td-filter" role="tablist"><button type="button" class="active" data-td-filter="all">All</button><button type="button" data-td-filter="client">Client</button><button type="button" data-td-filter="office">Office</button></div>
            </div>
            <form method="post" class="td-add"><?= csrf_field() ?><input type="hidden" name="action" value="add_todo">
                <label class="sr-only" for="td-title">New to-do</label>
                <input id="td-title" name="title" maxlength="500" placeholder="Add something to do…" required>
                <label class="sr-only" for="td-when">When</label>
                <select id="td-when" name="when" data-td-when><option value="today">Today</option><option value="tomorrow">Tomorrow</option><option value="week">This week</option><option value="date">Pick date</option><option value="none">No date</option></select>
                <button type="submit" class="td-btn-dark">Add</button>
                <details class="td-more"><summary>More options</summary>
                    <div class="td-more-grid">
                        <label><span>Date</span><input type="date" name="due_date" min="<?= h($today) ?>" data-td-date></label>
                        <label><span>Time</span><?= time12_field('due_time', null, false, 'Time') ?></label>
                        <label><span>Type</span><select name="category"><option value="office">Office</option><option value="client">Client</option><option value="team">Team</option></select></label>
                        <label><span>Client</span><select name="client_id"><option value="">—</option><?= options($deskClients, 'client_name') ?></select></label>
                        <label class="td-check"><input type="checkbox" name="is_urgent" value="1"> Urgent</label>
                    </div>
                </details>
            </form>
            <?php if (!$todos): ?><p class="td-empty">Nothing to do yet — add your first to-do above.</p><?php endif; ?>
            <?php foreach ($groups as $groupName => $items): if (!$items) continue; ?>
                <h3 class="td-group" data-td-group><?= h($groupName) ?></h3>
                <?php foreach ($items as $t): $done = $t['status'] === 'completed'; $late = !$done && $t['expected_date'] && $t['expected_date'] < $today; $cat = $t['category'] === 'client' ? 'client' : 'office'; ?>
                    <article class="td-item<?= $done ? ' is-done' : '' ?><?= $late || ($t['is_urgent'] && !$done) ? ' is-hot' : '' ?>" data-td-item data-category="<?= $cat ?>">
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_todo"><input type="hidden" name="todo_id" value="<?= (int) $t['id'] ?>"><button type="submit" class="td-tick" aria-label="<?= $done ? 'Mark not done' : 'Mark done' ?>"><?= $done ? '✓' : '' ?></button></form>
                        <div class="td-item-text">
                            <strong><?= h($t['title']) ?></strong>
                            <span class="td-tags">
                                <em class="td-tag is-<?= h($t['category']) ?>"><?= h($todoTag($t)) ?></em>
                                <?php if ($t['auto_key']): ?><em class="td-tag is-auto">Auto</em><?php endif; ?>
                                <?php if ($t['is_urgent'] && !$done): ?><em class="td-tag is-urgent">Urgent</em><?php endif; ?>
                                <?php if ($late): $days = (int) round((strtotime($today) - strtotime($t['expected_date'])) / 86400); ?><em class="td-due is-late">Due <?= $days === 1 ? 'yesterday' : $days . ' days ago' ?></em>
                                <?php elseif ($done): ?><em class="td-due">Done at <?= h(date('g:i A', strtotime($t['completed_at']))) ?></em>
                                <?php elseif ($t['expected_date']): ?><em class="td-due"><?= $t['expected_date'] === $today ? 'Today' : h(date('D, j M', strtotime($t['expected_date']))) ?><?= $t['due_time'] ? ', ' . h(date('g:i A', strtotime($t['due_time']))) : '' ?></em><?php endif; ?>
                                <?php if ($t['client_name'] && !str_contains($t['title'], $t['client_name'])): ?><em class="td-due">· <?= h($t['client_name']) ?></em><?php endif; ?>
                            </span>
                        </div>
                        <form method="post" class="td-del" onsubmit="return confirm('Remove this to-do?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_todo"><input type="hidden" name="todo_id" value="<?= (int) $t['id'] ?>"><button type="submit" aria-label="Remove">×</button></form>
                    </article>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <a class="td-link" href="admin-todo.php">Open full task list (dates &amp; remarks) →</a>
        </section>

        <div class="td-side" id="reminders">
            <section class="td-card">
                <p class="td-eyebrow">⏰ Reminders</p>
                <h2>Contract renewals</h2>
                <p class="td-note">30 days before the end date, a to-do is added for you <em class="td-tag is-auto">Auto</em></p>
                <?php if (!$renewals): ?><p class="td-empty">No contracts end in the next 30 days.</p><?php endif; ?>
                <?php foreach ($renewals as $c): $days = $c['ending_date'] ? (int) round((strtotime($c['ending_date']) - strtotime($today)) / 86400) : null; $wa = $c['ending_date'] ? desk_wa_link($c['whatsapp'] ?: $c['phone'], 'Hello ' . $c['client_name'] . ', your plan with Bhavi Creations ends on ' . date('j M Y', strtotime($c['ending_date'])) . '. Shall we renew it? Please let us know.') : null; ?>
                    <div class="td-renew">
                        <div><strong><?= h($c['client_name']) ?></strong><small><?= $c['ending_date'] ? 'Ends ' . h(date('j M Y', strtotime($c['ending_date']))) : 'Ongoing · no end date' ?></small></div>
                        <?php if ($days === null): ?><em class="td-chip">Ongoing</em><a class="td-btn-outline" href="admin-add-client.php?id=<?= (int) $c['id'] ?>">Set end date</a>
                        <?php else: ?><em class="td-chip <?= $days <= 7 ? 'is-red' : ($days <= 21 ? 'is-gold' : 'is-green') ?>"><?= $days < 0 ? 'Ended ' . abs($days) . 'd ago' : $days . ' days' ?></em>
                            <?php if ($wa): ?><a class="td-btn-dark" href="<?= h($wa) ?>" target="_blank" rel="noopener noreferrer">Send renewal</a><?php else: ?><a class="td-btn-outline" href="admin-add-client.php?id=<?= (int) $c['id'] ?>" title="Add a phone number to send on WhatsApp">Add phone</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="td-card" id="summary">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_summary">
                    <div class="td-card-head"><h2>Daily WhatsApp summary</h2><label class="td-check"><input type="checkbox" name="wa_enabled" value="1" <?= $waEnabled ? 'checked' : '' ?>> On</label></div>
                    <p class="td-note">To the admin, every evening</p>
                    <label class="td-inline"><span>Every day at</span><?= time12_field('wa_time', $waTime, true, 'Every day at') ?></label>
                    <div class="td-checks">
                        <?php foreach (['work' => 'Work submitted', 'late' => 'Late & absent', 'overdue' => 'Overdue tasks', 'money' => 'Money to collect & pay'] as $key => $label): ?><label class="td-check"><input type="checkbox" name="wa_items[]" value="<?= $key ?>" <?= in_array($key, $waItems, true) ? 'checked' : '' ?>> <?= h($label) ?></label><?php endforeach; ?>
                    </div>
                    <pre class="td-preview"><?= h($summaryText) ?></pre>
                    <div class="td-actions"><button type="submit" class="td-btn-outline">Save settings</button><a class="td-btn-dark" href="https://wa.me/?text=<?= h(rawurlencode($summaryText)) ?>" target="_blank" rel="noopener noreferrer">Send now on WhatsApp</a></div>
                    <p class="td-note">Automatic sending at the set time needs the WhatsApp Business API. Until it is connected, tap “Send now”.</p>
                </form>
            </section>
        </div>
    </div>

    <section class="td-card td-pl" id="money">
        <p class="td-eyebrow">₹ Money · <?= h(date('F')) ?></p>
        <div class="td-pl-grid">
            <div class="td-pl-text">
                <h2>Profit &amp; loss</h2>
                <div class="td-pl-line"><div><b>Income</b><small><?= $money($receivedMonth) ?> received + <?= $money($toCollect) ?> to collect</small></div><strong class="is-pos"><?= $money($incomeExpected) ?></strong></div>
                <div class="td-pl-line"><div><b>Expenses</b><small><?= $money($paidMonthTotal) ?> paid + <?= $money($toPay) ?> to pay</small></div><strong class="is-neg">−<?= $money($expenseExpected) ?></strong></div>
                <div class="td-pl-total"><div><span>Profit (expected)</span><small><?= $margin ?>% margin · <?= $money($incomeExpected) ?> in − <?= $money($expenseExpected) ?> out</small></div><strong><?= $money($profit) ?></strong></div>
            </div>
            <div class="td-chart">
                <div class="td-chart-head"><span>Last 6 months · profit on top of each month</span><span class="td-legend"><i class="is-in"></i>Income <i class="is-out"></i>Expenses</span></div>
                <div class="td-bars">
                    <?php foreach ($chart as $c): $p = $c['income'] - $c['expense']; ?>
                        <div class="td-bar-group<?= $c['current'] ? ' is-current' : '' ?>" title="<?= h($c['label']) ?>: income <?= h(rupees_label($c['income'])) ?>, expenses <?= h(rupees_label($c['expense'])) ?>">
                            <span class="td-bar-profit <?= $p < 0 ? 'is-neg' : '' ?>"><?= ($p >= 0 ? '+' : '') . h(desk_short($p)) ?></span>
                            <div class="td-bar-pair"><i class="is-in" style="height:<?= round(100 * $c['income'] / $chartMax) ?>%"></i><i class="is-out" style="height:<?= round(100 * $c['expense'] / $chartMax) ?>%"></i></div>
                            <small><?= h($c['label']) ?><?= $c['current'] ? '*' : '' ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="td-note">* <?= h(date('F')) ?> is expected — it updates as money comes in and goes out.</p>
            </div>
        </div>
    </section>

    <div class="td-row td-row-even">
        <section class="td-card" id="money-in">
            <p class="td-eyebrow">₹ Money · In</p>
            <div class="td-card-head"><h2>↓ To collect from clients</h2><strong class="td-head-amt"><?= $money($toCollect) ?></strong></div>
            <?php if (!$collect): ?><p class="td-empty">Every client is fully paid.</p><?php endif; ?>
            <?php foreach ($collect as $c): $amt = (int) round(100 * (float) $c['remaining_amount']); $wa = desk_wa_link($c['whatsapp'] ?: $c['phone'], 'Hello ' . $c['client_name'] . ', a gentle reminder: ' . rupees_label($amt / 100) . ' is pending with Bhavi Creations. Please share the payment when convenient. Thank you!'); ?>
                <div class="td-money-row">
                    <span class="td-avatar"><?= h($initials($c['client_name'])) ?></span>
                    <div><strong><?= h($c['client_name']) ?></strong><small><em class="td-chip is-gold">Balance</em> <?= $c['last_paid'] ? 'Last paid ' . h(date('j M', strtotime($c['last_paid']))) : 'No payment yet' ?><?= $c['billing_cycle'] ? ' · ' . h(ucfirst(str_replace('_', ' ', $c['billing_cycle']))) : '' ?></small></div>
                    <b><?= $money($amt) ?></b>
                    <?php if ($wa): ?><a class="td-btn-outline" href="<?= h($wa) ?>" target="_blank" rel="noopener noreferrer">Remind</a><?php else: ?><a class="td-btn-outline is-muted" href="admin-add-client.php?id=<?= (int) $c['id'] ?>" title="Add a phone number to remind on WhatsApp">Add phone</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="td-card" id="money-out">
            <p class="td-eyebrow">₹ Money · Out</p>
            <div class="td-card-head"><h2>↑ Payments to give</h2>
                <details class="td-addpay"><summary class="td-btn-gold">+ Add payment</summary>
                    <form method="post" class="td-addpay-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_payment">
                        <label><span>For</span><input name="title" maxlength="200" required placeholder="e.g. Printing press"></label>
                        <label><span>Type</span><select name="category"><?php foreach (['vendor', 'freelancer', 'rent', 'utility', 'other'] as $k): ?><option value="<?= $k ?>"><?= h($payCats[$k]) ?></option><?php endforeach; ?></select></label>
                        <label><span>Amount (₹)</span><input type="number" name="amount" min="0.01" step="0.01" required></label>
                        <label><span>Due date</span><input type="date" name="due_date" value="<?= h($today) ?>" required></label>
                        <label class="td-wide"><span>Note</span><input name="note" maxlength="255" placeholder="e.g. Brochure print · 1,000 copies"></label>
                        <button type="submit" class="td-btn-dark">Save payment</button>
                    </form>
                </details>
            </div>
            <?php if (!$unpaid && ($salaryPaid || !$salaryTotal)): ?><p class="td-empty">Nothing to pay right now.</p><?php endif; ?>
            <?php foreach ($unpaid as $p): $amt = (int) round(100 * (float) $p['amount']); $days = (int) round((strtotime($p['due_date']) - strtotime($today)) / 86400); ?>
                <div class="td-money-row<?= $days < 0 ? ' is-hot' : '' ?>">
                    <span class="td-avatar is-dark"><?= h($initials($p['title'])) ?></span>
                    <div><strong><?= h($p['title']) ?></strong><small><em class="td-chip <?= $days < 0 ? 'is-red' : 'is-gold' ?>"><?= $days < 0 ? 'Overdue · ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') : ($days === 0 ? 'Due today' : 'Due ' . h(date('j M', strtotime($p['due_date'])))) ?></em> <?= h($p['note'] ?: $payCats[$p['category']]) ?></small></div>
                    <b><?= $money($amt) ?></b>
                    <div class="td-row-actions">
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pay_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="td-btn-dark">Mark paid</button></form>
                        <form method="post" onsubmit="return confirm('Remove this payment?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="td-x" aria-label="Remove">×</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($salaryTotal && !$salaryPaid): ?>
                <div class="td-money-row is-salary">
                    <span class="td-avatar is-gold">₹</span>
                    <div><strong>Salaries · <?= count($salaryRows) ?> employee<?= count($salaryRows) === 1 ? '' : 's' ?></strong><small><em class="td-chip is-gold">Due <?= h(date('j M', strtotime($monthEnd))) ?></em> Take-home after PF / ESI · <a href="employee-salary.php">see each salary</a></small></div>
                    <b><?= $money($salaryTotal) ?></b>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pay_payment"><input type="hidden" name="payment_id" value="salary"><button type="submit" class="td-btn-dark">Mark paid</button></form>
                </div>
            <?php endif; ?>
            <?php if ($paidMonth): ?>
                <h3 class="td-group">Paid this month · <?= $money($paidMonthTotal) ?></h3>
                <?php foreach ($paidMonth as $p): ?>
                    <div class="td-money-row is-paid">
                        <span class="td-avatar is-dark"><?= h($initials($p['title'])) ?></span>
                        <div><strong><?= h($p['title']) ?></strong><small><em class="td-chip is-green">✓ Paid <?= h(date('j M', strtotime($p['paid_at']))) ?></em> <?= h($p['note'] ?: $payCats[$p['category']]) ?></small></div>
                        <b><?= $money((int) round(100 * (float) $p['amount'])) ?></b>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="undo_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="td-btn-outline">Undo</button></form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
(function () {
    var root = document.querySelector('.to-do-section'); if (!root) return;
    // All / Client / Office filter for the to-do list.
    root.querySelectorAll('[data-td-filter]').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = b.dataset.tdFilter;
            root.querySelectorAll('[data-td-filter]').forEach(function (x) { x.classList.toggle('active', x === b); });
            root.querySelectorAll('[data-td-item]').forEach(function (i) { i.hidden = f !== 'all' && i.dataset.category !== f; });
            root.querySelectorAll('[data-td-group]').forEach(function (g) {
                var n = g.nextElementSibling, any = false;
                while (n && n.matches('[data-td-item]')) { if (!n.hidden) any = true; n = n.nextElementSibling; }
                g.hidden = !any;
            });
        });
    });
    // "Pick date" opens the date field.
    var when = root.querySelector('[data-td-when]'), date = root.querySelector('[data-td-date]');
    if (when && date) when.addEventListener('change', function () {
        if (when.value === 'date') { date.closest('details').open = true; date.required = true; date.focus(); } else date.required = false;
    });
})();
</script>
<?php page_end(); ?>
