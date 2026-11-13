<?php
// Task (assigned work) attachment: view or download.
// Staff can open any task file; an employee only files of tasks assigned to them.
// Videos stream with HTTP Range so they start playing at once and can be seeked.
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['admin', 'manager', 'employee']);
$fileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$fileId) fail(404, 'Attachment not found.');
$attachment = is_staff()
    ? one('SELECT f.* FROM work_assignment_attachments f JOIN work_assignments a ON a.id=f.assignment_id WHERE f.id=?', [$fileId])
    : one('SELECT f.* FROM work_assignment_attachments f JOIN work_assignments a ON a.id=f.assignment_id WHERE f.id=? AND a.employee_id=? AND a.deleted_at IS NULL', [$fileId, $user['id']]);
if (!$attachment) fail(404, 'Attachment not found.');
// Release the session lock: a playing video must not block the user's other pages or the next video range request.
session_write_close();
assignment_file_stream($attachment, ($_GET['mode'] ?? 'view') === 'download');

/** Streams a work_assignment_attachments row (files live in includes/uploads/work-assignments). */
function assignment_file_stream(array $attachment, bool $download): void
{
    $relative = ltrim(str_replace('\\', '/', (string) ($attachment['file_path'] ?? '')), '/');
    $base = realpath(__DIR__ . '/includes/uploads/work-assignments');
    $path = $relative !== '' ? realpath(__DIR__ . '/includes/' . $relative) : false;
    if (!$base || !$path || !str_starts_with(strtolower($path), strtolower($base . DIRECTORY_SEPARATOR)) || !is_file($path) || !is_readable($path)) fail(404, 'Attachment file is missing from storage.');
    $mime = trim((string) ($attachment['mime_type'] ?? ''));
    if ($mime === '' || $mime === 'application/octet-stream') $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream');
    $lower = strtolower($mime);
    $inline = !$download && (str_starts_with($lower, 'image/') || str_starts_with($lower, 'video/') || str_starts_with($lower, 'audio/') || $lower === 'application/pdf');
    if ($lower === 'image/svg+xml') $inline = false;
    $name = trim((string) ($attachment['original_name'] ?? '')) ?: 'attachment';
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
    $size = (int) filesize($path);
    $start = 0; $end = max(0, $size - 1);
    while (ob_get_level() > 0) ob_end_clean();
    @set_time_limit(0);
    header_remove('Cache-Control');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }       // last N bytes
        else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    $handle = fopen($path, 'rb');
    if (!$handle) fail(500, 'Unable to open attachment.');
    if ($start > 0) fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, min(512 * 1024, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}
