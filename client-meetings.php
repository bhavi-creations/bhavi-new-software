<?php
// Client meetings & shoots — the list.
// Managing Director / managers: every plan on one page (table) with the client's response, Edit, Delete,
// WhatsApp, Mark done and Cancel. New plans are made on client-meeting-form.php.
// Clients: their own plans as cards; Confirm or Request a change (the team is notified).
require_once __DIR__ . '/includes/client-meetings.php';
$user = require_roles(['admin', 'manager', 'client']);
$isClient = $user['role'] === 'client';
$canManage = !$isClient;
$error = null;

$ownClient = $isClient ? one('SELECT * FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1', [$user['id']]) : null;
if ($isClient && !$ownClient) fail(403, 'Your client account is unavailable.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = positive_id($_POST['id'] ?? null);
        if ($isClient) {
            if ($action !== 'respond') throw new InvalidArgumentException('Invalid request.');
            $e = one("SELECT * FROM client_engagements WHERE id=? AND client_id=? AND deleted_at IS NULL AND status='scheduled'", [$id, $ownClient['id']]);
            if (!$e) throw new InvalidArgumentException('This plan is no longer open for changes.');
            $response = (string) ($_POST['response'] ?? '');
            if (!in_array($response, ['confirmed', 'change_requested'], true)) throw new InvalidArgumentException('Choose confirm or request a change.');
            $note = optional_text('client_note', 1000);
            if ($response === 'change_requested' && $note === '') throw new InvalidArgumentException('Tell us what you would like to change (for example a different date or time).');
            db()->beginTransaction();
            query('UPDATE client_engagements SET client_response=?,client_note=?,responded_at=NOW() WHERE id=?', [$response, $note, $id]);
            cm_notify_staff((int) $user['id'], CM_NOTICE_PREFIX . ($response === 'confirmed' ? ' confirmed by ' : ' change requested by ') . $ownClient['client_name'], cm_title($e) . "\n" . ($note !== '' ? '“' . $note . '”' : cm_summary($e)), $e['created_by'] ? (int) $e['created_by'] : null);
            db()->commit();
            flash($response === 'confirmed' ? 'Thank you! Your confirmation has been sent to our team.' : 'Your change request has been sent. Our team will update the plan.');
            redirect('client-meetings.php');
        }
        $e = one('SELECT e.*,c.user_id AS client_user_id FROM client_engagements e JOIN clients c ON c.id=e.client_id WHERE e.id=? AND e.deleted_at IS NULL', [$id]);
        if (!$e) throw new InvalidArgumentException('That plan was not found.');
        if ($action === 'set_status') {
            $status = (string) ($_POST['status'] ?? '');
            if (!isset(CM_STATUSES[$status])) throw new InvalidArgumentException('Invalid status.');
            db()->beginTransaction();
            query('UPDATE client_engagements SET status=? WHERE id=?', [$status, $id]);
            if ($status === 'cancelled' && $e['client_user_id']) cm_notify((int) $e['client_user_id'], (int) $user['id'], CM_NOTICE_PREFIX . ' cancelled: ' . cm_title($e), cm_summary($e));
            db()->commit();
            flash('Marked as ' . strtolower(CM_STATUSES[$status]) . '.');
        } elseif ($action === 'delete') {
            query('UPDATE client_engagements SET deleted_at=NOW() WHERE id=?', [$id]);
            flash('Plan deleted.');
        } else {
            throw new InvalidArgumentException('Invalid request.');
        }
        redirect('client-meetings.php' . (isset($_POST['back']) && preg_match('/^[a-z_]+$/', (string) $_POST['back']) ? '?tab=' . $_POST['back'] : ''));
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
    }
}

// ---- Load every plan, then sort: upcoming (soonest first) → past scheduled → completed / cancelled (newest first).
$sql = 'SELECT e.*,c.client_name,c.logo_path,c.whatsapp,c.phone,c.contact_person,c.user_id AS client_user_id,u.full_name AS creator FROM client_engagements e JOIN clients c ON c.id=e.client_id LEFT JOIN users u ON u.id=e.created_by WHERE e.deleted_at IS NULL';
$params = [];
$filterClient = 0;
if ($isClient) { $sql .= ' AND e.client_id=?'; $params[] = $ownClient['id']; }
elseif (!empty($_GET['client'])) { $filterClient = (int) $_GET['client']; $sql .= ' AND e.client_id=?'; $params[] = $filterClient; }
$all = rows($sql . ' LIMIT 1000', $params);
$firstDate = static fn(array $e): int => min(array_map('strtotime', array_filter([$e['meeting_at'], $e['shoot_at']])) ?: [strtotime($e['created_at'])]);
$bucket = static fn(array $e): int => $e['status'] !== 'scheduled' ? 2 : (cm_is_past($e) ? 1 : 0);
usort($all, static function ($a, $b) use ($firstDate, $bucket) {
    $ba = $bucket($a); $bb = $bucket($b);
    if ($ba !== $bb) return $ba <=> $bb;
    return $ba === 0 ? $firstDate($a) <=> $firstDate($b) : $firstDate($b) <=> $firstDate($a);
});

$tabs = [
    'all' => ['All', static fn($e) => true],
    'upcoming' => ['Upcoming', static fn($e) => $e['status'] === 'scheduled' && !cm_is_past($e)],
    'waiting' => ['Waiting for client', static fn($e) => $e['status'] === 'scheduled' && $e['client_response'] === 'pending'],
    'confirmed' => ['Confirmed by client', static fn($e) => $e['status'] === 'scheduled' && $e['client_response'] === 'confirmed'],
    'change' => ['Change requested', static fn($e) => $e['status'] === 'scheduled' && $e['client_response'] === 'change_requested'],
    'completed' => ['Completed', static fn($e) => $e['status'] === 'completed'],
    'cancelled' => ['Cancelled', static fn($e) => $e['status'] === 'cancelled'],
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';
$counts = array_map(static fn($t) => count(array_filter($all, $t[1])), $tabs);
$list = array_values(array_filter($all, $tabs[$tab][1]));
$savedId = (int) ($_GET['saved'] ?? 0);
$recentlyConfirmed = array_filter($all, static fn($e) => $e['status'] === 'scheduled' && $e['client_response'] === 'confirmed' && $e['responded_at'] && strtotime($e['responded_at']) >= time() - 2 * 86400);
$clientList = $canManage ? rows('SELECT id,client_name FROM clients WHERE deleted_at IS NULL AND is_active=1 ORDER BY client_name') : [];

/** Client response cell / pill (the most important thing for the MD to see). */
$responseHtml = static function (array $e, bool $forClient): string {
    if ($e['status'] !== 'scheduled') return '<span class="cm-resp is-' . h($e['status']) . '">' . h(CM_STATUSES[$e['status']]) . '</span>';
    $when = $e['responded_at'] ? '<small>' . h(date('j M, g:i A', strtotime($e['responded_at']))) . '</small>' : '';
    $fresh = $e['responded_at'] && strtotime($e['responded_at']) >= time() - 2 * 86400 ? ' <em class="cm-new">New</em>' : '';
    if ($e['client_response'] === 'confirmed') return '<span class="cm-resp is-confirmed">✓ ' . ($forClient ? 'You confirmed' : 'Confirmed by client') . $fresh . '</span>' . $when;
    if ($e['client_response'] === 'change_requested') return '<span class="cm-resp is-change_requested">⚠ Change requested' . $fresh . '</span>' . $when . ($e['client_note'] !== '' ? '<q class="cm-quote">' . h($e['client_note']) . '</q>' : '');
    return '<span class="cm-resp is-pending">⏳ ' . ($forClient ? 'Please confirm' : 'Waiting for client') . '</span>' . ($e['client_user_id'] ? '' : '<small>No portal login — send on WhatsApp</small>');
};
$waFor = static function (array $e): ?string {
    $text = 'Hello ' . ($e['contact_person'] ?: $e['client_name']) . ', greetings from Bhavi!' . "\n\n" . cm_title($e) . "\n" . cm_summary($e) . ($e['notes'] !== '' ? "\nNote: " . $e['notes'] : '') . "\n\nPlease confirm in your client portal (Meetings & shoots). Thank you!";
    return cm_wa_link($e['whatsapp'] ?: $e['phone'], $text);
};

page_start('Meetings & shoots', 'client-meetings.php');
error_message($error);
?>
<div class="ot-page cm-page">
    <header class="ot-head cm-head">
        <div>
            <p class="ot-crumb"><?= $isClient ? 'Your workspace' : 'Dashboard' ?> / Meetings &amp; shoots</p>
            <h1 class="ot-title">Meetings &amp; shoots</h1>
            <p class="ot-sub"><?= $isClient ? 'Your planned meetings and shoot days with our team. Please confirm, or tell us if something should change.' : 'Every client meeting and shoot in one place. Clients confirm in their portal — their answer shows here.' ?></p>
        </div>
        <?php if ($canManage): ?><a class="ot-gold cm-new-btn" href="client-meeting-form.php<?= $filterClient ? '?client=' . $filterClient : '' ?>"><?= icon('plus') ?> New meeting / shoot</a><?php endif; ?>
    </header>

<?php if ($canManage): ?>
    <?php if ($counts['change']): ?><a class="cm-banner is-change_requested" href="client-meetings.php?tab=change">⚠ <b><?= $counts['change'] ?></b> client<?= $counts['change'] > 1 ? 's' : '' ?> asked for a change — open them, click Edit and save the new plan. →</a><?php endif; ?>
    <?php if ($recentlyConfirmed): ?><div class="cm-banner is-confirmed">✓ Recently confirmed: <?= implode(', ', array_map(static fn($e) => '<a href="#plan-' . (int) $e['id'] . '">' . h($e['client_name']) . ' · ' . h(cm_title($e)) . '</a>', array_slice(array_values($recentlyConfirmed), 0, 5))) ?></div><?php endif; ?>

    <nav class="cm-tabs" aria-label="Filter plans">
        <?php foreach ($tabs as $key => [$label]): ?>
        <a href="?tab=<?= h($key) ?><?= $filterClient ? '&amp;client=' . $filterClient : '' ?>" class="cm-tab is-<?= h($key) ?><?= $tab === $key ? ' active' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?> <b><?= $counts[$key] ?></b></a>
        <?php endforeach; ?>
    </nav>

    <section class="ot-card cm-table-card">
        <div class="ot-card-head">
            <h2><?= h($tabs[$tab][0]) ?> <span>(<?= count($list) ?>)</span></h2>
            <form method="get" class="cm-filter"><input type="hidden" name="tab" value="<?= h($tab) ?>"><select name="client" onchange="this.form.submit()" aria-label="Filter by client"><option value="">All clients</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filterClient === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select><noscript><button type="submit">Filter</button></noscript></form>
        </div>
        <?php if (!$list): ?>
            <p class="ot-help cm-empty"><?= $all ? 'Nothing in this list.' : 'No meetings or shoots yet.' ?> <a href="client-meeting-form.php">+ Plan the first one</a></p>
        <?php else: ?>
        <div class="cm-table-wrap">
        <table class="cm-table">
            <thead><tr><th>Client</th><th>Meeting</th><th>Shoot</th><th>Client response</th><th class="cm-th-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($list as $e): $isMeet = $e['meeting_type'] === 'google_meet' && $e['meeting_place'] !== ''; $past = $e['status'] === 'scheduled' && cm_is_past($e); $wa = $waFor($e); ?>
            <tr id="plan-<?= (int) $e['id'] ?>" class="is-<?= h($e['status']) ?><?= $past ? ' is-past' : '' ?><?= $savedId === (int) $e['id'] ? ' is-saved' : '' ?>">
                <td data-label="Client">
                    <div class="cm-client">
                        <span class="cm-avatar"><?php if ($e['logo_path']): ?><img src="client-logo.php?id=<?= (int) $e['client_id'] ?>" alt="" onerror="this.remove()"><?php endif; ?><b><?= h(cm_initials($e['client_name'])) ?></b></span>
                        <div><strong><?= h($e['client_name']) ?></strong><small><?= h(cm_title($e)) ?><?= $e['notes'] !== '' ? ' · <span class="cm-has-note" title="Has notes — click 👁 to read">📝</span>' : '' ?></small><?php if ($e['creator']): ?><small>by <?= h($e['creator']) ?></small><?php endif; ?></div>
                    </div>
                </td>
                <td data-label="Meeting">
                    <?php if ($e['meeting_type'] === 'none'): ?><span class="cm-none">No meeting</span><?php else: ?>
                    <strong class="cm-type"><?= icon($e['meeting_type'] === 'google_meet' ? 'video' : 'people') ?><?= h(cm_meeting_label($e)) ?></strong>
                    <small><?= h(cm_when($e['meeting_at'])) ?></small>
                    <?php if ($isMeet): ?><?php if (!$past && $e['status'] === 'scheduled'): ?><a class="cm-join" href="<?= h($e['meeting_place']) ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a><?php endif; ?><?php elseif ($e['meeting_place'] !== ''): ?><small><?= h($e['meeting_place']) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td data-label="Shoot">
                    <?php if ($e['shoot_type'] === 'none'): ?><span class="cm-none">No shoot</span><?php else: ?>
                    <strong class="cm-type is-shoot"><?= icon('camera') ?><?= h(cm_shoot_label($e)) ?></strong>
                    <small><?= h(cm_when($e['shoot_at'])) ?></small>
                    <?php if ($e['shoot_place'] !== ''): ?><small><?= h($e['shoot_place']) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td data-label="Client response" class="cm-resp-cell"><?= $responseHtml($e, false) ?><?php if ($past): ?><small class="cm-pastnote">Date is over — mark done?</small><?php endif; ?></td>
                <td data-label="Actions">
                    <div class="cm-row-actions">
                        <a class="cm-icon-btn is-view" href="client-meeting-view.php?id=<?= (int) $e['id'] ?>" title="View full details" aria-label="View details of <?= h($e['client_name']) ?>"><?= icon('eye') ?></a>
                        <a class="cm-icon-btn" href="client-meeting-form.php?id=<?= (int) $e['id'] ?>" title="Edit" aria-label="Edit plan for <?= h($e['client_name']) ?>"><?= icon('edit') ?></a>
                        <form method="post" data-confirm="Delete this plan for <?= h($e['client_name']) ?>? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="back" value="<?= h($tab) ?>"><button class="cm-icon-btn is-del" type="submit" title="Delete" aria-label="Delete plan for <?= h($e['client_name']) ?>"><?= icon('delete') ?></button></form>
                        <details class="cm-more"><summary aria-label="More actions"><?= icon('more') ?></summary>
                            <div class="cm-more-menu">
                                <?php if ($wa): ?><a href="<?= h($wa) ?>" target="_blank" rel="noopener noreferrer"><?= icon('send') ?> Send on WhatsApp</a><?php endif; ?>
                                <?php if ($e['meeting_at']): ?><a href="<?= h(cm_gcal($e['client_name'] . ' – Meeting', $e['meeting_at'], CM_MEETING_MINUTES, cm_summary($e), $e['meeting_place'])) ?>" target="_blank" rel="noopener noreferrer"><?= icon('calendar') ?> Meeting to calendar</a><?php endif; ?>
                                <?php if ($e['shoot_at']): ?><a href="<?= h(cm_gcal($e['client_name'] . ' – Shoot', $e['shoot_at'], CM_SHOOT_MINUTES, cm_summary($e), $e['shoot_place'])) ?>" target="_blank" rel="noopener noreferrer"><?= icon('calendar') ?> Shoot to calendar</a><?php endif; ?>
                                <?php if ($e['status'] === 'scheduled'): ?>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="back" value="<?= h($tab) ?>"><button type="submit"><?= icon('check') ?> Mark as done</button></form>
                                <form method="post" data-confirm="Cancel this plan? The client will be told."><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="cancelled"><input type="hidden" name="back" value="<?= h($tab) ?>"><button type="submit">✕ Cancel plan</button></form>
                                <?php else: ?>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="scheduled"><input type="hidden" name="back" value="<?= h($tab) ?>"><button type="submit">↺ Re-open</button></form>
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

<?php else: /* ---------- Client view ---------- */
    $open = array_values(array_filter($all, static fn($e) => $e['status'] === 'scheduled' && !cm_is_past($e)));
    $done = array_values(array_filter($all, static fn($e) => !($e['status'] === 'scheduled' && !cm_is_past($e))));
    $waiting = count(array_filter($open, static fn($e) => $e['client_response'] === 'pending'));
?>
    <?php if ($waiting): ?><p class="cm-banner is-pending">⏳ <b><?= $waiting ?></b> plan<?= $waiting > 1 ? 's are' : ' is' ?> waiting for your confirmation.</p><?php endif; ?>
    <section class="ot-card">
        <div class="ot-card-head"><h2>Upcoming</h2><span><?= count($open) ?></span></div>
        <?php if (!$open): ?><p class="ot-help">Nothing planned right now. Our team will add your next meeting or shoot here.</p><?php endif; ?>
        <div class="cm-list">
        <?php foreach ($open as $e): $isMeet = $e['meeting_type'] === 'google_meet' && $e['meeting_place'] !== ''; ?>
            <article class="cm-item" id="plan-<?= (int) $e['id'] ?>">
                <div class="cm-item-head">
                    <div class="cm-item-title"><strong><?= h(cm_title($e)) ?></strong><small>Planned by <?= h($e['creator'] ?: 'our team') ?></small></div>
                    <div class="cm-resp-cell"><?= $responseHtml($e, true) ?></div>
                </div>
                <div class="cm-parts">
                    <?php if ($e['meeting_type'] !== 'none'): ?>
                    <div class="cm-part"><span class="cm-part-icon"><?= icon($e['meeting_type'] === 'google_meet' ? 'video' : 'people') ?></span><div>
                        <em>Meeting</em><strong><?= h(cm_meeting_label($e)) ?></strong><small><?= h(cm_when($e['meeting_at'])) ?></small>
                        <?php if ($e['meeting_place'] !== '' && !$isMeet): ?><small><?= h($e['meeting_place']) ?></small><?php endif; ?>
                        <div class="cm-links"><?php if ($isMeet): ?><a class="cm-join" href="<?= h($e['meeting_place']) ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a><?php endif; ?><?php if ($e['meeting_at']): ?><a href="<?= h(cm_gcal(cm_title($e) . ' – Meeting', $e['meeting_at'], CM_MEETING_MINUTES, cm_summary($e), $e['meeting_place'])) ?>" target="_blank" rel="noopener noreferrer">Add to calendar</a><?php endif; ?></div>
                    </div></div>
                    <?php endif; ?>
                    <?php if ($e['shoot_type'] !== 'none'): ?>
                    <div class="cm-part"><span class="cm-part-icon is-shoot"><?= icon('camera') ?></span><div>
                        <em>Shoot</em><strong><?= h(cm_shoot_label($e)) ?></strong><small><?= h(cm_when($e['shoot_at'])) ?></small>
                        <?php if ($e['shoot_place'] !== ''): ?><small><?= h($e['shoot_place']) ?></small><?php endif; ?>
                        <?php if ($e['shoot_at']): ?><div class="cm-links"><a href="<?= h(cm_gcal(cm_title($e) . ' – Shoot', $e['shoot_at'], CM_SHOOT_MINUTES, cm_summary($e), $e['shoot_place'])) ?>" target="_blank" rel="noopener noreferrer">Add to calendar</a></div><?php endif; ?>
                    </div></div>
                    <?php endif; ?>
                </div>
                <?php if ($e['notes'] !== ''): ?><p class="cm-notes"><?= nl2br(h($e['notes'])) ?></p><?php endif; ?>
                <div class="cm-actions">
                    <?php if ($e['client_response'] !== 'confirmed'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="respond"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="response" value="confirmed"><button class="ot-gold" type="submit"><?= icon('check') ?> Confirm</button></form>
                    <?php endif; ?>
                    <details class="cm-change"><summary>Request a change</summary>
                        <form method="post" class="ot-form"><?= csrf_field() ?><input type="hidden" name="action" value="respond"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="response" value="change_requested">
                            <label><span>What should we change?</span><textarea name="client_note" maxlength="1000" rows="3" required placeholder="e.g. Please move the shoot to Saturday morning"></textarea></label>
                            <div class="ot-actions"><button class="ot-dark" type="submit">Send request</button></div>
                        </form>
                    </details>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
        <?php if ($done): ?>
        <details class="zm-past"><summary>History (<?= count($done) ?>)</summary>
            <div class="cm-list"><?php foreach ($done as $e): ?>
                <article class="cm-item is-past"><div class="cm-item-head"><div class="cm-item-title"><strong><?= h(cm_title($e)) ?></strong><small><?= h(implode(' · ', array_filter([$e['meeting_type'] !== 'none' ? cm_meeting_label($e) . ' ' . cm_when($e['meeting_at']) : '', $e['shoot_type'] !== 'none' ? 'Shoot ' . cm_when($e['shoot_at']) : '']))) ?></small></div><span class="cm-resp is-<?= h($e['status']) ?>"><?= h(CM_STATUSES[$e['status']]) ?></span></div></article>
            <?php endforeach; ?></div>
        </details>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
<script>
document.addEventListener('click', function (e) { document.querySelectorAll('.cm-more[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
</script>
<?php page_end(); ?>
