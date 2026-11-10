<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user=require_roles();
$notificationId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$notificationId) fail(404,'Notification not found or expired.');
$notificationId=(int)$notificationId;
$notification=one(
    'SELECT id,sender_id,notification_type,title,submission_id,leave_request_id,holiday_id,requirement_id FROM notifications WHERE id=? AND recipient_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',
    [$notificationId,$user['id']]
);
if (!$notification) fail(404,'Notification not found or expired.');
query('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=?',[$notificationId,$user['id']]);

if ($notification['submission_id']) {
    $destination=is_staff()
        ? 'manager-work-details.php?id='.(int)$notification['submission_id']
        : 'employee-work-history.php';
} elseif ($notification['leave_request_id']) {
    $destination=is_staff()?'manager-leave-requist.php':'my-leave-requests.php';
} elseif ($notification['holiday_id']) {
    $destination='admin-holidays.php';
} elseif ($notification['requirement_id']) {
    $destination='client-reuirement.php';
} elseif (
    $user['role']==='employee'
    && $notification['notification_type']==='general'
    && preg_match('/\b(?:\d+\s+)?tasks?\s+assigned\b|\bwork assignment updated\b/i',$notification['title'])
) {
    $destination='employee-assigned-work.php';
} elseif (
    is_staff()
    && $notification['notification_type']==='general'
    && str_contains(strtolower($notification['title']),'submitted an assigned task update')
) {
    $destination='manager-assigned-status.php?employee='.(int)$notification['sender_id'];
} else {
    $destination='manager-notification.php?open='.$notificationId.'#notification-'.$notificationId;
}
redirect($destination);
