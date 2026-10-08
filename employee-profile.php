<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/finance.php';
$user=require_roles();
$id=isset($_GET['id'])?positive_id($_GET['id']):(int)$user['id'];
if (!is_staff() && $id!==(int)$user['id']) fail(403,'You can only view your own employee profile.');
$employee=one("SELECT u.full_name,u.email,u.username,u.avatar_path,u.account_status,u.created_at,ep.*,d.name AS department_name,manager.full_name AS manager_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id LEFT JOIN users manager ON manager.id=ep.manager_id WHERE u.id=? AND u.role='employee' AND u.deleted_at IS NULL",[$id]);
if (!$employee) fail(404,'Employee not found.');
$current=one('SELECT * FROM employee_salary_history WHERE employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1',[$id,today(),today()]);
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!is_staff()) fail(403,'Only management can change salary benefits.');
    check_csrf();
    try {
        if (($_POST['action']??'')!=='save_benefits') throw new InvalidArgumentException('Choose a valid benefits action.');
        $salaryId=positive_id($_POST['salary_id']??null);
        $benefits=other_benefit_input(true);
        $benefits['benefit_other']=(money_cents($benefits['other_benefit_amount'])>0 || $benefits['other_benefits']!=='')?1:0;
        db()->beginTransaction();
        if (!one('SELECT ep.user_id FROM employee_profiles ep JOIN users u ON u.id=ep.user_id WHERE ep.user_id=? AND u.deleted_at IS NULL FOR UPDATE',[$id])) throw new InvalidArgumentException('This employee is no longer available.');
        $salary=one('SELECT * FROM employee_salary_history WHERE id=? AND employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE',[$salaryId,$id,today(),today()]);
        if (!$salary) throw new InvalidArgumentException('The current salary period changed. Refresh the profile before saving benefits.');
        $net=salary_net_amount(money_cents($salary['amount']),money_cents($salary['employee_pf']),money_cents($salary['employee_esi']),money_cents($benefits['other_benefit_amount']));
        query('UPDATE employee_salary_history SET benefit_other=?,other_benefits=?,other_benefit_amount=?,net_salary=? WHERE id=?',array_merge(array_values($benefits),[$net,$salaryId]));
        query('UPDATE employee_profiles SET benefit_other=?,other_benefits=?,other_benefit_amount=? WHERE user_id=?',array_merge(array_values($benefits),[$id]));
        db()->commit();
        flash('Benefits saved. The employee receives total has been updated.');
        redirect('employee-profile.php?id='.$id);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error=mutation_error($e);
    }
}
$benefitValue=static fn(string $key)=>is_string($_POST[$key]??null)?$_POST[$key]:($current[$key]??'');
page_start(is_staff()?'Employee profile':'My profile & salary','employee-profile.php');
error_message($error);
?><div class="employee-profile-layout"><section class="panel employee-profile-card"><h2><?= h($employee['full_name']) ?></h2><?php if ($employee['avatar_path']): ?><img class="employee-profile-photo" src="employee-photo.php?id=<?= $id ?>" alt="Employee photo"><?php endif; ?><dl class="details-list"><?php foreach (['employee_code'=>'Employee ID','username'=>'Username','email'=>'Email','phone'=>'Phone number','guardian_phone'=>'Guardian number','department_name'=>'Department','role_title'=>'Role / job title','designation'=>'Designation','manager_name'=>'Manager','joining_date'=>'Joining date','relieving_date'=>'Relieving date','created_at'=>'Added on','account_status'=>'Status'] as $key=>$label): ?><dt><?= $label ?></dt><dd><?= h($employee[$key]?:'—') ?></dd><?php endforeach; ?><dt>Benefits selected</dt><dd><?= h(benefit_label($employee)) ?></dd><?php if ($employee['benefit_other']): ?><dt>Other benefits</dt><dd><?= h($employee['other_benefits']) ?></dd><?php endif; ?></dl><?php if (is_staff()): ?><a class="button" href="admin-add-employee.php?id=<?= $id ?>">Edit employee</a><?php endif; ?></section>
<div class="employee-profile-main">
<section class="panel"><h2>Current monthly salary</h2><?php if (!$current): ?><p>No salary has been set for today.</p><?php else: ?>
<?php salary_summary($current); salary_benefit_note($current); ?>
<p class="help">Salary starts on <?= h($current['effective_from']) ?>. Employee receives = salary + benefits amount minus employee PF and ESI.</p>
<?php if (is_staff()): ?>
<form method="post" id="current-benefits-form" class="record-form salary-benefits-form" data-salary="<?= h($current['amount']) ?>" data-pf="<?= h($current['employee_pf']) ?>" data-esi="<?= h($current['employee_esi']) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="save_benefits"><input type="hidden" name="salary_id" value="<?= (int)$current['id'] ?>">
<h3>Benefits</h3><div class="fields"><div class="field"><label for="other_benefit_amount">Benefits amount (₹)</label><input id="other_benefit_amount" name="other_benefit_amount" type="number" min="0" max="9999999999.99" step="0.01" value="<?= h($benefitValue('other_benefit_amount')) ?>" placeholder="e.g. 500"></div><div class="field"><label for="other_benefits">Reason for benefits</label><input id="other_benefits" name="other_benefits" maxlength="255" value="<?= h($benefitValue('other_benefits')) ?>" placeholder="e.g. Travel allowance"></div></div>
<p class="help">Enter the total monthly benefits amount for this salary period. Saving replaces the current amount; leave empty or enter 0 to remove it.</p>
<p class="benefits-preview" aria-live="polite">Employee receives after saving: <strong id="current-benefits-preview"><?= h(money_label($current['net_salary'])) ?></strong></p>
<button type="submit" class="primary">Save benefits</button>
</form>
<?php endif; ?>
<details class="simple-details"><summary>View PF & ESI breakdown</summary><?php salary_details($current); ?></details>
<?php endif; ?></section>
<section class="panel"><details class="simple-details"><summary>View salary & increment history</summary><?php salary_table(rows('SELECT * FROM employee_salary_history WHERE employee_id=? ORDER BY effective_from',[$id])); ?></details></section></div></div><?php if (is_staff()): ?><script src="assets/js/records.js?v=<?= filemtime(__DIR__.'/assets/js/records.js') ?>" defer></script><?php endif; page_end(); ?>
