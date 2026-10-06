<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/database/install.php';
$error = null;
$installed = false;
try {
    $config = require __DIR__ . '/config.php';
    $connection = new PDO("mysql:host={$config['db_host']};port={$config['db_port']};charset=utf8mb4", $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    portal_install($connection, $config['db_name']);
    $installed = true;
    if (count_value('SELECT COUNT(*) FROM users')) { redirect('login.php'); }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = 'Start MySQL in XAMPP and check the database settings in config.local.php, then refresh this page.';
}
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $adminName = text_input('admin_name', 150);
        $adminUsername = text_input('admin_username', 100);
        $adminPassword = password_input('admin_password');
        $managerName = text_input('manager_name', 150);
        $managerUsername = text_input('manager_username', 100);
        $managerPassword = password_input('manager_password');
        foreach ([$adminPassword,$managerPassword] as $password) {
            if (strlen($password) < 8) { throw new InvalidArgumentException('Passwords must have at least 8 characters.'); }
        }
        foreach ([$adminUsername,$managerUsername] as $username) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/D', $username)) { throw new InvalidArgumentException('Use 3–100 letters, numbers, dots, underscores or hyphens for usernames.'); }
        }
        $lock = 'bhavi_first_accounts_' . $config['db_name'];
        if (count_value('SELECT GET_LOCK(?,10)', [$lock]) !== 1) { throw new InvalidArgumentException('Another setup is running. Try again.'); }
        try {
            db()->beginTransaction();
            if (count_value('SELECT COUNT(*) FROM users')) { throw new InvalidArgumentException('Accounts have already been created. Please sign in.'); }
            query("INSERT INTO users (full_name,username,password_hash,role,must_change_password) VALUES (?,?,?,'admin',0)", [$adminName,$adminUsername,password_hash($adminPassword,PASSWORD_DEFAULT)]);
            $adminId = (int) db()->lastInsertId();
            query("INSERT INTO users (full_name,username,password_hash,role,created_by,must_change_password) VALUES (?,?,?,'manager',?,0)", [$managerName,$managerUsername,password_hash($managerPassword,PASSWORD_DEFAULT),$adminId]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        finally { query('SELECT RELEASE_LOCK(?)', [$lock]); }
        flash('Your administrator and manager accounts are ready. Sign in with the username and password you chose.');
        redirect('login.php');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Choose different usernames for the administrator and manager.';
        error_log($e->getMessage());
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up Bhavi</title><link rel="stylesheet" href="assets/css/portal.css?v=<?= filemtime(__DIR__.'/assets/css/portal.css') ?>"></head><body><main class="panel setup-card"><h1>Set up your team workspace</h1><p class="muted">Create your administrator and manager logins. You only need to do this once.</p>
<?php if ($error): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($installed): ?><form method="post"><?= csrf_field() ?><h2>Administrator</h2><div class="fields">
<div class="field"><label for="admin_name">Name</label><input id="admin_name" name="admin_name" value="<?= h($_POST['admin_name'] ?? '') ?>" maxlength="150" required></div><div class="field"><label for="admin_username">Username</label><input id="admin_username" name="admin_username" value="<?= h($_POST['admin_username'] ?? '') ?>" maxlength="100" autocomplete="username" required></div><div class="field full"><label for="admin_password">Password</label><input type="password" id="admin_password" name="admin_password" minlength="8" maxlength="72" autocomplete="new-password" required></div></div>
<h2>Manager</h2><div class="fields"><div class="field"><label for="manager_name">Name</label><input id="manager_name" name="manager_name" value="<?= h($_POST['manager_name'] ?? '') ?>" maxlength="150" required></div><div class="field"><label for="manager_username">Username</label><input id="manager_username" name="manager_username" value="<?= h($_POST['manager_username'] ?? '') ?>" maxlength="100" required></div><div class="field full"><label for="manager_password">Password</label><input type="password" id="manager_password" name="manager_password" minlength="8" maxlength="72" autocomplete="new-password" required></div></div><button class="primary" type="submit">Create accounts</button></form><?php endif; ?></main></body></html>
