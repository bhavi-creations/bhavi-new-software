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
page_start('My leave calendar','check-leave.php'); render_calendar($month,$events); page_end();
