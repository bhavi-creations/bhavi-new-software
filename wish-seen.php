<?php
// ✕ on a wish popup: hide it for the rest of this login session (it comes back on the next log-in, up to 5 times).
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['employee', 'client']);
check_csrf();
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) fail(400, 'Invalid message.');
if (one('SELECT 1 FROM wish_recipients WHERE wish_id=? AND user_id=?', [$id, $user['id']])) {
    $_SESSION['wish_closed'] = array_values(array_unique(array_merge(array_map('intval', $_SESSION['wish_closed'] ?? []), [(int) $id])));
}
header('Content-Type: application/json');
echo '{"ok":true}';
