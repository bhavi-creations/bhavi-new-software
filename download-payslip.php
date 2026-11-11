<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user=require_roles();
$payslip=one('SELECT id,pay_month,file_path FROM payslips WHERE id=?'.(is_staff()?'':' AND employee_id=?'),is_staff()?[(int)($_GET['id']??0)]:[(int)($_GET['id']??0),$user['id']]);
if (!$payslip) fail(404,'Payslip not found.');
$name='payslip-'.substr($payslip['pay_month'],0,7).'.pdf';
// New payslips are private files in storage/payslips; older ones are stored in the database.
$path=(string)($payslip['file_path']??'');
if ($path!=='') {
    $absolute=__DIR__.'/'.$path;
    if (!preg_match('~^storage/payslips/[a-f0-9]{40}\.pdf$~D',$path) || !is_file($absolute)) fail(404,'Payslip file is missing. Please upload it again.');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Content-Length: '.filesize($absolute));
    header('Cache-Control: private, no-store');
    while (ob_get_level()) ob_end_clean();
    readfile($absolute);
    exit;
}
$content=(string)(one('SELECT file_content FROM payslips WHERE id=?',[$payslip['id']])['file_content']??'');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$name.'"');
header('Content-Length: '.strlen($content));
header('Cache-Control: private, no-store');
echo $content;
