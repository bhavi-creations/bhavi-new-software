<?php
// Authentication uses hashes. Recoverable credentials use authenticated encryption.
class CredentialStorageException extends RuntimeException {}
function credential_key(): string {
    $configured=getenv('BHAVI_CREDENTIAL_KEY');
    if ($configured!==false && $configured!=='') {
        $key=base64_decode($configured,true);
        if ($key===false || strlen($key)!==32) throw new CredentialStorageException('Hosting configuration: BHAVI_CREDENTIAL_KEY must contain a base64-encoded 32-byte key.');
        return $key;
    }
    $directory=dirname(__DIR__).'/storage';
    $path=$directory.'/credential-key.php';
    if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new CredentialStorageException('Hosting storage error: PHP cannot create storage/. Ask hosting support to give the PHP account ownership and write access to this folder.');
    // Existing keys need read access only. Never recreate or overwrite a saved key.
    $handle=@fopen($path,'rb');
    $created=false;
    if (!$handle) {
        if (file_exists($path)) throw new CredentialStorageException('Hosting storage error: PHP cannot read storage/credential-key.php. Correct its owner/read permissions; do not delete or replace this key.');
        $handle=@fopen($path,'x+b');
        if ($handle) $created=true;
        else $handle=@fopen($path,'rb'); // Another request may have created it.
    }
    if (!$handle) throw new CredentialStorageException('Hosting storage error: PHP cannot create storage/credential-key.php. Make storage/ writable by the hosting PHP account and check available disk space.');
    if (!flock($handle,$created?LOCK_EX:LOCK_SH)) {
        fclose($handle);
        throw new CredentialStorageException('Hosting storage error: PHP cannot lock the credential key. Ask hosting support to enable file locking, or configure BHAVI_CREDENTIAL_KEY with the existing key.');
    }
    try {
        if ($created) {
            $data="<?php exit; ?>\n".base64_encode(random_bytes(32));
            if (fwrite($handle,$data)!==strlen($data) || !fflush($handle)) throw new CredentialStorageException('Hosting storage error: credential key could not be saved. Check disk space and storage/ permissions.');
            @chmod($path,0600);
        } else $data=stream_get_contents($handle);
        $key=is_string($data)?base64_decode(explode("\n",$data,2)[1]??'',true):false;
        if ($key===false || strlen($key)!==32) throw new CredentialStorageException('Hosting storage error: the saved credential key is invalid. Restore the original storage/credential-key.php backup; do not generate a replacement for existing credentials.');
        return $key;
    } finally { flock($handle,LOCK_UN); fclose($handle); }
}
function require_credential_encryption(): void {
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_get_cipher_methods') || !in_array('aes-256-gcm',openssl_get_cipher_methods(),true)) throw new CredentialStorageException('Hosting PHP configuration: enable the OpenSSL extension with AES-256-GCM support to save account credentials.');
}
function credential_encrypt(string $value): ?string {
    if ($value==='') return null;
    require_credential_encryption();
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($value,'aes-256-gcm',credential_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if ($cipher===false) throw new CredentialStorageException('Hosting encryption error: password encryption failed. Check the OpenSSL configuration.');
    return base64_encode($iv.$tag.$cipher);
}
function credential_decrypt(?string $value): string {
    if (!$value) return '';
    $data=base64_decode($value,true);
    if ($data===false || strlen($data)<29) throw new RuntimeException('Invalid encrypted credential.');
    $plain=openssl_decrypt(substr($data,28),'aes-256-gcm',credential_key(),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16));
    if ($plain===false) throw new RuntimeException('Credential key does not match. Restore the original key.');
    return $plain;
}
function project_age(?string $date): string {
    if (!$date) return 'Starting date not set';
    $start=new DateTimeImmutable($date); $now=new DateTimeImmutable('today');
    if ($start>$now) return 'Not started yet';
    return $start->diff($now)->format('%y years, %m months, %d days');
}
