<?php
// Shared helpers for Client meetings & shoots (list page: client-meetings.php, form: client-meeting-form.php).
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/finance.php';

const CM_MEETING_TYPES = ['live' => 'Live meeting', 'google_meet' => 'Google Meet', 'other' => 'Other', 'none' => 'No meeting'];
const CM_SHOOT_TYPES = ['our_team' => 'We will shoot', 'client' => 'Client will shoot', 'other' => 'Other', 'none' => 'No shoot'];
const CM_STATUSES = ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
const CM_RESPONSES = ['pending' => 'Waiting for client', 'confirmed' => 'Confirmed by client', 'change_requested' => 'Change requested'];
// Notification titles start with this so notification-open.php can open the Meetings & shoots page.
const CM_NOTICE_PREFIX = 'Meeting & shoot';

/** Human label for the meeting / shoot choice (uses the typed text for "Other"). */
function cm_meeting_label(array $e): string { return $e['meeting_type'] === 'other' ? ($e['meeting_other'] ?: 'Other') : (CM_MEETING_TYPES[$e['meeting_type']] ?? ''); }
function cm_shoot_label(array $e): string { return $e['shoot_type'] === 'other' ? ($e['shoot_other'] ?: 'Other') : (CM_SHOOT_TYPES[$e['shoot_type']] ?? ''); }
// No duration field: for the "another client in this time" check a meeting holds the team for 1 hour
// and a shoot for 3 hours from its start time.
const CM_MEETING_MINUTES = 60;
const CM_SHOOT_MINUTES = 180;
/** "Tue, 17 Nov 2026 · 11:00 AM". */
function cm_when(?string $dt): string { return $dt ? date('D, j M Y · g:i A', strtotime($dt)) : 'Date to be fixed'; }
/**
 * Every booked slot of the team from yesterday on: meetings, and shoots our team (or someone else) does.
 * "Client will shoot" does not take the team's time, so it is left out. Cancelled / completed plans are free.
 * @return list<array{id:int,kind:string,client:string,label:string,start:int,end:int}>
 */
function cm_busy_slots(int $excludeId = 0): array
{
    $slots = [];
    $plans = rows("SELECT e.*,c.client_name FROM client_engagements e JOIN clients c ON c.id=e.client_id WHERE e.deleted_at IS NULL AND e.status='scheduled' AND e.id<>? AND (e.meeting_at>=CURDATE()-INTERVAL 1 DAY OR e.shoot_at>=CURDATE()-INTERVAL 1 DAY)", [$excludeId]);
    foreach ($plans as $e) {
        if ($e['meeting_type'] !== 'none' && $e['meeting_at']) {
            $s = strtotime($e['meeting_at']);
            $slots[] = ['id' => (int) $e['id'], 'kind' => 'meeting', 'client' => $e['client_name'], 'label' => cm_meeting_label($e), 'start' => $s, 'end' => $s + CM_MEETING_MINUTES * 60];
        }
        if (!in_array($e['shoot_type'], ['none', 'client'], true) && $e['shoot_at']) {
            $s = strtotime($e['shoot_at']);
            $slots[] = ['id' => (int) $e['id'], 'kind' => 'shoot', 'client' => $e['client_name'], 'label' => cm_shoot_label($e), 'start' => $s, 'end' => $s + CM_SHOOT_MINUTES * 60];
        }
    }
    usort($slots, static fn($a, $b) => $a['start'] <=> $b['start']);
    return $slots;
}
/** Slots that overlap [start, start + minutes). */
function cm_overlaps(array $slots, string $start, int $minutes): array
{
    $s = strtotime($start); $e = $s + $minutes * 60;
    return array_values(array_filter($slots, static fn($x) => $x['start'] < $e && $x['end'] > $s));
}
function cm_slot_text(array $x): string
{
    return 'Another client ' . $x['kind'] . ' in this time: ' . $x['client'] . ' · ' . $x['label'] . ' · ' . date('j M, g:i A', $x['start']);
}
function cm_title(array $e): string { return $e['title'] !== '' ? $e['title'] : 'Meeting & shoot'; }
function cm_datetime(string $key, bool $required, string $what): ?string
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') { if ($required) throw new InvalidArgumentException('Choose the ' . $what . ' date and time.'); return null; }
    $ts = strtotime($raw);
    if (!$ts) throw new InvalidArgumentException('Choose a valid ' . $what . ' date and time.');
    return date('Y-m-d H:i:s', $ts);
}
/** Google Calendar "add event" link (Asia/Kolkata). */
function cm_gcal(string $title, string $start, int $minutes, string $details, string $location): string
{
    $s = strtotime($start);
    return 'https://calendar.google.com/calendar/render?' . http_build_query(['action' => 'TEMPLATE', 'text' => $title, 'dates' => date('Ymd\THis', $s) . '/' . date('Ymd\THis', $s + $minutes * 60), 'ctz' => 'Asia/Kolkata', 'details' => $details, 'location' => $location]);
}
function cm_wa_link(?string $phone, string $text): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) === 10) $digits = '91' . $digits;
    if (strlen($digits) < 11) return null;
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
}
function cm_summary(array $e): string
{
    $lines = [];
    if ($e['meeting_type'] !== 'none') {
        $lines[] = 'Meeting: ' . cm_meeting_label($e) . ' · ' . cm_when($e['meeting_at']);
        if ($e['meeting_place'] !== '') $lines[] = ($e['meeting_type'] === 'google_meet' ? 'Meet link: ' : 'Place: ') . $e['meeting_place'];
    }
    if ($e['shoot_type'] !== 'none') {
        $lines[] = 'Shoot: ' . cm_shoot_label($e) . ' · ' . cm_when($e['shoot_at']);
        if ($e['shoot_place'] !== '') $lines[] = 'Location: ' . $e['shoot_place'];
    }
    return implode("\n", $lines);
}
/** True when every planned date is already over (the plan still says "scheduled"). */
function cm_is_past(array $e): bool
{
    $dates = array_filter([$e['meeting_at'], $e['shoot_at']]);
    if (!$dates) return false;
    foreach ($dates as $d) if (strtotime($d) >= strtotime('today')) return false;
    return true;
}
function cm_notify(int $recipientId, int $senderId, string $title, string $message): void
{
    query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$recipientId, $senderId, $title, $message]);
}
function cm_notify_staff(int $senderId, string $title, string $message, ?int $alsoUserId): void
{
    query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) SELECT id,?,'general',?,? FROM users WHERE deleted_at IS NULL AND account_status='active' AND (role IN ('admin','manager') OR id<=>?) AND id<>?", [$senderId, $title, $message, $alsoUserId, $senderId]);
}
function cm_initials(string $name): string
{
    $w = array_slice(preg_split('/\s+/', trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name))) ?: [], 0, 2);
    return strtoupper(implode('', array_map(static fn($p) => mb_substr($p, 0, 1), $w))) ?: '?';
}
/** Plans where the client asked for a change and nobody has updated them yet (sidebar badge). */
function cm_attention_count(): int
{
    return count_value("SELECT COUNT(*) FROM client_engagements WHERE deleted_at IS NULL AND status='scheduled' AND client_response='change_requested'");
}
