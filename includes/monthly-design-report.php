<?php
require_once __DIR__.'/work.php';

function monthly_design_report(): array
{
    $month=$_GET['month']??date('Y-m');
    if (!is_string($month) || !preg_match('/^[1-9]\d{3}-\d{2}$/D',$month) || DateTimeImmutable::createFromFormat('!Y-m',$month)?->format('Y-m')!==$month) throw new InvalidArgumentException('Choose a valid report month.');
    $first=new DateTimeImmutable($month.'-01');
    $from=date_input('from_date',['from_date'=>$_GET['from_date']??$first->format('Y-m-d')]);
    $to=date_input('to_date',['to_date'=>$_GET['to_date']??$first->format('Y-m-t')]);
    if ($from>$to) throw new InvalidArgumentException('From date must be on or before To date.');
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366) throw new InvalidArgumentException('Choose a date range of up to 367 days.');
    $department=($_GET['department']??'')===''?0:filter_var($_GET['department'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $employee=($_GET['employee']??'')===''?0:filter_var($_GET['employee'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($department===false || $employee===false) throw new InvalidArgumentException('Choose a valid department and employee.');
    $departments=array_values(array_filter(rows('SELECT * FROM departments ORDER BY name'),static fn($row)=>creative_work_department($row['code'],$row['name'])));
    $departmentIds=array_column($departments,'id');
    if ($department && !in_array($department,array_map('intval',$departmentIds),true)) throw new InvalidArgumentException('Choose a Graphic Designer, Video Editing or Design & video department.');
    $selectedDepartments=$department?[$department]:($departmentIds?:[0]);
    $placeholders=implode(',',array_fill(0,count($selectedDepartments),'?'));
    $roster=rows("SELECT u.id,u.full_name,ep.joining_date,ep.designation,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.role='employee' AND u.deleted_at IS NULL AND d.id IN ($placeholders) ORDER BY u.full_name",$selectedDepartments);
    $entries=rows("SELECT e.*,s.employee_id,s.work_date,u.full_name,d.name AS department_name,c.client_name FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id LEFT JOIN clients c ON c.id=e.client_id WHERE s.submission_status='submitted' AND u.deleted_at IS NULL AND s.work_date>=? AND s.work_date<=? AND s.department_id IN ($placeholders) ORDER BY u.full_name,s.work_date,e.row_order,e.id",[$from,$to,...$selectedDepartments]);
    $people=[];
    foreach ($roster as $person) $people[$person['id']]=$person;
    foreach ($entries as $entry) if (!isset($people[$entry['employee_id']])) $people[$entry['employee_id']]=['id'=>$entry['employee_id'],'full_name'=>$entry['full_name'],'department_name'=>$entry['department_name']];
    uasort($people,static fn($a,$b)=>strcasecmp($a['full_name'],$b['full_name']));
    if ($employee && !isset($people[$employee])) throw new InvalidArgumentException('Choose an employee from the selected design departments.');
    $employees=array_values($people);
    if ($employee) $people=[$employee=>$people[$employee]];
    foreach ($people as &$person) $person+=['poster'=>0,'video_editing'=>0,'carousel'=>0,'pending'=>0,'unclassified'=>0,'entries'=>[]];
    unset($person);
    foreach ($entries as $entry) {
        if (!isset($people[$entry['employee_id']])) continue;
        $quantities=design_entry_quantities($entry);
        $entry['quantities']=$quantities;
        $people[$entry['employee_id']]['entries'][]=$entry;
        if ($entry['task_status']==='completed') {
            foreach ($quantities as $type=>$quantity) $people[$entry['employee_id']][$type]+=$quantity;
            if (!array_sum($quantities)) $people[$entry['employee_id']]['unclassified']++;
        } else $people[$entry['employee_id']]['pending']++;
    }
    return compact('from','to','month','first','department','employee','departments','employees','people');
}
function design_entry_quantities(array $entry): array
{
    $quantities=['poster'=>0,'video_editing'=>0,'carousel'=>0];
    $columns=['poster'=>'poster_count','video_editing'=>'video_count','carousel'=>'carousel_count'];
    if (isset($columns[$entry['work_type']??''])) $quantities[$entry['work_type']]=max(1,(int)($entry[$columns[$entry['work_type']]]??0));
    else foreach ($columns as $type=>$column) $quantities[$type]=(int)($entry[$column]??0);
    return $quantities;
}
function design_quantity_label(array $quantities): string
{
    $labels=['poster'=>'Poster','video_editing'=>'Reel / Video','carousel'=>'Carousel'];
    $parts=[];
    foreach ($quantities as $type=>$quantity) if ($quantity) $parts[]=$labels[$type].($quantity>1?' × '.$quantity:'');
    return implode(', ',$parts)?:'Unclassified';
}
