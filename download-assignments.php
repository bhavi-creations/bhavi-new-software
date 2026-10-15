<?php
require_once __DIR__.'/includes/work.php';
require_once __DIR__.'/includes/excel-export.php';
$user=require_roles(['admin','manager','employee']);
try { $assignments=assignment_rows(assignment_filter($user)); }
catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$records=(static function() use ($assignments): Generator {
    foreach ($assignments as $index=>$row) {
        yield [$index+1,$row['work_date'],$row['full_name'],$row['department_name'],$row['client_name'],$row['title'],$row['description'],$row['reported_status'],format_work_duration($row['reported_time']),$row['reported_remark'],$row['assigned_by_name']];
    }
})();
download_excel(['S.no','Date','Employee','Department','Client','Task','Brief','Status','Time spent','Employee remark','Assigned by'],$records,'bhavi-assigned-work');
