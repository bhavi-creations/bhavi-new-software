<?php
require_once __DIR__ . '/bootstrap.php';

function creative_work_department(string $code, string $name=''): bool
{
    foreach ([$code,$name] as $value) {
        $normalized=trim(preg_replace('/[^a-z0-9]+/','_',strtolower($value)),'_');
        if (in_array($normalized,['design_video','design_and_video'],true) || preg_match('/(?:^|_)(?:graphic_design(?:er|ing)?|video_edit(?:ing|or))(?:_|$)/',$normalized)) return true;
    }
    return false;
}
function creative_work_types(): array
{
    return ['poster'=>'Poster','video_editing'=>'Video Editing','carousel'=>'Carousel'];
}
function work_type_label(?string $type): string
{
    return (creative_work_types()+['reel'=>'Reel'])[$type??'']??'';
}
function work_type_input(array $source, bool $required=true): ?string
{
    $type=$source['work_type']??'';
    if ($type==='' && !$required) return null;
    if (!is_string($type) || !array_key_exists($type,creative_work_types())) throw new InvalidArgumentException('Choose Poster, Video Editing or Carousel.');
    return $type;
}
function render_work_type(array $values=[], string $suffix='', bool $required=true): void
{
    ?><div class="field"><label for="work-type<?= h($suffix) ?>">Task type</label><select id="work-type<?= h($suffix) ?>" name="work_type" <?= $required?'required':'' ?>><option value="">Choose task type</option><?php foreach (creative_work_types() as $value=>$label): ?><option value="<?= h($value) ?>" <?= ($values['work_type']??'')===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><?php
}
function creative_task_data(array $task): array
{
    $common=['work_type'=>work_type_input($task)];
    foreach (['task_title'=>255,'remark'=>5000] as $name=>$max) {
        $value=$task[$name]??'';
        if (!is_string($value) || ($name==='task_title' && trim($value)==='') || mb_strlen($value)>$max) throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$name).' (up to '.$max.' characters).');
        $common[$name]=trim($value);
    }
    $status=$task['task_status']??'';
    if (!in_array($status,['pending','completed'],true)) throw new InvalidArgumentException('Choose Pending or Completed.');
    $common['task_status']=$status;
    $duration=$task['time_spent_hours']??null;
    if (!is_string($duration) || trim($duration)==='') throw new InvalidArgumentException('Enter the time spent on each task, for example 45 min or 02:30.');
    $common['time_spent_minutes']=parse_work_duration($duration);
    $client=$task['client_id']??'';
    $common['client_id']=$client===''?null:positive_id($client);
    if ($common['client_id']!==null && !one('SELECT id FROM clients WHERE id=? AND is_active=1 AND deleted_at IS NULL',[$common['client_id']])) throw new InvalidArgumentException('Choose an active client.');
    return $common;
}
function social_media_task_data(array $task): array
{
    $type=$task['work_type']??'';
    $labels=['poster'=>'Poster','reel'=>'Reel','carousel'=>'Carousel'];
    if (!is_string($type) || !isset($labels[$type])) throw new InvalidArgumentException('Choose Poster, Reel or Carousel.');
    $count=$task['item_count']??'';
    if (!is_string($count) || !preg_match('/^[1-9]\d{0,5}$/D',$count)) throw new InvalidArgumentException('Enter a quantity between 1 and 999999.');
    $client=positive_id($task['client_id']??null);
    $clientRow=one('SELECT id FROM clients WHERE id=? AND is_active=1 AND deleted_at IS NULL',[$client]);
    if (!$clientRow) throw new InvalidArgumentException('Choose an active client.');
    $platforms=$task['platforms']??[];
    if (!is_array($platforms) || !$platforms) throw new InvalidArgumentException('Select at least one social media platform for this client.');
    $available=rows('SELECT id,platform_name FROM client_social_links WHERE client_id=? ORDER BY id',[$client]);
    $availableById=[]; foreach($available as $link) $availableById[(int)$link['id']]=$link;
    $selected=[];
    foreach ($platforms as $platformId) {
        $id=filter_var($platformId,FILTER_VALIDATE_INT);
        if (!$id || !isset($availableById[(int)$id])) throw new InvalidArgumentException('Choose social platforms saved for this client.');
        $selected[]=$availableById[(int)$id]['platform_name'];
    }
    $selected=array_values(array_unique($selected));
    $remark=$task['remark']??'';
    if (!is_string($remark) || mb_strlen($remark)>5000) throw new InvalidArgumentException('Remark must be up to 5000 characters.');
    return ['client_id'=>$client,'work_type'=>$type,'item_count'=>(int)$count,'social_platform'=>implode(', ',$selected),'remark'=>trim($remark),'task_title'=>$labels[$type].' · '.$count,'task_status'=>'completed','platforms'=>$selected];
}
function metric_definitions(string $department): array
{
    return [
        'website' => ['website_new_count'=>['New pages','number'],'website_changes_count'=>['Changes','number']],
        'seo' => ['seo_quantity'=>['Quantity','decimal'],'seo_quantity_unit'=>['Unit (pages, posts…)','text']],
        'design_video' => ['video_count'=>['Videos','number'],'poster_count'=>['Posters','number'],'carousel_count'=>['Carousels','number'],'design_changes_count'=>['Changes','number']],
        'telecaller' => ['calls_count'=>['Calls','number'],'connected_count'=>['Connected','number'],'followups_count'=>['Follow-ups','number'],'leads_count'=>['Leads','number']],
        'social_media' => ['social_platform'=>['Platform','text'],'posts_count'=>['Posts','number'],'reels_count'=>['Reels','number'],'replies_count'=>['Replies','number']],
    ][$department] ?? [];
}
function work_metrics(string $department): array
{
    $metrics=[];
    foreach (metric_definitions($department) as $name=>[$label,$type]) {
        $value=$_POST[$name]??($type==='text'?'':'0');
        if ($value==='' && $type!=='text') $value='0';
        if (!is_string($value)) throw new InvalidArgumentException('Enter a valid '.$label.'.');
        if ($type==='text') {
            if (mb_strlen($value)>($name==='social_platform'?100:50)) throw new InvalidArgumentException($label.' is too long.');
            $metrics[$name]=trim($value);
        } else {
            if (!preg_match($type==='decimal'?'/^\d{1,6}(\.\d{1,2})?$/D':'/^\d{1,6}$/D',$value)) throw new InvalidArgumentException('Enter a non-negative '.$label.' quantity.');
            $metrics[$name]=$value;
        }
    }
    if ($department==='telecaller' && (int)$metrics['connected_count']>(int)$metrics['calls_count']) throw new InvalidArgumentException('Connected calls cannot exceed total calls.');
    return $metrics;
}
function render_metrics(string $department, array $values=[], string $suffix=''): void
{
    ?><div class="metric-fields"><?php foreach (metric_definitions($department) as $name=>[$label,$type]): ?><div class="field"><label for="<?= h($name.$suffix) ?>"><?= h($label) ?></label><input id="<?= h($name.$suffix) ?>" name="<?= h($name) ?>" type="<?= $type==='text'?'text':'number' ?>" value="<?= h($values[$name]??($type==='text'?'':'0')) ?>" <?= $type==='text'?'maxlength="'.($name==='social_platform'?100:50).'"':'min="0" max="999999" step="'.($type==='decimal'?'0.01':'1').'"' ?>></div><?php endforeach; ?></div><?php
}
function work_sheet(array $employee,string $date): array
{
    query('INSERT INTO daily_work_submissions (employee_id,department_id,work_date) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$employee['id'],$employee['department_id'],$date]);
    return one('SELECT s.*,d.code AS department_code,d.name AS department_name FROM daily_work_submissions s JOIN departments d ON d.id=s.department_id WHERE s.employee_id=? AND s.work_date=? FOR UPDATE',[$employee['id'],$date]);
}
function submit_sheet(array $sheet, bool $notify=true): void
{
    query("UPDATE daily_work_submissions SET submission_status='submitted',submitted_at=NOW(),review_status='pending',reviewed_by=NULL,reviewed_at=NULL,manager_remark=NULL WHERE id=?",[$sheet['id']]);
    if ($notify) notify_staff('work_submitted',current_user()['full_name'].' submitted daily work',$sheet['work_date'].' · '.(current_user()['department_name']??''),(int)$sheet['id']);
}
function save_work_entry(array $sheet, string $department, array $common, array $metrics, ?int $id=null): int
{
    $data=array_merge($common,$metrics);
    if ($department==='website') { $data['website_page_task']=$common['task_title']; $data['website_status']=$common['task_status']==='completed'?'completed':'in_progress'; }
    if ($department==='seo') { $data['seo_task']=$common['task_title']; $data['seo_status']=$common['task_status']==='completed'?'completed':'in_progress'; }
    if ($id) {
        $set=implode(',',array_map(static fn($name)=>'`'.$name.'`=?',array_keys($data)));
        query('UPDATE daily_work_entries SET '.$set.' WHERE id=? AND submission_id=?',[...array_values($data),$id,$sheet['id']]);
        return $id;
    }
    $data['submission_id']=$sheet['id'];
    $data['row_order']=count_value('SELECT COALESCE(MAX(row_order),0)+1 FROM daily_work_entries WHERE submission_id=?',[$sheet['id']]);
    $columns=implode(',',array_map(static fn($name)=>'`'.$name.'`',array_keys($data)));
    query('INSERT INTO daily_work_entries ('.$columns.') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
    return (int)db()->lastInsertId();
}
function sync_assignment(int $id): void
{
    $latest=one('SELECT e.task_status,e.remark,e.time_spent_minutes FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id WHERE e.assignment_id=? ORDER BY s.work_date DESC,e.updated_at DESC,e.id DESC LIMIT 1',[$id]);
    query('UPDATE work_assignments SET status=?,employee_remark=?,time_spent_minutes=? WHERE id=?',[$latest['task_status']??'pending',$latest['remark']??null,$latest['time_spent_minutes']??0,$id]);
}
function parse_work_duration(string $value): int
{
    $value=trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})$/D',$value,$matches)) {
        if ((int)$matches[2]>59) throw new InvalidArgumentException('Minutes must be between 00 and 59.');
        $total=(int)$matches[1]*60+(int)$matches[2];
    } elseif (preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',$value)) {
        $hours=(float)$value;
        if ($hours>24) throw new InvalidArgumentException('Time spent must be between 0 and 24 hours.');
        $total=(int)round($hours*60);
    } elseif (preg_match('/^(?:(\d{1,2})\s*(?:hours?|hrs?|h))?\s*(?:(\d{1,4})\s*(?:minutes?|mins?|m))?$/iD',$value,$matches) && (($matches[1]??'')!=='' || ($matches[2]??'')!=='')) {
        $hours=(int)($matches[1]??0);
        $minutes=(int)($matches[2]??0);
        if (($matches[1]??'')!=='' && $minutes>59) throw new InvalidArgumentException('Minutes must be below 60 when hours are included.');
        $total=$hours*60+$minutes;
    } else {
        throw new InvalidArgumentException('Enter time like 2 hours 30 min, 02:30, 2.5 or 45 min (up to 24 hours).');
    }
    if ($total>1440) throw new InvalidArgumentException('Time spent must be between 0 and 24 hours.');
    return $total;
}
function format_work_duration($minutes): string
{
    $minutes=max(0,(int)$minutes);
    $hours=intdiv($minutes,60);
    $remaining=$minutes%60;
    $parts=[];
    if ($hours) $parts[]=$hours.' '.($hours===1?'hour':'hours');
    if ($remaining || !$hours) $parts[]=$remaining.' min';
    return implode(' ',$parts);
}
function work_details(array $entry,string $department): array
{
    $fields=['Task'=>$entry['task_title']?:($entry['website_page_task']?:($entry['seo_task']?:'Work entry')),'Status'=>$entry['task_status']];
    if (!empty($entry['work_type'])) $fields=['Task type'=>work_type_label($entry['work_type'])]+$fields;
    if (array_key_exists('time_spent_minutes',$entry)) $fields['Time spent']=$entry['time_spent_minutes']===null?'—':format_work_duration($entry['time_spent_minutes']);
    $fields['Remark']=$entry['remark'];
    foreach (metric_definitions($department) as $name=>[$label]) $fields[$label]=$entry[$name];
    return $fields;
}
function report_work_status(array $report): string
{
    return (int)$report['entry_count']>0 && (int)$report['completed_count']===(int)$report['entry_count']?'completed':'pending';
}
function assignment_filter(array $user): array
{
    $where='a.deleted_at IS NULL AND u.deleted_at IS NULL'; $params=[];
    $employee=$user['role']==='employee'?(int)$user['id']:(int)($_GET['employee']??0);
    $department=$user['role']==='employee'?0:(int)($_GET['department']??0);
    if ($employee) { $where.=' AND a.employee_id=?'; $params[]=$employee; }
    if ($department) { $where.=' AND a.department_id=?'; $params[]=$department; }
    if (isset($_GET['assignment_date'])) { $where.=' AND a.work_date=?'; $params[]=date_input('assignment_date',$_GET); }
    return compact('where','params');
}
function assignment_rows(array $filter): array
{
    return rows("SELECT a.*,u.full_name,d.name AS department_name,c.client_name,manager.full_name AS assigned_by_name,COALESCE(e.task_status,a.status) AS reported_status,COALESCE(e.time_spent_minutes,a.time_spent_minutes) AS reported_time,COALESCE(e.remark,a.employee_remark) AS reported_remark FROM work_assignments a JOIN users u ON u.id=a.employee_id JOIN departments d ON d.id=a.department_id LEFT JOIN clients c ON c.id=a.client_id JOIN users manager ON manager.id=a.assigned_by LEFT JOIN daily_work_entries e ON e.id=(SELECT latest.id FROM daily_work_entries latest JOIN daily_work_submissions sheet ON sheet.id=latest.submission_id WHERE latest.assignment_id=a.id ORDER BY sheet.work_date DESC,latest.updated_at DESC,latest.id DESC LIMIT 1) WHERE ".$filter['where']." ORDER BY a.work_date DESC,u.full_name,a.id",$filter['params']);
}
function report_filter(): array
{
    $allDates=($_GET['all_dates']??'')==='1';
    $from=$allDates?null:date_input('from_date',['from_date'=>$_GET['from_date']??$_GET['date']??today()]);
    $to=$allDates?null:date_input('to_date',['to_date'=>$_GET['to_date']??$from]);
    if (!$allDates && $to<$from) throw new InvalidArgumentException('The end date must be on or after the start date.');
    $department=(int)($_GET['department']??0); $employee=(int)($_GET['employee']??0);
    $where="s.submission_status='submitted' AND u.deleted_at IS NULL"; $params=[];
    if (!$allDates) { $where.=' AND s.work_date BETWEEN ? AND ?'; $params[]=$from; $params[]=$to; }
    if ($department) { $where.=' AND s.department_id=?'; $params[]=$department; }
    if ($employee) { $where.=' AND s.employee_id=?'; $params[]=$employee; }
    return compact('from','to','allDates','department','employee','where','params');
}
