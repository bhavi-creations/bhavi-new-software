<?php
require_once __DIR__.'/includes/bootstrap.php';
require_roles(['admin','manager']);
$document=one('SELECT d.* FROM employee_documents d JOIN users u ON u.id=d.employee_id WHERE d.id=? AND u.deleted_at IS NULL',[(int)($_GET['id']??0)]);
if (!$document) fail(404,'Document not found.');
$filename=preg_replace('/[^A-Za-z0-9._-]/','_',$document['original_name']);
header('Content-Type: '.$document['mime_type']);
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.strlen($document['file_content']));
echo $document['file_content'];
