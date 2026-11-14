<?php
// Content schedule file: view or download. Managing Director sees every file; an employee only files of items assigned to them.
// Videos stream with HTTP Range so they can start playing at once and be seeked.
require_once __DIR__.'/includes/bootstrap.php';
$user = require_roles(['admin', 'employee']);
$fileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$fileId) fail(404, 'File not found.');
$file = $user['role'] === 'admin'
    ? one('SELECT f.* FROM content_schedule_files f JOIN content_schedule s ON s.id=f.schedule_id WHERE f.id=? AND s.deleted_at IS NULL', [$fileId])
    : one('SELECT f.* FROM content_schedule_files f JOIN content_schedule s ON s.id=f.schedule_id WHERE f.id=? AND s.employee_id=? AND s.deleted_at IS NULL', [$fileId, $user['id']]);
if (!$file) fail(404, 'File not found.');
session_write_close(); // a playing video must not block the user's other pages

$base = realpath(__DIR__.'/includes/uploads/content-schedule');
$path = realpath(__DIR__.'/includes/'.ltrim(str_replace('\\', '/', (string) $file['file_path']), '/'));
if (!$base || !$path || !str_starts_with(strtolower($path), strtolower($base.DIRECTORY_SEPARATOR)) || !is_file($path)) fail(404, 'File is missing from storage.');

$mime = (string) $file['mime_type'] ?: 'application/octet-stream';
$lower = strtolower($mime);
$inline = ($_GET['mode'] ?? 'view') !== 'download' && (str_starts_with($lower, 'image/') || str_starts_with($lower, 'video/') || str_starts_with($lower, 'audio/') || $lower === 'application/pdf') && $lower !== 'image/svg+xml';
$name = (string) $file['original_name'] ?: 'file';
$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';
$size = (int) filesize($path);
$start = 0; $end = max(0, $size - 1);

while (ob_get_level() > 0) ob_end_clean();
@set_time_limit(0);
header_remove('Cache-Control');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Content-Type: '.$mime);
header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($name));
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) $_SERVER['HTTP_RANGE']), $m) && $size > 0) {
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int) $m[2]); }
    else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
    http_response_code(206);
    header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
}
header('Content-Length: '.($end - $start + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
$fp = fopen($path, 'rb');
if (!$fp) exit;
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp) && !connection_aborted()) {
    $chunk = fread($fp, (int) min(1048576, $left));
    if ($chunk === false) break;
    echo $chunk; flush();
    $left -= strlen($chunk);
}
fclose($fp);
