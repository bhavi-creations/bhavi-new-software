<?php
require_once __DIR__.'/client-portal.php';

function client_report_table(array $client, DateTimeImmutable $month, array $byDate, bool $management=false): void {
    $scope=$management?'&amp;client_id='.(int)$client['id']:'';
    ?>
    <section class="panel" id="day-reports"><h2>Reports — <?= h($month->format('F Y')) ?></h2>
    <form method="get" action="download-client-report.php" class="fields">
        <input type="hidden" name="period" value="custom">
        <?php if ($management): ?><input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>"><?php endif; ?>
        <div class="field"><label>From date<input type="date" name="from" value="<?= $month->format('Y-m-d') ?>" required></label></div>
        <div class="field"><label>To date<input type="date" name="to" value="<?= $month->modify('last day of this month')->format('Y-m-d') ?>" required></label></div>
        <div class="field"><button class="primary"><?= icon('download') ?> Download date range</button></div>
    </form>
    <p class="help">Choose any past or current dates, including different months or years. Both dates are included.</p>
    <p><a class="button secondary" href="download-client-report.php?period=month&amp;date=<?= $month->modify('-1 month')->format('Y-m-d') ?><?= $scope ?>"><?= icon('download') ?> Download previous month (<?= h($month->modify('-1 month')->format('M Y')) ?>)</a></p>
    <div class="table-scroll"><table><thead><tr><th>Date</th><th>Work</th><th>Type</th><th>Status</th><th>Details</th><th>Download</th><?php if ($management): ?><th>Edit</th><?php endif; ?></tr></thead><tbody>
    <?php for ($day=$month;$day<$month->modify('+1 month');$day=$day->modify('+1 day')): $date=$day->format('Y-m-d'); $items=$byDate[$date]??[]; ?>
        <?php if (!$items): ?><tr id="reports-<?= $date ?>"><td><?= $day->format('d-m-Y') ?></td><td>No report added for this date.</td><td>—</td><td>No report added</td><td>—</td><td><a class="icon-button" href="download-client-report.php?period=day&amp;date=<?= $date ?><?= $scope ?>" title="Download day" aria-label="Download <?= $date ?>"><?= icon('download') ?></a></td><?php if ($management): ?><td>—</td><?php endif; ?></tr><?php endif; ?>
        <?php foreach ($items as $index=>$report): ?><tr<?= $index===0?' id="reports-'.$date.'"':'' ?>><td><?= $day->format('d-m-Y') ?></td><td><?= h($report['title']?:'Untitled work') ?></td><td><?= h(ucfirst($report['work_type'])) ?></td><td><?= $report['uploaded_at']?'Uploaded':($report['approved_at']?'Approved':($report['sent_at']?'Sent to client':($report['started_at']?'In progress':'Pending'))) ?></td><td><?php detail_button('table-report-'.$report['id']); ?></td><td><a class="icon-button" href="download-client-report.php?id=<?= (int)$report['id'] ?><?= $scope ?>" title="Download Excel" aria-label="Download Excel for <?= h($report['title']?:'Untitled work') ?>"><?= icon('download') ?></a></td><?php if ($management): ?><td><a class="icon-button" href="?client_id=<?= (int)$client['id'] ?>&amp;date=<?= $date ?>&amp;edit=<?= (int)$report['id'] ?>#report-form" title="Edit report" aria-label="Edit report"><?= icon('edit') ?></a></td><?php endif; ?></tr><?php endforeach; ?>
    <?php endfor; ?></tbody></table></div>
    <?php foreach ($byDate as $date=>$items) foreach ($items as $report) detail_dialog('table-report-'.$report['id'],'Work report details',['Report date'=>$date]+client_report_fields($report)); ?>
    </section>
    <?php
}
