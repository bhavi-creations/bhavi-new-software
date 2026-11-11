<?php
// Opens a notification: marks it read and goes to the page it is about (task, leave, report, request…).
require_once __DIR__ . '/includes/bootstrap.php';
$user=require_roles();
$notificationId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$notificationId) fail(404,'Notification not found or expired.');
$notificationId=(int)$notificationId;
$notification=one(
    'SELECT id,sender_id,notification_type,title,message,submission_id,leave_request_id,holiday_id,requirement_id FROM notifications WHERE id=? AND recipient_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',
    [$notificationId,$user['id']]
);
if (!$notification) fail(404,'Notification not found or expired.');
query('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=?',[$notificationId,$user['id']]);

$title=strtolower((string)$notification['title']);
$message=(string)$notification['message'];
$isTaskNotice=$notification['notification_type']==='general'
    && (preg_match('/\b(?:\d+\s+)?tasks?\s+assigned\b|\bwork assignment updated\b|\breminder: task pending\b/i',$title)===1);

/** The employee's task this notification is about (its title appears in the message), newest first. */
$findTask=static function (int $employeeId) use ($message): ?int {
    foreach (rows('SELECT id,title FROM work_assignments WHERE employee_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 300',[$employeeId]) as $task) {
        if ($task['title']!=='' && str_contains($message,$task['title'])) return (int)$task['id'];
    }
    return null;
};

if ($notification['submission_id']) {
    $destination=is_staff()
        ? 'manager-work-details.php?id='.(int)$notification['submission_id']
        : 'employee-work-history.php';
} elseif ($notification['leave_request_id']) {
    $destination=is_staff()?'manager-leave-requist.php':'my-leave-requests.php';
} elseif ($notification['holiday_id']) {
    $destination='admin-holidays.php';
} elseif ($notification['requirement_id']) {
    $destination=$user['role']==='client'?'client-reuirement.php':'client-reuirement.php#department-requests';
} elseif ($user['role']==='employee' && $isTaskNotice) {
    // Open the exact task on "My assigned work".
    $taskId=$findTask((int)$user['id']);
    $destination='employee-assigned-work.php'.($taskId?'?task='.$taskId:'');
} elseif (
    is_staff()
    && $notification['notification_type']==='general'
    && str_contains($title,'submitted an assigned task update')
) {
    $destination='manager-assigned-status.php?employee='.(int)$notification['sender_id'];
} else {
    // Announcements and other messages: show the full message on the Notifications page.
    $destination='manager-notification.php?open='.$notificationId.'#notification-'.$notificationId;
}
redirect($destination);
