<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(['admin','manager']); $error=null;
$assignmentView=$assignmentView??'new';
$editId=(int)($_GET['edit']??0);
$record=$editId?one('SELECT * FROM work_assignments WHERE id=? AND deleted_at IS NULL',[$editId]):null;
if ($editId && !$record) fail(404,'Assignment not found.');
if ($editId && $user['role']!=='admin') fail(403,'Only administrators can edit assignments.');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='delete_assignment') {
            if ($user['role']!=='admin') fail(403,'Only administrators can delete assignments.');
            query('UPDATE work_assignments SET deleted_at=NOW() WHERE id=?',[positive_id($_POST['id']??null)]);
            flash('Assignment deleted. Existing work reports are retained.'); redirect('manager-assigned-status.php');
        }
        $department=positive_id($_POST['department_id']??null); $employee=positive_id($_POST['employee_id']??null); $client=positive_id($_POST['client_id']??null);
        $date=date_input('work_date');
        $taskItems=[];
        if ($record) {
            $taskItems[]=['title'=>text_input('title',255),'description'=>text_input('description',5000,false)];
        } elseif (isset($_POST['tasks'])) {
            if (!is_array($_POST['tasks'])) throw new InvalidArgumentException('Enter valid task details.');
            foreach ($_POST['tasks'] as $task) {
                if (!is_array($task)) throw new InvalidArgumentException('Enter a valid task and brief.');
                $title=trim((string)($task['title']??''));
                $description=trim((string)($task['description']??''));
                if ($title==='' && $description==='') continue;
                if ($title==='' || $description==='' || mb_strlen($title)>255 || mb_strlen($description)>5000) throw new InvalidArgumentException('Each task needs a title and a brief of no more than 5,000 characters.');
                $taskItems[]=['title'=>$title,'description'=>$description];
            }
        } else {
            $taskItems[]=['title'=>text_input('title',255),'description'=>text_input('description',5000,false)];
        }
        if (!$taskItems) throw new InvalidArgumentException('Add at least one task with a title and brief.');
        if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND ep.department_id=? AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL",[$employee,$department])) throw new InvalidArgumentException('Choose an active employee from the selected department.');
        if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL AND is_active=1',[$client])) throw new InvalidArgumentException('Choose an active client.');
        db()->beginTransaction();
        $assignmentId=$record?(int)$record['id']:null;
        if ($record) {
            query('SELECT id FROM work_assignments WHERE id=? FOR UPDATE',[$editId]);
            if (count_value('SELECT COUNT(*) FROM daily_work_entries WHERE assignment_id=?',[$editId]) && ($employee!==(int)$record['employee_id'] || $client!==(int)$record['client_id'] || $date!==$record['work_date'] || $department!==(int)$record['department_id'])) throw new InvalidArgumentException('This assignment has work reports. You can edit its title and brief; create a new assignment to change its employee, client or date.');
            query('UPDATE work_assignments SET employee_id=?,department_id=?,client_id=?,title=?,description=?,work_date=? WHERE id=?',[$employee,$department,$client,$taskItems[0]['title'],$taskItems[0]['description'],$date,$editId]);
        } else {
            foreach ($taskItems as $task) {
                query('INSERT INTO work_assignments (employee_id,department_id,client_id,title,description,work_date,assigned_by) VALUES (?,?,?,?,?,?,?)',[$employee,$department,$client,$task['title'],$task['description'],$date,$user['id']]);
                if ($assignmentId===null) $assignmentId=(int)db()->lastInsertId();
            }
        }
        $taskSummary=implode(' · ',array_map(static fn($task)=>$task['title'],$taskItems));
        query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",[$employee,$user['id'],$record?'Work assignment updated':count($taskItems).' tasks assigned',$date.' · '.$taskSummary]);
        db()->commit(); flash($record?'Assignment updated.':count($taskItems).' tasks assigned. They are visible in the employee dashboard.'); redirect('manager-assign-work.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$value=static fn($key,$fallback='')=>$_POST[$key]??$record[$key]??$fallback;
$employeeList=array_filter(employees(),static fn($e)=>$e['account_status']==='active');
$filterEmployee=(int)($_GET['employee']??0);
$assignmentWhere=$filterEmployee?' AND a.employee_id=?':'';
$assignments=rows("SELECT a.*,u.full_name,d.name AS department_name,c.client_name,manager.full_name AS assigned_by_name,COALESCE(e.task_status,a.status) AS reported_status,COALESCE(e.time_spent_hours,a.time_spent_hours) AS reported_time,COALESCE(e.remark,a.employee_remark) AS reported_remark FROM work_assignments a JOIN users u ON u.id=a.employee_id JOIN departments d ON d.id=a.department_id JOIN clients c ON c.id=a.client_id JOIN users manager ON manager.id=a.assigned_by LEFT JOIN daily_work_entries e ON e.id=(SELECT latest.id FROM daily_work_entries latest JOIN daily_work_submissions sheet ON sheet.id=latest.submission_id WHERE latest.assignment_id=a.id ORDER BY sheet.work_date DESC,latest.updated_at DESC,latest.id DESC LIMIT 1) WHERE a.deleted_at IS NULL".$assignmentWhere." ORDER BY a.work_date DESC,u.full_name,a.id",$filterEmployee?[$filterEmployee]:[]);
$assignedEmployeeList=rows("SELECT DISTINCT u.id,u.full_name FROM users u JOIN work_assignments a ON a.employee_id=u.id WHERE a.deleted_at IS NULL ORDER BY u.full_name");
$taskNumbers=[]; $taskCounters=[];
foreach ($assignments as $assignment) {
    $group=$assignment['employee_id'].':'.$assignment['work_date'];
    $taskNumbers[$assignment['id']]=($taskCounters[$group]??0)+1;
    $taskCounters[$group]=$taskNumbers[$assignment['id']];
}
page_start($assignmentView==='status'?'Assigned work status':'New assignment',$assignmentView==='status'?'manager-assigned-status.php':'manager-assign-work.php'); error_message($error);
?><?php if ($assignmentView==='new'): ?><section class="panel"><h2><?= $record?'Edit assignment':'New assignment' ?></h2><form method="post"><?= csrf_field() ?><div class="fields"><div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" data-department-select="employee_id" required><option value="">Select department</option><?= options(departments(),'name',$value('department_id')) ?></select></div><div class="field"><label for="employee_id">Employee</label><select id="employee_id" name="employee_id" required><option value="">Select employee</option><?php foreach ($employeeList as $employee): ?><option value="<?= $employee['id'] ?>" data-department="<?= $employee['department_id'] ?>" <?= (string)$employee['id']===(string)$value('employee_id')?'selected':'' ?>><?= h($employee['full_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="client_id">Client</label><select id="client_id" name="client_id" required><option value="">Select client</option><?= options(clients(),'client_name',$value('client_id')) ?></select></div><div class="field"><label for="work_date">Work date</label><input type="date" id="work_date" name="work_date" value="<?= h($value('work_date',today())) ?>" required></div></div><?php if ($record): ?><div class="fields"><div class="field full"><label for="title">Task 1</label><input id="title" name="title" value="<?= h($value('title')) ?>" maxlength="255" required></div><div class="field full"><label for="description">Task 1 brief</label><textarea id="description" name="description" maxlength="5000"><?= h($value('description')) ?></textarea></div></div><?php else: ?><div data-assignment-tasks><?php $taskRows=is_array($_POST['tasks']??null)?$_POST['tasks']:[['title'=>'','description'=>'']]; foreach ($taskRows as $index=>$taskRow): $taskRow=is_array($taskRow)?$taskRow:[]; ?><div class="panel assignment-task" data-assignment-task><h3 data-task-heading>Task <?= $index+1 ?></h3><div class="fields"><div class="field full"><label for="task-title-<?= $index ?>">Task title</label><input id="task-title-<?= $index ?>" name="tasks[<?= $index ?>][title]" value="<?= h($taskRow['title']??'') ?>" maxlength="255" required></div><div class="field full"><label for="task-brief-<?= $index ?>">Task brief</label><textarea id="task-brief-<?= $index ?>" name="tasks[<?= $index ?>][description]" maxlength="5000" required><?= h($taskRow['description']??'') ?></textarea></div></div><?php if ($index>0): ?><button type="button" class="button secondary" data-remove-assignment-task>Remove task</button><?php endif; ?></div><?php endforeach; ?></div><button type="button" class="button secondary" data-add-assignment-task>Add task</button><?php endif; ?><div class="form-actions"><button class="primary" type="submit"><?= $record?'Save changes':'Assign work' ?></button><?php if ($record): ?><a class="button secondary" href="manager-assign-work.php">Cancel</a><?php endif; ?></div></form></section>
<?php endif; if ($assignmentView==='status'):
$assignedEmployees=[]; $completedTasks=0;
foreach ($assignments as $assignment) {
 $eid=$assignment['employee_id'];
 if (!isset($assignedEmployees[$eid])) $assignedEmployees[$eid]=['name'=>$assignment['full_name'],'department'=>$assignment['department_name'],'total'=>0,'completed'=>0];
 $assignedEmployees[$eid]['total']++;
 if ($assignment['reported_status']==='completed') { $assignedEmployees[$eid]['completed']++; $completedTasks++; }
}
$finishedEmployees=count(array_filter($assignedEmployees,static fn($e)=>$e['total']===$e['completed']));
?><form method="get" class="filters"><div class="field"><label for="employee">Employee</label><select id="employee" name="employee"><option value="">All employees</option><?= options($assignedEmployeeList,'full_name',$filterEmployee) ?></select></div><button class="primary" type="submit">Show employee tasks</button></form><?php
?><div class="stats-grid"><article class="stat-card"><h2>Employees assigned</h2><strong><?= count($assignedEmployees) ?></strong></article><article class="stat-card"><h2>Employees completed all work</h2><strong><?= $finishedEmployees ?></strong></article><article class="stat-card"><h2>Completed tasks</h2><strong><?= $completedTasks ?></strong></article><article class="stat-card"><h2>Pending tasks</h2><strong><?= count($assignments)-$completedTasks ?></strong></article></div>
<section class="panel"><h2>Employee progress</h2><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Department</th><th>Assigned</th><th>Completed</th><th>Pending</th></tr></thead><tbody><?php if (!$assignedEmployees) empty_row(5); foreach ($assignedEmployees as $employee): ?><tr><td><?= h($employee['name']) ?></td><td><?= h($employee['department']) ?></td><td><?= $employee['total'] ?></td><td><?= $employee['completed'] ?></td><td><?= $employee['total']-$employee['completed'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel"><h2><?= $filterEmployee?'Selected employee tasks':'Assigned work' ?></h2><div class="table-scroll"><table><thead><tr><th>Date</th><th>Employee / department</th><th>Client</th><th>Task</th><th>Status</th><th>Time (hours)</th><th>Employee remark</th><th>Assigned by</th><th>Actions</th></tr></thead><tbody><?php if (!$assignments) empty_row(9); foreach ($assignments as $assignment): ?><tr><td><?= h($assignment['work_date']) ?></td><td><?= h($assignment['full_name']) ?><br><small class="muted"><?= h($assignment['department_name']) ?></small></td><td><?= h($assignment['client_name']) ?></td><td class="wrap">Task <?= $taskNumbers[$assignment['id']] ?> · <?= h($assignment['title']) ?></td><td><?= status_badge($assignment['reported_status']) ?></td><td><?= h($assignment['reported_time']) ?></td><td class="wrap"><?= h($assignment['reported_remark']) ?></td><td><?= h($assignment['assigned_by_name']) ?></td><td><div class="actions"><?php detail_button('assignment-'.$assignment['id']); ?><a class="icon-button" href="manager-assign-work.php?edit=<?= $assignment['id'] ?>" title="Edit assignment" aria-label="Edit assignment"><?= icon('edit') ?></a><?php delete_button((int)$assignment['id'],'delete_assignment','Delete this assignment?'); ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($assignments as $assignment) detail_dialog('assignment-'.$assignment['id'],$assignment['title'],['Employee'=>$assignment['full_name'],'Department'=>$assignment['department_name'],'Client'=>$assignment['client_name'],'Date'=>$assignment['work_date'],'Task'=>$assignment['title'],'Brief'=>$assignment['description'],'Status'=>$assignment['reported_status'],'Time spent (hours)'=>$assignment['reported_time'],'Employee remark / issue'=>$assignment['reported_remark'],'Assigned by'=>$assignment['assigned_by_name']]); endif; if ($assignmentView==='new'): ?><script src="assets/js/records.js" defer></script><?php endif; page_end(); ?>
