<?php
// Where a client's request file (requirement_files row) is on disk.
// New files are saved OUTSIDE the website folder (next to public_html, in bhavi-private/requirements), because
// updating the site replaces the website folder and removed storage/requirements with every client file in it.
// Older files were saved in storage/requirements; every file is looked for by its name in all these folders.

/** Folder for new client request files: outside the website folder when the server allows it. */
function requirement_storage_dir(): string
{
    static $dir;
    if ($dir !== null) return $dir;
    $env = getenv('BHAVI_REQUIREMENT_DIR');
    $candidates = is_string($env) && $env !== '' ? [$env] : [];
    $candidates[] = dirname(dirname(__DIR__)) . '/bhavi-private/requirements';
    $candidates[] = dirname(__DIR__) . '/storage/requirements';
    foreach ($candidates as $candidate) {
        if (!is_dir($candidate)) @mkdir($candidate, 0700, true);
        if (is_dir($candidate) && is_writable($candidate)) {
            // Never served by the web server, even if this folder is inside a web root (e.g. XAMPP htdocs).
            $guard = dirname($candidate) . '/.htaccess';
            if (!is_file($guard)) @file_put_contents($guard, "Require all denied\n");
            return $dir = rtrim(str_replace('\\', '/', $candidate), '/');
        }
    }
    return $dir = dirname(__DIR__) . '/storage/requirements';
}

/** Folders that can hold request files (only these are ever read). */
function requirement_file_roots(): array
{
    $roots = [];
    $env = getenv('BHAVI_REQUIREMENT_DIR');
    foreach (array_filter([is_string($env) ? $env : '', dirname(dirname(__DIR__)) . '/bhavi-private/requirements', dirname(__DIR__) . '/storage/requirements', __DIR__ . '/storage/requirements']) as $dir) {
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
    // Not in the folder: put it back from the database backup copy, if there is one.
    return requirement_file_restore($file);
}

const REQUIREMENT_BACKUP_MAX_BYTES = 30 * 1024 * 1024; // files up to 30 MB get a database backup copy
const REQUIREMENT_BACKUP_CHUNK = 512 * 1024;           // stored in 512 KB pieces (fits small MySQL packet limits)

/** Saves a backup copy of a request file in the database (requirement_file_chunks). Larger files are skipped. */
function requirement_file_backup(int $fileId, string $path): bool
{
    $size = @filesize($path);
    if ($size === false || $size <= 0 || $size > REQUIREMENT_BACKUP_MAX_BYTES) return false;
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    try {
        query('DELETE FROM requirement_file_chunks WHERE file_id=?', [$fileId]);
        $insert = db()->prepare('INSERT INTO requirement_file_chunks (file_id,seq,data) VALUES (?,?,?)');
        for ($seq = 0; !feof($handle); $seq++) {
            $chunk = fread($handle, REQUIREMENT_BACKUP_CHUNK);
            if ($chunk === false || $chunk === '') break;
            $insert->bindValue(1, $fileId, PDO::PARAM_INT);
            $insert->bindValue(2, $seq, PDO::PARAM_INT);
            $insert->bindValue(3, $chunk, PDO::PARAM_LOB);
            $insert->execute();
        }
        return true;
    } catch (Throwable $e) {
        error_log('Request file backup failed for #' . $fileId . ': ' . $e->getMessage());
        try { query('DELETE FROM requirement_file_chunks WHERE file_id=?', [$fileId]); } catch (Throwable $ignored) {}
        return false;
    } finally {
        fclose($handle);
    }
}

/** Writes the database backup copy back to storage/requirements and returns its path, or null when there is no copy. */
function requirement_file_restore(array $file): ?string
{
    $fileId = (int) ($file['id'] ?? 0);
    if ($fileId <= 0) return null;
    try {
        if (!one('SELECT 1 FROM requirement_file_chunks WHERE file_id=? LIMIT 1', [$fileId])) return null;
        $dir = requirement_storage_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        $name = basename(str_replace('\\', '/', (string) ($file['file_path'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) $name = bin2hex(random_bytes(20)) . '.dat';
        $target = $dir . '/' . $name;
        $tmp = $target . '.part';
        $out = @fopen($tmp, 'wb');
        if (!$out) return null;
        $stmt = db()->prepare('SELECT data FROM requirement_file_chunks WHERE file_id=? ORDER BY seq');
        $stmt->execute([$fileId]);
        while (($data = $stmt->fetchColumn()) !== false) fwrite($out, is_resource($data) ? stream_get_contents($data) : (string) $data);
        fclose($out);
        $expected = (int) ($file['file_size_bytes'] ?? 0);
        if (($expected > 0 && filesize($tmp) !== $expected) || !@rename($tmp, $target)) { @unlink($tmp); return null; }
        error_log('Request file #' . $fileId . ' restored from the database backup.');
        return realpath($target) ?: null;
    } catch (Throwable $e) {
        error_log('Request file restore failed for #' . $fileId . ': ' . $e->getMessage());
        return null;
    }
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
    // Only files that are really on the server go in the ZIP, so the button shows only when 2 or more are there.
    $found = count(array_filter($files, static fn($f) => requirement_file_path($f) !== null));
    if ($found > 1 && !empty($files[0]['requirement_id'])) {
        $out .= '<a class="rq-download-all" href="download-requirement-files.php?id=' . (int) $files[0]['requirement_id'] . '">⬇ Download all (' . $found . ' files)</a>';
    }
    return $out;
}
