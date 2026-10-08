<?php
require_once __DIR__ . '/layout.php'; require_once __DIR__ . '/work.php';
$user=require_roles(['employee']);
$employeeView=$employeeView??'dashboard';
$viewRoutes=['daily'=>'employee-daily-work.php','history'=>'employee-work-history.php','assigned'=>'employee-assigned-work.php'];
$viewRoute=$viewRoutes[$employeeView]??dashboard_path($user);
if (isset($expectedDepartment) && $user['department_code']!==$expectedDepartment) redirect(dashboard_path($user));
try { $date=$employeeView==='assigned'?today():date_input('date',['date'=>$_GET['date']??today()]); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$assignmentDate=null;
if ($employeeView==='assigned' && isset($_GET['assignment_date'])) {
    try { $assignmentDate=date_input('assignment_date',['assignment_date'=>$_GET['assignment_date']]); }
    catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
}
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if ($date>today()) throw new InvalidArgumentException('You can submit work for today or an earlier date.');
        $action=$_POST['action']??'';
        $title=''; $client=null; $assignmentId=null;
        db()->beginTransaction();
        if ($action==='update_assignment') {
            $assignment=one('SELECT * FROM work_assignments WHERE id=? AND employee_id=? AND deleted_at IS NULL FOR UPDATE',[positive_id($_POST['assignment_id']??null),$user['id']]);
            if (!$assignment) fail(403,'You can only update your own assigned work.');
            $assignmentId=(int)$assignment['id'];
            $title=$assignment['title']; $client=(int)$assignment['client_id'];
        } elseif ($action==='save_entry') {
            $title=text_input('task_title',255); $client=empty($_POST['client_id'])?null:positive_id($_POST['client_id']);
            if ($client!==null && !one('SELECT id FROM clients WHERE id=? AND is_active=1 AND deleted_at IS NULL',[$client])) throw new InvalidArgumentException('Choose an active client.');
        } elseif ($action!=='submit_day') throw new InvalidArgumentException('Invalid action.');
        $sheet=work_sheet($user,$date);
        if ($action==='submit_day') {
            if (!count_value('SELECT COUNT(*) FROM daily_work_entries WHERE submission_id=?',[$sheet['id']])) throw new InvalidArgumentException('Add a work entry before submitting.');
            submit_sheet($sheet);
        } else {
            $status=$_POST['task_status']??'';
            if (!in_array($status,['pending','completed'],true)) throw new InvalidArgumentException('Choose Pending or Completed.');
            $remark=text_input('remark',5000,false); $metrics=$action==='update_assignment'?[]:work_metrics($sheet['department_code']);
            $timeSpent=null;
            if ($action==='update_assignment') {
                $timeSpent=$_POST['time_spent_hours']??null;
                if (!is_string($timeSpent) || !preg_match('/^\d{1,5}(\.\d{1,2})?$/D',$timeSpent) || (float)$timeSpent>99999.99) throw new InvalidArgumentException('Enter time spent in hours (0 to 99,999.99).');
            }
            $entryId=null;
            if ($action==='update_assignment') {
                $existing=one('SELECT id FROM daily_work_entries WHERE submission_id=? AND assignment_id=?',[$sheet['id'],$assignmentId]);
                $entryId=$existing?(int)$existing['id']:null;
            } elseif (!empty($_POST['entry_id'])) {
                $entryId=positive_id($_POST['entry_id']);
                if (!one('SELECT id FROM daily_work_entries WHERE id=? AND submission_id=? AND assignment_id IS NULL',[$entryId,$sheet['id']])) fail(403,'You can only edit your own work entries.');
            }
            $common=['client_id'=>$client,'task_title'=>$title,'task_status'=>$status,'remark'=>$remark];
            if ($assignmentId!==null) { $common['assignment_id']=$assignmentId; $common['time_spent_hours']=$timeSpent; }
            save_work_entry($sheet,$sheet['department_code'],$common,$metrics,$entryId);
            if ($assignmentId!==null) sync_assignment($assignmentId);
            if ($action==='update_assignment') {
                submit_sheet($sheet,false);
                $taskMessage=$assignment['title'].' · '.$status.' · '.$timeSpent.' hours'.($remark!==''?' · '.$remark:'');
                notify_staff('work_submitted',$user['full_name'].' submitted an assigned task update',$date.' · '.$taskMessage,(int)$sheet['id']);
            } elseif (($_POST['submit_mode']??'submitted')==='submitted') submit_sheet($sheet);
            else query("UPDATE daily_work_submissions SET submission_status='draft',submitted_at=NULL,review_status='pending',reviewed_by=NULL,reviewed_at=NULL,manager_remark=NULL WHERE id=?",[$sheet['id']]);
        }
        db()->commit(); flash($action==='save_entry' && ($_POST['submit_mode']??'')==='draft'?'Work saved as draft. Submit the day when ready.':'Work saved and submitted to your manager.');
        $redirectQuery=['date'=>$date];
        if ($employeeView==='assigned' && $assignmentDate) $redirectQuery['assignment_date']=$assignmentDate;
        redirect(($employeeView==='daily'?'employee-work-history.php':$viewRoute).'?'.http_build_query($redirectQuery));
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$sheet=one('SELECT s.*,d.code AS department_code FROM daily_work_submissions s JOIN departments d ON d.id=s.department_id WHERE s.employee_id=? AND s.work_date=?',[$user['id'],$date]);
$workDepartment=$sheet['department_code']??$user['department_code'];
$entries=$sheet?rows('SELECT e.*,c.client_name FROM daily_work_entries e LEFT JOIN clients c ON c.id=e.client_id WHERE e.submission_id=? ORDER BY e.row_order',[$sheet['id']]):[];
$assignmentEntries=[]; foreach ($entries as $entry) if ($entry['assignment_id']) $assignmentEntries[$entry['assignment_id']]=$entry;
$assignmentDates=[];
if ($employeeView==='assigned') {
    $assignmentDates=rows('SELECT work_date,COUNT(*) AS task_count,GROUP_CONCAT(title ORDER BY id SEPARATOR \' · \') AS task_titles FROM work_assignments WHERE employee_id=? AND deleted_at IS NULL GROUP BY work_date ORDER BY work_date',[ $user['id'] ]);
    $assignments=$assignmentDate
        ? rows('SELECT a.*,c.client_name,u.full_name AS assigned_by_name FROM work_assignments a JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.assigned_by WHERE a.employee_id=? AND a.deleted_at IS NULL AND a.work_date=? ORDER BY a.id',[$user['id'],$assignmentDate])
        : [];
} else {
    $assignments=rows("SELECT a.*,c.client_name,u.full_name AS assigned_by_name FROM work_assignments a JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.assigned_by WHERE a.employee_id=? AND a.deleted_at IS NULL AND (a.work_date>=? OR a.status='pending' OR EXISTS (SELECT 1 FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id WHERE e.assignment_id=a.id AND s.work_date=?)) ORDER BY a.work_date,a.id",[$user['id'],$date,$date]);
}
$nextHoliday=one('SELECT * FROM holidays WHERE is_published=1 AND holiday_date>=? ORDER BY holiday_date LIMIT 1',[today()]);
$latestLeave=one('SELECT * FROM leave_requests WHERE employee_id=? ORDER BY applied_at DESC,id DESC LIMIT 1',[$user['id']]);
$todayAttendance=one('SELECT login_at,logout_at FROM employee_attendance_sessions WHERE employee_id=? AND DATE(login_at)=? ORDER BY login_at DESC LIMIT 1',[$user['id'],today()]);
$editEntry=null; if (!empty($_GET['edit_entry'])) { foreach ($entries as $entry) if ((int)$entry['id']===(int)$_GET['edit_entry'] && !$entry['assignment_id']) $editEntry=$entry; if (!$editEntry) fail(404,'Work entry not found.'); }
page_start(['daily'=>'Update daily work','history'=>'My submitted work','assigned'=>'My assigned work'][$employeeView]??$user['department_name'].' dashboard',$viewRoute); error_message($error);
?><?php if ($employeeView==='dashboard'): ?><p class="muted">Welcome, <?= h($user['full_name']) ?>. Update your assigned work and keep your manager informed.</p><div class="stats-grid"><a class="stat-card" href="payslips.php"><h2>My payslips</h2><strong><?= count_value('SELECT COUNT(*) FROM payslips WHERE employee_id=?',[$user['id']]) ?></strong><p>View and download your salary slips</p></a><a class="stat-card" href="employee-daily-work.php"><h2>Update daily work</h2><strong>+</strong><p>Enter your daily work and submit to manager</p></a><a class="stat-card" href="employee-assigned-work.php"><h2>Today's work</h2><strong><?= count($assignments) ?></strong><p>Assigned and pending tasks</p></a><a class="stat-card" href="employee-work-history.php"><h2>Work entries</h2><strong><?= count($entries) ?></strong><p><?= h($date) ?></p></a><a class="stat-card attendance-stat-card" href="employee-attendance.php"><h2>Attendance</h2><strong style="font-size:23px"><?= $todayAttendance?h(date('g:i A',strtotime($todayAttendance['login_at']))):'Not logged in' ?></strong><p><?= $todayAttendance?'Login time today · '.h(date('d M Y',strtotime($todayAttendance['login_at']))):'Use Login in the sidebar to record today’s time' ?></p><?php if ($todayAttendance && $todayAttendance['logout_at']): ?><p>Logout time · <?= h(date('g:i A',strtotime($todayAttendance['logout_at']))) ?></p><?php endif; ?><span class="attendance-card-link">View day-wise attendance →</span></a><a class="stat-card" href="my-leave-requests.php"><h2>Leave status</h2><strong style="font-size:23px"><?= h($latestLeave?ucfirst($latestLeave['status']):'No requests') ?></strong><p><?= h($latestLeave['from_date']??'Apply for leave') ?></p></a><a class="stat-card" href="admin-holidays.php"><h2>Next holiday</h2><strong style="font-size:23px"><?= h($nextHoliday?date('d M',strtotime($nextHoliday['holiday_date'])):'None scheduled') ?></strong><p><?= h($nextHoliday['holiday_name']??'View holiday calendar') ?></p></a></div>
<?php endif; if ($employeeView!=='dashboard' && $employeeView!=='assigned'): ?><form method="get" class="filters"><div class="field"><label for="date">Work date</label><input id="date" name="date" type="date" value="<?= h($date) ?>" required></div><button class="primary" type="submit">View work</button></form>
<?php endif; if ($employeeView==='assigned'): ?><?php if (!$assignmentDate): ?><section class="panel" id="assigned-work"><h2>Assigned work by date</h2><div class="table-scroll"><table><thead><tr><th>Assignment date</th><th>Tasks</th><th>Assigned work</th><th></th></tr></thead><tbody><?php if (!$assignmentDates) empty_row(4,'No assigned work yet.'); foreach ($assignmentDates as $day): ?><tr><td><?= h($day['work_date']) ?></td><td><?= h($day['task_count']) ?></td><td class="wrap"><?= h($day['task_titles']) ?></td><td><a class="button secondary" href="employee-assigned-work.php?assignment_date=<?= h($day['work_date']) ?>">View work</a></td></tr><?php endforeach; ?></tbody></table></div></section><?php else: ?><section class="panel" id="assigned-work"><div class="panel-heading"><h2>Assigned work · <?= h($assignmentDate) ?></h2><a class="button secondary" href="employee-assigned-work.php">All dates</a></div><?php if (!$assignments): ?><p class="muted">No assigned tasks for this date.</p><?php endif; foreach ($assignments as $taskIndex=>$assignment): $saved=$assignmentEntries[$assignment['id']]??[]; ?><article class="work-card"><div class="panel-heading"><h3>Task <?= $taskIndex+1 ?> · <?= h($assignment['title']) ?></h3><?= status_badge($saved['task_status']??$assignment['status']) ?></div><p class="muted"><?= h($assignment['client_name']) ?> · Assigned <?= h($assignment['work_date']) ?> by <?= h($assignment['assigned_by_name']) ?></p><p><?= h($assignment['description']) ?></p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_assignment"><input type="hidden" name="assignment_id" value="<?= $assignment['id'] ?>"><div class="fields"><div class="field"><label for="time-<?= $assignment['id'] ?>">Time spent (hours)</label><input id="time-<?= $assignment['id'] ?>" name="time_spent_hours" type="number" min="0" max="99999.99" step="0.01" value="<?= h($saved['time_spent_hours']??$assignment['time_spent_hours']??'0.00') ?>" required></div><div class="field full"><label>Status</label><div class="status-choice"><?php foreach (['pending','completed'] as $status): ?><label><input type="radio" name="task_status" value="<?= $status ?>" <?= ($saved['task_status']??$assignment['status'])===$status?'checked':'' ?> required><?= ucfirst($status) ?></label><?php endforeach; ?></div></div><div class="field full"><label for="remark-<?= $assignment['id'] ?>">Remark / issue details</label><textarea id="remark-<?= $assignment['id'] ?>" name="remark" maxlength="5000" placeholder="Describe any issue or progress update"><?= h($saved['remark']??$assignment['employee_remark']??'') ?></textarea></div></div><div class="form-actions" style="margin-top:20px"><button class="primary" type="submit">Save &amp; submit update</button></div></form></article><?php endforeach; ?></section><?php endif; ?>
<?php endif; if ($employeeView==='history'): ?><section class="panel" id="daily-sheet"><div class="panel-heading"><h2>Daily work sheet · <?= h($date) ?></h2><?= $sheet?status_badge($sheet['submission_status']):'' ?></div><?php if ($sheet && $sheet['manager_remark']): ?><p><strong>Manager feedback:</strong> <?= h($sheet['manager_remark']) ?> · <?= status_badge($sheet['review_status']) ?></p><?php endif; ?><div class="table-scroll"><table><thead><tr><th>S.no</th><th>Date</th><th>Client</th><th>Task</th><th>Status</th><th>Remark</th><th>Actions</th></tr></thead><tbody><?php if (!$entries) empty_row(7); foreach ($entries as $index=>$entry): ?><tr><td><?= $index+1 ?></td><td><?= h($date) ?></td><td><?= h($entry['client_name']) ?></td><td class="wrap"><?= h($entry['task_title']) ?></td><td><?= status_badge($entry['task_status']) ?></td><td class="wrap"><?= h($entry['remark']) ?></td><td><div class="actions"><?php detail_button('entry-'.$entry['id']); if (!$entry['assignment_id']): ?><a class="icon-button" href="employee-daily-work.php?date=<?= h($date) ?>&amp;edit_entry=<?= $entry['id'] ?>#add-work" title="Edit work" aria-label="Edit work"><?= icon('edit') ?></a><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php if ($entries): ?><form method="post" style="margin-top:20px"><?= csrf_field() ?><input type="hidden" name="action" value="submit_day"><button class="primary" type="submit">Submit day to manager</button></form><?php endif; ?></section>
<?php endif; if ($employeeView==='daily'): ?><section class="panel" id="add-work"><h2><?= $editEntry?'Edit work entry':'Update daily work' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_entry"><input type="hidden" name="entry_id" value="<?= h($editEntry['id']??'') ?>"><div class="fields"><div class="field"><label for="client_id">Client (optional)</label><select id="client_id" name="client_id"><option value="">No client / internal work</option><?= options(clients(),'client_name',$editEntry['client_id']??null) ?></select></div><div class="field"><label for="task_title">Task / page</label><input id="task_title" name="task_title" value="<?= h($editEntry['task_title']??'') ?>" maxlength="255" required></div><div class="field full"><label>Status</label><div class="status-choice"><?php foreach (['pending','completed'] as $status): ?><label><input type="radio" name="task_status" value="<?= $status ?>" <?= ($editEntry['task_status']??'pending')===$status?'checked':'' ?> required><?= ucfirst($status) ?></label><?php endforeach; ?></div></div><div class="field full"><label for="remark">Remark</label><textarea id="remark" name="remark" maxlength="5000"><?= h($editEntry['remark']??'') ?></textarea></div></div><?php render_metrics($workDepartment,$editEntry??[],'-new'); ?><div class="form-actions" style="margin-top:20px"><button class="primary" name="submit_mode" value="submitted">Save &amp; submit</button></div></form></section>
<?php endif; foreach ($entries as $entry) detail_dialog('entry-'.$entry['id'],'Work details',array_merge(['Date'=>$date,'Client'=>$entry['client_name']],work_details($entry,$workDepartment))); page_end(); ?>
