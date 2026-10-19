<?php
require_once __DIR__ . '/includes/bootstrap.php';
check_csrf();
revoke_browser_login();
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time()-42000, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
redirect('login.php');
