<?php
// Monthly client meeting.
// Client: sees the next meeting (countdown) and asks to move it — for 1 month, a few months, or permanently.
// Managing Director / managers: every client's monthly meeting, approve / reject requests, or change a meeting directly.
require_once __DIR__ . '/includes/monthly-meeting.php';
$user = require_roles(['admin', 'manager', 'client']);
$isClient = $user['role'] === 'client';
$error = null;

$ownClient = $isClient ? one('SELECT * FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1', [$user['id']]) : null;
if ($isClient && !$ownClient) fail(403, 'Your client account is unavailable.');

$notifyClient = static function (array $client, int $senderId, string $title, string $message): void {
    if (!empty($client['user_id'])) query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$client['user_id'], $senderId, $title, $message]);
};
$changeText = static fn(array $c): string => 'New time: ' . date('D, j M Y', strtotime(date('Y-m-', strtotime($c['start_month'])) . sprintf('%02d', min((int) $c['meeting_day'], (int) date('t', strtotime($c['start_month'])))))) . ' · ' . mm_time_label($c['meeting_time']) . ' · ' . mm_scope_label($c);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($isClient) {
            if ($action !== 'request_change') throw new InvalidArgumentException('Invalid request.');
            if (empty($ownClient['meeting_day'])) throw new InvalidArgumentException('Your monthly meeting is not fixed yet. Our team will set it.');
            $in = mm_change_input();
            $reason = text_input('reason', 1000);
            db()->beginTransaction();
            // Only one open request at a time: a new one replaces the old.
            query("UPDATE client_meeting_changes SET status='withdrawn' WHERE client_id=? AND status='pending'", [$ownClient['id']]);
            query('INSERT INTO client_meeting_changes (client_id,meeting_day,meeting_time,start_month,months,reason,status,requested_by) VALUES (?,?,?,?,?,?,?,?)', [$ownClient['id'], $in['meeting_day'], $in['meeting_time'], $in['start_month'], $in['months'], $reason, 'pending', $user['id']]);
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) SELECT id,?,'general',?,? FROM users WHERE deleted_at IS NULL AND account_status='active' AND role IN ('admin','manager')", [$user['id'], MM_NOTICE_PREFIX . ' change requested by ' . $ownClient['client_name'], $changeText($in) . "\nReason: " . $reason]);
            db()->commit();
            flash('Your request was sent to the Managing Director. We will confirm the new meeting date soon.');
            // Sent from the countdown card (dashboard / project details): go back there.
            $back = (string) ($_POST['back'] ?? '');
            redirect(in_array($back, ['client-dashboard.php', 'client-details.php'], true) ? $back . '#mm-change' : 'client-monthly-meeting.php');
        }

        if ($action === 'decide') {
            $change = one("SELECT m.*,c.client_name,c.user_id FROM client_meeting_changes m JOIN clients c ON c.id=m.client_id WHERE m.id=? AND m.status='pending' AND c.deleted_at IS NULL", [positive_id($_POST['id'] ?? null)]);
            if (!$change) throw new InvalidArgumentException('This request was already handled.');
            $approve = ($_POST['decision'] ?? '') === 'approve';
            $note = optional_text('decision_note', 500);
            db()->beginTransaction();
            query('UPDATE client_meeting_changes SET status=?,decided_by=?,decided_at=NOW(),decision_note=? WHERE id=?', [$approve ? 'approved' : 'rejected', $user['id'], $note, $change['id']]);
            if ($approve) mm_apply($change, (int) $change['client_id']);
            $notifyClient($change, (int) $user['id'], MM_NOTICE_PREFIX . ($approve ? ' change approved' : ' change not possible'), ($approve ? $changeText($change) : 'Your monthly meeting stays at the usual time.') . ($note !== '' ? "\nNote: " . $note : ''));
            db()->commit();
            flash($approve ? 'Approved. ' . $change['client_name'] . '’s meeting is moved.' : 'Request rejected. The client was told.');
            if (($_POST['back'] ?? '') === 'dashboard') redirect(dashboard_path($user) . '#meetings'); // decided on the dashboard card
            redirect('client-monthly-meeting.php' . (!empty($_POST['back_client']) ? '?client=' . (int) $change['client_id'] : ''));
        }
        if ($action === 'change') {
            $client = one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL', [positive_id($_POST['client_id'] ?? null)]);
            if (!$client) throw new InvalidArgumentException('Select a client.');
            if (empty($client['meeting_day'])) throw new InvalidArgumentException('Set the monthly meeting day on the client form first.');
            $in = mm_change_input();
            $reason = optional_text('reason', 1000);
            db()->beginTransaction();
            query('INSERT INTO client_meeting_changes (client_id,meeting_day,meeting_time,start_month,months,reason,status,requested_by,decided_by,decided_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())', [$client['id'], $in['meeting_day'], $in['meeting_time'], $in['start_month'], $in['months'], $reason, 'approved', $user['id'], $user['id']]);
            mm_apply($in, (int) $client['id']);
            if (!empty($_POST['notify'])) $notifyClient($client, (int) $user['id'], MM_NOTICE_PREFIX . ' time changed', $changeText($in) . ($reason !== '' ? "\nReason: " . $reason : ''));
            db()->commit();
            flash('Meeting time changed for ' . $client['client_name'] . '.');
            redirect('client-monthly-meeting.php?client=' . (int) $client['id']);
        }
        if ($action === 'undo') {
            // Remove a temporary change: the meeting goes back to the usual day / time for those months.
            $change = one("SELECT * FROM client_meeting_changes WHERE id=? AND status='approved' AND months IS NOT NULL", [positive_id($_POST['id'] ?? null)]);
            if (!$change) throw new InvalidArgumentException('That change is no longer active.');
            query("UPDATE client_meeting_changes SET status='cancelled' WHERE id=?", [$change['id']]);
            flash('Change removed. The usual meeting time applies again.');
            redirect('client-monthly-meeting.php?client=' . (int) $change['client_id']);
        }
        throw new InvalidArgumentException('Invalid request.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
        // Request sent from the countdown card: show the problem back on that page.
        $back = (string) ($_POST['back'] ?? '');
        if ($isClient && in_array($back, ['client-dashboard.php', 'client-details.php'], true)) { flash($error, 'error'); redirect($back . '#mm-change-open'); }
    }
}

$statusPill = static function (string $status): string {
    $map = ['pending' => ['is-pending', '⏳ Waiting for approval'], 'approved' => ['is-confirmed', '✓ Approved'], 'rejected' => ['is-change_requested', '✕ Not possible'], 'withdrawn' => ['is-cancelled', 'Replaced'], 'cancelled' => ['is-cancelled', 'Removed']];
    [$cls, $label] = $map[$status] ?? ['', ucfirst($status)];
    return '<span class="cm-resp ' . $cls . '">' . h($label) . '</span>';
};

/** The change form (new date, time, how long). */
$changeForm = static function (array $client, string $action, bool $forClient) use ($isClient): void {
    $next = mm_next($client);
    $date = $_POST['new_date'] ?? ($next['date'] ?? date('Y-m-d'));
    $time = $_POST['new_time'] ?? substr((string) ($next['time'] ?: ($client['meeting_time'] ?: '11:00:00')), 0, 5);
    $scope = $_POST['scope'] ?? 'once';
    ?>
    <form method="post" class="ot-form mm-form" data-mm-form><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($action) ?>"><?php if (!$forClient): ?><input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>"><?php endif; ?>
        <div class="mm-form-grid">
            <label><span>New meeting date *</span><input type="date" name="new_date" min="<?= h(today()) ?>" value="<?= h($date) ?>" required></label>
            <label><span>New time *</span><input type="time" name="new_time" value="<?= h($time) ?>" required></label>
        </div>
        <fieldset class="mm-scope"><legend>How long should this change apply?</legend>
            <div class="cm-pills">
                <label class="cm-pill"><input type="radio" name="scope" value="once" <?= $scope === 'once' ? 'checked' : '' ?>><span>Only this month</span></label>
                <label class="cm-pill"><input type="radio" name="scope" value="months" <?= $scope === 'months' ? 'checked' : '' ?>><span>For some months</span></label>
                <label class="cm-pill"><input type="radio" name="scope" value="permanent" <?= $scope === 'permanent' ? 'checked' : '' ?>><span>Permanently</span></label>
            </div>
            <label class="mm-months" data-mm-months<?= $scope === 'months' ? '' : ' hidden' ?>><span>Number of months *</span><input type="number" name="months" min="1" max="24" step="1" value="<?= h($_POST['months'] ?? '3') ?>"><small>e.g. 1, 3 or 5 months from the month of the new date</small></label>
            <p class="ot-help" data-mm-hint></p>
        </fieldset>
        <label><span><?= $forClient ? 'Reason *' : 'Reason / note' ?> <?= $forClient ? '' : '<small>(optional, shown to the client)</small>' ?></span><textarea name="reason" maxlength="1000" rows="2" <?= $forClient ? 'required' : '' ?> placeholder="<?= $forClient ? 'e.g. We have an urgent work on that day. Please move it to 23rd.' : 'e.g. MD travelling that week' ?>"><?= h($_POST['reason'] ?? '') ?></textarea></label>
        <?php if (!$forClient): ?><label class="zm-check"><input type="checkbox" name="notify" value="1" checked> Tell the client in their portal</label><?php endif; ?>
        <div class="ot-actions"><button class="ot-gold" type="submit"><?= $forClient ? 'Send request' : 'Change meeting time' ?></button></div>
    </form>
    <?php
};

page_start($isClient ? 'Monthly meeting' : 'Monthly meetings', 'client-monthly-meeting.php');
error_message($error);
?>
<div class="ot-page mm-page">
<?php if ($isClient):
    $client = $ownClient;
    $upcoming = mm_upcoming($client, 3);
    $history = rows('SELECT * FROM client_meeting_changes WHERE client_id=? ORDER BY id DESC LIMIT 20', [$client['id']]);
    $pending = array_values(array_filter($history, static fn($c) => $c['status'] === 'pending'));
?>
    <header class="ot-head">
        <p class="ot-crumb">Your workspace / Monthly meeting</p>
        <h1 class="ot-title">Monthly meeting</h1>
        <p class="ot-sub">Your monthly review meeting with Bhavi. If the date does not suit you, ask us to move it.</p>
    </header>
    <?php if (empty($client['meeting_day'])): ?>
        <section class="ot-card"><p class="ot-help">Your monthly meeting is not fixed yet. Our team will set it and it will show here.</p></section>
    <?php else: ?>
        <?= mm_countdown_card($client, true) ?>
        <?php if ($pending): ?><p class="cm-banner is-pending">⏳ Your change request is waiting for approval: <?= h($changeText($pending[0])) ?></p><?php endif; ?>
        <div class="mm-grid">
            <section class="ot-card">
                <div class="ot-card-head"><h2>Schedule</h2></div>
                <dl class="mm-facts">
                    <div><dt>Usual time</dt><dd><?= h(mm_rule_label($client)) ?></dd></div>
                    <div><dt>How we meet</dt><dd><?= h(mm_mode_label($client)) ?><?= $client['meeting_place'] !== '' && $client['meeting_mode'] !== 'google_meet' ? ' · ' . h($client['meeting_place']) : '' ?></dd></div>
                </dl>
                <ul class="mm-list">
                    <?php foreach ($upcoming as $u): ?>
                    <li><span><strong><?= h(mm_when($u)) ?></strong><?php if ($u['changed']): ?><small>Moved · <?= h(mm_scope_label($u['change'])) ?></small><?php endif; ?></span><b class="mm-chip<?= mm_days_to($u['date']) <= 3 ? ' is-soon' : '' ?>"><?= h(mm_countdown($u['date'])) ?></b></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="ot-card">
                <div class="ot-card-head"><h2>Request a change</h2></div>
                <p class="ot-help">Pick the new date and time. Choose if it is only for this month, for a few months, or for always.</p>
                <?php $changeForm($client, 'request_change', true); ?>
            </section>
        </div>
        <?php if ($history): ?>
        <section class="ot-card">
            <div class="ot-card-head"><h2>Change history</h2></div>
            <ul class="mm-history">
                <?php foreach ($history as $c): ?>
                <li><div><strong><?= h($changeText($c)) ?></strong><?php if ($c['reason'] !== ''): ?><small>“<?= h($c['reason']) ?>”</small><?php endif; ?><?php if ($c['decision_note'] !== ''): ?><small>Bhavi: <?= h($c['decision_note']) ?></small><?php endif; ?><small><?= h(date('j M Y, g:i A', strtotime($c['created_at']))) ?></small></div><?= $statusPill($c['status']) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    <?php endif; ?>

<?php else:
    $selectedId = (int) ($_GET['client'] ?? ($_POST['client_id'] ?? 0));
    $selected = $selectedId ? one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL', [$selectedId]) : null;
    $pending = rows("SELECT m.*,c.client_name,c.meeting_day AS usual_day,c.meeting_time AS usual_time FROM client_meeting_changes m JOIN clients c ON c.id=m.client_id WHERE m.status='pending' AND c.deleted_at IS NULL" . ($selected ? ' AND c.id=' . (int) $selected['id'] : '') . ' ORDER BY m.id');
?>
    <header class="ot-head vd-head">
        <div>
            <p class="ot-crumb">Dashboard / <?= $selected ? '<a href="client-monthly-meeting.php">Monthly meetings</a> / ' . h($selected['client_name']) : 'Monthly meetings' ?></p>
            <h1 class="ot-title"><?= $selected ? h($selected['client_name']) : 'Monthly meetings' ?></h1>
            <p class="ot-sub"><?= $selected ? 'Monthly meeting schedule. Move it for one month, a few months, or permanently.' : 'Every client’s monthly meeting. Approve change requests from clients or move a meeting yourself.' ?></p>
        </div>
        <?php if ($selected): ?><a class="cm-back" href="admin-add-client.php?id=<?= (int) $selected['id'] ?>">Edit client</a><?php endif; ?>
    </header>

    <?php if ($pending): ?>
    <section class="ot-card mm-requests">
        <div class="ot-card-head"><h2>Change requests from clients</h2><span><?= count($pending) ?> waiting</span></div>
        <?php foreach ($pending as $c): ?>
        <article class="mm-request">
            <div class="mm-request-text">
                <strong><?= h($c['client_name']) ?></strong>
                <small>Usual: <?= h(mm_rule_label(['meeting_day' => $c['usual_day'], 'meeting_time' => $c['usual_time']])) ?></small>
                <p><?= h($changeText($c)) ?></p>
                <?php if ($c['reason'] !== ''): ?><q class="cm-quote"><?= h($c['reason']) ?></q><?php endif; ?>
            </div>
            <form method="post" class="mm-decide"><?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><?php if ($selected): ?><input type="hidden" name="back_client" value="1"><?php endif; ?>
                <input name="decision_note" maxlength="500" placeholder="Note to client (optional)" aria-label="Note to client">
                <button class="ot-gold" type="submit" name="decision" value="approve"><?= icon('check') ?> Approve</button>
                <button class="mm-btn" type="submit" name="decision" value="reject">Reject</button>
            </form>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($selected):
        $history = rows('SELECT m.*,u.full_name AS by_name FROM client_meeting_changes m LEFT JOIN users u ON u.id=m.requested_by WHERE m.client_id=? ORDER BY m.id DESC LIMIT 30', [$selected['id']]);
        $today = date('Y-m-01');
    ?>
        <?php if (empty($selected['meeting_day'])): ?>
            <section class="ot-card"><p class="ot-help">No monthly meeting set for this client. <a href="admin-add-client.php?id=<?= (int) $selected['id'] ?>#monthly-meeting">Set it on the client form →</a></p></section>
        <?php else: ?>
            <?= mm_countdown_card($selected, false) ?>
            <div class="mm-grid">
                <section class="ot-card">
                    <div class="ot-card-head"><h2>Schedule</h2><a href="admin-add-client.php?id=<?= (int) $selected['id'] ?>#monthly-meeting">Edit usual time</a></div>
                    <dl class="mm-facts">
                        <div><dt>Usual time</dt><dd><?= h(mm_rule_label($selected)) ?></dd></div>
                        <div><dt>How we meet</dt><dd><?= h(mm_mode_label($selected)) ?><?= $selected['meeting_place'] !== '' ? ' · ' . h($selected['meeting_place']) : '' ?></dd></div>
                    </dl>
                    <ul class="mm-list">
                        <?php foreach (mm_upcoming($selected, 4) as $u): ?>
                        <li><span><strong><?= h(mm_when($u)) ?></strong><?php if ($u['changed']): ?><small>Moved · <?= h(mm_scope_label($u['change'])) ?></small><?php endif; ?></span><b class="mm-chip<?= mm_days_to($u['date']) <= 3 ? ' is-soon' : '' ?>"><?= h(mm_countdown($u['date'])) ?></b></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <section class="ot-card">
                    <div class="ot-card-head"><h2>Change meeting time</h2></div>
                    <p class="ot-help">Pick the new date and time, and how long it applies. “Permanently” changes the usual day and time.</p>
                    <?php $changeForm($selected, 'change', false); ?>
                </section>
            </div>
        <?php endif; ?>
        <?php if ($history): ?>
        <section class="ot-card">
            <div class="ot-card-head"><h2>Change history</h2></div>
            <ul class="mm-history">
                <?php foreach ($history as $c): $active = $c['status'] === 'approved' && $c['months'] !== null && date('Y-m-01', strtotime($c['start_month'] . ' +' . (int) $c['months'] . ' months')) > $today; ?>
                <li><div><strong><?= h($changeText($c)) ?></strong><?php if ($c['reason'] !== ''): ?><small>“<?= h($c['reason']) ?>”</small><?php endif; ?><small><?= h(($c['by_name'] ? 'By ' . $c['by_name'] . ' · ' : '') . date('j M Y, g:i A', strtotime($c['created_at']))) ?></small></div>
                    <span class="mm-history-side"><?= $statusPill($c['status']) ?><?php if ($active): ?><form method="post" data-confirm="Remove this change? The usual meeting time will apply again."><?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="mm-link">Remove</button></form><?php endif; ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

    <?php else:
        $clients = rows('SELECT * FROM clients WHERE deleted_at IS NULL AND is_active=1 ORDER BY client_name');
        $list = [];
        foreach ($clients as $c) { $n = mm_next($c); $list[] = ['c' => $c, 'n' => $n]; }
        usort($list, static fn($a, $b) => [$a['n'] === null, $a['n']['date'] ?? '', $a['c']['client_name']] <=> [$b['n'] === null, $b['n']['date'] ?? '', $b['c']['client_name']]);
    ?>
    <section class="ot-card">
        <div class="ot-card-head"><h2>All clients</h2><span>Soonest meeting first</span></div>
        <div class="table-scroll"><table class="mm-table">
            <thead><tr><th>Client</th><th>Usual time</th><th>Next meeting</th><th>Countdown</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$list) empty_row(5, 'No clients yet.'); foreach ($list as ['c' => $c, 'n' => $n]): ?>
                <tr>
                    <td data-label="Client"><strong><?= h($c['client_name']) ?></strong></td>
                    <td data-label="Usual time"><?= $c['meeting_day'] ? h(mm_rule_label($c)) . '<br><small class="ot-help">' . h(mm_mode_label($c)) . '</small>' : '<span class="cm-none">Not set</span>' ?></td>
                    <td data-label="Next meeting"><?= $n ? h(mm_when($n)) . ($n['changed'] ? ' <small class="mm-moved">Moved</small>' : '') : '—' ?></td>
                    <td data-label="Countdown"><?= $n ? '<b class="mm-chip' . (mm_days_to($n['date']) <= 3 ? ' is-soon' : '') . '">' . h(mm_countdown($n['date'])) . '</b>' : '—' ?></td>
                    <td data-label="Actions"><?= $c['meeting_day'] ? '<a class="button secondary" href="client-monthly-meeting.php?client=' . (int) $c['id'] . '">Change time</a>' : '<a class="button secondary" href="admin-add-client.php?id=' . (int) $c['id'] . '#monthly-meeting">Set meeting</a>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
<?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-mm-form]').forEach(function (form) {
    var months = form.querySelector('[data-mm-months]'), hint = form.querySelector('[data-mm-hint]'), date = form.querySelector('[name=new_date]');
    function sync() {
        var scope = (form.querySelector('[name=scope]:checked') || {}).value, n = parseInt(form.querySelector('[name=months]').value, 10) || 1;
        months.hidden = scope !== 'months';
        months.querySelector('input').required = scope === 'months';
        var d = date.value ? new Date(date.value + 'T00:00:00') : null, label = d ? d.toLocaleDateString('en-GB', {month: 'short', year: 'numeric'}) : 'the chosen month';
        hint.textContent = scope === 'once' ? 'Only the meeting in ' + label + ' moves. Next month it is back to the usual time.'
            : scope === 'months' ? 'The meeting moves to this day and time for ' + n + ' month' + (n === 1 ? '' : 's') + ' starting ' + label + '.'
            : 'From ' + label + ' the meeting is always on this day and time.';
    }
    form.addEventListener('change', sync); form.addEventListener('input', sync); sync();
});
</script>
<?php page_end(); ?>
