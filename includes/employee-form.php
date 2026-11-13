<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__.'/office-timing.php';
require_once __DIR__.'/employee-documents.php';
require_once __DIR__.'/finance.php';
require_once __DIR__.'/employee-accounts.php';
$user = require_roles(['admin','manager']);
$id = (int) ($_GET['id'] ?? 0);
if (!$id && $user['role'] !== 'admin') { fail(403,'Only an administrator can create accounts.'); }
$record = $id ? one('SELECT u.id,u.full_name,u.email,u.username,u.role,u.avatar_path,u.account_status,ep.department_id,ep.designation,ep.joining_date,ep.manager_id,ep.role_title,ep.employee_code,ep.phone,ep.guardian_phone,ep.relieving_date,ep.benefit_pf,ep.benefit_esi,ep.benefit_other,ep.other_benefits,ep.other_benefit_amount,ep.esi_basis,ep.pf_number,ep.esi_number,ep.date_of_birth,ep.gender,ep.address,ep.work_timings,ep.shift_start,ep.shift_end,ep.shift_label,ep.shift_slots,ep.shift_hours,ep.employment_type FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.deleted_at IS NULL',[$id]) : null;
if ($id && (!$record || !in_array($record['role'],['employee'],true))) { fail(403,'You cannot edit this account.'); }
$error = null;
const EMPLOYEE_GENDERS = ['male'=>'Male','female'=>'Female','other'=>'Other','na'=>'Prefer not to say'];
const EMPLOYMENT_TYPES = ['full_time'=>'Full-time','part_time'=>'Part-time','intern'=>'Intern','freelancer'=>'Freelancer'];
$reportingOptions=rows("SELECT u.id,u.full_name,u.role FROM users u WHERE u.account_status='active' AND u.deleted_at IS NULL AND u.id<>? AND (u.role IN ('admin','manager') OR u.id IN (SELECT head_user_id FROM departments WHERE is_active=1 AND head_user_id IS NOT NULL)) ORDER BY u.full_name",[$id]);
$isDepartmentHead=$record && $record['department_id'] && (int)count_value('SELECT COUNT(*) FROM departments WHERE id=? AND head_user_id=?',[$record['department_id'],$id])>0;
$storedDocuments=[];
$saveStage='validate input';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $name = text_input('employee_name',150);
        $email = text_input('email',254,false);
        $username = text_input('username',100);
        if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Enter a valid email address.'); }
        if (!preg_match('/^[A-Za-z0-9._ -]{3,100}$/D',$username)) { throw new InvalidArgumentException('Use 3-100 letters, numbers, spaces, dots, underscores or hyphens for the username.'); }
        $role = 'employee';
        $saveStage='validate documents';
        $documents=employee_document_uploads();
        $saveStage='validate employee details';
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
            // "Reporting to" is no longer on the form: keep the saved value unless one is posted.
            if (!isset($_POST['manager_id'])) $manager=isset($record['manager_id'])?(int)$record['manager_id']:null;
            $managerValue=$_POST['manager_id']??'';
            if ($managerValue!=='' && $managerValue!==null) {
                $manager=positive_id($managerValue);
                if (!in_array($manager,array_map('intval',array_column($reportingOptions,'id')),true)) throw new InvalidArgumentException('Choose a valid person for Reporting to.');
            }
        }
        $dateOfBirth=optional_date('date_of_birth');
        if ($dateOfBirth && $dateOfBirth>today()) throw new InvalidArgumentException('Date of birth cannot be in the future.');
        $gender=is_string($_POST['gender']??null) && isset(EMPLOYEE_GENDERS[$_POST['gender']]) ? $_POST['gender'] : '';
        $address=optional_text('address',500);
        // Office timing (admin default) or this employee's own shift. Older forms post a plain work_timings text.
        $timingMode=isset($_POST['timing_mode'])?(string)$_POST['timing_mode']:null;
        $shift=null;
        if ($timingMode==='custom') {
            $shiftStart=office_time_input($_POST['shift_start']??'','shift start time');
            $shiftEnd=office_time_input($_POST['shift_end']??'','shift end time');
            if ($shiftStart===$shiftEnd) throw new InvalidArgumentException('Shift start and end time cannot be the same.');
            $shift=[$shiftStart,$shiftEnd,optional_text('shift_label',60)?:'Own shift'];
            $workTimings=office_range_label($shiftStart,$shiftEnd);
        } elseif ($timingMode==='split') {
            // Split timing is managed on the Office timings page: keep it as saved.
            $workTimings=(string)($record['work_timings']??'');
        } elseif ($timingMode==='office') {
            $workTimings='';
        } else {
            $workTimings=optional_text('work_timings',100);
        }
        $employmentType=is_string($_POST['employment_type']??null) && isset(EMPLOYMENT_TYPES[$_POST['employment_type']]) ? $_POST['employment_type'] : ($record['employment_type'] ?? 'full_time');
        $makeHead=($_POST['access_role']??'')==='department_head';
        $phone=optional_text('phone',30); $guardian=optional_text('guardian_phone',30);
        $employeeCode=optional_text('employee_code',50);
        $relieving=optional_date('relieving_date');
        if ($relieving && $relieving<$joiningDate) throw new InvalidArgumentException('Relieving date cannot be before joining date.');
        $benefits=array_merge(['benefit_pf'=>isset($_POST['benefit_pf'])?1:0,'benefit_esi'=>isset($_POST['benefit_esi'])?1:0],other_benefit_input(isset($_POST['benefit_other'])),['esi_basis'=>'gross']);
        $salaryEntries=salary_rows_input($id?null:$joiningDate);
        $saveStage='encrypt login credential';
        $encryptedPassword=$password!==''?credential_encrypt($password):null;
        $saveStage='save account and profile';
        db()->beginTransaction();
        if ($id && !one("SELECT id FROM users WHERE id=? AND role='employee' AND deleted_at IS NULL FOR UPDATE",[$id])) throw new InvalidArgumentException('This employee has been deleted. Add a new employee instead.');
        release_matching_deleted_employees($username,$email,$employeeCode);
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
        query('UPDATE employee_profiles SET employee_code=?,phone=?,guardian_phone=?,relieving_date=?,benefit_pf=?,benefit_esi=?,benefit_other=?,other_benefits=?,other_benefit_amount=?,esi_basis=? WHERE user_id=?',array_merge([$employeeCode?:null,$phone,$guardian,$relieving],array_values($benefits),[$id]));
        query('UPDATE employee_profiles SET pf_number=?,esi_number=? WHERE user_id=?',[optional_text('pf_number',100),optional_text('esi_number',100),$id]);
        query('UPDATE employee_profiles SET date_of_birth=?,gender=?,address=?,work_timings=?,employment_type=? WHERE user_id=?',[$dateOfBirth,$gender,$address,$workTimings,$employmentType,$id]);
        if ($timingMode!==null && $timingMode!=='split') query('UPDATE employee_profiles SET shift_start=?,shift_end=?,shift_label=?,shift_slots=NULL,shift_hours=NULL WHERE user_id=?',[$shift[0]??null,$shift[1]??null,$shift[2]??'',$id]);
        if (isset($_POST['access_role'])) {
            query('UPDATE departments SET head_user_id=NULL WHERE head_user_id=? AND id<>?',[$id,$makeHead?$department:0]);
            if ($makeHead) query('UPDATE departments SET head_user_id=? WHERE id=?',[$id,$department]);
        }
        if ($password!=='') query('UPDATE users SET credential_cipher=? WHERE id=?',[$encryptedPassword,$id]);
        $saveStage='save salary history';
        save_salary_rows($id,$salaryEntries,$benefits,$joiningDate,$relieving,(int)$user['id']);
        if ($relieving) query('UPDATE employee_salary_history SET effective_to=? WHERE employee_id=? AND effective_to IS NULL',[$relieving,$id]);
        $saveStage='save employee photo';
        if ($photo) {
            $path=store_employee_document($photo); $storedDocuments[]=$path;
            query('UPDATE users SET avatar_path=? WHERE id=?',[$path,$id]);
        }
        $saveStage='save employee documents';
        foreach ($documents as $document) {
            $path=store_employee_document($document); $storedDocuments[]=$path;
            query('INSERT INTO employee_documents (employee_id,original_name,mime_type,file_path,uploaded_by) VALUES (?,?,?,?,?)',[$id,$document['name'],$document['mime'],$path,$user['id']]);
        }
        $saveStage='commit employee';
        db()->commit(); flash('Employee saved.'.(count($documents)?' '.count($documents).' document(s) uploaded successfully.':'')); redirect(($_POST['after']??'')==='another'&&!$record?'admin-add-employee.php':(count($documents)?'admin-add-employee.php?id='.$id.'#saved-documents':'admin-employees.php'));
    } catch (Throwable $e) {
        if (!($e instanceof InvalidArgumentException)) error_log('[employee-save: '.$saveStage.'] '.get_class($e).': '.$e->getMessage());
        if (db()->inTransaction()) db()->rollBack();
        foreach ($storedDocuments as $path) if (is_file(dirname(__DIR__).'/'.$path)) unlink(dirname(__DIR__).'/'.$path);
        if (!$record) $id=0;
        $error=mutation_error($e);
        if (!($e instanceof InvalidArgumentException)) $error.=' Save step: '.$saveStage.'.';
        if (!empty($_FILES['documents']['name'][0])) $error.=' Please select your documents again before saving.';
        if (!empty($_FILES['employee_photo']['name'])) $error.=' Please select the employee photo again before saving.';
    }
}
$value = static fn(string $key,$fallback='') => $_POST[$key] ?? $record[$key] ?? $fallback;
$isPost=$_SERVER['REQUEST_METHOD']==='POST';
$suggestedCode='';
if (!$record) {
    $lastCode=count_value("SELECT COALESCE(MAX(CAST(SUBSTRING(employee_code,5) AS UNSIGNED)),0) FROM employee_profiles WHERE employee_code REGEXP '^BHV-[0-9]+$'");
    $suggestedCode='BHV-'.str_pad((string)($lastCode+1),3,'0',STR_PAD_LEFT);
}
$departmentsList=departments();
$selectedDepartment=(string)$value('department_id');
$deptName=''; foreach ($departmentsList as $d) if ((string)$d['id']===$selectedDepartment) $deptName=$d['name'];
$accessRole=$isPost?($_POST['access_role']??'employee'):($isDepartmentHead?'department_head':'employee');
$employmentType=(string)$value('employment_type','full_time');
$nameValue=(string)($_POST['employee_name'] ?? $record['full_name'] ?? '');
$cardInitials=''; foreach (array_slice(preg_split('/\s+/',trim($nameValue)) ?: [],0,2) as $part) $cardInitials.=mb_strtoupper(mb_substr($part,0,1));
$joinedValue=(string)$value('joining_date',today());
$steps=['emp-personal'=>'Personal details','emp-job'=>'Job details','emp-login'=>'Login access','emp-docs'=>'Documents'];
page_start($record ? 'Edit employee' : 'Add employee','admin-add-employee.php'); error_message($error);
?>
<form method="post" enctype="multipart/form-data" class="record-form emp-page" data-emp-form><?= csrf_field() ?>
<header class="emp-header">
    <div><p class="emp-crumb">Dashboard / Employees / <?= $record?'Edit':'Add' ?></p><h2 class="emp-title"><?= $record?'Edit employee':'Add new employee' ?></h2><p class="emp-sub">Fill in the details below. Fields marked * are required.</p></div>
    <a class="emp-btn emp-btn-outline" href="admin-employees.php">← Back to employees</a>
</header>
<nav class="emp-steps" aria-label="Form sections">
    <?php $n=0; foreach ($steps as $anchor=>$label): $n++; ?><a href="#<?= $anchor ?>" class="<?= $n===1?'active':'' ?>" data-emp-step><span><?= $n ?></span><?= h($label) ?></a><?php endforeach; ?>
</nav>
<div class="emp-layout">
<div class="emp-main">
    <section class="emp-card" id="emp-personal"><h3>1 · Personal details</h3><p class="emp-card-sub">Basic information about the employee</p>
        <div class="emp-grid">
            <label>Full name *<input id="employee_name" name="employee_name" value="<?= h($nameValue) ?>" maxlength="150" placeholder="e.g. Ravi Kumar" required data-card-src="name"></label>
            <label>Employee ID<input id="employee_code" name="employee_code" maxlength="50" value="<?= h($value('employee_code',$suggestedCode)) ?>" placeholder="e.g. BHV-001" class="emp-soft" data-card-src="code"></label>
            <label>Phone number<input id="phone" name="phone" type="tel" maxlength="30" value="<?= h($value('phone')) ?>" placeholder="98765 43210"></label>
            <label>Email<input id="email" name="email" type="email" value="<?= h($value('email')) ?>" maxlength="254" placeholder="name@bhavicreations.com"></label>
            <label>Date of birth<input type="date" name="date_of_birth" value="<?= h($value('date_of_birth')) ?>" max="<?= today() ?>"></label>
            <label>Gender<select name="gender"><option value="">Select</option><?php foreach (EMPLOYEE_GENDERS as $k=>$l): ?><option value="<?= $k ?>" <?= (string)$value('gender')===$k?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>Guardian phone number<input id="guardian_phone" name="guardian_phone" type="tel" maxlength="30" value="<?= h($value('guardian_phone')) ?>" placeholder="Emergency contact"></label>
            <label class="emp-full">Address<textarea name="address" maxlength="500" rows="2" placeholder="House no, street, area, city"><?= h($value('address')) ?></textarea></label>
        </div>
    </section>

    <section class="emp-card" id="emp-job"><h3>2 · Job details</h3><p class="emp-card-sub">Role, team and joining information</p>
        <div class="emp-grid">
            <label>Department *<select id="department_id" name="department_id" required data-card-src="dept"><option value="">Select department</option><?= options($departmentsList,'name',$selectedDepartment) ?></select></label>
            <label>Designation *<input id="designation" name="designation" required value="<?= h($value('designation')) ?>" maxlength="150" placeholder="e.g. Senior Graphic Designer" data-card-src="designation"></label>
            <label>Joining date *<input type="date" id="joining_date" name="joining_date" required value="<?= h($joinedValue) ?>" data-card-src="joined"></label>
            <?php $officeDefault=office_timing_default(); $recordSplit=$record?employee_shift_slots($record):[]; $timingMode=$isPost?(string)($_POST['timing_mode']??'office'):(count($recordSplit)>1?'split':(!empty($record['shift_start'])?'custom':'office')); ?>
            <label>Work timings<select name="timing_mode" data-timing-mode><option value="office" <?= $timingMode!=='custom'?'selected':'' ?>>Office timing · <?= h(office_range_label($officeDefault['start'],$officeDefault['end'])) ?></option><option value="custom" <?= $timingMode==='custom'?'selected':'' ?>>Different shift (e.g. night shift)</option><?php if (count($recordSplit)>1): ?><option value="split" <?= $timingMode==='split'?'selected':'' ?>>Split timing · <?= h(employee_timing($record)['text']) ?></option><?php endif; ?></select><small>Split timings (e.g. 7–10 PM + 5–8 AM) and hours: <a href="office-timings.php<?= $id?'?edit='.(int)$id.'#shift-form':'' ?>">Office timings</a></small></label>
            <div class="emp-shift" data-shift-fields<?= $timingMode==='custom'?'':' hidden' ?>>
                <label>Shift starts<?= time12_field('shift_start',$isPost?(time12_value($_POST['shift_start']??null)?:null):substr((string)($record['shift_start']??'21:00'),0,5),false,'Shift starts') ?></label>
                <label>Shift ends<?= time12_field('shift_end',$isPost?(time12_value($_POST['shift_end']??null)?:null):substr((string)($record['shift_end']??'06:00'),0,5),false,'Shift ends') ?></label>
                <label>Shift name <small>(optional)</small><input name="shift_label" maxlength="60" value="<?= h($isPost?(string)($_POST['shift_label']??''):(string)($record['shift_label']??'')) ?>" placeholder="Night shift"></label>
            </div>
            <script>(() => { const s = document.currentScript.parentElement.querySelector('[data-timing-mode]'), f = document.currentScript.parentElement.querySelector('[data-shift-fields]'); const sync = () => { const on = s.value === 'custom'; f.hidden = !on; f.querySelectorAll('.time12 select:not([name$="[ap]"])').forEach(i => { i.required = on; }); }; s.addEventListener('change', sync); sync(); })();</script>
            <label>Relieving date (if leaving)<input type="date" id="relieving_date" name="relieving_date" value="<?= h($value('relieving_date')) ?>"></label>
        </div>
        <fieldset class="emp-choice emp-choice-inline"><legend>Employment type *</legend>
            <?php foreach (EMPLOYMENT_TYPES as $k=>$l): ?><label><input type="radio" name="employment_type" value="<?= $k ?>" <?= $employmentType===$k?'checked':'' ?>><span><?= h($l) ?></span></label><?php endforeach; ?>
        </fieldset>
        <div class="emp-more emp-benefits"><p class="emp-label">Benefits <small>— tick what this employee gets</small></p>
            <div class="benefit-options emp-checks"><?php foreach (['pf'=>'PF','esi'=>'ESI','other'=>'Other'] as $key=>$label): ?><label><input type="checkbox" name="benefit_<?= $key ?>" value="1" <?= ($isPost?isset($_POST['benefit_'.$key]):!empty($record['benefit_'.$key]))?'checked':'' ?>><?= $label ?></label><?php endforeach; ?></div>
            <div class="emp-grid"><?php foreach (['pf_number'=>'PF number','esi_number'=>'ESI number'] as $key=>$label): ?><label><?= $label ?><input name="<?= $key ?>" maxlength="100" value="<?= h($value($key)) ?>"></label><?php endforeach; ?></div>
            <div id="other-benefits-field"><div class="emp-grid"><label>Other benefits amount (₹)<input id="other_benefit_amount" name="other_benefit_amount" type="number" min="0" max="9999999999.99" step="0.01" value="<?= h($value('other_benefit_amount')) ?>" placeholder="e.g. 500"></label><label>Reason for benefits<input id="other_benefits" name="other_benefits" maxlength="255" value="<?= h($value('other_benefits')) ?>" placeholder="e.g. Travel allowance"></label></div></div>
            <p class="help">PF and ESI are calculated on the full monthly salary. PF: employee 12%, company 12%. ESI: employee 0.75%, company 3.25%. Only the employee share is deducted.</p>
        </div>
        <div class="emp-salary">
            <p class="emp-label">Monthly salary (₹)</p>
            <p class="help"><?= $record?'To change salary, add the new amount and its start date. Leave empty to keep the saved history.':'Enter the monthly salary. If the start date is empty, the joining date is used.' ?></p>
            <div id="salary-rows" data-initial-salary="<?= $record?'0':'1' ?>"><?php $draft=$_POST['salary']??[['amount'=>'','from'=>'','to'=>'']]; if (!is_array($draft)) $draft=[]; foreach ($draft as $index=>$entry): if (!is_array($entry)) continue; ?><div class="emp-grid emp-grid-3 repeat-row"><label>Amount (₹)<input type="number" name="salary[<?= (int)$index ?>][amount]" min="0.01" max="9999999999.99" step="0.01" placeholder="₹ 0" value="<?= h($entry['amount']??'') ?>"></label><label>Starts on<input type="date" name="salary[<?= (int)$index ?>][from]" value="<?= h($entry['from']??'') ?>"></label><label>Ends on (optional)<input type="date" name="salary[<?= (int)$index ?>][to]" value="<?= h($entry['to']??'') ?>"></label><button type="button" class="emp-link-btn" data-remove-row>Remove</button></div><?php endforeach; ?></div>
            <button type="button" class="emp-link-btn" data-add-row="salary">＋ Add another salary / increment</button><div id="salary-preview" aria-live="polite"></div>
        </div>
    </section>

    <section class="emp-card" id="emp-login"><h3>3 · Login access</h3><p class="emp-card-sub">How this person signs in to Team Workspace</p>
        <div class="emp-grid">
            <label>Username *<input id="username" name="username" value="<?= h($value('username')) ?>" maxlength="100" pattern="[A-Za-z0-9._\- ]{3,100}" autocomplete="off" placeholder="ravi.kumar" required></label>
            <label><?= $record ? 'New password (optional)' : 'Temporary password *' ?><input type="password" id="temporary_password" name="temporary_password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="<?= $record?'Leave empty to keep current':'Min. 8 characters' ?>" <?= !$record?'required':'' ?>></label>
            <label>Account status<select id="account_status" name="account_status"><?= options([['id'=>'active','name'=>'Active'],['id'=>'inactive','name'=>'Inactive (cannot log in)']],'name',$value('account_status','active')) ?></select></label>
        </div>
        <fieldset class="emp-choice emp-choice-cards"><legend>Access role *</legend>
            <label><input type="radio" name="access_role" value="department_head" <?= $accessRole==='department_head'?'checked':'' ?>><span><strong>Department head</strong><small>Head of the selected department</small></span></label>
            <label><input type="radio" name="access_role" value="employee" <?= $accessRole!=='department_head'?'checked':'' ?>><span><strong>Employee</strong><small>Own tasks &amp; reports</small></span></label>
        </fieldset>
    </section>

    <section class="emp-card" id="emp-docs"><h3>4 · Documents</h3><p class="emp-card-sub">ID proof, resume, offer letter (PDF or image, max 100 MB each, up to 20 files)</p>
        <label class="emp-drop" data-emp-drop><input id="documents" name="documents[]" type="file" accept="image/*,application/pdf,.pdf" multiple data-emp-docs><span class="emp-drop-icon"><?= icon('download') ?></span><strong>Click to upload or drag files here</strong><small>Aadhaar / PAN · Resume · Offer letter</small><span class="emp-drop-files" data-emp-doc-list></span></label>
    </section>
</div>

<aside class="emp-side">
    <section class="emp-card emp-photo-card">
        <label class="emp-photo"><input id="employee_photo" name="employee_photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-emp-photo><?php if (!empty($record['avatar_path'])): ?><img src="employee-photo.php?id=<?= (int)$record['id'] ?>" alt="Employee photo" data-emp-photo-img><?php else: ?><img alt="" hidden data-emp-photo-img><span class="emp-photo-icon" data-emp-photo-icon>📷</span><?php endif; ?></label>
        <strong>Profile photo</strong><small>Square JPG or PNG, at least 400 px</small>
        <button type="button" class="emp-btn emp-btn-outline emp-btn-sm" data-emp-photo-btn>Upload photo</button>
    </section>
    <section class="emp-idcard" aria-label="ID card preview">
        <small class="emp-idcard-label">ID card preview</small>
        <div class="emp-idcard-person"><span class="emp-idcard-avatar" data-card-out="initials"><?= h($cardInitials ?: '—') ?></span><div><strong data-card-out="name"><?= h($nameValue ?: 'Employee name') ?></strong><small><span data-card-out="designation"><?= h($value('designation') ?: 'Designation') ?></span> · <span data-card-out="dept"><?= h($deptName ?: 'Department') ?></span></small></div></div>
        <div class="emp-idcard-meta"><div><small>Employee ID</small><strong data-card-out="code"><?= h($value('employee_code',$suggestedCode) ?: '—') ?></strong></div><div><small>Joined</small><strong data-card-out="joined"><?= $joinedValue ? h(date('d M Y',strtotime($joinedValue))) : '—' ?></strong></div></div>
    </section>
    <section class="emp-card emp-actions">
        <button class="emp-btn emp-btn-gold" type="submit"><?= $record?'Save changes':'Save employee' ?></button>
        <?php if (!$record): ?><button class="emp-btn emp-btn-dark" type="submit" name="after" value="another">Save &amp; add another</button><?php endif; ?>
        <a class="emp-btn emp-btn-outline" href="admin-employees.php">Cancel</a>
    </section>
</aside>
</div>
</form>
<script>
(function () {
    var form = document.querySelector('[data-emp-form]'); if (!form) return;
    var placeholders = {name: 'Employee name', designation: 'Designation', dept: 'Department', code: '—', joined: '—'};
    function fmtDate(v) { if (!v) return '—'; var d = new Date(v + 'T00:00:00'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}); }
    function update() {
        form.querySelectorAll('[data-card-src]').forEach(function (el) {
            var key = el.dataset.cardSrc, out = form.querySelector('[data-card-out="' + key + '"]'); if (!out) return;
            var v = el.tagName === 'SELECT' ? (el.value ? el.options[el.selectedIndex].text : '') : el.value.trim();
            out.textContent = key === 'joined' ? fmtDate(v) : (v || placeholders[key]);
            if (key === 'name') {
                var ini = v.split(/\s+/).filter(Boolean).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
                form.querySelector('[data-card-out="initials"]').textContent = ini || '—';
            }
        });
    }
    form.addEventListener('input', update); form.addEventListener('change', update);
    var photo = form.querySelector('[data-emp-photo]'), img = form.querySelector('[data-emp-photo-img]'), icon = form.querySelector('[data-emp-photo-icon]');
    form.querySelector('[data-emp-photo-btn]').addEventListener('click', function () { photo.click(); });
    photo.addEventListener('change', function () {
        var file = photo.files[0]; if (!file) return;
        img.src = URL.createObjectURL(file); img.hidden = false; if (icon) icon.hidden = true;
    });
    var docs = form.querySelector('[data-emp-docs]'), docList = form.querySelector('[data-emp-doc-list]'), drop = form.querySelector('[data-emp-drop]');
    docs.addEventListener('change', function () { docList.textContent = docs.files.length ? Array.prototype.map.call(docs.files, function (f) { return f.name; }).join(' · ') : ''; });
    ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.add('is-drag'); }); });
    ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-drag'); }); });
    var steps = form.querySelectorAll('[data-emp-step]');
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) steps.forEach(function (s) { s.classList.toggle('active', s.getAttribute('href') === '#' + e.target.id); }); });
        }, {rootMargin: '-30% 0px -60% 0px'});
        form.querySelectorAll('.emp-main > section').forEach(function (s) { io.observe(s); });
    }
})();
</script>
<?php if ($record): ?><section class="panel"><details class="simple-details"><summary>View saved salary history</summary><?php salary_table(rows('SELECT * FROM employee_salary_history WHERE employee_id=? ORDER BY effective_from',[$record['id']])); ?></details></section>
<?php $documents=rows('SELECT id,original_name,created_at FROM employee_documents WHERE employee_id=? ORDER BY id DESC',[$record['id']]); ?><section class="panel" id="saved-documents"><h2>Saved documents (<?= count($documents) ?>)</h2><?php if (!$documents): ?><p class="muted">No documents uploaded.</p><?php endif; foreach ($documents as $document): ?><p><a href="download-employee-document.php?id=<?= $document['id'] ?>"><?= h($document['original_name']) ?></a> <small><?= h($document['created_at']) ?></small></p><?php endforeach; ?></section><?php endif; ?>
<script src="assets/js/records.js?v=<?= filemtime(__DIR__.'/../assets/js/records.js') ?>" defer></script><?php page_end(); ?>
