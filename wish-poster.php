<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['admin', 'manager', 'employee', 'client']);
$id = (int) ($_GET['id'] ?? 0);
$wish = one('SELECT poster_path FROM wishes WHERE id=?', [$id]);
if (!$wish || !$wish['poster_path']) fail(404, 'Poster not found.');
if (!is_staff() && !one('SELECT 1 FROM wish_recipients WHERE wish_id=? AND user_id=?', [$id, $user['id']])) fail(403, 'Access denied.');
$root = realpath(__DIR__ . '/uploads/wishes');
$path = realpath(__DIR__ . '/' . $wish['poster_path']);
if (!$root || !$path || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($path)) fail(404, 'Poster not found.');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) fail(404, 'Poster not available.');
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="poster.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
