<?php
require_once __DIR__ . '/layout.php';
$user=require_roles([$expectedRole]);
if (($_GET['view']??'')==='team') redirect('admin-employees.php');
if (($_GET['view']??'')==='work') redirect('manager-dailywork.php');
$leaveCount=count_value("SELECT COUNT(*) FROM leave_requests WHERE status='pending'");
if ($user['role']!=='admin') {
    $employeeCount=count_value("SELECT COUNT(*) FROM users WHERE role='employee' AND deleted_at IS NULL");
    $submittedCount=count_value("SELECT COUNT(*) FROM daily_work_submissions s WHERE s.work_date=? AND s.submission_status='submitted' AND EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.submission_id=s.id)",[today()]);
    $clientCount=count_value('SELECT COUNT(*) FROM clients WHERE is_active=1 AND deleted_at IS NULL');
    $holidayCount=count_value('SELECT COUNT(*) FROM holidays WHERE is_published=1 AND holiday_date>=?',[today()]);
    $departmentTotals=rows("SELECT d.id,d.name,COUNT(u.id) AS total,COALESCE(SUM(s.submission_status='submitted' AND EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.submission_id=s.id)),0) AS submitted FROM departments d LEFT JOIN employee_profiles ep ON ep.department_id=d.id LEFT JOIN users u ON u.id=ep.user_id AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL LEFT JOIN daily_work_submissions s ON s.employee_id=u.id AND s.work_date=? WHERE d.is_active=1 GROUP BY d.id,d.name ORDER BY d.name",[today()]);
    $pending=rows("SELECT u.full_name,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM daily_work_submissions s WHERE s.employee_id=u.id AND s.work_date=? AND s.submission_status='submitted' AND EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.submission_id=s.id)) ORDER BY u.full_name",[today()]);
    $notifications=rows('SELECT n.id,n.title,n.message,n.created_at FROM notifications n WHERE n.recipient_id=? AND n.created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) ORDER BY n.created_at DESC,n.id DESC LIMIT 5',[$user['id']]);
}
page_start(ucfirst($user['role']).' dashboard',dashboard_path($user));
if ($user['role']==='admin'):
$activeEmployeeCount=count_value("SELECT COUNT(*) FROM users WHERE role='employee' AND account_status='active' AND deleted_at IS NULL");
$todayWork=one("SELECT COUNT(e.id) AS task_count,COALESCE(SUM(e.task_status='completed'),0) AS completed_count FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL JOIN daily_work_entries e ON e.submission_id=s.id WHERE s.work_date=? AND s.submission_status='submitted'",[today()]);
$todaySubmitted=count_value("SELECT COUNT(*) FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL WHERE s.work_date=? AND s.submission_status='submitted' AND EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.submission_id=s.id)",[today()]);
$reviewCount=count_value("SELECT COUNT(*) FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL WHERE s.work_date=? AND s.submission_status='submitted' AND s.review_status IN ('pending','changes_requested') AND EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.submission_id=s.id)",[today()]);
$attendanceToday=one("SELECT COUNT(*) AS present_count,COALESCE(SUM(first_login>CONCAT(CURDATE(),' 09:30:00')),0) AS late_count,COALESCE(SUM(TIMESTAMPDIFF(MINUTE,first_login,COALESCE(last_logout,NOW()))),0) AS logged_minutes FROM (SELECT a.employee_id,MIN(a.login_at) AS first_login,CASE WHEN SUM(a.logout_at IS NULL)>0 THEN NULL ELSE MAX(a.logout_at) END AS last_logout FROM employee_attendance_sessions a JOIN users attended_employee ON attended_employee.id=a.employee_id AND attended_employee.role='employee' AND attended_employee.account_status='active' AND attended_employee.deleted_at IS NULL WHERE a.login_at>=CURDATE() AND a.login_at<CURDATE()+INTERVAL 1 DAY GROUP BY a.employee_id) AS today_sessions");
$attendancePercent=$activeEmployeeCount?min(100,(int)round(100*(int)$attendanceToday['present_count']/$activeEmployeeCount)):0;
$todayReports=rows("SELECT s.id,s.work_date,s.review_status,MAX(s.submitted_at) AS submitted_at,u.full_name,d.name AS department_name,COUNT(e.id) AS task_count,COALESCE(SUM(e.time_spent_minutes),0) AS work_minutes,GROUP_CONCAT(COALESCE(NULLIF(e.task_title,''),NULLIF(e.website_page_task,''),NULLIF(e.seo_task,''),'Daily work') ORDER BY e.row_order SEPARATOR ', ') AS work_summary FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id JOIN daily_work_entries e ON e.submission_id=s.id WHERE (s.work_date=? OR (s.submitted_at>=? AND s.submitted_at<?)) AND s.submission_status='submitted' AND u.deleted_at IS NULL GROUP BY s.id,s.work_date,s.review_status,u.full_name,d.name ORDER BY (s.review_status IN ('pending','changes_requested')) DESC,submitted_at DESC LIMIT 200",[today(),today().' 00:00:00',date('Y-m-d',strtotime('+1 day')).' 00:00:00']); // Also work submitted today for another date (e.g. a task dated tomorrow).
$dashboardLeaves=rows("SELECT l.id,l.from_date,l.to_date,l.working_days,l.reason,l.applied_at,u.full_name,d.name AS department_name,t.name AS leave_type FROM leave_requests l JOIN users u ON u.id=l.employee_id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id JOIN leave_types t ON t.id=l.leave_type_id WHERE l.status='pending' AND u.deleted_at IS NULL ORDER BY l.applied_at DESC LIMIT 100");
$activeProjects=rows("SELECT c.id,c.client_name,COUNT(a.id) AS task_count,COALESCE(SUM(a.status='completed'),0) AS completed_count FROM clients c JOIN work_assignments a ON a.client_id=c.id AND a.deleted_at IS NULL WHERE c.is_active=1 AND c.deleted_at IS NULL AND EXISTS (SELECT 1 FROM work_assignments open_task WHERE open_task.client_id=c.id AND open_task.deleted_at IS NULL AND open_task.status='pending') GROUP BY c.id,c.client_name ORDER BY (COUNT(a.id)-SUM(a.status='completed')) DESC,c.client_name LIMIT 3");
$activeProjectCount=count_value("SELECT COUNT(DISTINCT a.client_id) FROM work_assignments a JOIN clients c ON c.id=a.client_id WHERE a.deleted_at IS NULL AND a.status='pending' AND c.is_active=1 AND c.deleted_at IS NULL");
$announcements=rows("SELECT a.title,a.message,a.audience,a.created_at,sender.full_name AS sender_name FROM announcements a LEFT JOIN users sender ON sender.id=a.sender_id ORDER BY a.created_at DESC,a.id DESC LIMIT 20");
$greeting=(int)date('G')<12?'Good morning':((int)date('G')<17?'Good afternoon':'Good evening');
$formatMinutes=static function(int $minutes): string { return intdiv($minutes,60).'h '.str_pad((string)($minutes%60),2,'0',STR_PAD_LEFT).'m'; };
?>
<div class="admin-dashboard">
    <header class="admin-dashboard-heading">
        <div>
            <p class="admin-eyebrow">Admin dashboard · <?= h(date('l, d F Y')) ?></p>
            <h1><?= h($greeting) ?>, <?= h($user['full_name']) ?></h1>
        </div>
        <form class="admin-dashboard-search" method="get" action="admin-employees.php" role="search">
            <label class="sr-only" for="dashboard-search">Search employees</label>
            <span aria-hidden="true">⌕</span>
            <input id="dashboard-search" type="search" name="search" placeholder="Search employees" maxlength="100">
            <button type="submit">Search</button>
        </form>
        <a class="admin-icon-action" href="manager-notification.php" aria-label="Notifications"><?= icon('bell') ?></a>
        <a class="admin-action admin-action-dark" href="manager-assign-work.php">＋ New task</a>
    </header>

    <section class="admin-welcome-banner">
        <div class="admin-welcome-copy">
            <p class="admin-eyebrow">Today at Bhavi</p>
            <h2>We're here to help you shine.</h2>
            <div class="admin-welcome-stats">
                <a href="employee-attendance.php"><strong><?= (int)$attendanceToday['present_count'] ?><small> / <?= $activeEmployeeCount ?></small></strong><span>Present today</span></a>
                <a href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>"><strong><?= $todaySubmitted ?></strong><span>Reports submitted</span></a>
                <a href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>&amp;review_status=needs_review"><strong class="is-gold"><?= $reviewCount ?></strong><span>Awaiting your approval</span></a>
            </div>
        </div>
        <div class="admin-welcome-actions">
            <a class="admin-action admin-action-gold" href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>&amp;review_status=needs_review">Review reports <span aria-hidden="true">→</span></a>
            <a class="admin-action admin-action-outline" href="admin-add-employee.php">Add employee</a>
        </div>
        <span class="admin-welcome-rings" aria-hidden="true"><i></i><i></i><i></i></span>
    </section>

    <section class="admin-metric-grid" aria-label="Team overview">
        <a class="admin-metric-card" href="admin-employees.php"><span class="admin-metric-icon">♙</span><span class="admin-metric-label">Active employees</span><strong><?= $activeEmployeeCount ?></strong><small>View employee team</small></a>
        <a class="admin-metric-card" href="manager-assigned-status.php"><span class="admin-metric-icon">▣</span><span class="admin-metric-label">Active projects</span><strong><?= $activeProjectCount ?></strong><small>Clients with unfinished tasks</small></a>
        <a class="admin-metric-card" href="manager-assigned-status.php"><span class="admin-metric-icon">✓</span><span class="admin-metric-label">Tasks completed</span><strong><?= (int)$todayWork['completed_count'] ?><small class="admin-metric-total"> / <?= (int)$todayWork['task_count'] ?></small></strong><span class="admin-metric-progress"><i style="width:<?= (int)$todayWork['task_count']?min(100,(int)round(100*(int)$todayWork['completed_count']/(int)$todayWork['task_count'])):0 ?>%"></i></span></a>
        <a class="admin-metric-card" href="admin-todo.php"><span class="admin-metric-icon">☑</span><span class="admin-metric-label">To Do</span><strong><?= count_value("SELECT COUNT(*) FROM todo_tasks WHERE user_id=? AND status='open'",[$user['id']]) ?></strong><small>Pending tasks · open your list</small></a>
    </section>

    <div class="admin-dashboard-main-grid">
        <section class="admin-dashboard-card admin-reports-card">
            <div class="admin-card-heading"><div><h2>Today's work reports <span class="admin-count-badge"><?= $todaySubmitted ?> / <?= $activeEmployeeCount ?> submitted</span></h2><p><?= $todaySubmitted ?> employee<?= $todaySubmitted===1?' has':'s have' ?> sent today's work report · <?= $reviewCount ?> awaiting review.</p></div><div class="admin-filter-pills"><a class="active" href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>">All</a><a href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>&amp;review_status=needs_review">Pending</a><a href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>&amp;review_status=reviewed">Approved</a></div></div>
            <div class="admin-report-list">
                <?php if (!$todayReports): ?><p class="admin-empty">No work reports submitted today yet.</p><?php endif; ?>
                <?php foreach ($todayReports as $report): $needsReview=in_array($report['review_status'],['pending','changes_requested'],true); ?>
                    <article class="admin-report-row">
                        <span class="admin-avatar"><?= h(mb_strtoupper(mb_substr($report['full_name'],0,1))) ?></span>
                        <div class="admin-report-person"><strong><?= h($report['full_name']) ?></strong><small><?= h($report['department_name']) ?></small></div>
                        <p class="admin-report-summary"><?= h($report['work_summary']) ?></p>
                        <span class="admin-report-hours"><?= h($formatMinutes((int)$report['work_minutes'])) ?></span>
                        <span class="admin-status-pill <?= $needsReview?'pending':'approved' ?>"><?= h(str_replace('_',' ',ucfirst($report['review_status']))) ?></span>
                        <a class="admin-row-action" href="manager-review-work.php?id=<?= (int)$report['id'] ?>"><?= $needsReview?'Review':'View' ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
            <a class="admin-section-link" href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>">See all reports →</a>
        </section>

        <aside class="admin-dashboard-side">
            <section class="admin-dashboard-card admin-attendance-card">
                <div class="admin-card-heading"><h2>Attendance</h2><a href="employee-attendance.php">Details</a></div>
                <?php $presentCount=(int)$attendanceToday['present_count']; $lateCount=(int)$attendanceToday['late_count']; $onTimeCount=max(0,$presentCount-$lateCount); $absentCount=max(0,$activeEmployeeCount-$presentCount); $pct=static fn(int $n): int => $activeEmployeeCount?min(100,(int)round(100*$n/$activeEmployeeCount)):0; ?>
                <div class="admin-attendance-summary">
                    <div class="admin-attendance-ring" role="img" aria-label="<?= $attendancePercent ?> percent of active employees attended today" style="--attendance-percent:<?= $attendancePercent ?>%"><strong><?= $attendancePercent ?>%</strong><small>present</small></div>
                    <div class="admin-attendance-total"><strong><?= $presentCount ?> <small>of <?= $activeEmployeeCount ?></small></strong><span>employees logged in today</span></div>
                </div>
                <div class="admin-attendance-bars">
                    <div><span><i class="present"></i>On time</span><b><?= $onTimeCount ?></b><em><?= $pct($onTimeCount) ?>%</em><span class="admin-attendance-bar"><i class="present" style="width:<?= $pct($onTimeCount) ?>%"></i></span></div>
                    <div><span><i class="late"></i>Late login <small>(after 9:30 AM)</small></span><b><?= $lateCount ?></b><em><?= $pct($lateCount) ?>%</em><span class="admin-attendance-bar"><i class="late" style="width:<?= $pct($lateCount) ?>%"></i></span></div>
                    <div><span><i class="absent"></i>Absent</span><b><?= $absentCount ?></b><em><?= $pct($absentCount) ?>%</em><span class="admin-attendance-bar"><i class="absent" style="width:<?= $pct($absentCount) ?>%"></i></span></div>
                </div>
            </section>
            <section class="admin-dashboard-card admin-leaves-card">
                <div class="admin-card-heading"><div><h2>Leave requests</h2><span class="admin-count-badge"><?= $leaveCount ?> pending</span></div><a href="manager-leave-requist.php?status=pending">View all</a></div>
                <?php if (!$dashboardLeaves): ?><p class="admin-empty">No leave requests are waiting for review.</p><?php endif; ?>
                <div class="admin-leave-list">
                <?php foreach ($dashboardLeaves as $leave): ?>
                    <article class="admin-leave-row">
                        <span class="admin-avatar"><?= h(mb_strtoupper(mb_substr($leave['full_name'],0,1))) ?></span>
                        <div><strong><?= h($leave['full_name']) ?><?= $leave['department_name'] ? ' · '.h($leave['department_name']) : '' ?></strong><small><?= h($leave['leave_type']) ?> · <?= h(date('d M',strtotime($leave['from_date']))) ?>–<?= h(date('d M',strtotime($leave['to_date']))) ?> · <?= h($leave['working_days']) ?> day(s)</small><?php if (trim((string)$leave['reason'])!==''): ?><p><?= h($leave['reason']) ?></p><?php endif; ?></div>
                        <a class="admin-row-action" href="manager-leave-requist.php?status=pending">Review</a>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>
        </aside>
    </div>

    <div class="admin-dashboard-bottom-grid">
        <section class="admin-dashboard-card">
            <div class="admin-card-heading"><h2>Project progress</h2><a href="manager-assigned-status.php">All projects</a></div>
            <?php if (!$activeProjects): ?><p class="admin-empty">No client projects have assignments yet.</p><?php endif; ?>
            <?php foreach ($activeProjects as $project): $progress=(int)$project['task_count']?min(100,(int)round(100*(int)$project['completed_count']/(int)$project['task_count'])):0; ?>
                <a class="admin-project-row" href="manager-assigned-status.php"><span><strong><?= h($project['client_name']) ?></strong><small><?= (int)$project['completed_count'] ?> / <?= (int)$project['task_count'] ?> tasks complete</small></span><b><?= $progress ?>%</b><span class="admin-project-progress"><i style="width:<?= $progress ?>%"></i></span></a>
            <?php endforeach; ?>
        </section>
        <section class="admin-dashboard-card admin-announcements-card">
            <div class="admin-card-heading"><h2>Announcements</h2><a href="manager-notification.php">All messages</a></div>
            <form class="admin-announce-form" method="post" action="manager-notification.php"><?= csrf_field() ?>
                <input type="hidden" name="action" value="send_notification"><input type="hidden" name="department_id" value="all"><input type="hidden" name="employee_id" value="all"><input type="hidden" name="return" value="dashboard">
                <label class="sr-only" for="announce-title">Announcement title</label><input id="announce-title" name="title" maxlength="200" placeholder="Announcement title" required>
                <label class="sr-only" for="announce-message">Message</label><textarea id="announce-message" name="message" maxlength="5000" rows="2" placeholder="Write a message for all employees…" required></textarea>
                <button type="submit" class="admin-action admin-action-gold">Post to all employees</button>
            </form>
            <div class="admin-announcement-list">
            <?php if (!$announcements): ?><p class="admin-empty">No announcements yet. Messages you post appear here.</p><?php endif; ?>
            <?php foreach ($announcements as $announcement): ?>
                <article class="admin-announcement-row"><time><?= h(date('d M',strtotime($announcement['created_at']))) ?><small><?= h(date('g:i A',strtotime($announcement['created_at']))) ?></small></time><div><strong><?= h($announcement['title']) ?></strong><p><?= h($announcement['message']) ?></p><small class="admin-announcement-meta">To <?= h($announcement['audience']) ?><?= $announcement['sender_name'] ? ' · by '.h($announcement['sender_name']) : '' ?></small></div></article>
            <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php page_end(); return; endif; ?>
<p class="muted">Welcome, <?= h($user['full_name']) ?>. Your team's latest work, requests and records are here.</p><div class="stats-grid"><a class="stat-card" href="admin-employees.php"><h2>Total employees</h2><strong><?= $employeeCount ?></strong><p>View your employee directory</p></a><a class="stat-card" href="manager-dailywork.php?from_date=<?= today() ?>&amp;to_date=<?= today() ?>"><h2>Work submitted today</h2><strong><?= $submittedCount ?></strong><p>View all department updates</p></a><?php if (is_staff()): ?><a class="stat-card" href="manager-leave-requist.php?status=pending"><h2>Leave requests</h2><strong><?= $leaveCount ?></strong><p>Awaiting your decision</p></a><?php endif; if ($user['role']==='admin'): ?><a class="stat-card" href="admin-holidays.php"><h2>Upcoming holidays</h2><strong><?= $holidayCount ?></strong><p>Shared with the whole team</p></a><?php endif; ?><a class="stat-card" href="manager-assigned-status.php"><h2>Assigned work status</h2><strong><?= count_value("SELECT COUNT(*) FROM work_assignments WHERE deleted_at IS NULL AND status='pending'") ?></strong><p>Pending tasks and employee progress</p></a></div>
<div class="two-columns"><section class="panel"><div class="panel-heading"><h2>Today by department</h2><a href="manager-dailywork.php">View reports</a></div><?php foreach ($departmentTotals as $department): $percent=$department['total']?min(100,round(100*$department['submitted']/$department['total'])):0; ?><div class="progress-row"><div class="progress-label"><a href="manager-dailywork.php?department=<?= $department['id'] ?>"><?= h($department['name']) ?></a><span><?= $department['submitted'] ?> / <?= $department['total'] ?> submitted</span></div><div class="progress-track" role="progressbar" aria-label="<?= h($department['name']) ?> submissions" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $percent ?>%"></span></div></div><?php endforeach; ?><a class="button" href="manager-assign-work.php">+ Assign work</a></section><section class="panel"><div class="panel-heading"><h2>Latest updates</h2><a href="manager-notification.php">View all</a></div><?php if (!$notifications): ?><p class="muted">Team notifications will appear here.</p><?php endif; foreach ($notifications as $notification): ?><a class="notification-link" href="notification-open.php?id=<?= (int)$notification['id'] ?>"><article style="margin-bottom:18px"><h3><?= h($notification['title']) ?></h3><p class="muted"><?= h($notification['message']) ?></p></article></a><?php endforeach; ?></section></div>
<section class="panel"><div class="panel-heading"><h2>Pending daily submissions · <?= count($pending) ?></h2><a href="add-client.php"><?= $clientCount ?> clients</a></div><?php if (!$pending): ?><p class="muted">All active employees have submitted, or no employees have been added yet.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Department</th></tr></thead><tbody><?php foreach ($pending as $employee): ?><tr><td><?= h($employee['full_name']) ?></td><td><?= h($employee['department_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php page_end(); ?>
