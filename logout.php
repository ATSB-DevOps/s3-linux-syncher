<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/path_helper.php';

use S3Sync\Auth;

$auth = new Auth();
$auth->logout();

appRedirect('login.php');