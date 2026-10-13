<?php
require_once __DIR__.'/includes/client-portal.php';
$own=own_client();
$_GET['id']=$own['id'];
require __DIR__.'/client-profile.php';
