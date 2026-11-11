<?php
// Called when the notification bell is opened: marks the user's recent notifications as read.
require_once __DIR__ . '/includes/bootstrap.php';
$user=require_roles(['admin','manager','employee','client']);
check_csrf();
query('UPDATE notifications SET read_at=NOW() WHERE recipient_id=? AND read_at IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',[$user['id']]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok'=>true]);
