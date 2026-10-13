<?php
require_once __DIR__.'/includes/client-portal.php';
$client=own_client(); $month=client_month(); $reports=client_month_reports((int)$client['id'],$month); $byDate=[];
foreach ($reports as $report) $byDate[$report['report_date']][]=$report;
page_start('Work reports','client-reports.php');
?><div class="cp-section-heading"><div><h2><?= h($month->format('F Y')) ?></h2><p class="help">Monthly targets and work updates from your team.</p></div><form method="get" class="cp-month-picker"><label for="report-month">Choose month</label><input id="report-month" name="month" type="month" value="<?= h($month->format('Y-m')) ?>" required><button class="primary">View</button></form></div>
<?php client_progress($client,$reports); ?>
<p class="help">Completed counts items with an upload date. Each report is one item, grouped by its report date.</p>
<section class="panel cp-calendar-panel"><div class="cp-section-heading"><a class="button secondary" href="?month=<?= $month->modify('-1 month')->format('Y-m') ?>" aria-label="Previous month">&larr; Previous</a><h2><?= h($month->format('F Y')) ?></h2><a class="button secondary" href="?month=<?= $month->modify('+1 month')->format('Y-m') ?>" aria-label="Next month">Next &rarr;</a></div><p class="help">Hover or focus an eye icon to preview updates. Click or tap for full details.</p><div class="cp-calendar" aria-label="Work report calendar"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><div class="cp-weekday"><?= $day ?></div><?php endforeach;
$offset=(int)$month->format('N')-1; $days=(int)$month->format('t');
for ($cell=0;$cell<(int)(ceil(($offset+$days)/7)*7);$cell++): $number=$cell-$offset+1;
if ($number<1 || $number>$days): ?><div class="cp-day cp-day-empty" aria-hidden="true"></div><?php continue; endif;
$date=$month->format('Y-m-').sprintf('%02d',$number); $items=$byDate[$date]??[];
?><div class="cp-day <?= $items?'cp-day-updated':'' ?> <?= $date===today()?'cp-day-today':'' ?>"><time datetime="<?= $date ?>"><?= $number ?></time><?php if ($items): ?><button type="button" class="cp-eye" data-dialog="day-<?= $date ?>" data-report-preview aria-label="View <?= count($items) ?> work updates for <?= $date ?>"><?= icon('eye') ?><span><?= count($items) ?></span></button><?php endif; ?></div><?php endfor; ?></div>
<?php if (!$reports): ?><div class="cp-empty"><h3>No work updates this month</h3><p>Saved updates will appear on the calendar when your team adds them.</p></div><?php endif; ?></section>
<?php foreach ($byDate as $date=>$items): ?><dialog class="detail-dialog cp-report-dialog" id="day-<?= $date ?>" aria-labelledby="heading-<?= $date ?>"><div class="dialog-heading"><h2 id="heading-<?= $date ?>"><?= h((new DateTimeImmutable($date))->format('d M Y, l')) ?></h2><button type="button" data-close-dialog aria-label="Close details">&times;</button></div><div class="cp-report-content"><?php foreach ($items as $report): ?><article class="cp-work-item"><h3><?= h($report['title']?:'Untitled work') ?></h3><span class="badge"><?= $report['uploaded_at']?'Uploaded':($report['approved_at']?'Approved':($report['sent_at']?'Sent to client':($report['started_at']?'In progress':'Pending'))) ?></span><dl class="details-list"><?php foreach (client_report_fields($report) as $label=>$value): ?><dt><?= h($label) ?></dt><dd><?= h($value) ?></dd><?php endforeach; ?></dl></article><?php endforeach; ?></div></dialog><?php endforeach; ?>
<script src="assets/js/client-reports.js?v=<?= filemtime(__DIR__.'/assets/js/client-reports.js') ?>" defer></script>
<?php page_end(); ?>
