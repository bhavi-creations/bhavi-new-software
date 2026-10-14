<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/bhavi_credential_test_'.bin2hex(random_bytes(8));
mkdir($root.'/includes',0700,true);
copy(dirname(__DIR__).'/includes/credentials.php',$root.'/includes/credentials.php');
$previous=getenv('BHAVI_CREDENTIAL_KEY');
putenv('BHAVI_CREDENTIAL_KEY');
$checks=0;
function verify_storage(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
try {
    require $root.'/includes/credentials.php';
    $encrypted=credential_encrypt('Fixture-password');
    $path=$root.'/storage/credential-key.php';
    verify_storage(is_file($path),'Key created in private storage');
    verify_storage(credential_decrypt($encrypted)==='Fixture-password','Encrypted credential round trip');
    $original=file_get_contents($path);
    chmod($path,0444);
    verify_storage(credential_decrypt($encrypted)==='Fixture-password','Existing read-only key can be used');
    verify_storage(file_get_contents($path)===$original,'Existing key is unchanged');
    putenv('BHAVI_CREDENTIAL_KEY=invalid');
    try { credential_encrypt('test'); throw new RuntimeException('Invalid environment key accepted'); }
    catch (CredentialStorageException $e) { verify_storage(str_contains($e->getMessage(),'BHAVI_CREDENTIAL_KEY'),'Invalid hosting key gives a useful error'); }
    putenv('BHAVI_CREDENTIAL_KEY='.explode("\n",$original,2)[1]);
    verify_storage(credential_decrypt($encrypted)==='Fixture-password','Configured original key decrypts existing credentials');
    putenv('BHAVI_CREDENTIAL_KEY');
    chmod($path,0600); file_put_contents($path,'invalid-key');
    try { credential_encrypt('test'); throw new RuntimeException('Invalid saved key accepted'); }
    catch (CredentialStorageException $e) { verify_storage(str_contains($e->getMessage(),'Restore the original'),'Invalid saved key requires restoration'); }
    verify_storage(file_get_contents($path)==='invalid-key','Invalid saved key is never silently replaced');
    echo 'PASS: '.$checks." credential storage checks.\n";
} finally {
    if ($previous===false) putenv('BHAVI_CREDENTIAL_KEY'); else putenv('BHAVI_CREDENTIAL_KEY='.$previous);
    foreach ([$root.'/storage/credential-key.php',$root.'/includes/credentials.php'] as $file) if (is_file($file)) { chmod($file,0600); unlink($file); }
    if (is_dir($root.'/storage')) rmdir($root.'/storage');
    rmdir($root.'/includes'); rmdir($root);
}
