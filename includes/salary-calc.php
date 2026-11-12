<?php
// Day-wise salary.
//  - Day amount = monthly salary ÷ 30.
//  - Leave / absent working days are cut at the day amount. Sundays and holidays stay paid.
//  - Working more than the duty hours, or forgetting to logout, sends the day to the admin for approval.
//    The admin adds an amount (extra work) or cuts an amount (missed logout) and writes a remark.
//  - PF and ESI are cut on the full monthly salary.
require_once __DIR__ . '/finance.php';

const SALARY_DAYS_PER_MONTH = 30;

/** Duty hours per day, set by the admin on the Office timings page (default 8). */
function duty_hours(): float
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $value = 8.0;
    try {
        $row = one("SELECT setting_value FROM portal_settings WHERE setting_key='duty_hours'");
        if ($row && is_numeric($row['setting_value'])) $value = (float) $row['setting_value'];
    } catch (Throwable $e) {
        // Settings table not created yet.
    }
    return $cache = ($value > 0 && $value <= 24) ? $value : 8.0;
}

function duty_hours_label(?float $hours = null): string
{
    $minutes = (int) round(($hours ?? duty_hours()) * 60);
    return intdiv($minutes, 60) . ' hrs' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
}

function work_duration_label(int $seconds): string
{
    $minutes = intdiv(max(0, $seconds), 60);
    return intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

/** ₹ label from paise. */
function paise_label(int $paise): string { return rupees_label($paise / 100); }

/** Day amount in paise for a salary period row. */
function salary_day_rate(array $salaryRow): int
{
    return (int) round(money_cents((string) $salaryRow['amount']) / SALARY_DAYS_PER_MONTH);
}

/** Salary period that covers a date, or null. */
function salary_row_on(int $employeeId, string $date): ?array
{
    return one('SELECT * FROM employee_salary_history WHERE employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1', [$employeeId, $date, $date]);
}

/** Suggested extra-work amount: (day amount ÷ duty hours) × extra hours, rounded to the rupee. */
function overtime_suggestion(int $dayRate, int $workedSeconds): int
{
    $extra = max(0, $workedSeconds - (int) round(duty_hours() * 3600));
    return (int) (round($dayRate * $extra / (duty_hours() * 3600) / 100) * 100);
}

/**
 * Creates approval requests from attendance: extra hours (closed days over duty hours) and missed logouts
 * (a past day whose session was never closed). Returns the requests that were newly created.
 */
function attendance_sync_reviews(?int $employeeId = null): array
{
    $dutySeconds = (int) round(duty_hours() * 3600);
    $filter = $employeeId ? ' AND employee_id=?' : '';
    $params = $employeeId ? [$employeeId] : [];
    // Duty hours raised by the admin: drop pending extra-work requests that are no longer extra.
    query("DELETE FROM attendance_reviews WHERE kind='overtime' AND status='pending' AND worked_seconds<=?" . $filter, array_merge([$dutySeconds], $params));
    $days = rows("SELECT employee_id,DATE(login_at) AS work_date,SUM(logout_at IS NULL) AS open_sessions,
            SUM(IF(logout_at IS NULL,0,TIMESTAMPDIFF(SECOND,login_at,logout_at))) AS worked
        FROM employee_attendance_sessions WHERE login_at>=CURDATE()-INTERVAL 62 DAY" . $filter . '
        GROUP BY employee_id,DATE(login_at)', $params);
    $created = [];
    foreach ($days as $day) {
        $worked = (int) $day['worked'];
        if ((int) $day['open_sessions'] > 0) {
            if ($day['work_date'] >= today()) continue; // still working today
            $kind = 'missed_logout';
            $stmt = query("INSERT IGNORE INTO attendance_reviews (employee_id,work_date,kind,worked_seconds) VALUES (?,?,'missed_logout',?)", [$day['employee_id'], $day['work_date'], $worked]);
        } elseif ($worked > $dutySeconds) {
            $kind = 'overtime';
            $stmt = query("INSERT INTO attendance_reviews (employee_id,work_date,kind,worked_seconds) VALUES (?,?,'overtime',?) ON DUPLICATE KEY UPDATE worked_seconds=IF(status='pending',VALUES(worked_seconds),worked_seconds)", [$day['employee_id'], $day['work_date'], $worked]);
        } else {
            continue;
        }
        if ($stmt->rowCount() === 1) $created[] = ['employee_id' => (int) $day['employee_id'], 'work_date' => $day['work_date'], 'kind' => $kind, 'worked_seconds' => $worked];
    }
    return $created;
}

function pending_review_count(): int
{
    try {
        return count_value("SELECT COUNT(*) FROM attendance_reviews WHERE status='pending'");
    } catch (Throwable $e) {
        return 0;
    }
}

function salary_employee(int $id): ?array
{
    return one("SELECT u.id,u.full_name,ep.joining_date,ep.relieving_date,ep.department_id,ep.employee_code,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL", [$id]);
}

/** Valid "YYYY-MM" (not in the future), else the current month. */
function salary_month_input($value): string
{
    $current = date('Y-m');
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}$/D', $value)) return $current;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m', $value);
    return ($parsed && $parsed->format('Y-m') === $value && $value <= $current) ? $value : $current;
}

/**
 * Full salary calculation for one employee and month. All money values are in paise.
 * Day status: present, working, missed_logout, leave, absent, off, holiday, today, upcoming, not_joined, no_salary.
 */
function salary_month(array $employee, string $month): array
{
    $id = (int) $employee['id'];
    $first = new DateTimeImmutable($month . '-01');
    $last = $first->modify('last day of this month');
    $from = $first->format('Y-m-d');
    $to = $last->format('Y-m-d');
    $daysInMonth = (int) $last->format('j');
    $today = today();

    $history = rows('SELECT * FROM employee_salary_history WHERE employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from', [$id, $to, $from]);
    $schedule = array_column(rows('SELECT iso_weekday,is_working_day FROM department_weekly_schedule WHERE department_id=?', [$employee['department_id']]), 'is_working_day', 'iso_weekday');
    $holidays = array_column(rows('SELECT holiday_date,holiday_name FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?', [$from, $to]), 'holiday_name', 'holiday_date');
    $sessions = [];
    foreach (rows("SELECT DATE(login_at) AS d,MIN(login_at) AS first_login,MAX(logout_at) AS last_logout,SUM(logout_at IS NULL) AS open_sessions,
            SUM(CASE WHEN logout_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,login_at,logout_at) WHEN DATE(login_at)=CURDATE() THEN TIMESTAMPDIFF(SECOND,login_at,NOW()) ELSE 0 END) AS worked
        FROM employee_attendance_sessions WHERE employee_id=? AND login_at>=? AND login_at<? GROUP BY DATE(login_at)", [$id, $from . ' 00:00:00', $last->modify('+1 day')->format('Y-m-d') . ' 00:00:00']) as $session) {
        $sessions[$session['d']] = $session;
    }
    $marks = array_column(rows('SELECT attendance_date,marked_status FROM employee_attendance_days WHERE employee_id=? AND attendance_date BETWEEN ? AND ? AND marked_status IS NOT NULL', [$id, $from, $to]), 'marked_status', 'attendance_date');
    $leaves = [];
    foreach (rows("SELECT l.from_date,l.to_date,t.name FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id WHERE l.employee_id=? AND l.status='approved' AND l.from_date<=? AND l.to_date>=?", [$id, $to, $from]) as $leave) {
        if (stripos($leave['name'], 'permission') !== false) continue; // a few hours, not a full day
        for ($d = new DateTimeImmutable(max($from, $leave['from_date'])); $d <= new DateTimeImmutable(min($to, $leave['to_date'])); $d = $d->modify('+1 day')) $leaves[$d->format('Y-m-d')] = $leave['name'];
    }
    $reviews = [];
    foreach (rows('SELECT r.*,u.full_name AS reviewer_name FROM attendance_reviews r LEFT JOIN users u ON u.id=r.reviewed_by WHERE r.employee_id=? AND r.work_date BETWEEN ? AND ? ORDER BY r.work_date,r.kind', [$id, $from, $to]) as $review) {
        $reviews[$review['work_date']][] = $review;
    }

    $days = [];
    $share = 0.0;
    $totals = ['present' => 0, 'leave' => 0, 'paid_off' => 0, 'leave_cut' => 0, 'overtime_add' => 0, 'missed_cut' => 0, 'pending' => 0];
    $lastRow = null;
    for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        $row = null;
        foreach ($history as $candidate) if ($candidate['effective_from'] <= $date && (!$candidate['effective_to'] || $candidate['effective_to'] >= $date)) $row = $candidate;
        $session = $sessions[$date] ?? null;
        $rate = $row ? salary_day_rate($row) : 0;
        $isWorking = (bool) ($schedule[(int) $d->format('N')] ?? ((int) $d->format('N') !== 7)) && !isset($holidays[$date]);
        $label = '';
        if ($date < $employee['joining_date'] || ($employee['relieving_date'] && $date > $employee['relieving_date'])) {
            $status = 'not_joined';
        } elseif (!$row) {
            $status = 'no_salary';
        } elseif ($session) {
            $status = (int) $session['open_sessions'] ? ($date < $today ? 'missed_logout' : 'working') : 'present';
        } elseif ($date > $today) {
            $status = 'upcoming';
        } elseif (!$isWorking) {
            $status = isset($holidays[$date]) ? 'holiday' : 'off';
            $label = $holidays[$date] ?? '';
        } elseif (isset($leaves[$date]) || in_array($marks[$date] ?? '', ['leave', 'absent'], true)) {
            $status = 'leave';
            $label = $leaves[$date] ?? (($marks[$date] ?? '') === 'absent' ? 'Marked absent' : 'Marked leave');
        } elseif ($date === $today) {
            $status = 'today';
        } else {
            $status = 'absent';
        }
        if ($row) {
            $share += money_cents((string) $row['amount']) / $daysInMonth;
            $lastRow = $row;
        }
        $amount = 0;
        if (in_array($status, ['present', 'working', 'missed_logout'], true)) { $amount = $rate; $totals['present']++; }
        elseif (in_array($status, ['off', 'holiday'], true)) { $amount = $rate; $totals['paid_off']++; }
        elseif (in_array($status, ['leave', 'absent'], true)) { $amount = -$rate; $totals['leave']++; $totals['leave_cut'] += $rate; }
        $adjust = 0;
        foreach ($reviews[$date] ?? [] as $review) {
            if ($review['status'] === 'pending') { $totals['pending']++; continue; }
            if ($review['status'] !== 'approved') continue;
            $value = money_cents((string) $review['amount']);
            if ($review['kind'] === 'overtime') { $adjust += $value; $totals['overtime_add'] += $value; }
            else { $adjust -= $value; $totals['missed_cut'] += $value; }
        }
        $days[] = [
            'date' => $date, 'status' => $status, 'label' => $label, 'rate' => $rate, 'amount' => $amount, 'adjust' => $adjust,
            'login' => $session['first_login'] ?? null, 'logout' => ($session && !(int) $session['open_sessions']) ? $session['last_logout'] : null,
            'worked' => (int) ($session['worked'] ?? 0), 'reviews' => $reviews[$date] ?? [],
        ];
    }
    $monthly = (int) round($share);
    $pf = $lastRow ? money_cents((string) $lastRow['employee_pf']) : 0;
    $esi = $lastRow ? money_cents((string) $lastRow['employee_esi']) : 0;
    $benefits = $lastRow ? money_cents((string) ($lastRow['other_benefit_amount'] ?? '0.00')) : 0;
    $earned = $monthly - $totals['leave_cut'] + $totals['overtime_add'] - $totals['missed_cut'];
    return [
        'month' => $month, 'first' => $first, 'days' => $days, 'salary' => $lastRow,
        'full_salary' => $lastRow ? money_cents((string) $lastRow['amount']) : 0,
        'monthly' => $monthly, 'rate' => $lastRow ? salary_day_rate($lastRow) : 0,
        'earned' => $earned, 'benefits' => $benefits, 'pf' => $pf, 'esi' => $esi,
        'net' => max(0, $earned + $benefits - $pf - $esi),
        'is_current' => $month === date('Y-m'),
    ] + $totals;
}
