<?php
// Joins a Zoom meeting inside the portal with the Zoom Meeting SDK (Client View, full page).
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/zoom.php';
$user = require_roles(['admin', 'manager', 'employee']);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$meeting = $id ? one('SELECT * FROM zoom_meetings WHERE id=? AND deleted_at IS NULL', [$id]) : null;
if (!$meeting) fail(404, 'Meeting not found.');
if (!zoom_ready()) fail(503, 'Zoom is not connected yet. Ask the Managing Director to add the Zoom Client ID and Client Secret.');
$passcode = $meeting['passcode'] !== '' ? $meeting['passcode'] : (zoom_parse_link($meeting['join_url'])['pwd'] ?? '');
// The person who started an instant meeting joins as host (needs the account's ZAK token); everyone else as attendee.
$role = 0; $zak = '';
if ((int) ($meeting['is_instant'] ?? 0) === 1 && (int) $meeting['created_by'] === (int) $user['id'] && zoom_connected()) {
    try { $zak = zoom_zak(); $role = $zak !== '' ? 1 : 0; } catch (Throwable $e) { error_log($e->getMessage()); }
}
// After leaving, go back to the chat the meeting was started from (only local chat/meeting pages are allowed).
$back = (string) ($_GET['back'] ?? '');
$leavePath = preg_match('/^chat\.php\?id=\d+$/D', $back) ? $back : 'zoom-meetings.php';
$base = zoom_base_url();
$join = [
    'signature' => zoom_signature($meeting['meeting_number'], $role),
    'zak' => $zak,
    'sdkKey' => zoom_config()['client_id'],
    'meetingNumber' => $meeting['meeting_number'],
    'passWord' => $passcode,
    'userName' => (string) $user['full_name'],
    'userEmail' => (string) ($user['email'] ?? ''),
    'leaveUrl' => $base . '/' . $leavePath,
];
$cdn = 'https://source.zoom.us/' . ZOOM_SDK_VERSION;
$appLink = $meeting['join_url'] ?: 'https://zoom.us/j/' . $meeting['meeting_number'];
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($meeting['title']) ?> | Zoom | Bhavi</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<style>
html,body{margin:0;height:100%;background:#1f1f1f;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.zj-status{position:fixed;inset:0;display:grid;place-content:center;gap:14px;padding:16px;color:#fff;text-align:center}
.zj-status p{margin:0;font-size:15px}
.zj-status a{color:#e2bf62;font-weight:700}
.zj-status[hidden]{display:none}
</style>
</head><body>
<div class="zj-status" id="zj-status"><p id="zj-text">Joining “<?= h($meeting['title']) ?>”…</p><p><a href="<?= h($appLink) ?>" target="_blank" rel="noopener noreferrer">Open in Zoom app instead</a> · <a href="<?= h($leavePath) ?>">Go back</a></p></div>
<script src="<?= h($cdn) ?>/lib/vendor/react.min.js"></script>
<script src="<?= h($cdn) ?>/lib/vendor/react-dom.min.js"></script>
<script src="<?= h($cdn) ?>/lib/vendor/redux.min.js"></script>
<script src="<?= h($cdn) ?>/lib/vendor/redux-thunk.min.js"></script>
<script src="<?= h($cdn) ?>/lib/vendor/lodash.min.js"></script>
<script src="https://source.zoom.us/zoom-meeting-<?= h(ZOOM_SDK_VERSION) ?>.min.js"></script>
<script>
(() => {
    const cfg = <?= json_encode($join, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const status = document.getElementById('zj-status'), text = document.getElementById('zj-text');
    const fail = (msg) => { status.hidden = false; text.textContent = 'Could not join in the portal: ' + msg; };
    if (!window.ZoomMtg) { fail('Zoom could not load. Check the internet connection.'); return; }
    ZoomMtg.preLoadWasm();
    ZoomMtg.prepareWebSDK();
    ZoomMtg.init({
        leaveUrl: cfg.leaveUrl,
        patchJsMedia: true,
        leaveOnPageUnload: true,
        success: () => {
            status.hidden = true; // Zoom draws its own joining screen
            ZoomMtg.join({
                signature: cfg.signature,
                sdkKey: cfg.sdkKey,
                meetingNumber: cfg.meetingNumber,
                passWord: cfg.passWord,
                userName: cfg.userName,
                userEmail: cfg.userEmail,
                ...(cfg.zak ? { zak: cfg.zak } : {}),
                success: () => { status.hidden = true; },
                error: (e) => fail((e && (e.errorMessage || e.reason)) || 'Zoom refused the join request.'),
            });
        },
        error: (e) => fail((e && (e.errorMessage || e.reason)) || 'Zoom could not start.'),
    });
})();
</script>
</body></html>
