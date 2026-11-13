<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/salary-calc.php';
$user = require_roles();
$staff = is_staff();
if (!$staff && isset($_GET['employee']) && (int) $_GET['employee'] !== (int) $user['id']) fail(403, 'You can only view your own salary.');
$people = $staff ? array_values(array_filter(employees(), static fn($e) => $e['account_status'] === 'active')) : [];
$employeeId = $staff ? (int) ($_GET['employee'] ?? ($people[0]['id'] ?? 0)) : (int) $user['id'];
$month = salary_month_input($_GET['month'] ?? null);
$employee = $employeeId ? salary_employee($employeeId) : null;
if ($staff && $employeeId && !$employee) fail(404, 'Employee not found.');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$staff) fail(403, 'Only management can add or cut salary amounts.');
    check_csrf();
    try {
        if (!$employee) throw new InvalidArgumentException('Choose an employee.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_adjustment') {
            $kind = (string) ($_POST['kind'] ?? '');
            if (!in_array($kind, ['add', 'cut'], true)) throw new InvalidArgumentException('Choose Add amount or Cut amount.');
            $raw = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
            $amount = money_cents($raw === '' ? '0' : $raw);
            if ($amount <= 0) throw new InvalidArgumentException('Enter an amount greater than zero.');
            if ($amount > 99999999) throw new InvalidArgumentException('Amount is too large.');
            $date = date_input('adjustment_date');
            if ($date < $employee['joining_date']) throw new InvalidArgumentException('Date must be on or after the joining date.');
            if ($date > date('Y-m-t')) throw new InvalidArgumentException('Choose a date in this month or earlier.');
            $reason = text_input('reason', 500, true);
            query('INSERT INTO salary_adjustments (employee_id,adjustment_date,kind,amount,reason,created_by) VALUES (?,?,?,?,?,?)', [$employee['id'], $date, $kind, decimal_money($amount), $reason, $user['id']]);
            $title = ($kind === 'add' ? 'Salary: ' . paise_label($amount) . ' added' : 'Salary: ' . paise_label($amount) . ' cut');
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$employee['id'], $user['id'], $title, date('d M Y', strtotime($date)) . ' — Reason: ' . $reason]);
            flash($employee['full_name'] . ': ' . paise_label($amount) . ($kind === 'add' ? ' added to' : ' cut from') . ' the salary. The employee can see it now.');
            redirect('employee-salary.php?employee=' . (int) $employee['id'] . '&month=' . substr($date, 0, 7) . '#adjustments');
        } elseif ($action === 'delete_adjustment') {
            $adjustment = one('SELECT * FROM salary_adjustments WHERE id=? AND employee_id=?', [positive_id($_POST['adjustment_id'] ?? null), $employee['id']]);
            if (!$adjustment) throw new InvalidArgumentException('This entry was already removed.');
            query('DELETE FROM salary_adjustments WHERE id=?', [$adjustment['id']]);
            flash('Entry removed: ' . paise_label(money_cents((string) $adjustment['amount'])) . ' ' . ($adjustment['kind'] === 'add' ? 'add' : 'cut') . ' on ' . date('d M Y', strtotime($adjustment['adjustment_date'])) . '.');
            redirect('employee-salary.php?employee=' . (int) $employee['id'] . '&month=' . substr($adjustment['adjustment_date'], 0, 7) . '#adjustments');
        } else {
            throw new InvalidArgumentException('Invalid request.');
        }
    } catch (Throwable $e) {
        $error = mutation_error($e);
    }
}
if ($employee) attendance_sync_reviews((int) $employee['id']);
$calc = $employee ? salary_month($employee, $month) : null;

$statusLabels = ['present' => 'Present', 'working' => 'Working now', 'missed_logout' => 'Missed logout', 'leave' => 'Leave', 'absent' => 'Absent · no login', 'off' => 'Weekly off', 'holiday' => 'Holiday', 'today' => 'Today · not logged in', 'upcoming' => 'Upcoming', 'not_joined' => 'Not employed', 'no_salary' => 'No salary set'];
$signed = static fn(int $paise): string => $paise === 0 ? '—' : ($paise > 0 ? '+' : '−') . paise_label(abs($paise));
$reviewText = static function (array $review): string {
    $kind = $review['kind'] === 'overtime' ? 'Extra work ' . work_duration_label(max(0, (int) $review['worked_seconds'] - (int) round(duty_hours((int) $review['employee_id']) * 3600))) : 'Missed logout';
    if ($review['status'] === 'pending') return $kind . ' · waiting for admin approval';
    $amount = money_cents((string) $review['amount']);
    if ($review['status'] === 'rejected') return $kind . ' · ' . ($review['kind'] === 'overtime' ? 'not approved' : 'no cut');
    return $kind . ' · ' . ($review['kind'] === 'overtime' ? 'approved +' : 'cut −') . paise_label($amount);
};

page_start($staff ? 'Employee salary' : 'My salary', 'employee-salary.php');
error_message($error);
?>
<div class="emp-page sal-page">
<header class="emp-header">
    <div>
        <p class="emp-crumb">Dashboard / Salary</p>
        <h2 class="emp-title"><?= $staff ? ($employee ? h($employee['full_name']) . ' · Salary' : 'Employee salary') : 'My salary' ?></h2>
        <p class="emp-sub">Day amount = monthly salary ÷ <?= SALARY_DAYS_PER_MONTH ?>. Leave days are cut, Sundays and holidays are paid. Duty <?= h(duty_hours_label($employee ? duty_hours((int) $employee['id']) : null)) ?> a day.</p>
    </div>
    <form method="get" class="sal-filter">
        <?php if ($staff): ?><label><span>Employee</span><select name="employee" onchange="this.form.submit()"><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $employeeId ? 'selected' : '' ?>><?= h($p['full_name']) ?> · <?= h($p['department_name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <label><span>Month</span><input type="month" name="month" value="<?= h($month) ?>" max="<?= date('Y-m') ?>" onchange="this.form.submit()"></label>
        <noscript><button class="st-btn" type="submit">View</button></noscript>
    </form>
</header>

<?php if (!$employee): ?>
    <section class="emp-card"><p class="muted">No active employees found.</p></section>
<?php elseif (!$calc['salary']): ?>
    <section class="emp-card"><h3>No salary set for <?= h($calc['first']->format('F Y')) ?></h3><p class="muted">The admin has not added a salary for this month yet.<?php if ($staff): ?> <a href="admin-add-employee.php?id=<?= (int) $employee['id'] ?>">Add salary in Edit employee</a>.<?php endif; ?></p></section>
<?php else: ?>
<section class="at-stats sal-stats">
    <div class="at-stat"><span>Monthly salary</span><strong><?= h(paise_label($calc['full_salary'])) ?></strong></div>
    <div class="at-stat"><span>Day amount (÷ <?= SALARY_DAYS_PER_MONTH ?>)</span><strong><?= h(paise_label($calc['rate'])) ?></strong></div>
    <div class="at-stat"><span>Present days</span><strong><?= $calc['present'] ?><small> + <?= $calc['paid_off'] ?> paid off</small></strong></div>
    <div class="at-stat is-absent"><span>Leave days (cut)</span><strong><?= $calc['leave'] ?></strong></div>
    <div class="at-stat is-primary"><span><?= $calc['is_current'] ? 'Salary so far this month' : 'Total salary' ?></span><strong><?= h(paise_label($calc['net'])) ?></strong></div>
</section>

<div class="sal-layout">
<section class="emp-card sal-breakdown">
    <h3>How the salary is calculated · <?= h($calc['first']->format('F Y')) ?></h3>
    <dl>
        <div><dt>Monthly salary<?= $calc['monthly'] !== $calc['full_salary'] ? ' <small>(only the days employed this month)</small>' : '' ?></dt><dd><?= h(paise_label($calc['monthly'])) ?></dd></div>
        <div class="is-cut"><dt>− Leave cut <small><?= $calc['leave'] ?> day<?= $calc['leave'] === 1 ? '' : 's' ?> × <?= h(paise_label($calc['rate'])) ?></small></dt><dd>−<?= h(paise_label($calc['leave_cut'])) ?></dd></div>
        <div class="is-add"><dt>+ Extra work approved by admin</dt><dd>+<?= h(paise_label($calc['overtime_add'])) ?></dd></div>
        <div class="is-cut"><dt>− Missed logout cut by admin</dt><dd>−<?= h(paise_label($calc['missed_cut'])) ?></dd></div>
        <div class="is-add"><dt>+ Amount added by admin <small>see the list below</small></dt><dd>+<?= h(paise_label($calc['manual_add'])) ?></dd></div>
        <div class="is-cut"><dt>− Amount cut by admin <small>see the list below</small></dt><dd>−<?= h(paise_label($calc['manual_cut'])) ?></dd></div>
        <?php if ($calc['benefits']): ?><div class="is-add"><dt>+ Benefits <small><?= h($calc['salary']['other_benefits']) ?></small></dt><dd>+<?= h(paise_label($calc['benefits'])) ?></dd></div><?php endif; ?>
        <div class="is-cut"><dt>− PF <small>12% of full salary <?= h(paise_label($calc['full_salary'])) ?></small></dt><dd>−<?= h(paise_label($calc['pf'])) ?></dd></div>
        <div class="is-cut"><dt>− ESI <small>0.75% of full salary <?= h(paise_label($calc['full_salary'])) ?></small></dt><dd>−<?= h(paise_label($calc['esi'])) ?></dd></div>
        <div class="is-total"><dt>Employee receives</dt><dd><?= h(paise_label($calc['net'])) ?></dd></div>
    </dl>
    <?php if ($calc['pending']): ?><p class="sal-note is-pending"><?= $calc['pending'] ?> day<?= $calc['pending'] === 1 ? ' is' : 's are' ?> waiting for admin approval. The amount changes after the admin decides.<?php if ($staff): ?> <a href="attendance-approvals.php">Open approvals →</a><?php endif; ?></p><?php endif; ?>
    <?php if ($calc['is_current']): ?><p class="sal-note">Remaining days of this month are counted as present. Each leave from now cuts <?= h(paise_label($calc['rate'])) ?>.</p><?php endif; ?>
</section>

<section class="emp-card sal-decisions">
    <h3>Managing Director decisions & remarks</h3>
    <?php $decisions = array_merge(...array_map(static fn($d) => $d['reviews'], $calc['days'])); ?>
    <?php if (!$decisions): ?><p class="muted">No extra work or missed logout this month.</p><?php endif; ?>
    <?php foreach ($decisions as $review): $amount = money_cents((string) $review['amount']); ?>
        <article class="sal-decision is-<?= h($review['status']) ?>">
            <div class="sal-decision-top"><strong><?= h(date('D, d M', strtotime($review['work_date']))) ?></strong><span class="sal-chip is-<?= h($review['status']) ?>"><?= $review['status'] === 'pending' ? 'Waiting' : ($review['status'] === 'rejected' ? ($review['kind'] === 'overtime' ? 'Not approved' : 'No cut') : ($review['kind'] === 'overtime' ? '+' : '−') . h(paise_label($amount))) ?></span></div>
            <p><?= $review['kind'] === 'overtime' ? 'Worked ' . h(work_duration_label((int) $review['worked_seconds'])) . ' (duty ' . h(duty_hours_label(duty_hours((int) $review['employee_id']))) . ')' : 'Logout was not recorded' ?></p>
            <?php if ($review['remark'] !== ''): ?><p class="sal-remark"><b>Remark:</b> <?= h($review['remark']) ?></p><?php endif; ?>
            <?php if ($review['reviewer_name']): ?><small><?= h($review['reviewer_name']) ?> · <?= h(date('d M, g:i A', strtotime($review['reviewed_at']))) ?></small><?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
</div>

<?php $adjustments = array_merge(...array_map(static fn($d) => $d['adjustments'], $calc['days'])); ?>
<section class="emp-card sal-adjust" id="adjustments">
    <div class="sal-adjust-head">
        <h3>Amount added / cut by admin · <?= h($calc['first']->format('F Y')) ?></h3>
        <span><b class="sal-money is-pos">+<?= h(paise_label($calc['manual_add'])) ?></b> · <b class="sal-money is-neg">−<?= h(paise_label($calc['manual_cut'])) ?></b></span>
    </div>
    <?php if ($staff): ?>
    <form method="post" class="sal-adjust-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_adjustment">
        <fieldset class="sal-kind"><legend class="sr-only">Add or cut</legend>
            <label class="is-add"><input type="radio" name="kind" value="add" required> + Add amount</label>
            <label class="is-cut"><input type="radio" name="kind" value="cut" required> − Cut amount</label>
        </fieldset>
        <label><span>Amount (₹)</span><input type="number" name="amount" min="0.01" step="0.01" max="999999.99" required placeholder="e.g. 500"></label>
        <label><span>Date</span><input type="date" name="adjustment_date" value="<?= h($calc['is_current'] ? today() : $calc['first']->modify('last day of this month')->format('Y-m-d')) ?>" min="<?= h($employee['joining_date']) ?>" max="<?= date('Y-m-t') ?>" required></label>
        <label class="sal-reason"><span>Reason (employee sees this)</span><input name="reason" maxlength="500" required placeholder="e.g. Diwali bonus / Advance taken / Late coming fine"></label>
        <button class="emp-btn emp-btn-dark" type="submit">Save</button>
    </form>
    <?php endif; ?>
    <?php if (!$adjustments): ?><p class="muted">No amount added or cut this month.</p><?php endif; ?>
    <div class="sal-adjust-list">
    <?php foreach (array_reverse($adjustments) as $adj): $amt = money_cents((string) $adj['amount']); $isAdd = $adj['kind'] === 'add'; ?>
        <article class="sal-adjust-item <?= $isAdd ? 'is-add' : 'is-cut' ?>">
            <strong class="sal-money <?= $isAdd ? 'is-pos' : 'is-neg' ?>"><?= $isAdd ? '+' : '−' ?><?= h(paise_label($amt)) ?></strong>
            <div><b><?= $isAdd ? 'Added to salary' : 'Cut from salary' ?></b> · <?= h(date('D, d M Y', strtotime($adj['adjustment_date']))) ?><p>Reason: <?= h($adj['reason']) ?></p><?php if ($staff): ?><small>By <?= h($adj['created_by_name'] ?? 'Admin') ?> · <?= h(date('d M, g:i A', strtotime($adj['created_at']))) ?></small><?php endif; ?></div>
            <?php if ($staff): ?><form method="post" onsubmit="return confirm('Remove this entry? The salary total will change back.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_adjustment"><input type="hidden" name="adjustment_id" value="<?= (int) $adj['id'] ?>"><button class="st-btn" type="submit">Remove</button></form><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<section class="emp-card">
    <h3>Day-wise salary</h3>
    <div class="table-scroll"><table class="sal-table">
        <thead><tr><th>Date</th><th>Status</th><th>Login</th><th>Logout</th><th>Worked</th><th>Day amount</th><th>Extra / cut</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($calc['days']) as $day): if (in_array($day['status'], ['upcoming', 'not_joined'], true)) continue; ?>
            <tr class="is-<?= h($day['status']) ?>">
                <td><strong><?= h(date('d M', strtotime($day['date']))) ?></strong> <small><?= h(date('D', strtotime($day['date']))) ?></small></td>
                <td><span class="sal-status is-<?= h($day['status']) ?>"><?= h($statusLabels[$day['status']]) ?></span><?php if ($day['label'] !== ''): ?><small class="sal-sub"><?= h($day['label']) ?></small><?php endif; ?></td>
                <td><?= $day['login'] ? h(date('g:i A', strtotime($day['login']))) : '—' ?></td>
                <td><?= $day['logout'] ? h(date('g:i A', strtotime($day['logout']))) : ($day['status'] === 'missed_logout' ? '<span class="sal-bad">Not done</span>' : '—') ?></td>
                <td><?= $day['worked'] ? h(work_duration_label($day['worked'])) : '—' ?></td>
                <td class="sal-money <?= $day['amount'] < 0 ? 'is-neg' : 'is-pos' ?>"><?= h($signed($day['amount'])) ?><?= $day['amount'] < 0 ? ' <small>cut</small>' : '' ?></td>
                <td class="sal-money <?= $day['adjust'] < 0 ? 'is-neg' : 'is-pos' ?>"><?= h($signed($day['adjust'])) ?></td>
                <td class="sal-note-cell"><?php foreach ($day['reviews'] as $review): ?><span><?= h($reviewText($review)) ?><?= $review['remark'] !== '' ? ' — “' . h($review['remark']) . '”' : '' ?></span><?php endforeach; ?><?php foreach ($day['adjustments'] as $adj): ?><span><?= $adj['kind'] === 'add' ? 'Admin added +' : 'Admin cut −' ?><?= h(paise_label(money_cents((string) $adj['amount']))) ?> — “<?= h($adj['reason']) ?>”</span><?php endforeach; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
</div>
<?php page_end(); ?>
