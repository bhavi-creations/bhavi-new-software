<?php
// "Download all": every file attached to one assigned task, as one ZIP (task-1-file-1.jpg, task-1-file-2.mp4 …).
// Staff can download any task; an employee only tasks assigned to them.
// Written without ZipArchive (not enabled on every host): files are "stored" uncompressed,
// which is fine because photos, videos and Office files are already compressed.
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['admin', 'manager', 'employee']);
$assignmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$assignmentId) fail(404, 'Task not found.');
$assignment = is_staff()
    ? one('SELECT id,title,work_date FROM work_assignments WHERE id=?', [$assignmentId])
    : one('SELECT id,title,work_date FROM work_assignments WHERE id=? AND employee_id=? AND deleted_at IS NULL', [$assignmentId, $user['id']]);
if (!$assignment) fail(404, 'Task not found.');

$base = realpath(__DIR__ . '/includes/uploads/work-assignments');
$files = [];
$used = [];
foreach (rows('SELECT * FROM work_assignment_attachments WHERE assignment_id=? ORDER BY id', [$assignmentId]) as $attachment) {
    $relative = ltrim(str_replace('\\', '/', (string) $attachment['file_path']), '/');
    $path = $relative !== '' ? realpath(__DIR__ . '/includes/' . $relative) : false;
    if (!$base || !$path || !str_starts_with(strtolower($path), strtolower($base . DIRECTORY_SEPARATOR)) || !is_file($path) || !is_readable($path)) continue;
    $name = str_replace(['/', '\\'], '_', trim((string) $attachment['original_name'])) ?: 'file-' . (int) $attachment['id'];
    $stem = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION);
    for ($n = 2; isset($used[strtolower($name)]); $n++) $name = $stem . '-' . $n . ($ext !== '' ? '.' . $ext : '');
    $used[strtolower($name)] = true;
    $files[] = ['path' => $path, 'name' => $name, 'size' => (int) filesize($path), 'time' => filemtime($path) ?: time()];
}
if (!$files) fail(404, 'This task has no files to download.');
if (array_sum(array_column($files, 'size')) > 3_900_000_000) fail(413, 'These files are too large for one ZIP. Save them one by one.');

$dosTime = static function (int $ts): array {
    $d = getdate($ts);
    $year = max(1980, $d['year']);
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($year - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
};
$zipName = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $assignment['title']), '-');
$zipName = 'task-files-' . ($zipName !== '' ? substr($zipName, 0, 60) . '-' : '') . date('Y-m-d', strtotime((string) $assignment['work_date']) ?: time()) . '.zip';

session_write_close();
while (ob_get_level() > 0) ob_end_clean();
@set_time_limit(0);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$offset = 0;
$central = '';
foreach ($files as $file) {
    $crc = hexdec(hash_file('crc32b', $file['path']));
    [$time, $date] = $dosTime($file['time']);
    $nameLen = strlen($file['name']);
    // Local file header (version 2.0, flag bit 11 = UTF-8 names, method 0 = stored)
    $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $file['size'], $file['size'], $nameLen, 0) . $file['name'];
    echo $header;
    $handle = fopen($file['path'], 'rb');
    while ($handle && !feof($handle)) { $chunk = fread($handle, 1048576); if ($chunk === false || $chunk === '') break; echo $chunk; }
    if ($handle) fclose($handle);
    flush();
    $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $file['size'], $file['size'], $nameLen, 0, 0, 0, 0, 0, $offset) . $file['name'];
    $offset += strlen($header) + $file['size'];
}
echo $central;
echo pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
