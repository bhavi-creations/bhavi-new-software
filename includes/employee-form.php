<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__.'/employee-documents.php';
require_once __DIR__.'/finance.php';
$user = require_roles(['admin','manager']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id && $user['role'] !== 'admin') { fail(403,'Only an administrator can create accounts.'); }
$record = $id ? one('SELECT u.id,u.full_name,u.email,u.username,u.role,u.avatar_path,u.account_status,ep.department_id,ep.designation,ep.joining_date,ep.manager_id,ep.role_title,ep.employee_code,ep.phone,ep.guardian_phone,ep.relieving_date,ep.benefit_pf,ep.benefit_esi,ep.benefit_other,ep.other_benefits,ep.esi_basis FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.deleted_at IS NULL',[$id]) : null;
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
        $photo=null;
        $file=$_FILES['employee_photo']??null;
        if ($file && $file['error']!==UPLOAD_ERR_NO_FILE) {
            if ($file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name'])>50*1024*1024) throw new InvalidArgumentException('Choose an employee photo up to 50 MB.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true) || !@getimagesize($file['tmp_name'])) throw new InvalidArgumentException('Choose a valid JPG, PNG, WebP or GIF employee photo.');
            $photo=['mime'=>$mime,'tmp_path'=>$file['tmp_name']];
        }
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
        $phone=optional_text('phone',30); $guardian=optional_text('guardian_phone',30);
        $employeeCode=optional_text('employee_code',50);
        $relieving=optional_date('relieving_date');
        if ($relieving && $relieving<$joiningDate) throw new InvalidArgumentException('Relieving date cannot be before joining date.');
        $benefits=['benefit_pf'=>isset($_POST['benefit_pf'])?1:0,'benefit_esi'=>isset($_POST['benefit_esi'])?1:0,'benefit_other'=>isset($_POST['benefit_other'])?1:0,'other_benefits'=>optional_text('other_benefits'),'esi_basis'=>'half'];
        if (!$benefits['benefit_other']) $benefits['other_benefits']='';
        $salaryEntries=salary_rows_input();
        db()->beginTransaction();
        if ($id) {
            one('SELECT user_id FROM employee_profiles WHERE user_id=? FOR UPDATE',[$id]);
            if (one('SELECT id FROM employee_salary_history WHERE employee_id=? AND (effective_from<? OR (? IS NOT NULL AND (effective_from>? OR effective_to>?))) LIMIT 1',[$id,$joiningDate,$relieving,$relieving,$relieving])) throw new InvalidArgumentException('Employment dates conflict with saved salary history.');
        }
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
        query('UPDATE employee_profiles SET employee_code=?,phone=?,guardian_phone=?,relieving_date=?,benefit_pf=?,benefit_esi=?,benefit_other=?,other_benefits=?,esi_basis=? WHERE user_id=?',array_merge([$employeeCode?:null,$phone,$guardian,$relieving],array_values($benefits),[$id]));
        save_salary_rows($id,$salaryEntries,$benefits,$joiningDate,$relieving,(int)$user['id']);
        if ($relieving) query('UPDATE employee_salary_history SET effective_to=? WHERE employee_id=? AND effective_to IS NULL',[$relieving,$id]);
        if ($photo) {
            $path=store_employee_document($photo); $storedDocuments[]=$path;
            query('UPDATE users SET avatar_path=? WHERE id=?',[$path,$id]);
        }
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
        if (!empty($_FILES['employee_photo']['name'])) $error.=' Please select the employee photo again before saving.';
    }
}
$value = static fn(string $key,$fallback='') => $_POST[$key] ?? $record[$key] ?? $fallback;
page_start($record ? 'Edit employee' : 'Add employee','admin-add-employee.php'); error_message($error);
?>
<p class="muted">Fill in the details below, then click Save employee.</p>
<form method="post" enctype="multipart/form-data" class="record-form"><?= csrf_field() ?>
<section class="panel form-section"><h2><span class="section-number">1</span> Personal details</h2><div class="fields">
<div class="field"><label for="employee_name">Full name *</label><input id="employee_name" name="employee_name" value="<?= h($_POST['employee_name'] ?? $record['full_name'] ?? '') ?>" maxlength="150" required></div>
<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= h($value('email')) ?>" maxlength="254"></div>
<?php foreach (['phone'=>'Phone number','guardian_phone'=>'Guardian phone number'] as $key=>$label): ?><div class="field"><label for="<?= $key ?>"><?= $label ?></label><input id="<?= $key ?>" name="<?= $key ?>" type="tel" maxlength="30" value="<?= h($value($key)) ?>"></div><?php endforeach; ?>
<div class="field"><label for="employee_photo">Employee photo</label><input id="employee_photo" name="employee_photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif"><p class="help">Photo up to 50 MB. Leave empty to keep the current photo.</p><?php if (!empty($record['avatar_path'])): ?><img class="client-logo" src="employee-photo.php?id=<?= $record['id'] ?>" alt="Employee photo"><?php endif; ?></div>
</div></section>
<section class="panel form-section"><h2><span class="section-number">2</span> Work details</h2><div class="fields">
<div class="field"><label for="employee_code">Employee ID</label><input id="employee_code" name="employee_code" maxlength="50" value="<?= h($value('employee_code')) ?>" placeholder="e.g. BH-001"></div>
<div class="field"><label for="department_id">Department *</label><select id="department_id" name="department_id" required><option value="">Select department</option><?= options(departments(),'name',$value('department_id')) ?></select></div>
<div class="field"><label for="designation">Designation *</label><input id="designation" name="designation" required value="<?= h($value('designation')) ?>" maxlength="150" placeholder="e.g. Graphic designer"></div>
<div class="field"><label for="joining_date">Joining date *</label><input type="date" id="joining_date" name="joining_date" required value="<?= h($value('joining_date',today())) ?>"></div>
<div class="field"><label for="account_status">Status</label><select id="account_status" name="account_status"><?= options([['id'=>'active','name'=>'Active'],['id'=>'inactive','name'=>'Inactive']],'name',$value('account_status','active')) ?></select><p class="help">Inactive employees cannot log in.</p></div>
<div class="field"><label for="relieving_date">Relieving date (if leaving)</label><input type="date" id="relieving_date" name="relieving_date" value="<?= h($value('relieving_date')) ?>"></div>
</div><details class="simple-details"><summary>Additional job title (optional)</summary><div class="field"><label for="role_title">Job title</label><input id="role_title" name="role_title" value="<?= h($value('role_title','Employee')) ?>" maxlength="150"></div></details></section>
<section class="panel form-section"><h2><span class="section-number">3</span> Salary & benefits</h2>
<p class="help">Tick the benefits that apply to this employee.</p><div class="benefit-options"><?php foreach (['pf'=>'PF','esi'=>'ESI','other'=>'Other'] as $key=>$label): ?><label><input type="checkbox" name="benefit_<?= $key ?>" value="1" <?= ($_SERVER['REQUEST_METHOD']==='POST'?isset($_POST['benefit_'.$key]):!empty($record['benefit_'.$key]))?'checked':'' ?>><?= $label ?></label><?php endforeach; ?></div>
<div class="field" id="other-benefits-field"><label for="other_benefits">Other benefit details</label><input id="other_benefits" name="other_benefits" maxlength="255" value="<?= h($value('other_benefits')) ?>" placeholder="e.g. Travel allowance"></div>
<p class="help"><?= $record?'To change salary or benefits, add the new amount and its start date. Leave the salary rows empty to keep the saved history.':'Enter the monthly salary and the date it starts.' ?> The end date can be left empty.</p>
<div id="salary-rows"><?php $draft=$_POST['salary']??[['amount'=>'','from'=>'','to'=>'']]; if (!is_array($draft)) $draft=[]; foreach ($draft as $index=>$entry): if (!is_array($entry)) continue; ?><div class="fields repeat-row"><div class="field"><label>Monthly salary (₹)<input type="number" name="salary[<?= (int)$index ?>][amount]" min="0.01" max="9999999999.99" step="0.01" placeholder="e.g. 14000" value="<?= h($entry['amount']??'') ?>"></label></div><div class="field"><label>Salary starts on<input type="date" name="salary[<?= (int)$index ?>][from]" value="<?= h($entry['from']??'') ?>"></label></div><div class="field"><label>Ends on (optional)<input type="date" name="salary[<?= (int)$index ?>][to]" value="<?= h($entry['to']??'') ?>"></label></div><button type="button" class="secondary" data-remove-row>Remove</button></div><?php endforeach; ?></div>
<button type="button" class="secondary" data-add-row="salary">+ Add another salary / increment</button><div id="salary-preview" aria-live="polite"></div>
<details class="simple-details"><summary>How are PF & ESI calculated?</summary><p>Both use half of the monthly salary. PF: employee 12%, company 12%. ESI: employee 0.75%, company 3.25%.</p><p>Only the employee share is deducted from salary. The company pays its share separately. An unticked benefit has no deduction.</p><p>A new salary start date automatically ends the previous ongoing salary on the day before.</p></details>
</section>
<section class="panel form-section"><h2><span class="section-number">4</span> Login details</h2><div class="fields">
<div class="field"><label for="username">Username *</label><input id="username" name="username" value="<?= h($value('username')) ?>" maxlength="100" pattern="[A-Za-z0-9._\-]{3,100}" autocomplete="off" required></div>
<div class="field"><label for="temporary_password"><?= $record ? 'New password (optional)' : 'Password *' ?></label><input type="password" id="temporary_password" name="temporary_password" minlength="8" maxlength="72" autocomplete="new-password" <?= !$record?'required':'' ?>><p class="help"><?= $record?'Leave empty to keep the current password.':'Use at least 8 characters.' ?></p></div>
</div></section>
<section class="panel form-section"><h2><span class="section-number">5</span> Documents</h2><div class="field"><label for="documents">Upload employee documents (optional)</label><input id="documents" name="documents[]" type="file" accept="image/*,application/pdf,.pdf" multiple><p class="help">Images or PDFs. Maximum 100 MB per file; up to 20 files. Saved documents will stay.</p></div></section>
<div class="form-actions"><button class="primary" type="submit">Save employee</button><a class="button secondary" href="admin-employees.php">Cancel</a></div></form>
<?php if ($record): ?><section class="panel"><details class="simple-details"><summary>View saved salary history</summary><?php salary_table(rows('SELECT * FROM employee_salary_history WHERE employee_id=? ORDER BY effective_from',[$record['id']])); ?></details></section>
<?php $documents=rows('SELECT id,original_name,created_at FROM employee_documents WHERE employee_id=? ORDER BY id DESC',[$record['id']]); ?><section class="panel" id="saved-documents"><h2>Saved documents (<?= count($documents) ?>)</h2><?php if (!$documents): ?><p class="muted">No documents uploaded.</p><?php endif; foreach ($documents as $document): ?><p><a href="download-employee-document.php?id=<?= $document['id'] ?>"><?= h($document['original_name']) ?></a> <small><?= h($document['created_at']) ?></small></p><?php endforeach; ?></section><?php endif; ?>
<script src="assets/js/records.js" defer></script><?php page_end(); ?>
