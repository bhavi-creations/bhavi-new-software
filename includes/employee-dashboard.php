<?php
require_once __DIR__ . '/layout.php'; require_once __DIR__ . '/work.php';
$user=require_roles(['employee']);
if (isset($expectedDepartment) && $user['department_code']!==$expectedDepartment) redirect(dashboard_path($user));
try { $date=date_input('date',['date'=>$_GET['date']??today()]); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if ($date>today()) throw new InvalidArgumentException('You can submit work for today or an earlier date.');
        $action=$_POST['action']??'';
        db()->beginTransaction();
        if ($action==='update_assignment') {
            $assignment=one('SELECT * FROM work_assignments WHERE id=? AND employee_id=? AND deleted_at IS NULL FOR UPDATE',[positive_id($_POST['assignment_id']??null),$user['id']]);
            if (!$assignment) fail(403,'You can only update your own assigned work.');
            if ($assignment['work_date']>$date) throw new InvalidArgumentException('This task is assigned for a later date.');
            $title=$assignment['title']; $client=(int)$assignment['client_id'];
        } elseif ($action==='save_entry') {
            $title=text_input('task_title',255); $client=positive_id($_POST['client_id']??null);
            if (!one('SELECT id FROM clients WHERE id=? AND is_active=1 AND deleted_at IS NULL',[$client])) throw new InvalidArgumentException('Choose an active client.');
        } elseif ($action!=='submit_day') throw new InvalidArgumentException('Invalid action.');
        $sheet=work_sheet($user,$date);
        if ($action==='submit_day') {
            if (!count_value('SELECT COUNT(*) FROM daily_work_entries WHERE submission_id=?',[$sheet['id']])) throw new InvalidArgumentException('Add a work entry before submitting.');
            submit_sheet($sheet);
        } else {
            $status=$_POST['task_status']??'';
            if (!in_array($status,['pending','completed'],true)) throw new InvalidArgumentException('Choose Pending or Completed.');
            $remark=text_input('remark',5000,false); $metrics=work_metrics($sheet['department_code']);
            $entryId=null;
            if ($action==='update_assignment') {
                $existing=one('SELECT id FROM daily_work_entries WHERE submission_id=? AND assignment_id=?',[$sheet['id'],$assignment['id']]);
                $entryId=$existing?(int)$existing['id']:null;
            } elseif (!empty($_POST['entry_id'])) {
                $entryId=positive_id($_POST['entry_id']);
                if (!one('SELECT id FROM daily_work_entries WHERE id=? AND submission_id=? AND assignment_id IS NULL',[$entryId,$sheet['id']])) fail(403,'You can only edit your own work entries.');
            }
            $common=['client_id'=>$client,'task_title'=>$title,'task_status'=>$status,'remark'=>$remark];
            if ($action==='update_assignment') $common['assignment_id']=$assignment['id'];
            save_work_entry($sheet,$sheet['department_code'],$common,$metrics,$entryId);
            if ($action==='update_assignment') sync_assignment((int)$assignment['id']);
            if ($action==='update_assignment' || ($_POST['submit_mode']??'submitted')==='submitted') submit_sheet($sheet);
            else query("UPDATE daily_work_submissions SET submission_status='draft',submitted_at=NULL,review_status='pending',reviewed_by=NULL,reviewed_at=NULL,manager_remark=NULL WHERE id=?",[$sheet['id']]);
        }
        db()->commit(); flash($action==='save_entry' && ($_POST['submit_mode']??'')==='draft'?'Work saved as draft. Submit the day when ready.':'Work saved and submitted to your manager.');
        redirect(dashboard_path($user).'?date='.$date);
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$sheet=one('SELECT s.*,d.code AS department_code FROM daily_work_submissions s JOIN departments d ON d.id=s.department_id WHERE s.employee_id=? AND s.work_date=?',[$user['id'],$date]);
$workDepartment=$sheet['department_code']??$user['department_code'];
$entries=$sheet?rows('SELECT e.*,c.client_name FROM daily_work_entries e JOIN clients c ON c.id=e.client_id WHERE e.submission_id=? ORDER BY e.row_order',[$sheet['id']]):[];
$assignmentEntries=[]; foreach ($entries as $entry) if ($entry['assignment_id']) $assignmentEntries[$entry['assignment_id']]=$entry;
$assignments=rows("SELECT a.*,c.client_name,u.full_name AS assigned_by_name FROM work_assignments a JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.assigned_by WHERE a.employee_id=? AND a.deleted_at IS NULL AND a.work_date<=? AND (a.status='pending' OR a.work_date=? OR EXISTS (SELECT 1 FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id WHERE e.assignment_id=a.id AND s.work_date=?)) ORDER BY a.work_date,a.id",[$user['id'],$date,$date,$date]);
$nextHoliday=one('SELECT * FROM holidays WHERE is_published=1 AND holiday_date>=? ORDER BY holiday_date LIMIT 1',[today()]);
$latestLeave=one('SELECT * FROM leave_requests WHERE employee_id=? ORDER BY applied_at DESC,id DESC LIMIT 1',[$user['id']]);
$editEntry=null; if (!empty($_GET['edit_entry'])) { foreach ($entries as $entry) if ((int)$entry['id']===(int)$_GET['edit_entry'] && !$entry['assignment_id']) $editEntry=$entry; if (!$editEntry) fail(404,'Work entry not found.'); }
page_start($user['department_name'].' dashboard',dashboard_path($user)); error_message($error);
?><p class="muted">Welcome, <?= h($user['full_name']) ?>. Update your assigned work and keep your manager informed.</p><div class="stats-grid"><a class="stat-card" href="#assigned-work"><h2>Today's work</h2><strong><?= count($assignments) ?></strong><p>Assigned and pending tasks</p></a><a class="stat-card" href="#daily-sheet"><h2>Work entries</h2><strong><?= count($entries) ?></strong><p><?= h($date) ?></p></a><a class="stat-card" href="apply-leaves.php"><h2>Leave status</h2><strong style="font-size:23px"><?= h($latestLeave?ucfirst($latestLeave['status']):'No requests') ?></strong><p><?= h($latestLeave['from_date']??'Apply for leave') ?></p></a><a class="stat-card" href="admin-holidays.php"><h2>Next holiday</h2><strong style="font-size:23px"><?= h($nextHoliday?date('d M',strtotime($nextHoliday['holiday_date'])):'None scheduled') ?></strong><p><?= h($nextHoliday['holiday_name']??'View holiday calendar') ?></p></a></div>
<form method="get" class="filters"><div class="field"><label for="date">Work date</label><input id="date" name="date" type="date" value="<?= h($date) ?>" required></div><button class="primary" type="submit">View work</button></form>
<section class="panel" id="assigned-work"><h2>Assigned work</h2><?php if (!$assignments): ?><p class="muted">No assigned tasks for this date.</p><?php endif; foreach ($assignments as $assignment): $saved=$assignmentEntries[$assignment['id']]??[]; ?><article class="work-card"><div class="panel-heading"><h3><?= h($assignment['title']) ?></h3><?= status_badge($saved['task_status']??$assignment['status']) ?></div><p class="muted"><?= h($assignment['client_name']) ?> · Assigned <?= h($assignment['work_date']) ?> by <?= h($assignment['assigned_by_name']) ?></p><p><?= h($assignment['description']) ?></p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_assignment"><input type="hidden" name="assignment_id" value="<?= $assignment['id'] ?>"><div class="fields"><div class="field full"><label>Status</label><div class="status-choice"><?php foreach (['pending','completed'] as $status): ?><label><input type="radio" name="task_status" value="<?= $status ?>" <?= ($saved['task_status']??$assignment['status'])===$status?'checked':'' ?> required><?= ucfirst($status) ?></label><?php endforeach; ?></div></div><div class="field full"><label for="remark-<?= $assignment['id'] ?>">Remark</label><textarea id="remark-<?= $assignment['id'] ?>" name="remark" maxlength="5000"><?= h($saved['remark']??$assignment['employee_remark']??'') ?></textarea></div></div><?php render_metrics($workDepartment,$saved,'-assignment-'.$assignment['id']); ?><div class="form-actions" style="margin-top:20px"><button class="primary" type="submit">Save &amp; submit update</button></div></form></article><?php endforeach; ?></section>
<section class="panel" id="daily-sheet"><div class="panel-heading"><h2>Daily work sheet · <?= h($date) ?></h2><?= $sheet?status_badge($sheet['submission_status']):'' ?></div><?php if ($sheet && $sheet['manager_remark']): ?><p><strong>Manager feedback:</strong> <?= h($sheet['manager_remark']) ?> · <?= status_badge($sheet['review_status']) ?></p><?php endif; ?><div class="table-scroll"><table><thead><tr><th>S.no</th><th>Date</th><th>Client</th><th>Task</th><th>Status</th><th>Remark</th><th>Actions</th></tr></thead><tbody><?php if (!$entries) empty_row(7); foreach ($entries as $index=>$entry): ?><tr><td><?= $index+1 ?></td><td><?= h($date) ?></td><td><?= h($entry['client_name']) ?></td><td class="wrap"><?= h($entry['task_title']) ?></td><td><?= status_badge($entry['task_status']) ?></td><td class="wrap"><?= h($entry['remark']) ?></td><td><div class="actions"><?php detail_button('entry-'.$entry['id']); if (!$entry['assignment_id']): ?><a class="icon-button" href="<?= h(dashboard_path($user)) ?>?date=<?= h($date) ?>&amp;edit_entry=<?= $entry['id'] ?>#add-work" title="Edit work" aria-label="Edit work"><?= icon('edit') ?></a><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php if ($entries): ?><form method="post" style="margin-top:20px"><?= csrf_field() ?><input type="hidden" name="action" value="submit_day"><button class="primary" type="submit">Submit day to manager</button></form><?php endif; ?></section>
<section class="panel" id="add-work"><h2><?= $editEntry?'Edit work entry':'+ Add work row' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_entry"><input type="hidden" name="entry_id" value="<?= h($editEntry['id']??'') ?>"><div class="fields"><div class="field"><label for="client_id">Client</label><select id="client_id" name="client_id" required><option value="">Select client</option><?= options(clients(),'client_name',$editEntry['client_id']??null) ?></select></div><div class="field"><label for="task_title">Task / page</label><input id="task_title" name="task_title" value="<?= h($editEntry['task_title']??'') ?>" maxlength="255" required></div><div class="field full"><label>Status</label><div class="status-choice"><?php foreach (['pending','completed'] as $status): ?><label><input type="radio" name="task_status" value="<?= $status ?>" <?= ($editEntry['task_status']??'pending')===$status?'checked':'' ?> required><?= ucfirst($status) ?></label><?php endforeach; ?></div></div><div class="field full"><label for="remark">Remark</label><textarea id="remark" name="remark" maxlength="5000"><?= h($editEntry['remark']??'') ?></textarea></div></div><?php render_metrics($workDepartment,$editEntry??[],'-new'); ?><div class="form-actions" style="margin-top:20px"><button class="primary" name="submit_mode" value="submitted">Save &amp; submit</button><button class="button secondary" name="submit_mode" value="draft">Save draft</button></div></form></section>
<?php foreach ($entries as $entry) detail_dialog('entry-'.$entry['id'],'Work details',['Date'=>$date,'Client'=>$entry['client_name'],...work_details($entry,$workDepartment)]); page_end(); ?>
