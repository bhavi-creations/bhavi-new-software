<?php
// Connects the Managing Director's Zoom account (OAuth) so the portal can start instant meetings.
// Zoom sends the user back here with ?code=…; this must match the Redirect URL set in the Zoom app.
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/zoom.php';
$user = require_roles(['admin']);
if (!zoom_ready()) fail(503, 'Add the Zoom Client ID and Client Secret in config.zoom.php first.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'disconnect') {
        query("DELETE FROM portal_settings WHERE setting_key IN ('zoom_access_token','zoom_refresh_token','zoom_token_expires','zoom_account_email')");
        flash('Zoom account disconnected.');
    }
    redirect('zoom-meetings.php');
}

if (isset($_GET['error'])) {
    flash('Zoom connection was cancelled: ' . mb_substr((string) $_GET['error'], 0, 100), 'error');
    redirect('zoom-meetings.php');
}

if (isset($_GET['code'])) {
    $state = (string) ($_GET['state'] ?? '');
    $expected = (string) ($_SESSION['zoom_oauth_state'] ?? '');
    unset($_SESSION['zoom_oauth_state']);
    if ($expected === '' || !hash_equals($expected, $state)) fail(400, 'Zoom sign-in expired. Go back to Zoom meetings and click “Connect Zoom account” again.');
    try {
        zoom_store_tokens(zoom_oauth_request(['grant_type' => 'authorization_code', 'code' => (string) $_GET['code'], 'redirect_uri' => zoom_redirect_uri()]));
        try { zoom_setting_save('zoom_account_email', (string) (zoom_api('GET', '/users/me')['email'] ?? '')); } catch (Throwable $e) { /* email is display-only */ }
        flash('Zoom account connected. You can now start instant meetings.');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        flash($e->getMessage(), 'error');
    }
    redirect('zoom-meetings.php');
}

// Start: send the admin to Zoom to approve access.
$_SESSION['zoom_oauth_state'] = bin2hex(random_bytes(16));
redirect('https://zoom.us/oauth/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => zoom_config()['client_id'],
    'redirect_uri' => zoom_redirect_uri(),
    'state' => $_SESSION['zoom_oauth_state'],
]));
