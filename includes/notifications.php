<?php
require_once __DIR__ . '/layout.php';
$user=require_roles();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf(); query('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE recipient_id=?',[$user['id']]);
    flash('Notifications marked as read.'); redirect('manager-notification.php');
}
$notifications=rows('SELECT * FROM notifications WHERE recipient_id=? ORDER BY created_at DESC,id DESC LIMIT 200',[$user['id']]);
page_start('Notifications','manager-notification.php');
?><div class="toolbar"><p class="muted">Your latest work and leave updates.</p><form method="post"><?= csrf_field() ?><button type="submit" class="button secondary">Mark all as read</button></form></div><?php if (!$notifications): ?><section class="panel"><p class="muted">No notifications yet.</p></section><?php endif; foreach ($notifications as $notification): ?><article class="panel"><div class="panel-heading"><h2><?= h($notification['title']) ?></h2><?= $notification['read_at']?'':status_badge('pending') ?></div><p><?= h($notification['message']) ?></p><small class="muted"><?= h($notification['created_at']) ?></small></article><?php endforeach; page_end(); ?>
