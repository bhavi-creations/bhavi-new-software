<?php
require_once __DIR__ . '/layout.php'; require_once __DIR__ . '/calendar.php';
$user=require_roles(['employee']); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='cancel_leave') {
            $changed=query("UPDATE leave_requests SET status='cancelled' WHERE id=? AND employee_id=? AND status='pending'",[positive_id($_POST['id']??null),$user['id']])->rowCount();
            if (!$changed) throw new InvalidArgumentException('Only your pending leave requests can be cancelled.');
            flash('Leave request cancelled.'); redirect('apply-leaves.php#leave-requests');
        }
        $type=positive_id($_POST['leave_type_id']??null);
        if (!one('SELECT id FROM leave_types WHERE id=?',[$type])) throw new InvalidArgumentException('Choose a valid leave type.');
        $from=date_input('from_date'); $to=date_input('to_date'); $reason=text_input('reason',3000);
        if ($from<today() || $to<$from) throw new InvalidArgumentException('Choose today or a future start date, and an end date on or after it.');
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366) throw new InvalidArgumentException('A leave request can cover up to one year.');
        $holidays=array_column(rows('SELECT holiday_date FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$from,$to]),'holiday_date');
        $schedule=array_column(rows('SELECT iso_weekday,is_working_day FROM department_weekly_schedule WHERE department_id=?',[$user['department_id']]),'is_working_day','iso_weekday');
        $days=0;
        for ($date=new DateTimeImmutable($from);$date<=new DateTimeImmutable($to);$date=$date->modify('+1 day')) {
            $weekday=(int)$date->format('N');
            if (($schedule[$weekday]??($weekday!==7)) && !in_array($date->format('Y-m-d'),$holidays,true)) $days++;
        }
        if (!$days) throw new InvalidArgumentException('The selected dates contain no working days. Sundays and published holidays are excluded.');
        db()->beginTransaction();
        query('SELECT user_id FROM employee_profiles WHERE user_id=? FOR UPDATE',[$user['id']]);
        if (count_value("SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status IN ('pending','approved') AND from_date<=? AND to_date>=?",[$user['id'],$to,$from])) throw new InvalidArgumentException('These dates overlap one of your pending or approved requests.');
        query('INSERT INTO leave_requests (employee_id,leave_type_id,from_date,to_date,working_days,reason) VALUES (?,?,?,?,?,?)',[$user['id'],$type,$from,$to,$days,$reason]);
        $leaveId=(int)db()->lastInsertId();
        notify_staff('leave_applied',$user['full_name'].' requested leave',$from.' to '.$to,null,$leaveId);
        db()->commit(); flash('Leave request sent to your manager.'); redirect('apply-leaves.php#leave-requests');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
page_start('Apply leave','apply-leaves.php'); error_message($error);
$month=calendar_month(); $first=$month.'-01'; $last=(new DateTimeImmutable($first))->format('Y-m-t');
$leaveRequests=rows('SELECT l.*,t.name AS leave_type FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id WHERE l.employee_id=? ORDER BY l.applied_at DESC,l.id DESC',[$user['id']]);
$events=[]; foreach (rows('SELECT holiday_name,holiday_date FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$first,$last]) as $holiday) $events[$holiday['holiday_date']][]=['label'=>$holiday['holiday_name'],'class'=>'holiday-event'];
foreach (rows("SELECT l.from_date,l.to_date,l.status,t.name AS leave_type FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id WHERE l.employee_id=? AND l.from_date<=? AND l.to_date>=? AND l.status<>'cancelled'",[$user['id'],$last,$first]) as $leave) for ($day=new DateTimeImmutable(max($first,$leave['from_date']));$day<=new DateTimeImmutable(min($last,$leave['to_date']));$day=$day->modify('+1 day')) $events[$day->format('Y-m-d')][]=['label'=>$leave['leave_type'].' · '.$leave['status'],'class'=>'status-'.$leave['status']];
?><section class="panel"><h2>New leave request</h2><form method="post"><?= csrf_field() ?><div class="fields"><div class="field full"><label for="leave_type_id">Leave type</label><select id="leave_type_id" name="leave_type_id" required><?= options(rows('SELECT * FROM leave_types ORDER BY id'),'name',$_POST['leave_type_id']??null) ?></select></div><div class="field"><label for="from_date">From date</label><input type="date" id="from_date" name="from_date" value="<?= h($_POST['from_date']??today()) ?>" min="<?= today() ?>" required></div><div class="field"><label for="to_date">To date</label><input type="date" id="to_date" name="to_date" value="<?= h($_POST['to_date']??today()) ?>" min="<?= today() ?>" required></div><div class="field full"><label for="reason">Reason</label><textarea id="reason" name="reason" maxlength="3000" required><?= h($_POST['reason']??'') ?></textarea><p class="help">Working days exclude Sundays and published holidays.</p></div></div><button class="primary" type="submit">Submit request</button></form></section>
<section class="panel" id="leave-calendar"><div class="panel-heading"><h2>My leave calendar</h2><span class="muted">Approved, pending, and holiday dates</span></div><?php render_calendar($month,$events); ?></section>
<section class="panel" id="leave-requests"><h2>My leave requests</h2><div class="table-scroll"><table><thead><tr><th>Dates</th><th>Type</th><th>Working days</th><th>Status</th><th>Manager message</th><th>Action</th></tr></thead><tbody><?php if (!$leaveRequests) empty_row(6,'No leave requests yet.'); foreach ($leaveRequests as $leave): ?><tr><td><?= h(date('d-m-Y',strtotime($leave['from_date']))) ?> — <?= h(date('d-m-Y',strtotime($leave['to_date']))) ?></td><td><?= h($leave['leave_type']) ?></td><td><?= h($leave['working_days']) ?></td><td><?= status_badge($leave['status']) ?></td><td class="wrap"><?= h($leave['decision_note']?:'No message yet') ?></td><td><?php if ($leave['status']==='pending'): ?><form method="post" class="inline-form" data-confirm="Cancel this leave request?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_leave"><input type="hidden" name="id" value="<?= (int)$leave['id'] ?>"><button class="button secondary">Cancel</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php page_end(); ?>
