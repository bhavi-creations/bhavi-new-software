<?php
// Authentication uses hashes. Recoverable credentials use authenticated encryption.
function credential_key(): string {
    $configured=getenv('BHAVI_CREDENTIAL_KEY');
    if ($configured!==false && $configured!=='') {
        $key=base64_decode($configured,true);
        if ($key===false || strlen($key)!==32) throw new RuntimeException('BHAVI_CREDENTIAL_KEY must be a base64-encoded 32-byte key.');
        return $key;
    }
    $directory=dirname(__DIR__).'/storage';
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cannot create credential storage.');
    $handle=fopen($directory.'/credential-key.php','c+');
    if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Cannot access credential key.');
    try {
        $data=stream_get_contents($handle);
        if ($data==='') {
            $data="<?php exit; ?>\n".base64_encode(random_bytes(32));
            if (fwrite($handle,$data)!==strlen($data)) throw new RuntimeException('Cannot save credential key.');
            fflush($handle); chmod($directory.'/credential-key.php',0600);
        }
        $key=base64_decode(explode("\n",$data,2)[1]??'',true);
        if ($key===false || strlen($key)!==32) throw new RuntimeException('Invalid credential key.');
        return $key;
    } finally { flock($handle,LOCK_UN); fclose($handle); }
}
function credential_encrypt(string $value): ?string {
    if ($value==='') return null;
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($value,'aes-256-gcm',credential_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if ($cipher===false) throw new RuntimeException('Cannot encrypt credential.');
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
