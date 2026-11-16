<?php
// New / edit a client meeting & shoot (Managing Director and managers).
// Client dropdown → Meeting (Live / Google Meet / Other / none) → Shoot (we / client / Other / none) → notes → save.
// Saving goes back to the Meetings & shoots list, where every plan is shown with Edit / Delete.
require_once __DIR__ . '/includes/client-meetings.php';
$user = require_roles(['admin', 'manager']);
$error = null;

$editId = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$editing = $editId > 0 ? one('SELECT * FROM client_engagements WHERE id=? AND deleted_at IS NULL', [$editId]) : null;
if ($editId > 0 && !$editing) { flash('That plan was not found or was deleted.', 'error'); redirect('client-meetings.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $client = one('SELECT id,client_name,user_id FROM clients WHERE id=? AND deleted_at IS NULL', [filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT) ?: 0]);
        if (!$client) throw new InvalidArgumentException('Select a client.');

        $meetingType = (string) ($_POST['meeting_type'] ?? '');
        $shootType = (string) ($_POST['shoot_type'] ?? '');
        if (!isset(CM_MEETING_TYPES[$meetingType])) throw new InvalidArgumentException('Choose the meeting type.');
        if (!isset(CM_SHOOT_TYPES[$shootType])) throw new InvalidArgumentException('Choose who will shoot.');
        if ($meetingType === 'none' && $shootType === 'none') throw new InvalidArgumentException('Plan at least a meeting or a shoot.');

        $meetingOther = $meetingType === 'other' ? optional_text('meeting_other', 150) : '';
        if ($meetingType === 'other' && $meetingOther === '') throw new InvalidArgumentException('Type the meeting type (you chose “Other”).');
        $shootOther = $shootType === 'other' ? optional_text('shoot_other', 150) : '';
        if ($shootType === 'other' && $shootOther === '') throw new InvalidArgumentException('Type who will shoot (you chose “Other”).');

        $meetingAt = $meetingType !== 'none' ? cm_datetime('meeting_at', true, 'meeting') : null;
        $meetingPlace = $meetingType !== 'none' ? optional_text('meeting_place', 2048) : '';
        if ($meetingType === 'google_meet' && $meetingPlace !== '') {
            if (!preg_match('#^https?://#i', $meetingPlace)) $meetingPlace = 'https://' . $meetingPlace;
            if (!filter_var($meetingPlace, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Paste a valid Google Meet link, for example https://meet.google.com/abc-defg-hij');
        }
        $shootAt = $shootType !== 'none' ? cm_datetime('shoot_at', false, 'shoot') : null;
        $shootPlace = $shootType !== 'none' ? optional_text('shoot_place', 500) : '';
        $meetingMinutes = CM_MEETING_MINUTES; // fixed: a meeting blocks 1 hour, a shoot 3 hours (no duration field)
        $shootMinutes = CM_SHOOT_MINUTES;
        $title = optional_text('title', 150);
        $notes = optional_text('notes', 2000);

        // Another client already booked at this time? Block unless "Book anyway" is ticked.
        if (empty($_POST['allow_overlap'])) {
            $slots = cm_busy_slots($editing ? (int) $editing['id'] : 0);
            $clashes = [];
            if ($meetingAt) $clashes = array_merge($clashes, cm_overlaps($slots, $meetingAt, $meetingMinutes));
            if ($shootAt && $shootType !== 'client') $clashes = array_merge($clashes, cm_overlaps($slots, $shootAt, $shootMinutes));
            if ($clashes) throw new InvalidArgumentException(implode(' | ', array_unique(array_map('cm_slot_text', $clashes))) . '. Choose another time, or tick “Book anyway”.');
        }

        $plan = ['client_id' => $client['id'], 'meeting_type' => $meetingType, 'meeting_other' => $meetingOther, 'meeting_at' => $meetingAt, 'meeting_place' => $meetingPlace, 'shoot_type' => $shootType, 'shoot_other' => $shootOther, 'shoot_at' => $shootAt, 'shoot_place' => $shootPlace];
        $fields = [$client['id'], $title, $meetingType, $meetingOther, $meetingAt, $meetingPlace, $shootType, $shootOther, $shootAt, $shootPlace, $notes];
        db()->beginTransaction();
        if ($editing) {
            // Changing the client, type, date or place asks the client to confirm again.
            $changed = false;
            foreach ($plan as $k => $v) if ((string) $editing[$k] !== (string) $v) { $changed = true; break; }
            query('UPDATE client_engagements SET client_id=?,title=?,meeting_type=?,meeting_other=?,meeting_at=?,meeting_place=?,shoot_type=?,shoot_other=?,shoot_at=?,shoot_place=?,notes=?' . ($changed ? ",client_response='pending',responded_at=NULL" : '') . ' WHERE id=?', array_merge($fields, [$editing['id']]));
            $savedId = (int) $editing['id'];
        } else {
            query('INSERT INTO client_engagements (client_id,title,meeting_type,meeting_other,meeting_at,meeting_place,shoot_type,shoot_other,shoot_at,shoot_place,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($fields, [$user['id']]));
            $savedId = (int) db()->lastInsertId();
        }
        if (!empty($_POST['notify']) && $client['user_id']) {
            cm_notify((int) $client['user_id'], (int) $user['id'], CM_NOTICE_PREFIX . ($editing ? ' updated: ' : ' planned: ') . ($title !== '' ? $title : 'please confirm'), cm_summary($plan) . "\nOpen Meetings & shoots and tap Confirm.");
        }
        db()->commit();
        flash($editing ? 'Plan updated.' : 'Saved. ' . ($client['user_id'] ? 'The client can now see it and confirm.' : 'This client has no portal login yet — send it on WhatsApp from the list.'));
        redirect('client-meetings.php?saved=' . $savedId . '#plan-' . $savedId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
    }
}

$clientList = rows('SELECT id,client_name,contact_person FROM clients WHERE deleted_at IS NULL AND is_active=1 ORDER BY client_name');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = static function (string $k, string $d = '') use ($isPost, $editing): string {
    if ($isPost) return (string) ($_POST[$k] ?? $d);
    if ($editing && array_key_exists($k, $editing)) {
        $v = (string) ($editing[$k] ?? '');
        return in_array($k, ['meeting_at', 'shoot_at'], true) && $v !== '' ? date('Y-m-d\TH:i', strtotime($v)) : $v;
    }
    return $d;
};
$meetingType = $val('meeting_type', 'live');
$shootType = $val('shoot_type', 'our_team');
// Booked slots of other plans, for the live "another client in this time" warning.
$busyJson = array_map(static fn($x) => ['kind' => $x['kind'], 'start' => $x['start'], 'end' => $x['end'], 'day' => date('Y-m-d', $x['start']), 'text' => $x['client'] . ' · ' . $x['label'] . ' · ' . date('g:i A', $x['start'])], cm_busy_slots($editing ? (int) $editing['id'] : 0));
$pageTitle = $editing ? 'Edit meeting & shoot' : 'New meeting & shoot';

page_start($pageTitle, 'client-meetings.php');
error_message($error);
?>
<div class="ot-page cm-page">
    <header class="ot-head cm-head">
        <div>
            <p class="ot-crumb"><a href="client-meetings.php">Meetings &amp; shoots</a> / <?= $editing ? 'Edit' : 'New' ?></p>
            <h1 class="ot-title"><?= h($pageTitle) ?></h1>
            <p class="ot-sub">Choose the client, how you will meet and who will shoot. The client sees it in their portal and confirms.</p>
            <p class="ot-help cm-rule">If another client already has a meeting or shoot at that time, you will see a warning. A meeting keeps the team busy for 1 hour and a shoot for 3 hours from its start time.</p>
        </div>
        <a class="cm-back" href="client-meetings.php">← All meetings &amp; shoots</a>
    </header>

    <?php if ($editing && $editing['client_response'] !== 'pending'): ?>
    <p class="cm-banner is-<?= h($editing['client_response']) ?>"><?= $editing['client_response'] === 'confirmed' ? '✓ The client already confirmed this plan. If you change the client, date, type or place, they will be asked to confirm again.' : '⚠ The client asked for a change: “' . h($editing['client_note']) . '”. Update the plan below and save; the client will be asked to confirm again.' ?></p>
    <?php endif; ?>

    <section class="ot-card cm-form-card">
        <?php if (!$clientList): ?><p class="ot-help">Add a client first in <a href="add-client.php">Projects &amp; Clients</a>.</p><?php else: ?>
        <form method="post" class="ot-form cm-form" autocomplete="off"><?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
            <div class="cm-row">
                <div class="cm-row-label"><span class="cm-step">1</span><b>Client</b><small>Who is this for?</small></div>
                <div class="cm-row-body cm-two">
                    <label><span>Select client *</span><select name="client_id" required>
                        <option value="">— Select client —</option>
                        <?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $val('client_id', (string) (int) ($_GET['client'] ?? 0)) === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['client_name']) ?><?= $c['contact_person'] ? ' · ' . h($c['contact_person']) : '' ?></option><?php endforeach; ?>
                    </select></label>
                    <label><span>Title / purpose <small>(optional)</small></span><input name="title" maxlength="150" value="<?= h($val('title')) ?>" placeholder="e.g. December content planning"></label>
                </div>
            </div>

            <div class="cm-row" data-cm-group="meeting">
                <div class="cm-row-label"><span class="cm-step">2</span><b>Meeting</b><small>How will we meet?</small></div>
                <div class="cm-row-body">
                    <div class="cm-pills" role="radiogroup" aria-label="Meeting type">
                        <?php foreach (CM_MEETING_TYPES as $k => $label): ?>
                        <label class="cm-pill"><input type="radio" name="meeting_type" value="<?= h($k) ?>"<?= $meetingType === $k ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="cm-other" data-show-for="other"><span>Type the meeting type *</span><input name="meeting_other" maxlength="150" value="<?= h($val('meeting_other')) ?>" placeholder="e.g. Zoom call, Phone call, At client's shop"></label>
                    <div class="cm-two" data-hide-for="none">
                        <label><span>Meeting date &amp; time *</span><input type="datetime-local" name="meeting_at" data-clash-input="meeting" value="<?= h($val('meeting_at', date('Y-m-d\T11:00', strtotime('+1 day')))) ?>"></label>
                        <label><span data-place-label data-live="Meeting place / address" data-google_meet="Google Meet link" data-other="Link or place"><?= $meetingType === 'google_meet' ? 'Google Meet link' : 'Meeting place / address' ?></span><input name="meeting_place" maxlength="2048" value="<?= h($val('meeting_place')) ?>" placeholder="<?= $meetingType === 'google_meet' ? 'https://meet.google.com/abc-defg-hij' : 'e.g. Our office, Hyderabad' ?>" data-live-ph="e.g. Our office, Hyderabad" data-google_meet-ph="https://meet.google.com/abc-defg-hij" data-other-ph="Link or address"></label>
                    </div>
                    <div class="cm-clash" data-clash-box="meeting" hidden></div>
                </div>
            </div>

            <div class="cm-row" data-cm-group="shoot">
                <div class="cm-row-label"><span class="cm-step">3</span><b>Shoot</b><small>Who will shoot the content?</small></div>
                <div class="cm-row-body">
                    <div class="cm-pills" role="radiogroup" aria-label="Who will shoot">
                        <?php foreach (CM_SHOOT_TYPES as $k => $label): ?>
                        <label class="cm-pill"><input type="radio" name="shoot_type" value="<?= h($k) ?>"<?= $shootType === $k ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="cm-other" data-show-for="other"><span>Type who will shoot *</span><input name="shoot_other" maxlength="150" value="<?= h($val('shoot_other')) ?>" placeholder="e.g. Freelance photographer, Partner studio"></label>
                    <div class="cm-two" data-hide-for="none">
                        <label><span>Shoot date &amp; time <small>(if fixed)</small></span><input type="datetime-local" name="shoot_at" data-clash-input="shoot" value="<?= h($val('shoot_at')) ?>"></label>
                        <label><span>Shoot location</span><input name="shoot_place" maxlength="500" value="<?= h($val('shoot_place')) ?>" placeholder="e.g. Client's store, Ameerpet"></label>
                    </div>
                    <div class="cm-clash" data-clash-box="shoot" hidden></div>
                </div>
            </div>

            <div class="cm-row">
                <div class="cm-row-label"><span class="cm-step">4</span><b>Notes</b><small>Shown to the client</small></div>
                <div class="cm-row-body">
                    <label><span>Agenda / what to keep ready <small>(optional)</small></span><textarea name="notes" maxlength="2000" rows="3" placeholder="e.g. Keep 5 products and the new menu ready. Wear brand colours."><?= h($val('notes')) ?></textarea></label>
                    <label class="zm-check"><input type="checkbox" name="notify" value="1" <?= !$isPost || !empty($_POST['notify']) ? 'checked' : '' ?>> Notify the client in their portal</label>
                    <label class="zm-check cm-anyway" data-anyway hidden><input type="checkbox" name="allow_overlap" value="1" <?= !empty($_POST['allow_overlap']) ? 'checked' : '' ?>> Book anyway — another team will handle the other client at this time</label>
                    <div class="ot-actions"><button class="ot-dark" type="submit"><?= $editing ? 'Save changes' : 'Submit &amp; save' ?></button><a href="client-meetings.php">Cancel</a></div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    document.querySelectorAll('[data-cm-group]').forEach(function (group) {
        function sync() {
            var checked = group.querySelector('input[type=radio]:checked'), v = checked ? checked.value : '';
            group.querySelectorAll('[data-show-for]').forEach(function (el) {
                var on = el.dataset.showFor === v; el.hidden = !on;
                el.querySelectorAll('input').forEach(function (i) { i.required = on; });
            });
            group.querySelectorAll('[data-hide-for]').forEach(function (el) { el.hidden = el.dataset.hideFor === v; });
            if (group.dataset.cmGroup === 'meeting') {
                var at = group.querySelector('input[name=meeting_at]'); if (at) at.required = v !== 'none';
                var span = group.querySelector('[data-place-label]'), input = group.querySelector('input[name=meeting_place]');
                if (span && span.dataset[v]) span.textContent = span.dataset[v];
                if (input && input.getAttribute('data-' + v + '-ph')) input.placeholder = input.getAttribute('data-' + v + '-ph');
            }
        }
        group.querySelectorAll('input[type=radio]').forEach(function (r) { r.addEventListener('change', function () { sync(); var o = group.querySelector('[data-show-for]:not([hidden]) input'); if (o && r.value === 'other') o.focus(); }); });
        sync();
    });

    // "Another client meeting / shoot in this time": compare the chosen time with every other booked plan.
    var busy = <?= json_encode($busyJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var tzOffset = <?= (int) date('Z') ?>; // portal time zone (Asia/Kolkata) in seconds from UTC
    var anyway = document.querySelector('[data-anyway]');
    function toEpoch(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || ''); if (!m) return null;
        return Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5]) / 1000 - tzOffset;
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function check() {
        var anyClash = false;
        ['meeting', 'shoot'].forEach(function (kind) {
            var group = document.querySelector('[data-cm-group="' + kind + '"]'), box = group.querySelector('[data-clash-box]');
            var type = (group.querySelector('input[type=radio]:checked') || {}).value;
            var input = group.querySelector('[data-clash-input]'), minutes = kind === 'meeting' ? <?= CM_MEETING_MINUTES ?> : <?= CM_SHOOT_MINUTES ?>;
            var start = toEpoch(input.value);
            if (type === 'none' || (kind === 'shoot' && type === 'client') || start === null) { box.hidden = true; return; }
            var end = start + minutes * 60, day = input.value.slice(0, 10);
            var clash = busy.filter(function (b) { return b.start < end && b.end > start; });
            var sameDay = busy.filter(function (b) { return b.day === day && clash.indexOf(b) < 0; });
            var html = '';
            if (clash.length) {
                anyClash = true;
                var kinds = clash.map(function (b) { return b.kind; }).filter(function (k, i, a) { return a.indexOf(k) === i; });
                html += '<p class="cm-clash-bad"><b>⚠ Another client ' + kinds.join(' / ') + ' in this time</b>' + clash.map(function (b) { return '<span>' + esc(b.text) + ' (' + b.kind + ')</span>'; }).join('') + '<small>Choose another time, or tick “Book anyway” below.</small></p>';
            }
            if (sameDay.length) html += '<p class="cm-clash-info"><b>Also booked on this day</b>' + sameDay.map(function (b) { return '<span>' + esc(b.text) + ' (' + b.kind + ')</span>'; }).join('') + '</p>';
            if (!clash.length && !sameDay.length) html = '<p class="cm-clash-ok">✓ This time is free — no other client booked.</p>';
            box.innerHTML = html; box.hidden = false;
        });
        if (anyway) anyway.hidden = !anyClash;
    }
    document.querySelectorAll('[data-clash-input],[data-cm-group] input[type=radio]').forEach(function (el) { el.addEventListener('input', check); el.addEventListener('change', check); });
    check();
})();
</script>
<?php page_end(); ?>
