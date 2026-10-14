<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['admin','manager']);
$clientId=(int)($_GET['client_id']??$_POST['client_id']??0);
$client=$clientId?one('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL',[$clientId]):null;
if ($clientId && !$client) fail(404,'Client not found.');
$date=is_string($_GET['date']??null)?$_GET['date']:today();
try { $date=date_input('date',['date'=>$date]); } catch (InvalidArgumentException $e) { $date=today(); }
$editId=(int)($_GET['edit']??0);
$record=$editId?one('SELECT * FROM client_work_reports WHERE id=? AND client_id=?',[$editId,$clientId]):null;
if ($editId && !$record) fail(404,'Report not found.');
if ($record) $date=$record['report_date'];
$labels=['received_at'=>'Content received / collected on','started_at'=>'Work started on','sent_at'=>'Sent to client on WhatsApp','approved_at'=>'Client approved on','uploaded_at'=>'Uploaded to social media on'];
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (!$client) throw new InvalidArgumentException('Select a client.');
        $date=date_input('report_date');
        $type=$_POST['work_type']??'other';
        if (!in_array($type,['reel','poster','carousel','other'],true)) throw new InvalidArgumentException('Choose a valid work type.');
        $source=$_POST['content_source']??'';
        if (!in_array($source,['','client','own'],true)) throw new InvalidArgumentException('Choose a valid content source.');
        $title=text_input('title',255,false); $platform=text_input('platform',255,false); $remarks=text_input('remarks',10000,false);
        $times=[];
        foreach ($labels as $key=>$label) {
            $value=$_POST[$key]??'';
            if ($value==='') { $times[$key]=null; continue; }
            $parsed=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value):false;
            if (!$parsed || $parsed->format('Y-m-d\TH:i')!==$value) throw new InvalidArgumentException('Enter a valid date and time for '.$label.'.');
            $times[$key]=$parsed->format('Y-m-d H:i:s');
        }
        $data=[$date,$type,$title,$source?:null,...array_values($times),$platform,$remarks];
        db()->beginTransaction();
        if (!one('SELECT id FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$clientId])) throw new InvalidArgumentException('Client is no longer available.');
        if ($record) {
            query('UPDATE client_work_reports SET report_date=?,work_type=?,title=?,content_source=?,received_at=?,started_at=?,sent_at=?,approved_at=?,uploaded_at=?,platform=?,remarks=? WHERE id=? AND client_id=?',[...$data,$editId,$clientId]);
        } else {
            query('INSERT INTO client_work_reports (report_date,work_type,title,content_source,received_at,started_at,sent_at,approved_at,uploaded_at,platform,remarks,client_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$data,$clientId,$user['id']]);
        }
        db()->commit(); flash('Client work report saved.');
        redirect('admin-client-work-report.php?client_id='.$clientId.'&date='.$date.'#day-reports');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
$month=(new DateTimeImmutable($date))->modify('first day of this month');
$next=$month->modify('+1 month');
$reports=$client?rows('SELECT * FROM client_work_reports WHERE client_id=? AND report_date>=? AND report_date<? ORDER BY report_date,id',[$clientId,$month->format('Y-m-d'),$next->format('Y-m-d')]):[];
$byDate=[]; $completed=[];
foreach ($reports as $report) { $byDate[$report['report_date']][]=$report; if ($report['uploaded_at']) $completed[$report['work_type']]=($completed[$report['work_type']]??0)+1; }
$value=static fn($key,$fallback='')=>$_POST[$key]??$record[$key]??$fallback;
page_start('Client work report','admin-client-work-report.php'); error_message($error);
?>
<section class="panel"><form method="get" class="fields"><div class="field"><label>Client<select name="client_id" required><option value="">Select client</option><?= options(rows('SELECT id,client_name FROM clients WHERE deleted_at IS NULL ORDER BY client_name'),'client_name',$clientId) ?></select></label></div><div class="field"><label>Select date<input name="date" type="date" value="<?= h($date) ?>" required></label></div><div class="field"><button class="primary">View calendar</button></div></form></section>
<?php if ($client): ?>
<section class="panel"><h2><?= h($client['client_name']) ?> — <?= h($month->format('F Y')) ?></h2><p class="help">Monthly completion counts work scheduled in this month with an upload date entered. Each report is one item.</p><div class="table-scroll"><table><thead><tr><th>Work</th><th>Monthly target</th><th>Uploaded</th><th>Remaining</th></tr></thead><tbody><?php foreach (['reel'=>'monthly_reels','poster'=>'monthly_posters','carousel'=>'monthly_carousels'] as $type=>$key): $done=$completed[$type]??0; ?><tr><td><?= h(ucfirst($type)) ?></td><td><?= (int)$client[$key] ?></td><td><?= $done ?></td><td><?= max(0,(int)$client[$key]-$done) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel"><div class="toolbar"><a class="button secondary" href="?client_id=<?= $clientId ?>&date=<?= $month->modify('-1 month')->format('Y-m-d') ?>">Previous month</a><strong><?= h($month->format('F Y')) ?></strong><a class="button secondary" href="?client_id=<?= $clientId ?>&date=<?= $next->format('Y-m-d') ?>">Next month</a></div><div class="table-scroll"><table class="client-report-calendar"><thead><tr><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><th><?= $day ?></th><?php endforeach; ?></tr></thead><tbody><tr><?php
$offset=(int)$month->format('N')-1; $days=(int)$month->format('t');
for ($cell=0;$cell<(int)(ceil(($offset+$days)/7)*7);$cell++):
    if ($cell && $cell%7===0) echo '</tr><tr>';
    $number=$cell-$offset+1;
    if ($number<1 || $number>$days) { echo '<td></td>'; continue; }
    $dayDate=$month->format('Y-m-').sprintf('%02d',$number);
?><td <?= $dayDate===$date?'class="selected-report-day"':'' ?>><a href="?client_id=<?= $clientId ?>&date=<?= $dayDate ?>#report-form"><?= $number ?></a><?php if (!empty($byDate[$dayDate])): ?><div><button class="icon-button" type="button" data-dialog="calendar-day-<?= $dayDate ?>" title="View <?= count($byDate[$dayDate]) ?> reports" aria-label="View reports for <?= $dayDate ?>"><?= icon('eye') ?></button><small><?= count($byDate[$dayDate]) ?></small></div><?php endif; ?></td><?php endfor; ?></tr></tbody></table></div></section>
<section class="panel" id="report-form"><h2><?= $record?'Edit work report':'Add work report' ?></h2><p class="help">All work details are optional. Save now and edit as work progresses.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="client_id" value="<?= $clientId ?>"><div class="fields"><div class="field"><label>Report date<input type="date" name="report_date" value="<?= h($value('report_date',$date)) ?>" required></label></div><div class="field"><label>Work type<select name="work_type"><?= options([['id'=>'other','name'=>'Other / not specified'],['id'=>'reel','name'=>'Reel'],['id'=>'poster','name'=>'Poster'],['id'=>'carousel','name'=>'Carousel']],'name',$value('work_type','other')) ?></select></label></div><div class="field"><label>Work title<input name="title" maxlength="255" value="<?= h($value('title')) ?>"></label></div><div class="field"><label>Content source</label><div class="benefit-options"><?php foreach ([''=>'Not specified','client'=>'Client sent footage','own'=>'Own content'] as $key=>$label): ?><label><input type="radio" name="content_source" value="<?= $key ?>" <?= $value('content_source')===$key?'checked':'' ?>><?= $label ?></label><?php endforeach; ?></div></div>
<?php foreach ($labels as $key=>$label): $raw=$value($key); ?><div class="field"><label><?= h($label) ?><input type="datetime-local" name="<?= $key ?>" value="<?= h($raw?str_replace(' ','T',substr($raw,0,16)):'') ?>"></label></div><?php endforeach; ?>
<div class="field"><label>Social media platform / uploaded by<input name="platform" maxlength="255" value="<?= h($value('platform')) ?>"></label></div><div class="field full"><label>Remarks / requested changes<textarea name="remarks" maxlength="10000" rows="4"><?= h($value('remarks')) ?></textarea></label></div></div><button class="primary">Save report</button><?php if ($record): ?> <a class="button secondary" href="?client_id=<?= $clientId ?>&date=<?= h($date) ?>">Cancel edit</a><?php endif; ?></form></section>
<?php require_once __DIR__.'/includes/client-report-table.php'; client_report_table($client,$month,$byDate,true); ?>
<?php foreach ($byDate as $savedDate=>$dayItems): ?>
<dialog id="calendar-day-<?= h($savedDate) ?>" class="detail-dialog" aria-labelledby="calendar-title-<?= h($savedDate) ?>"><div class="dialog-heading"><h2 id="calendar-title-<?= h($savedDate) ?>"><?= h((new DateTimeImmutable($savedDate))->format('d-m-Y, l')) ?></h2><button type="button" data-close-dialog aria-label="Close details">&times;</button></div>
<?php foreach ($dayItems as $item): ?><section class="calendar-work-detail"><h3><?= h($item['title']?:'Untitled work') ?></h3><dl class="details-list"><dt>Work type</dt><dd><?= h(ucfirst($item['work_type'])) ?></dd><dt>Content source</dt><dd><?= $item['content_source']==='client'?'Client sent footage':($item['content_source']==='own'?'Own content':'Not specified') ?></dd><?php foreach ($labels as $key=>$label): ?><dt><?= h($label) ?></dt><dd><?= $item[$key]?h((new DateTimeImmutable($item[$key]))->format('d M Y, h:i A')):'Not updated' ?></dd><?php endforeach; ?><dt>Social media / uploaded by</dt><dd><?= h($item['platform']?:'Not updated') ?></dd><dt>Remarks / changes</dt><dd><?= h($item['remarks']?:'No remarks') ?></dd></dl><p><a class="button secondary" href="?client_id=<?= $clientId ?>&date=<?= h($savedDate) ?>&edit=<?= (int)$item['id'] ?>#report-form">Edit this work</a></p></section><?php endforeach; ?></dialog>
<?php endforeach; endif; page_end(); ?>
