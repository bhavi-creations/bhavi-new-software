<?php
// Where a client's request file (requirement_files row) is on disk.
// Files are saved as storage/requirements/<random>.dat. After a server move or an update upload the saved
// path can point to the wrong folder, so the file is also looked for by its name in the other known folders.

/** Folders that can hold request files (only these are ever read). */
function requirement_file_roots(): array
{
    $roots = [];
    foreach ([dirname(__DIR__) . '/storage/requirements', __DIR__ . '/storage/requirements'] as $dir) {
        $real = realpath($dir);
        if ($real && is_dir($real)) $roots[] = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }
    return array_values(array_unique($roots));
}

/** Absolute path of the file, or null when it is not on the server. */
function requirement_file_path(array $file): ?string
{
    $relative = ltrim(str_replace('\\', '/', (string) ($file['file_path'] ?? '')), '/');
    if ($relative === '') return null;
    $roots = requirement_file_roots();
    $inRoot = static function (string|false $path) use ($roots): bool {
        if (!$path || !is_file($path) || !is_readable($path)) return false;
        foreach ($roots as $root) if (strncasecmp($path, $root, strlen($root)) === 0) return true;
        return false;
    };
    $candidates = [dirname(__DIR__) . '/' . $relative, __DIR__ . '/' . $relative];
    $name = basename($relative);
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name)) foreach ($roots as $root) $candidates[] = $root . $name;
    foreach ($candidates as $candidate) {
        $path = realpath($candidate);
        if ($inRoot($path)) return $path;
    }
    return null;
}

/**
 * "Attached files" list for a client request: each file with its size and a Download button.
 * Files that are not on the server are summed up in one notice instead of one red row each.
 */
function requirement_files_html(array $files, string $heading = 'Attached files'): string
{
    if (!$files) return '';
    $ok = []; $missing = [];
    foreach ($files as $file) {
        if (requirement_file_path($file)) $ok[] = $file; else $missing[] = $file;
    }
    $icon = static function (string $name): string {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'avif'], true) ? '🖼️' : (in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'mkv', '3gp'], true) ? '🎬' : ($ext === 'pdf' ? '📄' : '📎'));
    };
    $out = '<div class="rq-files"><h4>' . h($heading) . ' <small>' . count($files) . '</small></h4>';
    if ($ok) {
        $out .= '<ul class="rq-file-list">';
        foreach ($ok as $file) {
            $size = (int) ($file['file_size_bytes'] ?? 0);
            $sizeText = $size >= 1048576 ? number_format($size / 1048576, 1) . ' MB' : max(1, (int) round($size / 1024)) . ' KB';
            $url = 'download-requirement-file.php?id=' . (int) $file['id'];
            $out .= '<li><span class="rq-file-icon">' . $icon((string) $file['original_file_name']) . '</span><a class="rq-file-name" href="' . h($url) . '" title="' . h($file['original_file_name']) . '">' . h($file['original_file_name']) . '</a>'
                . (isset($file['file_size_bytes']) ? '<span class="rq-file-size">' . h($sizeText) . '</span>' : '')
                . '<a class="rq-file-dl" href="' . h($url) . '">⬇ Download</a></li>';
        }
        $out .= '</ul>';
        if (count($ok) > 1 && !empty($ok[0]['requirement_id'])) {
            $out .= '<a class="rq-download-all" href="download-requirement-files.php?id=' . (int) $ok[0]['requirement_id'] . '">⬇ Download all (' . count($ok) . ' files)</a>';
        }
    }
    if ($missing) {
        $names = array_map(static fn($f) => (string) $f['original_file_name'], $missing);
        $out .= '<div class="rq-missing"><b>⚠ ' . count($missing) . ' file' . (count($missing) === 1 ? ' is' : 's are') . ' not on the server</b>'
            . '<span>' . h(implode(', ', $names)) . '</span>'
            . '<small>Ask the client to send ' . (count($missing) === 1 ? 'it' : 'them') . ' again.</small>';
        if (function_exists('is_staff') && is_staff()) {
            // Where the files were uploaded (saved with new uploads) compared with this site: shows if another copy of the portal took them.
            $here = 'Uploaded on ' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) . ' · ' . str_replace('\\', '/', dirname(__DIR__));
            $where = array_values(array_unique(array_filter(array_map(static fn($f) => (string) ($f['description'] ?? ''), $missing), static fn($d) => str_starts_with($d, 'Uploaded on '))));
            if ($where) {
                $other = array_values(array_filter($where, static fn($d) => $d !== $here));
                $out .= '<small class="rq-missing-why">' . ($other
                    ? 'These files were uploaded on another copy of the portal: <b>' . h(substr($other[0], 12)) . '</b>. This site is <b>' . h(substr($here, 12)) . '</b>. Ask the client to use this site’s link, or point both to the same folder.'
                    : 'They were uploaded on this same site and folder, and were later removed from the server (for example by an update that replaced the storage folder). Keep the storage folder when you update.') . '</small>';
            } else {
                $out .= '<small class="rq-missing-why">These were uploaded before upload tracking was added. Ask the client to send one file again: if it also goes missing, this notice will show the reason.</small>';
            }
            if ((current_user()['role'] ?? '') === 'admin') $out .= '<a class="rq-missing-check" href="hosting-check.php">Open Hosting checks →</a>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}
