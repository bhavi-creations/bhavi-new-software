<?php
// The caller owns the transaction and removes returned files only after commit.
function delete_employee_records(int $id): array {
    $employee=one("SELECT id,avatar_path,username FROM users WHERE id=? AND role='employee' FOR UPDATE",[$id]);
    if (!$employee) throw new InvalidArgumentException('Employee not found.');
    $files=array_column(rows('SELECT file_path FROM employee_documents WHERE employee_id=?',[$id]),'file_path');
    $files[]=$employee['avatar_path'];
    query('DELETE n FROM notifications n LEFT JOIN daily_work_submissions s ON s.id=n.submission_id LEFT JOIN leave_requests l ON l.id=n.leave_request_id WHERE n.recipient_id=? OR n.sender_id=? OR s.employee_id=? OR l.employee_id=?',[$id,$id,$id,$id]);
    query('DELETE e FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id WHERE s.employee_id=?',[$id]);
    query('UPDATE daily_work_entries e JOIN work_assignments a ON a.id=e.assignment_id SET e.assignment_id=NULL WHERE a.employee_id=?',[$id]);
    foreach (['daily_work_submissions','work_assignments','leave_requests','client_employee_assignments','requirement_assignments','payslips','employee_documents','employee_salary_history','employee_attendance_sessions','employee_attendance_days'] as $table) {
        query("DELETE FROM $table WHERE employee_id=?",[$id]);
    }
    query('DELETE FROM password_reset_tokens WHERE user_id=?',[$id]);
    query('DELETE FROM employee_profiles WHERE user_id=?',[$id]);
    query("DELETE FROM users WHERE id=? AND role='employee'",[$id]);
    return array_values(array_filter(array_unique($files)));
}
function remove_deleted_employee_files(array $files): bool {
    $success=true;
    foreach ($files as $file) {
        // Only application-generated private upload paths may be removed.
        if (!preg_match('~^uploads/(photos|pdf)/[a-f0-9]{48}\.[a-z0-9]+$~D',$file)) continue;
        $path=dirname(__DIR__).'/'.$file;
        if (is_file($path) && !@unlink($path)) {
            error_log('Employee deletion: unable to remove private upload '.$file);
            $success=false;
        }
    }
    return $success;
}
function release_matching_deleted_employees(string $username,string $email,string $code): array {
    $matches=rows("SELECT u.id FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.role='employee' AND u.deleted_at IS NOT NULL AND (u.username=? OR (?<>'' AND u.email=?) OR (?<>'' AND ep.employee_code=?)) ORDER BY u.id FOR UPDATE",[$username,$email,$email,$code,$code]);
    $files=[];
    foreach ($matches as $match) $files=array_merge($files,delete_employee_records((int)$match['id']));
    return $files;
}
