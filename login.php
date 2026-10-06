<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) { redirect(dashboard_path(current_user())); }
if (!count_value('SELECT COUNT(*) FROM users')) { redirect('setup.php'); }
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in | Bhavi</title><link rel="stylesheet" href="assets/css/portal.css?v=<?= filemtime(__DIR__.'/assets/css/portal.css') ?>"></head><body>
<main class="login-shell"><section class="login-brand-panel"><a class="portal-brand" href="index.php"><span class="brand-icon">B</span><span><strong>bhavi</strong><small>TEAM WORKSPACE</small></span></a><div class="brand-message"><h1>One team.<br>Every task in view.</h1><p>Work, client briefs and leave requests<br>in one connected workspace.</p><p>Daily work · All departments<br>Client requirements · Clear briefs<br>Leave &amp; holidays · Better planning</p></div><small>Bhavi Creations</small></section>
<section class="login-form-panel"><div class="login-card"><h1>Welcome back</h1><p class="muted">Sign in with your assigned username and password.</p>
<?php foreach ($_SESSION['flash'] ?? [] as $message): ?><div class="alert"><?= h($message['message']) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php if ($error): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
<form action="authenticate.php" method="post"><?= csrf_field() ?><div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" maxlength="100" autocapitalize="none" spellcheck="false" required></div><div class="field"><label for="password">Password</label><div class="password-wrapper"><input type="password" id="password" name="password" autocomplete="current-password" required><button type="button" data-password-toggle="password" aria-controls="password" aria-pressed="false">Show</button></div></div><button class="primary" type="submit">Sign in</button></form><p class="help">Need login access? Contact your administrator.</p><p class="help">Your account opens your Admin, Manager or department dashboard.</p></div></section></main><script src="assets/js/portal.js" defer></script></body></html>
