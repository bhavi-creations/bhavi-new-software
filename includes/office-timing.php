<?php
// Office timing: admin sets one default for everyone; an employee can have their own shift (e.g. night shift)
// or split timings (e.g. 7–10 PM and 5–8 AM) with their own duty hours.

/** Admin's default office timing as ['start'=>'10:00','end'=>'19:00']. */
function office_timing_default(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $values = ['office_start' => '10:00', 'office_end' => '19:00'];
    try {
        foreach (rows("SELECT setting_key,setting_value FROM portal_settings WHERE setting_key IN ('office_start','office_end')") as $row) $values[$row['setting_key']] = $row['setting_value'];
    } catch (Throwable $e) {
        // Settings table not created yet: use the built-in default.
    }
    return $cache = ['start' => substr($values['office_start'], 0, 5), 'end' => substr($values['office_end'], 0, 5)];
}

/** "10:00" → "10:00 AM". */
function office_time_label(?string $time): string
{
    if (!$time) return '—';
    $parsed = DateTimeImmutable::createFromFormat('H:i', substr($time, 0, 5));
    return $parsed ? $parsed->format('g:i A') : $time;
}

/** "10:00 AM – 7:00 PM". */
function office_range_label(string $start, string $end): string
{
    return office_time_label($start) . ' – ' . office_time_label($end) . ($end <= $start ? ' (next day)' : '');
}

/** Split timing slots saved for an employee, e.g. [['19:00','22:00'],['05:00','08:00']] (empty when none). */
function employee_shift_slots(array $profile): array
{
    $raw = $profile['shift_slots'] ?? null;
    $slots = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($slots)) return [];
    $valid = [];
    foreach ($slots as $slot) {
        if (is_array($slot) && isset($slot[0], $slot[1]) && preg_match('/^\d{2}:\d{2}$/D', (string) $slot[0]) && preg_match('/^\d{2}:\d{2}$/D', (string) $slot[1])) $valid[] = [(string) $slot[0], (string) $slot[1]];
    }
    return $valid;
}

/** Seconds covered by a time slot (a night slot runs past midnight). */
function office_slot_seconds(string $start, string $end): int
{
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $seconds = ($eh * 3600 + $em * 60) - ($sh * 3600 + $sm * 60);
    return $seconds <= 0 ? $seconds + 86400 : $seconds;
}

/** 3.5 → "3 hrs 30 min". */
function office_hours_label(float $hours): string
{
    $minutes = (int) round($hours * 60);
    return intdiv($minutes, 60) . ' hrs' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
}

/** Monthly hours target for an hours employee (e.g. 150), or null for a full-time employee. */
function employee_monthly_hours(array $profile): ?float
{
    $hours = $profile['monthly_hours'] ?? null;
    return is_numeric($hours) && (float) $hours > 0 ? (float) $hours : null;
}

/** Timing that applies to one employee: hours per month, own shift / split timings if set, else the office default. */
function employee_timing(array $profile): array
{
    $default = office_timing_default();
    $monthly = employee_monthly_hours($profile);
    if ($monthly !== null) {
        // Hours employee: logs in any time; no late marks, paid for the hours worked.
        return [
            'start' => $default['start'], 'end' => $default['end'], 'slots' => [], 'hours' => null, 'slot_hours' => 0.0,
            'custom' => true, 'split' => false, 'hourly' => true, 'monthly_hours' => $monthly,
            'label' => trim((string) ($profile['shift_label'] ?? '')) ?: 'Hours',
            'text' => office_hours_label($monthly) . ' a month · any time',
        ];
    }
    $slots = employee_shift_slots($profile);
    $custom = !empty($profile['shift_start']) && !empty($profile['shift_end']);
    if (!$slots) $slots = $custom ? [[substr((string) $profile['shift_start'], 0, 5), substr((string) $profile['shift_end'], 0, 5)]] : [[$default['start'], $default['end']]];
    $custom = $custom || count($slots) > 1;
    $slotHours = array_sum(array_map(static fn($s) => office_slot_seconds($s[0], $s[1]), $slots)) / 3600;
    $hours = isset($profile['shift_hours']) && is_numeric($profile['shift_hours']) && (float) $profile['shift_hours'] > 0 ? (float) $profile['shift_hours'] : null;
    $text = implode(' + ', array_map(static fn($s) => office_range_label($s[0], $s[1]), $slots));
    if (count($slots) > 1 || $hours !== null) $text .= ' · ' . office_hours_label($hours ?? $slotHours) . ' a day';
    return [
        'start' => $slots[0][0],
        'end' => $slots[count($slots) - 1][1],
        'slots' => $slots,
        'hours' => $hours,
        'slot_hours' => $slotHours,
        'custom' => $custom,
        'split' => count($slots) > 1,
        'label' => $custom ? (trim((string) ($profile['shift_label'] ?? '')) ?: (count($slots) > 1 ? 'Split timing' : 'Own shift')) : 'Office timing',
        'text' => $text,
    ];
}

/**
 * Seconds late for a first login against an employee's timing (0 when on time).
 * Night shifts (start after end, e.g. 21:00–06:00) are late after the start or after midnight.
 * Split timings: on time when on time for any slot; otherwise the smallest delay inside a slot.
 */
function login_late_seconds(string $loginAt, array $timing): int
{
    $time = strtotime($loginAt);
    if ($time === false || !empty($timing['hourly'])) return 0;
    if (count($timing['slots'] ?? []) > 1) {
        $late = null;
        foreach ($timing['slots'] as $slot) {
            $one = login_late_seconds($loginAt, ['start' => $slot[0], 'end' => $slot[1]]);
            if ($one > 0 && $one < office_slot_seconds($slot[0], $slot[1])) $late = $late === null ? $one : min($late, $one);
        }
        return $late ?? 0;
    }
    $login = (int) date('G', $time) * 3600 + (int) date('i', $time) * 60 + (int) date('s', $time);
    [$sh, $sm] = array_map('intval', explode(':', $timing['start']));
    [$eh, $em] = array_map('intval', explode(':', $timing['end']));
    $start = $sh * 3600 + $sm * 60;
    $end = $eh * 3600 + $em * 60;
    if ($start < $end) return max(0, $login - $start);
    if ($login >= $start) return $login - $start;
    if ($login < $end) return $login + 86400 - $start;
    return 0; // before a night shift starts
}

/**
 * 12-hour time picker (hour · minute · AM/PM). Posts name[h], name[m], name[ap];
 * office_time_input() turns it back into "HH:MM".
 */
function time12_field(string $name, ?string $value, bool $required = true, string $aria = 'Time'): string
{
    $value = $value ? substr($value, 0, 5) : '';
    $h24 = $value !== '' ? (int) substr($value, 0, 2) : null;
    $min = $value !== '' ? substr($value, 3, 2) : '';
    $ap = $h24 !== null && $h24 >= 12 ? 'PM' : 'AM';
    $h12 = $h24 === null ? null : (($h24 % 12) ?: 12);
    $req = $required ? ' required' : '';
    $html = '<span class="time12" role="group" aria-label="' . h($aria) . '"><select name="' . h($name) . '[h]" aria-label="Hour"' . $req . '><option value="">--</option>';
    for ($i = 1; $i <= 12; $i++) $html .= '<option value="' . $i . '"' . ($h12 === $i ? ' selected' : '') . '>' . $i . '</option>';
    $html .= '</select><b>:</b><select name="' . h($name) . '[m]" aria-label="Minute"' . $req . '><option value="">--</option>';
    $minutes = array_map(static fn($m) => str_pad((string) $m, 2, '0', STR_PAD_LEFT), range(0, 55, 5));
    if ($min !== '' && !in_array($min, $minutes, true)) { $minutes[] = $min; sort($minutes); }
    foreach ($minutes as $m) $html .= '<option value="' . $m . '"' . ($min === $m ? ' selected' : '') . '>' . $m . '</option>';
    $html .= '</select><select name="' . h($name) . '[ap]" aria-label="AM or PM">';
    foreach (['AM', 'PM'] as $o) $html .= '<option' . ($ap === $o ? ' selected' : '') . '>' . $o . '</option>';
    return $html . '</select></span>';
}

/** "HH:MM" from a 12-hour picker value (array) or a plain "HH:MM" string; null when left empty, '' when invalid. */
function time12_value($posted): ?string
{
    if (is_array($posted)) {
        $hRaw = (string) ($posted['h'] ?? ''); $m = (string) ($posted['m'] ?? '');
        if ($hRaw === '' && $m === '') return null;
        $hour = (int) $hRaw;
        if ($hour < 1 || $hour > 12 || !preg_match('/^[0-5]\d$/D', $m)) return '';
        $hour = $hour % 12 + (strtoupper((string) ($posted['ap'] ?? 'AM')) === 'PM' ? 12 : 0);
        return str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':' . $m;
    }
    return is_string($posted) && trim($posted) !== '' ? trim($posted) : null;
}

/** Valid "HH:MM" from a form field (12-hour picker or "HH:MM" text), or an error. */
function office_time_input($value, string $label): string
{
    $value = (string) (time12_value($value) ?? '');
    $parsed = DateTimeImmutable::createFromFormat('!H:i', substr(trim($value), 0, 5));
    if (!$parsed) throw new InvalidArgumentException('Choose a valid ' . $label . '.');
    return $parsed->format('H:i');
}
