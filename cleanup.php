<?php
/**
 * S3 Sync Cleanup Script
 * Run this script periodically to maintain the application
 */

require_once __DIR__ . '/vendor/autoload.php';

use S3Sync\JobController;
use S3Sync\Database;

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

$config = require __DIR__ . '/config/config.php';
echo "[" . date('Y-m-d H:i:s') . "] Starting S3 Sync cleanup...\n";

// Initialize components
$db = (new Database())->getConnection();
$jobController = new JobController();

// 1. Clean up stale jobs
echo "Cleaning up stale jobs...\n";
$jobController->cleanupStaleJobs();

// 2. Clean old logs
$retentionDays = $config['log_retention_days'] ?? 30;
echo "Cleaning logs older than {$retentionDays} days...\n";

$stmt = $db->prepare("DELETE FROM job_logs WHERE created_at < date('now', '-{$retentionDays} days')");
$deletedLogs = $stmt->execute();

$stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < date('now', '-{$retentionDays} days')");
$deletedAttempts = $stmt->execute();

// 3. Optimize database
echo "Optimizing database...\n";
$db->exec("VACUUM");
$db->exec("ANALYZE");

// 4. Clean log files
$logsPath = $config['logs_path'] ?? __DIR__ . '/data/logs/';
if (is_dir($logsPath)) {
    $logFiles = glob($logsPath . '*.log');
    foreach ($logFiles as $logFile) {
        $fileAge = time() - filemtime($logFile);
        $maxAge = $retentionDays * 24 * 3600; // Convert days to seconds
        
        if ($fileAge > $maxAge) {
            unlink($logFile);
            echo "Deleted old log file: " . basename($logFile) . "\n";
        }
    }
}

// 5. Check disk usage
$dataPath = __DIR__ . '/data/';
$freeSpace = disk_free_space($dataPath);
$totalSpace = disk_total_space($dataPath);
$usedPercent = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);

echo "Disk usage: {$usedPercent}%\n";

if ($usedPercent > 90) {
    echo "WARNING: Disk usage is high ({$usedPercent}%)\n";
    // Log to system
    error_log("S3 Sync: High disk usage detected ({$usedPercent}%)");
}

// 6. Check for failed jobs needing attention
$stmt = $db->query("SELECT COUNT(*) FROM sync_jobs WHERE status = 'failed' AND created_at > date('now', '-1 day')");
$recentFailures = $stmt->fetchColumn();

if ($recentFailures > 5) {
    echo "WARNING: {$recentFailures} jobs failed in the last 24 hours\n";
    error_log("S3 Sync: High failure rate detected ({$recentFailures} failed jobs in 24h)");
}

// 7. Performance statistics
$stmt = $db->query("SELECT 
    COUNT(*) as total_jobs,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
    SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running
    FROM sync_jobs 
    WHERE created_at > date('now', '-7 days')");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Last 7 days statistics:\n";
echo "- Total jobs: {$stats['total_jobs']}\n";
echo "- Completed: {$stats['completed']}\n";
echo "- Failed: {$stats['failed']}\n";
echo "- Currently running: {$stats['running']}\n";

$successRate = $stats['total_jobs'] > 0 ? round(($stats['completed'] / $stats['total_jobs']) * 100, 2) : 0;
echo "- Success rate: {$successRate}%\n";

echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed successfully\n";

// Output cleanup summary
$summary = [
    'timestamp' => date('Y-m-d H:i:s'),
    'disk_usage_percent' => $usedPercent,
    'recent_failures' => $recentFailures,
    'success_rate' => $successRate,
    'total_jobs_7d' => $stats['total_jobs'],
    'running_jobs' => $stats['running']
];

file_put_contents($logsPath . 'cleanup.log', json_encode($summary) . "\n", FILE_APPEND | LOCK_EX);