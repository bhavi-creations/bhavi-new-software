<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(['admin','manager']); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        $id=positive_id($_POST['id']??null);
        if (($_POST['action']??'')==='delete_leave') {
            if (!in_array($user['role'],['admin','manager'],true)) fail(403,'Only administrators and managers can delete leave requests.');
            db()->beginTransaction();
            if (!one('SELECT id FROM leave_requests WHERE id=? FOR UPDATE',[$id])) throw new InvalidArgumentException('Leave request no longer exists.');
            query('UPDATE notifications SET leave_request_id=NULL WHERE leave_request_id=?',[$id]);
            query('DELETE FROM leave_requests WHERE id=?',[$id]);
            db()->commit(); flash('Leave request deleted.'); redirect('manager-leave-requist.php');
        }
        $status=$_POST['decision']??''; $note=text_input('decision_note',3000,false);
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
if (!in_array($status,['','pending','approved','rejected','all'],true)) $status='';
$department=(int)($_GET['department']??0);
$base='SELECT l.*,u.full_name,d.name AS department_name,t.name AS leave_type,decider.full_name AS decided_by_name FROM leave_requests l JOIN users u ON u.id=l.employee_id JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id JOIN leave_types t ON t.id=l.leave_type_id LEFT JOIN users decider ON decider.id=l.decided_by WHERE l.status<>\'cancelled\'';
$params=[];
if ($department) { $base.=' AND ep.department_id=?'; $params[]=$department; }
$requests=rows($base.' ORDER BY (l.status=\'pending\') DESC,l.applied_at DESC',$params);
$counts=['pending'=>0,'approved'=>0,'rejected'=>0,'all'=>count($requests)];
foreach ($requests as $leave) if (isset($counts[$leave['status']])) $counts[$leave['status']]++;
$pendingList=array_values(array_filter($requests,static fn($l)=>$l['status']==='pending'));
$decidedList=array_values(array_filter($requests,static fn($l)=>$l['status']!=='pending'));
if (in_array($status,['approved','rejected'],true)) { $pendingList=[]; $decidedList=array_values(array_filter($decidedList,static fn($l)=>$l['status']===$status)); }
if ($status==='pending') $decidedList=[];
$upcoming=array_values(array_filter($requests,static fn($l)=>$l['status']==='approved' && $l['to_date']>=today()));
usort($upcoming,static fn($a,$b)=>$a['from_date']<=>$b['from_date']);
$onLeaveToday=count(array_filter($requests,static fn($l)=>$l['status']==='approved' && $l['from_date']<=today() && $l['to_date']>=today()));
$initials=static function(?string $name): string { $o=''; foreach (array_slice(preg_split('/\s+/',trim((string)$name))?:[],0,2) as $p) $o.=mb_strtoupper(mb_substr($p,0,1)); return $o?:'?'; };
$range=static function(string $from,string $to): string {
    $f=strtotime($from); $t=strtotime($to);
    if ($from===$to) return date('j M Y',$f);
    if (date('Y-m',$f)===date('Y-m',$t)) return date('j',$f).' – '.date('j M Y',$t);
    if (date('Y',$f)===date('Y',$t)) return date('j M',$f).' – '.date('j M Y',$t);
    return date('j M Y',$f).' – '.date('j M Y',$t);
};
$applied=static function(string $at): string { $d=date('Y-m-d',strtotime($at)); return 'Applied '.($d===today()?'today':($d===date('Y-m-d',strtotime('-1 day'))?'yesterday':date('d M',strtotime($at)))).', '.date('g:i A',strtotime($at)); };
$days=static fn($n)=>rtrim(rtrim(number_format((float)$n,2,'.',''),'0'),'.').' working day'.((float)$n==1.0?'':'s');
$tabLink=static fn(string $s)=>'manager-leave-requist.php?'.http_build_query(array_filter(['status'=>$s,'department'=>$department?:null]));
$firstName=static fn(string $n)=>explode(' ',trim($n))[0];
page_start('Leave requests','manager-leave-requist.php'); error_message($error);
?>
<div class="emp-page lv-page">
<header class="emp-header">
    <div><p class="emp-crumb">Dashboard / Leave requests</p><h2 class="emp-title">Leave requests</h2><p class="emp-sub">Review, approve or reject — your note goes straight to the employee.</p></div>
    <form method="get" class="lv-dept"><?php if ($status!==''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?><label for="department">Department</label><select id="department" name="department" onchange="this.form.submit()"><option value="">All departments</option><?= options(departments(),'name',$department) ?></select><noscript><button class="st-btn">Go</button></noscript></form>
</header>
<nav class="lv-tabs" aria-label="Leave status">
    <a class="<?= in_array($status,['','pending'],true)?'active':'' ?>" href="<?= h($tabLink('')) ?>">Pending <b><?= $counts['pending'] ?></b></a>
    <a class="<?= $status==='approved'?'active':'' ?>" href="<?= h($tabLink('approved')) ?>">Approved · <?= $counts['approved'] ?></a>
    <a class="<?= $status==='rejected'?'active':'' ?>" href="<?= h($tabLink('rejected')) ?>">Rejected · <?= $counts['rejected'] ?></a>
    <a class="<?= $status==='all'?'active':'' ?>" href="<?= h($tabLink('all')) ?>">All · <?= $counts['all'] ?></a>
</nav>
<div class="lv-layout">
<div class="lv-main">
    <?php if (!$pendingList && in_array($status,['','pending','all'],true)): ?><div class="lv-empty">🎉 No leave requests are waiting for you.</div><?php endif; ?>
    <?php foreach ($pendingList as $leave): ?>
    <article class="lv-card is-pending">
        <header class="lv-head">
            <span class="aw-avatar lv-avatar"><?= h($initials($leave['full_name'])) ?></span>
            <div class="lv-who"><strong><?= h($leave['full_name']) ?></strong><small><?= h($leave['department_name']) ?> · <?= h($applied($leave['applied_at'])) ?></small></div>
            <div class="lv-when"><strong><?= h($range($leave['from_date'],$leave['to_date'])) ?></strong><small><?= h($leave['leave_type']) ?> · <?= h($days($leave['working_days'])) ?></small></div>
            <span class="st-status st-status-pending">Pending</span>
            <details class="dept-menu"><summary aria-label="More actions"><?= icon('more') ?></summary><div class="dept-menu-list"><button type="button" class="lv-menu-btn" data-dialog="leave-<?= (int)$leave['id'] ?>"><?= icon('eye') ?>Details</button><?php delete_button((int)$leave['id'],'delete_leave','Permanently delete this leave request? It will also be removed from the employee history and leave calendar.'); ?></div></details>
        </header>
        <blockquote class="lv-reason">“<?= h($leave['reason']) ?>”</blockquote>
        <form method="post" class="lv-decide"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$leave['id'] ?>">
            <label for="note-<?= (int)$leave['id'] ?>">Note to employee (optional)</label>
            <div class="lv-decide-row"><input id="note-<?= (int)$leave['id'] ?>" name="decision_note" maxlength="3000" placeholder="e.g. Approved — please hand over pending work before you go"><button class="lv-approve" name="decision" value="approved">✓ Approve</button><button class="lv-reject" name="decision" value="rejected">Reject</button></div>
        </form>
    </article>
    <?php endforeach; ?>

    <?php if ($decidedList): ?><p class="lv-section"><?= $status==='approved'?'Approved':($status==='rejected'?'Rejected':'Already decided') ?></p><?php elseif (in_array($status,['approved','rejected'],true)): ?><div class="lv-empty">No <?= h($status) ?> leave requests.</div><?php endif; ?>
    <?php foreach ($decidedList as $leave): $hasNote=trim((string)$leave['decision_note'])!==''; ?>
    <article class="lv-card">
        <header class="lv-head">
            <span class="aw-avatar lv-avatar"><?= h($initials($leave['full_name'])) ?></span>
            <div class="lv-who"><strong><?= h($leave['full_name']) ?></strong><small><?= h($leave['department_name']) ?> · <?= h($leave['leave_type']) ?></small></div>
            <div class="lv-when"><strong><?= h($range($leave['from_date'],$leave['to_date'])) ?></strong><small><?= h($days($leave['working_days'])) ?></small></div>
            <span class="st-status <?= $leave['status']==='approved'?'st-status-done':'st-status-overdue' ?>"><?= h(ucfirst($leave['status'])) ?></span>
            <details class="dept-menu"><summary aria-label="More actions"><?= icon('more') ?></summary><div class="dept-menu-list"><button type="button" class="lv-menu-btn" data-dialog="leave-<?= (int)$leave['id'] ?>"><?= icon('eye') ?>Details</button><?php delete_button((int)$leave['id'],'delete_leave','Permanently delete this leave request? It will also be removed from the employee history and leave calendar.'); ?></div></details>
        </header>
        <blockquote class="lv-reason">“<?= h($leave['reason']) ?>”</blockquote>
        <details class="lv-note"<?= $hasNote?'':'' ?>>
            <?php if ($hasNote): ?>
                <summary class="lv-note-bubble"><span class="lv-note-by" title="<?= h($leave['decided_by_name'] ?? '') ?>"><?= h($initials($leave['decided_by_name'] ?? '')) ?></span><span class="lv-note-text"><?= h($leave['decision_note']) ?></span><span class="lv-note-edit">Edit note</span></summary>
            <?php else: ?>
                <summary class="lv-note-add">+ Add a note for <?= h($firstName($leave['full_name'])) ?></summary>
            <?php endif; ?>
            <form method="post" class="lv-note-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_note"><input type="hidden" name="id" value="<?= (int)$leave['id'] ?>">
                <label class="sr-only" for="followup-<?= (int)$leave['id'] ?>">Message to employee</label>
                <textarea id="followup-<?= (int)$leave['id'] ?>" name="decision_note" maxlength="3000" rows="2" required placeholder="Write a message for <?= h($firstName($leave['full_name'])) ?>…"><?= h($leave['decision_note']) ?></textarea>
                <button class="st-btn st-btn-dark">Save &amp; send note</button>
            </form>
        </details>
    </article>
    <?php endforeach; ?>
</div>
<aside class="lv-side">
    <section class="emp-idcard lv-upcoming">
        <h3>Upcoming approved leave</h3>
        <?php if (!$upcoming): ?><p class="lv-none">No approved leave coming up.</p><?php endif; ?>
        <?php foreach (array_slice($upcoming,0,6) as $leave): ?>
        <div class="lv-up-row"><span class="lv-date"><small><?= h(strtoupper(date('M',strtotime($leave['from_date'])))) ?></small><b><?= h(date('j',strtotime($leave['from_date']))) ?></b></span><div><strong><?= h($leave['full_name']) ?></strong><small><?= h($days($leave['working_days'])) ?> · <?= h($leave['department_name']) ?></small></div></div>
        <?php endforeach; ?>
    </section>
    <section class="emp-card lv-month">
        <h3>This month</h3>
        <div class="lv-month-grid"><div><strong><?= $counts['pending'] ?></strong><small>Waiting for you</small></div><div><strong><?= $onLeaveToday ?></strong><small>On leave today</small></div></div>
    </section>
</aside>
</div>
</div>
<?php foreach ($requests as $leave) detail_dialog('leave-'.$leave['id'],$leave['full_name'].' · Leave',['Employee'=>$leave['full_name'],'Department'=>$leave['department_name'],'Type'=>$leave['leave_type'],'From'=>$leave['from_date'],'To'=>$leave['to_date'],'Working days'=>$leave['working_days'],'Reason'=>$leave['reason'],'Status'=>$leave['status'],'Staff note'=>$leave['decision_note'],'Decision by'=>$leave['decided_by_name'],'Decided at'=>$leave['decided_at']]); page_end(); ?>
