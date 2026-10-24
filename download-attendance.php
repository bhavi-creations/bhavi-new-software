<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/excel-export.php';
require_roles(['admin']);

$month=(new DateTimeImmutable('today'))->format('Y-m');
$first=new DateTimeImmutable($month.'-01');
$days=(int)$first->format('t');
$employees=rows("SELECT u.id,u.full_name FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.role='employee' AND u.deleted_at IS NULL ORDER BY u.full_name");
$sessions=rows("SELECT employee_id,DATE(login_at) AS attendance_date,MIN(login_at) AS first_login,CASE WHEN SUM(logout_at IS NULL)>0 THEN NULL ELSE MAX(logout_at) END AS last_logout FROM employee_attendance_sessions WHERE login_at>=? AND login_at<? GROUP BY employee_id,DATE(login_at)",[$first->format('Y-m-d 00:00:00'),$first->modify('+1 month')->format('Y-m-d 00:00:00')]);
$byEmployeeDate=[];
foreach($sessions as $session) $byEmployeeDate[(int)$session['employee_id']][$session['attendance_date']]=$session;
$records=(static function() use($employees,$byEmployeeDate,$first,$days): Generator {
    for($day=1;$day<=$days;$day++) {
        $date=$first->setDate((int)$first->format('Y'),(int)$first->format('m'),$day)->format('Y-m-d');
        $row=[$day,$date];
        foreach($employees as $employee) {
            $session=$byEmployeeDate[(int)$employee['id']][$date]??null;
            $row[]=$session&&$session['first_login']?date('g:i A',strtotime($session['first_login'])):'';
            $row[]=$session&&$session['last_logout']?date('g:i A',strtotime($session['last_logout'])):'';
        }
        yield $row;
    }
})();
$groupedHeaders=['S.no','Date'];
$headers=['',''];
$mergeRanges=[];
foreach($employees as $index=>$employee) {
    $groupedHeaders[]=$employee['full_name'];
    $groupedHeaders[]='';
    $columnIndex=2+($index*2)+1;
    $columnLetters=static function(int $index): string { $letters=''; for($n=$index;$n>0;$n=intdiv($n-1,26)) $letters=chr(65+(($n-1)%26)).$letters; return $letters; };
    $mergeRanges[]=$columnLetters($columnIndex).'1:'.$columnLetters($columnIndex+1).'1';
    $headers[]='Login';
    $headers[]='Logout';
}
download_excel($headers,$records,'bhavi-attendance-'.$month,['grouped_headers'=>$groupedHeaders,'merge_ranges'=>$mergeRanges]);
