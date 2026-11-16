<?php
// One client meeting & shoot, everything on one page (opened with the 👁 button on Meetings & shoots).
require_once __DIR__ . '/includes/client-meetings.php';
$user = require_roles(['admin', 'manager']);
$id = (int) ($_GET['id'] ?? 0);
$e = $id > 0 ? one('SELECT e.*,c.client_name,c.logo_path,c.whatsapp,c.phone,c.email,c.contact_person,c.contact_designation,c.city,c.address,c.user_id AS client_user_id,u.full_name AS creator FROM client_engagements e JOIN clients c ON c.id=e.client_id LEFT JOIN users u ON u.id=e.created_by WHERE e.id=? AND e.deleted_at IS NULL', [$id]) : null;
if (!$e) { flash('That plan was not found or was deleted.', 'error'); redirect('client-meetings.php'); }

$isMeet = $e['meeting_type'] === 'google_meet' && $e['meeting_place'] !== '';
$past = $e['status'] === 'scheduled' && cm_is_past($e);
$phone = $e['whatsapp'] ?: $e['phone'];
$wa = cm_wa_link($phone, 'Hello ' . ($e['contact_person'] ?: $e['client_name']) . ', greetings from Bhavi!' . "\n\n" . cm_title($e) . "\n" . cm_summary($e) . ($e['notes'] !== '' ? "\nNote: " . $e['notes'] : '') . "\n\nPlease confirm in your client portal (Meetings & shoots). Thank you!");
// What else the team has on the same days (other clients).
$others = array_filter(cm_busy_slots((int) $e['id']), static function ($x) use ($e) {
    $day = date('Y-m-d', $x['start']);
    return ($e['meeting_at'] && $day === date('Y-m-d', strtotime($e['meeting_at']))) || ($e['shoot_at'] && $day === date('Y-m-d', strtotime($e['shoot_at'])));
});
$responseText = match (true) {
    $e['status'] !== 'scheduled' => CM_STATUSES[$e['status']],
    $e['client_response'] === 'confirmed' => '✓ Confirmed by client',
    $e['client_response'] === 'change_requested' => '⚠ Change requested',
    default => '⏳ Waiting for client',
};
$responseClass = $e['status'] !== 'scheduled' ? $e['status'] : $e['client_response'];
$field = static function (string $label, string $value, bool $html = false): void {
    if ($value === '') return;
    echo '<div class="cmv-field"><dt>' . h($label) . '</dt><dd>' . ($html ? $value : h($value)) . '</dd></div>';
};

page_start('Meeting & shoot details', 'client-meetings.php');
?>
<div class="ot-page cm-page">
    <header class="ot-head cm-head">
        <div>
            <p class="ot-crumb"><a href="client-meetings.php">Meetings &amp; shoots</a> / Details</p>
            <h1 class="ot-title"><?= h(cm_title($e)) ?></h1>
            <p class="ot-sub"><?= h($e['client_name']) ?> · planned by <?= h($e['creator'] ?: '—') ?> on <?= h(date('j M Y, g:i A', strtotime($e['created_at']))) ?></p>
        </div>
        <a class="cm-back" href="client-meetings.php">← All meetings &amp; shoots</a>
    </header>

    <section class="ot-card cmv-status is-<?= h($responseClass) ?>">
        <div class="cmv-status-main">
            <span class="cm-resp is-<?= h($responseClass) ?>"><?= h($responseText) ?></span>
            <?php if ($e['responded_at']): ?><small>Client answered on <?= h(date('D, j M Y · g:i A', strtotime($e['responded_at']))) ?></small><?php elseif ($e['status'] === 'scheduled' && !$e['client_user_id']): ?><small>This client has no portal login — send the plan on WhatsApp.</small><?php endif; ?>
            <?php if ($past): ?><small class="cm-pastnote">The date is over — mark it as done.</small><?php endif; ?>
        </div>
        <?php if ($e['client_note'] !== ''): ?><q class="cm-quote"><?= h($e['client_note']) ?></q><?php endif; ?>
        <div class="cmv-actions">
            <a class="cm-btn" href="client-meeting-form.php?id=<?= (int) $e['id'] ?>"><?= icon('edit') ?> Edit</a>
            <?php if ($wa): ?><a class="cm-btn is-wa" href="<?= h($wa) ?>" target="_blank" rel="noopener noreferrer"><?= icon('send') ?> Send on WhatsApp</a><?php endif; ?>
            <?php if ($e['status'] === 'scheduled'): ?>
            <form method="post" action="client-meetings.php"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="completed"><button class="cm-btn is-done" type="submit"><?= icon('check') ?> Mark as done</button></form>
            <form method="post" action="client-meetings.php" data-confirm="Cancel this plan? The client will be told."><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="cm-btn" type="submit">✕ Cancel plan</button></form>
            <?php else: ?>
            <form method="post" action="client-meetings.php"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="status" value="scheduled"><button class="cm-btn" type="submit">↺ Re-open</button></form>
            <?php endif; ?>
            <form method="post" action="client-meetings.php" data-confirm="Delete this plan for <?= h($e['client_name']) ?>? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="cm-btn is-del" type="submit"><?= icon('delete') ?> Delete</button></form>
        </div>
    </section>

    <div class="cmv-grid">
        <section class="ot-card">
            <div class="cmv-sec-head"><span class="cm-part-icon"><?= icon($e['meeting_type'] === 'google_meet' ? 'video' : 'people') ?></span><h2>Meeting</h2></div>
            <?php if ($e['meeting_type'] === 'none'): ?><p class="cm-none">No meeting planned.</p><?php else: ?>
            <dl class="cmv-list">
                <?php $field('Type', cm_meeting_label($e)); ?>
                <?php $field('Date & time', cm_when($e['meeting_at'])); ?>
                <?php if ($isMeet): $field('Google Meet link', '<a href="' . h($e['meeting_place']) . '" target="_blank" rel="noopener noreferrer">' . h($e['meeting_place']) . '</a>', true); else: $field($e['meeting_type'] === 'live' ? 'Place / address' : 'Link or place', $e['meeting_place']); endif; ?>
            </dl>
            <div class="cm-links">
                <?php if ($isMeet && $e['status'] === 'scheduled' && !$past): ?><a class="cm-join" href="<?= h($e['meeting_place']) ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a><?php endif; ?>
                <?php if ($e['meeting_at']): ?><a href="<?= h(cm_gcal($e['client_name'] . ' – Meeting', $e['meeting_at'], CM_MEETING_MINUTES, cm_summary($e), $e['meeting_place'])) ?>" target="_blank" rel="noopener noreferrer">Add to Google Calendar</a><?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="ot-card">
            <div class="cmv-sec-head"><span class="cm-part-icon is-shoot"><?= icon('camera') ?></span><h2>Shoot</h2></div>
            <?php if ($e['shoot_type'] === 'none'): ?><p class="cm-none">No shoot planned.</p><?php else: ?>
            <dl class="cmv-list">
                <?php $field('Who will shoot', cm_shoot_label($e)); ?>
                <?php $field('Date & time', cm_when($e['shoot_at'])); ?>
                <?php $field('Location', $e['shoot_place']); ?>
            </dl>
            <?php if ($e['shoot_at']): ?><div class="cm-links"><a href="<?= h(cm_gcal($e['client_name'] . ' – Shoot', $e['shoot_at'], CM_SHOOT_MINUTES, cm_summary($e), $e['shoot_place'])) ?>" target="_blank" rel="noopener noreferrer">Add to Google Calendar</a></div><?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="ot-card">
            <div class="cmv-sec-head">
                <span class="cm-avatar"><?php if ($e['logo_path']): ?><img src="client-logo.php?id=<?= (int) $e['client_id'] ?>" alt="" onerror="this.remove()"><?php endif; ?><b><?= h(cm_initials($e['client_name'])) ?></b></span>
                <h2><?= h($e['client_name']) ?></h2>
            </div>
            <dl class="cmv-list">
                <?php $field('Contact person', trim($e['contact_person'] . ($e['contact_designation'] ? ' · ' . $e['contact_designation'] : ''))); ?>
                <?php if ($phone): $field('Phone / WhatsApp', '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $phone)) . '">' . h($phone) . '</a>', true); endif; ?>
                <?php if ($e['email']): $field('Email', '<a href="mailto:' . h($e['email']) . '">' . h($e['email']) . '</a>', true); endif; ?>
                <?php $field('Address', trim($e['address'] . ($e['city'] && !str_contains($e['address'], $e['city']) ? ', ' . $e['city'] : ''), ', ')); ?>
                <?php $field('Portal login', $e['client_user_id'] ? 'Yes — the client sees this plan' : 'No portal login'); ?>
            </dl>
            <div class="cm-links"><a href="client-profile.php?id=<?= (int) $e['client_id'] ?>">Open client profile →</a></div>
        </section>

        <section class="ot-card">
            <div class="cmv-sec-head"><span class="cm-part-icon"><?= icon('document') ?></span><h2>Notes &amp; history</h2></div>
            <?php if ($e['notes'] !== ''): ?><p class="cm-notes"><?= nl2br(h($e['notes'])) ?></p><?php else: ?><p class="cm-none">No notes for the client.</p><?php endif; ?>
            <dl class="cmv-list">
                <?php $field('Planned by', ($e['creator'] ?: '—') . ' · ' . date('j M Y, g:i A', strtotime($e['created_at']))); ?>
                <?php $field('Last updated', date('j M Y, g:i A', strtotime($e['updated_at']))); ?>
                <?php $field('Status', CM_STATUSES[$e['status']] ?? $e['status']); ?>
            </dl>
        </section>
    </div>

    <section class="ot-card">
        <div class="ot-card-head"><h2>Other clients booked on the same day</h2><span><?= count($others) ?></span></div>
        <?php if (!$others): ?><p class="cm-clash-ok cmv-free">✓ No other client meeting or shoot on these days.</p><?php else: ?>
        <ul class="cmv-others">
            <?php foreach ($others as $x): ?>
            <li><span class="cm-resp is-<?= $x['kind'] === 'shoot' ? 'pending' : 'scheduled' ?>"><?= h(ucfirst($x['kind'])) ?></span><b><?= h($x['client']) ?></b><span><?= h($x['label']) ?> · <?= h(date('D, j M · g:i A', $x['start'])) ?></span><a href="client-meeting-view.php?id=<?= (int) $x['id'] ?>">View</a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
<?php page_end(); ?>
