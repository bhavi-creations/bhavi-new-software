<?php
require_once __DIR__ . '/includes/layout.php'; require_once __DIR__ . '/includes/work.php';
$user=require_roles(['admin','manager']); $id=(int)($_GET['id']??0); $error=null;
$sheet=one('SELECT s.*,u.full_name,d.code AS department_code,d.name AS department_name FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id WHERE s.id=?',[$id]);
if (!$sheet) fail(404,'Work report not found.');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        db()->beginTransaction();
        $lockedSheet=one('SELECT id,employee_id,work_date FROM daily_work_submissions WHERE id=? FOR UPDATE',[$id]);
        if (!$lockedSheet) throw new InvalidArgumentException('Work report no longer exists.');
        if (($_POST['action']??'')==='review_sheet') {
            $review=$_POST['review_status']??'';
            if (!in_array($review,['pending','reviewed','changes_requested'],true)) throw new InvalidArgumentException('Choose a valid review status.');
            $remark=text_input('manager_remark',5000,false);
            query('UPDATE daily_work_submissions SET review_status=?,manager_remark=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$review,$remark,$user['id'],$lockedSheet['id']]);
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message,submission_id) VALUES (?,?,'general',?,?,?)",[$lockedSheet['employee_id'],$user['id'],'Work report '.str_replace('_',' ',$review),$lockedSheet['work_date'].' · '.$remark,$lockedSheet['id']]);
        } elseif (($_POST['action']??'')==='edit_entry') {
            $entryId=positive_id($_POST['entry_id']??null);
            $entry=one('SELECT * FROM daily_work_entries WHERE id=? AND submission_id=? FOR UPDATE',[$entryId,$lockedSheet['id']]);
            if (!$entry) fail(404,'Work entry not found in this report.');
            $status=$_POST['task_status']??'';
            if (!in_array($status,['pending','completed'],true)) throw new InvalidArgumentException('Choose Pending or Completed.');
            $common=['task_title'=>text_input('task_title',255),'task_status'=>$status,'remark'=>text_input('remark',5000,false)];
            save_work_entry($sheet,$sheet['department_code'],$common,work_metrics($sheet['department_code']),$entryId);
            if ($entry['assignment_id']) sync_assignment((int)$entry['assignment_id']);
        } else throw new InvalidArgumentException('Invalid action.');
        db()->commit(); flash('Work report updated.'); redirect('manager-review-work.php?id='.$id);
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$entries=rows('SELECT e.*,c.client_name FROM daily_work_entries e LEFT JOIN clients c ON c.id=e.client_id WHERE e.submission_id=? ORDER BY e.row_order',[$id]);
page_start('Review daily work','manager-dailywork.php'); error_message($error);
?><p><?= h($sheet['full_name']) ?> · <?= h($sheet['department_name']) ?> · <?= h($sheet['work_date']) ?></p><a class="button secondary" href="manager-dailywork.php?from_date=<?= h($sheet['work_date']) ?>&amp;to_date=<?= h($sheet['work_date']) ?>">Back to reports</a><section class="panel" style="margin-top:22px"><h2>Review and feedback</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="review_sheet"><div class="fields"><div class="field full"><label for="review_status">Review status</label><select id="review_status" name="review_status"><?= options([['id'=>'pending','name'=>'Pending'],['id'=>'reviewed','name'=>'Reviewed'],['id'=>'changes_requested','name'=>'Changes requested']],'name',$sheet['review_status']) ?></select></div><div class="field full"><label for="manager_remark">Manager remark</label><textarea id="manager_remark" name="manager_remark" maxlength="5000"><?= h($sheet['manager_remark']) ?></textarea></div></div><button class="primary" type="submit">Save review</button></form></section>
<?php foreach ($entries as $entry): ?><section class="panel"><h2><?= h($entry['client_name']) ?> · Work <?= $entry['row_order'] ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit_entry"><input type="hidden" name="entry_id" value="<?= $entry['id'] ?>"><div class="fields"><div class="field"><label for="task-<?= $entry['id'] ?>">Task / page</label><input id="task-<?= $entry['id'] ?>" name="task_title" value="<?= h($entry['task_title']) ?>" maxlength="255" required></div><div class="field"><label for="status-<?= $entry['id'] ?>">Status</label><select id="status-<?= $entry['id'] ?>" name="task_status"><?= options([['id'=>'pending','name'=>'Pending'],['id'=>'completed','name'=>'Completed']],'name',$entry['task_status']) ?></select></div><div class="field full"><label for="remark-<?= $entry['id'] ?>">Remark</label><textarea id="remark-<?= $entry['id'] ?>" name="remark" maxlength="5000"><?= h($entry['remark']) ?></textarea></div></div><?php render_metrics($sheet['department_code'],$entry,'-review-'.$entry['id']); ?><button class="primary" type="submit" style="margin-top:20px">Save entry changes</button></form></section><?php endforeach; page_end(); ?>
