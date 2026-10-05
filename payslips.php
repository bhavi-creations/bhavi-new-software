<?php
require_once __DIR__ . '/includes/layout.php';
$user=require_roles(); $staff=is_staff(); $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_roles(['admin','manager']); check_csrf();
    try {
        $department=positive_id($_POST['department_id']??null);
        $employee=positive_id($_POST['employee_id']??null);
        if (!one("SELECT u.id FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND ep.department_id=? AND u.role='employee' AND u.account_status='active' AND u.deleted_at IS NULL",[$employee,$department])) throw new InvalidArgumentException('Choose an active employee from the selected department.');
        $month=text_input('pay_month',7);
        date_input('pay_date',['pay_date'=>$month.'-01']);
        $file=$_FILES['payslip']??null;
        if (!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Choose a PDF payslip to upload (maximum 2 MB).');
        if (filesize($file['tmp_name'])>2*1024*1024) throw new InvalidArgumentException('Payslip must be 2 MB or smaller.');
        if ((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])!=='application/pdf') throw new InvalidArgumentException('Only PDF payslips are accepted.');
        $content=file_get_contents($file['tmp_name']);
        query('INSERT INTO payslips (employee_id,pay_month,file_content,uploaded_by) VALUES (?,?,?,?)',[$employee,$month.'-01',$content,$user['id']]);
        flash('Payslip added. Only the selected employee and management can access it.'); redirect('payslips.php');
    } catch (Throwable $e) { $error=$e instanceof PDOException && $e->getCode()==='23000'?'A payslip already exists for this employee and month.':mutation_error($e); }
}
$records=rows('SELECT p.id,p.pay_month,p.created_at,u.full_name,d.name AS department_name FROM payslips p JOIN users u ON u.id=p.employee_id JOIN employee_profiles ep ON ep.user_id=u.id JOIN departments d ON d.id=ep.department_id'.($staff?'':' WHERE p.employee_id=?').' ORDER BY p.pay_month DESC,p.id DESC',$staff?[]:[$user['id']]);
page_start($staff?'Employee payslips':'My payslips','payslips.php'); error_message($error);
?>
<?php if ($staff): ?>
<section class="panel"><h2>Add payslip</h2><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><div class="fields">
<div class="field"><label for="department_id">Department</label><select id="department_id" name="department_id" data-department-select="employee_id" required><option value="">Select department</option><?= options(departments(),'name',$_POST['department_id']??'') ?></select></div>
<div class="field"><label for="employee_id">Employee</label><select id="employee_id" name="employee_id" required><option value="">Select employee</option><?php foreach (employees() as $employee): if ($employee['account_status']!=='active') continue; ?><option value="<?= $employee['id'] ?>" data-department="<?= $employee['department_id'] ?>" <?= (string)$employee['id']===(string)($_POST['employee_id']??'')?'selected':'' ?>><?= h($employee['full_name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="pay_month">Salary month</label><input id="pay_month" name="pay_month" type="month" value="<?= h($_POST['pay_month']??date('Y-m')) ?>" required></div>
<div class="field"><label for="payslip">Payslip PDF (maximum 2 MB)</label><input id="payslip" name="payslip" type="file" accept="application/pdf,.pdf" required></div></div>
<div class="form-actions"><button class="primary" type="submit">Add payslip</button></div></form></section>
<?php endif; ?>
<section class="panel"><h2><?= $staff?'Uploaded payslips':'Your payslips' ?></h2><div class="table-scroll"><table><thead><tr><th>Salary month</th><?php if ($staff): ?><th>Employee</th><th>Department</th><?php endif; ?><th>Added on</th><th>Download</th></tr></thead><tbody>
<?php if (!$records) empty_row($staff?5:3,'No payslips added yet.'); foreach ($records as $record): ?><tr><td><?= h(date('F Y',strtotime($record['pay_month']))) ?></td><?php if ($staff): ?><td><?= h($record['full_name']) ?></td><td><?= h($record['department_name']) ?></td><?php endif; ?><td><?= h($record['created_at']) ?></td><td><a class="button secondary" href="download-payslip.php?id=<?= $record['id'] ?>">Download PDF</a></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php page_end(); ?>
