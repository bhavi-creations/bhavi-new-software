<?php
// "Client meetings & shoots" card on the Managing Director / manager dashboard:
//  - change requests from clients (monthly meeting moves: Approve / Reject here; shoot changes: open to edit)
//  - every monthly meeting (with moved months applied) and every shoot between two dates, day by day.
require_once __DIR__ . '/monthly-meeting.php';
require_once __DIR__ . '/client-meetings.php';

/**
 * Monthly meetings and shoots from $from to $to (Y-m-d, inclusive), sorted by date and time.
 * @return list<array{date:string,time:string,kind:string,client_id:int,client:string,label:string,place:string,note:string,status:string,url:string}>
 */
function agenda_items(string $from, string $to): array
{
    $items = [];
    // Monthly meetings: the usual day of each month, or the moved day for months with an approved change.
    $clients = rows('SELECT id,client_name,meeting_day,meeting_time,meeting_mode,meeting_place FROM clients WHERE deleted_at IS NULL AND is_active=1 AND meeting_day IS NOT NULL');
    $overrides = [];
    foreach (rows("SELECT * FROM client_meeting_changes WHERE status='approved' AND months IS NOT NULL ORDER BY decided_at DESC,id DESC") as $o) $overrides[(int) $o['client_id']][] = $o;
    // Open change requests from clients: the months they cover get "Client asked to change" + Change the date.
    $asks = [];
    foreach (rows("SELECT * FROM client_meeting_changes WHERE status='pending' ORDER BY id DESC") as $q) $asks[(int) $q['client_id']][] = $q;
    foreach ($clients as $c) {
        $month = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = substr($to, 0, 7) . '-01';
        for ($guard = 0; $month->format('Y-m-01') <= $last && $guard < 36; $guard++, $month = $month->modify('+1 month')) {
            $o = mm_override_for($overrides[(int) $c['id']] ?? [], $month->format('Y-m-01'));
            $day = (int) ($o['meeting_day'] ?? $c['meeting_day']);
            $date = $month->format('Y-m-') . sprintf('%02d', min($day, (int) $month->format('t')));
            if ($date < $from || $date > $to) continue;
            $ask = null;
            foreach ($asks[(int) $c['id']] ?? [] as $q) {
                $askEnd = $q['months'] === null ? '9999-12-01' : date('Y-m-01', strtotime($q['start_month'] . ' +' . (int) $q['months'] . ' months'));
                if ($q['start_month'] <= $month->format('Y-m-01') && $month->format('Y-m-01') < $askEnd) { $ask = $q; break; }
            }
            $askText = '';
            if ($ask) {
                $askDate = date('Y-m-', strtotime($ask['start_month'])) . sprintf('%02d', min((int) $ask['meeting_day'], (int) date('t', strtotime($ask['start_month']))));
                $askText = 'Client wants ' . date('D, j M', strtotime($askDate)) . ' · ' . mm_time_label($ask['meeting_time']) . ' (' . mm_scope_label($ask) . ')' . ($ask['reason'] !== '' ? ' — “' . $ask['reason'] . '”' : '');
            }
            $items[] = ['date' => $date, 'time' => (string) ($o['meeting_time'] ?? $c['meeting_time'] ?? ''), 'kind' => 'meeting', 'client_id' => (int) $c['id'], 'client' => $c['client_name'],
                'label' => mm_mode_label($c), 'place' => (string) $c['meeting_place'], 'note' => $o ? 'Moved · ' . mm_scope_label($o) : 'Monthly meeting', 'status' => $ask ? 'change_requested' : ($o ? 'moved' : 'usual'),
                'url' => 'client-monthly-meeting.php?client=' . (int) $c['id'], 'ask' => $askText, 'change_url' => 'client-monthly-meeting.php?client=' . (int) $c['id']];
        }
    }
    // Shoots (and any meeting saved on a shoot plan) on those days. Cancelled plans are left out.
    $plans = rows("SELECT e.*,c.client_name FROM client_engagements e JOIN clients c ON c.id=e.client_id WHERE e.deleted_at IS NULL AND c.deleted_at IS NULL AND e.status<>'cancelled' AND ((e.shoot_type<>'none' AND e.shoot_at>=? AND e.shoot_at<?) OR (e.meeting_type<>'none' AND e.meeting_at>=? AND e.meeting_at<?))", [$from, date('Y-m-d', strtotime($to . ' +1 day')), $from, date('Y-m-d', strtotime($to . ' +1 day'))]);
    foreach ($plans as $e) {
        $status = $e['status'] === 'completed' ? 'completed' : (string) $e['client_response'];
        foreach ([['shoot', $e['shoot_type'], $e['shoot_at'], cm_shoot_label($e), $e['shoot_place']], ['meeting', $e['meeting_type'], $e['meeting_at'], cm_meeting_label($e), $e['meeting_place']]] as [$kind, $type, $at, $label, $place]) {
            if ($type === 'none' || !$at) continue;
            $date = substr($at, 0, 10);
            if ($date < $from || $date > $to) continue;
            $items[] = ['date' => $date, 'time' => substr($at, 11, 8), 'kind' => $kind, 'client_id' => (int) $e['client_id'], 'client' => $e['client_name'],
                'label' => $label, 'place' => (string) $place, 'note' => cm_title($e), 'status' => $status, 'url' => 'client-meeting-view.php?id=' . (int) $e['id'],
                'ask' => $status === 'change_requested' ? 'Client asked to change' . ($e['client_note'] !== '' ? ' — “' . $e['client_note'] . '”' : '') : '', 'change_url' => 'client-meeting-form.php?id=' . (int) $e['id']];
        }
    }
    usort($items, static fn($a, $b) => [$a['date'], $a['time'], $a['client']] <=> [$b['date'], $b['time'], $b['client']]);
    return $items;
}

/** The dashboard card. Reads ?mtg_from=&mtg_to= (dates) from the URL; default: today + 6 days. */
function agenda_card_html(string $dashboard): string
{
    $today = date('Y-m-d');
    $valid = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    $from = $valid($_GET['mtg_from'] ?? null) ? $_GET['mtg_from'] : $today;
    $to = $valid($_GET['mtg_to'] ?? null) ? $_GET['mtg_to'] : ($valid($_GET['mtg_from'] ?? null) ? $from : date('Y-m-d', strtotime('+6 days')));
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) $to = date('Y-m-d', strtotime($from . ' +92 days')); // up to 3 months at a time
    $items = agenda_items($from, $to);
    $mmRequests = rows("SELECT m.*,c.client_name,c.meeting_day AS usual_day,c.meeting_time AS usual_time FROM client_meeting_changes m JOIN clients c ON c.id=m.client_id WHERE m.status='pending' AND c.deleted_at IS NULL ORDER BY m.id");
    $shootRequests = rows("SELECT e.*,c.client_name FROM client_engagements e JOIN clients c ON c.id=e.client_id WHERE e.deleted_at IS NULL AND c.deleted_at IS NULL AND e.status='scheduled' AND e.client_response='change_requested' ORDER BY e.responded_at DESC");
    $quick = [
        'Today' => [$today, $today],
        'Tomorrow' => [date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day'))],
        'Next 7 days' => [$today, date('Y-m-d', strtotime('+6 days'))],
        'This month' => [date('Y-m-01'), date('Y-m-t')],
    ];
    $link = static fn(string $f, string $t) => h($dashboard . '?mtg_from=' . $f . '&mtg_to=' . $t . '#meetings');
    $range = $from === $to ? date('D, j M Y', strtotime($from)) : date('j M', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
    $meetCount = count(array_filter($items, static fn($i) => $i['kind'] === 'meeting'));
    $shootCount = count($items) - $meetCount;
    $statusPill = static function (array $i): string {
        $map = ['moved' => ['is-pending', 'Moved'], 'confirmed' => ['is-confirmed', '✓ Confirmed'], 'pending' => ['is-pending', '⏳ Waiting for client'], 'change_requested' => ['is-change_requested', '⚠ Client asked to change'], 'completed' => ['is-completed', 'Done']];
        if (!isset($map[$i['status']])) return '';
        return '<span class="cm-resp ' . $map[$i['status']][0] . '">' . h($map[$i['status']][1]) . '</span>';
    };
    ob_start(); ?>
    <section class="admin-dashboard-card mtg-card" id="meetings">
        <div class="admin-card-heading">
            <div><h2>Client meetings &amp; shoots</h2><p><?= h($range) ?> · <?= $meetCount ?> meeting<?= $meetCount === 1 ? '' : 's' ?> · <?= $shootCount ?> shoot<?= $shootCount === 1 ? '' : 's' ?></p></div>
            <span class="mtg-head-links"><a href="client-monthly-meeting.php">Monthly meetings</a> · <a href="client-meetings.php">Shoots</a></span>
        </div>

        <?php if ($mmRequests || $shootRequests): ?>
        <div class="mtg-requests">
            <h3>⚠ Change requests from clients <span><?= count($mmRequests) + count($shootRequests) ?></span></h3>
            <?php foreach ($mmRequests as $c):
                $newDate = date('Y-m-', strtotime($c['start_month'])) . sprintf('%02d', min((int) $c['meeting_day'], (int) date('t', strtotime($c['start_month'])))); ?>
            <article class="mtg-request">
                <div>
                    <strong><?= h($c['client_name']) ?> <small>· Monthly meeting</small></strong>
                    <p>Usual: <?= h(mm_rule_label(['meeting_day' => $c['usual_day'], 'meeting_time' => $c['usual_time']])) ?> → <b><?= h(date('D, j M Y', strtotime($newDate))) ?> · <?= h(mm_time_label($c['meeting_time'])) ?></b> <em><?= h(mm_scope_label($c)) ?></em></p>
                    <?php if ($c['reason'] !== ''): ?><q class="cm-quote"><?= h($c['reason']) ?></q><?php endif; ?>
                </div>
                <form method="post" action="client-monthly-meeting.php" class="mtg-decide"><?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="back" value="dashboard">
                    <input name="decision_note" maxlength="500" placeholder="Note to client (optional)" aria-label="Note to <?= h($c['client_name']) ?>">
                    <button class="ot-gold" type="submit" name="decision" value="approve">✓ Approve</button>
                    <button class="mm-btn" type="submit" name="decision" value="reject">Reject</button>
                </form>
            </article>
            <?php endforeach; ?>
            <?php foreach ($shootRequests as $e): ?>
            <article class="mtg-request">
                <div>
                    <strong><?= h($e['client_name']) ?> <small>· Shoot · <?= h(cm_title($e)) ?></small></strong>
                    <p>Planned: <?= h(cm_when($e['shoot_at'])) ?></p>
                    <?php if ($e['client_note'] !== ''): ?><q class="cm-quote"><?= h($e['client_note']) ?></q><?php endif; ?>
                </div>
                <div class="mtg-decide"><a class="ot-gold" href="client-meeting-form.php?id=<?= (int) $e['id'] ?>">Edit shoot</a></div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="get" action="<?= h($dashboard) ?>#meetings" class="mtg-filter">
            <nav class="mtg-quick" aria-label="Quick dates">
                <?php foreach ($quick as $label => [$f, $t]): ?><a href="<?= $link($f, $t) ?>" class="<?= $f === $from && $t === $to ? 'is-on' : '' ?>"><?= h($label) ?></a><?php endforeach; ?>
            </nav>
            <label>From <input type="date" name="mtg_from" value="<?= h($from) ?>"></label>
            <label>To <input type="date" name="mtg_to" value="<?= h($to) ?>"></label>
            <button type="submit" class="admin-action admin-action-dark">Show</button>
        </form>

        <?php if (!$items): ?><p class="admin-empty">No client meetings or shoots on <?= h($range) ?>.</p><?php endif; ?>
        <div class="mtg-days">
        <?php $day = null; foreach ($items as $i): if ($i['date'] !== $day): if ($day !== null) echo '</ul>'; $day = $i['date']; $d = mm_days_to($day); ?>
            <h3 class="mtg-day<?= $d === 0 ? ' is-today' : '' ?>"><?= h(date('l, j M Y', strtotime($day))) ?><?= $d === 0 ? ' <em>Today</em>' : ($d === 1 ? ' <em>Tomorrow</em>' : '') ?></h3><ul class="mtg-list">
        <?php endif; ?>
            <li class="mtg-item is-<?= h($i['kind']) ?><?= $i['status'] === 'change_requested' ? ' is-asked' : '' ?>">
                <time><?= $i['time'] !== '' ? h(mm_time_label($i['time'])) : '—' ?></time>
                <span class="mtg-kind"><?= $i['kind'] === 'shoot' ? icon('camera') . ' Shoot' : icon('people') . ' Meeting' ?></span>
                <span class="mtg-text"><strong><?= h($i['client']) ?></strong><small><?= h($i['label']) ?><?= $i['place'] !== '' ? ' · ' . h($i['place']) : '' ?> · <?= h($i['note']) ?></small></span>
                <?= $statusPill($i) ?>
                <?php if ($i['status'] === 'change_requested'): ?><a class="mtg-change-btn" href="<?= h($i['change_url']) ?>">✎ Change the date</a><?php endif; ?>
                <a class="admin-row-action" href="<?= h($i['url']) ?>">Open</a>
                <?php if ($i['ask'] !== ''): ?><small class="mtg-ask">⚠ <?= h($i['ask']) ?></small><?php endif; ?>
            </li>
        <?php endforeach; if ($day !== null) echo '</ul>'; ?>
        </div>
    </section>
    <?php return (string) ob_get_clean();
}
