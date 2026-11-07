<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(['admin','manager','employee','client']);
$isClient=$user['role']==='client';
$clientRecord=$isClient?one('SELECT * FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1',[$user['id']]):null;
if ($isClient && !$clientRecord) fail(403,'Your client account is unavailable.');
$clientFilter=is_staff()?(int)($_GET['client']??0):0;
$error=null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['client']); check_csrf(); $storedFiles=[];
    try {
        if (($_POST['action']??'')!=='submit_requirement') throw new InvalidArgumentException('Invalid request.');
        $departmentId=positive_id($_POST['department_id']??null);
        $department=one('SELECT id,name FROM departments WHERE id=? AND is_active=1',[$departmentId]);
        if (!$department) throw new InvalidArgumentException('Choose an active department.');
        $remark=text_input('remark',5000);
        $dueDate=date_input('due_date');
        if ($dueDate<today()) throw new InvalidArgumentException('The requested completion date must be today or later.');
        $upload=$_FILES['files']??null;
        if (!$upload || !isset($upload['name']) || !is_array($upload['name'])) throw new InvalidArgumentException('Choose at least one file to upload.');
        $fileCount=count($upload['name']);
        if ($fileCount<1 || $fileCount>20) throw new InvalidArgumentException('Upload between 1 and 20 files. Each file can be up to 5 GB.');
        $files=[]; $maxBytes=5*1024*1024*1024;
        foreach ($upload['name'] as $index=>$original) {
            $uploadError=(int)($upload['error'][$index]??UPLOAD_ERR_NO_FILE);
            if ($uploadError===UPLOAD_ERR_NO_FILE) continue;
            if ($uploadError!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'][$index]??'')) throw new InvalidArgumentException('A file upload failed. Check the server upload limit and try again.');
            $size=filesize($upload['tmp_name'][$index]);
            if ($size===false || $size<0 || $size>$maxBytes) throw new InvalidArgumentException('Each file must be 5 GB or smaller.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name'][$index])?:'application/octet-stream';
            $name=basename(str_replace('\\','/',(string)$original));
            $name=preg_replace('/[\x00-\x1F\x7F]/u','',$name)?:'client-file';
            if (strlen($name)>255) $name=substr($name,0,255);
            $type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':(str_starts_with($mime,'text/')?'text':'other'));
            $files[]=['tmp'=>$upload['tmp_name'][$index],'name'=>$name,'size'=>$size,'mime'=>substr($mime,0,100),'type'=>$type];
        }
        if (!$files) throw new InvalidArgumentException('Choose at least one file to upload.');
        $directory=dirname(__DIR__).'/storage/requirements';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new InvalidArgumentException('Unable to create secure requirement file storage.');
        if (!is_writable($directory)) throw new InvalidArgumentException('Secure requirement file storage is not writable by PHP.');
        db()->beginTransaction();
        $title=mb_substr('Client request · '.$clientRecord['client_name'],0,200);
        query("INSERT INTO client_requirements (client_id,department_id,title,brief,due_date,status,created_by) VALUES (?,?,?, ?,?,'assigned',?)",[$clientRecord['id'],$departmentId,$title,$remark,$dueDate,$user['id']]);
        $requirementId=(int)db()->lastInsertId();
        foreach ($files as $file) {
            $relative='storage/requirements/'.bin2hex(random_bytes(20)).'.dat';
            $absolute=dirname(__DIR__).'/'.$relative;
            if (!move_uploaded_file($file['tmp'],$absolute)) throw new InvalidArgumentException('A file could not be saved. Check available disk space and storage permissions.');
            $storedFiles[]=$absolute;
            query('INSERT INTO requirement_files (requirement_id,file_type,description,original_file_name,file_path,mime_type,file_size_bytes,uploaded_by) VALUES (?,?,?,?,?,?,?,?)',[$requirementId,$file['type'],null,$file['name'],$relative,$file['mime'],$file['size'],$user['id']]);
        }
        $noticeTitle='New client request · '.$clientRecord['client_name'];
        $noticeMessage=$department['name'].' · Due '.$dueDate.' · '.$remark;
        query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) SELECT u.id,?,'requirement',?,?,? FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.deleted_at IS NULL AND u.account_status='active' AND (u.role IN ('admin','manager') OR (u.role='employee' AND ep.department_id=?))",[$user['id'],$noticeTitle,$noticeMessage,$requirementId,$departmentId]);
        db()->commit(); flash('Your request and files were sent to the selected department.'); redirect('client-reuirement.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($storedFiles as $path) if (is_file($path)) @unlink($path);
        $error=mutation_error($e);
    }
}

$assignments=[];
if (!$isClient) {
    $assignmentSql='SELECT a.*,c.client_name,u.full_name AS employee_name FROM work_assignments a JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.employee_id WHERE a.deleted_at IS NULL'; $assignmentParams=[];
    if (!is_staff()) { $assignmentSql.=' AND a.employee_id=?'; $assignmentParams[]=$user['id']; }
    if ($clientFilter>0) { $assignmentSql.=' AND a.client_id=?'; $assignmentParams[]=$clientFilter; }
    $assignments=rows($assignmentSql.' ORDER BY a.work_date DESC',$assignmentParams);
}
$requirementSql='SELECT r.*,c.client_name,d.name AS department_name FROM client_requirements r JOIN clients c ON c.id=r.client_id LEFT JOIN departments d ON d.id=r.department_id WHERE c.deleted_at IS NULL'; $requirementParams=[];
if ($isClient) { $requirementSql.=' AND r.client_id=?'; $requirementParams[]=$clientRecord['id']; }
elseif (!is_staff()) { $requirementSql.=' AND (r.department_id=? OR EXISTS (SELECT 1 FROM requirement_assignments ra WHERE ra.requirement_id=r.id AND ra.employee_id=?))'; $requirementParams[]=$user['department_id']; $requirementParams[]=$user['id']; }
elseif ($clientFilter>0) { $requirementSql.=' AND r.client_id=?'; $requirementParams[]=$clientFilter; }
$requirements=rows($requirementSql.' ORDER BY r.created_at DESC,r.id DESC',$requirementParams);
$departments=departments();
page_start($isClient?'Request work':'Client requirements','client-reuirement.php'); error_message($error);
if (is_staff()): ?><form method="get" class="filters"><div class="field"><label for="client">Client</label><select id="client" name="client"><option value="">All clients</option><?= options(clients(),'client_name',$clientFilter?:null) ?></select></div><button class="primary" type="submit">Filter</button></form><?php endif; ?>
<?php if ($isClient): ?>
<section class="panel client-request-form"><div class="panel-heading"><h2>Send a work request</h2><span class="badge">Your request is saved to the portal</span></div><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="submit_requirement"><div class="fields"><div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" required><option value="">Choose a department</option><?= options($departments,'name',$_POST['department_id']??null) ?></select></div><div class="field"><label for="due_date">Last date</label><input id="due_date" name="due_date" type="date" min="<?= today() ?>" value="<?= h($_POST['due_date']??'') ?>" required></div><div class="field full"><label for="remark">Remark / work details</label><textarea id="remark" name="remark" maxlength="5000" rows="5" required><?= h($_POST['remark']??'') ?></textarea></div><div class="field full"><label for="request-files">Upload files</label><input id="request-files" name="files[]" type="file" multiple required><p class="help">Up to 20 files, each up to 5 GB. Upload speed and available server storage affect large files.</p></div></div><button class="primary" type="submit">Send request</button></form></section>
<?php else: ?><p class="muted"><?= is_staff()?'Client requests are visible to management and the selected department.':'Client requests for your department appear here alongside your assigned briefs.' ?></p><?php endif; ?>
<?php if (!$isClient && $assignments): ?><section class="panel"><h2>Assigned work</h2><?php foreach ($assignments as $assignment): ?><article class="requirement-card"><div class="panel-heading"><h3><?= h($assignment['title']) ?></h3><?= status_badge($assignment['status']) ?></div><p class="muted"><?= h($assignment['client_name']) ?> · <?= h($assignment['employee_name']) ?> · <?= h(date('d-m-Y',strtotime($assignment['work_date']))) ?></p><p><?= nl2br(h($assignment['description'])) ?></p></article><?php endforeach; ?></section><?php endif; ?>
<section class="panel" id="department-requests"><h2><?= $isClient?'Your requests':(is_staff()?'Client requests':'Requests for your department') ?></h2><?php if (!$requirements): ?><p class="muted"><?= $isClient?'Your submitted requests will appear here.':'No client requests are available for this department.' ?></p><?php endif; foreach ($requirements as $requirement): $files=rows('SELECT id,original_file_name,file_size_bytes FROM requirement_files WHERE requirement_id=? ORDER BY id',[$requirement['id']]); ?><article class="requirement-card"><div class="panel-heading"><div><h3><?= h($requirement['client_name']) ?> request</h3><p class="muted"><?= h($requirement['department_name']??'Department not selected') ?> · Due <?= $requirement['due_date']?h(date('d-m-Y',strtotime($requirement['due_date']))):'No deadline' ?></p></div><?= status_badge($requirement['status']) ?></div><p class="requirement-remark"><?= nl2br(h($requirement['brief'])) ?></p><?php if ($requirement['approved_text']): ?><p class="requirement-remark"><strong>Team update:</strong><br><?= nl2br(h($requirement['approved_text'])) ?></p><?php endif; ?><?php if ($files): ?><h4>Attached files</h4><ul class="requirement-file-list"><?php foreach ($files as $file): ?><li><a href="download-requirement-file.php?id=<?= (int)$file['id'] ?>"><?= h($file['original_file_name']) ?></a><span><?= h(number_format((int)$file['file_size_bytes']/1024/1024,1)) ?> MB</span></li><?php endforeach; ?></ul><?php endif; ?></article><?php endforeach; ?></section>
<?php page_end(); ?>
