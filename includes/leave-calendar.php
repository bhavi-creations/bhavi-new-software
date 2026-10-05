<?php
require_once __DIR__ . '/layout.php'; require_once __DIR__ . '/calendar.php';
$user=require_roles(['employee']); $month=calendar_month();
$first=$month.'-01'; $last=(new DateTimeImmutable($first))->format('Y-m-t');
$events=[];
foreach (rows('SELECT holiday_name,holiday_date FROM holidays WHERE is_published=1 AND holiday_date BETWEEN ? AND ?',[$first,$last]) as $holiday) $events[$holiday['holiday_date']][]=['label'=>$holiday['holiday_name'],'class'=>''];
$leaves=rows("SELECT l.*,t.name AS leave_type FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id WHERE l.employee_id=? AND l.from_date<=? AND l.to_date>=? AND l.status<>'cancelled'",[$user['id'],$last,$first]);
foreach ($leaves as $leave) {
    for ($day=new DateTimeImmutable(max($first,$leave['from_date']));$day<=new DateTimeImmutable(min($last,$leave['to_date']));$day=$day->modify('+1 day')) $events[$day->format('Y-m-d')][]=['label'=>$leave['leave_type'].' · '.$leave['status'],'class'=>'status-'.$leave['status']];
}
$counts=array_fill_keys(['pending','approved','rejected'],0);
foreach ($leaves as $leave) $counts[$leave['status']]++;
page_start('My leave calendar','check-leave.php');
?><p class="muted">Requests overlapping <?= h($month) ?>, excluding cancelled requests. Counts are requests, not leave days.</p><div class="stats-grid"><article class="stat-card"><h2>Total requests</h2><strong><?= count($leaves) ?></strong></article><?php foreach ($counts as $status=>$count): ?><article class="stat-card"><h2><?= h(ucfirst($status)) ?></h2><strong><?= $count ?></strong></article><?php endforeach; ?></div>
<?php render_calendar($month,$events); ?>
<section class="panel"><div class="panel-heading"><h2>Leave request history</h2><a class="button" href="apply-leaves.php">Apply leave</a></div><div class="table-scroll"><table><thead><tr><th>Applied on</th><th>From</th><th>To</th><th>Leave type</th><th>Status</th><th>Reason</th></tr></thead><tbody><?php if (!$leaves) empty_row(6,'No leave requests for this month.'); foreach ($leaves as $leave): ?><tr><td><?= h($leave['applied_at']) ?></td><td><?= h($leave['from_date']) ?></td><td><?= h($leave['to_date']) ?></td><td><?= h($leave['leave_type']) ?></td><td><?= status_badge($leave['status']) ?></td><td class="wrap"><?= h($leave['reason']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php page_end();
