<?php
// Zoom Meeting SDK helpers: credentials, join signature and Zoom link parsing.

const ZOOM_SDK_VERSION = '6.10.0';

/**
 * ['client_id'=>..., 'client_secret'=>...]; empty strings when not set.
 * Order: environment → config.zoom.php → saved in the portal by the Managing Director (portal_settings, secret encrypted).
 */
function zoom_config(bool $reload = false): array
{
    static $config;
    if ($config !== null && !$reload) return $config;
    $file = dirname(__DIR__) . '/config.zoom.php';
    $values = is_file($file) ? require $file : [];
    if (!is_array($values)) $values = [];
    $id = trim((string) (getenv('BHAVI_ZOOM_CLIENT_ID') ?: ($values['client_id'] ?? '')));
    $secret = trim((string) (getenv('BHAVI_ZOOM_CLIENT_SECRET') ?: ($values['client_secret'] ?? '')));
    if ($id === '' || $secret === '') {
        try {
            $id = trim(zoom_setting('zoom_client_id'));
            $secret = trim(credential_decrypt(zoom_setting('zoom_client_secret') ?: null));
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $id = $secret = '';
        }
    }
    $config = ['client_id' => $id, 'client_secret' => $secret];
    return $config;
}

/** True when the credentials come from the portal settings (so the admin can change them on the page). */
function zoom_config_from_portal(): bool
{
    $file = dirname(__DIR__) . '/config.zoom.php';
    $values = is_file($file) ? require $file : [];
    $fileOk = is_array($values) && trim((string) ($values['client_id'] ?? '')) !== '' && trim((string) ($values['client_secret'] ?? '')) !== '';
    return !$fileOk && !getenv('BHAVI_ZOOM_CLIENT_ID');
}

function zoom_save_credentials(string $clientId, string $clientSecret): void
{
    zoom_setting_save('zoom_client_id', $clientId);
    zoom_setting_save('zoom_client_secret', (string) credential_encrypt($clientSecret));
    zoom_config(true);
}

function zoom_ready(): bool
{
    $c = zoom_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

function zoom_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Meeting SDK JWT (HS256). Role 0 = attendee, 1 = host. Valid for 2 hours. */
function zoom_signature(string $meetingNumber, int $role = 0): string
{
    $c = zoom_config();
    $iat = time() - 30;
    $exp = $iat + 7200;
    $header = zoom_b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = zoom_b64url(json_encode([
        'appKey' => $c['client_id'], 'sdkKey' => $c['client_id'],
        'mn' => $meetingNumber, 'role' => $role,
        'iat' => $iat, 'exp' => $exp, 'tokenExp' => $exp,
    ]));
    return $header . '.' . $payload . '.' . zoom_b64url(hash_hmac('sha256', $header . '.' . $payload, $c['client_secret'], true));
}

/** Meeting number (digits only) or '' when invalid. Zoom IDs are 9–11 digits. */
function zoom_meeting_number(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    return preg_match('/^\d{9,11}$/', $digits) ? $digits : '';
}

/**
 * Reads a Zoom invite link such as https://us05web.zoom.us/j/12345678901?pwd=abc
 * Returns ['number'=>..., 'pwd'=>...] (pwd may be the encrypted link token) or null.
 */
function zoom_parse_link(string $url): ?array
{
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'zoom.us' && !str_ends_with($host, '.zoom.us')) return null;
    if (!preg_match('#/(?:j|w|s|wc/join|wc)/(\d{9,11})#', (string) ($parts['path'] ?? ''), $m)) return null;
    parse_str((string) ($parts['query'] ?? ''), $query);
    return ['number' => $m[1], 'pwd' => (string) ($query['pwd'] ?? '')];
}

// ---------------------------------------------------------------------------
// Zoom account connection (OAuth) so the portal can create instant meetings.
// The Managing Director connects once (zoom-connect.php); tokens are stored encrypted in portal_settings.
// Zoom app needs: Redirect URL = zoom_redirect_uri(), scopes to create meetings and read the user's ZAK.
// ---------------------------------------------------------------------------

function zoom_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($dir, '/includes')) $dir = substr($dir, 0, -9);
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function zoom_redirect_uri(): string
{
    return zoom_base_url() . '/zoom-connect.php';
}

function zoom_setting(string $key): string
{
    return (string) (one('SELECT setting_value FROM portal_settings WHERE setting_key=?', [$key])['setting_value'] ?? '');
}

function zoom_setting_save(string $key, string $value): void
{
    $by = current_user()['id'] ?? null;
    query('INSERT INTO portal_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', [$key, $value, $by]);
}

function zoom_connected(): bool
{
    return zoom_ready() && zoom_setting('zoom_refresh_token') !== '';
}

/** POST to zoom.us/oauth/token with the app's Basic auth. */
function zoom_oauth_request(array $fields): array
{
    $c = zoom_config();
    $h = curl_init('https://zoom.us/oauth/token');
    curl_setopt_array($h, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']), 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($h); $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE); $err = curl_error($h); curl_close($h);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        $reason = is_array($data) ? (string) ($data['reason'] ?? $data['error_description'] ?? $data['error'] ?? '') : $err;
        throw new RuntimeException('Zoom sign-in failed' . ($reason !== '' ? ': ' . $reason : '.'));
    }
    return $data;
}

function zoom_store_tokens(array $data): void
{
    zoom_setting_save('zoom_access_token', (string) credential_encrypt((string) $data['access_token']));
    if (!empty($data['refresh_token'])) zoom_setting_save('zoom_refresh_token', (string) credential_encrypt((string) $data['refresh_token']));
    zoom_setting_save('zoom_token_expires', (string) (time() + max(60, (int) ($data['expires_in'] ?? 3600)) - 120));
}

function zoom_access_token(): string
{
    if (!zoom_connected()) throw new RuntimeException('Zoom account is not connected. Open Zoom meetings and click “Connect Zoom account”.');
    if ((int) zoom_setting('zoom_token_expires') > time()) {
        $token = credential_decrypt(zoom_setting('zoom_access_token'));
        if ($token !== '') return $token;
    }
    $data = zoom_oauth_request(['grant_type' => 'refresh_token', 'refresh_token' => credential_decrypt(zoom_setting('zoom_refresh_token'))]);
    zoom_store_tokens($data);
    return (string) $data['access_token'];
}

/** Calls the Zoom REST API (https://api.zoom.us/v2) as the connected account. */
function zoom_api(string $method, string $path, ?array $body = null): array
{
    $h = curl_init('https://api.zoom.us/v2' . $path);
    $headers = ['Authorization: Bearer ' . zoom_access_token(), 'Accept: application/json'];
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($body); $headers[] = 'Content-Type: application/json'; }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($h, $opts);
    $raw = curl_exec($h); $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE); $err = curl_error($h); curl_close($h);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if ($status < 200 || $status >= 300) {
        $reason = is_array($data) ? (string) ($data['message'] ?? '') : $err;
        throw new RuntimeException('Zoom could not do that' . ($reason !== '' ? ': ' . $reason : ' (HTTP ' . $status . ').'));
    }
    return is_array($data) ? $data : [];
}

/** Creates an instant meeting on the connected account that the team can join before the host. */
function zoom_create_instant_meeting(string $topic): array
{
    return zoom_api('POST', '/users/me/meetings', [
        'topic' => mb_substr($topic, 0, 200), 'type' => 1,
        'settings' => ['join_before_host' => true, 'jbh_time' => 0, 'waiting_room' => false, 'mute_upon_entry' => true, 'host_video' => true, 'participant_video' => true],
    ]);
}

/** Saves an API-created meeting in zoom_meetings and returns its id. */
function zoom_save_instant_meeting(array $meeting, string $title, int $userId): int
{
    query('INSERT INTO zoom_meetings (title,meeting_number,passcode,join_url,starts_at,duration_minutes,notes,created_by,is_instant) VALUES (?,?,?,?,NOW(),60,?,?,1)', [
        $title, (string) $meeting['id'], (string) ($meeting['password'] ?? ''), (string) ($meeting['join_url'] ?? ''), 'Instant meeting', $userId,
    ]);
    return (int) db()->lastInsertId();
}

/** Host's ZAK token so the person who started the meeting joins inside the portal as host. */
function zoom_zak(): string
{
    return (string) (zoom_api('GET', '/users/me/token?type=zak')['token'] ?? '');
}
