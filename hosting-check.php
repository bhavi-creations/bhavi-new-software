<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/database/install.php';
$user=require_roles(['admin']);
$checks=[];
$add=static function(string $name,bool $ok,string $message) use (&$checks): void { $checks[]=[$name,$ok,$message]; };
$add('PHP runtime',PHP_VERSION_ID>=80000,'PHP '.PHP_VERSION.'; application requires PHP 8.0 or later.');
foreach (['pdo_mysql','mbstring','fileinfo','openssl','session'] as $extension) $add('PHP '.$extension,extension_loaded($extension),extension_loaded($extension)?'Enabled':'Enable this PHP extension in the subdomain PHP settings.');
$add('OpenSSL AES-256-GCM',function_exists('openssl_get_cipher_methods') && in_array('aes-256-gcm',openssl_get_cipher_methods(),true),'Required to save and read account credentials.');
$version=(int)query('SELECT MAX(version) FROM portal_schema_versions')->fetchColumn();
$add('Database migration',$version>=PORTAL_SCHEMA_VERSION,'Installed schema '.$version.'; required '.PORTAL_SCHEMA_VERSION.'.');
foreach (['users'=>['credential_cipher','deleted_at'],'employee_profiles'=>['employee_code','pf_number','esi_number','other_benefit_amount'],'employee_documents'=>['file_path'],'clients'=>['user_id','client_type'],'client_social_links'=>['username','password_cipher'],'client_work_reports'=>['report_date','uploaded_at']] as $table=>$columns) {
    try { query('SELECT '.implode(',',$columns).' FROM '.$table.' LIMIT 0'); $add($table.' columns',true,'Required columns exist.'); }
    catch (Throwable $e) { $add($table.' columns',false,'Required columns unavailable. Upload database/install.php and run setup.php to repair the schema.'); }
}
foreach (['includes/employee-accounts.php','includes/credentials.php','includes/employee-documents.php','database/Bhavi_Team_Portal_Full_Database.sql','storage/.htaccess','uploads/photos/.htaccess','uploads/pdf/.htaccess'] as $file) $add($file,is_file(__DIR__.'/'.$file),is_file(__DIR__.'/'.$file)?'Present':'Missing from the uploaded application. Upload this file including hidden .htaccess files.');
foreach (['storage','uploads/photos','uploads/pdf'] as $folder) {
    $root=__DIR__.'/'.$folder;
    $add($folder.' permissions',is_dir($root) && is_writable($root),is_dir($root) && is_writable($root)?'Folder exists and is writable by PHP.':'Create this folder and give the hosting PHP account write access.');
}
$keyPath=__DIR__.'/storage/credential-key.php';
if (getenv('BHAVI_CREDENTIAL_KEY') || is_file($keyPath)) {
    try { credential_key(); $add('Credential key',true,'Existing key can be read and has the expected format.'); }
    catch (CredentialStorageException $e) { $add('Credential key',false,$e->getMessage()); }
} else $add('Credential key',is_writable(__DIR__.'/storage'),'Key will be created when the first credential is saved. Existing encrypted data requires the original key from its backup.');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    foreach (['storage','uploads/photos','uploads/pdf'] as $folder) {
        $path=__DIR__.'/'.$folder.'/.hosting-probe-'.bin2hex(random_bytes(12));
        $handle=@fopen($path,'x+b'); $ok=false;
        if ($handle) { $ok=fwrite($handle,'check')===5 && fflush($handle); fclose($handle); $ok=@unlink($path) && $ok; }
        $add($folder.' write test',$ok,$ok?'Created and removed a temporary test file successfully.':'PHP could not write/remove a test file. Ask hosting support to check ownership, permissions and disk quota.');
    }
    $previous=$_SESSION['hosting_probe']??null;
    $_SESSION['hosting_probe']=true;
    $add('Session persistence',$previous===true,$previous===true?'Session data persisted between checks.':'Run checks once more to verify session data persists.');
}
page_start('Hosting checks','hosting-check.php');
?><section class="panel"><p>Checks for this server only. Database passwords, encryption keys and employee data are never shown.</p><p>PHP upload limit: <strong><?= h(ini_get('upload_max_filesize')) ?></strong>; total request limit: <strong><?= h(ini_get('post_max_size')) ?></strong>; maximum files: <strong><?= h(ini_get('max_file_uploads')) ?></strong>.</p><p>Images are stored in <strong>uploads/photos/</strong>; PDFs in <strong>uploads/pdf/</strong>. PHP hosting limits can be lower than the application's file-size limits.</p><form method="post"><?= csrf_field() ?><button class="primary">Run storage and session checks</button></form></section><section class="panel"><div class="table-scroll"><table><thead><tr><th>Check</th><th>Status</th><th>Result / action</th></tr></thead><tbody><?php foreach ($checks as [$label,$ok,$message]): ?><tr><td><?= h($label) ?></td><td><?= $ok?'PASS':'CHECK' ?></td><td><?= h($message) ?></td></tr><?php endforeach; ?></tbody></table></div></section><p class="help">A passing check does not verify every save operation. For a failed save, also share its exact message including the Save step. Do not delete the credential key.</p><?php page_end(); ?>
