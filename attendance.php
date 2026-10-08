<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_roles(['employee']);
check_csrf();
$action=$_POST['action']??'';
if (!in_array($action,['login','logout'],true)) fail(400,'Choose Login or Logout.');

try {
    db()->beginTransaction();
    if (!one('SELECT user_id FROM employee_profiles WHERE user_id=? FOR UPDATE',[$user['id']])) {
        throw new InvalidArgumentException('Employee profile not found.');
    }
    $active=one('SELECT id,login_at FROM employee_attendance_sessions WHERE employee_id=? AND logout_at IS NULL ORDER BY login_at DESC LIMIT 1 FOR UPDATE',[$user['id']]);
    if ($action==='login') {
        if ($active) throw new InvalidArgumentException('You are already logged in for attendance.');
        query('INSERT INTO employee_attendance_sessions (employee_id) VALUES (?)',[$user['id']]);
        query("INSERT INTO employee_attendance_days (employee_id,attendance_date,status) VALUES (?,CURDATE(),'pending') ON DUPLICATE KEY UPDATE status='pending'",[$user['id']]);
        $message='Login time saved.';
    } else {
        if (!$active) throw new InvalidArgumentException('Log in for attendance before logging out.');
        query('UPDATE employee_attendance_sessions SET logout_at=NOW() WHERE id=? AND logout_at IS NULL',[$active['id']]);
        $loginDay=substr((string)$active['login_at'],0,10);
        query("INSERT INTO employee_attendance_days (employee_id,attendance_date,status) VALUES (?,?,'present') ON DUPLICATE KEY UPDATE status='present'",[$user['id'],$loginDay]);
        $message='Logout time saved.';
    }
    db()->commit();
    flash($message);
    redirect(dashboard_path($user));
} catch (InvalidArgumentException $e) {
    if (db()->inTransaction()) db()->rollBack();
    fail(409,$e->getMessage());
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log($e->getMessage());
    fail(500,'Unable to save attendance time. Please try again.');
}
