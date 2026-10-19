<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/work.php';
require_roles(['admin','manager']);
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) fail(404,'Work report not found.');
$sheet=one("SELECT s.*,u.full_name,u.username,d.code AS department_code,d.name AS department_name FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id WHERE s.id=? AND s.submission_status='submitted' AND u.deleted_at IS NULL",[$id]);
if (!$sheet) fail(404,'Work report not found.');
try { $filter=report_filter(); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$backQuery=http_build_query(['from_date'=>$filter['from'],'to_date'=>$filter['to'],'all_dates'=>$filter['allDates']?1:0,'department'=>$filter['department'],'employee'=>$filter['employee']]);
$downloadQuery=http_build_query(['from_date'=>$sheet['work_date'],'to_date'=>$sheet['work_date'],'employee'=>$sheet['employee_id'],'department'=>$sheet['department_id']]);
$entries=rows('SELECT e.*,c.client_name,a.description AS assignment_brief FROM daily_work_entries e LEFT JOIN clients c ON c.id=e.client_id LEFT JOIN work_assignments a ON a.id=e.assignment_id WHERE e.submission_id=? ORDER BY e.row_order,e.id',[$id]);
$completed=count(array_filter($entries,static fn($entry)=>$entry['task_status']==='completed'));
$timed=count(array_filter($entries,static fn($entry)=>$entry['time_spent_minutes']!==null));
$summary=['entry_count'=>count($entries),'completed_count'=>$completed];
page_start('Daily work details','manager-dailywork.php');
?>
<div class="form-actions work-report-actions">
    <a class="button secondary" href="manager-dailywork.php?<?= h($backQuery) ?>" data-back-to-reports>Back to reports</a>
    <a class="button primary" href="manager-review-work.php?id=<?= $id ?>"><?= icon('edit') ?> Edit / review work</a>
    <a class="button secondary" href="download-work.php?<?= h($downloadQuery) ?>"><?= icon('download') ?> Download Excel</a>
</div>
<section class="panel" aria-labelledby="report-employee">
    <div class="panel-heading"><h2 id="report-employee"><?= h($sheet['full_name']) ?></h2><?= status_badge(report_work_status($summary)) ?></div>
    <dl class="work-report-meta">
        <div><dt>Username</dt><dd><?= h($sheet['username']) ?></dd></div>
        <div><dt>Department</dt><dd><?= h($sheet['department_name']) ?></dd></div>
        <div><dt>Work date</dt><dd><?= h(date('d M Y',strtotime($sheet['work_date']))) ?></dd></div>
        <div><dt>Submitted at</dt><dd><?= h($sheet['submitted_at']?date('d M Y, g:i A',strtotime($sheet['submitted_at'])):'Not recorded') ?></dd></div>
    </dl>
</section>
<div class="stats-grid">
    <article class="stat-card"><h2>Total tasks</h2><strong><?= count($entries) ?></strong></article>
    <article class="stat-card"><h2>Completed</h2><strong><?= $completed ?></strong></article>
    <article class="stat-card"><h2>Pending</h2><strong><?= count($entries)-$completed ?></strong></article>
    <article class="stat-card"><h2>Total time spent</h2><strong class="work-report-duration"><?= h($timed?format_work_duration(array_sum(array_column($entries,'time_spent_minutes'))):'Not entered') ?></strong><?php if ($timed<count($entries)): ?><p><?= count($entries)-$timed ?> task(s) without a recorded time</p><?php endif; ?></article>
</div>
<div class="work-report-entries">
<?php if (!$entries): ?><section class="panel"><p>No work entries in this report.</p></section><?php endif; ?>
<?php foreach ($entries as $index=>$entry): ?>
<article class="panel work-report-entry" aria-labelledby="work-title-<?= $entry['id'] ?>">
    <div class="panel-heading"><span class="muted">Task <?= $index+1 ?></span><?= status_badge($entry['task_status']) ?></div>
    <h2 id="work-title-<?= $entry['id'] ?>"><?= h($entry['task_title']?:($entry['website_page_task']?:($entry['seo_task']?:'Work entry'))) ?></h2>
    <dl class="work-report-meta">
        <?php if (!empty($entry['work_type']) || creative_work_department($sheet['department_code'],$sheet['department_name'])): ?><div><dt>Task type</dt><dd><?= h(work_type_label($entry['work_type'])?:'Not selected') ?></dd></div><?php endif; ?>
        <div><dt>Client</dt><dd><?= h($entry['client_name']?:'No client / internal work') ?></dd></div>
        <div><dt>Time spent</dt><dd><?= h($entry['time_spent_minutes']===null?'Not entered':format_work_duration($entry['time_spent_minutes'])) ?></dd></div>
    </dl>
    <?php if (!empty($entry['assignment_brief'])): ?><h3>Assignment brief</h3><p class="work-report-text"><?= h($entry['assignment_brief']) ?></p><?php endif; ?>
    <h3>Remark</h3><p class="work-report-text"><?= h($entry['remark']!==null && $entry['remark']!==''?$entry['remark']:'No remark added.') ?></p>
    <?php $metrics=[]; foreach (metric_definitions($sheet['department_code']) as $key=>[$label]) if ($entry[$key]!==null && $entry[$key]!=='') $metrics[$label]=$entry[$key]; ?>
    <?php if ($metrics): ?><details class="simple-details"><summary>Work quantities</summary><dl class="work-report-meta"><?php foreach ($metrics as $label=>$value): ?><div><dt><?= h($label) ?></dt><dd><?= h($value) ?></dd></div><?php endforeach; ?></dl></details><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<section class="panel" aria-labelledby="report-feedback"><div class="panel-heading"><h2 id="report-feedback">Manager feedback</h2><?= status_badge($sheet['review_status']) ?></div><p class="work-report-text"><?= h($sheet['manager_remark']!==null && $sheet['manager_remark']!==''?$sheet['manager_remark']:'No feedback added yet.') ?></p></section>
<?php page_end(); ?>
