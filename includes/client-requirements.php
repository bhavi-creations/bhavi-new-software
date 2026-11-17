<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';
require_once __DIR__ . '/requirement-files.php';
$user=require_roles(['admin','manager','employee','client']);

/** What a client can ask for, and which team handles it. */
function client_request_types(): array
{
    return [
        'reel'=>['label'=>'Reel','desc'=>'Short video for Instagram / Facebook','icon'=>'video','team'=>'design',
            'hints'=>['Text on the video','Offer / price','Music / language','Where it will be posted'],
            'example'=>'For example: Children’s Day reel for 14 Nov. Show our kids’ dental care, add the offer ‘20% off on first check-up’, use doctor photo from last shoot.'],
        'poster'=>['label'=>'Poster','desc'=>'Single post, offer, festival wish','icon'=>'image','team'=>'design',
            'hints'=>['Text on the design','Offer / price','Colours / theme','Where it will be posted'],
            'example'=>'For example: Diwali wishes poster with our logo and the line ‘Flat 15% off till 20 Nov’. Use gold and maroon colours.'],
        'carousel'=>['label'=>'Carousel','desc'=>'Multi-slide educational post','icon'=>'carousel','team'=>'design',
            'hints'=>['Topic','Number of slides','Text for each slide','Where it will be posted'],
            'example'=>'For example: 5-slide carousel on “5 tips to keep your teeth healthy in winter”. End slide with our phone number.'],
        'website'=>['label'=>'Website / Google profile','desc'=>'Updates, new page, GMB post','icon'=>'code','team'=>'website',
            'hints'=>['Page / section to change','New text','Photos to add','Google profile post'],
            'example'=>'For example: Update timings on the Contact page to 9 AM – 8 PM and add the new branch address on Google profile.'],
        'ads'=>['label'=>'Ads','desc'=>'Meta or Google ad campaign','icon'=>'megaphone','team'=>'social_media',
            'hints'=>['Budget','How many days','Area / audience','Offer / goal'],
            'example'=>'For example: Run a Meta ad for the free check-up camp. ₹5,000 budget for 7 days, people within 5 km of our clinic.'],
        'printing'=>['label'=>'Printing','desc'=>'Visiting cards, brochures, flyers','icon'=>'printer','team'=>'design',
            'hints'=>['Size','Quantity','Paper / finish','Text on the design'],
            'example'=>'For example: 500 visiting cards for Dr. Rao, matte finish, both sides. Same design as last time with the new phone number.'],
        'change'=>['label'=>'Change to existing work','desc'=>'Edit something we delivered','icon'=>'pen','team'=>'design',
            'hints'=>['Which work','What to change','New text'],
            'example'=>'For example: In the Diwali poster delivered on 8 Nov, change the offer to 20% and make the phone number bigger.'],
        'other'=>['label'=>'Something else','desc'=>'Tell us in the details','icon'=>'chat','team'=>'',
            'hints'=>['What you need','Where it will be used'],
            'example'=>'Tell us what you need, where it will be used and anything we should know.'],
        // Sent from the Payments page ("Share payment proof"); not shown as a card here. Goes to admins/managers.
        'payment'=>['label'=>'Payment proof','desc'=>'Payment screenshot or transaction ID','icon'=>'document','team'=>'','hidden'=>true,
            'hints'=>[],'example'=>''],
    ];
}
/** Active department for a team key; "design" also matches custom designer/video departments. */
function client_request_department(string $team): ?array
{
    if ($team==='') return null;
    $code=$team==='design'?'design_video':$team;
    $department=one('SELECT id,name FROM departments WHERE code=? AND is_active=1',[$code]);
    if ($department || $team!=='design') return $department;
    foreach (rows('SELECT id,name,code FROM departments WHERE is_active=1 ORDER BY id') as $row) if (creative_work_department((string)$row['code'],(string)$row['name'])) return ['id'=>$row['id'],'name'=>$row['name']];
    return null;
}
$isClient=$user['role']==='client';
$clientRecord=$isClient?one('SELECT * FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1',[$user['id']]):null;
if ($isClient && !$clientRecord) fail(403,'Your client account is unavailable.');
$clientFilter=is_staff()?(int)($_GET['client']??0):0;
$error=null;

/** Amount and date the client wrote in a payment proof ("Amount: ₹50000", "Paid on: 2026-11-11"). */
function payment_proof_values(string $brief): array
{
    $amount=preg_match('/Amount:\s*₹?\s*([\d,]+(?:\.\d{1,2})?)/u',$brief,$m)?str_replace(',','',$m[1]):'';
    $date=preg_match('/Paid on:\s*(\d{4}-\d{2}-\d{2})/',$brief,$d)?$d[1]:'';
    return ['amount'=>$amount,'date'=>$date];
}
function is_payment_proof(array $requirement): bool
{
    return (bool)preg_match('/^(URGENT · )?Payment proof · /u',(string)$requirement['title']);
}

// Admin / manager: confirm a client's payment proof (records the payment) or mark it as not received.
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'',['confirm_payment','reject_payment'],true)) {
    require_once __DIR__.'/finance.php';
    require_roles(['admin','manager']); check_csrf();
    try {
        $proofId=positive_id($_POST['requirement_id']??null);
        db()->beginTransaction();
        $proof=one("SELECT r.*,c.client_name,c.user_id AS client_user_id,c.payment_total FROM client_requirements r JOIN clients c ON c.id=r.client_id WHERE r.id=? AND c.deleted_at IS NULL FOR UPDATE",[$proofId]);
        if (!$proof || !is_payment_proof($proof)) throw new InvalidArgumentException('Payment proof not found.');
        if (!in_array($proof['status'],['assigned','in_progress'],true)) throw new InvalidArgumentException('This payment proof was already handled.');
        if ($_POST['action']==='confirm_payment') {
            $amountText=trim((string)($_POST['amount']??''));
            $amountCents=money_cents($amountText);
            if ($amountCents<=0) throw new InvalidArgumentException('Enter the amount received.');
            $paidOn=date_input('payment_date');
            if ($paidOn>today()) throw new InvalidArgumentException('Payment date cannot be in the future.');
            $paidSoFar=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=?',[$proof['client_id']])->fetchColumn();
            if (money_cents($paidSoFar)+$amountCents>money_cents(number_format((float)$proof['payment_total'],2,'.',''))) throw new InvalidArgumentException('This payment is more than the balance. Increase the package amount in the client details first.');
            query('INSERT INTO client_payments (client_id,payment_date,amount,request_key,created_by) VALUES (?,?,?,?,?)',[$proof['client_id'],$paidOn,decimal_money($amountCents),'proof-'.$proofId,$user['id']]);
            $paid=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=?',[$proof['client_id']])->fetchColumn();
            query('UPDATE clients SET paid_amount=? WHERE id=?',[$paid,$proof['client_id']]);
            $note=trim((string)($_POST['note']??''));
            $update='Payment of '.rupees_label(decimal_money($amountCents)).' received on '.date('d M Y',strtotime($paidOn)).'. Thank you!'.($note!==''?' '.mb_substr($note,0,500):'');
            query("UPDATE client_requirements SET status='completed',approved_text=? WHERE id=?",[$update,$proofId]);
            $message='Payment confirmed for '.$proof['client_name'].'.';
        } else {
            $note=trim((string)($_POST['note']??''));
            $update=$note!==''?mb_substr($note,0,1000):'We could not find this payment yet. Please check the transaction ID and send the proof again.';
            query("UPDATE client_requirements SET status='cancelled',approved_text=? WHERE id=?",[$update,$proofId]);
            $message='Payment proof marked as not received. The client will see your note.';
        }
        if ($proof['client_user_id']) query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) VALUES (?,?,'requirement',?,?,?)",[$proof['client_user_id'],$user['id'],$_POST['action']==='confirm_payment'?'Payment confirmed':'Payment not confirmed',$update,$proofId]);
        db()->commit();
        flash($message);
        redirect('client-reuirement.php#request-'.$proofId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error=mutation_error($e);
    }
}

// Admin / manager: send a client request straight to an employee. It becomes an assigned task
// (Assigned work) with the client's files attached as task-1-file-1, task-1-file-2 …
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='assign_requirement') {
    require_roles(['admin','manager']); check_csrf(); $copiedFiles=[];
    try {
        $requirementId=positive_id($_POST['requirement_id']??null);
        $employeeId=positive_id($_POST['employee_id']??null);
        $requirement=one("SELECT r.*,c.client_name FROM client_requirements r JOIN clients c ON c.id=r.client_id WHERE r.id=? AND c.deleted_at IS NULL",[$requirementId]);
        if (!$requirement || is_payment_proof($requirement)) throw new InvalidArgumentException('Client request not found.');
        if (!in_array($requirement['status'],['assigned','in_progress'],true)) throw new InvalidArgumentException('This request is already '.str_replace('_',' ',$requirement['status']).'.');
        $employee=one("SELECT u.id,u.full_name,ep.department_id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL",[$employeeId]);
        if (!$employee) throw new InvalidArgumentException('Choose an active employee.');
        if (one('SELECT id FROM work_assignments WHERE requirement_id=? AND employee_id=? AND deleted_at IS NULL',[$requirementId,$employeeId])) throw new InvalidArgumentException('This request is already sent to '.$employee['full_name'].'.');
        $workDate=date_input('work_date');
        if ($workDate<today()) throw new InvalidArgumentException('The work date must be today or later.');
        $priority=in_array($_POST['priority']??'',['normal','high','urgent'],true)?$_POST['priority']:'normal';
        $note=trim((string)($_POST['note']??''));
        if (mb_strlen($note)>2000) throw new InvalidArgumentException('Keep the note for the employee under 2000 characters.');
        // "URGENT · Reel · Client name" → task "Reel · Client name", editing type "reel".
        $title=preg_replace('/^URGENT · /u','',(string)$requirement['title']);
        $kind=strtolower(trim(explode('·',$title)[0]));
        $editingType=['reel'=>'reel','poster'=>'poster','carousel'=>'carousel','change to existing work'=>'image_editing'][$kind]??null;
        $description=trim($requirement['brief']).($requirement['due_date']?"\n\nClient wants it by ".date('d M Y',strtotime($requirement['due_date'])).'.':'').($note!==''?"\n\nNote from ".$user['full_name'].":\n".$note:'');

        db()->beginTransaction();
        query("INSERT INTO work_assignments (employee_id,department_id,client_id,title,description,editing_type,reference_link,work_date,status,assigned_by) VALUES (?,?,?,?,?,?,NULL,?,'pending',?)",[$employeeId,$employee['department_id'],$requirement['client_id'],mb_substr($title,0,255),$description,$editingType,$workDate,$user['id']]);
        $assignmentId=(int)db()->lastInsertId();
        query('UPDATE work_assignments SET priority=?,requirement_id=? WHERE id=?',[$priority,$requirementId,$assignmentId]);
        // Copy the client's files into the task (the request keeps its own copy).
        $missingFiles=[]; $n=0;
        $files=rows('SELECT * FROM requirement_files WHERE requirement_id=? ORDER BY id',[$requirementId]);
        if ($files) {
            $relativeDir='uploads/work-assignments/'.date('Y/m');
            $absoluteDir=__DIR__.'/'.$relativeDir;
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0775,true) && !is_dir($absoluteDir)) throw new RuntimeException('Unable to create the task file folder.');
            foreach ($files as $file) {
                $source=requirement_file_path($file);
                if (!$source) { $missingFiles[]=$file['original_file_name']; continue; }
                $ext=strtolower((string)pathinfo((string)$file['original_file_name'],PATHINFO_EXTENSION));
                $ext=preg_match('/^[a-z0-9]{1,8}$/',$ext)?$ext:'bin';
                $stored=bin2hex(random_bytes(20)).'.'.$ext;
                if (!copy($source,$absoluteDir.'/'.$stored)) throw new RuntimeException('Unable to copy the client file '.$file['original_file_name'].'.');
                $copiedFiles[]=$absoluteDir.'/'.$stored;
                query('INSERT INTO work_assignment_attachments (assignment_id,original_name,stored_name,file_path,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?,?)',[$assignmentId,'task-1-file-'.(++$n).'.'.$ext,$stored,$relativeDir.'/'.$stored,$file['mime_type'],$file['file_size_bytes'],$user['id']]);
            }
        }
        query('INSERT IGNORE INTO requirement_assignments (requirement_id,employee_id,assigned_by) VALUES (?,?,?)',[$requirementId,$employeeId,$user['id']]);
        query("UPDATE client_requirements SET status='in_progress',approved_text=COALESCE(NULLIF(approved_text,''),?) WHERE id=?",['Our team has started working on this.',$requirementId]);
        db()->commit();
        try {
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",[$employeeId,$user['id'],'1 task assigned',$title.' · '.date('d M',strtotime($workDate)).($n?' · '.$n.' client file'.($n===1?'':'s'):'')]);
            // The Managing Director hears that the client request is assigned (also when a manager / an employee with access did it).
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) SELECT id,?,'requirement',?,?,? FROM users WHERE role='admin' AND account_status='active' AND deleted_at IS NULL AND id<>?",[$user['id'],'Client request assigned · '.$requirement['client_name'],$title.' → '.$employee['full_name'].' · by '.$user['full_name'].' · work date '.date('d M',strtotime($workDate)),$requirementId,$user['id']]);
        } catch (Throwable $notificationError) { error_log('Request assigned; notification failed: '.$notificationError->getMessage()); }
        flash('Sent to '.$employee['full_name'].'. It is in their assigned work'.($n?' with '.$n.' file'.($n===1?'':'s'):'').'.'.($missingFiles?' Not attached (missing on the server, ask the client to send again): '.implode(', ',$missingFiles).'.':''),$missingFiles?'error':'success');
        redirect('client-reuirement.php#request-'.$requirementId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($copiedFiles as $path) if (is_file($path)) @unlink($path);
        $error=mutation_error($e);
    }
}

// Admin / manager (or an employee given Client requests access): Done → the request is Completed and the client is told.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='complete_requirement') {
    require_roles(['admin','manager']); check_csrf();
    try {
        $requirementId=positive_id($_POST['requirement_id']??null);
        $requirement=one("SELECT r.*,c.client_name,c.user_id AS client_user_id FROM client_requirements r JOIN clients c ON c.id=r.client_id WHERE r.id=? AND c.deleted_at IS NULL",[$requirementId]);
        if (!$requirement || is_payment_proof($requirement)) throw new InvalidArgumentException('Client request not found.');
        if (!in_array($requirement['status'],['assigned','in_progress'],true)) throw new InvalidArgumentException('This request is already '.str_replace('_',' ',$requirement['status']).'.');
        $note=trim((string)($_POST['note']??''));
        if (mb_strlen($note)>2000) throw new InvalidArgumentException('Keep the note to the client under 2000 characters.');
        $title=preg_replace('/^URGENT · /u','',(string)$requirement['title']);
        db()->beginTransaction();
        query("UPDATE client_requirements SET status='completed',approved_text=? WHERE id=?",[$note!==''?$note:'Your work is completed. Thank you!',$requirementId]);
        if ($requirement['client_user_id']) query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) VALUES (?,?,'requirement',?,?,?)",[$requirement['client_user_id'],$user['id'],'Your request is completed ✓',$title.($note!==''?"\n".$note:''),$requirementId]);
        // Other admins see who closed it.
        query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) SELECT id,?,'requirement',?,?,? FROM users WHERE role='admin' AND account_status='active' AND deleted_at IS NULL AND id<>?",[$user['id'],'Client request completed · '.$requirement['client_name'],$title.' · marked done by '.$user['full_name'],$requirementId,$user['id']]);
        db()->commit();
        flash('Marked as done. '.$requirement['client_name'].' now sees this request as Completed.');
        redirect('client-reuirement.php#request-'.$requirementId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error=mutation_error($e);
    }
}

// Admin / manager: show a client request to every employee (they see it under Client requests and can download its files), or stop.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='share_requirement') {
    require_roles(['admin','manager']); check_csrf();
    try {
        $requirementId=positive_id($_POST['requirement_id']??null);
        $requirement=one("SELECT r.*,c.client_name FROM client_requirements r JOIN clients c ON c.id=r.client_id WHERE r.id=? AND c.deleted_at IS NULL",[$requirementId]);
        if (!$requirement || is_payment_proof($requirement)) throw new InvalidArgumentException('Client request not found.');
        $share=($_POST['share']??'1')==='1';
        query('UPDATE client_requirements SET shared_with_all=? WHERE id=?',[$share?1:0,$requirementId]);
        if ($share) {
            if ($requirement['status']==='assigned') query("UPDATE client_requirements SET status='in_progress',approved_text=COALESCE(NULLIF(approved_text,''),?) WHERE id=?",['Our team has started working on this.',$requirementId]);
            try {
                query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) SELECT u.id,?,'requirement',?,?,? FROM users u WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL",[$user['id'],'Client request · '.$requirement['client_name'],mb_substr(preg_replace('/^URGENT · /u','',(string)$requirement['title']).' · '.trim((string)$requirement['brief']),0,500),$requirementId]);
            } catch (Throwable $notificationError) { error_log('Request shared; notification failed: '.$notificationError->getMessage()); }
            flash('This request is now visible to all employees. They can download its files.');
        } else {
            flash('This request is no longer shown to all employees.');
        }
        redirect('client-reuirement.php#request-'.$requirementId);
    } catch (Throwable $e) {
        $error=mutation_error($e);
    }
}

// Admin / manager: a client file is missing on the server — upload it again (e.g. from WhatsApp), or ask the client to resend it.
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'',['replace_requirement_file','ask_resend_file'],true)) {
    require_roles(['admin','manager']); check_csrf(); $savedPath=null;
    try {
        $file=one('SELECT f.*,r.client_id,r.title,c.client_name,c.user_id AS client_user_id FROM requirement_files f JOIN client_requirements r ON r.id=f.requirement_id JOIN clients c ON c.id=r.client_id WHERE f.id=? AND c.deleted_at IS NULL',[positive_id($_POST['file_id']??null)]);
        if (!$file) throw new InvalidArgumentException('Request file not found.');
        $back='client-reuirement.php#request-'.(int)$file['requirement_id'];
        if ($_POST['action']==='ask_resend_file') {
            if (!$file['client_user_id']) throw new InvalidArgumentException($file['client_name'].' has no portal login. Ask them on phone / WhatsApp to send the file again.');
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) VALUES (?,?,'requirement',?,?,?)",[$file['client_user_id'],$user['id'],'Please send a file again',mb_substr('We could not open “'.$file['original_file_name'].'” from your request “'.preg_replace('/^URGENT · /u','',(string)$file['title']).'”. Please send it again with a new request.',0,500),$file['requirement_id']]);
            flash('The client was asked to send “'.$file['original_file_name'].'” again.');
            redirect($back);
        }
        $up=$_FILES['replacement']??null;
        if (!$up || ($up['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Choose the file to upload.');
        if ($up['error']!==UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) throw new InvalidArgumentException('The upload failed. Check the server upload limit and try again.');
        $size=(int)filesize($up['tmp_name']);
        $mime=substr((new finfo(FILEINFO_MIME_TYPE))->file($up['tmp_name'])?:'application/octet-stream',0,100);
        $name=preg_replace('/[\x00-\x1F\x7F]/u','',basename(str_replace('\\','/',(string)$up['name'])))?:'client-file';
        if (strlen($name)>255) $name=substr($name,0,255);
        $type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':(str_starts_with($mime,'text/')?'text':'other'));
        [$relative,$absolute]=requirement_upload_target($mime,$name);
        if (!move_uploaded_file($up['tmp_name'],$absolute)) throw new InvalidArgumentException('The file could not be saved. Check disk space and folder permissions.');
        $savedPath=$absolute;
        clearstatcache(true,$absolute);
        if (!is_file($absolute) || filesize($absolute)!==$size) throw new InvalidArgumentException('“'.$name.'” did not upload completely. Please try again.');
        query('UPDATE requirement_files SET file_type=?,description=?,original_file_name=?,file_path=?,mime_type=?,file_size_bytes=? WHERE id=?',[$type,mb_substr('Uploaded again by '.$user['full_name'].' on '.preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??'')),0,255),$name,$relative,$mime,$size,$file['id']]);
        $savedPath=null;
        requirement_file_backup((int)$file['id'],$absolute); // database copy, so it cannot go missing again
        flash('“'.$name.'” is uploaded. It can be downloaded now.');
        redirect($back);
    } catch (Throwable $e) {
        if ($savedPath && is_file($savedPath)) @unlink($savedPath);
        flash(mutation_error($e),'error');
        redirect('client-reuirement.php');
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && !in_array($_POST['action']??'',['confirm_payment','reject_payment','assign_requirement','share_requirement','complete_requirement','replace_requirement_file','ask_resend_file'],true)) {
    require_roles(['client']); check_csrf(); $storedFiles=[];
    try {
        if (($_POST['action']??'')!=='submit_requirement') throw new InvalidArgumentException('Invalid request.');
        $requestTypes=client_request_types();
        // One request can carry several tasks: the form posts tasks[n][type|due_date|urgent|remark] with files in task_files_n[].
        // Older forms post a single set of fields (request_type / department_id, remark, due_date, files[]).
        $rawTasks=[];
        if (isset($_POST['tasks']) && is_array($_POST['tasks'])) {
            foreach ($_POST['tasks'] as $key=>$task) if (is_array($task) && preg_match('/^\d{1,3}$/',(string)$key)) $rawTasks[]=['in'=>$task,'files'=>$_FILES['task_files_'.$key]??null,'typeKey'=>'type'];
            if (!$rawTasks) throw new InvalidArgumentException('Add at least one task.');
        } else $rawTasks[]=['in'=>$_POST,'files'=>$_FILES['files']??null,'typeKey'=>'request_type'];
        if (count($rawTasks)>10) throw new InvalidArgumentException('You can send up to 10 tasks at once.');
        $maxBytes=5*1024*1024*1024; $tasks=[]; $many=count($rawTasks)>1; $anyFiles=false;
        foreach ($rawTasks as $n=>$raw) {
            $in=$raw['in'];
            try {
                $requestType=is_string($in[$raw['typeKey']]??null)?$in[$raw['typeKey']]:'';
                if ($requestType!=='' || $raw['typeKey']==='type') {
                    // The client picks what they need; the right team is chosen for them.
                    if (!isset($requestTypes[$requestType])) throw new InvalidArgumentException('Choose what you need.');
                    $department=client_request_department($requestTypes[$requestType]['team']);
                    $departmentId=$department?(int)$department['id']:null;
                } else {
                    $departmentId=positive_id($in['department_id']??null);
                    $department=one('SELECT id,name FROM departments WHERE id=? AND is_active=1',[$departmentId]);
                    if (!$department) throw new InvalidArgumentException('Choose an active department.');
                }
                if (!is_string($in['remark']??null)) throw new InvalidArgumentException('Please enter remark.');
                $remark=trim($in['remark']);
                if ($remark==='' || mb_strlen($remark)>5000) throw new InvalidArgumentException('Please enter a valid remark (up to 5000 characters).');
                $dueDate=date_input('due_date',$in);
                if ($dueDate<today()) throw new InvalidArgumentException('The requested completion date must be today or later.');
                $urgent=!empty($in['urgent']);
                $upload=$raw['files']; $files=[];
                if ($upload && isset($upload['name']) && is_array($upload['name']) && count($upload['name'])>20) throw new InvalidArgumentException('Upload up to 20 files. Each file can be up to 5 GB.');
                foreach (($upload && isset($upload['name']) && is_array($upload['name']))?$upload['name']:[] as $index=>$original) {
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
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(($many?'Task '.($n+1).': ':'').$e->getMessage());
            }
            if ($urgent) $remark="⚠ Urgent — please call me.\n\n".$remark;
            $anyFiles=$anyFiles||$files!==[];
            $tasks[]=['type'=>$requestType,'label'=>$requestType!==''?$requestTypes[$requestType]['label']:'Client request','department'=>$department,'departmentId'=>$departmentId,'remark'=>$remark,'due'=>$dueDate,'urgent'=>$urgent,'files'=>$files];
        }
        // Client files are saved by type: images in uploads/photos, PDFs in uploads/pdf, everything else in uploads/files.
        db()->beginTransaction();
        // Each task is saved as its own request so it reaches the right team and gets its own status.
        foreach ($tasks as $task) {
            $title=mb_substr(($task['urgent']?'URGENT · ':'').$task['label'].' · '.$clientRecord['client_name'],0,200);
            query("INSERT INTO client_requirements (client_id,department_id,title,brief,due_date,status,created_by) VALUES (?,?,?, ?,?,'assigned',?)",[$clientRecord['id'],$task['departmentId'],$title,$task['remark'],$task['due'],$user['id']]);
            $requirementId=(int)db()->lastInsertId();
            foreach ($task['files'] as $file) {
                [$relative,$absolute]=requirement_upload_target($file['mime'],$file['name']); // e.g. uploads/photos/client-1a2b….png
                if (!move_uploaded_file($file['tmp'],$absolute)) throw new InvalidArgumentException('A file could not be saved. Check available disk space and storage permissions.');
                clearstatcache(true,$absolute);
                if (!is_file($absolute) || filesize($absolute)!==(int)$file['size']) throw new InvalidArgumentException('“'.$file['name'].'” did not upload completely. Please try again.');
                $storedFiles[]=$absolute;
                query('INSERT INTO requirement_files (requirement_id,file_type,description,original_file_name,file_path,mime_type,file_size_bytes,uploaded_by) VALUES (?,?,?,?,?,?,?,?)',[$requirementId,$file['type'],mb_substr('Uploaded on '.preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??'')).' · '.str_replace('\\','/',dirname(__DIR__)),0,255),$file['name'],$relative,$file['mime'],$file['size'],$user['id']]);
                requirement_file_backup((int)db()->lastInsertId(),$absolute); // database copy, so the file still opens if the folder loses it
            }
            $noticeTitle=($task['urgent']?'URGENT · ':'').'New client request · '.$clientRecord['client_name'];
            $noticeMessage=$task['label'].' · '.($task['department']['name']??'Management').' · Due '.$task['due'].' · '.$task['remark'];
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,requirement_id) SELECT u.id,?,'requirement',?,?,? FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.deleted_at IS NULL AND u.account_status='active' AND (u.role IN ('admin','manager') OR (u.role='employee' AND ep.department_id=?))",[$user['id'],$noticeTitle,$noticeMessage,$requirementId,$task['departmentId']]);
        }
        db()->commit();
        $sentCount=count($tasks);
        if ($tasks[0]['type']==='payment') flash('Payment proof sent! We will check it and confirm the payment here.');
        else flash($sentCount>1?$sentCount.' requests sent! Our team will review them and update each status here.':($tasks[0]['type']!==''?'Request sent! Our team will review it and update the status here.':'Your request and files were sent to the selected department.'));
        redirect(($_POST['return']??'')==='payments'?'client-payments.php':'client-reuirement.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($storedFiles as $path) if (is_file($path)) @unlink($path);
        $error=mutation_error($e);
        // Payment proof is sent from the Payments page: show the problem there.
        if (($_POST['return']??'')==='payments') { flash($error,'error'); redirect('client-payments.php#pay-proof'); }
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
elseif (!is_staff()) { $requirementSql.=' AND (r.department_id=? OR r.shared_with_all=1 OR EXISTS (SELECT 1 FROM requirement_assignments ra WHERE ra.requirement_id=r.id AND ra.employee_id=?))'; $requirementParams[]=$user['department_id']; $requirementParams[]=$user['id']; }
elseif ($clientFilter>0) { $requirementSql.=' AND r.client_id=?'; $requirementParams[]=$clientFilter; }
$requirements=rows($requirementSql.' ORDER BY r.created_at DESC,r.id DESC',$requirementParams);
// Payment proofs are not work: the Managing Director / team list shows only client work requests.
if (!$isClient) $requirements=array_values(array_filter($requirements,static fn($r)=>!is_payment_proof($r)));
$departments=departments();
page_start($isClient?'Request work':'Client requirements','client-reuirement.php'); error_message($error);
if (is_staff()): ?><form method="get" class="filters"><div class="field"><label for="client">Client</label><select id="client" name="client"><option value="">All clients</option><?= options(clients(),'client_name',$clientFilter?:null) ?></select></div><button class="primary" type="submit">Filter</button></form><?php endif; ?>
<?php if ($isClient): ?>
<?php
$crTypes=client_request_types();
$crTypeByLabel=[]; foreach ($crTypes as $key=>$type) if (empty($type['hidden'])) $crTypeByLabel[$type['label']]=$key;
$crTypeOf=static function (array $requirement) use ($crTypeByLabel): string {
    $title=preg_replace('/^URGENT · /u','',(string)$requirement['title']);
    foreach ($crTypeByLabel as $label=>$key) if (str_starts_with($title,$label.' · ')) return $key;
    return '';
};
$crDefaultDue=date('Y-m-d',strtotime('+3 days'));
$crQuick=['Tomorrow'=>date('Y-m-d',strtotime('+1 day')),'In 3 days'=>$crDefaultDue,'In a week'=>date('Y-m-d',strtotime('+7 days'))];
// Tasks to show: what the client typed (after an error), else one empty task.
// Nothing is pre-selected unless ?type=reel ("Request another …") asks for it.
$crTasks=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['tasks']) && is_array($_POST['tasks'])) {
    foreach ($_POST['tasks'] as $task) if (is_array($task)) $crTasks[]=['type'=>(string)($task['type']??''),'due'=>(string)($task['due_date']??$crDefaultDue),'urgent'=>!empty($task['urgent']),'remark'=>(string)($task['remark']??'')];
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
    $crTasks[]=['type'=>(string)($_POST['request_type']??''),'due'=>(string)($_POST['due_date']??$crDefaultDue),'urgent'=>!empty($_POST['urgent']),'remark'=>(string)($_POST['remark']??'')];
}
if (!$crTasks) $crTasks[]=['type'=>is_string($_GET['type']??null)?$_GET['type']:'','due'=>$crDefaultDue,'urgent'=>false,'remark'=>''];
$crTasks=array_slice($crTasks,0,10);
foreach ($crTasks as &$crTask) if (!isset($crTypes[$crTask['type']]) || !empty($crTypes[$crTask['type']]['hidden'])) $crTask['type']='';
unset($crTask);
$crChosen=count(array_filter($crTasks,static fn($t)=>$t['type']!==''));
$crPlan=array_filter(['reels'=>(int)$clientRecord['monthly_reels'],'posters'=>(int)$clientRecord['monthly_posters'],'carousels'=>(int)$clientRecord['monthly_carousels']]);
$crPlanText=$crPlan?implode(', ',array_map(static fn($k,$v)=>$v.' '.($v===1?rtrim($k,'s'):$k),array_keys($crPlan),$crPlan)):'';
if ($crPlan && count($crPlan)>1) { $parts=explode(', ',$crPlanText); $last=array_pop($parts); $crPlanText=implode(', ',$parts).' and '.$last; }
$crStatus=['assigned'=>['Received','is-received'],'in_progress'=>['In progress','is-progress'],'completed'=>['Completed ✓','is-done'],'cancelled'=>['Cancelled','is-cancelled']];

/** One task block. $i is the form index ("__I__" inside the template that JS copies). */
$crTaskBlock=static function (string $i, int $number, array $task) use ($crTypes,$crQuick,$crPlanText): string {
    $type=$task['type']; ob_start(); ?>
    <div class="cr-task" data-cr-task data-index="<?= h($i) ?>">
        <div class="cr-task-head">
            <span class="cr-task-no">Task <b data-cr-task-no><?= $number ?></b></span>
            <span class="cr-task-type" data-cr-task-type><?= $type!==''?h($crTypes[$type]['label']):'' ?></span>
            <button type="button" class="cr-remove" data-cr-remove>Remove</button>
        </div>
        <fieldset class="cr-step">
            <legend>1 · What do you need?</legend>
            <?php if ($crPlanText && $number===1): ?><p class="cr-note cr-plan">Your plan this month includes <?= h($crPlanText) ?>.</p><?php endif; ?>
            <div class="cr-types">
                <?php foreach ($crTypes as $key=>$item): if (!empty($item['hidden'])) continue; ?>
                    <label class="cr-type">
                        <input type="radio" name="tasks[<?= h($i) ?>][type]" value="<?= h($key) ?>" <?= $type===$key?'checked':'' ?> required data-cr-type data-label="<?= h($item['label']) ?>" data-example="<?= h($item['example']) ?>">
                        <span class="cr-type-icon"><?= icon($item['icon']) ?></span>
                        <strong><?= h($item['label']) ?></strong>
                        <small><?= h($item['desc']) ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="cr-pick-first" data-cr-pick-first<?= $type!==''?' hidden':'' ?>>Choose one above to continue.</p>
        </fieldset>

        <div class="cr-more" data-cr-more<?= $type===''?' hidden':'' ?>>
            <div class="cr-selected">
                <span class="cr-selected-icon" data-cr-selected-icon><?= $type!==''?icon($crTypes[$type]['icon']):'' ?></span>
                <span class="cr-selected-text"><small>You are requesting</small><strong data-cr-selected-label><?= $type!==''?h($crTypes[$type]['label']):'' ?></strong></span>
                <button type="button" class="cr-change" data-cr-change>Change</button>
            </div>
            <fieldset class="cr-step">
                <legend>2 · When do you need it?</legend>
                <div class="cr-when">
                    <?php foreach ($crQuick as $label=>$value): ?><button type="button" class="cr-chip<?= $task['due']===$value?' active':'' ?>" data-date="<?= h($value) ?>"><?= h($label) ?></button><?php endforeach; ?>
                    <label class="cr-pick"><span>Pick a date</span><input type="date" name="tasks[<?= h($i) ?>][due_date]" min="<?= h(today()) ?>" value="<?= h($task['due']) ?>" required data-cr-date></label>
                </div>
                <label class="cr-urgent"><input type="checkbox" name="tasks[<?= h($i) ?>][urgent]" value="1" <?= $task['urgent']?'checked':'' ?>> This is urgent — please call me</label>
            </fieldset>
            <fieldset class="cr-step">
                <legend>3 · Tell us the details</legend>
                <label class="cr-field"><span>Work details *</span>
                    <textarea name="tasks[<?= h($i) ?>][remark]" maxlength="5000" rows="5" required data-cr-details placeholder="<?= h($crTypes[$type ?: 'reel']['example']) ?>"><?= h($task['remark']) ?></textarea>
                </label>
                <?php foreach ($crTypes as $key=>$item): ?><div class="cr-hints" data-cr-hints="<?= h($key) ?>"<?= $type!==$key?' hidden':'' ?>><span>Good to mention:</span><?php foreach ($item['hints'] as $hint): ?><button type="button" data-hint="<?= h($hint) ?>"><?= h($hint) ?></button><?php endforeach; ?></div><?php endforeach; ?>
                <div class="cr-uploads" data-cr-uploads data-name="task_files_<?= h($i) ?>[]">
                    <p class="cr-uploads-title"><strong>Photos, videos or files</strong><small>One file in each box · click “Add more” for the next one · up to 20 files, 5 GB each · optional</small></p>
                    <div class="cr-upload-list" data-cr-upload-list>
                        <div class="cr-upload-row" data-cr-upload-row>
                            <span class="cr-upload-no" data-cr-upload-label>File 1</span>
                            <label class="cr-upload-pick"><input type="file" name="task_files_<?= h($i) ?>[]" data-cr-files><span class="cr-upload-btn"><?= icon('paperclip') ?> Choose file</span><small class="cr-upload-name" data-cr-file-name>No file chosen</small></label>
                            <button type="button" class="cr-upload-remove" data-cr-upload-remove title="Remove this file" aria-label="Remove this file">×</button>
                        </div>
                    </div>
                    <button type="button" class="cr-upload-more" data-cr-upload-more><?= icon('plus') ?> Add more</button>
                </div>
            </fieldset>
        </div>
    </div>
    <?php return (string)ob_get_clean();
};
?>
<div class="cr-page">
    <header class="cr-head">
        <p class="cr-crumb">Dashboard / Request work</p>
        <h1 class="cr-title">Request new work</h1>
        <p class="cr-sub">Tell us what you need — it goes straight to the right Bhavi team.</p>
    </header>

    <div class="cr-grid">
        <section class="cr-card">
            <form method="post" enctype="multipart/form-data" class="cr-form" data-cr-form><?= csrf_field() ?><input type="hidden" name="action" value="submit_requirement">
                <div class="cr-tasks<?= count($crTasks)>1?' is-multi':'' ?>" data-cr-tasks data-next="<?= count($crTasks) ?>">
                    <?php foreach ($crTasks as $index=>$task) echo $crTaskBlock((string)$index,$index+1,$task); ?>
                </div>

                <div class="cr-footer" data-cr-footer<?= $crChosen===0?' hidden':'' ?>>
                    <div class="cr-add-row">
                        <button type="button" class="cr-add" data-cr-add><?= icon('plus') ?> Add another task</button>
                        <small>Need a reel and a poster too? Add them here and send everything together.</small>
                    </div>
                    <div class="cr-actions">
                        <button class="cr-send" type="submit" data-cr-send>Send request →</button>
                        <span>We usually reply within 1 working day.</span>
                    </div>
                </div>
                <template data-cr-template><?= $crTaskBlock('__I__',0,['type'=>'','due'=>$crDefaultDue,'urgent'=>false,'remark'=>'']) ?></template>
            </form>
        </section>

        <aside class="cr-side">
            <section class="cr-how">
                <h2>How it works</h2>
                <ol>
                    <li><b>1</b><span><strong>You send the request</strong><small>One or more tasks, with details, files and dates</small></span></li>
                    <li><b>2</b><span><strong>Our team reviews it</strong><small>We confirm or call you if anything is unclear</small></span></li>
                    <li><b>3</b><span><strong>Work in progress</strong><small>You’ll see the status change here</small></span></li>
                    <li><b>4</b><span><strong>Delivered</strong><small>Final files appear in Work reports</small></span></li>
                </ol>
            </section>

            <section class="cr-card cr-mine" id="department-requests">
                <h3>Your requests</h3>
                <?php if (!$requirements): ?>
                    <div class="cr-empty"><strong>No requests yet</strong><small>Requests you send will appear here with their status — Received, In progress or Delivered.</small></div>
                <?php endif; ?>
                <?php foreach ($requirements as $requirement): [$statusLabel,$statusClass]=$crStatus[$requirement['status']]??[ucfirst($requirement['status']),'']; $fileCount=(int)count_value('SELECT COUNT(*) FROM requirement_files WHERE requirement_id=?',[$requirement['id']]); ?>
                    <details class="cr-req">
                        <summary>
                            <span class="cr-req-text"><strong><?= h(preg_replace('/ · '.preg_quote($requirement['client_name'],'/').'$/u','',$requirement['title'])) ?></strong><small>Sent <?= h(date('j M',strtotime($requirement['created_at']))) ?> · due <?= $requirement['due_date']?h(date('j M',strtotime($requirement['due_date']))):'—' ?><?= $fileCount?' · '.$fileCount.' '.($fileCount===1?'file':'files'):'' ?></small></span>
                            <em class="cr-status <?= $statusClass ?>"><?= h($statusLabel) ?></em>
                        </summary>
                        <p><?= nl2br(h($requirement['brief'])) ?></p>
                        <?php if ($requirement['approved_text']): ?><p class="cr-update"><strong>Team update:</strong> <?= nl2br(h($requirement['approved_text'])) ?></p><?php endif; ?>
                        <?php $againType=$crTypeOf($requirement); if ($againType!==''): ?><div class="cr-again"><a href="client-reuirement.php?type=<?= h($againType) ?>" data-cr-again="<?= h($againType) ?>">+ Request another <?= h(strtolower($crTypes[$againType]['label'])) ?></a></div><?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </section>
        </aside>
    </div>
</div>
<script>
(() => {
    const form = document.querySelector('[data-cr-form]');
    if (!form) return;
    const list = form.querySelector('[data-cr-tasks]'), footer = form.querySelector('[data-cr-footer]'), send = form.querySelector('[data-cr-send]');
    const template = form.querySelector('[data-cr-template]'), addBtn = form.querySelector('[data-cr-add]');
    const tasks = () => Array.from(list.querySelectorAll('[data-cr-task]'));
    const chosen = task => task.querySelector('[data-cr-type]:checked');

    // Numbers, the send button text and the footer follow the tasks on the page.
    const refresh = () => {
        const all = tasks(), picked = all.map(chosen).filter(Boolean);
        all.forEach((t, n) => { t.querySelector('[data-cr-task-no]').textContent = n + 1; });
        list.classList.toggle('is-multi', all.length > 1);
        footer.hidden = picked.length === 0;
        addBtn.hidden = all.length >= 10;
        send.textContent = all.length > 1 ? 'Send ' + all.length + ' requests →' : 'Send ' + (picked[0] ? picked[0].dataset.label + ' ' : '') + 'request →';
    };

    // Step 1 first: the rest of a task opens once its work type is chosen.
    const choose = (task, radio, scroll) => {
        const more = task.querySelector('[data-cr-more]');
        more.hidden = false; task.querySelector('[data-cr-pick-first]').hidden = true;
        task.querySelector('[data-cr-selected-label]').textContent = radio.dataset.label;
        task.querySelector('[data-cr-task-type]').textContent = radio.dataset.label;
        task.querySelector('[data-cr-selected-icon]').innerHTML = radio.closest('.cr-type').querySelector('.cr-type-icon').innerHTML;
        task.querySelector('[data-cr-details]').placeholder = radio.dataset.example;
        task.querySelectorAll('[data-cr-hints]').forEach(h => { h.hidden = h.dataset.crHints !== radio.value; });
        refresh();
        if (scroll) more.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const setup = task => {
        task.querySelectorAll('[data-cr-type]').forEach(r => r.addEventListener('change', () => choose(task, r, true)));
        task.querySelector('[data-cr-change]').addEventListener('click', () => task.querySelector('.cr-types').scrollIntoView({ behavior: 'smooth', block: 'center' }));
        task.querySelector('[data-cr-remove]').addEventListener('click', () => { if (tasks().length > 1) { task.remove(); refresh(); } });
        // Date chips
        const date = task.querySelector('[data-cr-date]'), chips = task.querySelectorAll('.cr-chip');
        const sync = () => chips.forEach(c => c.classList.toggle('active', c.dataset.date === date.value));
        chips.forEach(c => c.addEventListener('click', () => { date.value = c.dataset.date; sync(); }));
        date.addEventListener('change', sync);
        // "Good to mention" chips add a starter line to the details box.
        const details = task.querySelector('[data-cr-details]');
        task.querySelectorAll('[data-hint]').forEach(b => b.addEventListener('click', () => {
            details.value = (details.value.trim() ? details.value.replace(/\s*$/, '') + '\n' : '') + b.dataset.hint + ': ';
            details.focus(); details.setSelectionRange(details.value.length, details.value.length);
        }));
        // Files: one file per box (Reel 1, Reel 2 …) with "Add more" for the next box.
        const uploads = task.querySelector('[data-cr-uploads]'), upList = uploads.querySelector('[data-cr-upload-list]'), more = uploads.querySelector('[data-cr-upload-more]');
        const rows = () => Array.from(upList.querySelectorAll('[data-cr-upload-row]'));
        const word = () => { const t = (task.querySelector('[data-cr-type]:checked') || {}).value; return t === 'reel' ? 'Reel' : (t === 'poster' || t === 'carousel' ? 'Photo' : 'File'); };
        const relabel = () => {
            const all = rows();
            all.forEach((row, i) => { row.querySelector('[data-cr-upload-label]').textContent = word() + ' ' + (i + 1); row.querySelector('[data-cr-upload-remove]').hidden = all.length === 1 && !row.querySelector('[data-cr-files]').files.length; });
            more.hidden = all.length >= 20;
        };
        const showName = row => { const f = row.querySelector('[data-cr-files]').files[0]; const name = row.querySelector('[data-cr-file-name]'); name.textContent = f ? f.name : 'No file chosen'; row.classList.toggle('has-file', !!f); };
        upList.addEventListener('change', e => { const row = e.target.closest('[data-cr-upload-row]'); if (row) { showName(row); relabel(); } });
        upList.addEventListener('click', e => {
            const btn = e.target.closest('[data-cr-upload-remove]'); if (!btn) return;
            const row = btn.closest('[data-cr-upload-row]');
            if (rows().length === 1) { row.querySelector('[data-cr-files]').value = ''; showName(row); } else row.remove();
            relabel();
        });
        more.addEventListener('click', () => {
            if (rows().length >= 20) return;
            const row = rows()[0].cloneNode(true);
            row.querySelector('[data-cr-files]').value = ''; showName(row);
            upList.appendChild(row); relabel();
            row.querySelector('[data-cr-files]').click();
        });
        task.querySelectorAll('[data-cr-type]').forEach(r => r.addEventListener('change', relabel));
        relabel();
    };

    const addTask = type => {
        const index = list.dataset.next++;
        list.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__I__/g, index));
        const task = tasks().pop();
        task.querySelectorAll('.cr-plan').forEach(p => p.remove());
        setup(task); refresh();
        const radio = type && task.querySelector('[data-cr-type][value="' + type + '"]');
        if (radio) { radio.checked = true; choose(task, radio, false); }
        task.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return task;
    };

    tasks().forEach(setup);
    refresh();
    addBtn.addEventListener('click', () => addTask(''));

    // "Request another …" fills an empty task, or adds a new one with the same type.
    document.querySelectorAll('[data-cr-again]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        const empty = tasks().find(t => !chosen(t));
        if (!empty) { if (tasks().length < 10) addTask(a.dataset.crAgain); return; }
        const radio = empty.querySelector('[data-cr-type][value="' + a.dataset.crAgain + '"]');
        radio.checked = true; choose(empty, radio, false);
        empty.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));

    form.addEventListener('submit', () => {
        const uploading = Array.from(form.querySelectorAll('[data-cr-files]')).some(i => i.files.length);
        send.disabled = true; send.textContent = uploading ? 'Uploading…' : 'Sending…';
    });
})();
</script>
<?php else: ?><p class="muted"><?= is_staff()?'Client requests are visible to management and the selected department.':'Client requests for your department appear here alongside your assigned briefs.' ?></p><?php endif; ?>
<?php if (!$isClient && $assignments): ?><section class="panel"><h2>Assigned work</h2><?php foreach ($assignments as $assignment): ?><article class="requirement-card"><div class="panel-heading"><h3><?= h($assignment['title']) ?></h3><?= status_badge($assignment['status']) ?></div><p class="muted"><?= h($assignment['client_name']) ?> · <?= h($assignment['employee_name']) ?> · <?= h(date('d-m-Y',strtotime($assignment['work_date']))) ?></p><p><?= nl2br(h($assignment['description'])) ?></p></article><?php endforeach; ?></section><?php endif; ?>
<?php
// Admin / manager: "Send to employee" box on each client request (see the assign_requirement action above).
$crAssignBox=static function (array $requirement, array $files): void {};
if (is_staff()) {
    $crEmployees=array_values(array_filter(employees(),static fn($e)=>$e['account_status']==='active'));
    $crAssignBox=static function (array $requirement, array $files) use ($crEmployees): void {
        $sent=rows('SELECT a.id,a.status,a.work_date,a.started_at,u.full_name FROM work_assignments a JOIN users u ON u.id=a.employee_id WHERE a.requirement_id=? AND a.deleted_at IS NULL ORDER BY a.id',[$requirement['id']]);
        $open=in_array($requirement['status'],['assigned','in_progress'],true);
        $completed=$requirement['status']==='completed';
        if (!$sent && !$open && !$completed) return;
        $allDone=$sent && count(array_filter($sent,static fn($a)=>$a['status']==='completed'))===count($sent);
        $groups=[]; foreach ($crEmployees as $e) $groups[$e['department_name']][]=$e;
        // The request's own team first.
        uksort($groups,static fn($a,$b)=>[$a!==($requirement['department_name']??''),$a]<=>[$b!==($requirement['department_name']??''),$b]);
        $taken=array_column($sent,'full_name');
        $date=$requirement['due_date'] && $requirement['due_date']>=today()?$requirement['due_date']:today();
        $urgent=str_starts_with((string)$requirement['title'],'URGENT');
        ?><div class="cr-assign">
            <?php if ($sent): ?><ul class="cr-assign-sent"><?php foreach ($sent as $a): $state=$a['status']==='completed'?['done','✓ Done']:(!empty($a['started_at'])?['work','▶ Working']:['wait','⏳ Not started']); ?><li><span class="cr-assign-avatar"><?= h(mb_strtoupper(mb_substr($a['full_name'],0,1))) ?></span><span>Sent to <b><?= h($a['full_name']) ?></b> · <?= h(date('d M Y',strtotime($a['work_date']))) ?></span><span class="cr-assign-state is-<?= $state[0] ?>"><?= $state[1] ?></span></li><?php endforeach; ?></ul><?php endif; ?>
            <?php $shared=!empty($requirement['shared_with_all']); if ($shared): ?><div class="cr-shared"><span>👥 <b>Visible to all employees</b> · they can see this request and download its files</span><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="share_requirement"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['id'] ?>"><input type="hidden" name="share" value="0"><button type="submit" class="cr-shared-stop">Stop showing to all</button></form></div><?php endif; ?>
            <?php if ($completed): ?><p class="cr-complete-done">✓ Completed · the client sees this request as Completed</p><?php endif; ?>
            <?php if ($open && ($sent || !empty($requirement['shared_with_all']))): ?>
            <form method="post" class="cr-complete<?= $allDone?' is-ready':'' ?>" data-confirm="Mark this client request as done? The client will see it as Completed."><?= csrf_field() ?><input type="hidden" name="action" value="complete_requirement"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['id'] ?>">
                <span class="cr-complete-text"><?= $allDone?'<b>Work finished by the team.</b> Click Done to tell the client it is completed.':'When the work is finished, click Done — the client sees it as Completed.' ?></span>
                <input name="note" maxlength="2000" placeholder="Message to client (optional)" aria-label="Message to client">
                <button type="submit" class="cr-complete-btn"><?= icon('check') ?> Done</button>
            </form>
            <?php endif; ?>
            <?php if ($open): ?>
            <details class="cr-assign-box">
                <summary class="cr-assign-btn"><?= icon('send') ?> <?= $sent||$shared?'Assign again':'Assign' ?></summary>
                <p class="cr-assign-title">1. Assign to one employee</p>
                <form method="post" class="cr-assign-form"><?= csrf_field() ?><input type="hidden" name="action" value="assign_requirement"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['id'] ?>">
                    <label class="cr-assign-emp"><span>Employee</span><select name="employee_id" required><option value="">Choose employee…</option><?php foreach ($groups as $dept=>$list): ?><optgroup label="<?= h($dept) ?>"><?php foreach ($list as $e): ?><option value="<?= (int)$e['id'] ?>"<?= in_array($e['full_name'],$taken,true)?' disabled':'' ?>><?= h($e['full_name']) ?><?= $e['designation']?' · '.h($e['designation']):'' ?><?= in_array($e['full_name'],$taken,true)?' (already sent)':'' ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
                    <label><span>Work date</span><input type="date" name="work_date" min="<?= h(today()) ?>" value="<?= h($date) ?>" required></label>
                    <label><span>Priority</span><select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="urgent"<?= $urgent?' selected':'' ?>>Urgent</option></select></label>
                    <label class="cr-assign-note"><span>Note for the employee <small>(optional)</small></span><textarea name="note" rows="2" maxlength="2000" placeholder="e.g. Use the new logo. Send the first draft by 4 PM."></textarea></label>
                    <div class="cr-assign-foot"><small><?= $files?'📎 '.count($files).' client file'.(count($files)===1?'':'s').' will be attached as task-1-file-1 … task-1-file-'.count($files):'No client files to attach' ?> · The client’s message goes with the task.</small><button type="submit" class="primary"><?= icon('send') ?> Assign to employee</button></div>
                </form>
                <?php if (!$shared): ?>
                <div class="cr-assign-or"><span>or</span></div>
                <form method="post" class="cr-assign-all"><?= csrf_field() ?><input type="hidden" name="action" value="share_requirement"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['id'] ?>"><input type="hidden" name="share" value="1">
                    <p class="cr-assign-title">2. Show to all employees</p>
                    <small>Every employee sees this request with the client’s message and can download the files. They get a notification.</small>
                    <button type="submit" class="cr-assign-all-btn">👥 Show to all employees</button>
                </form>
                <?php endif; ?>
            </details>
            <?php endif; ?>
        </div><?php
    };
}
?>
<?php if (!$isClient): ?><section class="panel" id="department-requests"><h2><?= $isClient?'Your requests':(is_staff()?'Client requests':'Requests for your department') ?></h2><?php if (!$requirements): ?><p class="muted"><?= $isClient?'Your submitted requests will appear here.':'No client requests are available for this department.' ?></p><?php endif; foreach ($requirements as $requirement): $files=rows('SELECT id,requirement_id,original_file_name,file_size_bytes,file_path,mime_type,description FROM requirement_files WHERE requirement_id=? ORDER BY id',[$requirement['id']]); $isProof=is_payment_proof($requirement); ?><article class="requirement-card<?= $isProof?' is-payment-proof':'' ?>" id="request-<?= (int)$requirement['id'] ?>"><div class="panel-heading"><div><h3><?= h($requirement['client_name']) ?> <?= $isProof?'· Payment proof':'request' ?></h3><p class="muted"><?= h($requirement['department_name']??'Department not selected') ?> · Due <?= $requirement['due_date']?h(date('d-m-Y',strtotime($requirement['due_date']))):'No deadline' ?></p></div><?= status_badge($requirement['status']) ?></div><p class="requirement-remark"><?= nl2br(h($requirement['brief'])) ?></p><?php if ($requirement['approved_text']): ?><p class="requirement-remark"><strong>Team update:</strong><br><?= nl2br(h($requirement['approved_text'])) ?></p><?php endif; ?><?= requirement_files_html($files,true,is_staff()) ?><?php if (!$isProof && is_staff()) $crAssignBox($requirement,$files); ?></article><?php endforeach; ?></section><?php endif; ?>
<?php page_end(); ?>
