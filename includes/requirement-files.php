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
 * "Attached files" list for a client request, in the original simple look (file links, sizes),
 * plus a "Download all" button when there are 2 or more files.
 * $withHeading: Client requests page (heading + sizes); false: employee dashboard (links only).
 */
function requirement_files_html(array $files, bool $withHeading = true): string
{
    if (!$files) return '';
    $out = ($withHeading ? '<h4>Attached files</h4>' : '') . '<ul class="requirement-file-list">';
    foreach ($files as $file) {
        $out .= '<li><a href="download-requirement-file.php?id=' . (int) $file['id'] . '">' . h($file['original_file_name']) . '</a>'
            . ($withHeading && isset($file['file_size_bytes']) ? '<span>' . h(number_format((int) $file['file_size_bytes'] / 1024 / 1024, 1)) . ' MB</span>' : '') . '</li>';
    }
    $out .= '</ul>';
    if (count($files) > 1 && !empty($files[0]['requirement_id'])) {
        $out .= '<a class="rq-download-all" href="download-requirement-files.php?id=' . (int) $files[0]['requirement_id'] . '">⬇ Download all (' . count($files) . ' files)</a>';
    }
    return $out;
}
