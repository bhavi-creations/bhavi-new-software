<?php
// Content schedule: edited reels / posters are sent to the client day by day at a set date & time; the employee gets a reminder.

const SCHEDULE_TYPES = ['reel' => 'Reel', 'poster' => 'Poster', 'carousel' => 'Carousel', 'story' => 'Story', 'video' => 'Video', 'blog' => 'Blog', 'other' => 'Other'];
const SCHEDULE_PLATFORMS = ['Instagram', 'Facebook', 'YouTube', 'LinkedIn', 'X (Twitter)', 'Google Business', 'Pinterest', 'Threads'];
const SCHEDULE_STATUSES = ['scheduled' => 'Scheduled', 'done' => 'Sent', 'cancelled' => 'Cancelled'];
/** Reminder goes this many minutes before the scheduled time. */
const SCHEDULE_REMIND_BEFORE_MIN = 10;

/**
 * Sends reminders for schedules that are due (no cron needed: runs on page loads, at most once a minute per session).
 * Each item is claimed with an UPDATE first, so two page loads never send the same reminder twice.
 */
function content_schedule_send_reminders(): void
{
    if (isset($_SESSION['schedule_check']) && $_SESSION['schedule_check'] > time() - 60) return;
    $_SESSION['schedule_check'] = time();
    try {
        $limit = date('Y-m-d H:i:s', time() + SCHEDULE_REMIND_BEFORE_MIN * 60);
        $due = rows("SELECT s.id,s.employee_id,s.content_type,s.title,s.platforms,s.scheduled_at,c.client_name FROM content_schedule s JOIN clients c ON c.id=s.client_id WHERE s.status='scheduled' AND s.reminded_at IS NULL AND s.deleted_at IS NULL AND s.scheduled_at<=? ORDER BY s.scheduled_at LIMIT 50", [$limit]);
        if (!$due) return;
        $admins = null;
        foreach ($due as $item) {
            if (query('UPDATE content_schedule SET reminded_at=NOW() WHERE id=? AND reminded_at IS NULL', [$item['id']])->rowCount() !== 1) continue;
            $title = mb_substr('Send to client now: '.(SCHEDULE_TYPES[$item['content_type']] ?? 'Post').' · '.$item['client_name'], 0, 200);
            $message = $item['title'].' — '.date('d M Y, h:i A', strtotime($item['scheduled_at'])).($item['platforms'] !== '' ? ' — Post on: '.$item['platforms'] : '');
            // Assigned employee gets it; with nobody assigned, the Managing Director(s) get it.
            $recipients = $item['employee_id'] ? [(int) $item['employee_id']] : ($admins ??= array_map('intval', array_column(rows("SELECT id FROM users WHERE role='admin' AND account_status='active' AND deleted_at IS NULL"), 'id')));
            foreach ($recipients as $to) {
                query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,NULL,'general',?,?)", [$to, $title, $message]);
            }
        }
    } catch (Throwable $e) {
        error_log('Content schedule reminders: '.$e->getMessage()); // never break the page for reminders
    }
}

/** Scheduled items that are due or overdue for an employee (sidebar badge). */
function content_schedule_due_count(int $employeeId): int
{
    try {
        return count_value("SELECT COUNT(*) FROM content_schedule WHERE employee_id=? AND status='scheduled' AND deleted_at IS NULL AND scheduled_at<=?", [$employeeId, date('Y-m-d H:i:s', time() + SCHEDULE_REMIND_BEFORE_MIN * 60)]);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Links typed one per line (or separated by spaces / commas) → clean list of http(s) URLs. */
function schedule_links_input(string $text, string $label): array
{
    $links = [];
    foreach (preg_split('/[\s,]+/', trim($text)) ?: [] as $url) {
        if ($url === '') continue;
        if (!preg_match('#^https?://#i', $url)) $url = 'https://'.$url;
        if (mb_strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Enter valid links for '.$label.' (one per line).');
        $links[] = $url;
    }
    if (count($links) > 20) throw new InvalidArgumentException('Add up to 20 links for '.$label.'.');
    return array_values(array_unique($links));
}
function schedule_links(?string $stored): array
{
    return array_values(array_filter(preg_split('/\s+/', trim((string) $stored)) ?: []));
}

const SCHEDULE_FILE_TYPES = ['mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'pdf', 'zip', 'rar', 'psd', 'ai', 'mp3', 'wav', 'doc', 'docx', 'ppt', 'pptx', 'txt'];

/**
 * Uploaded files for one item, from <input type="file" name="item_files[INDEX][]" multiple>.
 * Returns [['tmp'=>..,'name'=>..,'size'=>..,'mime'=>..,'ext'=>..], ...] after checking each one.
 */
function schedule_uploaded_files(int $index, string $label): array
{
    $f = $_FILES['item_files'] ?? null;
    if (!$f || !isset($f['name'][$index])) return [];
    $files = [];
    foreach ((array) $f['name'][$index] as $k => $name) {
        $error = (int) ($f['error'][$index][$k] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('"'.$name.'" ('.$label.') is too big. Maximum per file is '.ini_get('upload_max_filesize').'. For big videos add a Google Drive link instead.');
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('"'.$name.'" ('.$label.') could not be uploaded. Please try again.');
        $tmp = (string) $f['tmp_name'][$index][$k];
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!in_array($ext, SCHEDULE_FILE_TYPES, true)) throw new InvalidArgumentException('"'.$name.'" ('.$label.'): this file type is not allowed. Use video, image, PDF or ZIP files.');
        if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('Invalid upload for '.$label.'.');
        $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream');
        $files[] = ['tmp' => $tmp, 'name' => mb_substr(basename((string) $name), 0, 255), 'size' => (int) $f['size'][$index][$k], 'mime' => $mime, 'ext' => $ext];
    }
    if (count($files) > 10) throw new InvalidArgumentException('Add up to 10 files for '.$label.'.');
    return $files;
}

/** Moves checked uploads into includes/uploads/content-schedule/YYYY/MM and records them. Returns the saved paths (for cleanup on failure). */
function schedule_save_files(int $scheduleId, array $files, int $userId): array
{
    $saved = [];
    if (!$files) return $saved;
    $relDir = 'uploads/content-schedule/'.date('Y/m');
    $absDir = __DIR__.'/'.$relDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) throw new InvalidArgumentException('Hosting upload error: the files folder could not be created. Check includes/uploads permissions.');
    foreach ($files as $file) {
        $rel = $relDir.'/'.bin2hex(random_bytes(16)).'.'.$file['ext'];
        if (!@move_uploaded_file($file['tmp'], __DIR__.'/'.$rel)) throw new InvalidArgumentException('Hosting upload error: "'.$file['name'].'" could not be saved. Check disk space and includes/uploads permissions.');
        $saved[] = __DIR__.'/'.$rel;
        query('INSERT INTO content_schedule_files (schedule_id,original_name,file_path,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?)', [$scheduleId, $file['name'], $rel, $file['mime'], $file['size'], $userId]);
    }
    return $saved;
}

/** Files of several schedule items, grouped by schedule id. */
function schedule_files_for(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $out = [];
    foreach (rows('SELECT id,schedule_id,original_name,mime_type,file_size FROM content_schedule_files WHERE schedule_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY id', $ids) as $f) $out[(int) $f['schedule_id']][] = $f;
    return $out;
}

function schedule_file_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
}

/** Small list of an item's files (download) and links (open), shown in tables and on the employee dashboard. */
function schedule_media_list(array $files, array $links): void
{
    if (!$files && !$links) return;
    echo '<span class="sc-media">';
    foreach ($files as $f) {
        echo '<a class="sc-file" href="content-schedule-file.php?id='.(int) $f['id'].'" target="_blank" rel="noopener" title="'.h($f['original_name'].' · '.schedule_file_size((int) $f['file_size'])).'">📎 '.h(mb_strimwidth($f['original_name'], 0, 28, '…')).'</a>';
        echo '<a class="sc-dl" href="content-schedule-file.php?id='.(int) $f['id'].'&amp;mode=download" title="Download" aria-label="Download '.h($f['original_name']).'">⬇</a>';
    }
    foreach ($links as $i => $url) {
        echo '<a class="sc-link" href="'.h($url).'" target="_blank" rel="noopener noreferrer" title="'.h($url).'">🔗 Link'.(count($links) > 1 ? ' '.($i + 1) : '').'</a>';
    }
    echo '</span>';
}
