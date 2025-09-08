<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/path_helper.php';

use S3Sync\Auth;

$auth = new Auth();
$auth->requireAuth();

$currentUser = $auth->getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Sync Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .navbar { background: #343a40; padding: 1rem; color: white; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: white; text-decoration: none; margin: 0 1rem; padding: 0.5rem; border-radius: 4px; }
        .navbar a:hover, .navbar a.active { background: rgba(255,255,255,0.1); }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 1rem; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background: #f8f9fa; font-weight: bold; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.25rem; font-weight: bold; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        .form-radio, .form-checkbox { display: flex; align-items: center; margin-bottom: 0.5rem; }
        .form-radio input, .form-checkbox input { width: auto; margin-right: 0.5rem; margin-bottom: 0; }
        .form-radio label, .form-checkbox label { margin-bottom: 0; display: flex; align-items: center; cursor: pointer; }
        .form-group .radio-group, .form-group .checkbox-group { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        .table-checkbox { width: auto !important; margin: 0; }
        .alert { padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .status-badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem; }
        .status-pending { background: #ffc107; color: #000; }
        .status-running { background: #17a2b8; color: white; }
        .status-completed { background: #28a745; color: white; }
        .status-failed { background: #dc3545; color: white; }
        td:last-child { white-space: nowrap; min-width: 200px; }
        .btn { margin-right: 5px; }
        .btn:last-child { margin-right: 0; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div>
            <span style="font-size: 1.25rem; font-weight: bold;">S3 Sync Manager</span>
            <a href="<?= appUrl('index.php') ?>" class="<?= $currentPage === 'index' ? 'active' : '' ?>">Dashboard</a>
            <a href="<?= appUrl('sync.php') ?>" class="<?= $currentPage === 'sync' ? 'active' : '' ?>">New Sync</a>
            <a href="<?= appUrl('jobs.php') ?>" class="<?= $currentPage === 'jobs' ? 'active' : '' ?>">Jobs</a>
            <a href="<?= appUrl('scheduled.php') ?>" class="<?= $currentPage === 'scheduled' ? 'active' : '' ?>">Scheduled</a>
            <a href="<?= appUrl('settings.php') ?>" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">S3 Settings</a>
        </div>
        <div>
            <span>Welcome, <?= htmlspecialchars($currentUser['username']) ?></span>
            <a href="<?= appUrl('change-password.php') ?>" class="<?= $currentPage === 'change-password' ? 'active' : '' ?>">Change Password</a>
            <a href="<?= appUrl('logout.php') ?>">Logout</a>
        </div>
    </nav>
    <div class="container">