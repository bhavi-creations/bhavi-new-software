<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(['employee']); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='cancel_leave') {
            $changed=query("UPDATE leave_requests SET status='cancelled' WHERE id=? AND employee_id=? AND status='pending'",[positive_id($_POST['id']??null),$user['id']])->rowCount();
            if (!$changed) throw new InvalidArgumentException('Only your pending leave requests can be cancelled.');
            flash('Leave request cancelled.'); redirect('my-leave-requests.php');
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
        db()->commit(); flash('Leave request sent to your manager.'); redirect('my-leave-requests.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
page_start('Apply leave','apply-leaves.php'); error_message($error);
?><section class="panel"><h2>New leave request</h2><form method="post"><?= csrf_field() ?><div class="fields"><div class="field full"><label for="leave_type_id">Leave type</label><select id="leave_type_id" name="leave_type_id" required><?= options(rows('SELECT * FROM leave_types ORDER BY id'),'name',$_POST['leave_type_id']??null) ?></select></div><div class="field"><label for="from_date">From date</label><input type="date" id="from_date" name="from_date" value="<?= h($_POST['from_date']??today()) ?>" min="<?= today() ?>" required></div><div class="field"><label for="to_date">To date</label><input type="date" id="to_date" name="to_date" value="<?= h($_POST['to_date']??today()) ?>" min="<?= today() ?>" required></div><div class="field full"><label for="reason">Reason</label><textarea id="reason" name="reason" maxlength="3000" required><?= h($_POST['reason']??'') ?></textarea><p class="help">Working days exclude Sundays and published holidays.</p></div></div><button class="primary" type="submit">Submit request</button></form></section>
<?php page_end(); ?>
