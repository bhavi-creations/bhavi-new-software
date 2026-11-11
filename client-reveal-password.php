<?php
// Shows one saved social-account password to the signed-in client after they confirm their own login password.
require_once __DIR__ . '/includes/bootstrap.php';

$user=require_roles(['client']);
check_csrf();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply=static function (int $status, array $body): never { http_response_code($status); echo json_encode($body); exit; };

$client=one('SELECT id FROM clients WHERE user_id=? AND deleted_at IS NULL AND is_active=1',[$user['id']]);
if (!$client) $reply(403,['error'=>'Your client account is unavailable.']);
$linkId=filter_var($_POST['link']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$link=$linkId?one('SELECT password_cipher FROM client_social_links WHERE id=? AND client_id=?',[$linkId,$client['id']]):null;
if (!$link) $reply(404,['error'=>'Account not found.']);

// After one correct password the client can view passwords for 5 minutes without typing it again.
$unlocked=(int)($_SESSION['client_reveal_until']??0)>time();
if (!$unlocked) {
    $attempts=array_values(array_filter($_SESSION['client_reveal_fails']??[],static fn($t)=>$t>time()-600));
    if (count($attempts)>=5) $reply(429,['error'=>'Too many wrong tries. Please wait 10 minutes and try again.']);
    $password=is_string($_POST['password']??null)?$_POST['password']:'';
    if ($password==='') $reply(401,['error'=>'Enter your login password.','needPassword'=>true]);
    $hash=(string)(one('SELECT password_hash FROM users WHERE id=?',[$user['id']])['password_hash']??'');
    if (!password_verify($password,$hash)) {
        $attempts[]=time(); $_SESSION['client_reveal_fails']=$attempts;
        $reply(401,['error'=>'That password is not correct.','needPassword'=>true]);
    }
    $_SESSION['client_reveal_fails']=[];
    $_SESSION['client_reveal_until']=time()+300;
}
$plain=credential_decrypt($link['password_cipher']??null);
$reply(200,['password'=>$plain!==''?$plain:'Not set']);
