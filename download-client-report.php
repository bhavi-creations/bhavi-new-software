<?php
require_once __DIR__.'/includes/client-portal.php';
require_once __DIR__.'/includes/excel-export.php';
$user=require_roles(['client','admin','manager']);
if ($user['role']==='client') $client=own_client();
else {
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) fail(405,'This page is read-only.');
    $clientId=filter_var($_GET['client_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $client=$clientId?one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL',[$clientId]):null;
    if (!$client) fail(404,'Client not found.');
}
if (isset($_GET['id'])) {
$id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) fail(400,'Choose a valid report.');
$report=one('SELECT * FROM client_work_reports WHERE id=? AND client_id=?',[$id,$client['id']]);
if (!$report) fail(404,'Report not found.');
$fields=['Client'=>$client['client_name'],'Report date'=>$report['report_date']]+client_report_fields($report);
download_excel(array_keys($fields),[array_values($fields)],'work-report-'.$report['report_date'].'-'.$id);
exit;
}
$period=$_GET['period']??'day';
if (!in_array($period,['day','week','month','custom'],true)) fail(400,'Choose day, week, month or custom dates.');
if ($period==='custom') {
    try { $from=date_input('from',$_GET); $to=date_input('to',$_GET); } catch (InvalidArgumentException $e) { fail(400,'Choose valid From and To dates.'); }
    if ($from>$to) fail(400,'From date must be on or before To date.');
    $start=new DateTimeImmutable($from);
    $end=(new DateTimeImmutable($to))->modify('+1 day');
} else {
try { $date=date_input('date',$_GET); } catch (InvalidArgumentException $e) { fail(400,'Choose a valid date.'); }
$start=new DateTimeImmutable($date);
if ($period==='week') $start=$start->modify('-'.((int)$start->format('N')-1).' days');
if ($period==='month') $start=$start->modify('first day of this month');
$end=$start->modify($period==='month'?'+1 month':($period==='week'?'+7 days':'+1 day'));
}
$reports=rows('SELECT * FROM client_work_reports WHERE client_id=? AND report_date>=? AND report_date<? ORDER BY report_date,id',[$client['id'],$start->format('Y-m-d'),$end->format('Y-m-d')]);
$byDate=[];
foreach ($reports as $report) $byDate[$report['report_date']][]=$report;
$headers=['Client','Report date','Update','Work','Type','Content source','Content received / collected','Work started','Sent on WhatsApp','Client approval','Social media upload','Social media / uploaded by','Remarks / changes'];
$records=(static function() use ($start,$end,$byDate,$client,$headers): Generator {
for ($day=$start;$day<$end;$day=$day->modify('+1 day')) {
    $key=$day->format('Y-m-d');
    $items=$byDate[$key]??[];
    if (!$items) yield array_merge([$client['client_name'],$key,'No report added'],array_fill(0,count($headers)-3,''));
    foreach ($items as $report) yield array_merge([$client['client_name'],$key,'Report added'],array_values(client_report_fields($report)));
}
})();
download_excel($headers,$records,'work-reports-'.$start->format('Y-m-d').'-'.$end->modify('-1 day')->format('Y-m-d'));
exit;
