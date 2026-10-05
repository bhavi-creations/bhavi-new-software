<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(['manager']); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        $id=positive_id($_POST['id']??null); $status=$_POST['decision']??''; $note=text_input('decision_note',3000,false);
        if (($_POST['action']??'')==='save_note') {
            if ($note==='') throw new InvalidArgumentException('Enter a message for the employee.');
            db()->beginTransaction();
            $leave=one("SELECT * FROM leave_requests WHERE id=? AND status IN ('approved','rejected') FOR UPDATE",[$id]);
            if (!$leave) throw new InvalidArgumentException('Approve or reject the request before adding a follow-up note.');
            query('UPDATE leave_requests SET decision_note=? WHERE id=?',[$note,$id]);
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,leave_request_id) VALUES (?,?,'leave_decision',?,?,?)",[$leave['employee_id'],$user['id'],'Message about your leave',$leave['from_date'].' to '.$leave['to_date'].' - '.$note,$id]);
            db()->commit(); flash('Note saved and sent to the employee.'); redirect('manager-leave-requist.php');
        }
        if (!in_array($status,['approved','rejected'],true)) throw new InvalidArgumentException('Choose Approve or Reject.');
        db()->beginTransaction();
        $leave=one("SELECT * FROM leave_requests WHERE id=? AND status='pending' FOR UPDATE",[$id]);
        if (!$leave) throw new InvalidArgumentException('This request has already been decided or cancelled.');
        query('UPDATE leave_requests SET status=?,decision_note=?,decided_by=?,decided_at=NOW() WHERE id=?',[$status,$note,$user['id'],$id]);
        query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,leave_request_id) VALUES (?,?,'leave_decision',?,?,?)",[$leave['employee_id'],$user['id'],'Leave '.$status,$leave['from_date'].' to '.$leave['to_date'].($note?' · '.$note:''),$id]);
        db()->commit(); flash('Leave request '.$status.'.'); redirect('manager-leave-requist.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$status=is_string($_GET['status']??null)?$_GET['status']:'';
$department=(int)($_GET['department']??0);
$sql='SELECT l.*,u.full_name,d.name AS department_name,t.name AS leave_type,decider.full_name AS decided_by_name FROM leave_requests l JOIN users u ON u.id=l.employee_id JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id JOIN leave_types t ON t.id=l.leave_type_id LEFT JOIN users decider ON decider.id=l.decided_by WHERE 1=1'; $params=[];
if ($status!=='') { $sql.=' AND l.status=?'; $params[]=$status; }
if ($department) { $sql.=' AND ep.department_id=?'; $params[]=$department; }
$requests=rows($sql.' ORDER BY (l.status=\'pending\') DESC,l.applied_at DESC',$params);
page_start('Leave requests','manager-leave-requist.php'); error_message($error);
?><form method="get" class="filters"><div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><?= options(array_map(static fn($s)=>['id'=>$s,'name'=>ucfirst($s)],['pending','approved','rejected','cancelled']),'name',$status) ?></select></div><div class="field"><label for="department">Department</label><select id="department" name="department"><option value="">All departments</option><?= options(departments(),'name',$department) ?></select></div><button class="primary" type="submit">Filter</button></form><section class="panel"><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Department</th><th>Dates / working days</th><th>Reason</th><th>Status</th><th>Manager message</th><th>Actions</th></tr></thead><tbody><?php if (!$requests) empty_row(7); foreach ($requests as $leave): ?><tr><td><?= h($leave['full_name']) ?></td><td><?= h($leave['department_name']) ?></td><td><?= h($leave['from_date']) ?><br><?= h($leave['to_date']) ?> · <?= h($leave['working_days']) ?> days</td><td class="wrap"><?= h($leave['reason']) ?></td><td><?= status_badge($leave['status']) ?></td><td class="wrap"><?= h($leave['decision_note']) ?></td><td><?php detail_button('leave-'.$leave['id']); if ($leave['status']==='pending'): ?><form method="post" class="field"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $leave['id'] ?>"><label for="note-<?= $leave['id'] ?>">Message to employee (optional)</label><input id="note-<?= $leave['id'] ?>" name="decision_note" maxlength="3000" placeholder="Optional note"><div class="form-actions"><button class="primary" name="decision" value="approved">Approve</button><button class="button danger" name="decision" value="rejected">Reject</button></div></form><?php elseif (in_array($leave['status'],['approved','rejected'],true)): ?><form method="post" class="field"><?= csrf_field() ?><input type="hidden" name="action" value="save_note"><input type="hidden" name="id" value="<?= $leave['id'] ?>"><label for="followup-<?= $leave['id'] ?>">Message to employee</label><textarea id="followup-<?= $leave['id'] ?>" name="decision_note" maxlength="3000" required><?= h($leave['decision_note']) ?></textarea><button class="button secondary">Save &amp; send note</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($requests as $leave) detail_dialog('leave-'.$leave['id'],$leave['full_name'].' · Leave',['Employee'=>$leave['full_name'],'Department'=>$leave['department_name'],'Type'=>$leave['leave_type'],'From'=>$leave['from_date'],'To'=>$leave['to_date'],'Working days'=>$leave['working_days'],'Reason'=>$leave['reason'],'Status'=>$leave['status'],'Manager note'=>$leave['decision_note'],'Decision by'=>$leave['decided_by_name'],'Decided at'=>$leave['decided_at']]); page_end(); ?>
