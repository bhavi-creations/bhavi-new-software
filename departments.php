<?php
require_once __DIR__.'/includes/layout.php';
require_roles(['admin']); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        $name=text_input('name',100);
        if (one('SELECT id FROM departments WHERE name=?',[$name])) throw new InvalidArgumentException('This department already exists.');
        query('INSERT INTO departments (code,name) VALUES (?,?)',['dept_'.bin2hex(random_bytes(8)),$name]);
        flash('Department added. You can now select it when adding employees.'); redirect('departments.php');
    } catch (Throwable $e) { $error=mutation_error($e); }
}
page_start('Departments','departments.php'); error_message($error);
?><section class="panel"><h2>Add department</h2><form method="post"><?= csrf_field() ?><div class="field"><label for="name">Department name</label><input id="name" name="name" maxlength="100" required></div><div class="form-actions"><button class="primary">Add department</button></div></form></section><section class="panel"><h2>Departments</h2><div class="table-scroll"><table><thead><tr><th>Department</th><th>Employees</th></tr></thead><tbody><?php foreach (rows("SELECT d.id,d.name,COUNT(u.id) AS total FROM departments d LEFT JOIN employee_profiles ep ON ep.department_id=d.id LEFT JOIN users u ON u.id=ep.user_id AND u.deleted_at IS NULL AND u.role='employee' WHERE d.is_active=1 GROUP BY d.id,d.name ORDER BY d.name") as $department): ?><tr><td><?= h($department['name']) ?></td><td><?= $department['total'] ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php page_end();
