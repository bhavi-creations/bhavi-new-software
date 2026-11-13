<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/wishes.php';
$user = require_roles(['admin', 'manager', 'employee', 'client']);
$id = (int) ($_GET['id'] ?? 0);
$wish = one('SELECT poster_path FROM wishes WHERE id=?', [$id]);
if (!$wish || !$wish['poster_path']) fail(404, 'Poster not found.');
if (!is_staff() && !one('SELECT 1 FROM wish_recipients WHERE wish_id=? AND user_id=?', [$id, $user['id']])) fail(403, 'Access denied.');
$path = wish_poster_file($wish['poster_path']);
if (!$path) fail(404, 'Poster not found.');
session_write_close();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) fail(404, 'Poster not available.');
header_remove('Cache-Control');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="poster.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
