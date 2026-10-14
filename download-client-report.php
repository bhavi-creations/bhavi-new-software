<?php
require_once __DIR__.'/includes/client-portal.php';
require_once __DIR__.'/includes/excel-export.php';
$client=own_client();
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) fail(400,'Choose a valid report.');
$report=one('SELECT * FROM client_work_reports WHERE id=? AND client_id=?',[$id,$client['id']]);
if (!$report) fail(404,'Report not found.');
$fields=['Client'=>$client['client_name'],'Report date'=>$report['report_date']]+client_report_fields($report);
download_excel(array_keys($fields),[array_values($fields)],'work-report-'.$report['report_date'].'-'.$id);
exit;
