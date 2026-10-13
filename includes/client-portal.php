<?php
require_once __DIR__.'/layout.php';
require_once __DIR__.'/finance.php';
function own_client(): array {
    $user=require_roles(['client']);
    if ($_SERVER['REQUEST_METHOD']!=='GET' && $_SERVER['REQUEST_METHOD']!=='HEAD') fail(405,'This page is read-only.');
    $client=one('SELECT * FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1',[$user['id']]);
    if (!$client) fail(403,'Your client account is unavailable.');
    return $client;
}
function client_month(): DateTimeImmutable {
    $value=$_GET['month']??date('Y-m');
    $month=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m',$value):false;
    if (!$month || $month->format('Y-m')!==$value) fail(400,'Choose a valid month.');
    return $month;
}
function client_month_reports(int $id, DateTimeImmutable $month): array {
    return rows('SELECT * FROM client_work_reports WHERE client_id=? AND report_date>=? AND report_date<? ORDER BY report_date,id',[$id,$month->format('Y-m-d'),$month->modify('+1 month')->format('Y-m-d')]);
}
function client_progress(array $client,array $reports): void {
    $done=[]; foreach ($reports as $report) if ($report['uploaded_at']) $done[$report['work_type']]=($done[$report['work_type']]??0)+1;
    ?><div class="cp-progress-grid"><?php foreach (['reel'=>['monthly_reels','Reels'],'poster'=>['monthly_posters','Posters'],'carousel'=>['monthly_carousels','Carousels']] as $type=>$spec): $target=(int)$client[$spec[0]]; $count=$done[$type]??0; ?><article class="panel cp-progress"><span class="cp-eyebrow"><?= $spec[1] ?></span><p class="cp-count"><?= $count ?><small> / <?= $target ?></small></p><progress value="<?= min($count,max(1,$target)) ?>" max="<?= max(1,$target) ?>" aria-label="<?= $spec[1] ?> completed"></progress><div class="cp-progress-caption"><span><?= $count ?> completed</span><strong><?= max(0,$target-$count) ?> remaining</strong></div></article><?php endforeach; ?></div><?php
}
function client_payment_summary(array $client): void {
    ?><div class="cp-progress-grid" aria-label="Client payments"><?php foreach (['payment_total'=>'Total agreed amount','paid_amount'=>'Amount paid','remaining_amount'=>'Balance remaining'] as $key=>$label): ?><article class="panel cp-payment <?= $key==='remaining_amount'?'cp-balance':'' ?>"><span class="cp-eyebrow"><?= $label ?></span><strong><?= h(money_label($client[$key])) ?></strong></article><?php endforeach; ?></div><?php
}
function client_report_fields(array $report): array {
    $fields=['Work'=>$report['title']?:'Untitled work','Type'=>ucfirst($report['work_type']),'Content source'=>$report['content_source']==='client'?'Client sent footage':($report['content_source']==='own'?'Own content':'Not updated')];
    foreach (['received_at'=>'Content received / collected','started_at'=>'Work started','sent_at'=>'Sent on WhatsApp','approved_at'=>'Client approval','uploaded_at'=>'Social media upload'] as $key=>$label) $fields[$label]=$report[$key]?(new DateTimeImmutable($report[$key]))->format('d M Y, h:i A'):'Not updated';
    $fields['Social media / uploaded by']=$report['platform']?:'Not updated'; $fields['Remarks / changes']=$report['remarks']?:'No remarks';
    return $fields;
}
