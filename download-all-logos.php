<?php
// Streams every active client logo as one ZIP file.
// Written without ZipArchive (not enabled on every host): files are "stored" uncompressed,
// which is fine because PNG/JPG/WebP logos are already compressed.
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin','manager','employee']);

$files = [];
$used = [];
foreach (clients() as $client) {
    $logo = (string) ($client['logo_path'] ?? '');
    if ($logo === '' || !preg_match('~^uploads/(photos|logos)/~', $logo)) continue;
    $root = realpath(__DIR__ . '/' . dirname($logo));
    $path = realpath(__DIR__ . '/' . $logo);
    if (!$root || !$path || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($path) || !is_readable($path)) continue;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null) continue;
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $client['client_name']), '-') ?: 'client-' . (int) $client['id'];
    $name = $base . '.' . $ext;
    for ($n = 2; isset($used[strtolower($name)]); $n++) $name = $base . '-' . $n . '.' . $ext;
    $used[strtolower($name)] = true;
    $files[] = ['path' => $path, 'name' => $name, 'size' => filesize($path), 'time' => filemtime($path) ?: time()];
}
if (!$files) fail(404, 'No client logos are available to download yet.');
if (array_sum(array_column($files, 'size')) > 3_900_000_000) fail(413, 'The logo collection is too large for one ZIP file.');

$dosTime = static function (int $ts): array {
    $d = getdate($ts);
    $year = max(1980, $d['year']);
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($year - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
};

while (ob_get_level() > 0) ob_end_clean();
@set_time_limit(0);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="client-logos-' . date('Y-m-d') . '.zip"');
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
