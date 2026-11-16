<?php
// "Download all": every file a client sent with one request, as one ZIP, with the same names the client used.
// Same access as one file (download-requirement-file.php).
// Written without ZipArchive (not enabled on every host): files are "stored" uncompressed.
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/requirement-files.php';
$user=require_roles(['admin','manager','employee','client']);
$requirementId=positive_id($_GET['id']??null);
$requirement=one('SELECT r.id,r.client_id,r.department_id,r.shared_with_all,r.title,c.client_name FROM client_requirements r JOIN clients c ON c.id=r.client_id WHERE r.id=? AND c.deleted_at IS NULL',[$requirementId]);
if (!$requirement) fail(404,'Request not found.');
if ($user['role']==='client') {
    if (!one('SELECT id FROM clients WHERE id=? AND user_id=?',[$requirement['client_id'],$user['id']])) fail(403,'You cannot access these files.');
} elseif ($user['role']==='employee' && !(int)$requirement['shared_with_all'] && (int)$requirement['department_id']!==(int)$user['department_id'] && !one('SELECT requirement_id FROM requirement_assignments WHERE requirement_id=? AND employee_id=?',[$requirementId,$user['id']])) {
    fail(403,'This request belongs to another department.');
}

$files=[]; $used=[];
foreach (rows('SELECT * FROM requirement_files WHERE requirement_id=? ORDER BY id',[$requirementId]) as $file) {
    $path=requirement_file_path($file);
    if (!$path) continue;
    $name=trim(str_replace(['/','\\'],'_',basename(str_replace('\\','/',(string)$file['original_file_name']))))?:'file-'.(int)$file['id'];
    $stem=pathinfo($name,PATHINFO_FILENAME); $ext=pathinfo($name,PATHINFO_EXTENSION);
    for ($n=2; isset($used[mb_strtolower($name)]); $n++) $name=$stem.' ('.$n.')'.($ext!==''?'.'.$ext:'');
    $used[mb_strtolower($name)]=true;
    $files[]=['path'=>$path,'name'=>$name,'size'=>(int)filesize($path),'time'=>filemtime($path)?:time()];
}
if (!$files) {
    flash('None of these files are on the server any more. Ask the client to send them again.','error');
    $back=(string)parse_url((string)($_SERVER['HTTP_REFERER']??''),PHP_URL_PATH);
    $back=preg_match('~/([a-z0-9-]+\.php)$~',$back,$m)&&!str_starts_with($m[1],'download-')?$m[1]:'client-reuirement.php';
    redirect($back.'#request-'.$requirementId);
}
if (array_sum(array_column($files,'size'))>3_900_000_000) fail(413,'These files are too large for one ZIP. Download them one by one.');

$dosTime=static function (int $ts): array {
    $d=getdate($ts); $year=max(1980,$d['year']);
    return [($d['hours']<<11)|($d['minutes']<<5)|intdiv($d['seconds'],2),(($year-1980)<<9)|($d['mon']<<5)|$d['mday']];
};
$zipName=trim((string)preg_replace('/[^A-Za-z0-9]+/','-',(string)$requirement['client_name']),'-');
$zipName=($zipName!==''?substr($zipName,0,60).'-':'').'request-'.(int)$requirementId.'-files.zip';

session_write_close();
while (ob_get_level()>0) ob_end_clean();
@set_time_limit(0);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$zipName.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$offset=0; $central='';
foreach ($files as $file) {
    $crc=hexdec(hash_file('crc32b',$file['path']));
    [$time,$date]=$dosTime($file['time']);
    $nameLen=strlen($file['name']);
    // Local file header (version 2.0, flag bit 11 = UTF-8 names, method 0 = stored)
    $header=pack('VvvvvvVVVvv',0x04034b50,20,0x0800,0,$time,$date,$crc,$file['size'],$file['size'],$nameLen,0).$file['name'];
    echo $header;
    $handle=fopen($file['path'],'rb');
    while ($handle && !feof($handle)) { $chunk=fread($handle,1048576); if ($chunk===false || $chunk==='') break; echo $chunk; }
    if ($handle) fclose($handle);
    flush();
    $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0x0800,0,$time,$date,$crc,$file['size'],$file['size'],$nameLen,0,0,0,0,0,$offset).$file['name'];
    $offset+=strlen($header)+$file['size'];
}
echo $central;
echo pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($central),$offset,0);
