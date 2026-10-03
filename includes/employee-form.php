<?php
require_once __DIR__ . '/layout.php';
$user = require_roles(['admin','manager']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id && $user['role'] !== 'admin') { fail(403,'Only an administrator can create accounts.'); }
$record = $id ? one('SELECT u.id,u.full_name,u.email,u.username,u.role,u.account_status,ep.department_id,ep.designation,ep.joining_date,ep.manager_id FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.deleted_at IS NULL',[$id]) : null;
if ($id && (!$record || !in_array($record['role'],$user['role']==='admin'?['employee','manager']:['employee'],true))) { fail(403,'You cannot edit this account.'); }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $name = text_input('employee_name',150);
        $email = text_input('email',254,false);
        $username = text_input('username',100);
        if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Enter a valid email address.'); }
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/D',$username)) { throw new InvalidArgumentException('Use at least 3 letters, numbers, dots, underscores or hyphens for the username.'); }
        $role = $record['role'] ?? ($_POST['role'] ?? 'employee');
        if (!in_array($role,['employee','manager'],true)) { throw new InvalidArgumentException('Choose Employee or Manager.'); }
        $status = $_POST['account_status'] ?? '';
        if (!in_array($status,['active','inactive'],true)) { throw new InvalidArgumentException('Choose a valid account status.'); }
        $password = $_POST['temporary_password'] ?? '';
        if (!is_string($password) || (!$id && strlen($password)<8) || ($password!=='' && (strlen($password)<8 || strlen($password)>72))) { throw new InvalidArgumentException('Passwords must have 8–72 characters.'); }
        $department = $manager = null;
        if ($role==='employee') {
            $department = positive_id($_POST['department_id'] ?? null);
            if (!one('SELECT id FROM departments WHERE id=? AND is_active=1',[$department])) { throw new InvalidArgumentException('Choose a valid department.'); }
            $designation = text_input('designation',150);
            $joiningDate = date_input('joining_date');
            if (!empty($_POST['manager_id'])) {
                $manager = positive_id($_POST['manager_id']);
                if (!one("SELECT id FROM users WHERE id=? AND role='manager' AND account_status='active' AND deleted_at IS NULL",[$manager])) { throw new InvalidArgumentException('Choose an active manager.'); }
            }
        }
        db()->beginTransaction();
        if ($id) {
            query('UPDATE users SET full_name=?,email=?,username=?,account_status=? WHERE id=?',[$name,$email?:null,$username,$status,$id]);
            if ($password!=='') { query('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]); }
        } else {
            query('INSERT INTO users (full_name,email,username,password_hash,role,account_status,created_by,must_change_password) VALUES (?,?,?,?,?,?,?,0)',[$name,$email?:null,$username,password_hash($password,PASSWORD_DEFAULT),$role,$status,$user['id']]);
            $id = (int) db()->lastInsertId();
        }
        if ($role==='employee') {
            query('INSERT INTO employee_profiles (user_id,department_id,designation,joining_date,manager_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE department_id=VALUES(department_id),designation=VALUES(designation),joining_date=VALUES(joining_date),manager_id=VALUES(manager_id)',[$id,$department,$designation,$joiningDate,$manager]);
        }
        db()->commit(); flash('Account saved.'); redirect('admin-employees.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$value = static fn(string $key,$fallback='') => $_POST[$key] ?? $record[$key] ?? $fallback;
$managers = rows("SELECT id,full_name FROM users WHERE role='manager' AND account_status='active' AND deleted_at IS NULL ORDER BY full_name");
page_start($record ? 'Edit '.$record['role'] : 'Add employee / manager','admin-add-employee.php'); error_message($error);
?><section class="panel"><form method="post"><?= csrf_field() ?><div class="fields">
<div class="field"><label for="employee_name">Full name</label><input id="employee_name" name="employee_name" value="<?= h($_POST['employee_name'] ?? $record['full_name'] ?? '') ?>" maxlength="150" required></div>
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= h($value('email')) ?>" maxlength="254"></div>
<div class="field"><label for="role">Role</label><select id="role" name="role" <?= $record ? 'disabled' : '' ?>><option value="employee" <?= $value('role','employee')==='employee'?'selected':'' ?>>Employee</option><option value="manager" <?= $value('role')==='manager'?'selected':'' ?>>Manager</option></select></div>
<div class="field"><label for="department_id">Department (employees)</label><select id="department_id" name="department_id"><option value="">Select department</option><?= options(departments(),'name',$value('department_id')) ?></select></div>
<div class="field"><label for="designation">Designation (employees)</label><input id="designation" name="designation" value="<?= h($value('designation')) ?>" maxlength="150"></div>
<div class="field"><label for="joining_date">Joining date (employees)</label><input type="date" id="joining_date" name="joining_date" value="<?= h($value('joining_date',today())) ?>"></div>
<div class="field"><label for="username">Username</label><input id="username" name="username" value="<?= h($value('username')) ?>" maxlength="100" pattern="[A-Za-z0-9._\-]{3,100}" autocomplete="off" required></div>
<div class="field"><label for="temporary_password"><?= $record ? 'New password (leave blank to keep current)' : 'Password' ?></label><input type="password" id="temporary_password" name="temporary_password" minlength="8" maxlength="72" autocomplete="new-password" <?= !$record?'required':'' ?>></div>
<div class="field"><label for="manager_id">Reports to (employees)</label><select id="manager_id" name="manager_id"><option value="">All managers</option><?= options($managers,'full_name',$value('manager_id')) ?></select></div>
<div class="field"><label for="account_status">Account status</label><select id="account_status" name="account_status"><?= options([['id'=>'active','name'=>'Active'],['id'=>'inactive','name'=>'Inactive']],'name',$value('account_status','active')) ?></select></div>
</div><div class="form-actions"><button class="primary" type="submit"><?= $record?'Save changes':'Create account' ?></button><a class="button secondary" href="admin-employees.php">Cancel</a></div></form></section><?php page_end(); ?>
