<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (!count_value('SELECT COUNT(*) FROM users')) { redirect('setup.php'); }
$user = current_user();
redirect($user ? dashboard_path($user) : 'login.php');
