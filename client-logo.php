<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_roles();
$client=one('SELECT logo_path,is_active FROM clients WHERE id=? AND deleted_at IS NULL',[(int)($_GET['id']??0)]);
if (!$client || (!$client['is_active'] && !is_staff()) || !$client['logo_path']) fail(404,'Client logo not found.');

$folder=str_starts_with($client['logo_path'],'uploads/photos/')?'uploads/photos':(str_starts_with($client['logo_path'],'uploads/logos/')?'uploads/logos':null);
if ($folder===null) fail(404,'Client logo not found.');
$root=realpath(__DIR__.'/'.$folder);
$path=realpath(__DIR__.'/'.$client['logo_path']);
if (!$root || !$path || !str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR)) || !is_file($path)) fail(404,'Client logo not found.');
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) fail(404,'Client logo not available.');
header('Content-Type: '.$mime);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.filesize($path));
readfile($path);
