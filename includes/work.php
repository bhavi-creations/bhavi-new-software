<?php
require_once __DIR__ . '/bootstrap.php';

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
    ?><div class="metric-fields"><?php foreach (metric_definitions($department) as $name=>[$label,$type]): ?><div class="field"><label for="<?= h($name.$suffix) ?>"><?= h($label) ?></label><input id="<?= h($name.$suffix) ?>" name="<?= h($name) ?>" type="<?= $type==='text'?'text':'number' ?>" value="<?= h($values[$name]??($type==='text'?'':'0')) ?>" <?= $type==='text'?'maxlength="'.($name==='social_platform'?100:50).'"':'min="0" max="999999" step="'.($type==='decimal'?'0.01':'1').'" required' ?>></div><?php endforeach; ?></div><?php
}
function work_sheet(array $employee,string $date): array
{
    query('INSERT INTO daily_work_submissions (employee_id,department_id,work_date) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$employee['id'],$employee['department_id'],$date]);
    return one('SELECT s.*,d.code AS department_code FROM daily_work_submissions s JOIN departments d ON d.id=s.department_id WHERE s.employee_id=? AND s.work_date=? FOR UPDATE',[$employee['id'],$date]);
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
    $latest=one('SELECT e.task_status,e.remark FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id WHERE e.assignment_id=? ORDER BY s.work_date DESC,e.updated_at DESC,e.id DESC LIMIT 1',[$id]);
    query('UPDATE work_assignments SET status=?,employee_remark=? WHERE id=?',[$latest['task_status']??'pending',$latest['remark']??null,$id]);
}
function work_details(array $entry,string $department): array
{
    $fields=['Task'=>$entry['task_title']?:($entry['website_page_task']?:($entry['seo_task']?:'Work entry')),'Status'=>$entry['task_status'],'Remark'=>$entry['remark']];
    foreach (metric_definitions($department) as $name=>[$label]) $fields[$label]=$entry[$name];
    return $fields;
}
function report_filter(): array
{
    $from=date_input('from_date',['from_date'=>$_GET['from_date']??$_GET['date']??today()]);
    $to=date_input('to_date',['to_date'=>$_GET['to_date']??$from]);
    if ($to<$from) throw new InvalidArgumentException('The end date must be on or after the start date.');
    $department=(int)($_GET['department']??0); $employee=(int)($_GET['employee']??0);
    $where="s.submission_status='submitted' AND s.work_date BETWEEN ? AND ?"; $params=[$from,$to];
    if ($department) { $where.=' AND s.department_id=?'; $params[]=$department; }
    if ($employee) { $where.=' AND s.employee_id=?'; $params[]=$employee; }
    return compact('from','to','department','employee','where','params');
}
