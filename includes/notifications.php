<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(); $error=null;
function notification_target(array $notification,array $user): string
{
    if (!empty($notification['assignment_id']) && $notification['assignment_date']) return 'employee-assigned-work.php?assignment_date='.rawurlencode($notification['assignment_date']);
    if (!empty($notification['submission_id']) && $notification['submission_date']) {
        if (in_array($user['role'],['admin','manager'],true)) return 'manager-dailywork.php?employee='.(int)$notification['submission_employee_id'].'&from_date='.rawurlencode($notification['submission_date']).'&to_date='.rawurlencode($notification['submission_date']);
        return 'employee-work-history.php?date='.rawurlencode($notification['submission_date']);
    }
    if (!empty($notification['leave_request_id'])) return $user['role']==='employee'?'my-leave-requests.php':'manager-leave-requist.php';
    if (!empty($notification['holiday_id']) && $notification['holiday_date']) return 'admin-holidays.php?month='.rawurlencode(substr($notification['holiday_date'],0,7));
    if (!empty($notification['requirement_id'])) return 'client-reuirement.php';
    if ($notification['notification_type']==='leave_applied') return 'manager-leave-requist.php?status=pending';
    if ($notification['notification_type']==='holiday') return 'admin-holidays.php';
    if ($notification['notification_type']==='requirement') return 'client-reuirement.php';
    return dashboard_path($user);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='send_notification') {
            require_roles(['admin','manager']);
            $employee=positive_id($_POST['employee_id']??null); $department=positive_id($_POST['department_id']??null);
            if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND ep.department_id=? AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL",[$employee,$department])) throw new InvalidArgumentException('Select an active employee from this department.');
            $title=text_input('title',200); $message=text_input('message',5000);
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",[$employee,$user['id'],$title,$message]);
            flash('Notification sent to the employee.');
        } else {
            query('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE recipient_id=?',[$user['id']]);
            flash('Notifications marked as read.');
        }
        redirect('manager-notification.php');
    } catch (Throwable $e) { $error=mutation_error($e); }
}
$notifications=rows('SELECT n.*,a.work_date AS assignment_date,s.work_date AS submission_date,s.employee_id AS submission_employee_id,h.holiday_date FROM notifications n LEFT JOIN work_assignments a ON a.id=n.assignment_id LEFT JOIN daily_work_submissions s ON s.id=n.submission_id LEFT JOIN holidays h ON h.id=n.holiday_id WHERE n.recipient_id=? ORDER BY n.created_at DESC,n.id DESC LIMIT 200',[$user['id']]);
page_start('Notifications','manager-notification.php'); error_message($error);
if (is_staff()): ?>
<section class="panel"><h2>Send employee notification</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="send_notification"><div class="fields"><div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" data-department-select="employee_id" required><option value="">Select department</option><?= options(departments(),'name',$_POST['department_id']??'') ?></select></div><div class="field"><label for="employee_id">Employee</label><select id="employee_id" name="employee_id" required><option value="">Select employee</option><?php foreach (employees() as $employee): if ($employee['account_status']!=='active') continue; ?><option value="<?= $employee['id'] ?>" data-department="<?= $employee['department_id'] ?>" <?= (string)$employee['id']===(string)($_POST['employee_id']??'')?'selected':'' ?>><?= h($employee['full_name']) ?></option><?php endforeach; ?></select></div><div class="field full"><label for="title">Title</label><input id="title" name="title" maxlength="200" value="<?= h($_POST['title']??'') ?>" required></div><div class="field full"><label for="message">Message</label><textarea id="message" name="message" maxlength="5000" required><?= h($_POST['message']??'') ?></textarea></div></div><button class="primary">Send notification</button></form></section>
<?php endif;
?><div class="toolbar"><p class="muted">Your latest work and leave updates.</p><form method="post"><?= csrf_field() ?><button type="submit" class="button secondary">Mark all as read</button></form></div><?php if (!$notifications): ?><section class="panel"><p class="muted">No notifications yet.</p></section><?php endif; foreach ($notifications as $notification): ?><article class="panel"><a class="notification-link" href="<?= h(notification_target($notification,$user)) ?>"><div class="panel-heading"><h2><?= h($notification['title']) ?></h2><?= $notification['read_at']?'':status_badge('pending') ?></div><p><?= h($notification['message']) ?></p><small class="muted"><?= h($notification['created_at']) ?></small></a></article><?php endforeach; page_end(); ?>
