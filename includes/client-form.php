<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__.'/finance.php';
require_once __DIR__.'/monthly-meeting.php';
require_once __DIR__.'/meeting-agenda.php';
$user=require_roles(['admin','manager']);
$id=(int)($_GET['id'] ?? 0);
$record=$id?one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL',[$id]):null;
if ($id && !$record) fail(404,'Client not found.');
// Live check from the meeting date box: is that day free? (one client a day — no other client's meeting or shoot)
if (isset($_GET['meeting_check'])) {
    $checkDate=(string)$_GET['meeting_check'];
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$checkDate) || !checkdate((int)substr($checkDate,5,2),(int)substr($checkDate,8,2),(int)substr($checkDate,0,4))) { echo json_encode(['ok'=>false]); exit; }
    echo json_encode(['ok'=>true,'clashes'=>meeting_day_clashes($checkDate,($_GET['scope']??'permanent')!=='months',$id)],JSON_UNESCAPED_UNICODE);
    exit;
}
$login=$record && $record['user_id']?one('SELECT username,credential_cipher FROM users WHERE id=?',[$record['user_id']]):null;
$error=null;
const CLIENT_INDUSTRIES=['Retail & shops','Manufacturing','Real estate','Healthcare & clinics','Education','Food & restaurants','Hotels & hospitality','Fashion & beauty','Automobile','Finance & insurance','IT & software','Construction','Events & entertainment','Other'];
const CLIENT_SERVICES=['graphic_design'=>'Graphic design','social_media'=>'Social media management','video_editing'=>'Video editing','content_strategy'=>'Content strategy','meta_google_ads'=>'Meta & Google ads','website'=>'Website','seo_blogs'=>'SEO & blogs','gmb'=>'GMB maintenance','software_app'=>'Software / App','printing'=>'Printing','theater_ads'=>'Theater ads','photoshoot'=>'Photoshoot','podcast'=>'Podcast'];
const CLIENT_BILLING=['monthly'=>'Monthly retainer','one_time'=>'One-time project','quarterly'=>'Quarterly'];
const CLIENT_STATUSES=['lead'=>'Lead','active'=>'Active','on_hold'=>'On hold'];
$managerOptions=rows("SELECT id,full_name,role FROM users WHERE role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL ORDER BY full_name");
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf(); $savedFile=null;
    try {
        $action=$_POST['action']??'save_client';
        if ($action==='update_payment') {
            if (!$id) throw new InvalidArgumentException('Select a client before editing a payment.');
            $paymentId=positive_id($_POST['payment_id']??null);
            $paymentDate=date_input('payment_date');
            $amount=decimal_money(money_cents($_POST['amount']??''));
            if (money_cents($amount)===0) throw new InvalidArgumentException('Payment must be greater than zero.');
            db()->beginTransaction();
            $lockedClient=one('SELECT payment_total FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);
            $payment=one('SELECT id FROM client_payments WHERE id=? AND client_id=? FOR UPDATE',[$paymentId,$id]);
            if (!$lockedClient || !$payment) throw new InvalidArgumentException('Client payment is no longer available.');
            $otherPayments=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=? AND id<>?',[$id,$paymentId])->fetchColumn();
            if (money_cents($otherPayments)+money_cents($amount)>money_cents((string)$lockedClient['payment_total'])) {
                throw new InvalidArgumentException('Payments exceed the total payment amount.');
            }
            query('UPDATE client_payments SET payment_date=?,amount=? WHERE id=? AND client_id=?',[$paymentDate,$amount,$paymentId,$id]);
            $paid=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=?',[$id])->fetchColumn();
            query('UPDATE clients SET paid_amount=? WHERE id=?',[$paid,$id]);
            db()->commit();
            flash('Saved payment updated.');
            redirect('admin-add-client.php?id='.$id.'#saved-payments');
        }
        if ($action!=='save_client') throw new InvalidArgumentException('Invalid action.');
        $name=text_input('client_name',150);
        $website=text_input('website_url',2048,false);
        if ($website!=='' && (!filter_var($website,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($website,PHP_URL_SCHEME)??''),['http','https'],true))) { throw new InvalidArgumentException('Enter an http:// or https:// website URL.'); }
        $socialLinks=$_POST['social_links']??[];
        if (!is_array($socialLinks) || count($socialLinks)>50) throw new InvalidArgumentException('Add up to 50 social media links.');
        $validSocialLinks=[];
        foreach ($socialLinks as $link) {
            if (!is_array($link)) throw new InvalidArgumentException('Invalid social media link.');
            $platformValue=$link['platform']??'';
            $urlValue=$link['url']??'';
            if (!is_string($platformValue) || !is_string($urlValue)) throw new InvalidArgumentException('Invalid social media link.');
            $platform=trim($platformValue);
            $url=trim($urlValue);
            $socialUsername=$link['username']??''; $socialPassword=$link['password']??'';
            if (!is_string($socialUsername) || !is_string($socialPassword) || mb_strlen($socialUsername)>255 || strlen($socialPassword)>1000) throw new InvalidArgumentException('Invalid social username or password.');
            if ($platform==='' && $url==='' && $socialUsername==='' && $socialPassword==='') continue;
            $cipher=null;
            if ($id && !empty($link['id'])) $cipher=one('SELECT password_cipher FROM client_social_links WHERE id=? AND client_id=?',[(int)$link['id'],$id])['password_cipher']??null;
            if (!empty($link['clear_password'])) $cipher=null;
            if ($socialPassword!=='') $cipher=credential_encrypt($socialPassword);
            if ($platform==='' || mb_strlen($platform)>100) throw new InvalidArgumentException('Enter a platform name up to 100 characters for each social media link.');
            if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true) || strlen($url)>2048) throw new InvalidArgumentException('Enter a valid http:// or https:// URL for each social media link.');
            $validSocialLinks[]=['platform'=>$platform,'url'=>$url,'username'=>$socialUsername,'cipher'=>$cipher];
        }
        $clientType=$_POST['client_type']??'non-premium';
        if (!in_array($clientType,['premium','non-premium'],true)) throw new InvalidArgumentException('Select a valid client type.');
        $loginName=optional_text('login_username',100); $loginPassword=$_POST['login_password']??'';
        if (!is_string($loginPassword) || ($loginPassword!=='' && (strlen($loginPassword)<8 || strlen($loginPassword)>72))) throw new InvalidArgumentException('Client password must have 8-72 characters.');
        if ($loginName!=='' && !preg_match('/^[A-Za-z0-9._ -]{3,100}$/D',$loginName)) throw new InvalidArgumentException('Client username must have 3-100 letters, numbers, spaces, dots, underscores or hyphens.');
        if (($loginName!=='' && empty($record['user_id']) && $loginPassword==='') || ($loginPassword!=='' && $loginName==='') || (!empty($record['user_id']) && $loginName==='')) throw new InvalidArgumentException('Enter the client login username and password. Existing logins require a username.');
        $legacySocialUrl=$validSocialLinks[0]['url']??'';
        foreach (['domain_password','gmb_password'] as $secretField) if (!is_string($_POST[$secretField]??'' ) || strlen($_POST[$secretField]??'')>1000) throw new InvalidArgumentException('Passwords must be 1,000 characters or fewer.');
        $extra=['client_type'=>$clientType,'phone'=>optional_text('phone',30),'starting_date'=>optional_date('starting_date'),'ending_date'=>optional_date('ending_date'),'package'=>optional_text('package'),'social_media_url'=>$legacySocialUrl,'gmb_url'=>optional_text('gmb_url',2048),'domain_name'=>optional_text('domain_name',255),'domain_provider'=>optional_text('domain_provider',100),'domain_username'=>optional_text('domain_username',255),'domain_password_cipher'=>($_POST['domain_password']??'')!==''?credential_encrypt($_POST['domain_password']):($record['domain_password_cipher']??null),'domain_expiry'=>optional_date('domain_expiry'),'gmb_username'=>optional_text('gmb_username',255),'gmb_password_cipher'=>($_POST['gmb_password']??'')!==''?credential_encrypt($_POST['gmb_password']):($record['gmb_password_cipher']??null),'gmb_access_granted'=>isset($_POST['gmb_access_granted'])?1:0];
        if ($extra['starting_date'] && $extra['ending_date'] && $extra['ending_date']<$extra['starting_date']) throw new InvalidArgumentException('Ending date cannot be before starting date.');
        foreach (['social_media_url','gmb_url'] as $key) if ($extra[$key]!=='' && (!filter_var($extra[$key],FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($extra[$key],PHP_URL_SCHEME)??''),['http','https'],true))) throw new InvalidArgumentException('Enter valid http:// or https:// social media and GMB URLs.');
        foreach (['monthly_reels','monthly_posters','monthly_carousels','monthly_blogs'] as $key) {
            $number=filter_var($_POST[$key]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
            if ($number===false) throw new InvalidArgumentException('Monthly work quantities must be whole numbers from 0 to 1,000,000.');
            $extra[$key]=$number;
        }
        $extra['monthly_work_remark']=optional_text('monthly_work_remark',5000);
        // Monthly meeting: a date (its day repeats every month, e.g. 19 Nov → the 19th of every month) + time + how we meet.
        $meetingDate=trim((string)($_POST['meeting_date']??''));
        $meetingDay=$meetingDate!==''?(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$meetingDate)&&checkdate((int)substr($meetingDate,5,2),(int)substr($meetingDate,8,2),(int)substr($meetingDate,0,4))?(int)substr($meetingDate,8,2):false):trim((string)($_POST['meeting_day']??''));
        if ($meetingDay===false) throw new InvalidArgumentException('Choose a valid monthly meeting date.');
        if ($meetingDay!=='') {
            $meetingDay=filter_var($meetingDay,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>31]]);
            if ($meetingDay===false) throw new InvalidArgumentException('Choose a valid monthly meeting date.');
            $meetingTime=trim((string)($_POST['meeting_time']??''));
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/D',$meetingTime)) throw new InvalidArgumentException('Choose the monthly meeting time.');
            $extra['meeting_day']=$meetingDay; $extra['meeting_time']=substr($meetingTime,0,5).':00';
        } else { $extra['meeting_day']=null; $extra['meeting_time']=null; }
        // Editing a client that already has a meeting: a new day / time can apply only for some months,
        // after which the usual day / time comes back. Then the client's own day / time stay as they are.
        $meetingOverride=null;
        if ($record && !empty($record['meeting_day']) && $extra['meeting_day']!==null && ($_POST['meeting_scope']??'permanent')==='months'
            && ((int)$extra['meeting_day']!==(int)$record['meeting_day'] || $extra['meeting_time']!==(string)$record['meeting_time'])) {
            // The changed months start from the month of the new date (e.g. 23 Dec 2026 for 3 months → Dec, Jan, Feb).
            $startMonth=$meetingDate!==''?substr($meetingDate,0,7):date('Y-m');
            if ($meetingDate!=='' && $meetingDate<date('Y-m-d')) throw new InvalidArgumentException('For a change of some months, choose today or a future meeting date.');
            if ($startMonth>date('Y-m',strtotime('first day of this month +23 months'))) throw new InvalidArgumentException('Choose a meeting date within the next 2 years.');
            $months=filter_var($_POST['meeting_months']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>24]]);
            if ($months===false || $months===null) throw new InvalidArgumentException('Enter for how many months the new meeting date applies (1 to 24).');
            $meetingOverride=['meeting_day'=>$extra['meeting_day'],'meeting_time'=>$extra['meeting_time'],'start_month'=>$startMonth.'-01','months'=>$months];
            $extra['meeting_day']=(int)$record['meeting_day']; $extra['meeting_time']=(string)$record['meeting_time'];
        }
        // One client a day: a new or changed meeting date must be free — no other client's monthly meeting or shoot
        // that day (and, for a date that repeats every month, no other client on the same day of the month).
        $meetingDayChanged=$meetingOverride!==null || ($extra['meeting_day']!==null && (!$record || (int)$extra['meeting_day']!==(int)($record['meeting_day']??0)));
        if ($meetingDayChanged && $meetingDate!=='') {
            $clashes=meeting_day_clashes($meetingDate,$meetingOverride===null,$id);
            if ($clashes) throw new InvalidArgumentException('This meeting date is not available (one client a day). Already booked: '.implode('; ',$clashes).'. Please choose another date.');
        }
        $meetingMode=(string)($_POST['meeting_mode']??'live');
        $extra['meeting_mode']=in_array($meetingMode,['live','google_meet','phone','zoom'],true)?$meetingMode:'live';
        $extra['meeting_place']=optional_text('meeting_place',2048);
        if (isset($_POST['client_profile'])) {
            $industry=optional_text('industry',60);
            if ($industry!=='' && !in_array($industry,CLIENT_INDUSTRIES,true)) throw new InvalidArgumentException('Select a valid industry.');
            $clientEmail=optional_text('email',254);
            if ($clientEmail!=='' && !filter_var($clientEmail,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid client email address.');
            $services=$_POST['services']??[];
            if (!is_array($services)) $services=[];
            $services=array_values(array_intersect(array_keys(CLIENT_SERVICES),array_map('strval',$services)));
            // Account manager / lead department are no longer on the form: keep what was saved unless a value is posted.
            $accountManager=isset($_POST['account_manager_id'])?null:($record['account_manager_id']??null); if (($_POST['account_manager_id']??'')!=='') { $accountManager=positive_id($_POST['account_manager_id']); if (!in_array($accountManager,array_map('intval',array_column($managerOptions,'id')),true)) throw new InvalidArgumentException('Choose a valid account manager.'); }
            $leadDepartment=isset($_POST['lead_department_id'])?null:($record['lead_department_id']??null); if (($_POST['lead_department_id']??'')!=='') { $leadDepartment=positive_id($_POST['lead_department_id']); if (!one('SELECT id FROM departments WHERE id=? AND is_active=1',[$leadDepartment])) throw new InvalidArgumentException('Choose a valid lead department.'); }
            $billing=is_string($_POST['billing_cycle']??null)&&isset(CLIENT_BILLING[$_POST['billing_cycle']])?$_POST['billing_cycle']:'monthly';
            $status=is_string($_POST['client_status']??null)&&isset(CLIENT_STATUSES[$_POST['client_status']])?$_POST['client_status']:'active';
            $extra+=['industry'=>$industry,'contact_person'=>optional_text('contact_person',150),'contact_designation'=>optional_text('contact_designation',150),'whatsapp'=>optional_text('whatsapp',30),'email'=>$clientEmail,'city'=>optional_text('city',100),'gst_number'=>optional_text('gst_number',30),'address'=>optional_text('address',500),'services'=>implode(',',$services),'account_manager_id'=>$accountManager,'lead_department_id'=>$leadDepartment,'billing_cycle'=>$billing,'team_notes'=>optional_text('team_notes',5000),'client_status'=>$status];
        }
        $total=money_cents($_POST['payment_total']??($record['payment_total']??'0'));
        $extra['payment_total']=decimal_money($total);
        $payments=$_POST['payments']??[]; $newPayments=[];
        if (!is_array($payments) || count($payments)>100) throw new InvalidArgumentException('Add up to 100 payments at a time.');
        $requestKey=$_POST['payment_request_key']??'';
        foreach ($payments as $payment) {
            if (!is_array($payment)) throw new InvalidArgumentException('Invalid payment entry.');
            if (($payment['amount']??'')==='' && ($payment['date']??'')==='') continue;
            if (!is_string($requestKey) || !preg_match('/^[a-f0-9]{32}$/D',$requestKey)) throw new InvalidArgumentException('Refresh the payment form and try again.');
            $amount=money_cents($payment['amount']??'');
            if (!$amount) throw new InvalidArgumentException('Payment must be greater than zero.');
            $newPayments[]=['date'=>date_input('date',$payment),'amount'=>decimal_money($amount),'key'=>$requestKey.'-'.count($newPayments)];
        }
        db()->beginTransaction();
        if ($id && !one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id])) throw new InvalidArgumentException('Client is no longer available.');
        $logo=$record['logo_path'] ?? '';
        $file=$_FILES['client_logo'] ?? null;
        if ($file && $file['error']!==UPLOAD_ERR_NO_FILE) {
            if ($file['error']!==UPLOAD_ERR_OK || $file['size']>50*1024*1024) { throw new InvalidArgumentException('Choose a PNG, JPG or WebP logo under 50 MB.'); }
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime] ?? null;
            if (!$extension || !getimagesize($file['tmp_name'])) { throw new InvalidArgumentException('Choose a valid PNG, JPG or WebP image.'); }
            $directory=dirname(__DIR__).'/uploads/photos';
            if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) { throw new InvalidArgumentException('Unable to create uploads/photos. Check the folder permissions.'); }
            if (!is_writable($directory)) { throw new InvalidArgumentException('uploads/photos is not writable by PHP. Check the folder permissions.'); }
            $logo='uploads/photos/'.bin2hex(random_bytes(16)).'.'.$extension;
            $savedFile=dirname(__DIR__).'/'.$logo;
            if (!move_uploaded_file($file['tmp_name'],$savedFile)) { throw new InvalidArgumentException('The logo could not be stored. Check uploads/photos permissions and try a PNG, JPG or WebP under 50 MB.'); }
        }
        if ($id) query('UPDATE clients SET client_name=?,website_url=?,logo_path=? WHERE id=?',[$name,$website,$logo,$id]);
        else query('INSERT INTO clients (client_name,website_url,logo_path,created_by) VALUES (?,?,?,?)',[$name,$website,$logo,$user['id']]);
        if (!$id) $id=(int)db()->lastInsertId();
        if ($loginName!=='') {
            $loginId=$record['user_id']??null;
            if ($loginId) {
                query("UPDATE users SET username=?,full_name=? WHERE id=? AND role='client'",[$loginName,$name,$loginId]);
                if ($loginPassword!=='') query('UPDATE users SET password_hash=?,credential_cipher=? WHERE id=?',[password_hash($loginPassword,PASSWORD_DEFAULT),credential_encrypt($loginPassword),$loginId]);
            } else {
                query("INSERT INTO users (full_name,username,password_hash,credential_cipher,role,account_status,must_change_password,created_by) VALUES (?,?,?,?,'client','active',0,?)",[$name,$loginName,password_hash($loginPassword,PASSWORD_DEFAULT),credential_encrypt($loginPassword),$user['id']]);
                $loginId=(int)db()->lastInsertId();
                query('UPDATE clients SET user_id=? WHERE id=?',[$loginId,$id]);
            }
        }
        foreach ($newPayments as $payment) {
            $existing=one('SELECT * FROM client_payments WHERE request_key=?',[$payment['key']]);
            if ($existing) {
                if ((int)$existing['client_id']!==$id || $existing['payment_date']!==$payment['date'] || $existing['amount']!==$payment['amount']) throw new InvalidArgumentException('This payment form was already submitted. Refresh before adding another payment.');
                continue;
            }
            query('INSERT INTO client_payments (client_id,payment_date,amount,request_key,created_by) VALUES (?,?,?,?,?)',[$id,$payment['date'],$payment['amount'],$payment['key'],$user['id']]);
        }
        $paid=(string)query('SELECT COALESCE(SUM(amount),0) FROM client_payments WHERE client_id=?',[$id])->fetchColumn();
        if (money_cents($paid)>$total) throw new InvalidArgumentException('Payments exceed the total payment amount. Increase the total or correct the new payment.');
        $extra['paid_amount']=$paid;
        $assignments=implode(',',array_map(static fn($key)=>$key.'=?',array_keys($extra)));
        query('UPDATE clients SET '.$assignments.' WHERE id=?',array_merge(array_values($extra),[$id]));
        if ($meetingOverride) query("INSERT INTO client_meeting_changes (client_id,meeting_day,meeting_time,start_month,months,reason,status,requested_by,decided_by,decided_at) VALUES (?,?,?,?,?,'Changed on the client form','approved',?,?,NOW())",[$id,$meetingOverride['meeting_day'],$meetingOverride['meeting_time'],$meetingOverride['start_month'],$meetingOverride['months'],$user['id'],$user['id']]);
        query('DELETE FROM client_social_links WHERE client_id=?',[$id]);
        foreach ($validSocialLinks as $link) {
            query('INSERT INTO client_social_links (client_id,platform_name,url,username,password_cipher) VALUES (?,?,?,?,?)',[$id,$link['platform'],$link['url'],$link['username'],$link['cipher']]);
        }
        db()->commit();
        flash('Client saved.'.(($_POST['after']??'')==='project'?' Now assign the first task for this client.':'')); redirect(($_POST['after']??'')==='project'?'manager-assign-work.php':'add-client.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); if (!$record) $id=0; if ($savedFile && is_file($savedFile)) unlink($savedFile); $error=mutation_error($e); }
}
$value=static fn($key,$fallback='')=>$_POST[$key]??$record[$key]??$fallback;
$isPost=$_SERVER['REQUEST_METHOD']==='POST';
$selectedServices=$isPost?(is_array($_POST['services']??null)?array_map('strval',$_POST['services']):[]):array_filter(explode(',',(string)($record['services']??'')));
$clientStatus=(string)$value('client_status','active'); if (!isset(CLIENT_STATUSES[$clientStatus])) $clientStatus='active';
$billingCycle=(string)$value('billing_cycle','monthly');
$clientName=(string)$value('client_name');
$clientInitials=''; foreach (array_slice(preg_split('/\s+/',trim($clientName)) ?: [],0,2) as $part) $clientInitials.=mb_strtoupper(mb_substr($part,0,1));
$departmentsList=departments();
page_start($record?'Edit client':'Add client','add-client.php'); error_message($error);
?>
<form method="post" enctype="multipart/form-data" class="record-form emp-page cl-page" data-cl-form><?= csrf_field() ?><input type="hidden" name="client_profile" value="1">
<header class="emp-header">
    <div><p class="emp-crumb">Dashboard / Clients / <?= $record?'Edit':'Add' ?></p><h2 class="emp-title"><?= $record?'Edit client':'Add new client' ?></h2><p class="emp-sub">Create a client profile and assign the right team. Fields marked * are required.</p></div>
    <a class="emp-btn emp-btn-outline" href="add-client.php">← Back to clients</a>
</header>
<div class="emp-layout">
<div class="emp-main">
    <section class="emp-card"><h3>Business details</h3><p class="emp-card-sub">Who the client is and how to reach them</p>
        <div class="emp-grid">
            <label>Business / brand name *<input id="client_name" name="client_name" value="<?= h($clientName) ?>" maxlength="150" placeholder="e.g. Santhi PVC &amp; HDPE Pipes" required data-cl-src="name"></label>
            <label>Industry *<select name="industry" required data-cl-src="industry"><option value="">Select industry</option><?php foreach (CLIENT_INDUSTRIES as $industry): ?><option value="<?= h($industry) ?>" <?= (string)$value('industry')===$industry?'selected':'' ?>><?= h($industry) ?></option><?php endforeach; ?></select></label>
            <label>Contact person *<input name="contact_person" maxlength="150" value="<?= h($value('contact_person')) ?>" placeholder="Full name" required></label>
            <label>Designation<input name="contact_designation" maxlength="150" value="<?= h($value('contact_designation')) ?>" placeholder="e.g. Owner, Marketing Head"></label>
            <label>Phone *<input id="phone" name="phone" type="tel" maxlength="30" value="<?= h($value('phone')) ?>" placeholder="98765 43210" required></label>
            <label>WhatsApp number<input name="whatsapp" type="tel" maxlength="30" value="<?= h($value('whatsapp')) ?>" placeholder="Same as phone"></label>
            <label>Email<input name="email" type="email" maxlength="254" value="<?= h($value('email')) ?>" placeholder="client@business.com"></label>
            <label>Website / Instagram<input type="url" id="website_url" name="website_url" value="<?= h($value('website_url')) ?>" placeholder="https://" maxlength="2048"></label>
            <label>City *<input name="city" maxlength="100" value="<?= h($value('city')) ?>" placeholder="e.g. Visakhapatnam" required data-cl-src="city"></label>
            <label>GST number<input name="gst_number" maxlength="30" value="<?= h($value('gst_number')) ?>" placeholder="Optional"></label>
            <label class="emp-full">Address<textarea name="address" maxlength="500" rows="2" placeholder="Office / shop address"><?= h($value('address')) ?></textarea></label>
        </div>
    </section>

    <section class="emp-card"><h3>Services required *</h3><p class="emp-card-sub">Select everything this client has signed up for</p>
        <div class="cl-services" data-cl-services>
            <?php foreach (CLIENT_SERVICES as $key=>$label): ?><label><input type="checkbox" name="services[]" value="<?= $key ?>" <?= in_array($key,$selectedServices,true)?'checked':'' ?>><span><?= h($label) ?></span></label><?php endforeach; ?>
        </div>
    </section>

    <section class="emp-card"><h3>Billing</h3><p class="emp-card-sub">When the project started and how the client pays</p>
        <div class="emp-grid">
            <label>Start date *<input id="starting_date" name="starting_date" type="date" value="<?= h($value('starting_date')) ?>" required></label>
            <label>Package amount (₹)<input id="payment_total" name="payment_total" type="number" min="0" max="9999999999.99" step="0.01" placeholder="₹ 0" value="<?= h($value('payment_total','0.00')) ?>" required></label>
        </div>
        <fieldset class="emp-choice emp-choice-inline"><legend>Billing cycle</legend>
            <?php foreach (CLIENT_BILLING as $k=>$l): ?><label><input type="radio" name="billing_cycle" value="<?= $k ?>" <?= $billingCycle===$k?'checked':'' ?>><span><?= h($l) ?></span></label><?php endforeach; ?>
        </fieldset>
        <label class="cl-notes">Notes for the team<textarea name="team_notes" maxlength="5000" rows="3" placeholder="Brand guidelines, preferred colours, posting schedule, special instructions…"><?= h($value('team_notes')) ?></textarea></label>
    </section>

    <section class="emp-card" id="monthly-meeting"><h3>Monthly meeting</h3><p class="emp-card-sub">Pick the meeting date — the meeting is on that date every month. It shows in the client’s details and dashboard with a countdown.</p>
        <?php $meetingDayValue=(string)$value('meeting_day',''); $meetingTimeValue=substr((string)$value('meeting_time',''),0,5); $meetingModeValue=(string)$value('meeting_mode','live');
        // The date box shows the next usual meeting date (this month's, or next month's if it has passed).
        $meetingDateValue=(string)($_POST['meeting_date']??'');
        if ($meetingDateValue==='' && !isset($_POST['meeting_date']) && $meetingDayValue!=='') {
            $mDayInt=(int)$meetingDayValue; $mMonth=new DateTimeImmutable('first day of this month');
            $mDate=$mMonth->format('Y-m-').sprintf('%02d',min($mDayInt,(int)$mMonth->format('t')));
            if ($mDate<date('Y-m-d')) { $mMonth=$mMonth->modify('+1 month'); $mDate=$mMonth->format('Y-m-').sprintf('%02d',min($mDayInt,(int)$mMonth->format('t'))); }
            $meetingDateValue=$mDate;
        } ?>
        <div class="emp-grid">
            <label>Meeting date<input type="date" name="meeting_date" value="<?= h($meetingDateValue) ?>" data-cl-meeting-day><small>Repeats every month on this date. Leave empty for no monthly meeting.</small></label>
            <label>Meeting time<input type="time" name="meeting_time" value="<?= h($meetingTimeValue) ?>" data-cl-meeting-time></label>
            <label>How you will meet<select name="meeting_mode"><?php foreach (['live'=>'Live meeting','google_meet'=>'Google Meet','phone'=>'Phone call','zoom'=>'Zoom'] as $k=>$l): ?><option value="<?= $k ?>" <?= $meetingModeValue===$k?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>Place / meeting link<input name="meeting_place" maxlength="2048" value="<?= h($value('meeting_place')) ?>" placeholder="e.g. Our office, or https://meet.google.com/…"></label>
        </div>
        <p class="emp-card-sub" data-cl-meeting-preview aria-live="polite"></p>
        <?php if ($record && !empty($record['meeting_day'])):
            $mScope=(string)($_POST['meeting_scope']??'permanent');
            $mMonths=(string)($_POST['meeting_months']??'1'); ?>
        <fieldset class="emp-choice emp-choice-inline" data-cl-meeting-scope data-orig-day="<?= (int)$record['meeting_day'] ?>" data-orig-time="<?= h(substr((string)$record['meeting_time'],0,5)) ?>" hidden>
            <legend>You changed the meeting date / time. How long should it apply?</legend>
            <label><input type="radio" name="meeting_scope" value="permanent" <?= $mScope!=='months'?'checked':'' ?>><span>Permanently (every month from now)</span></label>
            <label><input type="radio" name="meeting_scope" value="months" <?= $mScope==='months'?'checked':'' ?>><span>Only for some months, then back to the usual date</span></label>
            <div class="emp-grid" data-cl-meeting-months<?= $mScope==='months'?'':' hidden' ?>>
                <label>For how many months<input type="number" name="meeting_months" min="1" max="24" step="1" value="<?= h($mMonths) ?>"><small>Counted from the month of the new date.</small></label>
            </div>
            <p class="emp-card-sub" data-cl-meeting-scope-hint></p>
        </fieldset>
        <?php $upcomingMeetings=mm_upcoming($record,6); if ($upcomingMeetings): ?>
        <div class="table-scroll"><table><thead><tr><th>Month</th><th>Meeting date</th><th>Time</th><th></th></tr></thead><tbody>
            <?php foreach ($upcomingMeetings as $m): ?><tr><td><?= h(date('F Y',strtotime($m['date']))) ?></td><td><?= h(date('D, d-m-Y',strtotime($m['date']))) ?></td><td><?= h(mm_time_label($m['time'])) ?></td><td><?= $m['changed']?'<span class="badge client-status is-on_hold">Changed · '.h(mm_scope_label($m['change'])).'</span>':'<small>Usual date</small>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <p class="emp-card-sub cl-meeting-link"><a href="client-monthly-meeting.php?client=<?= (int)$record['id'] ?>">Move one meeting, undo a change, or see client requests →</a></p><?php endif; ?>
    </section>

    <details class="cl-more"<?= $record?' open':'' ?>><summary><span>More details (optional)</span><small>Client type, contract dates, domain &amp; GMB, social logins, client portal login, monthly work and payments</small></summary>
    <section class="emp-card"><h3>Contract</h3>
        <div class="emp-grid">
            <label>Type of client<select name="client_type"><?= options([['id'=>'premium','name'=>'Premium'],['id'=>'non-premium','name'=>'Non-premium']],'name',$value('client_type','non-premium')) ?></select></label>
            <label>Package name<input id="package" name="package" maxlength="255" value="<?= h($value('package')) ?>" placeholder="e.g. Social Media Gold"></label>
            <label>Ending date<input id="ending_date" name="ending_date" type="date" value="<?= h($value('ending_date')) ?>"></label>
            <label>Project age<input value="<?= h(project_age($value('starting_date') ?: null)) ?>" readonly class="emp-soft"></label>
        </div>
    </section>
    <section class="panel form-section"><h2>Website, domain, GMB &amp; social media</h2><div class="fields">
<div class="field"><label for="domain_name">Domain name</label><input id="domain_name" name="domain_name" maxlength="255" value="<?= h($value('domain_name')) ?>" placeholder="example.com"></div>
<div class="field"><label for="domain_provider">Domain purchased from</label><select id="domain_provider" name="domain_provider"><option value="">Choose provider</option><?php foreach (['Hostinger','GoDaddy','Other'] as $provider): ?><option value="<?= h($provider) ?>" <?= $value('domain_provider')===$provider?'selected':'' ?>><?= h($provider) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="domain_username">Domain account username</label><input id="domain_username" name="domain_username" maxlength="255" value="<?= h($value('domain_username')) ?>"></div>
<div class="field"><label for="domain_password">Domain account password</label><div class="password-wrapper"><input type="password" id="domain_password" name="domain_password" autocomplete="new-password" placeholder="Leave blank to keep saved password"><button type="button" class="password-eye" data-password-toggle="domain_password" aria-controls="domain_password" aria-pressed="false" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></div><?php if (!empty($record['domain_password_cipher'])): ?><details class="simple-details"><summary>View saved domain password</summary><div class="password-wrapper"><input type="password" id="saved-domain-password" value="<?= h(credential_decrypt($record['domain_password_cipher'])) ?>" readonly><button type="button" class="password-eye" data-password-toggle="saved-domain-password" aria-controls="saved-domain-password" aria-pressed="false" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></div></details><?php endif; ?></div>
<div class="field"><label for="domain_expiry">Domain expiry date</label><input type="date" id="domain_expiry" name="domain_expiry" value="<?= h($value('domain_expiry')) ?>"></div>
<div class="field"><label for="gmb_url">Google Business Profile URL</label><input type="url" id="gmb_url" name="gmb_url" value="<?= h($value('gmb_url')) ?>" placeholder="https://" maxlength="2048"></div>
<div class="field"><label for="gmb_username">GMB login username / email</label><input id="gmb_username" name="gmb_username" maxlength="255" value="<?= h($value('gmb_username')) ?>"></div>
<div class="field"><label for="gmb_password">GMB password</label><div class="password-wrapper"><input type="password" id="gmb_password" name="gmb_password" autocomplete="new-password" placeholder="Leave blank to keep saved password"><button type="button" class="password-eye" data-password-toggle="gmb_password" aria-controls="gmb_password" aria-pressed="false" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></div><?php if (!empty($record['gmb_password_cipher'])): ?><details class="simple-details"><summary>View saved GMB password</summary><div class="password-wrapper"><input type="password" id="saved-gmb-password" value="<?= h(credential_decrypt($record['gmb_password_cipher'])) ?>" readonly><button type="button" class="password-eye" data-password-toggle="saved-gmb-password" aria-controls="saved-gmb-password" aria-pressed="false" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></div></details><?php endif; ?></div>
<div class="field full social-access-grid"><div class="gmb-access-field sa-access"><label>Access status</label><label class="gmb-access-check" for="gmb_access_granted"><input id="gmb_access_granted" type="checkbox" name="gmb_access_granted" value="1" <?= ($_SERVER['REQUEST_METHOD']==='POST'?isset($_POST['gmb_access_granted']):!empty($record['gmb_access_granted']))?'checked':'' ?>><span>Client has granted us access</span></label><p class="help">Check this when the client has shared Google Business Profile access with your team.</p></div>
<div class="sa-head"><label>Social media links</label><p class="help">Enter a platform name and its URL. Add as many as needed.</p></div><div id="social-link-rows"><?php
$savedSocialLinks=$id?rows('SELECT id,platform_name AS platform,url,username,password_cipher FROM client_social_links WHERE client_id=? ORDER BY id',[$id]):[];
$savedSocialPasswords=array_column($savedSocialLinks,'password_cipher','id');
$socialDraft=$_POST['social_links']??$savedSocialLinks;
if (!is_array($socialDraft)) $socialDraft=[];
if (!$socialDraft) $socialDraft=[['platform'=>'','url'=>'']];
foreach ($socialDraft as $index=>$link): if (!is_array($link)) continue; $savedSocialPassword=$savedSocialPasswords[(int)($link['id']??0)]??null; ?>
<div class="fields repeat-row social-link-row">
<div class="field"><label>Platform<select name="social_links[<?= (int)$index ?>][platform]"><option value="">Choose platform</option><?php $platforms=['Instagram','Facebook','YouTube','LinkedIn','Pinterest','Twitter','Quora','Other']; foreach ($platforms as $platform): ?><option value="<?= h($platform) ?>" <?= ($link['platform']??'')===$platform?'selected':'' ?>><?= h($platform) ?></option><?php endforeach; if (!empty($link['platform'])&&!in_array($link['platform'],$platforms,true)): ?><option selected value="<?= h($link['platform']) ?>"><?= h($link['platform']) ?></option><?php endif; ?></select></label></div>
<div class="field"><label>Social media URL<input type="url" name="social_links[<?= (int)$index ?>][url]" maxlength="2048" placeholder="https://" value="<?= h($link['url']??'') ?>"></label></div>
<input type="hidden" name="social_links[<?= (int)$index ?>][id]" value="<?= (int)($link['id']??0) ?>">
<div class="field"><label>Username<input name="social_links[<?= (int)$index ?>][username]" maxlength="255" value="<?= h($link['username']??'') ?>" autocomplete="off"></label></div>
<div class="field"><label for="social-password-<?= (int)$index ?>">Password</label><div class="password-wrapper"><input type="password" id="social-password-<?= (int)$index ?>" name="social_links[<?= (int)$index ?>][password]" maxlength="1000" autocomplete="new-password" placeholder="••••••"><button type="button" class="password-eye" data-password-toggle="social-password-<?= (int)$index ?>" aria-controls="social-password-<?= (int)$index ?>" aria-pressed="false" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></div>
<?php if ($savedSocialPassword!==null): ?><details class="simple-details"><summary>View saved password</summary><div class="password-wrapper"><input type="password" id="saved-social-password-<?= (int)$index ?>" aria-label="Saved social media password" value="<?= h(credential_decrypt($savedSocialPassword)) ?>" readonly autocomplete="off"><button type="button" class="password-eye" data-password-toggle="saved-social-password-<?= (int)$index ?>" aria-controls="saved-social-password-<?= (int)$index ?>" aria-pressed="false" aria-label="Show password" title="Show password"><?= icon('eye') ?></button></div></details><?php endif; ?>
</div>
<button type="button" class="secondary" data-remove-row>Remove</button></div>
<?php endforeach; ?></div><button type="button" class="secondary" data-add-row="social">+ Add social media link</button></div>
</div></section>
<section class="panel form-section"><h2>Client login</h2><?php if ($login && $user['role']==='admin'): ?><details class="simple-details"><summary>View saved login credentials</summary><dl class="details-list"><dt>Username</dt><dd><?= h($login['username']) ?></dd><dt>Password</dt><dd><?= h(credential_decrypt($login['credential_cipher'])?:'Not recoverable. Set a new password below.') ?></dd></dl></details><?php endif; ?><p class="help">Create credentials for the client to view their own profile. Leave both empty if a login is not needed.</p><div class="fields"><div class="field"><label>Username<input name="login_username" minlength="3" maxlength="100" pattern="[A-Za-z0-9._\- ]{3,100}" autocomplete="off" value="<?= h($_POST['login_username']??$login['username']??'') ?>" aria-describedby="client-username-help"></label><p class="help" id="client-username-help">Spaces between words are allowed. Use the same spaces when signing in.</p></div><div class="field"><label><?= $login?'New password (leave empty to keep current)':'Password' ?><input type="password" name="login_password" minlength="8" maxlength="72" autocomplete="new-password"></label></div></div></section>
<section class="panel form-section"><h2><span class="section-number">2</span> Work to deliver each month</h2><p class="help">Enter how many of each item you need to create. Use 0 if it is not included.</p><div class="fields">
<?php foreach (['monthly_reels'=>'Reels','monthly_posters'=>'Posters','monthly_carousels'=>'Carousels','monthly_blogs'=>'Blogs'] as $key=>$label): ?><div class="field"><label for="<?= $key ?>"><?= $label ?> per month</label><input type="number" min="0" max="1000000" step="1" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($value($key,0)) ?>" required></div><?php endforeach; ?>
<div class="field full"><label for="monthly_work_remark">Remark</label><textarea id="monthly_work_remark" name="monthly_work_remark" rows="3" maxlength="5000"><?= h($value('monthly_work_remark')) ?></textarea></div>
</div></section>
<section class="panel form-section"><h2><span class="section-number">3</span> Payments</h2>
<div class="money-summary"><div class="money-card"><span>Total amount</span><strong id="total-preview"><?= h(money_label($record['payment_total']??0)) ?></strong></div><div class="money-card"><span>Received so far</span><strong id="paid-preview"><?= h(money_label($record['paid_amount']??0)) ?></strong></div><div class="money-card highlight"><span>Still to receive</span><strong id="remaining-preview" data-paid="<?= h($record['paid_amount']??0) ?>"><?= h(money_label($record['remaining_amount']??0)) ?></strong></div></div>
<p class="help">Example: ₹10,000 total − ₹2,000 received = ₹8,000 remaining.</p>
<h3>Add money received</h3><p class="help">Enter the date and amount paid by the client. Leave empty if no new payment was received.</p>
<input type="hidden" name="payment_request_key" value="<?= h($_POST['payment_request_key']??bin2hex(random_bytes(16))) ?>"><div id="payment-rows"><?php $draft=$_POST['payments']??[['amount'=>'','date'=>'']]; if (!is_array($draft)) $draft=[]; foreach ($draft as $index=>$payment): if (!is_array($payment)) continue; ?><div class="fields repeat-row"><div class="field"><label>Date received<input type="date" name="payments[<?= (int)$index ?>][date]" value="<?= h($payment['date']??'') ?>"></label></div><div class="field"><label>Amount received (₹)<input type="number" name="payments[<?= (int)$index ?>][amount]" min="0.01" max="9999999999.99" step="0.01" placeholder="e.g. 2000" value="<?= h($payment['amount']??'') ?>"></label></div><button type="button" class="secondary" data-remove-row>Remove</button></div><?php endforeach; ?></div>
<button type="button" class="secondary" data-add-row="payment">+ Add another payment</button><p class="help">The totals above include new entries. Click Save client to save them.</p>
</section>
    </details>
</div>

<aside class="emp-side">
    <section class="emp-card emp-photo-card">
        <label class="emp-photo cl-logo"><input type="file" id="client_logo" name="client_logo" accept="image/png,image/jpeg,image/webp" data-cl-logo><?php if (!empty($record['logo_path'])): ?><img src="client-logo.php?id=<?= (int)$record['id'] ?>" alt="Current logo" data-cl-logo-img><?php else: ?><img alt="" hidden data-cl-logo-img><span class="emp-photo-icon" data-cl-logo-icon>🖼</span><?php endif; ?></label>
        <strong>Client logo</strong><small>PNG, JPG or WebP, transparent background</small>
        <button type="button" class="emp-btn emp-btn-outline emp-btn-sm" data-cl-logo-btn>Upload logo</button>
    </section>
    <section class="emp-card cl-status-card">
        <p class="emp-label">Client status</p>
        <div class="cl-segment" role="radiogroup" aria-label="Client status"><?php foreach (CLIENT_STATUSES as $k=>$l): ?><label><input type="radio" name="client_status" value="<?= $k ?>" <?= $clientStatus===$k?'checked':'' ?>><span><?= h($l) ?></span></label><?php endforeach; ?></div>
    </section>
    <section class="emp-idcard cl-card-preview" aria-label="Client card preview">
        <small class="emp-idcard-label">Client card preview</small>
        <div class="emp-idcard-person"><span class="cl-preview-initials" data-cl-out="initials"><?= h($clientInitials ?: '—') ?></span><div><strong data-cl-out="name"><?= h($clientName ?: 'Client name') ?></strong><small><span data-cl-out="industry"><?= h($value('industry') ?: 'Industry') ?></span> · <span data-cl-out="city"><?= h($value('city') ?: 'City') ?></span></small></div></div>
        <div class="cl-preview-tags" data-cl-out="services"><?php foreach ($selectedServices as $key) if (isset(CLIENT_SERVICES[$key])) echo '<span>'.h(CLIENT_SERVICES[$key]).'</span>'; ?></div>
    </section>
    <section class="emp-card emp-actions">
        <button class="emp-btn emp-btn-gold" type="submit"><?= $record?'Save changes':'Save client' ?></button>
        <button class="emp-btn emp-btn-dark" type="submit" name="after" value="project">Save &amp; create project</button>
        <a class="emp-btn emp-btn-outline" href="add-client.php">Cancel</a>
    </section>
</aside>
</div>
</form>
<script>
(function () {
    var form = document.querySelector('[data-cl-form]'); if (!form) return;
    var ph = {name: 'Client name', industry: 'Industry', city: 'City'};
    function out(k) { return form.querySelector('[data-cl-out="' + k + '"]'); }
    function update() {
        form.querySelectorAll('[data-cl-src]').forEach(function (el) {
            var k = el.dataset.clSrc, v = el.value.trim();
            out(k).textContent = v || ph[k];
            if (k === 'name') out('initials').textContent = v.split(/\s+/).filter(Boolean).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('') || '—';
        });
        var tags = out('services'); tags.textContent = '';
        form.querySelectorAll('[data-cl-services] input:checked').forEach(function (cb) { var s = document.createElement('span'); s.textContent = cb.nextElementSibling.textContent; tags.appendChild(s); });
    }
    form.addEventListener('input', update); form.addEventListener('change', update);
    // Services: at least one must be chosen
    var boxes = form.querySelectorAll('[data-cl-services] input');
    function needOne() { var any = Array.prototype.some.call(boxes, function (b) { return b.checked; }); boxes[0].setCustomValidity(any ? '' : 'Select at least one service.'); }
    boxes.forEach(function (b) { b.addEventListener('change', needOne); }); needOne();
    // Monthly meeting: a time is needed once a day is chosen.
    var mDay = form.querySelector('[data-cl-meeting-day]'), mTime = form.querySelector('[data-cl-meeting-time]');
    function meetingNeedsTime() { mTime.required = mDay.value !== ''; if (mDay.value !== '' && !mTime.value) mTime.value = '11:00'; }
    var dayOf = function () { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(mDay.value); return m ? +m[3] : 0; };
    if (mDay && mTime) { mDay.addEventListener('change', meetingNeedsTime); mTime.required = mDay.value !== ''; }
    // Preview the next meeting dates, and on edit ask how long a changed date / time applies.
    var mPreview = form.querySelector('[data-cl-meeting-preview]'), mScope = form.querySelector('[data-cl-meeting-scope]');
    var monthName = function (d) { return d.toLocaleDateString('en-IN', {month: 'short', year: 'numeric'}); };
    var ord = function (n) { var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); };
    var timeLabel = function (t) { if (!t) return ''; var p = t.split(':'), h = +p[0]; return (h % 12 || 12) + ':' + p[1] + ' ' + (h < 12 ? 'AM' : 'PM'); };
    function meetingPreview() {
        if (!mDay || !mPreview) return;
        var day = dayOf();
        if (!day) { mPreview.textContent = ''; } else {
            var now = new Date(), list = [];
            for (var i = 0; list.length < 3 && i < 4; i++) {
                var d = new Date(now.getFullYear(), now.getMonth() + i, 1), last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
                d.setDate(Math.min(day, last));
                if (d >= new Date(now.getFullYear(), now.getMonth(), now.getDate())) list.push(d.toLocaleDateString('en-IN', {day: 'numeric', month: 'short', year: 'numeric'}));
            }
            mPreview.textContent = 'Meeting every month on the ' + ord(day) + (mTime.value ? ' at ' + timeLabel(mTime.value) : '') + '. Next: ' + list.join(', ') + '.';
        }
        if (!mScope) return;
        var changed = day && (day !== +mScope.dataset.origDay || mTime.value !== mScope.dataset.origTime);
        mScope.hidden = !changed;
        var some = changed && mScope.querySelector('[name="meeting_scope"][value="months"]').checked;
        mScope.querySelector('[data-cl-meeting-months]').hidden = !some;
        var hint = mScope.querySelector('[data-cl-meeting-scope-hint]');
        if (!some) { hint.textContent = changed ? 'The usual date changes to the ' + ord(day) + ' for all future months.' : ''; return; }
        var start = mDay.value.split('-'), n = Math.max(1, Math.min(24, parseInt(mScope.querySelector('[name="meeting_months"]').value, 10) || 1));
        var from = new Date(+start[0], +start[1] - 1, 1), to = new Date(+start[0], +start[1] - 2 + n, 1), back = new Date(+start[0], +start[1] - 1 + n, 1);
        hint.textContent = (n === 1 ? monthName(from) : monthName(from) + ' – ' + monthName(to)) + ': meeting on the ' + ord(day) + ' at ' + timeLabel(mTime.value) + '. From ' + monthName(back) + ' it goes back to the usual ' + ord(+mScope.dataset.origDay) + ' at ' + timeLabel(mScope.dataset.origTime) + '.';
    }
    if (mDay) { form.addEventListener('change', meetingPreview); form.addEventListener('input', meetingPreview); meetingPreview(); }
    // One client a day: when a meeting date is picked, ask the server if that day is free. If another client has a
    // meeting or a shoot then, show a popup with who is booked and put the date back.
    if (mDay) {
        var mPrev = mDay.value, clientId = new URLSearchParams(location.search).get('id') || '';
        var busyPopup = function (date, clashes) {
            var when = new Date(date + 'T00:00:00').toLocaleDateString('en-IN', {weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'});
            var dlg = document.createElement('dialog'); dlg.className = 'mm-busy';
            var h = document.createElement('h3'); h.textContent = '📅 ' + when + ' is not available';
            var p = document.createElement('p'); p.textContent = 'Only one client a day. That day is already booked for:';
            var ul = document.createElement('ul'); clashes.forEach(function (c) { var li = document.createElement('li'); li.textContent = c; ul.appendChild(li); });
            var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'emp-btn emp-btn-gold'; ok.textContent = 'Choose another date';
            ok.addEventListener('click', function () { dlg.close(); });
            dlg.addEventListener('close', function () { dlg.remove(); mDay.focus(); });
            dlg.append(h, p, ul, ok); document.body.appendChild(dlg);
            if (dlg.showModal) dlg.showModal(); else { alert(h.textContent + '\n' + clashes.join('\n')); dlg.remove(); }
        };
        var checkMeetingDay = function (force) {
            if (!mDay.value || (!force && mDay.value === mPrev)) return;
            var picked = form.querySelector('[name="meeting_scope"]:checked');
            var scope = mScope && !mScope.hidden && picked ? picked.value : 'permanent';
            // Same usual day as before (only the month in the box moved): nothing new to check.
            if (mScope && scope === 'permanent' && dayOf() === +mScope.dataset.origDay) { mPrev = mDay.value; return; }
            var date = mDay.value;
            fetch(location.pathname + '?' + (clientId ? 'id=' + encodeURIComponent(clientId) + '&' : '') + 'meeting_check=' + encodeURIComponent(date) + '&scope=' + scope, {credentials: 'same-origin', cache: 'no-store'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (mDay.value !== date) return;
                    if (data.ok && data.clashes && data.clashes.length) { busyPopup(date, data.clashes); mDay.value = mPrev; meetingPreview(); }
                    else mPrev = date;
                }).catch(function () {});
        };
        mDay.addEventListener('change', function () { checkMeetingDay(false); });
        form.querySelectorAll('[name="meeting_scope"]').forEach(function (r) { r.addEventListener('change', function () { checkMeetingDay(true); }); });
    }
    var logo = form.querySelector('[data-cl-logo]'), img = form.querySelector('[data-cl-logo-img]'), icon = form.querySelector('[data-cl-logo-icon]');
    form.querySelector('[data-cl-logo-btn]').addEventListener('click', function () { logo.click(); });
    logo.addEventListener('change', function () { var f = logo.files[0]; if (!f) return; img.src = URL.createObjectURL(f); img.hidden = false; if (icon) icon.hidden = true; });
})();
</script>
<?php if ($record): $history=rows('SELECT * FROM client_payments WHERE client_id=? ORDER BY payment_date,id',[$id]); ?><section class="panel" id="saved-payments"><details class="simple-details" open><summary>View saved payments (<?= count($history) ?>)</summary><div class="table-scroll"><table><thead><tr><th>Date received</th><th>Amount received</th><th>Action</th></tr></thead><tbody><?php if (!$history) empty_row(3,'No payments received yet.'); foreach ($history as $payment): $editing=(int)($_GET['edit_payment']??0)===(int)$payment['id']; ?><tr><?php if ($editing): $formId='edit-payment-'.$payment['id']; ?><td><input form="<?= $formId ?>" type="date" name="payment_date" aria-label="Date received" value="<?= h($payment['payment_date']) ?>" required></td><td><input form="<?= $formId ?>" type="number" name="amount" aria-label="Amount received" min="0.01" max="9999999999.99" step="0.01" value="<?= h($payment['amount']) ?>" required></td><td><form id="<?= $formId ?>" method="post" action="admin-add-client.php?id=<?= $id ?>&edit_payment=<?= (int)$payment['id'] ?>#saved-payments"><?= csrf_field() ?><input type="hidden" name="action" value="update_payment"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"></form><button form="<?= $formId ?>" class="primary" type="submit">Save</button> <a class="button secondary" href="admin-add-client.php?id=<?= $id ?>#saved-payments">Cancel</a></td><?php else: ?><td><?= h($payment['payment_date']) ?></td><td><?= h(money_label($payment['amount'])) ?></td><td><a class="button secondary" href="admin-add-client.php?id=<?= $id ?>&edit_payment=<?= (int)$payment['id'] ?>#saved-payments">Edit</a></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></details></section><?php endif; ?>
<script src="assets/js/records.js?v=<?= filemtime(__DIR__.'/../assets/js/records.js') ?>" defer></script><?php page_end(); ?>
