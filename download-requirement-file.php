<?php
// Downloads a file a client sent with a request (Client requests page, employee dashboard).
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/requirement-files.php';
$user=require_roles(['admin','manager','employee','client']);
$fileId=positive_id($_GET['id']??null);
$file=one('SELECT f.*,r.client_id,r.department_id,r.shared_with_all FROM requirement_files f JOIN client_requirements r ON r.id=f.requirement_id JOIN clients c ON c.id=r.client_id WHERE f.id=? AND c.deleted_at IS NULL',[$fileId]);
if (!$file) fail(404,'Request file not found.');
if ($user['role']==='client') {
    if (!one('SELECT id FROM clients WHERE id=? AND user_id=?',[$file['client_id'],$user['id']])) fail(403,'You cannot access this file.');
} elseif ($user['role']==='employee' && !(int)$file['shared_with_all'] && (int)$file['department_id']!==(int)$user['department_id'] && !one('SELECT requirement_id FROM requirement_assignments WHERE requirement_id=? AND employee_id=?',[$file['requirement_id'],$user['id']])) {
    fail(403,'This request belongs to another department.');
}
$path=requirement_file_path($file);
if (!$path) {
    // The database has the file but the server folder does not (e.g. lost in a server move or update upload).
    error_log('Request file missing on disk: id '.(int)$file['id'].' path '.$file['file_path']);
    $message='“'.$file['original_file_name'].'” is not on the server any more, so it cannot be downloaded. '.($user['role']==='client'?'Please send this file again with a new request.':'On the Client requests page use “Upload this file” (if you have it) or “Ask client to send again”.');
    if ($user['role']==='admin') {
        // Reason for the Managing Director: where the file was saved, and what this server has.
        $where=requirement_upload_summary();
        $message.=' Details: this server has '.$where.'.'
            .((string)$file['description']!==''?' The file was '.lcfirst((string)$file['description']).'.':' (Uploaded before upload tracking was added.)');
    }
    flash($message,'error');
    $back=(string)parse_url((string)($_SERVER['HTTP_REFERER']??''),PHP_URL_PATH);
    $back=preg_match('~/([a-z0-9-]+\.php)$~',$back,$m)&&$m[1]!=='download-requirement-file.php'?$m[1]:'client-reuirement.php';
    redirect($back); // no #anchor: the message is at the top of the page, so it must be in view
}
$name=basename(str_replace('\\','/',(string)$file['original_file_name']));
session_write_close();
while (ob_get_level()>0) ob_end_clean();
@set_time_limit(0);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
// Same name and same file type as the client sent (e.g. “Diwali offer.png” opens as an image), always as a download.
$mime=strtolower(trim((string)$file['mime_type']));
header('Content-Type: '.(preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~',$mime)?$mime:'application/octet-stream'));
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="'.(trim((string)preg_replace('/[^\x20-\x7E]|["\\\\]/','_',$name))?:'client-file').'"; filename*=UTF-8\'\''.rawurlencode($name));
$handle=fopen($path,'rb');
if (!$handle) fail(500,'Unable to open request file.');
while (!feof($handle)) { $chunk=fread($handle,1024*1024); if ($chunk===false || $chunk==='') break; echo $chunk; flush(); }
fclose($handle);
