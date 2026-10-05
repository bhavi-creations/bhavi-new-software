<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user=require_roles();
$payslip=one('SELECT * FROM payslips WHERE id=?'.(is_staff()?'':' AND employee_id=?'),is_staff()?[(int)($_GET['id']??0)]:[(int)($_GET['id']??0),$user['id']]);
if (!$payslip) fail(404,'Payslip not found.');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="payslip-'.substr($payslip['pay_month'],0,7).'.pdf"');
header('Content-Length: '.strlen($payslip['file_content']));
echo $payslip['file_content'];
