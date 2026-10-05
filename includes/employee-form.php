<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__.'/employee-documents.php';
$user = require_roles(['admin','manager']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id && $user['role'] !== 'admin') { fail(403,'Only an administrator can create accounts.'); }
$record = $id ? one('SELECT u.id,u.full_name,u.email,u.username,u.role,u.account_status,ep.department_id,ep.designation,ep.joining_date,ep.manager_id,ep.role_title FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.deleted_at IS NULL',[$id]) : null;
if ($id && (!$record || !in_array($record['role'],['employee'],true))) { fail(403,'You cannot edit this account.'); }
$error = null;
$storedDocuments=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $name = text_input('employee_name',150);
        $email = text_input('email',254,false);
        $username = text_input('username',100);
        if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Enter a valid email address.'); }
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/D',$username)) { throw new InvalidArgumentException('Use at least 3 letters, numbers, dots, underscores or hyphens for the username.'); }
        $role = 'employee';
        $roleTitle=isset($_POST['role_title'])?text_input('role_title',150,false):'Employee';
        $documents=employee_document_uploads();
        $status = $_POST['account_status'] ?? '';
        if (!in_array($status,['active','inactive'],true)) { throw new InvalidArgumentException('Choose a valid account status.'); }
        $password = $_POST['temporary_password'] ?? '';
        if (!is_string($password) || (!$id && strlen($password)<8) || ($password!=='' && (strlen($password)<8 || strlen($password)>72))) { throw new InvalidArgumentException('Passwords must have 8–72 characters.'); }
        $department = $manager = null;
        if ($role==='employee') {
            $department = filter_var($_POST['department_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$department) throw new InvalidArgumentException('Please select a department for this employee.');
            if (!one('SELECT id FROM departments WHERE id=? AND is_active=1',[$department])) { throw new InvalidArgumentException('Choose a valid department.'); }
            $designation = text_input('designation',150);
            $joiningDate = date_input('joining_date');

        }
        db()->beginTransaction();
        if (!one('SELECT id FROM departments WHERE id=? AND is_active=1 FOR UPDATE',[$department])) throw new InvalidArgumentException('This department is no longer available. Choose another department.');
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
        query('UPDATE employee_profiles SET role_title=? WHERE user_id=?',[$roleTitle?:'Employee',$id]);
        foreach ($documents as $document) {
            $path=store_employee_document($document); $storedDocuments[]=$path;
            query('INSERT INTO employee_documents (employee_id,original_name,mime_type,file_path,uploaded_by) VALUES (?,?,?,?,?)',[$id,$document['name'],$document['mime'],$path,$user['id']]);
        }
        db()->commit(); flash('Employee saved.'.(count($documents)?' '.count($documents).' document(s) uploaded successfully.':'')); redirect(count($documents)?'admin-add-employee.php?id='.$id.'#saved-documents':'admin-employees.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($storedDocuments as $path) if (is_file(dirname(__DIR__).'/'.$path)) unlink(dirname(__DIR__).'/'.$path);
        if (!$record) $id=0;
        $error=mutation_error($e);
        if (!empty($_FILES['documents']['name'][0])) $error.=' Please select your documents again before saving.';
    }
}
$value = static fn(string $key,$fallback='') => $_POST[$key] ?? $record[$key] ?? $fallback;
page_start($record ? 'Edit '.$record['role'] : 'Add employee','admin-add-employee.php'); error_message($error);
?><section class="panel"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><div class="fields">
<div class="field"><label for="employee_name">Full name</label><input id="employee_name" name="employee_name" value="<?= h($_POST['employee_name'] ?? $record['full_name'] ?? '') ?>" maxlength="150" required></div>
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= h($value('email')) ?>" maxlength="254"></div>
<div class="field"><label for="role_title">Role / job title</label><input id="role_title" name="role_title" value="<?= h($value('role_title','Employee')) ?>" maxlength="150"><p class="help">Employee account. You can enter a custom job title here.</p></div>
<div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" required><option value="">Select department</option><?= options(departments(),'name',$value('department_id')) ?></select></div>
<div class="field"><label for="designation">Designation</label><input id="designation" name="designation" required value="<?= h($value('designation')) ?>" maxlength="150"></div>
<div class="field"><label for="joining_date">Joining date</label><input type="date" id="joining_date" name="joining_date" required value="<?= h($value('joining_date',today())) ?>"></div>
<div class="field"><label for="username">Username</label><input id="username" name="username" value="<?= h($value('username')) ?>" maxlength="100" pattern="[A-Za-z0-9._\-]{3,100}" autocomplete="off" required></div>
<div class="field"><label for="temporary_password"><?= $record ? 'New password (leave blank to keep current)' : 'Password' ?></label><input type="password" id="temporary_password" name="temporary_password" minlength="8" maxlength="72" autocomplete="new-password" <?= !$record?'required':'' ?>></div>
<div class="field full"><label for="documents">Employee documents</label><input id="documents" name="documents[]" type="file" accept="image/*,application/pdf,.jpg,.jpeg,.jfif,.png,.gif,.webp,.bmp,.tif,.tiff,.heic,.heif,.avif,.svg,.ico,.pdf" multiple><p class="help">JPG, PNG, GIF, WebP, BMP, TIFF, HEIC/HEIF, AVIF, SVG and PDF. Up to 20 files per upload. Maximum 20 MB each, 100 MB total including the form. Existing documents stay saved.</p></div>
<div class="field"><label for="account_status">Account status</label><select id="account_status" name="account_status"><?= options([['id'=>'active','name'=>'Active'],['id'=>'inactive','name'=>'Inactive']],'name',$value('account_status','active')) ?></select></div>
</div><div class="form-actions"><button class="primary" type="submit"><?= $record?'Save changes':'Create account' ?></button><a class="button secondary" href="admin-employees.php">Cancel</a></div></form></section><?php if ($record): $documents=rows('SELECT id,original_name,created_at FROM employee_documents WHERE employee_id=? ORDER BY id DESC',[$record['id']]); ?><section class="panel" id="saved-documents"><h2>Saved documents (<?= count($documents) ?>)</h2><?php if (!$documents): ?><p class="muted">No documents uploaded.</p><?php endif; foreach ($documents as $document): ?><p><a href="download-employee-document.php?id=<?= $document['id'] ?>"><?= h($document['original_name']) ?></a> <small><?= h($document['created_at']) ?></small></p><?php endforeach; ?></section><?php endif; page_end(); ?>
