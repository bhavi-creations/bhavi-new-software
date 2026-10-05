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
        $date=date_input('work_date'); $title=text_input('title',255); $description=text_input('description',5000,false);
        if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND ep.department_id=? AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL",[$employee,$department])) throw new InvalidArgumentException('Choose an active employee from the selected department.');
        if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL AND is_active=1',[$client])) throw new InvalidArgumentException('Choose an active client.');
        db()->beginTransaction();
        $assignmentId=$record?(int)$record['id']:null;
        if ($record) {
            query('SELECT id FROM work_assignments WHERE id=? FOR UPDATE',[$editId]);
            if (count_value('SELECT COUNT(*) FROM daily_work_entries WHERE assignment_id=?',[$editId]) && ($employee!==(int)$record['employee_id'] || $client!==(int)$record['client_id'] || $date!==$record['work_date'] || $department!==(int)$record['department_id'])) throw new InvalidArgumentException('This assignment has work reports. You can edit its title and brief; create a new assignment to change its employee, client or date.');
            query('UPDATE work_assignments SET employee_id=?,department_id=?,client_id=?,title=?,description=?,work_date=? WHERE id=?',[$employee,$department,$client,$title,$description,$date,$editId]);
        } else {
            query('INSERT INTO work_assignments (employee_id,department_id,client_id,title,description,work_date,assigned_by) VALUES (?,?,?,?,?,?,?)',[$employee,$department,$client,$title,$description,$date,$user['id']]);
            $assignmentId=(int)db()->lastInsertId();
        }
        query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",[$employee,$user['id'],'Work assigned: '.$title,$date.' · '.$description]);
        db()->commit(); flash('Work assigned. It is visible in the employee dashboard.'); redirect('manager-assign-work.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$value=static fn($key,$fallback='')=>$_POST[$key]??$record[$key]??$fallback;
$employeeList=array_filter(employees(),static fn($e)=>$e['account_status']==='active');
$assignments=rows('SELECT a.*,u.full_name,d.name AS department_name,c.client_name,manager.full_name AS assigned_by_name FROM work_assignments a JOIN users u ON u.id=a.employee_id JOIN departments d ON d.id=a.department_id JOIN clients c ON c.id=a.client_id JOIN users manager ON manager.id=a.assigned_by WHERE a.deleted_at IS NULL ORDER BY a.work_date DESC,a.id DESC');
page_start($assignmentView==='status'?'Assigned work status':'New assignment',$assignmentView==='status'?'manager-assigned-status.php':'manager-assign-work.php'); error_message($error);
?><?php if ($assignmentView==='new'): ?><section class="panel"><h2><?= $record?'Edit assignment':'New assignment' ?></h2><form method="post"><?= csrf_field() ?><div class="fields"><div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" data-department-select="employee_id" required><option value="">Select department</option><?= options(departments(),'name',$value('department_id')) ?></select></div><div class="field"><label for="employee_id">Employee</label><select id="employee_id" name="employee_id" required><option value="">Select employee</option><?php foreach ($employeeList as $employee): ?><option value="<?= $employee['id'] ?>" data-department="<?= $employee['department_id'] ?>" <?= (string)$employee['id']===(string)$value('employee_id')?'selected':'' ?>><?= h($employee['full_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="client_id">Client</label><select id="client_id" name="client_id" required><option value="">Select client</option><?= options(clients(),'client_name',$value('client_id')) ?></select></div><div class="field"><label for="work_date">Work date</label><input type="date" id="work_date" name="work_date" value="<?= h($value('work_date',today())) ?>" required></div><div class="field full"><label for="title">Task / page</label><input id="title" name="title" value="<?= h($value('title')) ?>" maxlength="255" required></div><div class="field full"><label for="description">Work brief</label><textarea id="description" name="description" maxlength="5000"><?= h($value('description')) ?></textarea></div></div><div class="form-actions"><button class="primary" type="submit"><?= $record?'Save changes':'Assign work' ?></button><?php if ($record): ?><a class="button secondary" href="manager-assign-work.php">Cancel</a><?php endif; ?></div></form></section>
<?php endif; if ($assignmentView==='status'):
$assignedEmployees=[]; $completedTasks=0;
foreach ($assignments as $assignment) {
 $eid=$assignment['employee_id'];
 if (!isset($assignedEmployees[$eid])) $assignedEmployees[$eid]=['name'=>$assignment['full_name'],'department'=>$assignment['department_name'],'total'=>0,'completed'=>0];
 $assignedEmployees[$eid]['total']++;
 if ($assignment['status']==='completed') { $assignedEmployees[$eid]['completed']++; $completedTasks++; }
}
$finishedEmployees=count(array_filter($assignedEmployees,static fn($e)=>$e['total']===$e['completed']));
?><div class="stats-grid"><article class="stat-card"><h2>Employees assigned</h2><strong><?= count($assignedEmployees) ?></strong></article><article class="stat-card"><h2>Employees completed all work</h2><strong><?= $finishedEmployees ?></strong></article><article class="stat-card"><h2>Completed tasks</h2><strong><?= $completedTasks ?></strong></article><article class="stat-card"><h2>Pending tasks</h2><strong><?= count($assignments)-$completedTasks ?></strong></article></div>
<section class="panel"><h2>Employee progress</h2><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Department</th><th>Assigned</th><th>Completed</th><th>Pending</th></tr></thead><tbody><?php if (!$assignedEmployees) empty_row(5); foreach ($assignedEmployees as $employee): ?><tr><td><?= h($employee['name']) ?></td><td><?= h($employee['department']) ?></td><td><?= $employee['total'] ?></td><td><?= $employee['completed'] ?></td><td><?= $employee['total']-$employee['completed'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel"><h2>Assigned work</h2><div class="table-scroll"><table><thead><tr><th>Date</th><th>Employee / department</th><th>Client</th><th>Task</th><th>Status</th><th>Employee remark</th><th>Assigned by</th><th>Actions</th></tr></thead><tbody><?php if (!$assignments) empty_row(8); foreach ($assignments as $assignment): ?><tr><td><?= h($assignment['work_date']) ?></td><td><?= h($assignment['full_name']) ?><br><small class="muted"><?= h($assignment['department_name']) ?></small></td><td><?= h($assignment['client_name']) ?></td><td class="wrap"><?= h($assignment['title']) ?></td><td><?= status_badge($assignment['status']) ?></td><td class="wrap"><?= h($assignment['employee_remark']) ?></td><td><?= h($assignment['assigned_by_name']) ?></td><td><div class="actions"><?php detail_button('assignment-'.$assignment['id']); ?><a class="icon-button" href="manager-assign-work.php?edit=<?= $assignment['id'] ?>" title="Edit assignment" aria-label="Edit assignment"><?= icon('edit') ?></a><?php delete_button((int)$assignment['id'],'delete_assignment','Delete this assignment?'); ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($assignments as $assignment) detail_dialog('assignment-'.$assignment['id'],$assignment['title'],['Employee'=>$assignment['full_name'],'Department'=>$assignment['department_name'],'Client'=>$assignment['client_name'],'Date'=>$assignment['work_date'],'Task'=>$assignment['title'],'Brief'=>$assignment['description'],'Status'=>$assignment['status'],'Employee remark'=>$assignment['employee_remark'],'Assigned by'=>$assignment['assigned_by_name']]); endif; page_end(); ?>
