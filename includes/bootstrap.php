<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bhavi_portal');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = require dirname(__DIR__) . '/config.php';
    try {
        $pdo = new PDO("mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+05:30'");
        return $pdo;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(503);
        exit('Database unavailable. Start MySQL in XAMPP, check config.local.php, and run php database/install.php.');
    }
}
function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function rows(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function count_value(string $sql, array $params = []): int { return (int) query($sql, $params)->fetchColumn(); }
function h($value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function today(): string { return date('Y-m-d'); }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function fail(int $status, string $message): never { http_response_code($status); exit(h($message)); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail(405, 'Use the form to submit this request.'); }
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0) {
        $limit=trim(ini_get('post_max_size')); $bytes=(float)$limit;
        $unit=strtolower(substr($limit,-1));
        $bytes*= match($unit) { 'g'=>1024**3, 'm'=>1024**2, 'k'=>1024, default=>1 };
        if ($bytes>0 && (int)$_SERVER['CONTENT_LENGTH']>$bytes) fail(413,'The upload exceeds the server limit of '.$limit.'. Upload fewer documents at a time.');
    }
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf'])) { fail(419, 'Your form expired. Refresh the page and try again.'); }
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'][] = ['message' => $message, 'type' => $type]; }
function current_user(): ?array
{
    static $loaded = false, $user = null;
    if (!$loaded) {
        $loaded = true;
        if (isset($_SESSION['user_id'])) {
            $user = one('SELECT u.*, ep.department_id, ep.designation, d.code AS department_code, d.name AS department_name FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.id=? AND u.account_status=\'active\' AND u.deleted_at IS NULL', [$_SESSION['user_id']]);
            if (!$user) { unset($_SESSION['user_id']); }
        }
    }
    return $user;
}
function require_roles(array $roles = ['admin','manager','employee']): array
{
    $user = current_user();
    if (!$user) { redirect('login.php'); }
    if (!in_array($user['role'], $roles, true)) { fail(403, 'You do not have permission to access this page.'); }
    return $user;
}
function is_staff(): bool { return in_array(current_user()['role'] ?? '', ['admin','manager'], true); }
function dashboard_path(array $user): string
{
    if ($user['role'] !== 'employee') { return $user['role'] . '-dashboard.php'; }
    return [
        'website' => 'website-employee-dashboard.php', 'seo' => 'seo-employee-dashboard.php',
        'design_video' => 'design-employee-dashboard.php', 'social_media' => 'socialmedia-employee-dashboard.php',
        'telecaller' => 'telecaller-employee-dashboard.php',
    ][$user['department_code'] ?? ''] ?? 'employee-dashboard.php';
}
function departments(): array { return rows('SELECT * FROM departments WHERE is_active=1 ORDER BY name'); }
function employees(): array
{
    return rows("SELECT u.id,u.full_name,u.email,u.username,u.account_status,u.avatar_path,u.created_at,ep.role_title,ep.designation,ep.joining_date,ep.department_id,d.name AS department_name,d.code AS department_code FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.deleted_at IS NULL ORDER BY u.full_name");
}
function clients(): array { return rows('SELECT * FROM clients WHERE deleted_at IS NULL AND is_active=1 ORDER BY client_name'); }
function text_input(string $name, int $max = 255, bool $required = true): string
{
    if (!is_string($_POST[$name] ?? null)) { throw new InvalidArgumentException('Please enter ' . str_replace('_', ' ', $name) . '.'); }
    $value = trim($_POST[$name]);
    if (($required && $value === '') || mb_strlen($value) > $max) { throw new InvalidArgumentException('Please enter a valid ' . str_replace('_', ' ', $name) . ' (up to ' . $max . ' characters).'); }
    return $value;
}
function password_input(string $name): string
{
    $value = $_POST[$name] ?? null;
    if (!is_string($value) || strlen($value) < 8 || strlen($value) > 72) {
        throw new InvalidArgumentException('Passwords must have 8–72 bytes.');
    }
    return $value;
}
function date_input(string $name, ?array $source = null): string
{
    $value = ($source ?? $_POST)[$name] ?? '';
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if (!$date || $date->format('Y-m-d') !== $value) { throw new InvalidArgumentException('Please choose a valid ' . str_replace('_', ' ', $name) . '.'); }
    return $value;
}
function positive_id($value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) { throw new InvalidArgumentException('Please select a valid record.'); }
    return $id;
}
function options(array $records, string $label, $selected = null, string $key = 'id'): string
{
    $html = '';
    foreach ($records as $record) { $html .= '<option value="' . h($record[$key]) . '"' . ((string) $record[$key] === (string) $selected ? ' selected' : '') . '>' . h($record[$label]) . '</option>'; }
    return $html;
}
function notify_staff(string $type, string $title, string $message, ?int $submission = null, ?int $leave = null): void
{
    foreach (rows("SELECT id FROM users WHERE role IN ('manager','admin') AND (? <> 'leave_applied' OR role='manager') AND account_status='active' AND deleted_at IS NULL",[$type]) as $recipient) {
        query('INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,submission_id,leave_request_id) VALUES (?,?,?,?,?,?,?)', [$recipient['id'], current_user()['id'], $type, $title, $message, $submission, $leave]);
    }
}
