<?php
// Wishes: a message / poster from the Managing Director that pops up in the bottom corner when the
// person opens the portal. It shows on their first 5 visits (log-ins); the ✕ closes it for that visit.
// tone 'wish' shows with sprinkles; tone 'danger' shows as a red warning.

const WISH_POSTER_MAX_BYTES = 10 * 1024 * 1024;
const WISH_POSTER_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const WISH_MAX_SHOWS = 5;

/** Validated poster upload as ['tmp'=>…, 'mime'=>…], or null when no file was chosen. */
function wish_poster_input(): ?array
{
    $file = $_FILES['poster'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (is_array($file['error'])) throw new InvalidArgumentException('Choose one poster image.');
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('Poster is too large. Use an image up to 10 MB.');
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Poster could not be uploaded. Please try again.');
    if ((int) $file['size'] > WISH_POSTER_MAX_BYTES) throw new InvalidArgumentException('Poster is too large. Use an image up to 10 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(WISH_POSTER_TYPES[$mime])) throw new InvalidArgumentException('Poster must be a JPG, PNG, WebP or GIF image.');
    return ['tmp' => $file['tmp_name'], 'mime' => $mime];
}

/** Folders a wish poster may be kept in (uploads/photos is the fallback when hosting blocks a new folder). */
const WISH_POSTER_FOLDERS = ['uploads/wishes', 'uploads/photos'];

function wish_store_poster(array $poster): string
{
    $app = dirname(__DIR__);
    foreach (WISH_POSTER_FOLDERS as $folder) {
        $root = $app . '/' . $folder;
        if (!is_dir($root)) @mkdir($root, 0755, true);
        if (!is_dir($root) || !is_writable($root)) continue; // hosting did not allow it: try the next folder
        $relative = $folder . '/wish-' . bin2hex(random_bytes(20)) . '.' . WISH_POSTER_TYPES[$poster['mime']];
        if (@move_uploaded_file($poster['tmp'], $app . '/' . $relative)) return $relative;
    }
    throw new InvalidArgumentException('Hosting upload error: the poster could not be saved. In the hosting File Manager, create the folder uploads/wishes (or make uploads/photos writable) with permission 755.');
}

/** Absolute path of a saved wish poster, or null when it is missing / outside the poster folders. */
function wish_poster_file(?string $relative): ?string
{
    if (!$relative) return null;
    $app = dirname(__DIR__);
    $path = realpath($app . '/' . $relative);
    if (!$path || !is_file($path)) return null;
    foreach (WISH_POSTER_FOLDERS as $folder) {
        $root = realpath($app . '/' . $folder);
        if ($root && str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR))) return $path;
    }
    return null;
}

/**
 * Wishes to show now. A wish counts one "show" the first time it appears in a login session;
 * after 5 sessions it stops. Closing it with ✕ hides it for the rest of that session.
 */
function wishes_to_show(int $userId): array
{
    try {
        $list = rows('SELECT w.id,w.tone,w.title,w.message,w.poster_path,w.created_at,r.show_count,u.full_name AS sender_name FROM wish_recipients r JOIN wishes w ON w.id=r.wish_id LEFT JOIN users u ON u.id=w.sender_id WHERE r.user_id=? AND r.show_count<=? AND w.created_at>=NOW()-INTERVAL 60 DAY ORDER BY w.created_at DESC,w.id DESC LIMIT 10', [$userId, WISH_MAX_SHOWS]);
    } catch (Throwable $e) {
        return []; // tables not created yet
    }
    $closed = array_map('intval', $_SESSION['wish_closed'] ?? []);
    $counted = array_map('intval', $_SESSION['wish_counted'] ?? []);
    $show = [];
    foreach ($list as $wish) {
        $id = (int) $wish['id'];
        if (in_array($id, $closed, true)) continue;
        if (!in_array($id, $counted, true)) {
            if ((int) $wish['show_count'] >= WISH_MAX_SHOWS) continue;
            query('UPDATE wish_recipients SET show_count=show_count+1,seen_at=COALESCE(seen_at,NOW()) WHERE wish_id=? AND user_id=?', [$id, $userId]);
            $_SESSION['wish_counted'][] = $id;
        }
        $show[] = $wish;
    }
    return $show;
}

/** Bottom-corner popup markup for waiting wishes; empty string when nothing is waiting. */
function wish_popup_html(array $user): string
{
    if (!in_array($user['role'], ['employee', 'client'], true)) return '';
    $wishes = wishes_to_show((int) $user['id']);
    if (!$wishes) return '';
    ob_start();
    ?><div class="wish-dock" data-wish-dock data-csrf="<?= h(csrf_token()) ?>" aria-live="polite"><canvas class="wish-sprinkles" data-wish-sprinkles aria-hidden="true"></canvas><?php foreach ($wishes as $i => $wish): $danger = $wish['tone'] === 'danger'; ?><article class="wish-card<?= $danger ? ' is-danger' : '' ?>" data-wish="<?= (int) $wish['id'] ?>" data-tone="<?= h($wish['tone']) ?>" role="dialog" aria-label="<?= h($wish['title'] ?: ($danger ? 'Important message' : 'Message for you')) ?>"<?= $i ? ' hidden' : '' ?>><button type="button" class="wish-close" data-wish-close aria-label="Close" title="Close">✕</button><p class="wish-from"><?= $danger ? '⚠ Important message' : '✨ A message for you' ?></p><?php if ($wish['title'] !== ''): ?><h2><?= h($wish['title']) ?></h2><?php endif; ?><?php if ($wish['poster_path']): ?><img class="wish-poster" src="wish-poster.php?id=<?= (int) $wish['id'] ?>" alt="<?= h($wish['title'] ?: 'Poster') ?>"><?php endif; ?><?php if (trim($wish['message']) !== ''): ?><p class="wish-text"><?= nl2br(h($wish['message'])) ?></p><?php endif; ?><p class="wish-meta">— <?= h($wish['sender_name'] ?: 'Managing Director') ?> · <?= h(date('d M, g:i A', strtotime($wish['created_at']))) ?><?= count($wishes) > 1 ? ' · ' . ($i + 1) . ' of ' . count($wishes) : '' ?></p></article><?php endforeach; ?></div><?php
    return (string) ob_get_clean();
}
