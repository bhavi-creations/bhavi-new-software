<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/finance.php';
$user=require_roles();
$id=isset($_GET['id'])?positive_id($_GET['id']):(int)$user['id'];
if (!is_staff() && $id!==(int)$user['id']) fail(403,'You can only view your own employee profile.');
$employee=one("SELECT u.full_name,u.email,u.avatar_path,u.account_status,ep.*,d.name AS department_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL",[$id]);
if (!$employee) fail(404,'Employee not found.');
page_start(is_staff()?'Employee profile':'My profile & salary','employee-profile.php');
?><div class="employee-profile-layout"><section class="panel employee-profile-card"><h2><?= h($employee['full_name']) ?></h2><?php if ($employee['avatar_path']): ?><img class="employee-profile-photo" src="employee-photo.php?id=<?= $id ?>" alt="Employee photo"><?php endif; ?><dl class="details-list"><?php foreach (['employee_code'=>'Employee ID','email'=>'Email','phone'=>'Phone number','guardian_phone'=>'Guardian number','department_name'=>'Department','designation'=>'Designation','joining_date'=>'Joining date','relieving_date'=>'Relieving date','account_status'=>'Status'] as $key=>$label): ?><dt><?= $label ?></dt><dd><?= h($employee[$key]?:'—') ?></dd><?php endforeach; ?><dt>Benefits selected</dt><dd><?= h(benefit_label($employee)) ?></dd><?php if ($employee['benefit_other']): ?><dt>Other benefits</dt><dd><?= h($employee['other_benefits']) ?></dd><?php endif; ?></dl><?php if (is_staff()): ?><a class="button" href="admin-add-employee.php?id=<?= $id ?>">Edit employee</a><?php endif; ?></section>
<div class="employee-profile-main"><?php $current=one('SELECT * FROM employee_salary_history WHERE employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1',[$id,today(),today()]); ?>
<section class="panel"><h2>Current monthly salary</h2><?php if (!$current): ?><p>No salary has been set for today.</p><?php else: ?>
<?php salary_summary($current); ?>
<p class="help">Salary starts on <?= h($current['effective_from']) ?>. Employee receives = salary minus employee PF and ESI.</p>
<details class="simple-details"><summary>View PF & ESI breakdown</summary><?php salary_details($current); ?></details>
<?php endif; ?></section>
<section class="panel"><details class="simple-details"><summary>View salary & increment history</summary><?php salary_table(rows('SELECT * FROM employee_salary_history WHERE employee_id=? ORDER BY effective_from',[$id])); ?></details></section></div></div><?php page_end(); ?>
