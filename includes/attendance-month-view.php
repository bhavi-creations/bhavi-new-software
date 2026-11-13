<?php
// One clear month view for an employee (used by the employee and by the admin):
// salary for the login days, leave cuts, admin cuts / additions with reason, and a calendar
// where every date shows login, logout, worked hours and the amount for that day.
require_once __DIR__ . '/salary-calc.php';
require_once __DIR__ . '/office-timing.php';

function attendance_month_view(array $employee, array $calc, bool $isAdmin): void
{
    $rate = $calc['rate'];
    $timing = employee_timing(one('SELECT shift_start,shift_end,shift_label,shift_slots,shift_hours FROM employee_profiles WHERE user_id=?', [$employee['id']]) ?: []);
    $cuts = [];
    $adds = [];
    $pending = [];
    foreach ($calc['days'] as $day) {
        foreach ($day['reviews'] as $review) {
            $amount = money_cents((string) $review['amount']);
            if ($review['status'] === 'pending') $pending[] = $review;
            elseif ($review['status'] === 'approved' && $amount > 0) {
                $item = ['date' => $review['work_date'], 'paise' => $amount, 'reason' => $review['remark'], 'what' => $review['kind'] === 'overtime' ? 'Extra work' : 'Missed logout'];
                if ($review['kind'] === 'overtime') $adds[] = $item; else $cuts[] = $item;
            }
        }
        foreach ($day['adjustments'] as $adjustment) {
            $item = ['date' => $adjustment['adjustment_date'], 'paise' => money_cents((string) $adjustment['amount']), 'reason' => $adjustment['reason'], 'what' => ''];
            if ($adjustment['kind'] === 'add') $adds[] = $item; else $cuts[] = $item;
        }
    }
    $cutTotal = $calc['missed_cut'] + $calc['manual_cut'];
    $addTotal = $calc['overtime_add'] + $calc['manual_add'];
    $loginAmount = $calc['present'] * $rate;
    $offAmount = $calc['paid_off'] * $rate;
    $tillToday = max(0, $loginAmount + $offAmount + $addTotal - $cutTotal);
    $money = static fn(int $p): string => h(paise_label($p));
    $monthLabel = $calc['first']->format('F Y');
    $salaryLink = 'employee-salary.php?' . http_build_query(array_filter(['employee' => $isAdmin ? (int) $employee['id'] : null, 'month' => $calc['month']]));
    $statusText = ['present' => 'Present', 'working' => 'Working now', 'missed_logout' => 'Missed logout', 'leave' => 'Leave', 'absent' => 'Absent', 'off' => 'Sunday / week off', 'holiday' => 'Holiday', 'today' => 'Not logged in yet', 'upcoming' => '', 'not_joined' => 'Not joined', 'no_salary' => 'No salary set'];
    ?>
<?php if ($calc['salary']): ?>
<section class="am-sum" aria-label="Salary for <?= h($monthLabel) ?>">
    <div class="am-sum-head">
        <h3>Salary calculation · <?= h($monthLabel) ?></h3>
        <p>Monthly salary <?= $money($calc['full_salary']) ?> ÷ <?= SALARY_DAYS_PER_MONTH ?> days = <b><?= $money($rate) ?> per day</b></p>
    </div>
    <div class="am-cards">
        <article class="am-card is-login">
            <span>Login days</span>
            <strong><?= $calc['present'] ?> day<?= $calc['present'] === 1 ? '' : 's' ?></strong>
            <p><?= $calc['present'] ?> × <?= $money($rate) ?> = <b><?= $money($loginAmount) ?></b></p>
        </article>
        <article class="am-card is-off">
            <span>Sundays &amp; holidays (paid)</span>
            <strong><?= $calc['paid_off'] ?> day<?= $calc['paid_off'] === 1 ? '' : 's' ?></strong>
            <p><?= $calc['paid_off'] ?> × <?= $money($rate) ?> = <b><?= $money($offAmount) ?></b></p>
        </article>
        <article class="am-card is-leave">
            <span>Leave days (salary cut)</span>
            <strong><?= $calc['leave'] ?> day<?= $calc['leave'] === 1 ? '' : 's' ?></strong>
            <p><?= $calc['leave'] ?> × <?= $money($rate) ?> = <b>−<?= $money($calc['leave_cut']) ?></b></p>
        </article>
        <article class="am-card is-cut">
            <span>Salary cut by admin</span>
            <strong>−<?= $money($cutTotal) ?></strong>
            <?php if (!$cuts): ?><p>No cut this month.</p><?php endif; ?>
            <?php foreach ($cuts as $c): ?><p class="am-item"><b><?= h(date('d M', strtotime($c['date']))) ?> · −<?= $money($c['paise']) ?></b><?= $c['what'] !== '' ? ' · ' . h($c['what']) : '' ?><?= $c['reason'] !== '' ? '<br>Reason: ' . h($c['reason']) : '' ?></p><?php endforeach; ?>
        </article>
        <article class="am-card is-add">
            <span>Added by admin</span>
            <strong>+<?= $money($addTotal) ?></strong>
            <?php if (!$adds): ?><p>Nothing added this month.</p><?php endif; ?>
            <?php foreach ($adds as $a): ?><p class="am-item"><b><?= h(date('d M', strtotime($a['date']))) ?> · +<?= $money($a['paise']) ?></b><?= $a['what'] !== '' ? ' · ' . h($a['what']) : '' ?><?= $a['reason'] !== '' ? '<br>Reason: ' . h($a['reason']) : '' ?></p><?php endforeach; ?>
        </article>
    </div>
    <div class="am-total">
        <div>
            <span><?= $calc['is_current'] ? 'Salary till today' : 'Salary for these days' ?></span>
            <p><?= $money($loginAmount) ?> login + <?= $money($offAmount) ?> holidays<?= $addTotal ? ' + ' . $money($addTotal) . ' added by admin' : '' ?><?= $cutTotal ? ' − ' . $money($cutTotal) . ' cut by admin' : '' ?></p>
        </div>
        <strong><?= $money($tillToday) ?></strong>
    </div>
    <p class="am-foot">
        <?= $calc['is_current'] ? 'Full month salary (if no more leave)' : 'Final salary for the month' ?>, after PF <?= $money($calc['pf']) ?> and ESI <?= $money($calc['esi']) ?>: <b><?= $money($calc['net']) ?></b>
        · <a href="<?= h($salaryLink) ?>">Full salary details →</a>
        <?php if ($isAdmin): ?> · <a href="<?= h($salaryLink) ?>#adjustments">＋ Add / cut amount</a><?php endif; ?>
        <?php if ($pending): ?><br><span class="am-wait"><?= count($pending) ?> day<?= count($pending) === 1 ? ' is' : 's are' ?> waiting for admin approval<?= $isAdmin ? ' · <a href="attendance-approvals.php">Approve now</a>' : '' ?>.</span><?php endif; ?>
    </p>
</section>
<?php else: ?>
<section class="am-sum"><p class="muted">No salary is set for <?= h($monthLabel) ?>, so day amounts are not shown.</p></section>
<?php endif; ?>

<section class="am-cal-card">
    <div class="am-legend">
        <span class="is-present">Present</span><span class="is-late">Late login</span><span class="is-leave">Leave / absent (cut)</span><span class="is-missed_logout">Missed logout</span><span class="is-off">Sunday / holiday</span>
        <small>Office time <?= h($timing['text']) ?> · login after <?= h(office_time_label($timing['start'])) ?> is late</small>
    </div>
    <div class="am-cal">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $wd): ?><div class="am-wd"><?= $wd ?></div><?php endforeach; ?>
        <?php for ($b = 1; $b < (int) $calc['first']->format('N'); $b++): ?><div class="am-blank" aria-hidden="true"></div><?php endfor; ?>
        <?php foreach ($calc['days'] as $day):
            $status = $day['status'];
            // Late = first login after the start time (day shifts only; night shifts cross midnight).
            $late = $day['login'] && $timing['start'] < $timing['end'] && date('H:i', strtotime($day['login'])) > $timing['start'];
            $class = $status === 'holiday' ? 'off' : $status;
            if ($status === 'absent') $class = 'leave';
            if ($status === 'present' && $late) $class = 'late';
            $isToday = $day['date'] === today();
        ?>
        <article class="am-day is-<?= h($class) ?><?= $isToday ? ' is-today' : '' ?><?= in_array($status, ['upcoming', 'not_joined'], true) && $day['label'] === '' ? ' is-empty' : '' ?><?= $status === 'upcoming' && $day['label'] !== '' ? ' is-planned' : '' ?>">
            <header><b><?= (int) date('j', strtotime($day['date'])) ?></b><small><?= h(date('D, d M', strtotime($day['date']))) ?></small><?php if ($statusText[$status] !== ''): ?><i><?= h($status === 'present' && $late ? 'Late login' : $statusText[$status]) ?></i><?php endif; ?></header>
            <?php if ($day['label'] !== ''): ?><p class="am-label"><?= h($day['label']) ?></p><?php endif; ?>
            <?php if ($day['login']): ?>
                <p class="am-times"><span>In <b><?= h(date('g:i A', strtotime($day['login']))) ?></b></span><span>Out <b><?= $day['logout'] ? h(date('g:i A', strtotime($day['logout']))) : ($status === 'working' ? '—' : '<em class="am-bad">not done</em>') ?></b></span><?php if ($day['worked']): ?><span><?= h(work_duration_label($day['worked'])) ?></span><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($day['amount'] > 0): ?><p class="am-amt is-pos"><?= $money($day['amount']) ?></p><?php elseif ($day['amount'] < 0): ?><p class="am-amt is-neg">Salary cut −<?= $money(-$day['amount']) ?></p><?php endif; ?>
            <?php foreach ($day['reviews'] as $review): $amt = money_cents((string) $review['amount']); $ot = $review['kind'] === 'overtime'; ?>
                <?php if ($review['status'] === 'pending'): ?>
                    <p class="am-adj is-wait"><?= $ot ? 'Extra ' . h(work_duration_label(max(0, (int) $review['worked_seconds'] - (int) round(duty_hours((int) $employee['id']) * 3600)))) : 'Missed logout' ?> · waiting for admin</p>
                <?php elseif ($review['status'] === 'approved' && $amt > 0): ?>
                    <p class="am-adj <?= $ot ? 'is-add' : 'is-cut' ?>"><b><?= $ot ? 'Extra added +' : 'MD cut −' ?><?= $money($amt) ?></b><?= $review['remark'] !== '' ? '<span>Reason: ' . h($review['remark']) . '</span>' : '' ?></p>
                <?php else: ?>
                    <p class="am-adj"><b><?= $ot ? 'Extra not approved' : 'No cut' ?></b><?= $review['remark'] !== '' ? '<span>' . h($review['remark']) . '</span>' : '' ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($day['adjustments'] as $adjustment): $isAdd = $adjustment['kind'] === 'add'; ?>
                <p class="am-adj <?= $isAdd ? 'is-add' : 'is-cut' ?>"><b><?= $isAdd ? 'MD added +' : 'MD cut −' ?><?= $money(money_cents((string) $adjustment['amount'])) ?></b><span>Reason: <?= h($adjustment['reason']) ?></span></p>
            <?php endforeach; ?>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
}
