<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_roles();
$asset=one('SELECT a.* FROM brand_assets a JOIN clients c ON c.id=a.client_id WHERE a.id=? AND c.deleted_at IS NULL AND c.is_active=1',[(int)($_GET['id']??0)]);
if (!$asset) fail(404,'File not found.');
$root=realpath(__DIR__.'/uploads'); $path=realpath(__DIR__.'/'.$asset['file_path']);
if (!$root || !$path || !str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR)) || !is_file($path)) fail(404,'File not available.');
$filename=preg_replace('/[^A-Za-z0-9._-]/','_',basename($asset['original_file_name']));
header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: '.filesize($path)); readfile($path);
