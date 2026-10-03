<?php
require_once __DIR__ . '/layout.php';
$user=require_roles(); $client=(int)($_GET['client']??0);
$sql='SELECT a.*,c.client_name,u.full_name AS employee_name FROM work_assignments a JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.employee_id WHERE a.deleted_at IS NULL'; $params=[];
if (!is_staff()) { $sql.=' AND a.employee_id=?'; $params[]=$user['id']; }
if ($client) { $sql.=' AND a.client_id=?'; $params[]=$client; }
$assignments=rows($sql.' ORDER BY a.work_date DESC',$params);
$sql='SELECT r.*,c.client_name FROM client_requirements r JOIN clients c ON c.id=r.client_id WHERE c.deleted_at IS NULL'; $params=[];
if (!is_staff()) { $sql.=' AND EXISTS (SELECT 1 FROM requirement_assignments ra WHERE ra.requirement_id=r.id AND ra.employee_id=?)'; $params[]=$user['id']; }
if ($client) { $sql.=' AND r.client_id=?'; $params[]=$client; }
$requirements=rows($sql.' ORDER BY r.due_date DESC,r.id DESC',$params);
page_start('Client requirements','client-reuirement.php');
?><form method="get" class="filters"><div class="field"><label for="client">Client</label><select id="client" name="client"><option value="">All clients</option><?= options(clients(),'client_name',$client) ?></select></div><button class="primary" type="submit">Filter</button></form>
<?php if (!$assignments && !$requirements): ?><section class="panel"><p class="muted">Your assigned client briefs will appear here.</p></section><?php endif; foreach ($assignments as $assignment): ?><article class="panel"><div class="panel-heading"><h2><?= h($assignment['title']) ?></h2><?= status_badge($assignment['status']) ?></div><p class="muted"><?= h($assignment['client_name']) ?> · <?= h($assignment['employee_name']) ?> · <?= h($assignment['work_date']) ?></p><p style="white-space:pre-wrap"><?= h($assignment['description']) ?></p></article><?php endforeach; foreach ($requirements as $requirement): ?><article class="panel"><div class="panel-heading"><h2><?= h($requirement['title']) ?></h2><?= status_badge($requirement['status']) ?></div><p class="muted"><?= h($requirement['client_name']) ?> · Due <?= h($requirement['due_date']) ?></p><p style="white-space:pre-wrap"><?= h($requirement['brief']) ?></p><?php if ($requirement['approved_text']): ?><p style="white-space:pre-wrap"><?= h($requirement['approved_text']) ?></p><?php endif; ?></article><?php endforeach; page_end(); ?>
