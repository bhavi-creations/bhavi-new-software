<?php
// Content schedule: the Managing Director sets when each edited reel / poster goes out (e.g. 10 reels, one a day at 6 PM),
// with its files / links and where to post it. The assigned employee sees it, gets a reminder just before the time and ticks it Sent.
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/content-schedule.php';
$user = require_roles(['admin', 'employee']);
$isAdmin = $user['role'] === 'admin';
$error = null;
$now = date('Y-m-d H:i:s');

$mode = $isAdmin && (isset($_GET['add']) || isset($_GET['id'])) ? 'form' : 'list';
$id = (int) ($_GET['id'] ?? 0);
$record = $isAdmin && $id ? one('SELECT * FROM content_schedule WHERE id=? AND deleted_at IS NULL', [$id]) : null;
if ($isAdmin && $id && !$record) fail(404, 'Schedule not found.');
$clientList = rows('SELECT id,client_name FROM clients WHERE deleted_at IS NULL AND is_active=1 ORDER BY client_name');
$employeeList = $isAdmin ? rows("SELECT u.id,u.full_name,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name") : [];

/** "2026-11-20" + "18:00" → "2026-11-20 18:00:00", or an error naming the row. */
function schedule_datetime(string $date, string $time, string $label): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date).' '.substr(trim($time), 0, 5));
    if (!$dt || $dt->format('Y-m-d') !== trim($date)) throw new InvalidArgumentException('Choose a valid date and time for '.$label.'.');
    return $dt->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_done' || $action === 'mark_scheduled') {
            $itemId = positive_id($_POST['id'] ?? null);
            $item = one('SELECT id,employee_id FROM content_schedule WHERE id=? AND deleted_at IS NULL', [$itemId]);
            if (!$item || (!$isAdmin && (int) $item['employee_id'] !== (int) $user['id'])) throw new InvalidArgumentException('Schedule not found.');
            if ($action === 'mark_done') {
                query("UPDATE content_schedule SET status='done',done_at=NOW(),done_by=?,done_note=? WHERE id=?", [$user['id'], optional_text('done_note', 1000), $itemId]);
                flash('Marked as sent to client.');
            } else {
                query("UPDATE content_schedule SET status='scheduled',done_at=NULL,done_by=NULL WHERE id=?", [$itemId]);
                flash('Moved back to scheduled.');
            }
            if (($_POST['return'] ?? '') === 'dashboard') redirect(dashboard_path($user));
            redirect('content-schedule.php'.(isset($_GET['tab']) ? '?tab='.rawurlencode((string) $_GET['tab']) : ''));
        }
        if (!$isAdmin) throw new InvalidArgumentException('Invalid request.');
        if ($action === 'delete_schedule') {
            $delId = positive_id($_POST['id'] ?? null);
            query('UPDATE content_schedule SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [$delId]);
            foreach (rows('SELECT file_path FROM content_schedule_files WHERE schedule_id=?', [$delId]) as $df) @unlink(__DIR__.'/includes/'.$df['file_path']);
            query('DELETE FROM content_schedule_files WHERE schedule_id=?', [$delId]);
            flash('Schedule deleted.');
            redirect('content-schedule.php');
        }
        if ($action !== 'save_schedule') throw new InvalidArgumentException('Invalid request.');

        $clientId = positive_id($_POST['client_id'] ?? null);
        if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL', [$clientId])) throw new InvalidArgumentException('Choose a client.');
        $employeeId = (int) ($_POST['employee_id'] ?? 0) ?: null;
        if ($employeeId && !in_array($employeeId, array_map('intval', array_column($employeeList, 'id')), true)) throw new InvalidArgumentException('Choose a valid employee.');
        $type = (string) ($_POST['content_type'] ?? 'reel');
        if (!isset(SCHEDULE_TYPES[$type])) $type = 'other';
        $platforms = array_values(array_intersect(SCHEDULE_PLATFORMS, (array) ($_POST['platforms'] ?? [])));
        $platformText = implode(', ', $platforms);
        $caption = optional_text('caption', 5000);

        $posted = $_POST['items'] ?? [];
        if (!is_array($posted) || count($posted) > 100) throw new InvalidArgumentException('Add up to 100 items at a time.');
        $items = [];
        foreach ($posted as $idx => $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid item.');
            $title = trim((string) ($row['title'] ?? ''));
            $label = 'item '.(count($items) + 1);
            $files = schedule_uploaded_files((int) $idx, $label);
            if ($title === '' && trim((string) ($row['date'] ?? '')) === '' && !$files && trim((string) ($row['links'] ?? '')) === '') continue;
            if ($title === '' || mb_strlen($title) > 255) throw new InvalidArgumentException('Enter a name for '.$label.' (e.g. Reel 1).');
            $when = schedule_datetime((string) ($row['date'] ?? ''), (string) ($row['time'] ?? ''), $label.' ('.$title.')');
            $items[] = ['title' => $title, 'scheduled_at' => $when, 'media_link' => implode("\n", schedule_links_input((string) ($row['links'] ?? ''), $label.' ('.$title.')')), 'files' => $files];
        }
        if (!$items) throw new InvalidArgumentException('Add at least one item with a date and time.');

        $savedPaths = [];
        db()->beginTransaction();
        if ($record) {
            $it = $items[0];
            // A new time means a new reminder.
            $resetReminder = $it['scheduled_at'] !== $record['scheduled_at'] || (int) $record['employee_id'] !== (int) $employeeId;
            $status = (string) ($_POST['status'] ?? $record['status']);
            if (!isset(SCHEDULE_STATUSES[$status])) $status = 'scheduled';
            $doneSql = $status === 'done' ? ($record['status'] === 'done' ? '' : ',done_at=NOW(),done_by='.(int) $user['id']) : ',done_at=NULL,done_by=NULL';
            query('UPDATE content_schedule SET client_id=?,employee_id=?,content_type=?,title=?,platforms=?,media_link=?,caption=?,scheduled_at=?,status=?'.$doneSql.($resetReminder ? ',reminded_at=NULL' : '').' WHERE id=?', [$clientId, $employeeId, $type, $it['title'], $platformText, $it['media_link'], $caption, $it['scheduled_at'], $status, $id]);
            // Files ticked for removal.
            $removeIds = array_map('intval', (array) ($_POST['remove_files'] ?? []));
            if ($removeIds) {
                $gone = rows('SELECT id,file_path FROM content_schedule_files WHERE schedule_id=? AND id IN ('.implode(',', array_fill(0, count($removeIds), '?')).')', array_merge([$id], $removeIds));
                foreach ($gone as $g) query('DELETE FROM content_schedule_files WHERE id=?', [$g['id']]);
                $removePaths = array_column($gone, 'file_path');
            }
            $savedPaths = schedule_save_files($id, $it['files'], (int) $user['id']);
        } else {
            foreach ($items as $it) {
                query('INSERT INTO content_schedule (client_id,employee_id,content_type,title,platforms,media_link,caption,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$clientId, $employeeId, $type, $it['title'], $platformText, $it['media_link'], $caption, $it['scheduled_at'], $user['id']]);
                $savedPaths = array_merge($savedPaths, schedule_save_files((int) db()->lastInsertId(), $it['files'], (int) $user['id']));
            }
        }
        db()->commit();
        foreach ($removePaths ?? [] as $p) @unlink(__DIR__.'/includes/'.$p);
        flash($record ? 'Schedule updated.' : count($items).' item'.(count($items) === 1 ? '' : 's').' scheduled.');
        redirect('content-schedule.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($savedPaths ?? [] as $p) @unlink($p); // nothing saved, so drop the copied files too
        $error = mutation_error($e);
    }
}

if ($mode === 'form') {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $v = static fn(string $k, string $d = ''): string => (string) ($isPost ? ($_POST[$k] ?? $d) : ($record[$k] ?? $d));
    $chosenPlatforms = $isPost ? (array) ($_POST['platforms'] ?? []) : ($record ? array_map('trim', explode(',', (string) $record['platforms'])) : ['Instagram', 'Facebook']);
    if ($isPost && is_array($_POST['items'] ?? null)) $items = array_values(array_filter($_POST['items'], 'is_array'));
    elseif ($record) $items = [['title' => $record['title'], 'date' => substr($record['scheduled_at'], 0, 10), 'time' => substr($record['scheduled_at'], 11, 5), 'links' => (string) $record['media_link']]];
    else $items = [];
    $recordFiles = $record ? (schedule_files_for([$id])[$id] ?? []) : [];
    if (!$items) $items = [['title' => '', 'date' => '', 'time' => '', 'links' => '']];
    page_start($record ? 'Edit schedule' : 'New schedule', 'content-schedule.php');
    error_message($error);
    ?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Content schedule / <?= $record ? 'Edit' : 'New' ?></p>
        <h1 class="ot-title"><?= $record ? h($record['title']) : 'Schedule content' ?></h1>
        <p class="ot-sub">Edited reels are not sent all at once: set the date and time each one goes to the client. The employee gets a reminder <?= SCHEDULE_REMIND_BEFORE_MIN ?> minutes before.</p>
    </header>
    <form method="post" class="vd-form" autocomplete="off" enctype="multipart/form-data" data-schedule-form><?= csrf_field() ?><input type="hidden" name="action" value="save_schedule">
        <section class="ot-card"><h2>1 · Client &amp; employee</h2>
            <div class="ot-form vd-grid">
                <label><span>Client *</span><select name="client_id" required><option value="">Select client</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $v('client_id') === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select></label>
                <label><span>Employee who sends it <small>(sees it &amp; gets the reminder)</small></span><select name="employee_id"><option value="">Me (Managing Director)</option><?php foreach ($employeeList as $e): ?><option value="<?= (int) $e['id'] ?>" <?= $v('employee_id') === (string) $e['id'] ? 'selected' : '' ?>><?= h($e['full_name'].($e['department_name'] ? ' · '.$e['department_name'] : '')) ?></option><?php endforeach; ?></select></label>
                <?php if ($record): ?><label><span>Status</span><select name="status"><?php foreach (SCHEDULE_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $v('status', 'scheduled') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label><span>Content type</span><select name="content_type" data-type><?php foreach (SCHEDULE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $v('content_type', 'reel') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="sc-platforms"><span>Post on <small>(tick where it should be posted)</small></span><?php foreach (SCHEDULE_PLATFORMS as $p): ?><label><input type="checkbox" name="platforms[]" value="<?= h($p) ?>" <?= in_array($p, $chosenPlatforms, true) ? 'checked' : '' ?>> <?= h($p) ?></label><?php endforeach; ?></div>
            <div class="ot-form"><label><span>Caption / instructions <small>(optional, same for all)</small></span><textarea name="caption" maxlength="5000" rows="2" placeholder="Caption, hashtags or posting notes"><?= h($v('caption')) ?></textarea></label></div>
        </section>

        <?php if (!$record): ?>
        <section class="ot-card sc-quick"><h2>2 · Quick schedule <small class="ot-help">(fills the list below)</small></h2>
            <div class="ot-form sc-quick-grid">
                <label><span>How many</span><input type="number" min="1" max="100" value="10" data-q-count></label>
                <label><span>Name</span><input value="Reel" maxlength="200" data-q-name></label>
                <label><span>First date</span><input type="date" value="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" data-q-date></label>
                <label><span>Time</span><input type="time" value="18:00" data-q-time></label>
                <label><span>Gap</span><select data-q-gap><option value="1">Every day</option><option value="2">Every 2 days</option><option value="3">Every 3 days</option><option value="7">Every week</option><option value="0">Same day (hour gap)</option></select></label>
                <label data-q-hours-wrap hidden><span>Hours between</span><input type="number" min="1" max="23" value="3" data-q-hours></label>
            </div>
            <button type="button" class="ot-gold sc-fill" data-q-fill>Fill list</button>
        </section>
        <?php endif; ?>

        <section class="ot-card"><h2><?= $record ? '2' : '3' ?> · <?= $record ? 'Date &amp; time' : 'Items &amp; times' ?></h2>
            <div class="sc-rows" data-rows>
                <?php foreach ($items as $i => $it): ?>
                    <div class="ot-form sc-row">
                        <label><span>Name *</span><input name="items[<?= $i ?>][title]" maxlength="255" value="<?= h($it['title'] ?? '') ?>" placeholder="Reel 1"></label>
                        <label><span>Date *</span><input type="date" name="items[<?= $i ?>][date]" value="<?= h($it['date'] ?? '') ?>"></label>
                        <label><span>Time *</span><input type="time" name="items[<?= $i ?>][time]" value="<?= h($it['time'] ?? '') ?>"></label>
                        <label class="sc-files"><span>Files <small>(reel video, thumbnail…)</small></span><input type="file" name="item_files[<?= $i ?>][]" multiple accept="video/*,image/*,.pdf,.zip,.rar,.psd,.ai,.mp3,.wav,.doc,.docx,.ppt,.pptx,.txt"></label>
                        <label class="sc-links"><span>Links <small>(one per line)</small></span><textarea name="items[<?= $i ?>][links]" rows="1" placeholder="https://drive.google.com/..."><?= h($it['links'] ?? '') ?></textarea></label>
                        <?php if (!$record): ?><button type="button" class="icon-button danger" data-remove title="Remove" aria-label="Remove item"><?= icon('delete') ?></button><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($record && $recordFiles): ?>
                <div class="sc-saved-files"><span>Saved files <small>(tick to remove)</small></span>
                    <?php foreach ($recordFiles as $rf): ?><label><input type="checkbox" name="remove_files[]" value="<?= (int) $rf['id'] ?>"> <a href="content-schedule-file.php?id=<?= (int) $rf['id'] ?>" target="_blank" rel="noopener"><?= h($rf['original_name']) ?></a> <small class="muted"><?= h(schedule_file_size((int) $rf['file_size'])) ?></small></label><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="ot-help">Files up to <?= h(ini_get('upload_max_filesize')) ?> each (<?= h(ini_get('post_max_size')) ?> in total per save). For bigger videos add a Google Drive link.</p>
            <?php if (!$record): ?><button type="button" class="vd-add" data-add>+ Add one more</button><?php endif; ?>
        </section>
        <div class="ot-actions"><button class="ot-gold" type="submit"><?= $record ? 'Save' : 'Save schedule' ?></button><a href="content-schedule.php">Cancel</a></div>
    </form>
</div>
<?php if (!$record): ?>
<script>
(() => {
    const form = document.querySelector('[data-schedule-form]'), list = form.querySelector('[data-rows]');
    const q = s => form.querySelector(s);
    const blank = () => { const r = list.firstElementChild.cloneNode(true); r.querySelectorAll('input,textarea').forEach(i => i.value = ''); return r; };
    const renumber = () => list.querySelectorAll('.sc-row').forEach((row, i) => row.querySelectorAll('input,textarea').forEach(inp => inp.name = inp.name.replace(/items\[\d+\]/, 'items[' + i + ']').replace(/item_files\[\d+\]/, 'item_files[' + i + ']')));
    const pad = n => String(n).padStart(2, '0');
    q('[data-q-gap]').addEventListener('change', e => { q('[data-q-hours-wrap]').hidden = e.target.value !== '0'; });
    q('[data-q-fill]').addEventListener('click', () => {
        const count = Math.min(100, Math.max(1, parseInt(q('[data-q-count]').value, 10) || 1));
        const name = q('[data-q-name]').value.trim() || 'Reel', date = q('[data-q-date]').value, time = q('[data-q-time]').value || '18:00';
        if (!date) { q('[data-q-date]').focus(); return; }
        const gap = parseInt(q('[data-q-gap]').value, 10), hours = Math.max(1, parseInt(q('[data-q-hours]').value, 10) || 1);
        const [y, m, d] = date.split('-').map(Number), [hh, mm] = time.split(':').map(Number);
        const links = [...list.querySelectorAll('[name$="[links]"]')].map(i => i.value);
        const fileInputs = [...list.querySelectorAll('input[type=file]')]; // chosen files cannot be copied, so the inputs are moved
        const template = blank(); list.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const t = gap ? new Date(y, m - 1, d + i * gap, hh, mm) : new Date(y, m - 1, d, hh + i * hours, mm);
            const row = template.cloneNode(true);
            row.querySelector('[name$="[title]"]').value = name + ' ' + (i + 1);
            row.querySelector('[name$="[date]"]').value = t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
            row.querySelector('[name$="[time]"]').value = pad(t.getHours()) + ':' + pad(t.getMinutes());
            row.querySelector('[name$="[links]"]').value = links[i] || '';
            if (fileInputs[i] && fileInputs[i].files.length) row.querySelector('input[type=file]').replaceWith(fileInputs[i]);
            list.appendChild(row);
        }
        renumber();
    });
    form.querySelector('[data-add]').addEventListener('click', () => { list.appendChild(blank()); renumber(); list.lastElementChild.querySelector('input').focus(); });
    list.addEventListener('click', e => {
        const b = e.target.closest('[data-remove]'); if (!b) return;
        if (list.children.length === 1) list.firstElementChild.querySelectorAll('input,textarea').forEach(i => i.value = '');
        else b.closest('.sc-row').remove();
        renumber();
    });
    // Name follows the content type (Reel / Poster / ...) until changed by hand.
    const nameInput = q('[data-q-name]'); let nameTouched = false;
    nameInput.addEventListener('input', () => { nameTouched = true; });
    q('[data-type]').addEventListener('change', e => { if (!nameTouched) nameInput.value = e.target.selectedOptions[0].textContent; });
})();
</script>
<?php endif; ?>
    <?php
    page_end();
    exit;
}

// ---- List ----
$tab = (string) ($_GET['tab'] ?? 'upcoming');
$tabs = ['due' => 'Due now', 'upcoming' => 'Upcoming', 'done' => 'Sent', 'cancelled' => 'Cancelled', 'all' => 'All'];
if (!isset($tabs[$tab])) $tab = 'upcoming';
$filterClient = (int) ($_GET['client'] ?? 0);
$filterEmployee = $isAdmin ? (int) ($_GET['employee'] ?? 0) : (int) $user['id'];
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$where = ['s.deleted_at IS NULL']; $params = [];
if ($filterClient) { $where[] = 's.client_id=?'; $params[] = $filterClient; }
if ($filterEmployee) { $where[] = 's.employee_id=?'; $params[] = $filterEmployee; }
if ($search !== '') {
    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    $where[] = '(s.title LIKE ? OR c.client_name LIKE ? OR s.platforms LIKE ? OR s.caption LIKE ? OR u.full_name LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$all = rows('SELECT s.*,c.client_name,u.full_name AS employee_name,db.full_name AS done_by_name FROM content_schedule s JOIN clients c ON c.id=s.client_id LEFT JOIN users u ON u.id=s.employee_id LEFT JOIN users db ON db.id=s.done_by WHERE '.implode(' AND ', $where).' ORDER BY s.scheduled_at,s.id', $params);
$dueLimit = date('Y-m-d H:i:s', time() + SCHEDULE_REMIND_BEFORE_MIN * 60);
$inTab = static fn(array $s, string $t): bool => match ($t) {
    'due' => $s['status'] === 'scheduled' && $s['scheduled_at'] <= $dueLimit,
    'upcoming' => $s['status'] === 'scheduled' && $s['scheduled_at'] > $dueLimit,
    'done' => $s['status'] === 'done',
    'cancelled' => $s['status'] === 'cancelled',
    default => true,
};
$counts = []; foreach (array_keys($tabs) as $t) $counts[$t] = count(array_filter($all, static fn($s) => $inTab($s, $t)));
$items = array_values(array_filter($all, static fn($s) => $inTab($s, $tab)));
$filesBy = schedule_files_for(array_column($items, 'id'));
if (in_array($tab, ['done', 'all'], true)) $items = array_reverse($items); // newest first
$url = static function (array $change) use ($tab, $filterClient, $filterEmployee, $search, $isAdmin): string {
    $q = array_merge(['tab' => $tab, 'client' => $filterClient ?: null, 'employee' => $isAdmin && $filterEmployee ? $filterEmployee : null, 'q' => $search !== '' ? $search : null], $change);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return 'content-schedule.php'.($q ? '?'.http_build_query($q) : '');
};
page_start('Content schedule', 'content-schedule.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head vd-head">
        <div><p class="ot-crumb">Dashboard / Content schedule</p><h1 class="ot-title"><?= $isAdmin ? 'Content schedule' : 'Reels to send to client' ?></h1><p class="ot-sub"><?= $isAdmin ? 'Send edited reels to clients day by day, not all at once. The employee sees each one and gets a reminder '.SCHEDULE_REMIND_BEFORE_MIN.' minutes before its time.' : 'Send each reel to the client at its time, then tick ✓ Sent.' ?></p></div>
        <?php if ($isAdmin): ?><a class="ot-gold vd-new" href="content-schedule.php?add=1">+ Schedule content</a><?php endif; ?>
    </header>
    <section class="ot-card">
        <div class="inv-filters">
            <nav class="inv-tabs" aria-label="Filter schedules">
                <?php foreach ($tabs as $k => $l): ?><a href="<?= h($url(['tab' => $k])) ?>" class="inv-tab sc-tab-<?= $k ?><?= $tab === $k ? ' is-on' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= h($l) ?> <em><?= $counts[$k] ?></em></a><?php endforeach; ?>
            </nav>
            <form method="get" class="inv-client-filter">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <select name="client" aria-label="Filter by client" onchange="this.form.submit()"><option value="">All clients</option><?php foreach ($clientList as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filterClient === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option><?php endforeach; ?></select>
                <?php if ($isAdmin): ?><select name="employee" aria-label="Filter by employee" onchange="this.form.submit()"><option value="">All employees</option><?php foreach ($employeeList as $e): ?><option value="<?= (int) $e['id'] ?>" <?= $filterEmployee === (int) $e['id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
                <span class="inv-search"><input type="search" name="q" value="<?= h($search) ?>" maxlength="100" placeholder="Search name, client, platform…" aria-label="Search schedules"><button type="submit" class="button secondary">Search</button></span>
                <?php if ($filterClient || ($isAdmin && $filterEmployee) || $search !== ''): ?><a href="<?= h('content-schedule.php?tab='.$tab) ?>">Clear</a><?php endif; ?>
            </form>
        </div>
        <div class="table-scroll"><table><thead><tr><th>When</th><th>Client</th><th>Content</th><th>Post on</th><?php if ($isAdmin): ?><th>Employee</th><?php endif; ?><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php if (!$items) empty_row($isAdmin ? 7 : 6, $search !== '' ? 'Nothing matches “'.$search.'”.' : ['due' => 'Nothing due right now.', 'upcoming' => 'Nothing scheduled yet.', 'done' => 'Nothing sent yet.', 'cancelled' => 'Nothing cancelled.', 'all' => 'Nothing scheduled yet.'][$tab]);
            foreach ($items as $s): $ts = strtotime($s['scheduled_at']); $late = $s['status'] === 'scheduled' && $s['scheduled_at'] < $now; $soon = $s['status'] === 'scheduled' && !$late && $s['scheduled_at'] <= $dueLimit; ?>
                <tr class="<?= $late ? 'sc-late' : ($soon ? 'sc-soon' : '') ?>">
                    <td><strong><?= h(date('d M Y', $ts)) ?></strong><br><span class="sc-time"><?= h(date('h:i A', $ts)) ?></span><?php if ($late): ?> <small class="inv-overdue">Overdue</small><?php elseif ($soon): ?> <small class="sc-now">Now</small><?php endif; ?></td>
                    <td><?= h($s['client_name']) ?></td>
                    <td><span class="sc-type"><?= h(SCHEDULE_TYPES[$s['content_type']] ?? 'Post') ?></span> <?= h($s['title']) ?><?php schedule_media_list($filesBy[(int) $s['id']] ?? [], schedule_links($s['media_link'])); ?></td>
                    <td><?= h($s['platforms'] ?: '—') ?></td>
                    <?php if ($isAdmin): ?><td><?= h($s['employee_name'] ?? 'Me') ?></td><?php endif; ?>
                    <td><?php if ($s['status'] === 'done'): ?><span class="badge client-status is-active">Sent</span><br><small class="muted"><?= h(date('d M, h:i A', strtotime($s['done_at']))) ?><?= $s['done_by_name'] ? ' · '.h($s['done_by_name']) : '' ?></small><?php elseif ($s['status'] === 'cancelled'): ?><span class="badge client-status is-inactive">Cancelled</span><?php else: ?><span class="badge client-status is-<?= $late ? 'on_hold' : 'lead' ?>">Scheduled</span><?php endif; ?></td>
                    <td><div class="actions">
                        <?php if ($s['status'] === 'scheduled'): ?><form method="post" action="<?= h('content-schedule.php?tab='.$tab) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="mark_done"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button type="submit" class="button secondary" title="Sent to client">✓ Sent</button></form>
                        <?php elseif ($s['status'] === 'done'): ?><form method="post" action="<?= h('content-schedule.php?tab='.$tab) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="mark_scheduled"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button type="submit" class="icon-button" title="Undo sent" aria-label="Undo sent">↺</button></form><?php endif; ?>
                        <?php detail_button('sched-'.$s['id']); ?>
                        <?php if ($isAdmin): ?><a class="icon-button" href="content-schedule.php?id=<?= (int) $s['id'] ?>" title="Edit" aria-label="Edit"><?= icon('edit') ?></a><?php delete_button((int) $s['id'], 'delete_schedule', 'Delete '.$s['title'].'?'); endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php foreach ($items as $s) {
    detail_dialog('sched-'.$s['id'], $s['title'].' · '.$s['client_name'], [
        'When' => date('d M Y, h:i A', strtotime($s['scheduled_at'])), 'Type' => SCHEDULE_TYPES[$s['content_type']] ?? 'Post', 'Post on' => $s['platforms'],
        'Employee' => $s['employee_name'] ?? 'Managing Director', 'Links' => implode(' , ', schedule_links($s['media_link'])), 'Files' => implode(', ', array_column($filesBy[(int) $s['id']] ?? [], 'original_name')), 'Caption / instructions' => (string) $s['caption'],
        'Reminder sent' => $s['reminded_at'] ? date('d M Y, h:i A', strtotime($s['reminded_at'])) : 'Not yet',
        'Status' => SCHEDULE_STATUSES[$s['status']] ?? 'Scheduled', 'Done at' => $s['done_at'] ? date('d M Y, h:i A', strtotime($s['done_at'])).($s['done_by_name'] ? ' by '.$s['done_by_name'] : '') : '',
    ]);
}
page_end(); ?>
