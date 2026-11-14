<?php
// Zoom meetings: the Managing Director / managers add a meeting (Zoom link or ID + passcode);
// everyone on the team joins it inside the portal (Zoom Meeting SDK) or in the Zoom app.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/zoom.php';
$user = require_roles(['admin', 'manager', 'employee']);
$canManage = in_array($user['role'], ['admin', 'manager'], true);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (!$canManage) throw new InvalidArgumentException('Only the Managing Director and managers can manage meetings.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_zoom_credentials') {
            // The Managing Director pastes the Zoom app's Client ID / Secret here (no server file needed).
            if ($user['role'] !== 'admin') throw new InvalidArgumentException('Only the Managing Director can change the Zoom connection.');
            $clientId = trim(text_input('client_id', 100));
            $clientSecret = trim((string) ($_POST['client_secret'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{10,100}$/', $clientId)) throw new InvalidArgumentException('Enter the Client ID exactly as shown in Zoom (letters and numbers).');
            if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $clientSecret)) throw new InvalidArgumentException('Enter the Client Secret exactly as shown in Zoom.');
            zoom_save_credentials($clientId, $clientSecret);
            // New app credentials make any earlier Zoom account connection invalid.
            query("DELETE FROM portal_settings WHERE setting_key IN ('zoom_access_token','zoom_refresh_token','zoom_token_expires','zoom_account_email')");
            flash('Zoom Client ID and Client Secret saved.');
            redirect('zoom-meetings.php');
        }
        if ($action === 'instant_meeting') {
            // One click: Zoom creates the meeting, the team is notified, and the starter joins as host.
            $title = optional_text('title', 150) ?: 'Instant meeting · ' . $user['full_name'];
            $meeting = zoom_create_instant_meeting($title);
            db()->beginTransaction();
            $meetingId = zoom_save_instant_meeting($meeting, $title, (int) $user['id']);
            foreach (rows("SELECT id FROM users WHERE role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL AND id<>?", [$user['id']]) as $recipient) {
                query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$recipient['id'], $user['id'], 'Zoom meeting started: ' . $title, 'Join now: open Zoom meetings in the portal and click “Join here”.']);
            }
            db()->commit();
            redirect('zoom-join.php?id=' . $meetingId);
        }
        if ($action === 'add_meeting') {
            $title = text_input('title', 150);
            $link = optional_text('join_url', 2048);
            $parsed = zoom_parse_link($link);
            $number = $parsed['number'] ?? zoom_meeting_number(optional_text('meeting_number', 30));
            if ($number === '' && $link !== '') $number = zoom_meeting_number($link);
            if ($number === '') throw new InvalidArgumentException('Paste the Zoom invite link, or enter the 9–11 digit Meeting ID.');
            if ($link !== '' && !$parsed && !preg_match('/^[\d\s-]+$/', $link)) throw new InvalidArgumentException('That does not look like a Zoom link. Paste a link such as https://zoom.us/j/12345678901?pwd=…');
            $passcode = optional_text('passcode', 100);
            $start = strtotime((string) ($_POST['starts_at'] ?? ''));
            if (!$start) throw new InvalidArgumentException('Choose the meeting date and time.');
            $duration = max(15, min(600, (int) ($_POST['duration_minutes'] ?? 60)));
            $joinUrl = $parsed ? (preg_match('#^https?://#i', $link) ? $link : 'https://' . $link) : 'https://zoom.us/j/' . $number;
            db()->beginTransaction();
            query('INSERT INTO zoom_meetings (title,meeting_number,passcode,join_url,starts_at,duration_minutes,notes,created_by) VALUES (?,?,?,?,?,?,?,?)', [$title, $number, $passcode, $joinUrl, date('Y-m-d H:i:s', $start), $duration, optional_text('notes', 1000), $user['id']]);
            if (!empty($_POST['notify'])) {
                $message = date('D j M, g:i A', $start) . ' · Open Zoom meetings in the portal to join.';
                foreach (rows("SELECT id FROM users WHERE role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL AND id<>?", [$user['id']]) as $recipient) {
                    query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$recipient['id'], $user['id'], 'Zoom meeting: ' . $title, $message]);
                }
            }
            db()->commit();
            flash('Meeting added.');
        } elseif ($action === 'delete_meeting') {
            query('UPDATE zoom_meetings SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [positive_id($_POST['id'] ?? null)]);
            flash('Meeting deleted.');
        } else {
            throw new InvalidArgumentException('Invalid request.');
        }
        redirect('zoom-meetings.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
    }
}

// Upcoming = not finished yet (start + duration + 30 min grace); past = last 30 days.
$upcoming = rows('SELECT z.*,u.full_name AS creator FROM zoom_meetings z LEFT JOIN users u ON u.id=z.created_by WHERE z.deleted_at IS NULL AND z.starts_at>=NOW()-INTERVAL (z.duration_minutes+30) MINUTE ORDER BY z.starts_at');
$past = rows('SELECT z.*,u.full_name AS creator FROM zoom_meetings z LEFT JOIN users u ON u.id=z.created_by WHERE z.deleted_at IS NULL AND z.starts_at<NOW()-INTERVAL (z.duration_minutes+30) MINUTE AND z.starts_at>=NOW()-INTERVAL 30 DAY ORDER BY z.starts_at DESC LIMIT 20');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = static fn(string $k, string $d = ''): string => (string) ($isPost ? ($_POST[$k] ?? $d) : $d);
$when = static function (array $m): array {
    $start = strtotime($m['starts_at']); $end = $start + (int) $m['duration_minutes'] * 60; $now = time();
    $day = date('Y-m-d', $start);
    $dayLabel = $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D, j M', $start));
    $live = $now >= $start - 600 && $now <= $end + 1800;
    return [$dayLabel . ' · ' . date('g:i A', $start) . ' – ' . date('g:i A', $end), $live];
};

page_start('Zoom meetings', 'zoom-meetings.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Zoom meetings</p>
        <h1 class="ot-title">Zoom meetings</h1>
        <p class="ot-sub">Join team meetings right here in the portal, or open them in the Zoom app.</p>
    </header>
    <?php if (!zoom_ready() && $user['role'] !== 'admin'): ?><p class="alert error">Zoom is not set up yet. Ask the Managing Director to add the Zoom details. Until then use “Open in Zoom app”.</p><?php endif; ?>
    <?php if ($user['role'] === 'admin' && (!zoom_ready() || zoom_config_from_portal())): $editing = !zoom_ready() || isset($_GET['zoom_keys']); ?>
    <section class="ot-card zm-keys" id="zoom-keys">
        <div class="ot-card-head"><h2>Zoom app details</h2><?php if (zoom_ready()): ?><span class="zm-ok">Saved · Client ID <?= h(zoom_config()['client_id']) ?></span><?php endif; ?></div>
        <?php if ($editing): ?>
            <p class="ot-help">From <a href="https://marketplace.zoom.us/user/build" target="_blank" rel="noopener noreferrer">Zoom Marketplace → your app</a> → <b>App Credentials</b>, copy the Client ID and Client Secret and paste them here. The secret is saved encrypted.</p>
            <form method="post" class="ot-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_zoom_credentials">
                <div class="ot-two">
                    <label><span>Client ID *</span><input name="client_id" maxlength="100" required value="<?= h(zoom_config()['client_id']) ?>" placeholder="e.g. hgj7RrZn…"></label>
                    <label><span>Client Secret *</span><input type="password" name="client_secret" maxlength="200" required autocomplete="new-password" placeholder="Paste the Client Secret"></label>
                </div>
                <div class="ot-actions"><button class="ot-dark" type="submit">Save Zoom details</button><?php if (zoom_ready()): ?><a href="zoom-meetings.php">Cancel</a><?php endif; ?></div>
            </form>
        <?php else: ?>
            <div class="ot-actions"><a href="zoom-meetings.php?zoom_keys=1#zoom-keys">Change Client ID / Secret</a></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canManage && zoom_ready()): $connected = zoom_connected(); ?>
    <section class="ot-card zm-instant">
        <div class="ot-card-head"><h2>Instant meeting</h2><?php if ($connected): ?><span class="zm-ok">Zoom connected<?= zoom_setting('zoom_account_email') !== '' ? ' · ' . h(zoom_setting('zoom_account_email')) : '' ?></span><?php endif; ?></div>
        <?php if ($connected): ?>
            <form method="post" class="zm-instant-form"><?= csrf_field() ?><input type="hidden" name="action" value="instant_meeting">
                <input name="title" maxlength="150" placeholder="Meeting title (optional)">
                <button class="ot-gold" type="submit"><?= icon('video') ?> Start instant meeting</button>
            </form>
            <p class="ot-help">Zoom creates the meeting, the whole team gets a notification, and you join as host. Team members can join before you. You can also start one from any chat with the 📹 button.</p>
            <?php if ($user['role'] === 'admin'): ?><form method="post" action="zoom-connect.php" data-confirm="Disconnect the Zoom account? Instant meetings will stop until you connect again."><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><button type="submit" class="zm-del">Disconnect Zoom account</button></form><?php endif; ?>
        <?php elseif ($user['role'] === 'admin'): ?>
            <p class="ot-help">Connect your Zoom account once so the portal can create meetings with one click.</p>
            <ol class="zm-steps">
                <li>In <a href="https://marketplace.zoom.us/user/build" target="_blank" rel="noopener noreferrer">Zoom Marketplace → your app</a> → <b>Basic Information</b>, set <b>OAuth Redirect URL</b> and add it to the <b>OAuth Allow List</b>:<br><code class="zm-code"><?= h(zoom_redirect_uri()) ?></code> <button type="button" class="zm-link" data-copy="<?= h(zoom_redirect_uri()) ?>">Copy</button></li>
                <li>In <b>Scopes</b>, add: <b>meeting:write:meeting</b>, <b>user:read:user</b>, <b>user:read:zak</b> (older apps: meeting:write, user:read, user_zak:read).</li>
                <li>Click the button below and approve in Zoom.</li>
            </ol>
            <div class="ot-actions"><a class="ot-dark zm-btn" href="zoom-connect.php">Connect Zoom account</a></div>
        <?php else: ?>
            <p class="ot-help">The Managing Director needs to connect the office Zoom account before instant meetings can start.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="<?= $canManage ? 'ot-grid' : '' ?> zm-grid">
        <?php if ($canManage): ?>
        <section class="ot-card">
            <h2>Add a meeting</h2>
            <form method="post" class="ot-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="add_meeting">
                <label><span>Meeting title *</span><input name="title" maxlength="150" required value="<?= h($val('title')) ?>" placeholder="e.g. Monday team meeting"></label>
                <label><span>Zoom invite link</span><input name="join_url" maxlength="2048" value="<?= h($val('join_url')) ?>" placeholder="https://us05web.zoom.us/j/12345678901?pwd=…"></label>
                <div class="ot-two">
                    <label><span>or Meeting ID</span><input name="meeting_number" inputmode="numeric" maxlength="30" value="<?= h($val('meeting_number')) ?>" placeholder="123 4567 8901"></label>
                    <label><span>Passcode</span><input name="passcode" maxlength="100" value="<?= h($val('passcode')) ?>" placeholder="From the Zoom invite"></label>
                </div>
                <div class="ot-two">
                    <label><span>Date &amp; time *</span><input type="datetime-local" name="starts_at" required value="<?= h($val('starts_at', date('Y-m-d\TH:00', strtotime('+1 hour')))) ?>"></label>
                    <label><span>Duration</span><select name="duration_minutes"><?php foreach ([15, 30, 45, 60, 90, 120, 180] as $m): ?><option value="<?= $m ?>"<?= (int) $val('duration_minutes', '60') === $m ? ' selected' : '' ?>><?= $m < 60 ? $m . ' min' : ($m / 60) . ' hour' . ($m > 60 ? 's' : '') ?></option><?php endforeach; ?></select></label>
                </div>
                <label><span>Notes <small>(optional)</small></span><input name="notes" maxlength="1000" value="<?= h($val('notes')) ?>" placeholder="Agenda, who should join…"></label>
                <label class="zm-check"><input type="checkbox" name="notify" value="1" <?= !$isPost || !empty($_POST['notify']) ? 'checked' : '' ?>> Notify the whole team</label>
                <p class="ot-help">Create the meeting in your Zoom account first, then paste its invite link and passcode here.</p>
                <div class="ot-actions"><button class="ot-dark" type="submit">Add meeting</button></div>
            </form>
        </section>
        <?php endif; ?>

        <section class="ot-card">
            <div class="ot-card-head"><h2>Upcoming meetings</h2><span><?= count($upcoming) ?></span></div>
            <?php if (!$upcoming): ?><p class="ot-help">No meetings scheduled.<?= $canManage ? ' Add one on the left.' : '' ?></p><?php endif; ?>
            <div class="zm-list">
                <?php foreach ($upcoming as $m): [$label, $live] = $when($m); ?>
                <article class="zm-item<?= $live ? ' is-live' : '' ?>">
                    <span class="zm-icon"><?= icon('video') ?></span>
                    <div class="zm-info">
                        <strong><?= h($m['title']) ?><?php if ($live): ?> <em class="zm-live">Live now</em><?php endif; ?></strong>
                        <small><?= h($label) ?></small>
                        <small>ID <?= h(trim(chunk_split($m['meeting_number'], 4, ' '))) ?><?= $m['passcode'] !== '' ? ' · Passcode ' . h($m['passcode']) : '' ?><?= $m['creator'] ? ' · by ' . h($m['creator']) : '' ?></small>
                        <?php if ($m['notes'] !== ''): ?><small class="zm-notes"><?= h($m['notes']) ?></small><?php endif; ?>
                    </div>
                    <div class="zm-actions">
                        <?php if (zoom_ready()): ?><a class="ot-gold zm-btn" href="zoom-join.php?id=<?= (int) $m['id'] ?>">Join here</a><?php endif; ?>
                        <a class="zm-link" href="<?= h($m['join_url'] ?: 'https://zoom.us/j/' . $m['meeting_number']) ?>" target="_blank" rel="noopener noreferrer">Open in Zoom app</a>
                        <?php if ($canManage): ?><form method="post" data-confirm="Delete the meeting “<?= h($m['title']) ?>”?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_meeting"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button type="submit" class="zm-del">Delete</button></form><?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php if ($past): ?>
            <details class="zm-past"><summary>Past meetings (last 30 days)</summary>
                <div class="zm-list">
                    <?php foreach ($past as $m): [$label] = $when($m); ?>
                    <article class="zm-item is-past"><span class="zm-icon"><?= icon('video') ?></span><div class="zm-info"><strong><?= h($m['title']) ?></strong><small><?= h($label) ?></small></div>
                        <?php if ($canManage): ?><div class="zm-actions"><form method="post" data-confirm="Delete the meeting “<?= h($m['title']) ?>”?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_meeting"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button type="submit" class="zm-del">Delete</button></form></div><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
document.addEventListener('click', e => {
    const copy = e.target.closest('[data-copy]'); if (!copy) return;
    navigator.clipboard?.writeText(copy.dataset.copy).then(() => { const t = copy.textContent; copy.textContent = 'Copied'; setTimeout(() => { copy.textContent = t; }, 1200); });
});
</script>
<?php page_end(); ?>
