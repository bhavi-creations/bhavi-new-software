<?php
require_once __DIR__.'/includes/bootstrap.php';
require_roles(['admin','manager']);
$document=one('SELECT d.* FROM employee_documents d JOIN users u ON u.id=d.employee_id WHERE d.id=? AND u.deleted_at IS NULL',[(int)($_GET['id']??0)]);
if (!$document) fail(404,'Document not found.');
$filename=preg_replace('/[^A-Za-z0-9._-]/','_',$document['original_name']);
header('Content-Type: '.($document['mime_type']==='image/svg+xml'?'application/octet-stream':$document['mime_type']));
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Disposition: attachment; filename="'.$filename.'"');
if ($document['file_path']) {
    $root=realpath(__DIR__.'/storage/employee-documents');
    $path=realpath(__DIR__.'/'.$document['file_path']);
    if (!$root || !$path || !str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR)) || !is_file($path)) fail(404,'Document file not available.');
    header('Content-Length: '.filesize($path)); readfile($path);
} else {
    header('Content-Length: '.strlen($document['file_content'])); echo $document['file_content'];
}
