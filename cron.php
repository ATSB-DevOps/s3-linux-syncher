<?php
require_once __DIR__ . '/vendor/autoload.php';

use S3Sync\Database;

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

$db = (new Database())->getConnection();

// Get current time
$now = new DateTime();

// Get all active scheduled jobs
$stmt = $db->query("SELECT * FROM scheduled_jobs WHERE is_active = 1");
$scheduledJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($scheduledJobs as $job) {
    // Check if job should run based on cron expression
    if (shouldRunJob($job['cron_expression'], $job['last_run'])) {
        echo "[" . $now->format('Y-m-d H:i:s') . "] Running scheduled job: " . $job['name'] . "\n";
        
        // Create a new sync job
        $stmt = $db->prepare("INSERT INTO sync_jobs (name, local_path, s3_path, s3_settings_id, overwrite_files, status) 
                              VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([
            $job['name'] . ' (Scheduled)',
            $job['local_path'],
            $job['s3_path'],
            $job['s3_settings_id'],
            $job['overwrite_files']
        ]);
        
        $jobId = $db->lastInsertId();
        
        // Copy scheduled job paths to sync job paths
        $stmt = $db->prepare("SELECT local_path, path_type FROM scheduled_job_paths WHERE scheduled_job_id = ?");
        $stmt->execute([$job['id']]);
        $scheduledPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no specific paths, create one with the main path (backward compatibility)
        if (empty($scheduledPaths)) {
            $stmt = $db->prepare("INSERT INTO job_paths (job_id, local_path, path_type) VALUES (?, ?, ?)");
            $stmt->execute([
                $jobId,
                $job['local_path'],
                is_file($job['local_path']) ? 'file' : 'directory'
            ]);
        } else {
            // Copy all paths
            foreach ($scheduledPaths as $pathInfo) {
                $stmt = $db->prepare("INSERT INTO job_paths (job_id, local_path, path_type) VALUES (?, ?, ?)");
                $stmt->execute([$jobId, $pathInfo['local_path'], $pathInfo['path_type']]);
            }
        }
        
        // Update last run time
        $stmt = $db->prepare("UPDATE scheduled_jobs SET last_run = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$job['id']]);
        
        // Execute sync in background
        $workerPath = __DIR__ . '/worker.php';
        $logPath = __DIR__ . '/data/logs/worker_' . $jobId . '.log';
        
        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Execute worker with error logging using default php command
        $command = sprintf('php %s %d >> %s 2>&1 &', 
            escapeshellarg($workerPath), 
            $jobId,
            escapeshellarg($logPath)
        );
        exec($command);
        
        echo "[" . $now->format('Y-m-d H:i:s') . "] Started job ID: $jobId\n";
    }
}

/**
 * Simple cron expression parser
 * Supports: minute hour day month weekday
 */
function shouldRunJob($cronExpression, $lastRun) {
    $now = new DateTime();
    $parts = explode(' ', $cronExpression);
    
    if (count($parts) !== 5) {
        return false;
    }
    
    list($minute, $hour, $day, $month, $weekday) = $parts;
    
    // Check each cron field
    if (!matchCronField($minute, (int)$now->format('i'))) return false;
    if (!matchCronField($hour, (int)$now->format('G'))) return false;
    if (!matchCronField($day, (int)$now->format('j'))) return false;
    if (!matchCronField($month, (int)$now->format('n'))) return false;
    if (!matchCronField($weekday, (int)$now->format('w'))) return false;
    
    // Check if enough time has passed since last run (at least 1 minute)
    if ($lastRun) {
        $lastRunTime = new DateTime($lastRun);
        $diff = $now->getTimestamp() - $lastRunTime->getTimestamp();
        if ($diff < 60) {
            return false;
        }
    }
    
    return true;
}

function matchCronField($field, $value) {
    // Wildcard
    if ($field === '*') {
        return true;
    }
    
    // Step values (*/5)
    if (strpos($field, '*/') === 0) {
        $step = (int)substr($field, 2);
        return $value % $step === 0;
    }
    
    // Range (1-5)
    if (strpos($field, '-') !== false) {
        list($start, $end) = explode('-', $field);
        return $value >= (int)$start && $value <= (int)$end;
    }
    
    // List (1,3,5)
    if (strpos($field, ',') !== false) {
        $values = explode(',', $field);
        return in_array((string)$value, $values);
    }
    
    // Exact match
    return (int)$field === $value;
}