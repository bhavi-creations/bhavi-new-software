<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_roles(['admin','manager','employee']);
$attachmentId=positive_id($_GET['id']??null);
$attachment=one('SELECT a.*,m.conversation_id FROM chat_attachments a JOIN chat_messages m ON m.id=a.message_id JOIN chat_participants p ON p.conversation_id=m.conversation_id AND p.user_id=? WHERE a.id=?',[$user['id'],$attachmentId]);
if (!$attachment) fail(404,'Chat attachment not found.');
$root=realpath(__DIR__.'/storage/chat');
$path=realpath(__DIR__.'/'.$attachment['storage_path']);
$prefix=$root?rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR:'';
if (!$root || !$path || !is_file($path) || !is_readable($path) || strncmp($path,$prefix,strlen($prefix))!==0) fail(404,'Chat attachment is unavailable.');
$filename=basename((string)$attachment['original_file_name']);
while (ob_get_level()>0) ob_end_clean();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Type: application/octet-stream');
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',$filename).'"; filename*=UTF-8\'\''.rawurlencode($filename));
$handle=fopen($path,'rb');
if (!$handle) fail(500,'Unable to open the chat attachment.');
while (!feof($handle)) { $chunk=fread($handle,1024*1024); if ($chunk===false || $chunk==='') break; echo $chunk; flush(); }
fclose($handle);
