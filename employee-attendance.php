<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['admin','employee']);
$isAdmin=$user['role']==='admin';
if (!$isAdmin && isset($_GET['employee']) && (int)$_GET['employee']!==(int)$user['id']) fail(403,'You can only view your own attendance.');
$selectedEmployeeId=$isAdmin?(isset($_GET['employee'])?positive_id($_GET['employee']):null):(int)$user['id'];
if ($isAdmin && $selectedEmployeeId===null) {
    $directory=rows("SELECT u.id,u.full_name,ep.employee_code,d.name AS department_name,ep.joining_date,
        (SELECT MIN(a.login_at) FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND DATE(a.login_at)=CURDATE()) AS today_login,
        (SELECT MAX(a.logout_at) FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND DATE(a.login_at)=CURDATE()) AS today_logout
        FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id
        WHERE u.role='employee' AND u.deleted_at IS NULL ORDER BY u.full_name");
    page_start('Employee attendance','employee-attendance.php');
    ?><p class="muted">Select an employee to view day-by-day login, logout and leave records.</p>
    <section class="panel"><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Today's login</th><th>Today's logout</th><th></th></tr></thead><tbody>
    <?php if (!$directory) empty_row(6,'No employees found.'); foreach ($directory as $employee): ?>
    <tr><td><?= h($employee['full_name']) ?></td><td><?= h($employee['employee_code']??'—') ?></td><td><?= h($employee['department_name']) ?></td><td><?= $employee['today_login']?h(date('g:i A',strtotime($employee['today_login']))):'—' ?></td><td><?= $employee['today_logout']?h(date('g:i A',strtotime($employee['today_logout']))):'—' ?></td><td><a class="button secondary" href="employee-attendance.php?employee=<?= (int)$employee['id'] ?>&amp;month=<?= h(date('Y-m')) ?>">View attendance</a></td></tr>
    <?php endforeach; ?></tbody></table></div></section><?php page_end(); exit;
}
$attendanceEmployee=$isAdmin
    ?one("SELECT u.id,u.full_name,ep.joining_date,ep.department_id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL",[$selectedEmployeeId])
    :['id'=>$user['id'],'full_name'=>$user['full_name'],'joining_date'=>one('SELECT joining_date FROM employee_profiles WHERE user_id=?',[$user['id']])['joining_date']??null,'department_id'=>$user['department_id']];
if (!$attendanceEmployee || !$attendanceEmployee['joining_date']) fail(404,'Employee not found.');
$currentMonth=date('Y-m');
$month=$_GET['month']??$currentMonth;
if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/D',$month) || DateTimeImmutable::createFromFormat('!Y-m',$month)?->format('Y-m')!==$month || $month>$currentMonth) {
    fail(422,'Choose a valid attendance month.');
}
$first=new DateTimeImmutable($month.'-01');
$last=$first->modify('last day of this month');
$end=$last;
$joinDate=new DateTimeImmutable($attendanceEmployee['joining_date']);
$start=$first>$joinDate?$first:$joinDate;
$schedule=array_column(rows('SELECT iso_weekday,is_working_day FROM department_weekly_schedule WHERE department_id=?',[$attendanceEmployee['department_id']]),'is_working_day','iso_weekday');
$holidays=array_fill_keys(array_column(rows('SELECT holiday_date FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$start->format('Y-m-d'),$end->format('Y-m-d')]),'holiday_date'),true);
$sessions=rows("SELECT DATE(login_at) AS attendance_date,MIN(login_at) AS first_login,MAX(logout_at) AS last_logout,SUM(logout_at IS NULL) AS open_sessions FROM employee_attendance_sessions WHERE employee_id=? AND login_at>=? AND login_at<? GROUP BY DATE(login_at) ORDER BY attendance_date",[$attendanceEmployee['id'],$start->format('Y-m-d 00:00:00'),$end->modify('+1 day')->format('Y-m-d 00:00:00')]);
$sessionByDate=[];
foreach ($sessions as $session) $sessionByDate[$session['attendance_date']]=$session;
$leaveRequests=rows("SELECT l.from_date,l.to_date,l.status,t.name AS leave_type FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id WHERE l.employee_id=? AND l.status<>'cancelled' AND l.from_date<=? AND l.to_date>=? ORDER BY l.from_date,l.id",[$attendanceEmployee['id'],$end->format('Y-m-d'),$start->format('Y-m-d')]);
$leaveByDate=[];
foreach ($leaveRequests as $leave) {
    $from=max($start->format('Y-m-d'),$leave['from_date']);
    $to=min($end->format('Y-m-d'),$leave['to_date']);
    for ($day=new DateTimeImmutable($from);$day<=new DateTimeImmutable($to);$day=$day->modify('+1 day')) $leaveByDate[$day->format('Y-m-d')][]=$leave;
}
$dayRows=[];
$presentCount=0;
$leaveCount=0;
$pendingCount=0;
if ($start<=$end) {
    for ($day=$start;$day<=$end;$day=$day->modify('+1 day')) {
        $date=$day->format('Y-m-d');
        $session=$sessionByDate[$date]??null;
        $isWorking=(bool)($schedule[(int)$day->format('N')]??((int)$day->format('N')!==7)) && !isset($holidays[$date]);
        if ($date>today()) {
            $status='upcoming';
        } elseif ($session && $session['first_login'] && $session['last_logout'] && !(int)$session['open_sessions']) {
            $status='present';
        } elseif ($date<today() && $isWorking) {
            $status='leave';
        } elseif ($date===today() && $session && (int)$session['open_sessions']) {
            $status='pending';
        } elseif ($date===today()) {
            $status='pending';
        } elseif (!$isWorking) {
            $status=isset($holidays[$date])?'holiday':'weekly_off';
        } else {
            $status='leave';
        }
        if (in_array($status,['present','leave','pending'],true)) {
            query('INSERT INTO employee_attendance_days (employee_id,attendance_date,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',[$attendanceEmployee['id'],$date,$status]);
        }
        if ($status==='present') $presentCount++;
        if ($status==='leave') $leaveCount++;
        if ($status==='pending') $pendingCount++;
        $dayRows[]=['date'=>$date,'session'=>$session,'status'=>$status,'leaves'=>$leaveByDate[$date]??[]];
    }
}
$previousMonth=$first->modify('-1 month')->format('Y-m');
$nextMonth=$first->modify('+1 month')->format('Y-m');
page_start($isAdmin?$attendanceEmployee['full_name'].' · Attendance':'My attendance','employee-attendance.php');
?>
<?php if ($isAdmin): ?><p><a class="button secondary" href="employee-attendance.php">← All employees</a></p><?php endif; ?>
<p class="muted">Daily login/logout times, leave requests, and working days without a complete attendance record.</p>
<div class="stats-grid">
    <article class="stat-card"><h2>Present</h2><strong><?= $presentCount ?></strong><p>Login and logout recorded</p></article>
    <article class="stat-card"><h2>Leave</h2><strong><?= $leaveCount ?></strong><p>Past working days with missing login/logout</p></article>
    <article class="stat-card"><h2>Pending</h2><strong><?= $pendingCount ?></strong><p>Today is not marked as leave before the day ends</p></article>
</div>
<section class="panel">
    <div class="panel-heading"><h2>Attendance by day · <?= h($first->format('F Y')) ?></h2><div class="actions"><?php if ($month>$joinDate->format('Y-m')): ?><a class="button secondary" href="employee-attendance.php?month=<?= h($previousMonth) ?>">Previous month</a><?php endif; ?><?php if ($month<$currentMonth): ?><a class="button secondary" href="employee-attendance.php?month=<?= h($nextMonth) ?>">Next month</a><?php endif; ?></div></div>
    <div class="table-scroll"><table><thead><tr><th>Date</th><th>Login time</th><th>Logout time</th><th>Attendance</th><th>Leave details</th></tr></thead><tbody>
    <?php if (!$dayRows) { empty_row(5,'No attendance days in this month.'); } foreach (array_reverse($dayRows) as $row): $session=$row['session']; ?>
    <tr><td><?= h(date('D, d M Y',strtotime($row['date']))) ?></td><td><?= $session&&$session['first_login']?h(date('g:i A',strtotime($session['first_login']))):'—' ?></td><td><?= $session&&$session['last_logout']?h(date('g:i A',strtotime($session['last_logout']))):'—' ?></td><td><?php if ($row['status']==='present'): ?><?= status_badge('present') ?><?php elseif ($row['status']==='leave'): ?><?= status_badge('leave') ?><?php elseif ($row['status']==='pending'): ?><?= status_badge('pending') ?><?php elseif ($row['status']==='upcoming'): ?><span class="badge">Upcoming</span><?php elseif ($row['status']==='holiday'): ?><span class="badge">Holiday</span><?php else: ?><span class="badge">Weekly off</span><?php endif; ?></td><td><?php if ($row['leaves']): foreach ($row['leaves'] as $leave): ?><div><?= h($leave['leave_type']) ?> · <?= status_badge($leave['status']) ?></div><?php endforeach; elseif ($row['status']==='leave'): ?>No complete login/logout recorded<?php else: ?>—<?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p class="help">Past working days without a login or logout are saved and shown as Leave. Today stays Pending until completed. Sundays and published holidays are not treated as leave.</p>
</section>
<?php if (!$isAdmin): ?><p><a class="button secondary" href="apply-leaves.php">Apply for leave</a> <a class="button secondary" href="my-leave-requests.php">View leave requests</a></p><?php endif; ?>
<?php page_end(); ?>
