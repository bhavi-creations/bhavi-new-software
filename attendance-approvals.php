<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/salary-calc.php';
$user = require_roles(['admin', 'manager']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $reviewId = positive_id($_POST['review_id'] ?? null);
        $decision = (string) ($_POST['decision'] ?? '');
        if (!in_array($decision, ['approve', 'reject'], true)) throw new InvalidArgumentException('Choose Approve or Reject.');
        $remark = text_input('remark', 500, true);
        db()->beginTransaction();
        $review = one("SELECT r.*,u.full_name FROM attendance_reviews r JOIN users u ON u.id=r.employee_id WHERE r.id=? AND r.status='pending' FOR UPDATE", [$reviewId]);
        if (!$review) throw new InvalidArgumentException('This request was already decided. Refresh the page.');
        $amount = 0;
        if ($decision === 'approve') {
            $raw = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
            $amount = money_cents($raw === '' ? '0' : $raw);
            if ($review['kind'] === 'overtime' && $amount <= 0) throw new InvalidArgumentException('Enter the amount to add for the extra work, or choose Reject.');
            if ($amount > 99999999) throw new InvalidArgumentException('Amount is too large.');
        }
        query("UPDATE attendance_reviews SET status=?,amount=?,remark=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?", [$decision === 'approve' ? 'approved' : 'rejected', decimal_money($amount), $remark, $user['id'], $reviewId]);
        $dateLabel = date('d M Y', strtotime($review['work_date']));
        if ($review['kind'] === 'overtime') {
            $title = $decision === 'approve' ? 'Extra work approved · ' . paise_label($amount) . ' added' : 'Extra work not approved';
        } else {
            $title = ($decision === 'approve' && $amount > 0) ? 'Missed logout · ' . paise_label($amount) . ' cut' : 'Missed logout · no cut';
        }
        query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$review['employee_id'], $user['id'], $title, $dateLabel . ' — Remark: ' . $remark]);
        db()->commit();
        flash($review['full_name'] . ' · ' . $dateLabel . ': ' . $title . '.');
        redirect('attendance-approvals.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = mutation_error($e);
    }
}

attendance_sync_reviews();
$dutySeconds = (int) round(duty_hours() * 3600);
$attendanceSelect = "(SELECT MIN(a.login_at) FROM employee_attendance_sessions a WHERE a.employee_id=r.employee_id AND a.login_at>=r.work_date AND a.login_at<r.work_date+INTERVAL 1 DAY) AS first_login,
    (SELECT MAX(a.logout_at) FROM employee_attendance_sessions a WHERE a.employee_id=r.employee_id AND a.login_at>=r.work_date AND a.login_at<r.work_date+INTERVAL 1 DAY) AS last_logout,
    (SELECT s.amount FROM employee_salary_history s WHERE s.employee_id=r.employee_id AND s.effective_from<=r.work_date AND (s.effective_to IS NULL OR s.effective_to>=r.work_date) ORDER BY s.effective_from DESC LIMIT 1) AS salary_amount";
$pending = rows("SELECT r.*,u.full_name,d.name AS department_name,$attendanceSelect FROM attendance_reviews r JOIN users u ON u.id=r.employee_id JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE r.status='pending' AND u.deleted_at IS NULL ORDER BY r.work_date,u.full_name");
$decided = rows("SELECT r.*,u.full_name,rv.full_name AS reviewer_name FROM attendance_reviews r JOIN users u ON u.id=r.employee_id LEFT JOIN users rv ON rv.id=r.reviewed_by WHERE r.status<>'pending' AND r.work_date>=CURDATE()-INTERVAL 62 DAY ORDER BY r.reviewed_at DESC LIMIT 100");

page_start('Attendance approvals', 'attendance-approvals.php');
error_message($error);
?>
<div class="emp-page sal-page">
<header class="emp-header">
    <div>
        <p class="emp-crumb">Dashboard / Attendance approvals</p>
        <h2 class="emp-title">Attendance approvals</h2>
        <p class="emp-sub">Extra work (more than <?= h(duty_hours_label()) ?>) and missed logouts. Add or cut an amount, write a remark and submit — the employee sees it on their salary page.</p>
    </div>
    <a class="emp-btn emp-btn-outline" href="employee-salary.php"><?= icon('money') ?> Employee salary</a>
</header>

<section class="emp-card">
    <h3>Waiting for approval · <?= count($pending) ?></h3>
    <?php if (!$pending): ?><p class="muted">Nothing to approve right now.</p><?php endif; ?>
    <div class="sal-approvals">
    <?php foreach ($pending as $r):
        $rate = $r['salary_amount'] !== null ? (int) round(money_cents((string) $r['salary_amount']) / SALARY_DAYS_PER_MONTH) : 0;
        $isOvertime = $r['kind'] === 'overtime';
        $extra = max(0, (int) $r['worked_seconds'] - $dutySeconds);
        $suggest = $isOvertime ? overtime_suggestion($rate, (int) $r['worked_seconds']) : 0;
    ?>
        <article class="sal-approval <?= $isOvertime ? 'is-overtime' : 'is-missed' ?>">
            <div class="sal-approval-head">
                <div><strong><?= h($r['full_name']) ?></strong><small><?= h($r['department_name']) ?> · <?= h(date('D, d M Y', strtotime($r['work_date']))) ?></small></div>
                <span class="sal-chip <?= $isOvertime ? 'is-approved' : 'is-rejected' ?>"><?= $isOvertime ? 'Extra work' : 'Missed logout' ?></span>
            </div>
            <dl class="sal-facts">
                <div><dt>Login</dt><dd><?= $r['first_login'] ? h(date('g:i A', strtotime($r['first_login']))) : '—' ?></dd></div>
                <div><dt>Logout</dt><dd><?= $isOvertime && $r['last_logout'] ? h(date('g:i A', strtotime($r['last_logout']))) : '<span class="sal-bad">Not done</span>' ?></dd></div>
                <div><dt>Worked</dt><dd><?= $isOvertime ? h(work_duration_label((int) $r['worked_seconds'])) : '—' ?></dd></div>
                <div><dt><?= $isOvertime ? 'Extra time' : 'Duty' ?></dt><dd><?= $isOvertime ? h(work_duration_label($extra)) : h(duty_hours_label()) ?></dd></div>
                <div><dt>Day amount</dt><dd><?= $rate ? h(paise_label($rate)) : 'No salary' ?></dd></div>
            </dl>
            <form method="post" class="sal-approval-form"><?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                <label><span><?= $isOvertime ? 'Amount to add (₹)' : 'Amount to cut (₹)' ?></span><input type="number" name="amount" min="0" step="0.01" max="999999.99" value="<?= $isOvertime && $suggest ? h(decimal_money($suggest)) : '' ?>" placeholder="<?= $isOvertime ? 'e.g. 120' : 'e.g. ' . h(decimal_money((int) (round($rate / 200) * 100))) ?>"></label>
                <label class="sal-remark-field"><span>Remark</span><input name="remark" maxlength="500" required placeholder="<?= $isOvertime ? 'e.g. Worked late for client delivery' : 'e.g. Forgot to logout — half day cut' ?>"></label>
                <div class="sal-approval-actions">
                    <button class="emp-btn emp-btn-dark emp-btn-sm" type="submit" name="decision" value="approve"><?= $isOvertime ? 'Approve & add' : 'Cut amount' ?></button>
                    <button class="emp-btn emp-btn-outline emp-btn-sm" type="submit" name="decision" value="reject"><?= $isOvertime ? 'Reject' : 'No cut' ?></button>
                </div>
                <?php if ($isOvertime && $suggest): ?><small class="sal-hint">Suggested: <?= h(paise_label($rate)) ?> ÷ <?= h(duty_hours_label()) ?> × <?= h(work_duration_label($extra)) ?> extra. Change it if needed.</small><?php endif; ?>
            </form>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<section class="emp-card">
    <h3>Decided · last 2 months</h3>
    <div class="table-scroll"><table class="sal-table">
        <thead><tr><th>Date</th><th>Employee</th><th>Type</th><th>Decision</th><th>Amount</th><th>Remark</th><th>By</th></tr></thead>
        <tbody>
        <?php if (!$decided) empty_row(7, 'No decisions yet.'); foreach ($decided as $r): $amount = money_cents((string) $r['amount']); $isOvertime = $r['kind'] === 'overtime'; ?>
            <tr>
                <td><?= h(date('d M Y', strtotime($r['work_date']))) ?></td>
                <td><a href="employee-salary.php?employee=<?= (int) $r['employee_id'] ?>&amp;month=<?= h(substr($r['work_date'], 0, 7)) ?>"><?= h($r['full_name']) ?></a></td>
                <td><?= $isOvertime ? 'Extra work · ' . h(work_duration_label((int) $r['worked_seconds'])) : 'Missed logout' ?></td>
                <td><span class="sal-chip is-<?= h($r['status']) ?>"><?= $r['status'] === 'approved' ? ($isOvertime ? 'Approved' : 'Cut') : ($isOvertime ? 'Rejected' : 'No cut') ?></span></td>
                <td class="sal-money <?= $isOvertime ? 'is-pos' : 'is-neg' ?>"><?= $amount ? ($isOvertime ? '+' : '−') . h(paise_label($amount)) : '—' ?></td>
                <td><?= h($r['remark']) ?></td>
                <td><?= h($r['reviewer_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
</div>
<?php page_end(); ?>
