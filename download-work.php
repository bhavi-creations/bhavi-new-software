<?php
require_once __DIR__ . '/includes/work.php';
require_once __DIR__ . '/includes/excel-export.php';
require_roles(['admin','manager']);
try { $filter=report_filter(); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$metrics=[]; foreach (['website','seo','design_video','telecaller','social_media'] as $department) foreach (metric_definitions($department) as $key=>[$label]) $metrics[$key]=$label;
$stmt=query('SELECT s.work_date,u.full_name,u.username,d.name AS department_name,c.client_name,s.submitted_at,s.review_status,s.manager_remark,e.* FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id JOIN daily_work_entries e ON e.submission_id=s.id LEFT JOIN clients c ON c.id=e.client_id WHERE '.$filter['where'].' ORDER BY s.work_date,u.full_name,e.row_order',$filter['params']);
$records=(static function() use ($stmt,$metrics): Generator {
    $number=0;
    while ($row=$stmt->fetch()) {
        $values=[++$number,$row['work_date'],$row['full_name'],$row['username'],$row['department_name'],$row['client_name'],$row['task_title'],$row['task_status'],array_key_exists('time_spent_minutes',$row)&&$row['time_spent_minutes']!==null?format_work_duration($row['time_spent_minutes']):'',$row['remark']];
        foreach ($metrics as $key=>$label) $values[]=$row[$key];
        array_push($values,$row['submitted_at'],$row['review_status'],$row['manager_remark']);
        yield $values;
    }
})();
$filename=$filter['allDates']?'bhavi-work-all-dates':'bhavi-work-'.$filter['from'].'-to-'.$filter['to'];
download_excel(['S.no','Date','Employee','Username','Department','Client','Task','Status','Time spent','Remark',...array_values($metrics),'Submitted at','Review','Manager remark'],$records,$filename);
