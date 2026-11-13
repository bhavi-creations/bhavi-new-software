<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/salary-calc.php';
require_once __DIR__.'/includes/attendance-month-view.php';
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
    // Late is measured against each employee's own office time / shift (Office timings page).
    $officeDefault=office_timing_default();
    $workTargetSeconds=(int)round(duty_hours()*3600);
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

    $directory=rows("SELECT u.id,u.full_name,ep.employee_code,ep.department_id,ep.joining_date,ep.shift_start,ep.shift_end,ep.shift_label,ep.shift_slots,ep.shift_hours,d.name AS department_name,
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
        $lateSeconds=$login?login_late_seconds($login,employee_timing($employee)):0;
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
            if (isset($logins[$date])) $status=login_late_seconds($logins[$date],employee_timing($calEmployee))>0?'late':'present';
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
    <div><p class="emp-crumb">Dashboard / Attendance</p><h2 class="emp-title">Employee attendance</h2><p class="emp-sub">Office time <?= h(office_range_label($officeDefault['start'],$officeDefault['end'])) ?> · duty <?= h(duty_hours_label()) ?> · <?= $viewDate===today()?'Live status for today':'Attendance for '.h(date('d M Y',strtotime($viewDate))) ?></p></div>
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
            <div class="at-person"><span class="aw-avatar"><?= h($initials($employee['full_name'])) ?></span><div><a class="at-name-link" href="employee-attendance.php?employee=<?= (int)$employee['id'] ?>&amp;month=<?= h(substr($viewDate,0,7)) ?>"><strong><?= h($employee['full_name']) ?></strong></a><small><?= h($employee['department_name']) ?><?= $employee['employee_code']?' · '.h($employee['employee_code']):'' ?></small></div></div>
            <?php if ($employee['day_login']): ?>
                <div class="at-time"><strong><?= h(date('g:i A',strtotime($employee['day_login']))) ?></strong><?php if ($lateMin>0): ?><span class="at-badge <?= $lateMin>=60?'is-bad':'' ?>">Late <?= $lateMin>=60?intdiv($lateMin,60).'h '.($lateMin%60).'m':$lateMin.'m' ?></span><?php else: ?><span class="at-badge is-ok">On time</span><?php endif; ?></div>
                <div class="at-work"><span class="at-work-top"><span class="at-dot <?= $employee['day_logout']?'is-out':'' ?>"><?= $employee['day_logout']?'Logged out '.h(date('g:i A',strtotime($employee['day_logout']))):($viewDate===today()?'Working':'No logout') ?></span><b><?= h($durationLabel($seconds)) ?></b></span><span class="at-bar <?= $lateMin>0?'is-late':'' ?>"><i style="width:<?= $pct ?>%"></i></span></div>
            <?php else: ?>
                <div class="at-time"><strong class="at-missing"><?= $employee['marked_status']==='leave'?'On leave':($employee['marked_status']==='absent'?'Absent':'Not logged in') ?></strong><small><?= $employee['leave_info']?h(ucfirst($employee['leave_info'])):($employee['marked_status']?'Marked by Managing Director':'No leave applied') ?></small></div>
                <div class="at-mark">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>"><input type="hidden" name="cal" value="<?= $calId ?>">
                    <?php if ($employee['marked_status']): ?><button class="st-btn" name="mark" value="clear">Undo</button>
                    <?php else: ?><button class="st-btn" name="mark" value="leave">Mark leave</button><button class="st-btn" name="mark" value="absent">Mark absent</button><?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
            <div class="at-row-action"><a class="st-btn st-btn-dark" href="employee-attendance.php?employee=<?= (int)$employee['id'] ?>&amp;month=<?= h(substr($viewDate,0,7)) ?>">Open calendar</a><?php if ($sel): ?><span class="at-viewing">Viewing →</span><?php else: ?><a class="st-btn at-preview" href="<?= h($link(['cal'=>(int)$employee['id']])) ?>#at-calendar">Preview</a><?php endif; ?></div>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<?php if ($calEmployee): $prevMonth=$calFirst->modify('-1 month')->format('Y-m'); $nextMonth=$calFirst->modify('+1 month')->format('Y-m'); ?>
<aside class="emp-card at-cal" id="at-calendar">
    <div class="at-cal-person"><span class="aw-avatar"><?= h($initials($calEmployee['full_name'])) ?></span><div><strong><?= h($calEmployee['full_name']) ?></strong><small>Monthly attendance · <a href="employee-attendance.php?employee=<?= $calId ?>&amp;month=<?= h($calMonth) ?>">Open full calendar with login, logout &amp; salary →</a></small></div></div>
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
    ?salary_employee((int)$selectedEmployeeId)
    :salary_employee((int)$user['id']);
if (!$attendanceEmployee || !$attendanceEmployee['joining_date']) fail(404,'Employee not found.');
$currentMonth=date('Y-m');
$month=$_GET['month']??$currentMonth;
if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/D',$month) || DateTimeImmutable::createFromFormat('!Y-m',$month)?->format('Y-m')!==$month || $month>$currentMonth) {
    fail(422,'Choose a valid attendance month.');
}
attendance_sync_reviews((int)$attendanceEmployee['id']);
$calc=salary_month($attendanceEmployee,$month);
// Keep the saved day status in step with the calendar (used by reports).
foreach ($calc['days'] as $day) {
    $saved=['present'=>'present','leave'=>'leave','absent'=>'leave','missed_logout'=>'leave','today'=>'pending','working'=>'pending'][$day['status']]??null;
    if ($saved) query('INSERT INTO employee_attendance_days (employee_id,attendance_date,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',[$attendanceEmployee['id'],$day['date'],$saved]);
}
$first=$calc['first'];
$previousMonth=$first->modify('-1 month')->format('Y-m');
$nextMonth=$first->modify('+1 month')->format('Y-m');
$monthLink=static fn(string $m): string => 'employee-attendance.php?'.http_build_query(array_filter(['employee'=>$isAdmin?(int)$attendanceEmployee['id']:null,'month'=>$m]));
page_start($isAdmin?$attendanceEmployee['full_name'].' · Attendance':'My attendance','employee-attendance.php');
?>
<div class="emp-page am-page">
<header class="emp-header">
    <div>
        <p class="emp-crumb"><?php if ($isAdmin): ?><a href="employee-attendance.php">← All employees</a><?php else: ?>Dashboard / My attendance<?php endif; ?></p>
        <h2 class="emp-title"><?= $isAdmin?h($attendanceEmployee['full_name']):'My attendance' ?></h2>
        <p class="emp-sub"><?= h($attendanceEmployee['department_name']) ?><?= $attendanceEmployee['employee_code']?' · '.h($attendanceEmployee['employee_code']):'' ?> · Login / logout time, day amount and salary cuts for every date.</p>
    </div>
    <div class="am-month-nav">
        <a href="<?= h($monthLink($previousMonth)) ?>" aria-label="Previous month">‹</a>
        <form method="get"><?php if ($isAdmin): ?><input type="hidden" name="employee" value="<?= (int)$attendanceEmployee['id'] ?>"><?php endif; ?><input type="month" name="month" value="<?= h($month) ?>" max="<?= h($currentMonth) ?>" onchange="this.form.submit()" aria-label="Month"><noscript><button type="submit">View</button></noscript></form>
        <?php if ($nextMonth<=$currentMonth): ?><a href="<?= h($monthLink($nextMonth)) ?>" aria-label="Next month">›</a><?php else: ?><span aria-hidden="true">›</span><?php endif; ?>
    </div>
</header>
<?php attendance_month_view($attendanceEmployee,$calc,$isAdmin); ?>
<?php if (!$isAdmin): ?><p class="am-actions"><a class="emp-btn emp-btn-outline" href="apply-leaves.php">Apply for leave</a> <a class="emp-btn emp-btn-outline" href="my-leave-requests.php">View leave requests</a></p><?php endif; ?>
</div>
<?php page_end(); ?>
