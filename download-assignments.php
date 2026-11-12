<?php
require_once __DIR__.'/includes/work.php';
require_once __DIR__.'/includes/pdf-export.php';
$user=require_roles(['admin','manager','employee']);
try { $assignments=assignment_rows(assignment_filter($user)); }
catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
// Employees see the priority; only management sees who assigned the task.
$staffExport=$user['role']!=='employee';
$records=(static function() use ($assignments,$staffExport): Generator {
    foreach ($assignments as $index=>$row) {
        $line=[$index+1,$row['work_date'],$row['full_name'],$row['department_name'],$row['client_name'],$row['title'],assignment_priority_label($row['priority']??null),$row['description'],$row['reported_status'],format_work_duration($row['reported_time']),$row['reported_remark']];
        if ($staffExport) $line[]=$row['assigned_by_name'];
        yield $line;
    }
})();
$headers=['S.no','Date','Employee','Department','Client','Task','Priority','Brief','Status','Time spent','Employee remark'];
if ($staffExport) $headers[]='Assigned by';
download_pdf($headers,$records,'bhavi-assigned-work');
