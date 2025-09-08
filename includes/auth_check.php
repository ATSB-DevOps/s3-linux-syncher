<?php
require_once __DIR__ . '/../vendor/autoload.php';

use S3Sync\Auth;

$auth = new Auth();
$auth->requireAuth();