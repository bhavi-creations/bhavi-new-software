<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/monthly-design-report.php';
require_roles(['admin','manager']);
try { $report=monthly_design_report(); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
if (($_GET['format']??'')==='xlsx') {
    require_once __DIR__.'/includes/excel-export.php';
    $records=(static function () use ($report): Generator {
        foreach ($report['people'] as $person) yield ['Period total',$report['from'].' to '.$report['to'],$person['full_name'],'',$person['poster'],$person['video_editing'],$person['carousel'],'','','','Completed','',''];
        foreach ($report['people'] as $person) foreach ($person['entries'] as $entry) yield ['Work detail',$report['from'].' to '.$report['to'],$person['full_name'],$entry['work_date'],$entry['quantities']['poster'],$entry['quantities']['video_editing'],$entry['quantities']['carousel'],design_quantity_label($entry['quantities']),$entry['task_title'],$entry['client_name'],$entry['task_status'],$entry['time_spent_minutes']===null?'':format_work_duration($entry['time_spent_minutes']),$entry['remark']];
    })();
    download_excel(['Row type','Date range','Employee','Work date','Posters','Reels / Videos','Carousels','Task type','Title','Client','Status','Time spent','Remark'],$records,'bhavi-design-'.$report['from'].'-to-'.$report['to']);
    exit;
}
$standalone=in_array($_GET['format']??'', ['html','print'],true);
if ($standalone) {
    header('Content-Type: text/html; charset=UTF-8');
    if (($_GET['format']??'')==='html') header('Content-Disposition: attachment; filename="design-work-report-'.$report['from'].'-to-'.$report['to'].'.html"');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Design work report</title></head><body>';
} else page_start('Design & video work report','manager-monthly-design-report.php');
require __DIR__.'/includes/design-report-view.php';
if ($standalone) echo '</body></html>'; else page_end();