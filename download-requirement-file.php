<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_roles(['admin','manager','employee','client']);
$fileId=positive_id($_GET['id']??null);
$file=one('SELECT f.*,r.client_id,r.department_id FROM requirement_files f JOIN client_requirements r ON r.id=f.requirement_id JOIN clients c ON c.id=r.client_id WHERE f.id=? AND c.deleted_at IS NULL AND c.is_active=1',[$fileId]);
if (!$file) fail(404,'Request file not found.');
if ($user['role']==='client') {
    if (!one('SELECT id FROM clients WHERE id=? AND user_id=?',[$file['client_id'],$user['id']])) fail(403,'You cannot access this file.');
} elseif ($user['role']==='employee' && (int)$file['department_id']!==(int)$user['department_id'] && !one('SELECT requirement_id FROM requirement_assignments WHERE requirement_id=? AND employee_id=?',[$file['requirement_id'],$user['id']])) {
    fail(403,'This request belongs to another department.');
}
$relative=ltrim(str_replace('\\','/',(string)$file['file_path']),'/');
$root=realpath(__DIR__.'/storage/requirements');
$path=realpath(__DIR__.'/'.$relative);
if (!$root || !$path || !is_file($path) || !is_readable($path) || strncmp($path,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,strlen(rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))!==0) fail(404,'Request file is unavailable.');
$name=basename((string)$file['original_file_name']);
while (ob_get_level()>0) ob_end_clean();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Type: application/octet-stream');
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',$name).'"; filename*=UTF-8\'\''.rawurlencode($name));
$handle=fopen($path,'rb');
if (!$handle) fail(500,'Unable to open request file.');
while (!feof($handle)) { $chunk=fread($handle,1024*1024); if ($chunk===false || $chunk==='') break; echo $chunk; flush(); }
fclose($handle);
