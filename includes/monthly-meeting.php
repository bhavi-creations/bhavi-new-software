<?php
// Monthly client meeting: set on the client form (day of month + time, how we meet).
// A change (asked by the client, or made by the Managing Director) can apply to 1 month, a few months, or permanently.
//   - months = N  → for N months starting from start_month, the meeting moves to meeting_day / meeting_time.
//   - months NULL → permanent: the client's own meeting_day / meeting_time are updated when it is approved.
require_once __DIR__ . '/layout.php';

const MM_MODES = ['live' => 'Live meeting', 'google_meet' => 'Google Meet', 'phone' => 'Phone call', 'zoom' => 'Zoom'];
const MM_NOTICE_PREFIX = 'Monthly meeting';

/** "20th" */
function mm_ordinal(int $n): string
{
    $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $s;
}

function mm_time_label(?string $time): string { return $time ? date('g:i A', strtotime('2000-01-01 ' . $time)) : ''; }

/** "Every month on the 20th at 11:00 AM" (or '' when not set). */
function mm_rule_label(array $client): string
{
    if (empty($client['meeting_day'])) return '';
    return 'Every month on the ' . mm_ordinal((int) $client['meeting_day']) . ($client['meeting_time'] ? ' at ' . mm_time_label($client['meeting_time']) : '');
}

/** Approved temporary changes (months not null) for a client, newest first. */
function mm_overrides(int $clientId): array
{
    return rows("SELECT * FROM client_meeting_changes WHERE client_id=? AND status='approved' AND months IS NOT NULL ORDER BY decided_at DESC,id DESC", [$clientId]);
}

/** The override that covers a month (Y-m-01), if any. */
function mm_override_for(array $overrides, string $monthStart): ?array
{
    foreach ($overrides as $o) {
        $end = date('Y-m-01', strtotime($o['start_month'] . ' +' . (int) $o['months'] . ' months'));
        if ($o['start_month'] <= $monthStart && $monthStart < $end) return $o;
    }
    return null;
}

/**
 * The next meetings from today (inclusive): list of ['date'=>Y-m-d,'time'=>H:i:s,'changed'=>bool,'change'=>row|null].
 * Returns [] when the client has no monthly meeting day.
 */
function mm_upcoming(array $client, int $count = 1, ?array $overrides = null): array
{
    if (empty($client['meeting_day'])) return [];
    $overrides ??= mm_overrides((int) $client['id']);
    $out = [];
    $today = date('Y-m-d');
    $month = new DateTimeImmutable('first day of this month');
    for ($i = 0; $i < 24 && count($out) < $count; $i++, $month = $month->modify('+1 month')) {
        $start = $month->format('Y-m-01');
        $o = mm_override_for($overrides, $start);
        $day = (int) ($o['meeting_day'] ?? $client['meeting_day']);
        $time = (string) ($o['meeting_time'] ?? $client['meeting_time'] ?? '');
        $date = $month->format('Y-m-') . sprintf('%02d', min($day, (int) $month->format('t')));
        if ($date < $today) continue;
        $out[] = ['date' => $date, 'time' => $time, 'changed' => $o !== null, 'change' => $o];
    }
    return $out;
}

function mm_next(array $client): ?array { return mm_upcoming($client, 1)[0] ?? null; }

/** Days from today to a date (0 = today). */
function mm_days_to(string $date): int
{
    return (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date))->format('%r%a');
}

/** "Today meeting", "1 day to go", "3 days to go". */
function mm_countdown(string $date): string
{
    $d = mm_days_to($date);
    if ($d <= 0) return 'Today meeting';
    return $d . ' ' . ($d === 1 ? 'day' : 'days') . ' to go';
}

/** "Fri, 20 Nov 2026 · 11:00 AM" */
function mm_when(array $next): string
{
    return date('D, j M Y', strtotime($next['date'])) . ($next['time'] ? ' · ' . mm_time_label($next['time']) : '');
}

/** "For 1 month (Nov 2026)", "For 3 months (Nov 2026 – Jan 2027)", "Permanent from Nov 2026". */
function mm_scope_label(array $change): string
{
    $from = date('M Y', strtotime($change['start_month']));
    if ($change['months'] === null || $change['months'] === '') return 'Permanent from ' . $from;
    $n = (int) $change['months'];
    if ($n === 1) return 'Only ' . $from;
    return 'For ' . $n . ' months (' . $from . ' – ' . date('M Y', strtotime($change['start_month'] . ' +' . ($n - 1) . ' months')) . ')';
}

function mm_mode_label(array $client): string { return MM_MODES[$client['meeting_mode'] ?? 'live'] ?? 'Meeting'; }

/**
 * Reads the change form fields (new_date, new_time, scope, months) from $_POST.
 * @return array{meeting_day:int,meeting_time:string,start_month:string,months:?int}
 */
function mm_change_input(): array
{
    $date = date_input('new_date');
    if ($date < date('Y-m-d')) throw new InvalidArgumentException('Choose today or a future date for the meeting.');
    $timeRaw = trim((string) ($_POST['new_time'] ?? ''));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $timeRaw)) throw new InvalidArgumentException('Choose the meeting time.');
    $scope = (string) ($_POST['scope'] ?? 'once');
    if (!in_array($scope, ['once', 'months', 'permanent'], true)) throw new InvalidArgumentException('Choose how long the change applies.');
    $months = null;
    if ($scope === 'once') $months = 1;
    if ($scope === 'months') {
        $months = filter_var($_POST['months'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24]]);
        if ($months === false || $months === null) throw new InvalidArgumentException('Enter the number of months (1 to 24).');
    }
    return ['meeting_day' => (int) date('j', strtotime($date)), 'meeting_time' => $timeRaw . ':00', 'start_month' => date('Y-m-01', strtotime($date)), 'months' => $months];
}

/** Apply an approved change. Permanent changes update the client's own day / time. */
function mm_apply(array $change, int $clientId): void
{
    if ($change['months'] === null) {
        query('UPDATE clients SET meeting_day=?,meeting_time=? WHERE id=?', [$change['meeting_day'], $change['meeting_time'], $clientId]);
    }
}

function mm_pending_count(): int
{
    return count_value("SELECT COUNT(*) FROM client_meeting_changes m JOIN clients c ON c.id=m.client_id WHERE m.status='pending' AND c.deleted_at IS NULL");
}

/** Small countdown card (client dashboard, project details). */
function mm_countdown_card(array $client, bool $forClient): string
{
    $next = mm_next($client);
    if (!$next) return '';
    $days = mm_days_to($next['date']);
    $tone = $days <= 0 ? 'is-today' : ($days <= 3 ? 'is-soon' : '');
    ob_start(); ?>
    <section class="mm-count <?= $tone ?>" aria-label="Next monthly meeting">
        <div class="mm-count-badge"><strong><?= $days <= 0 ? 'Today' : $days ?></strong><small><?= $days <= 0 ? 'meeting' : ($days === 1 ? 'day to go' : 'days to go') ?></small></div>
        <div class="mm-count-text">
            <p class="mm-count-eyebrow"><?= $forClient ? 'Your next monthly meeting' : 'Next monthly meeting' ?></p>
            <h2><?= h(date('d-m-Y', strtotime($next['date']))) ?><?= $next['time'] ? ' · ' . h(mm_time_label($next['time'])) : '' ?></h2>
            <p><?= h(mm_countdown($next['date'])) ?> · <?= h(mm_mode_label($client)) ?><?= ($client['meeting_place'] ?? '') !== '' && ($client['meeting_mode'] ?? '') !== 'google_meet' ? ' · ' . h($client['meeting_place']) : '' ?><?= $next['changed'] ? ' · <em>Moved for this month</em>' : '' ?></p>
        </div>
        <div class="mm-count-actions">
            <?php if (($client['meeting_mode'] ?? '') === 'google_meet' && preg_match('#^https?://#i', (string) $client['meeting_place'])): ?><a class="mm-btn is-gold" href="<?= h($client['meeting_place']) ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a><?php endif; ?>
            <a class="mm-btn" href="client-monthly-meeting.php<?= $forClient ? '' : '?client=' . (int) $client['id'] ?>"><?= $forClient ? 'Change meeting time' : 'Change time' ?></a>
        </div>
    </section>
    <?php return (string) ob_get_clean();
}
