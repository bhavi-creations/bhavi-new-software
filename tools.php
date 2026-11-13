<?php
// Tools / Apps: premium tools the office pays for (Claude AI, Canva, ChatGPT…) and the login used for each.
require_once __DIR__ . '/includes/layout.php';
$user = require_roles(['admin']);
$error = null;

function tool_url_input(string $key): string
{
    $url = optional_text($key, 2048);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) throw new InvalidArgumentException('Enter a valid website link for the tool.');
    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_tool') {
            $id = (int) ($_POST['id'] ?? 0);
            $existing = $id ? one('SELECT * FROM premium_tools WHERE id=? AND deleted_at IS NULL', [$id]) : null;
            if ($id && !$existing) throw new InvalidArgumentException('This tool was deleted. Add it again.');
            $name = text_input('tool_name', 150);
            $email = optional_text('login_email', 255);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email (e.g. office@gmail.com).');
            $username = optional_text('username', 255);
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || strlen($password) > 1000) throw new InvalidArgumentException('Password must be 1,000 characters or fewer.');
            if ($email === '' && $username === '') throw new InvalidArgumentException('Enter the login email or the username used for this tool.');
            $cipher = $password !== '' ? credential_encrypt($password) : ($existing['password_cipher'] ?? null);
            if (!empty($_POST['clear_password'])) $cipher = null;
            $renewal = optional_date('renewal_date');
            $values = [$name, tool_url_input('tool_url'), $email, $username, $cipher, optional_text('plan_name', 100), $renewal, optional_text('notes', 1000)];
            if ($existing) {
                query('UPDATE premium_tools SET tool_name=?,tool_url=?,login_email=?,username=?,password_cipher=?,plan_name=?,renewal_date=?,notes=? WHERE id=?', array_merge($values, [$id]));
                flash($name . ' updated.');
            } else {
                query('INSERT INTO premium_tools (tool_name,tool_url,login_email,username,password_cipher,plan_name,renewal_date,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($values, [$user['id']]));
                flash($name . ' saved.');
            }
        } elseif ($action === 'delete_tool') {
            $id = positive_id($_POST['id'] ?? null);
            query('UPDATE premium_tools SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL', [$id]);
            flash('Tool deleted.');
        } else {
            throw new InvalidArgumentException('Invalid request.');
        }
        redirect('tools.php');
    } catch (Throwable $e) {
        $error = mutation_error($e);
    }
}

$tools = rows('SELECT * FROM premium_tools WHERE deleted_at IS NULL ORDER BY tool_name,id');
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null; foreach ($tools as $t) if ((int) $t['id'] === $editId) $editing = $t;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = static fn(string $k): string => (string) ($isPost ? ($_POST[$k] ?? '') : ($editing[$k] ?? ''));
$secret = static function (?string $cipher): string { try { return credential_decrypt($cipher); } catch (Throwable $e) { return ''; } };

page_start('Tools / Apps', 'tools.php');
error_message($error);
?>
<div class="ot-page">
    <header class="ot-head">
        <p class="ot-crumb">Dashboard / Tools / Apps</p>
        <h1 class="ot-title">Tools / Apps</h1>
        <p class="ot-sub">Premium tools the office has bought (Claude AI, Canva, ChatGPT…) with the email, username and password used to log in. Passwords are saved encrypted.</p>
    </header>

    <div class="ot-grid tl-grid">
        <section class="ot-card" id="tool-form">
            <h2><?= $editing ? 'Edit · ' . h($editing['tool_name']) : 'Add a tool / app' ?></h2>
            <form method="post" class="ot-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_tool"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                <label><span>Tool / app name *</span><input name="tool_name" maxlength="150" required value="<?= h($val('tool_name')) ?>" placeholder="e.g. Claude AI"></label>
                <label><span>Website link <small>(optional)</small></span><input name="tool_url" maxlength="2048" value="<?= h($val('tool_url')) ?>" placeholder="https://claude.ai"></label>
                <label><span>Login email <small>(Gmail or any email)</small></span><input type="email" name="login_email" maxlength="255" value="<?= h($val('login_email')) ?>" placeholder="office@gmail.com"></label>
                <div class="ot-two">
                    <label><span>Username</span><input name="username" maxlength="255" value="<?= h($val('username')) ?>" placeholder="Username (if any)"></label>
                    <label><span>Password<?= $editing && $editing['password_cipher'] ? ' <small>(leave empty to keep)</small>' : '' ?></span><span class="tl-pass"><input type="password" id="tool-password" name="password" maxlength="1000" autocomplete="new-password" placeholder="<?= $editing && $editing['password_cipher'] ? '••••••••' : 'Password' ?>"><button type="button" class="tl-eye" data-password-toggle="tool-password" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></span></label>
                </div>
                <div class="ot-two">
                    <label><span>Plan <small>(optional)</small></span><input name="plan_name" maxlength="100" value="<?= h($val('plan_name')) ?>" placeholder="e.g. Pro · ₹1,999 / month"></label>
                    <label><span>Renewal date <small>(optional)</small></span><input type="date" name="renewal_date" value="<?= h($val('renewal_date')) ?>"></label>
                </div>
                <label><span>Notes <small>(optional)</small></span><input name="notes" maxlength="1000" value="<?= h($val('notes')) ?>" placeholder="Who uses it, 2-step code phone, etc."></label>
                <?php if ($editing && $editing['password_cipher']): ?><label class="tl-check"><input type="checkbox" name="clear_password" value="1"> Remove saved password</label><?php endif; ?>
                <div class="ot-actions"><button class="ot-dark" type="submit"><?= $editing ? 'Save changes' : 'Save tool' ?></button><?php if ($editing): ?><a href="tools.php">Cancel</a><?php endif; ?></div>
            </form>
        </section>

        <section class="ot-card">
            <div class="ot-card-head"><h2>Saved tools</h2><span><?= count($tools) ?> tool<?= count($tools) === 1 ? '' : 's' ?></span></div>
            <?php if (!$tools): ?><p class="ot-help">No tools added yet. Add your first premium tool on the left.</p><?php endif; ?>
            <div class="tl-list">
                <?php foreach ($tools as $t): $pass = $secret($t['password_cipher']); $soon = $t['renewal_date'] && $t['renewal_date'] <= date('Y-m-d', strtotime('+7 days')); ?>
                    <article class="tl-item">
                        <div class="tl-item-head">
                            <span class="ot-avatar"><?= h(mb_strtoupper(mb_substr($t['tool_name'], 0, 2))) ?></span>
                            <div class="tl-item-name"><strong><?= h($t['tool_name']) ?></strong><?php if ($t['plan_name'] !== ''): ?><small><?= h($t['plan_name']) ?></small><?php endif; ?></div>
                            <?php if ($t['renewal_date']): ?><span class="tl-renew<?= $soon ? ' is-soon' : '' ?>">Renews <?= h(date('d M Y', strtotime($t['renewal_date']))) ?></span><?php endif; ?>
                        </div>
                        <dl class="tl-creds">
                            <?php if ($t['tool_url'] !== ''): ?><div><dt>Link</dt><dd><a href="<?= h($t['tool_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https?://#', '', $t['tool_url'])) ?></a></dd></div><?php endif; ?>
                            <?php if ($t['login_email'] !== ''): ?><div><dt>Email</dt><dd><span><?= h($t['login_email']) ?></span><button type="button" class="tl-copy" data-copy="<?= h($t['login_email']) ?>">Copy</button></dd></div><?php endif; ?>
                            <?php if ($t['username'] !== ''): ?><div><dt>Username</dt><dd><span><?= h($t['username']) ?></span><button type="button" class="tl-copy" data-copy="<?= h($t['username']) ?>">Copy</button></dd></div><?php endif; ?>
                            <div><dt>Password</dt><dd><?php if ($pass !== ''): ?><span class="tl-secret" data-secret="<?= h($pass) ?>">••••••••</span><button type="button" class="tl-copy" data-reveal>Show</button><button type="button" class="tl-copy" data-copy="<?= h($pass) ?>">Copy</button><?php else: ?><span class="muted">Not saved</span><?php endif; ?></dd></div>
                            <?php if ($t['notes'] !== ''): ?><div><dt>Notes</dt><dd><?= h($t['notes']) ?></dd></div><?php endif; ?>
                        </dl>
                        <div class="tl-item-actions">
                            <a href="tools.php?edit=<?= (int) $t['id'] ?>#tool-form"><?= icon('edit') ?> Edit</a>
                            <form method="post" data-confirm="Delete <?= h($t['tool_name']) ?> and its saved login?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_tool"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button type="submit"><?= icon('delete') ?> Delete</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<script>
document.addEventListener('click', e => {
    const copy = e.target.closest('[data-copy]');
    if (copy) { navigator.clipboard?.writeText(copy.dataset.copy).then(() => { const t = copy.textContent; copy.textContent = 'Copied'; setTimeout(() => { copy.textContent = t; }, 1200); }); return; }
    const reveal = e.target.closest('[data-reveal]');
    if (reveal) { const s = reveal.parentElement.querySelector('[data-secret]'); const show = reveal.textContent === 'Show'; s.textContent = show ? s.dataset.secret : '••••••••'; reveal.textContent = show ? 'Hide' : 'Show'; }
});
</script>
<?php page_end(); ?>
