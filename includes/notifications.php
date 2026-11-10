<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(); $error=null;
$departments=departments();
$activeEmployees=array_values(array_filter(employees(),static fn(array $employee): bool => $employee['account_status']==='active'));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='send_notification') {
            require_roles(['admin','manager']);
            $departmentValue=$_POST['department_id']??'all';
            $employeeValue=$_POST['employee_id']??'all';
            if (!is_string($departmentValue) || !is_string($employeeValue)) throw new InvalidArgumentException('Choose a valid notification audience.');
            $department=$departmentValue==='all'?null:positive_id($departmentValue);
            $employee=$employeeValue==='all'?null:positive_id($employeeValue);
            $recipientSql="SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL";
            $recipientParams=[];
            if ($department!==null) { $recipientSql.=' AND ep.department_id=?'; $recipientParams[]=$department; }
            if ($employee!==null) { $recipientSql.=' AND u.id=?'; $recipientParams[]=$employee; }
            $recipients=rows($recipientSql.' ORDER BY u.id',$recipientParams);
            if (!$recipients) throw new InvalidArgumentException('No active employees match the selected department and employee.');
            $title=text_input('title',200); $message=text_input('message',5000);
            db()->beginTransaction();
            foreach ($recipients as $recipient) {
                query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",[$recipient['id'],$user['id'],$title,$message]);
            }
            db()->commit();
            flash('Notification sent to '.count($recipients).' employee'.(count($recipients)===1?'':'s').'.');
        } else {
            query('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE recipient_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',[$user['id']]);
            flash('Notifications marked as read.');
        }
        redirect('manager-notification.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$notifications=rows('SELECT n.*,sender.full_name AS sender_name FROM notifications n LEFT JOIN users sender ON sender.id=n.sender_id WHERE n.recipient_id=? AND n.created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) ORDER BY n.created_at DESC,n.id DESC',[$user['id']]);
$notificationDays=[];
foreach ($notifications as $notification) $notificationDays[substr($notification['created_at'],0,10)][]=$notification;
$openNotificationId=filter_var($_GET['open']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
page_start('Notifications','manager-notification.php'); error_message($error);
if (is_staff()): ?>
<section class="panel"><h2>Send employee notification</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="send_notification"><div class="fields"><div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" data-department-select="employee_id"><option value="all">All departments</option><?= options($departments,'name',$_POST['department_id']??'all') ?></select></div><div class="field"><label for="employee_id">Employee</label><select id="employee_id" name="employee_id"><option value="all">All employees</option><?php foreach ($activeEmployees as $employee): ?><option value="<?= $employee['id'] ?>" data-department="<?= $employee['department_id'] ?>" <?= (string)$employee['id']===(string)($_POST['employee_id']??'all')?'selected':'' ?>><?= h($employee['full_name']) ?></option><?php endforeach; ?></select></div><div class="field full"><label for="title">Title</label><input id="title" name="title" maxlength="200" value="<?= h($_POST['title']??'') ?>" required></div><div class="field full"><label for="message">Message</label><textarea id="message" name="message" maxlength="5000" required><?= h($_POST['message']??'') ?></textarea></div></div><p class="help">Choose a department, employee, or both. “All departments” and “All employees” sends to every active employee.</p><button class="primary">Send notification</button></form></section>
<?php endif;
?><div class="toolbar"><p class="muted">Notifications are available for 24 hours after they are sent.</p><form method="post"><?= csrf_field() ?><button type="submit" class="button secondary">Mark all as read</button></form></div><?php if (!$notifications): ?><section class="panel"><p class="muted">No recent notifications.</p></section><?php endif; foreach ($notificationDays as $day=>$dayNotifications): ?><details class="panel notification-day" <?= in_array($openNotificationId,array_map('intval',array_column($dayNotifications,'id')),true)?'open':'' ?>><summary><strong><?= h(date('l, d M Y',strtotime($day))) ?></strong> <span class="badge" data-notification-day-count><?= count($dayNotifications) ?> notifications</span></summary><div class="notification-day-messages"><?php foreach ($dayNotifications as $notification): ?><a class="notification-link" id="notification-<?= (int)$notification['id'] ?>" data-expires-at="<?= h(date(DATE_ATOM,strtotime($notification['created_at'])+86400)) ?>" href="notification-open.php?id=<?= (int)$notification['id'] ?>"><article class="panel"><div class="panel-heading"><h2><?= h($notification['title']) ?></h2><?= $notification['read_at']?'':status_badge('pending') ?></div><p><?= h($notification['message']) ?></p><small class="muted"><?= h($notification['sender_name']?:'Bhavi team') ?> · <?= h(date('d M Y, g:i A',strtotime($notification['created_at']))) ?></small></article></a><?php endforeach; ?></div></details><?php endforeach; page_end(); ?>
