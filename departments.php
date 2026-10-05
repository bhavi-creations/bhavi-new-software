<?php
require_once __DIR__.'/includes/layout.php';
require_roles(['admin']); $error=null;
$editId=(int)($_GET['edit']??0);
$record=$editId?one('SELECT * FROM departments WHERE id=? AND is_active=1',[$editId]):null;
if ($editId && !$record) fail(404,'Department not found.');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if (($_POST['action']??'')==='delete_department') {
            $id=positive_id($_POST['id']??null);
            db()->beginTransaction();
            if (!one('SELECT id FROM departments WHERE id=? AND is_active=1 FOR UPDATE',[$id])) throw new InvalidArgumentException('Department not found.');
            if (count_value('SELECT COUNT(*) FROM employee_profiles ep JOIN users u ON u.id=ep.user_id WHERE ep.department_id=? AND u.deleted_at IS NULL',[$id])) throw new InvalidArgumentException('Move the employees to another department before deleting this department.');
            query('UPDATE departments SET is_active=0 WHERE id=?',[$id]);
            db()->commit(); flash('Department deleted. Historical reports are retained.');
        } else {
            $name=text_input('name',100);
            if (one('SELECT id FROM departments WHERE name=? AND id<>?',[$name,$editId])) throw new InvalidArgumentException('This department name is already in use.');
            if ($record) query('UPDATE departments SET name=? WHERE id=?',[$name,$editId]);
            else query('INSERT INTO departments (code,name) VALUES (?,?)',['dept_'.bin2hex(random_bytes(8)),$name]);
            flash($record?'Department updated.':'Department added. You can now select it when adding employees.');
        }
        redirect('departments.php');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=mutation_error($e); }
}
page_start('Departments','departments.php'); error_message($error);
?><section class="panel"><h2><?= $record?'Edit department':'Add department' ?></h2><form method="post"><?= csrf_field() ?><div class="field"><label for="name">Department name</label><input id="name" name="name" maxlength="100" value="<?= h($_POST['name']??$record['name']??'') ?>" required></div><div class="form-actions"><button class="primary"><?= $record?'Save changes':'Add department' ?></button><?php if ($record): ?><a class="button secondary" href="departments.php">Cancel</a><?php endif; ?></div></form></section><section class="panel"><h2>Departments</h2><div class="table-scroll"><table><thead><tr><th>Department</th><th>Employees</th><th>Actions</th></tr></thead><tbody><?php foreach (rows("SELECT d.id,d.name,COUNT(u.id) AS total FROM departments d LEFT JOIN employee_profiles ep ON ep.department_id=d.id LEFT JOIN users u ON u.id=ep.user_id AND u.deleted_at IS NULL AND u.role='employee' WHERE d.is_active=1 GROUP BY d.id,d.name ORDER BY d.name") as $department): ?><tr><td><?= h($department['name']) ?></td><td><?= $department['total'] ?></td><td><div class="actions"><a class="button secondary" href="departments.php?edit=<?= $department['id'] ?>">Edit</a><?php delete_button((int)$department['id'],'delete_department','Delete this department? Existing reports will be retained.'); ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php page_end();
