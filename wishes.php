<?php
// Wishes: send a festival poster / message (or a red warning) to employees or clients.
// It pops up for them the next time they open the portal.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/wishes.php';
$user = require_roles(['admin']);
$error = null;

$employeeList = rows("SELECT u.id,u.full_name,d.name AS sub FROM users u JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name");
$clientList = rows("SELECT u.id,c.client_name AS full_name,c.package AS sub FROM clients c JOIN users u ON u.id=c.user_id AND u.role='client' AND u.account_status='active' AND u.deleted_at IS NULL WHERE c.deleted_at IS NULL AND c.is_active=1 ORDER BY c.client_name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $storedPoster = null;
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_wish') {
            $wid = positive_id($_POST['id'] ?? null);
            $old = one('SELECT poster_path FROM wishes WHERE id=?', [$wid]);
            query('DELETE FROM wishes WHERE id=?', [$wid]);
            if ($old && ($posterFile = wish_poster_file($old['poster_path']))) @unlink($posterFile);
            flash('Message deleted. People who have not opened it yet will not see it.');
            redirect('wishes.php');
        }
        if ($action !== 'send_wish') throw new InvalidArgumentException('Invalid request.');
        $audience = (string) ($_POST['audience'] ?? '');
        if (!in_array($audience, ['employee', 'client'], true)) throw new InvalidArgumentException('Choose Employee or Client.');
        $tone = ($_POST['tone'] ?? '') === 'danger' ? 'danger' : 'wish';
        $pool = $audience === 'employee' ? $employeeList : $clientList;
        $poolIds = array_map('intval', array_column($pool, 'id'));
        $sendAll = !empty($_POST['send_all']);
        $picked = array_values(array_unique(array_map('intval', is_array($_POST['recipients'][$audience] ?? null) ? $_POST['recipients'][$audience] : [])));
        $recipientIds = $sendAll ? $poolIds : array_values(array_intersect($picked, $poolIds));
        if (!$recipientIds) throw new InvalidArgumentException($audience === 'employee' ? 'Select at least one employee, or tick “All employees”.' : 'Select at least one client, or tick “All clients”. (Only clients with a login can get messages.)');
        $title = optional_text('title', 200);
        $message = isset($_POST['message']) ? text_input('message', 5000, false) : '';
        $poster = wish_poster_input();
        if ($message === '' && !$poster) throw new InvalidArgumentException('Write a message or add a poster.');
        $names = array_column($pool, 'full_name', 'id');
        $label = $sendAll ? ($audience === 'employee' ? 'All employees' : 'All clients') : (count($recipientIds) === 1 ? (string) ($names[$recipientIds[0]] ?? '1 person') : count($recipientIds) . ' ' . ($audience === 'employee' ? 'employees' : 'clients'));
        if ($poster) $storedPoster = wish_store_poster($poster);
        db()->beginTransaction();
        query('INSERT INTO wishes (audience,tone,title,message,poster_path,audience_label,recipient_count,sender_id) VALUES (?,?,?,?,?,?,?,?)', [$audience, $tone, $title, $message, $storedPoster, mb_substr($label, 0, 255), count($recipientIds), $user['id']]);
        $wishId = (int) db()->lastInsertId();
        foreach ($recipientIds as $rid) query('INSERT INTO wish_recipients (wish_id,user_id) VALUES (?,?)', [$wishId, $rid]);
        db()->commit();
        flash(($tone === 'danger' ? 'Warning' : 'Wish') . ' sent to ' . $label . '. It will pop up when they open the portal.');
        redirect('wishes.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($storedPoster) @unlink(__DIR__ . '/' . $storedPoster);
        $error = mutation_error($e);
    }
}

$history = rows('SELECT w.*,(SELECT COUNT(*) FROM wish_recipients r WHERE r.wish_id=w.id AND r.show_count>0) AS seen_count FROM wishes w ORDER BY w.created_at DESC,w.id DESC LIMIT 30');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$audienceValue = $isPost ? (string) ($_POST['audience'] ?? 'employee') : 'employee';
$pickedValues = $isPost && is_array($_POST['recipients'] ?? null) ? $_POST['recipients'] : [];

page_start('Wishes', 'wishes.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Wishes</p>
        <h1 class="ot-title">Wishes</h1>
        <p class="ot-sub">Send a festival poster or a message to employees or clients — it pops up the next time they log in. Use the red button for a warning.</p>
    </header>

    <div class="ot-grid ws-grid">
        <section class="ot-card">
            <h2>New message</h2>
            <form method="post" enctype="multipart/form-data" class="ot-form" data-wish-form><?= csrf_field() ?><input type="hidden" name="action" value="send_wish">
                <div class="ot-two">
                    <label><span>Type *</span><select name="audience" data-wish-audience required><option value="employee" <?= $audienceValue === 'employee' ? 'selected' : '' ?>>Employee</option><option value="client" <?= $audienceValue === 'client' ? 'selected' : '' ?>>Client</option></select></label>
                    <label class="ws-all"><input type="checkbox" name="send_all" value="1" data-wish-all <?= $isPost && !empty($_POST['send_all']) ? 'checked' : '' ?>><span data-wish-all-label>All employees</span></label>
                </div>
                <?php foreach (['employee' => $employeeList, 'client' => $clientList] as $kind => $list): ?>
                    <div class="ws-pick" data-wish-list="<?= $kind ?>"<?= $audienceValue === $kind ? '' : ' hidden' ?>>
                        <div class="ws-pick-head"><span><?= $kind === 'employee' ? 'Select employees' : 'Select clients' ?></span><input type="search" placeholder="Search…" data-wish-search aria-label="Search"></div>
                        <div class="ws-pick-list">
                            <?php if (!$list): ?><p class="ot-help"><?= $kind === 'employee' ? 'No active employees.' : 'No clients with a login yet. Create a client login in Edit client.' ?></p><?php endif; ?>
                            <?php foreach ($list as $person): $checked = in_array((string) $person['id'], array_map('strval', (array) ($pickedValues[$kind] ?? [])), true); ?>
                                <label class="ws-person" data-name="<?= h(mb_strtolower($person['full_name'] . ' ' . $person['sub'])) ?>"><input type="checkbox" name="recipients[<?= $kind ?>][]" value="<?= (int) $person['id'] ?>" <?= $checked ? 'checked' : '' ?>><span><strong><?= h($person['full_name']) ?></strong><?php if ($person['sub']): ?><small><?= h($person['sub']) ?></small><?php endif; ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <label><span>Title <small>(optional)</small></span><input name="title" maxlength="200" value="<?= h($isPost ? ($_POST['title'] ?? '') : '') ?>" placeholder="e.g. Happy Children’s Day!"></label>
                <label><span>Poster <small>(optional · JPG, PNG, WebP, GIF up to 10 MB)</small></span><input type="file" name="poster" accept="image/jpeg,image/png,image/webp,image/gif" data-wish-poster></label>
                <img class="ws-preview" data-wish-preview alt="" hidden>
                <label><span>Message</span><textarea name="message" maxlength="5000" rows="4" placeholder="Write your wish or message…"><?= h($isPost ? ($_POST['message'] ?? '') : '') ?></textarea></label>
                <div class="ws-send">
                    <button class="ot-gold" type="submit" name="tone" value="wish"><?= icon('send') ?> Send wish</button>
                    <button class="ws-danger" type="submit" name="tone" value="danger" data-confirm-danger><?= icon('bell') ?> Send as warning</button>
                </div>
                <p class="ot-help">Wish = pops up with sprinkles 🎉. Warning = pops up in red.</p>
            </form>
        </section>

        <section class="ot-card">
            <div class="ot-card-head"><h2>Sent messages</h2><span>Last 30</span></div>
            <?php if (!$history): ?><p class="ot-help">Nothing sent yet.</p><?php endif; ?>
            <div class="ws-history">
                <?php foreach ($history as $w): ?>
                    <article class="ws-item<?= $w['tone'] === 'danger' ? ' is-danger' : '' ?><?= $w['poster_path'] ? '' : ' no-poster' ?>">
                        <?php if ($w['poster_path']): ?><img src="wish-poster.php?id=<?= (int) $w['id'] ?>" alt="" loading="lazy"><?php endif; ?>
                        <div>
                            <p class="ws-item-meta"><b><?= $w['tone'] === 'danger' ? 'Warning' : 'Wish' ?></b> · <?= $w['audience'] === 'employee' ? 'Employee' : 'Client' ?> · <?= h($w['audience_label']) ?></p>
                            <?php if ($w['title'] !== ''): ?><strong><?= h($w['title']) ?></strong><?php endif; ?>
                            <?php if ($w['message'] !== ''): ?><p><?= h(mb_strimwidth($w['message'], 0, 160, '…')) ?></p><?php endif; ?>
                            <small><?= h(date('d M Y, g:i A', strtotime($w['created_at']))) ?> · Seen by <?= (int) $w['seen_count'] ?> / <?= (int) $w['recipient_count'] ?></small>
                        </div>
                        <form method="post" data-confirm="Delete this message?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_wish"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><button type="submit" class="icon-button danger" title="Delete" aria-label="Delete"><?= icon('delete') ?></button></form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<script>
(() => {
    const form = document.querySelector('[data-wish-form]'), audience = form.querySelector('[data-wish-audience]'), all = form.querySelector('[data-wish-all]'), allLabel = form.querySelector('[data-wish-all-label]');
    const sync = () => {
        const kind = audience.value;
        form.querySelectorAll('[data-wish-list]').forEach(list => {
            const on = list.dataset.wishList === kind;
            list.hidden = !on;
            list.querySelectorAll('input[type=checkbox]').forEach(c => { c.disabled = !on || all.checked; });
        });
        allLabel.textContent = kind === 'employee' ? 'All employees' : 'All clients';
    };
    audience.addEventListener('change', sync); all.addEventListener('change', sync); sync();
    form.querySelectorAll('[data-wish-search]').forEach(search => search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        search.closest('[data-wish-list]').querySelectorAll('.ws-person').forEach(p => { p.hidden = q !== '' && !p.dataset.name.includes(q); });
    }));
    const poster = form.querySelector('[data-wish-poster]'), preview = form.querySelector('[data-wish-preview]');
    poster.addEventListener('change', () => { const f = poster.files[0]; if (f) { preview.src = URL.createObjectURL(f); preview.hidden = false; } else preview.hidden = true; });
    form.querySelector('[data-confirm-danger]').addEventListener('click', e => { if (!confirm('Send this as a red warning?')) e.preventDefault(); });
})();
</script>
<?php page_end(); ?>
