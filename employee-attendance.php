<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/salary-calc.php';
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
    $officeTime='09:30:00';
    $workTargetSeconds=9*3600;
    $initials=static function(string $name): string { $o=''; foreach (array_slice(preg_split('/\s+/',trim($name))?:[],0,2) as $p) $o.=mb_strtoupper(mb_substr($p,0,1)); return $o?:'?'; };
    $durationLabel=static function(int $seconds): string { $m=max(0,intdiv($seconds,60)); return intdiv($m,60).'h '.($m%60).'m'; };
    $viewDate=$_GET['date']??today();
    if (!is_string($viewDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$viewDate) || DateTimeImmutable::createFromFormat('!Y-m-d',$viewDate)?->format('Y-m-d')!==$viewDate || $viewDate>today()) fail(422,'Choose a valid attendance date.');

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf();
        $markEmployee=positive_id($_POST['employee_id']??null);
        $markAction=$_POST['mark']??'';
        if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL",[$markEmployee])) fail(404,'Employee not found.');
        if ($markAction==='clear') {
            query('UPDATE employee_attendance_days SET marked_status=NULL,marked_by=NULL WHERE employee_id=? AND attendance_date=?',[$markEmployee,$viewDate]);
            flash('Attendance mark removed.');
        } elseif (in_array($markAction,['leave','absent'],true)) {
            query("INSERT INTO employee_attendance_days (employee_id,attendance_date,status,marked_status,marked_by) VALUES (?,?,'leave',?,?) ON DUPLICATE KEY UPDATE marked_status=VALUES(marked_status),marked_by=VALUES(marked_by)",[$markEmployee,$viewDate,$markAction,$user['id']]);
            flash($markAction==='leave'?'Marked as leave.':'Marked as absent.');
        } else fail(422,'Choose a valid attendance action.');
        redirect('employee-attendance.php?'.http_build_query(array_filter(['date'=>$viewDate===today()?null:$viewDate,'cal'=>(int)($_POST['cal']??0)?:null])));
    }

    $directory=rows("SELECT u.id,u.full_name,ep.employee_code,ep.department_id,ep.joining_date,d.name AS department_name,
        (SELECT MIN(a.login_at) FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND a.login_at>=? AND a.login_at<?+INTERVAL 1 DAY) AS day_login,
        (SELECT CASE WHEN SUM(a.logout_at IS NULL)>0 THEN NULL ELSE MAX(a.logout_at) END FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND a.login_at>=? AND a.login_at<?+INTERVAL 1 DAY) AS day_logout,
        (SELECT SUM(TIMESTAMPDIFF(SECOND,a.login_at,COALESCE(a.logout_at,IF(DATE(a.login_at)=CURDATE(),NOW(),DATE(a.login_at)+INTERVAL 1 DAY)))) FROM employee_attendance_sessions a WHERE a.employee_id=u.id AND a.login_at>=? AND a.login_at<?+INTERVAL 1 DAY) AS day_seconds,
        (SELECT marked_status FROM employee_attendance_days ad WHERE ad.employee_id=u.id AND ad.attendance_date=?) AS marked_status,
        (SELECT CONCAT(t.name,' · ',l.status) FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id WHERE l.employee_id=u.id AND l.status IN ('pending','approved') AND ? BETWEEN l.from_date AND l.to_date ORDER BY l.status='approved' DESC LIMIT 1) AS leave_info
        FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id
        WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name",
        [$viewDate,$viewDate,$viewDate,$viewDate,$viewDate,$viewDate,$viewDate,$viewDate]);

    $stats=['logged'=>0,'on_time'=>0,'late'=>0,'absent'=>0,'logged_out'=>0];
    foreach ($directory as &$employee) {
        $login=$employee['day_login'];
        $lateSeconds=$login?max(0,strtotime($login)-strtotime($viewDate.' '.$officeTime)):0;
        $employee['state']=$login?($lateSeconds>0?'late':'on_time'):'absent';
        $employee['late_seconds']=$lateSeconds;
        if ($login) { $stats['logged']++; $stats[$employee['state']]++; if ($employee['day_logout']) $stats['logged_out']++; }
        else $stats['absent']++;
    }
    unset($employee);
    usort($directory,static fn($a,$b)=>[$a['day_login']?0:1,$a['day_login']??'',$a['full_name']]<=>[$b['day_login']?0:1,$b['day_login']??'',$b['full_name']]);

    // Calendar for the selected employee (right panel)
    $calId=(int)($_GET['cal']??0);
    $calEmployee=null;
    foreach ($directory as $employee) if ((int)$employee['id']===$calId) $calEmployee=$employee;
    if (!$calEmployee && $directory) { $calEmployee=$directory[0]; $calId=(int)$calEmployee['id']; }
    $calMonth=$_GET['cal_month']??substr($viewDate,0,7);
    if (!is_string($calMonth) || !preg_match('/^\d{4}-\d{2}$/D',$calMonth) || $calMonth>date('Y-m')) $calMonth=substr($viewDate,0,7);
    $calFirst=new DateTimeImmutable($calMonth.'-01'); $calLast=$calFirst->modify('last day of this month');
    $calDays=[]; $calCounts=['present'=>0,'late'=>0,'absent'=>0,'leave'=>0];
    if ($calEmployee) {
        $schedule=array_column(rows('SELECT iso_weekday,is_working_day FROM department_weekly_schedule WHERE department_id=?',[$calEmployee['department_id']]),'is_working_day','iso_weekday');
        $holidays=array_fill_keys(array_column(rows('SELECT holiday_date FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$calFirst->format('Y-m-d'),$calLast->format('Y-m-d')]),'holiday_date'),true);
        $logins=array_column(rows('SELECT DATE(login_at) AS d,MIN(login_at) AS first_login FROM employee_attendance_sessions WHERE employee_id=? AND login_at>=? AND login_at<? GROUP BY DATE(login_at)',[$calId,$calFirst->format('Y-m-d'),$calLast->modify('+1 day')->format('Y-m-d')]),'first_login','d');
        $marks=array_column(rows('SELECT attendance_date,marked_status FROM employee_attendance_days WHERE employee_id=? AND attendance_date BETWEEN ? AND ? AND marked_status IS NOT NULL',[$calId,$calFirst->format('Y-m-d'),$calLast->format('Y-m-d')]),'marked_status','attendance_date');
        $leaveDays=[];
        foreach (rows("SELECT from_date,to_date FROM leave_requests WHERE employee_id=? AND status='approved' AND from_date<=? AND to_date>=?",[$calId,$calLast->format('Y-m-d'),$calFirst->format('Y-m-d')]) as $leave)
            for ($d=new DateTimeImmutable(max($leave['from_date'],$calFirst->format('Y-m-d')));$d<=new DateTimeImmutable(min($leave['to_date'],$calLast->format('Y-m-d')));$d=$d->modify('+1 day')) $leaveDays[$d->format('Y-m-d')]=true;
        for ($d=$calFirst;$d<=$calLast;$d=$d->modify('+1 day')) {
            $date=$d->format('Y-m-d');
            $working=(bool)($schedule[(int)$d->format('N')]??((int)$d->format('N')!==7)) && !isset($holidays[$date]);
            if (isset($logins[$date])) $status=strtotime($logins[$date])>strtotime($date.' '.$officeTime)?'late':'present';
            elseif (($marks[$date]??'')==='leave' || isset($leaveDays[$date])) $status='leave';
            elseif (($marks[$date]??'')==='absent') $status='absent';
            elseif (!$working) $status='off';
            elseif ($date<$calEmployee['joining_date'] || $date>=today()) $status='none';
            else $status='absent';
            if (isset($calCounts[$status])) $calCounts[$status]++;
            $calDays[]=['date'=>$date,'day'=>(int)$d->format('j'),'status'=>$status];
        }
    }
    $prevDate=(new DateTimeImmutable($viewDate))->modify('-1 day')->format('Y-m-d');
    $nextDate=(new DateTimeImmutable($viewDate))->modify('+1 day')->format('Y-m-d');
    $link=static fn(array $q)=>'employee-attendance.php?'.http_build_query(array_filter($q+['date'=>$viewDate===today()?null:$viewDate,'cal'=>$calId?:null]));
    page_start('Employee attendance','employee-attendance.php');
    ?>
<div class="emp-page at-page" data-at-page>
<header class="emp-header">
    <div><p class="emp-crumb">Dashboard / Attendance</p><h2 class="emp-title">Employee attendance</h2><p class="emp-sub">Office time <?= h(date('g:i A',strtotime($officeTime))) ?> · <?= $viewDate===today()?'Live status for today':'Attendance for '.h(date('d M Y',strtotime($viewDate))) ?></p></div>
    <div class="st-head-actions">
        <div class="at-date-nav"><a href="<?= h($link(['date'=>$prevDate])) ?>" aria-label="Previous day">‹</a><span><?= $viewDate===today()?'Today · ':'' ?><?= h(date('D, d M Y',strtotime($viewDate))) ?></span><?php if ($viewDate<today()): ?><a href="<?= h($link(['date'=>$nextDate===today()?null:$nextDate])) ?>" aria-label="Next day">›</a><?php else: ?><span class="at-disabled" aria-hidden="true">›</span><?php endif; ?></div>
        <a class="emp-btn emp-btn-outline" href="download-attendance.php"><?= icon('download') ?> Monthly report</a>
    </div>
</header>

<section class="at-stats">
    <div class="at-stat is-primary"><span>Logged in <?= $viewDate===today()?'today':'' ?></span><strong><?= $stats['logged'] ?><small> / <?= count($directory) ?></small></strong></div>
    <div class="at-stat"><span>On time</span><strong><?= $stats['on_time'] ?></strong></div>
    <div class="at-stat is-late"><span>Late</span><strong><?= $stats['late'] ?></strong></div>
    <div class="at-stat is-absent"><span>Not logged in</span><strong><?= $stats['absent'] ?></strong></div>
    <div class="at-stat"><span>Logged out</span><strong><?= $stats['logged_out'] ?></strong></div>
</section>

<div class="at-layout">
<section class="emp-card at-list-card">
    <div class="at-list-head"><h3><?= $viewDate===today()?"Today's attendance":'Attendance · '.h(date('d M',strtotime($viewDate))) ?></h3>
        <div class="dept-toggle" role="tablist"><button type="button" class="active" data-at-tab="all">All · <?= count($directory) ?></button><button type="button" data-at-tab="late">Late · <?= $stats['late'] ?></button><button type="button" data-at-tab="absent">Absent · <?= $stats['absent'] ?></button></div>
    </div>
    <?php if (!$directory): ?><p class="muted at-empty">No active employees found.</p><?php endif; ?>
    <div class="at-rows">
    <?php foreach ($directory as $employee): $sel=(int)$employee['id']===$calId; $seconds=(int)$employee['day_seconds']; $pct=min(100,(int)round(100*$seconds/$workTargetSeconds)); $lateMin=intdiv((int)$employee['late_seconds'],60); ?>
        <article class="at-row at-<?= $employee['state'] ?><?= $sel?' is-selected':'' ?>" data-at-row data-state="<?= $employee['state'] ?>">
            <div class="at-person"><span class="aw-avatar"><?= h($initials($employee['full_name'])) ?></span><div><strong><?= h($employee['full_name']) ?></strong><small><?= h($employee['department_name']) ?><?= $employee['employee_code']?' · '.h($employee['employee_code']):'' ?></small></div></div>
            <?php if ($employee['day_login']): ?>
                <div class="at-time"><strong><?= h(date('g:i A',strtotime($employee['day_login']))) ?></strong><?php if ($lateMin>0): ?><span class="at-badge <?= $lateMin>=60?'is-bad':'' ?>">Late <?= $lateMin>=60?intdiv($lateMin,60).'h '.($lateMin%60).'m':$lateMin.'m' ?></span><?php else: ?><span class="at-badge is-ok">On time</span><?php endif; ?></div>
                <div class="at-work"><span class="at-work-top"><span class="at-dot <?= $employee['day_logout']?'is-out':'' ?>"><?= $employee['day_logout']?'Logged out '.h(date('g:i A',strtotime($employee['day_logout']))):($viewDate===today()?'Working':'No logout') ?></span><b><?= h($durationLabel($seconds)) ?></b></span><span class="at-bar <?= $lateMin>0?'is-late':'' ?>"><i style="width:<?= $pct ?>%"></i></span></div>
            <?php else: ?>
                <div class="at-time"><strong class="at-missing"><?= $employee['marked_status']==='leave'?'On leave':($employee['marked_status']==='absent'?'Absent':'Not logged in') ?></strong><small><?= $employee['leave_info']?h(ucfirst($employee['leave_info'])):($employee['marked_status']?'Marked by admin':'No leave applied') ?></small></div>
                <div class="at-mark">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>"><input type="hidden" name="cal" value="<?= $calId ?>">
                    <?php if ($employee['marked_status']): ?><button class="st-btn" name="mark" value="clear">Undo</button>
                    <?php else: ?><button class="st-btn" name="mark" value="leave">Mark leave</button><button class="st-btn" name="mark" value="absent">Mark absent</button><?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
            <div class="at-row-action"><?php if ($sel): ?><span class="at-viewing">Viewing →</span><?php else: ?><a class="st-btn" href="<?= h($link(['cal'=>(int)$employee['id']])) ?>#at-calendar">Calendar</a><?php endif; ?></div>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<?php if ($calEmployee): $prevMonth=$calFirst->modify('-1 month')->format('Y-m'); $nextMonth=$calFirst->modify('+1 month')->format('Y-m'); ?>
<aside class="emp-card at-cal" id="at-calendar">
    <div class="at-cal-person"><span class="aw-avatar"><?= h($initials($calEmployee['full_name'])) ?></span><div><strong><?= h($calEmployee['full_name']) ?></strong><small>Monthly attendance · <a href="employee-attendance.php?employee=<?= $calId ?>&amp;month=<?= h($calMonth) ?>">Full history</a></small></div></div>
    <div class="at-cal-nav"><a href="<?= h($link(['cal_month'=>$prevMonth])) ?>#at-calendar" aria-label="Previous month">‹</a><h3><?= h($calFirst->format('F Y')) ?></h3><?php if ($nextMonth<=date('Y-m')): ?><a href="<?= h($link(['cal_month'=>$nextMonth])) ?>#at-calendar" aria-label="Next month">›</a><?php else: ?><span class="at-disabled">›</span><?php endif; ?></div>
    <div class="at-cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i=>$wd): ?><span class="at-cal-wd<?= $i===0?' is-sun':'' ?>"><?= $wd ?></span><?php endforeach; ?>
        <?php for ($b=0;$b<(int)$calFirst->format('w');$b++): ?><span></span><?php endfor; ?>
        <?php foreach ($calDays as $cd): ?><span class="at-day at-day-<?= $cd['status'] ?><?= $cd['date']===$viewDate?' is-today':'' ?>" title="<?= h(date('d M',strtotime($cd['date']))) ?> · <?= h(['present'=>'Present','late'=>'Late','absent'=>'Absent','leave'=>'Leave','off'=>'Sunday / Holiday','none'=>''][$cd['status']]) ?>"><?= $cd['day'] ?></span><?php endforeach; ?>
    </div>
    <div class="at-legend"><span><i class="present"></i>Present</span><span><i class="late"></i>Late</span><span><i class="absent"></i>Absent</span><span><i class="leave"></i>Leave</span><span><i class="off"></i>Sunday / Holiday</span></div>
    <div class="at-cal-counts"><div><strong class="c-present"><?= $calCounts['present'] ?></strong><small>Present</small></div><div><strong class="c-late"><?= $calCounts['late'] ?></strong><small>Late</small></div><div><strong class="c-absent"><?= $calCounts['absent'] ?></strong><small>Absent</small></div><div><strong class="c-leave"><?= $calCounts['leave'] ?></strong><small>Leave</small></div></div>
</aside>
<?php endif; ?>
</div>
</div>
<script>
(function () {
    var page = document.querySelector('[data-at-page]'); if (!page) return;
    page.querySelectorAll('[data-at-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.dataset.atTab;
            page.querySelectorAll('[data-at-tab]').forEach(function (x) { x.classList.toggle('active', x === b); });
            page.querySelectorAll('[data-at-row]').forEach(function (r) { r.hidden = t !== 'all' && r.dataset.state !== t; });
        });
    });
})();
</script>
<?php page_end(); exit;
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
// Day amount and admin add / cut for each calendar day.
$salaryDays=[];
$salaryEmployee=salary_employee((int)$attendanceEmployee['id']);
if ($salaryEmployee) { attendance_sync_reviews((int)$salaryEmployee['id']); foreach (salary_month($salaryEmployee,$month)['days'] as $salaryDay) $salaryDays[$salaryDay['date']]=$salaryDay; }
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
    <div class="attendance-calendar"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?><div class="attendance-calendar-head"><?= $weekday ?></div><?php endforeach; for ($blank=1;$blank<(int)$first->format('N');$blank++): ?><div></div><?php endfor; foreach ($dayRows as $row): $session=$row['session']; $hasLogin=$session&&$session['first_login']; $late=$hasLogin&&date('H:i:s',strtotime($session['first_login']))>'09:30:00'; $hasPermission=false; foreach ($row['leaves'] as $leave) if (stripos($leave['leave_type'],'permission')!==false) $hasPermission=true; $class=$hasPermission?'permission':($late?'late':($hasLogin?'present':$row['status'])); ?><article class="attendance-calendar-day <?= h($class) ?>"><strong><?= h(date('d-m-Y',strtotime($row['date']))) ?></strong><span><?= $hasPermission?'Permission':($late?'Late login':($hasLogin?'On time':ucfirst(str_replace('_',' ',$row['status'])))) ?></span><?php if ($session&&$session['first_login']): ?><span>Login <?= h(date('g:i A',strtotime($session['first_login']))) ?></span><?php endif; if ($session&&$session['last_logout']): ?><span>Logout <?= h(date('g:i A',strtotime($session['last_logout']))) ?></span><?php endif; foreach ($row['leaves'] as $leave): ?><span><?= h($leave['leave_type']) ?> · <?= h(ucfirst($leave['status'])) ?></span><?php endforeach; $sd=$salaryDays[$row['date']]??null; if ($sd && $sd['amount']!==0): ?><span class="cal-amount <?= $sd['amount']<0?'is-neg':'is-pos' ?>"><?= $sd['amount']<0?'Cut −'.h(paise_label(-$sd['amount'])):'Amount '.h(paise_label($sd['amount'])) ?></span><?php endif; if ($sd) foreach ($sd['reviews'] as $rv): $rvAmount=money_cents((string)$rv['amount']); ?><span class="cal-review is-<?= h($rv['status']) ?>"><?php if ($rv['status']==='pending'): ?><?= $rv['kind']==='overtime'?'Extra work':'Missed logout' ?> · waiting for admin<?php elseif ($rv['status']==='approved' && $rvAmount>0): ?>Admin <?= $rv['kind']==='overtime'?'added +':'cut −' ?><?= h(paise_label($rvAmount)) ?><?php else: ?>Admin: <?= $rv['kind']==='overtime'?'extra not approved':'no cut' ?><?php endif; ?><?php if ($rv['remark']!==''): ?><em>“<?= h($rv['remark']) ?>”</em><?php endif; ?></span><?php endforeach; ?></article><?php endforeach; ?></div>
    <p><a class="button secondary" href="employee-salary.php<?= $isAdmin?'?employee='.(int)$attendanceEmployee['id'].'&amp;month='.h($month):'?month='.h($month) ?>">View salary for this month →</a></p>
    <p class="help">Login at 9:30 AM or earlier is on time. A login after 9:30 AM appears red for the administrator. Past working days without a complete login/logout are shown as leave.</p>
</section>
<?php if (!$isAdmin): ?><p><a class="button secondary" href="apply-leaves.php">Apply for leave</a> <a class="button secondary" href="my-leave-requests.php">View leave requests</a></p><?php endif; ?>
<?php page_end(); ?>
