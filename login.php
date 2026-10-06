<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) { redirect(dashboard_path(current_user())); }
if (!count_value('SELECT COUNT(*) FROM users')) { redirect('setup.php'); }
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in | Bhavi</title><link rel="stylesheet" href="assets/css/portal.css?v=<?= filemtime(__DIR__.'/assets/css/portal.css') ?>"><link rel="stylesheet" href="assets/css/login.css?v=<?= filemtime(__DIR__.'/assets/css/login.css') ?>"></head><body class="login-page">
<main class="login-shell">
<section class="login-form-panel"><div class="login-card"><div class="login-identity"><span class="brand-icon" aria-label="Bhavi">B</span></div><h1>Welcome back</h1><p class="muted">Sign in with your assigned username and password.</p>
<?php foreach ($_SESSION['flash'] ?? [] as $message): ?><div class="alert"><?= h($message['message']) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php if ($error): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
<form action="authenticate.php" method="post"><?= csrf_field() ?><div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" maxlength="100" autocapitalize="none" spellcheck="false" required></div><div class="field"><label for="password">Password</label><div class="password-wrapper"><input type="password" id="password" name="password" autocomplete="current-password" required><button type="button" data-password-toggle="password" aria-controls="password" aria-pressed="false">Show</button></div></div><button class="primary" type="submit">Sign in</button></form><p class="help">Need login access? Contact your administrator.</p></div></section></main><script src="assets/js/portal.js" defer></script></body></html>
