<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['admin','employee']);
function attendance_duration_label($seconds): string
{
    $minutes=max(0,(int)floor(((int)$seconds)/60));
    return intdiv($minutes,60).'h '.str_pad((string)($minutes%60),2,'0',STR_PAD_LEFT).'m';
}
$isAdmin=$user['role']==='admin';
if (!$isAdmin && isset($_GET['employee']) && (int)$_GET['employee']!==(int)$user['id']) fail(403,'You can only view your own attendance.');
$selectedEmployeeId=$isAdmin?(isset($_GET['employee'])?positive_id($_GET['employee']):null):(int)$user['id'];
if ($isAdmin && $selectedEmployeeId===null) {
    $directory=rows("SELECT u.id,u.full_name,ep.employee_code,d.name AS department_name,ep.joining_date,
        (SELECT MIN(a.login_at) FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND DATE(a.login_at)=CURDATE()) AS today_login,
        (SELECT CASE WHEN SUM(a.logout_at IS NULL)>0 THEN NULL ELSE MAX(a.logout_at) END FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND DATE(a.login_at)=CURDATE()) AS today_logout,
        (SELECT SUM(TIMESTAMPDIFF(SECOND,a.login_at,COALESCE(a.logout_at,NOW()))) FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND DATE(a.login_at)=CURDATE()) AS today_seconds
        FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id
        WHERE u.role='employee' AND u.deleted_at IS NULL ORDER BY u.full_name");
    page_start('Employee attendance','employee-attendance.php');
    ?><p class="muted">Select an employee to view the color-coded monthly attendance calendar.</p>
    <section class="panel"><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Today's login</th><th>Today's logout</th><th>Total hours today</th><th></th></tr></thead><tbody>
    <?php if (!$directory) empty_row(7,'No employees found.'); foreach ($directory as $employee): $todayLate=$employee['today_login']&&date('H:i:s',strtotime($employee['today_login']))>'09:30:00'; ?>
    <tr><td><?= h($employee['full_name']) ?></td><td><?= h($employee['employee_code']??'—') ?></td><td><?= h($employee['department_name']) ?></td><td style="color:<?= $todayLate?'#b42318':'#16803c' ?>;font-weight:700"><?= $employee['today_login']?h(date('g:i A',strtotime($employee['today_login']))).($todayLate?' · Late':''):'—' ?></td><td><?= $employee['today_logout']?h(date('g:i A',strtotime($employee['today_logout']))):'—' ?></td><td><?= $employee['today_seconds']!==null?attendance_duration_label($employee['today_seconds']):'—' ?></td><td><a class="button secondary" href="employee-attendance.php?employee=<?= (int)$employee['id'] ?>&amp;month=<?= h(date('Y-m',strtotime('first day of this month -1 month'))) ?>">View attendance</a></td></tr>
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
$start=$first;
$schedule=array_column(rows('SELECT iso_weekday,is_working_day FROM department_weekly_schedule WHERE department_id=?',[$attendanceEmployee['department_id']]),'is_working_day','iso_weekday');
$holidays=array_fill_keys(array_column(rows('SELECT holiday_date FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$start->format('Y-m-d'),$end->format('Y-m-d')]),'holiday_date'),true);
$sessions=rows("SELECT DATE(login_at) AS attendance_date,MIN(login_at) AS first_login,CASE WHEN SUM(logout_at IS NULL)>0 THEN NULL ELSE MAX(logout_at) END AS last_logout,SUM(logout_at IS NULL) AS open_sessions,SUM(TIMESTAMPDIFF(SECOND,login_at,COALESCE(logout_at,IF(DATE(login_at)=CURDATE(),NOW(),DATE(login_at)+INTERVAL 1 DAY)))) AS total_seconds FROM employee_attendance_sessions WHERE employee_id=? AND login_at>=? AND login_at<? GROUP BY DATE(login_at) ORDER BY attendance_date",[$attendanceEmployee['id'],$start->format('Y-m-d 00:00:00'),$end->modify('+1 day')->format('Y-m-d 00:00:00')]);
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
        if ($date<$joinDate->format('Y-m-d')) {
            $status='not_joined';
        } elseif ($date>today()) {
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
<p class="muted">Daily login/logout times, total hours worked, leave requests, and working days without a complete attendance record.</p>
<div class="stats-grid">
    <article class="stat-card"><h2>Present</h2><strong><?= $presentCount ?></strong><p>Login and logout recorded</p></article>
    <article class="stat-card"><h2>Leave</h2><strong><?= $leaveCount ?></strong><p>Past working days with missing login/logout</p></article>
    <article class="stat-card"><h2>Pending</h2><strong><?= $pendingCount ?></strong><p>Today is not marked as leave before the day ends</p></article>
</div>
<section class="panel">
    <div class="panel-heading"><h2>Attendance calendar · <?= h($first->format('F Y')) ?></h2><form method="get" class="filters" style="margin:0"><?php if ($isAdmin): ?><input type="hidden" name="employee" value="<?= (int)$attendanceEmployee['id'] ?>"><?php endif; ?><div class="field"><label for="month">Month and year</label><input type="month" id="month" name="month" value="<?= h($month) ?>" max="<?= h($currentMonth) ?>" required></div><button class="primary" type="submit">View</button></form></div>
    <style>.attendance-calendar{display:grid;grid-template-columns:repeat(7,minmax(100px,1fr));gap:7px}.attendance-calendar-head{font-weight:700;text-align:center;padding:8px;background:#eef2f7}.attendance-calendar-day{min-height:112px;padding:9px;border-radius:10px;background:#f2f4f7;border:1px solid #e4e7ec}.attendance-calendar-day strong{display:block;margin-bottom:7px}.attendance-calendar-day span{display:block;font-size:12px;margin-top:3px}.attendance-calendar-day.present{background:#dcfce7;border-color:#86efac;color:#166534}.attendance-calendar-day.late{background:#fee2e2;border-color:#fca5a5;color:#991b1b}.attendance-calendar-day.leave{background:#ffedd5;border-color:#fdba74;color:#9a3412}.attendance-calendar-day.permission{background:#f3e8ff;border-color:#d8b4fe;color:#6b21a8}.attendance-calendar-day.holiday{background:#dbeafe;border-color:#93c5fd;color:#1e40af}.attendance-calendar-day.pending{background:#fef9c3;border-color:#fde047;color:#854d0e}.attendance-calendar-day.upcoming{background:#f8fafc}.attendance-legend{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}.attendance-legend span{padding:5px 10px;border-radius:99px;background:#eee}@media(max-width:800px){.attendance-calendar{grid-template-columns:repeat(2,minmax(120px,1fr))}.attendance-calendar-head{display:none}}</style>
    <div class="attendance-legend"><span class="present">On time</span><span class="late">Late login (after 9:30 AM)</span><span class="leave">Leave</span><span class="permission">Permission</span><span class="holiday">Holiday</span><span class="pending">Pending</span></div>
    <div class="attendance-calendar"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?><div class="attendance-calendar-head"><?= $weekday ?></div><?php endforeach; for ($blank=1;$blank<(int)$first->format('N');$blank++): ?><div></div><?php endfor; foreach ($dayRows as $row): $session=$row['session']; $hasLogin=$session&&$session['first_login']; $late=$hasLogin&&date('H:i:s',strtotime($session['first_login']))>'09:30:00'; $hasPermission=false; foreach ($row['leaves'] as $leave) if (stripos($leave['leave_type'],'permission')!==false) $hasPermission=true; $class=$hasPermission?'permission':($late?'late':($hasLogin?'present':$row['status'])); ?><article class="attendance-calendar-day <?= h($class) ?>"><strong><?= h(date('d-m-Y',strtotime($row['date']))) ?></strong><span><?= $hasPermission?'Permission':($late?'Late login':($hasLogin?'On time':ucfirst(str_replace('_',' ',$row['status'])))) ?></span><?php if ($session&&$session['first_login']): ?><span>Login <?= h(date('g:i A',strtotime($session['first_login']))) ?></span><?php endif; if ($session&&$session['last_logout']): ?><span>Logout <?= h(date('g:i A',strtotime($session['last_logout']))) ?></span><?php endif; foreach ($row['leaves'] as $leave): ?><span><?= h($leave['leave_type']) ?> · <?= h(ucfirst($leave['status'])) ?></span><?php endforeach; ?></article><?php endforeach; ?></div>
    <p class="help">Login at 9:30 AM or earlier is on time. A login after 9:30 AM appears red for the administrator. Past working days without a complete login/logout are shown as leave.</p>
</section>
<?php if (!$isAdmin): ?><p><a class="button secondary" href="apply-leaves.php">Apply for leave</a> <a class="button secondary" href="my-leave-requests.php">View leave requests</a></p><?php endif; ?>
<?php page_end(); ?>
