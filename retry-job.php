<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/path_helper.php';

use S3Sync\Auth;
use S3Sync\Database;

$auth = new Auth();
$auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    appRedirect('jobs.php');
}

$jobId = $_POST['job_id'] ?? 0;
$db = (new Database())->getConnection();

// Get original job details
$stmt = $db->prepare("SELECT * FROM sync_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$originalJob = $stmt->fetch(PDO::FETCH_ASSOC);

if ($originalJob) {
    // Create new job with same settings
    $stmt = $db->prepare("INSERT INTO sync_jobs (name, local_path, s3_path, s3_settings_id, overwrite_files, status) 
                          VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([
        $originalJob['name'] . ' (Retry)',
        $originalJob['local_path'],
        $originalJob['s3_path'],
        $originalJob['s3_settings_id'],
        $originalJob['overwrite_files'] ?? 1
    ]);
    
    $newJobId = $db->lastInsertId();
    
    // Execute sync in background
    $workerPath = __DIR__ . '/worker.php';
    $logPath = __DIR__ . '/data/logs/worker_' . $newJobId . '.log';
    
    // Ensure log directory exists
    $logDir = dirname($logPath);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Execute worker with error logging using default php command
    $command = sprintf('php %s %d >> %s 2>&1 &', 
        escapeshellarg($workerPath), 
        $newJobId,
        escapeshellarg($logPath)
    );
    exec($command);
    
    appRedirect('job-details.php?id=' . $newJobId);
} else {
    appRedirect('jobs.php');
}