<?php
// Live alerts for every page (polled by assets/js/portal.js): unread counts for the sidebar badges and the
// notifications newer than ?after= so the page can pop them up (new chat message, request assigned, …).
require_once __DIR__ . '/includes/bootstrap.php';
$user = current_user();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$user) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
$userId = (int) $user['id'];
$after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$unread = count_value('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND read_at IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)', [$userId]);
$chatUnread = 0;
try { $chatUnread = count_value('SELECT COUNT(*) FROM chat_messages m JOIN chat_participants p ON p.conversation_id=m.conversation_id AND p.user_id=? WHERE m.id>p.last_read_message_id AND m.sender_id<>? AND m.deleted_at IS NULL', [$userId, $userId]); } catch (Throwable $e) { }
// Chat notifications are updated in place (same row, newer time), so "new" means changed in the last 10 minutes too
// (background tabs are only polled about once a minute; ?after= skips the ones already shown).
$items = rows('SELECT id,title,message,chat_conversation_id,UNIX_TIMESTAMP(created_at) AS at FROM notifications WHERE recipient_id=? AND read_at IS NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE) ORDER BY created_at DESC,id DESC LIMIT 5', [$userId]);
$latest = (int) (one('SELECT UNIX_TIMESTAMP(MAX(created_at)) AS t FROM notifications WHERE recipient_id=?', [$userId])['t'] ?? 0);
$out = [];
foreach ($items as $n) {
    if ((int) $n['at'] <= $after) continue;
    $out[] = ['id' => (int) $n['id'], 'at' => (int) $n['at'], 'title' => (string) $n['title'], 'message' => mb_strimwidth((string) $n['message'], 0, 400, '…'), 'url' => 'notification-open.php?id=' . (int) $n['id'], 'chat' => (int) ($n['chat_conversation_id'] ?? 0)];
}
echo json_encode(['ok' => true, 'unread' => $unread, 'chat_unread' => $chatUnread, 'latest' => $latest, 'items' => $out], JSON_UNESCAPED_UNICODE);
