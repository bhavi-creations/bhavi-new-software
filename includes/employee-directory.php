<?php
require_once __DIR__ . '/layout.php';
$user = require_roles();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_roles(['admin','manager']); check_csrf();
    try {
        if (($_POST['action'] ?? '') !== 'delete_employee') { throw new InvalidArgumentException('Invalid action.'); }
        $id = positive_id($_POST['id'] ?? null);
        $target = one('SELECT id,role FROM users WHERE id=? AND deleted_at IS NULL', [$id]);
        if (!$target || !in_array($target['role'], ['employee'], true)) { fail(403,'You cannot delete this account.'); }
        query("UPDATE users SET deleted_at=NOW(),account_status='inactive' WHERE id=?",[$id]);
        flash('Account deleted. The employee can no longer sign in.'); redirect('admin-employees.php');
    } catch (Throwable $e) { $error = mutation_error($e); }
}
$department = (int) ($_GET['department'] ?? 0);
$directory = array_values(array_filter(employees(), static fn($employee) => !$department || (int) $employee['department_id'] === $department));
page_start('Employees','admin-employees.php'); error_message($error);
?><div class="toolbar"><p class="muted"><?= count($directory) ?> employees</p><?php if ($user['role']==='admin'): ?><a class="button" href="admin-add-employee.php">+ Add employee</a><?php endif; ?></div>
<form method="get" class="filters"><div class="field"><label for="department">Department</label><select id="department" name="department"><option value="">All departments</option><?= options(departments(),'name',$department) ?></select></div><button class="primary" type="submit">Filter</button></form>
<section class="panel"><div class="table-scroll"><table><thead><tr><th>Name</th><th>Department</th><th>Designation</th><th>Added on</th><th>Username</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if (!$directory) empty_row(7); foreach ($directory as $employee): ?><tr><td><?php if (is_staff() && !empty($employee['avatar_path'])): ?><img class="client-logo" loading="lazy" src="employee-photo.php?id=<?= $employee['id'] ?>" alt="<?= h($employee['full_name']) ?> photo"> <?php endif; ?><?= h($employee['full_name']) ?></td><td><?= h($employee['department_name']) ?></td><td><?= h($employee['designation']) ?></td><td><?= h($employee['created_at']) ?></td><td><?= h($employee['username']) ?></td><td><?= status_badge($employee['account_status']) ?></td><td><div class="actions"><?php detail_button('employee-'.$employee['id']); if (is_staff()): ?><a class="icon-button" href="admin-add-employee.php?id=<?= $employee['id'] ?>" title="Edit employee" aria-label="Edit employee"><?= icon('edit') ?></a><?php delete_button((int)$employee['id'],'delete_employee','Delete this employee account?'); endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php foreach ($directory as $employee) detail_dialog('employee-'.$employee['id'],$employee['full_name'],['Name'=>$employee['full_name'],'Department'=>$employee['department_name'],'Designation'=>$employee['designation'],'Email'=>$employee['email'],'Username'=>$employee['username'],'Joining date'=>$employee['joining_date'],'Status'=>$employee['account_status']]); ?>
<?php page_end(); ?>
