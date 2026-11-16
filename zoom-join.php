<?php
// Joins a Zoom meeting inside the portal with the Zoom Meeting SDK (Client View, full page).
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/zoom.php';
$user = require_roles(['admin', 'manager', 'employee']);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$meeting = $id ? one('SELECT * FROM zoom_meetings WHERE id=? AND deleted_at IS NULL', [$id]) : null;
if (!$meeting) fail(404, 'Meeting not found.');
if (!zoom_ready()) fail(503, 'Zoom is not connected yet. Ask the Managing Director to add the Zoom details on the Zoom meetings page.');
$passcode = $meeting['passcode'] !== '' ? $meeting['passcode'] : (zoom_parse_link($meeting['join_url'])['pwd'] ?? '');
// Meetings the portal made on the connected Zoom account (instant, or "Create Zoom link automatically") are started
// by the person who made them, or by the Managing Director, as host (needs the account's ZAK token); everyone else joins as attendee.
$role = 0; $zak = ''; $hostProblem = '';
if (zoom_host_can_start($meeting, $user)) {
    try { $zak = zoom_zak(); $role = $zak !== '' ? 1 : 0; } catch (Throwable $e) { error_log($e->getMessage()); $hostProblem = $e->getMessage(); }
    if ($zak === '' && $hostProblem === '') $hostProblem = 'Zoom did not give the host key (ZAK).';
}
// The host could not get host rights: do not silently join as a participant (everyone would wait for a host).
// Show why, and offer to start it in the Zoom app as host, or join as a participant anyway.
if ($hostProblem !== '' && !isset($_GET['as_guest'])) {
    $startUrl = '';
    try { $startUrl = (string) (zoom_api('GET', '/meetings/' . rawurlencode($meeting['meeting_number']))['start_url'] ?? ''); } catch (Throwable $e) { error_log($e->getMessage()); }
    $appLink = $startUrl !== '' ? $startUrl : ($meeting['join_url'] ?: 'https://zoom.us/j/' . $meeting['meeting_number']);
    $guestLink = 'zoom-join.php?' . http_build_query(array_filter(['id' => (int) $meeting['id'], 'back' => (string) ($_GET['back'] ?? ''), 'as_guest' => 1]));
    ?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($meeting['title']) ?> | Zoom | Bhavi</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;box-sizing:border-box;background:#f2f5fb;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#172b49}
.zh{max-width:620px;width:100%;padding:26px;border:1px solid #dbe3f0;border-radius:16px;background:#fff;box-shadow:0 10px 30px #172b4914}
.zh h1{margin:0 0 6px;font-size:20px}.zh p{margin:8px 0;line-height:1.55;font-size:14px}
.zh .why{padding:10px 12px;border-radius:10px;background:#fff6f5;color:#b42318;font-size:13px}
.zh ol{margin:8px 0 0;padding-left:20px;font-size:13.5px;line-height:1.7}
.zh .acts{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.zh .acts a{padding:11px 18px;border-radius:10px;font-weight:800;font-size:14px;text-decoration:none}
.zh .go{background:#1f4f9c;color:#fff}.zh .alt{border:1px solid #c9d7f0;color:#1f4f9c}.zh .back{color:#5c6b82}
</style></head><body><div class="zh">
    <h1>“<?= h($meeting['title']) ?>” needs its host</h1>
    <p>You started this meeting, so you are its host. But Zoom did not give the portal permission to start it as host, so everyone would see “Waiting for the host to start the meeting”.</p>
    <p class="why">Zoom said: <?= h($hostProblem) ?></p>
    <?php if ($user['role'] === 'admin'): ?>
    <p><b>Fix once</b> (Managing Director):</p>
    <ol>
        <li>Open <a href="https://marketplace.zoom.us/user/build" target="_blank" rel="noopener noreferrer">Zoom Marketplace → your app</a> → <b>Scopes</b> and add <b>user:read:zak</b> (also <b>meeting:read:meeting</b>). Save.</li>
        <li>In the portal: <b>Zoom meetings</b> → <b>Disconnect Zoom account</b> → <b>Connect Zoom account</b> → Allow.</li>
        <li>Start the meeting again: you join as host straight away.</li>
    </ol>
    <?php endif; ?>
    <p><b>Right now:</b> start it in the Zoom app as host. The team can then join from the portal.</p>
    <div class="acts">
        <a class="go" href="<?= h($appLink) ?>" target="_blank" rel="noopener noreferrer">▶ Start in Zoom app (host)</a>
        <a class="alt" href="<?= h($guestLink) ?>">Join here as participant</a>
        <a class="back" href="<?= h(preg_match('/^chat\.php\?id=\d+$/D', (string) ($_GET['back'] ?? '')) ? $_GET['back'] : 'zoom-meetings.php') ?>">Go back</a>
    </div>
</div></body></html><?php
    exit;
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
