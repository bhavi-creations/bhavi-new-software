<?php
// Keep only hashed validators in the database. Each browser has its own token.
const PORTAL_LOGIN_COOKIE = 'bhavi_remember';
const PORTAL_COOKIE_LIFETIME = 34560000; // Renew the browser's 400-day cookie on use.

function login_cookie_parts(): ?array
{
    $cookie=$_COOKIE[PORTAL_LOGIN_COOKIE]??'';
    return is_string($cookie) && preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D',$cookie,$match)?[$match[1],$match[2]]:null;
}
function login_cookie(string $value): void
{
    $params=session_get_cookie_params();
    setcookie(PORTAL_LOGIN_COOKIE,$value,['expires'=>$value===''?time()-42000:time()+PORTAL_COOKIE_LIFETIME,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Lax']);
    if ($value==='') unset($_COOKIE[PORTAL_LOGIN_COOKIE]); else $_COOKIE[PORTAL_LOGIN_COOKIE]=$value;
}
function revoke_browser_login(): void
{
    $parts=login_cookie_parts();
    if ($parts) query('DELETE FROM portal_login_tokens WHERE selector=? AND validator_hash=?',[$parts[0],hash('sha256',$parts[1])]);
    if (!empty($_SESSION['auth_selector']) && !empty($_SESSION['user_id'])) query('DELETE FROM portal_login_tokens WHERE selector=? AND user_id=?',[$_SESSION['auth_selector'],$_SESSION['user_id']]);
    login_cookie('');
}
function begin_browser_login(array $user): void
{
    revoke_browser_login();
    $selector=bin2hex(random_bytes(16));
    $validator=bin2hex(random_bytes(32));
    $fingerprint=hash('sha256',$user['password_hash']);
    query('INSERT INTO portal_login_tokens (selector,user_id,validator_hash,password_fingerprint) VALUES (?,?,?,?)',[$selector,$user['id'],hash('sha256',$validator),$fingerprint]);
    $_SESSION['user_id']=$user['id'];
    $_SESSION['auth_selector']=$selector;
    $_SESSION['auth_fingerprint']=$fingerprint;
    $_SESSION['csrf']=hash_hmac('sha256','bhavi_portal_csrf',$validator);
    login_cookie($selector.'.'.$validator);
}
function restore_browser_login(): void
{
    $parts=login_cookie_parts();
    if (!$parts) return;
    $token=one('SELECT t.*,u.password_hash,u.account_status,u.deleted_at FROM portal_login_tokens t JOIN users u ON u.id=t.user_id WHERE t.selector=?',[$parts[0]]);
    if (!$token || !hash_equals($token['validator_hash'],hash('sha256',$parts[1])) || !hash_equals($token['password_fingerprint'],hash('sha256',$token['password_hash'])) || $token['account_status']!=='active' || $token['deleted_at']!==null) {
        revoke_browser_login();
        return;
    }
    session_regenerate_id(true);
    $_SESSION['user_id']=$token['user_id'];
    $_SESSION['auth_selector']=$token['selector'];
    $_SESSION['auth_fingerprint']=$token['password_fingerprint'];
    $_SESSION['csrf']=hash_hmac('sha256','bhavi_portal_csrf',$parts[1]);
}
function maintain_browser_login(array $user): bool
{
    if (!isset($_SESSION['auth_selector'])) {
        // Existing signed-in users acquire persistence without signing in again.
        begin_browser_login($user);
    } else {
        $fingerprint=hash('sha256',$user['password_hash']);
        $token=one('SELECT validator_hash,password_fingerprint FROM portal_login_tokens WHERE selector=? AND user_id=?',[$_SESSION['auth_selector'],$user['id']]);
        if (!$token || !hash_equals($fingerprint,$_SESSION['auth_fingerprint']??'') || !hash_equals($fingerprint,$token['password_fingerprint'])) return false;
        $parts=login_cookie_parts();
        if (!$parts || $parts[0]!==$_SESSION['auth_selector'] || !hash_equals($token['validator_hash'],hash('sha256',$parts[1]))) begin_browser_login($user);
        else login_cookie($parts[0].'.'.$parts[1]);
    }
    $params=session_get_cookie_params();
    setcookie(session_name(),session_id(),['expires'=>time()+PORTAL_COOKIE_LIFETIME,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Lax']);
    return true;
}
