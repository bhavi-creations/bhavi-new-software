<?php
require_once __DIR__ . '/layout.php'; require_once __DIR__ . '/work.php';
require_roles(['admin','manager']); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')!=='delete_submission') throw new InvalidArgumentException('Invalid action.');
        $id=positive_id($_POST['id']??null);
        db()->beginTransaction();
        if (!one('SELECT id FROM daily_work_submissions WHERE id=? FOR UPDATE',[$id])) throw new InvalidArgumentException('Report not found.');
        $assignments=array_filter(array_column(rows('SELECT assignment_id FROM daily_work_entries WHERE submission_id=?',[$id]),'assignment_id'));
        query('DELETE FROM daily_work_entries WHERE submission_id=?',[$id]);
        query("UPDATE daily_work_submissions SET submission_status='draft',submitted_at=NULL,review_status='pending',reviewed_by=NULL,reviewed_at=NULL,manager_remark=NULL WHERE id=?",[$id]);
        foreach (array_unique($assignments) as $assignment) sync_assignment((int)$assignment);
        db()->commit(); flash('Work report deleted.'); redirect('manager-dailywork.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
try { $filter=report_filter(); } catch (InvalidArgumentException $e) { fail(422,$e->getMessage()); }
$reports=rows('SELECT s.*,u.full_name,d.name AS department_name,d.code AS department_code,COUNT(e.id) AS entry_count,COALESCE(SUM(e.task_status=\'completed\'),0) AS completed_count FROM daily_work_submissions s JOIN users u ON u.id=s.employee_id JOIN departments d ON d.id=s.department_id JOIN daily_work_entries e ON e.submission_id=s.id WHERE '.$filter['where'].' GROUP BY s.id,u.full_name,d.name,d.code ORDER BY s.work_date DESC,u.full_name',$filter['params']);
$queryString=http_build_query(['from_date'=>$filter['from'],'to_date'=>$filter['to'],'department'=>$filter['department'],'employee'=>$filter['employee']]);
$employeeList=$filter['department']
    ? rows("SELECT u.id,u.full_name FROM users u WHERE u.role='employee' AND (EXISTS (SELECT 1 FROM employee_profiles ep WHERE ep.user_id=u.id AND ep.department_id=?) OR EXISTS (SELECT 1 FROM daily_work_submissions historic WHERE historic.employee_id=u.id AND historic.department_id=?)) ORDER BY u.full_name",[$filter['department'],$filter['department']])
    : rows("SELECT id,full_name FROM users WHERE role='employee' ORDER BY full_name");
page_start('Daily work reports','manager-dailywork.php'); error_message($error);
?><p class="muted">View submitted work from every department. Filter by category, employee or any date range.</p><section class="panel"><form method="get" class="filters"><div class="field"><label for="range">Date range</label><select id="range" data-range-preset data-today="<?= today() ?>"><option value="custom">Custom dates</option><option value="day">Today</option><option value="week">Last 7 days</option><option value="month">This month</option></select></div><div class="field"><label for="from_date">From date</label><input type="date" id="from_date" name="from_date" value="<?= h($filter['from']) ?>" required></div><div class="field"><label for="to_date">To date</label><input type="date" id="to_date" name="to_date" value="<?= h($filter['to']) ?>" required></div><div class="field"><label for="department">Department / category</label><select id="department" name="department" data-filter-department><option value="">All departments</option><?= options(departments(),'name',$filter['department']) ?></select></div><div class="field"><label for="employee">Employee</label><select id="employee" name="employee"><option value="">All employees</option><?= options($employeeList,'full_name',$filter['employee']) ?></select></div><button class="primary" type="submit">Apply filters</button><button class="button secondary" type="submit" formaction="download-work.php"><?= icon('download') ?> Download CSV</button></form><p class="help">Download opens in Excel and includes all work fields for the selected day, week, month or custom dates.</p></section>
<div class="stats-grid"><article class="stat-card"><h2>Submissions</h2><strong><?= count($reports) ?></strong><p>Employee daily reports</p></article><article class="stat-card"><h2>Work entries</h2><strong><?= array_sum(array_column($reports,'entry_count')) ?></strong><p>Across the selected dates</p></article><article class="stat-card"><h2>Completed tasks</h2><strong><?= array_sum(array_column($reports,'completed_count')) ?></strong><p>Across selected departments</p></article><a class="stat-card" href="download-work.php?<?= h($queryString) ?>"><h2>Download report</h2><strong><?= icon('download') ?></strong><p><?= h($filter['from']) ?> to <?= h($filter['to']) ?></p></a></div>
<section class="panel"><div class="table-scroll"><table><thead><tr><th>Date</th><th>Employee</th><th>Department</th><th>Work summary</th><th>Submitted</th><th>Review</th><th>Actions</th></tr></thead><tbody><?php if (!$reports) empty_row(7,'No submitted work matches these filters.'); foreach ($reports as $report): ?><tr><td><?= h($report['work_date']) ?></td><td><?= h($report['full_name']) ?></td><td><?= h($report['department_name']) ?></td><td><?= $report['entry_count'] ?> entries · <?= $report['completed_count'] ?> completed</td><td><?= h($report['submitted_at']) ?></td><td><?= status_badge($report['review_status']) ?></td><td><div class="actions"><?php detail_button('report-'.$report['id']); ?><a class="icon-button" href="manager-review-work.php?id=<?= $report['id'] ?>" title="Edit / review work" aria-label="Edit / review work"><?= icon('edit') ?></a><?php delete_button((int)$report['id'],'delete_submission','Delete this daily work report and its entries?'); ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php foreach ($reports as $report) {
    $fields=['Employee'=>$report['full_name'],'Department'=>$report['department_name'],'Date'=>$report['work_date'],'Submitted'=>$report['submitted_at'],'Review'=>$report['review_status'],'Manager remark'=>$report['manager_remark']];
    foreach (rows('SELECT e.*,c.client_name FROM daily_work_entries e JOIN clients c ON c.id=e.client_id WHERE e.submission_id=? ORDER BY e.row_order',[$report['id']]) as $index=>$entry) {
        $parts=['Client: '.$entry['client_name']]; foreach (work_details($entry,$report['department_code']) as $label=>$value) $parts[]=$label.': '.($value??'');
        $fields['Work '.($index+1)]=implode("\n",$parts);
    }
    detail_dialog('report-'.$report['id'],$report['full_name'].' · Work details',$fields);
} page_end(); ?>
