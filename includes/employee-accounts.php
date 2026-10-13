<?php
// Keep historical foreign-key references separate from newly created accounts.
// Call only inside the caller's transaction, after locking the deleted user.
function release_deleted_employee_identifiers(int $id): void {
    $reserved='!deleted.employee.'.$id.'.'.bin2hex(random_bytes(12));
    query("UPDATE users SET username=?,email=NULL,password_hash=?,credential_cipher=NULL,must_change_password=1,account_status='inactive' WHERE id=? AND role='employee' AND deleted_at IS NOT NULL",[$reserved,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$id]);
    query("UPDATE employee_profiles ep JOIN users u ON u.id=ep.user_id SET ep.employee_code=NULL WHERE ep.user_id=? AND u.role='employee' AND u.deleted_at IS NOT NULL",[$id]);
}
function release_matching_deleted_employees(string $username,string $email,string $code): void {
    $matches=rows("SELECT u.id FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.role='employee' AND u.deleted_at IS NOT NULL AND (u.username=? OR (?<>'' AND u.email=?) OR (?<>'' AND ep.employee_code=?)) ORDER BY u.id FOR UPDATE",[$username,$email,$email,$code,$code]);
    foreach ($matches as $match) release_deleted_employee_identifiers((int)$match['id']);
}
