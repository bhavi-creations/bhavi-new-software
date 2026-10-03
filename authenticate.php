<?php
require_once __DIR__ . '/includes/bootstrap.php';
check_csrf();
$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$key = hash('sha256', strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (count_value('SELECT COUNT(*) FROM login_attempts WHERE attempt_key=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)', [$key]) >= 8) {
    $_SESSION['login_error'] = 'Too many sign-in attempts. Please try again in 15 minutes.';
    redirect('login.php');
}
$user = one("SELECT u.*, d.code AS department_code FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.username=? AND u.account_status='active' AND u.deleted_at IS NULL", [$username]);
$valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
if (!$user || !$valid || ($user['role'] === 'employee' && !$user['department_code'])) {
    query('INSERT INTO login_attempts (attempt_key) VALUES (?)', [$key]);
    $_SESSION['login_error'] = 'Incorrect username or password. Please try again.';
    redirect('login.php');
}
query('DELETE FROM login_attempts WHERE attempt_key=? OR attempted_at < DATE_SUB(NOW(),INTERVAL 1 DAY)', [$key]);
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password,PASSWORD_DEFAULT),$user['id']]);
}
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['csrf'] = bin2hex(random_bytes(32));
query('UPDATE users SET last_login_at=NOW() WHERE id=?', [$user['id']]);
redirect(dashboard_path($user));
