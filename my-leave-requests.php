<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['employee']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
 check_csrf();
 try {
 $changed=query("UPDATE leave_requests SET status='cancelled' WHERE id=? AND employee_id=? AND status='pending'",[positive_id($_POST['id']??null),$user['id']])->rowCount();
 if (!$changed) throw new InvalidArgumentException('Only your pending requests can be cancelled.');
 flash('Leave request cancelled.');
 } catch (Throwable $e) { flash(mutation_error($e),'error'); }
 redirect('my-leave-requests.php');
}
$requests=rows('SELECT l.*,t.name AS leave_type,u.full_name AS decided_by_name FROM leave_requests l JOIN leave_types t ON t.id=l.leave_type_id LEFT JOIN users u ON u.id=l.decided_by WHERE l.employee_id=? ORDER BY l.applied_at DESC',[$user['id']]);
page_start('My leave requests','my-leave-requests.php');
?><section class="panel"><h2>My leave requests</h2><div class="table-scroll"><table><thead><tr><th>Dates</th><th>Type</th><th>Working days</th><th>Status</th><th>Manager message</th><th>Actions</th></tr></thead><tbody><?php if (!$requests) empty_row(6); foreach ($requests as $leave): ?><tr><td><?= h($leave['from_date']) ?> — <?= h($leave['to_date']) ?></td><td><?= h($leave['leave_type']) ?></td><td><?= h($leave['working_days']) ?></td><td><?= status_badge($leave['status']) ?></td><td class="wrap"><?= h($leave['decision_note']?:'No message yet') ?></td><td><div class="actions"><?php detail_button('leave-'.$leave['id']); if ($leave['status']==='pending'): ?><form method="post" class="inline-form" data-confirm="Cancel this leave request?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_leave"><input type="hidden" name="id" value="<?= $leave['id'] ?>"><button class="button secondary" type="submit">Cancel</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php foreach ($requests as $leave) detail_dialog('leave-'.$leave['id'],'Leave request',['Type'=>$leave['leave_type'],'From'=>$leave['from_date'],'To'=>$leave['to_date'],'Working days'=>$leave['working_days'],'Reason'=>$leave['reason'],'Status'=>$leave['status'],'Manager note'=>$leave['decision_note'],'Decision by'=>$leave['decided_by_name'],'Decision date'=>$leave['decided_at']]); page_end(); ?>
