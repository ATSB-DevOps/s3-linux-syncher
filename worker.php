<?php
require_once __DIR__ . '/vendor/autoload.php';

use S3Sync\Database;
use S3Sync\S3Manager;
use S3Sync\Logger;

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

if ($argc < 2) {
    die("Usage: php worker.php <job_id>\n");
}

$jobId = $argv[1];

$db = (new Database())->getConnection();
$s3Manager = new S3Manager();
$logger = new Logger();

// Get job details
$stmt = $db->prepare("SELECT * FROM sync_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    die("Job not found\n");
}

// Get all paths for this job
$stmt = $db->prepare("SELECT local_path, path_type FROM job_paths WHERE job_id = ?");
$stmt->execute([$jobId]);
$jobPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no specific paths found, fall back to single path (backward compatibility)
if (empty($jobPaths)) {
    $jobPaths = [[
        'local_path' => $job['local_path'],
        'path_type' => is_file($job['local_path']) ? 'file' : 'directory'
    ]];
}

// Get S3 settings
$settings = $s3Manager->getSettings($job['s3_settings_id']);
if (!$settings) {
    die("S3 settings not found\n");
}

// Store process ID and update job status to running
$processId = getmypid();
$stmt = $db->prepare("UPDATE sync_jobs SET status = 'running', started_at = CURRENT_TIMESTAMP, process_id = ? WHERE id = ?");
$stmt->execute([$processId, $jobId]);

$logger->logJobEvent($jobId, 'started', [
    'process_id' => $processId,
    'job_name' => $job['name'],
    'paths_count' => count($paths)
]);

// Set up signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    
    $signalHandler = function($signal) use ($db, $jobId, $logger) {
        $logger->logJobEvent($jobId, 'stopped_by_signal', ['signal' => $signal]);
        $stmt = $db->prepare("UPDATE sync_jobs SET status = 'stopped', completed_at = CURRENT_TIMESTAMP, error_message = 'Job stopped by signal' WHERE id = ?");
        $stmt->execute([$jobId]);
        exit(0);
    };
    
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
}

try {
    // Create S3 client
    $s3Client = $s3Manager->createS3Client($settings);
    
    $s3BasePath = rtrim($job['s3_path'], '/');
    $bucket = $settings['bucket'];
    $overwriteFiles = $job['overwrite_files'] ?? 1;
    
    $filesSucceeded = 0;
    $filesFailed = 0;
    $filesSkipped = 0;
    
    // Function to sync a single file
    $syncFile = function($filePath, $s3Key) use ($s3Manager, $s3Client, $bucket, $db, $jobId, $overwriteFiles, &$filesSucceeded, &$filesFailed, &$filesSkipped) {
        // Check if file exists in S3 if overwrite is disabled
        if (!$overwriteFiles) {
            try {
                $s3Client->headObject([
                    'Bucket' => $bucket,
                    'Key' => $s3Key
                ]);
                
                // File exists and overwrite is disabled, skip it
                $stmt = $db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $jobId,
                    $filePath,
                    'skipped',
                    'File already exists in S3 and overwrite is disabled'
                ]);
                
                $filesSkipped++;
                return true;
                
            } catch (\Exception $e) {
                // File doesn't exist, proceed with upload
            }
        }
        
        $result = $s3Manager->syncFile($s3Client, $filePath, $s3Key, $bucket);
        
        $stmt = $db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $jobId,
            $filePath,
            $result['success'] ? 'success' : 'failed',
            $result['message']
        ]);
        
        if ($result['success']) {
            $filesSucceeded++;
        } else {
            $filesFailed++;
        }
        
        return $result['success'];
    };
    
    // Process each selected path
    foreach ($jobPaths as $pathInfo) {
        $localPath = $pathInfo['local_path'];
        $pathType = $pathInfo['path_type'];
        
        if (!file_exists($localPath)) {
            $stmt = $db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$jobId, $localPath, 'failed', 'Path not found: ' . $localPath]);
            $filesFailed++;
            continue;
        }
        
        if ($pathType === 'file' || is_file($localPath)) {
            // Single file sync
            $fileName = basename($localPath);
            $s3Key = $s3BasePath ? $s3BasePath . '/' . $fileName : $fileName;
            $syncFile($localPath, $s3Key);
            
        } elseif ($pathType === 'directory' || is_dir($localPath)) {
            // Directory sync - preserve directory structure
            $baseName = basename($localPath);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($localPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativePath = str_replace($localPath . '/', '', $file->getPathname());
                    $relativePath = str_replace($localPath, '', $relativePath);
                    $relativePath = ltrim($relativePath, '/');
                    
                    // Include directory name in S3 path for better organization
                    $s3Key = $s3BasePath ? $s3BasePath . '/' . $baseName . '/' . $relativePath : $baseName . '/' . $relativePath;
                    $syncFile($file->getPathname(), $s3Key);
                }
            }
        }
    }
    
    // Update job status to completed
    $stmt = $db->prepare("UPDATE sync_jobs SET status = 'completed', completed_at = CURRENT_TIMESTAMP, 
                          files_synced = ?, files_failed = ?, files_skipped = ? WHERE id = ?");
    $stmt->execute([$filesSucceeded, $filesFailed, $filesSkipped, $jobId]);
    
    echo "Job completed. Files synced: $filesSucceeded, Failed: $filesFailed, Skipped: $filesSkipped\n";
    
} catch (Exception $e) {
    // Update job status to failed
    $stmt = $db->prepare("UPDATE sync_jobs SET status = 'failed', completed_at = CURRENT_TIMESTAMP, 
                          error_message = ? WHERE id = ?");
    $stmt->execute([$e->getMessage(), $jobId]);
    
    echo "Job failed: " . $e->getMessage() . "\n";
    exit(1);
}