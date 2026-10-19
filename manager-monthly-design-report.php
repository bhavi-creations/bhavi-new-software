<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/monthly-design-report.php';
require_roles(['admin','manager']);
try { $report=monthly_design_report(); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
if (($_GET['format']??'')==='xlsx') {
    require_once __DIR__.'/includes/excel-export.php';
    $records=(static function () use ($report): Generator {
        foreach ($report['people'] as $person) yield ['Monthly total',$report['month'],$person['full_name'],'',$person['poster'],$person['video_editing'],$person['carousel'],'','','','Completed','',''];
        foreach ($report['people'] as $person) foreach ($person['entries'] as $entry) yield ['Work detail',$report['month'],$person['full_name'],$entry['work_date'],$entry['quantities']['poster'],$entry['quantities']['video_editing'],$entry['quantities']['carousel'],design_quantity_label($entry['quantities']),$entry['task_title'],$entry['client_name'],$entry['task_status'],$entry['time_spent_minutes']===null?'':format_work_duration($entry['time_spent_minutes']),$entry['remark']];
    })();
    download_excel(['Row type','Month','Employee','Work date','Posters','Reels / Videos','Carousels','Task type','Title','Client','Status','Time spent','Remark'],$records,'bhavi-design-monthly-'.$report['month']);
    exit;
}
page_start('Monthly design report','manager-monthly-design-report.php');
?>
<p class="muted">Monthly work for Graphic Designer, Video Editing and Design &amp; video employees. Totals count completed work; the date-wise details also show pending work.</p>
<section class="panel"><form method="get" class="filters">
    <div class="field"><label for="month">Month</label><input type="month" id="month" name="month" value="<?= h($report['month']) ?>" required></div>
    <div class="field"><label for="department">Department</label><select id="department" name="department" data-filter-department><option value="">All design departments</option><?= options($report['departments'],'name',$report['department']?:null) ?></select></div>
    <div class="field"><label for="employee">Employee</label><select id="employee" name="employee"><option value="">All employees</option><?= options($report['employees'],'full_name',$report['employee']?:null) ?></select></div>
    <button class="primary" type="submit">View month</button><button class="button secondary" type="submit" name="format" value="xlsx"><?= icon('download') ?> Download Excel</button>
</form></section>
<section class="panel"><h2><?= h($report['first']->format('F Y')) ?> · Completed work</h2><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Posters</th><th>Reels / Videos</th><th>Carousels</th><th>Total</th><th></th></tr></thead><tbody>
<?php if (!$report['people']) empty_row(6,'No design employees found.'); foreach ($report['people'] as $person): ?>
<tr data-monthly-employee="<?= (int)$person['id'] ?>"><td><strong><?= h($person['full_name']) ?></strong><br><small class="muted"><?= h($person['department_name']) ?></small></td><td><?= $person['poster'] ?></td><td><?= $person['video_editing'] ?></td><td><?= $person['carousel'] ?></td><td><strong><?= $person['poster']+$person['video_editing']+$person['carousel'] ?></strong></td><td><a class="button secondary" href="#employee-<?= (int)$person['id'] ?>-work">Date-wise work</a></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php foreach ($report['people'] as $person): ?>
<section class="panel monthly-design-details" id="employee-<?= (int)$person['id'] ?>-work"><h2><?= h($person['full_name']) ?> · Date-wise work</h2>
<p class="muted"><?= h($report['first']->format('F Y')) ?> · <?= $person['pending'] ?> pending task(s)<?php if ($person['unclassified']): ?> · <?= $person['unclassified'] ?> completed task(s) without a saved type or quantity<?php endif; ?></p>
<div class="table-scroll"><table><thead><tr><th>Date</th><th>Task type / quantity</th><th>Title</th><th>Client</th><th>Status</th><th>Time spent</th><th>Remark</th><th></th></tr></thead><tbody>
<?php if (!$person['entries']) empty_row(8,'No submitted work for this month.'); foreach ($person['entries'] as $entry): ?>
<tr><td><?= h(date('d-m-Y',strtotime($entry['work_date']))) ?></td><td class="wrap"><?= h(design_quantity_label($entry['quantities'])) ?></td><td class="wrap"><?= h($entry['task_title']?:'Work entry') ?></td><td><?= h($entry['client_name']?:'Internal work') ?></td><td><?= status_badge($entry['task_status']) ?></td><td><?= h($entry['time_spent_minutes']===null?'Not entered':format_work_duration($entry['time_spent_minutes'])) ?></td><td class="wrap work-report-text"><?= h($entry['remark']) ?></td><td><a class="button secondary" href="manager-work-details.php?id=<?= (int)$entry['submission_id'] ?>">View day</a></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php endforeach; page_end(); ?>
