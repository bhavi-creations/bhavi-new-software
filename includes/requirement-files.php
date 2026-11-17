<?php
// Where a client's request file (requirement_files row) is on disk.
// New files are saved by type, next to the other uploads: images in uploads/photos, PDFs in uploads/pdf,
// everything else (videos, Word, ZIP …) in uploads/files. These folders are blocked from direct web access;
// files are only given out through download-requirement-file.php.
// Older files were saved in storage/requirements (or bhavi-private/requirements); every file is also looked for
// by its name in all these folders, and comes back from the database backup copy when it is lost.

const REQUIREMENT_UPLOAD_FOLDERS = ['uploads/photos', 'uploads/pdf', 'uploads/files'];

/** Base folder that holds uploads/… (the portal folder; BHAVI_REQUIREMENT_DIR only in tests). */
function requirement_upload_base(): string
{
    $env = getenv('BHAVI_REQUIREMENT_DIR');
    return rtrim(str_replace('\\', '/', is_string($env) && $env !== '' ? $env : dirname(__DIR__)), '/');
}

/** uploads/photos for images, uploads/pdf for PDFs, uploads/files for everything else. */
function requirement_upload_folder(string $mime, string $name): string
{
    $mime = strtolower($mime);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') return 'uploads/photos';
    if ($mime === 'application/pdf' || ($ext === 'pdf' && $mime === 'application/octet-stream')) return 'uploads/pdf';
    return 'uploads/files';
}

/**
 * [relative path saved in the database, absolute path] for a new client file, e.g.
 * ['uploads/photos/client-1a2b….png', '/home/…/public_html/uploads/photos/client-1a2b….png'].
 */
function requirement_upload_target(string $mime, string $name): array
{
    $folder = requirement_upload_folder($mime, $name);
    $dir = requirement_upload_base() . '/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new InvalidArgumentException('Unable to create the ' . $folder . ' folder for client files.');
    if (!is_writable($dir)) throw new InvalidArgumentException('The ' . $folder . ' folder is not writable by PHP. Give it write permission.');
    // Never served directly by the web server (photos/pdf already have this file).
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) $ext = $folder === 'uploads/pdf' ? 'pdf' : 'bin';
    if (in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'cgi', 'pl', 'htaccess', 'html', 'htm', 'svg'], true)) $ext = 'bin';
    $stored = 'client-' . bin2hex(random_bytes(16)) . '.' . $ext;
    return [$folder . '/' . $stored, $dir . '/' . $stored];
}

/** Folder shown in Hosting checks / the missing-file message (where new client files go). */
function requirement_storage_dir(): string
{
    return requirement_upload_base() . '/uploads';
}

/** Folders that can hold request files (only these are ever read). */
function requirement_file_roots(): array
{
    $roots = [];
    $dirs = array_map(static fn($f) => requirement_upload_base() . '/' . $f, REQUIREMENT_UPLOAD_FOLDERS);
    array_push($dirs, dirname(dirname(__DIR__)) . '/bhavi-private/requirements', dirname(__DIR__) . '/storage/requirements', __DIR__ . '/storage/requirements');
    foreach ($dirs as $dir) {
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
    $candidates = [requirement_upload_base() . '/' . $relative, dirname(__DIR__) . '/' . $relative, __DIR__ . '/' . $relative];
    $name = basename($relative);
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name)) foreach ($roots as $root) $candidates[] = $root . $name;
    foreach ($candidates as $candidate) {
        $path = realpath($candidate);
        if ($inRoot($path)) { requirement_file_keep_backup($file, $path); return $path; }
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

/** Writes the database backup copy back to its uploads folder and returns its path, or null when there is no copy. */
function requirement_file_restore(array $file): ?string
{
    $fileId = (int) ($file['id'] ?? 0);
    if ($fileId <= 0) return null;
    try {
        if (!one('SELECT 1 FROM requirement_file_chunks WHERE file_id=? LIMIT 1', [$fileId])) return null;
        $relative = ltrim(str_replace('\\', '/', (string) ($file['file_path'] ?? '')), '/');
        $folder = dirname($relative);
        if (!in_array($folder, REQUIREMENT_UPLOAD_FOLDERS, true)) $folder = requirement_upload_folder((string) ($file['mime_type'] ?? ''), (string) ($file['original_file_name'] ?? ''));
        $dir = requirement_upload_base() . '/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        $name = basename($relative);
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
 * $staffActions: MD / manager can upload a missing file again or ask the client to send it.
 */
function requirement_files_html(array $files, bool $withHeading = true, bool $staffActions = false): string
{
    if (!$files) return '';
    $out = ($withHeading ? '<h4>Attached files</h4>' : '') . '<ul class="requirement-file-list">';
    $found = 0;
    foreach ($files as $file) {
        $id = (int) $file['id'];
        $name = h($file['original_file_name']);
        $size = $withHeading && isset($file['file_size_bytes']) ? '<span>' . h(number_format((int) $file['file_size_bytes'] / 1024 / 1024, 1)) . ' MB</span>' : '';
        if (requirement_file_path($file) !== null) {
            $found++;
            $out .= '<li><a href="download-requirement-file.php?id=' . $id . '">' . $name . '</a>' . $size . '<a class="rq-file-btn" href="download-requirement-file.php?id=' . $id . '" title="Download ' . $name . '">⬇ Download</a></li>';
            continue;
        }
        // Lost from the server folder (and no backup copy): say so, and let the MD / a manager put it back.
        $out .= '<li class="is-missing"><s>' . $name . '</s><span>File missing on server</span>';
        if ($staffActions) {
            $out .= '<form method="post" enctype="multipart/form-data" class="inline-form rq-replace-form">' . csrf_field()
                . '<input type="hidden" name="action" value="replace_requirement_file"><input type="hidden" name="file_id" value="' . $id . '">'
                . '<label class="rq-file-btn">⬆ Upload this file<input type="file" name="replacement" required hidden onchange="this.form.submit()"></label></form>'
                . '<form method="post" class="inline-form">' . csrf_field()
                . '<input type="hidden" name="action" value="ask_resend_file"><input type="hidden" name="file_id" value="' . $id . '">'
                . '<button type="submit" class="button secondary">Ask client to send again</button></form>';
        }
        $out .= '</li>';
    }
    $out .= '</ul>';
    // Only files that are really on the server go in the ZIP, so the button shows only when 2 or more are there.
    if ($found > 1 && !empty($files[0]['requirement_id'])) {
        $out .= '<a class="rq-download-all" href="download-requirement-files.php?id=' . (int) $files[0]['requirement_id'] . '">⬇ Download all (' . $found . ' files)</a>';
    }
    return $out;
}

/**
 * Old files (saved before database backups were added) get a backup copy the first time they are found,
 * so a later server move or update upload cannot lose them.
 */
function requirement_file_keep_backup(array $file, string $path): void
{
    static $checked = [];
    $fileId = (int) ($file['id'] ?? 0);
    if ($fileId <= 0 || isset($checked[$fileId])) return;
    $checked[$fileId] = true;
    try {
        $size = @filesize($path);
        if ($size === false || $size <= 0 || $size > REQUIREMENT_BACKUP_MAX_BYTES) return;
        if (one('SELECT 1 FROM requirement_file_chunks WHERE file_id=? LIMIT 1', [$fileId])) return;
        if (requirement_file_backup($fileId, $path)) error_log('Request file #' . $fileId . ' backed up to the database.');
    } catch (Throwable $e) {
        error_log('Request file backup check failed for #' . $fileId . ': ' . $e->getMessage());
    }
}

/** "uploads/photos: 3 client files · uploads/pdf: 1 · uploads/files: 0" (Hosting checks, missing-file message). */
function requirement_upload_summary(): string
{
    $parts = [];
    foreach (REQUIREMENT_UPLOAD_FOLDERS as $folder) {
        $dir = requirement_upload_base() . '/' . $folder;
        $parts[] = $folder . ': ' . (is_dir($dir) ? count(glob($dir . '/client-*') ?: []) . ' client file(s)' . (is_writable($dir) ? '' : ' (NOT writable)') : 'folder missing');
    }
    return implode(' · ', $parts);
}
