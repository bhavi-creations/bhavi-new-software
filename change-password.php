<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_roles();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $old = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $password = password_input('new_password');
        if (!password_verify($old, $user['password_hash'])) { throw new InvalidArgumentException('Your current password is incorrect.'); }
        if (strlen($password) < 8 || $password !== ($_POST['confirm_password'] ?? '')) { throw new InvalidArgumentException('Enter matching new passwords with at least 8 characters.'); }
        query('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?', [password_hash($password,PASSWORD_DEFAULT),$user['id']]);
        session_regenerate_id(true);
        flash('Password updated.'); redirect('change-password.php');
    } catch (Throwable $e) { $error = mutation_error($e); }
}
page_start('Change password','change-password.php'); error_message($error);
?><div class="panel"><form method="post"><?= csrf_field() ?><div class="fields"><?php foreach (['current_password'=>'Current password','new_password'=>'New password','confirm_password'=>'Confirm new password'] as $name=>$label): ?><div class="field full"><label for="<?= $name ?>"><?= $label ?></label><input type="password" name="<?= $name ?>" id="<?= $name ?>" autocomplete="<?= $name==='current_password'?'current-password':'new-password' ?>" required maxlength="72" <?= $name !== 'current_password' ? 'minlength="8"' : '' ?>></div><?php endforeach; ?></div><button type="submit" class="primary">Update password</button></form></div><?php page_end(); ?>
