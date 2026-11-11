<?php
// Office timing: admin sets one default for everyone; an employee can have their own shift (e.g. night shift).

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

/** Timing that applies to one employee: their own shift if set, else the office default. */
function employee_timing(array $profile): array
{
    $default = office_timing_default();
    $custom = !empty($profile['shift_start']) && !empty($profile['shift_end']);
    $start = $custom ? substr((string) $profile['shift_start'], 0, 5) : $default['start'];
    $end = $custom ? substr((string) $profile['shift_end'], 0, 5) : $default['end'];
    return [
        'start' => $start,
        'end' => $end,
        'custom' => $custom,
        'label' => $custom ? (trim((string) ($profile['shift_label'] ?? '')) ?: 'Own shift') : 'Office timing',
        'text' => office_range_label($start, $end),
    ];
}

/** Valid "HH:MM" from a form field, or an error. */
function office_time_input(string $value, string $label): string
{
    $parsed = DateTimeImmutable::createFromFormat('!H:i', substr(trim($value), 0, 5));
    if (!$parsed) throw new InvalidArgumentException('Choose a valid ' . $label . '.');
    return $parsed->format('H:i');
}
