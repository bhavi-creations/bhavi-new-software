<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_roles();
if (!is_staff() && (int)($_GET['id']??0)!==(int)$user['id']) fail(403,'You can only view your own photo.');
$employee=one("SELECT avatar_path FROM users WHERE id=? AND role='employee' AND deleted_at IS NULL",[(int)($_GET['id']??0)]);
if (!$employee || !$employee['avatar_path']) fail(404,'Employee photo not found.');
$root=realpath(__DIR__.'/uploads/photos');
$path=realpath(__DIR__.'/'.$employee['avatar_path']);
if (!$root || !$path || !str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR)) || !is_file($path)) fail(404,'Employee photo not found.');
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)) fail(404,'Employee photo not available.');
header('Content-Type: '.$mime);
header('Content-Disposition: inline');
header('Content-Length: '.filesize($path));
readfile($path);
