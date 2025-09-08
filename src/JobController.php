<?php
namespace S3Sync;

class JobController {
    private $db;
    private $database;
    private $config;
    private $logger;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->database = new Database();
        $this->db = $this->database->getConnection();
        $this->logger = new Logger();
    }
    
    public function stopJob($jobId) {
        // Get job details
        $stmt = $this->db->prepare("SELECT * FROM sync_jobs WHERE id = ? AND status = 'running'");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$job || !$job['can_be_stopped']) {
            return ['success' => false, 'message' => 'Job cannot be stopped or not found'];
        }
        
        $processId = $job['process_id'];
        
        if ($processId && $this->isProcessRunning($processId)) {
            $this->logger->logJobEvent($jobId, 'stopping_process', ['process_id' => $processId]);
            
            // Send SIGTERM to gracefully stop the process
            if (function_exists('posix_kill')) {
                $killed = posix_kill($processId, SIGTERM);
                
                // If SIGTERM doesn't work, try SIGKILL after a moment
                if ($killed) {
                    sleep(2);
                    if ($this->isProcessRunning($processId)) {
                        $this->logger->warning("Process $processId still running after SIGTERM, sending SIGKILL");
                        posix_kill($processId, SIGKILL);
                        sleep(1);
                        
                        // Final check and force kill if still running
                        if ($this->isProcessRunning($processId)) {
                            $this->logger->error("Process $processId still running after SIGKILL, using system kill");
                            exec("kill -9 $processId 2>/dev/null");
                        }
                    }
                }
            } else {
                // Fallback for systems without POSIX functions
                exec("kill -TERM $processId 2>/dev/null");
                sleep(2);
                if ($this->isProcessRunning($processId)) {
                    exec("kill -KILL $processId 2>/dev/null");
                    sleep(1);
                    // Force kill as last resort
                    if ($this->isProcessRunning($processId)) {
                        exec("kill -9 $processId 2>/dev/null");
                    }
                }
            }
            
            // Final verification
            if ($this->isProcessRunning($processId)) {
                $this->logger->error("Failed to kill process $processId after all attempts");
                return ['success' => false, 'message' => 'Failed to stop job process'];
            } else {
                $this->logger->logJobEvent($jobId, 'process_killed', ['process_id' => $processId]);
            }
        }
        
        // Update job status
        $this->database->executeWithRetry(function() use ($jobId) {
            $stmt = $this->db->prepare("UPDATE sync_jobs SET status = 'stopped', completed_at = CURRENT_TIMESTAMP, error_message = 'Job stopped by user' WHERE id = ?");
            $stmt->execute([$jobId]);
            
            // Log the action
            $stmt = $this->db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$jobId, '', 'stopped', 'Job stopped by user request']);
        });
        
        return ['success' => true, 'message' => 'Job stopped successfully'];
    }
    
    public function pauseJob($jobId) {
        $stmt = $this->db->prepare("SELECT * FROM sync_jobs WHERE id = ? AND status = 'running'");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['success' => false, 'message' => 'Job not found or not running'];
        }
        
        $processId = $job['process_id'];
        
        if ($processId && $this->isProcessRunning($processId)) {
            // Send SIGSTOP to pause the process
            if (function_exists('posix_kill')) {
                posix_kill($processId, SIGSTOP);
            } else {
                exec("kill -STOP $processId 2>/dev/null");
            }
        }
        
        $stmt = $this->db->prepare("UPDATE sync_jobs SET status = 'paused', paused_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$jobId]);
        
        $stmt = $this->db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$jobId, '', 'paused', 'Job paused by user request']);
        
        return ['success' => true, 'message' => 'Job paused successfully'];
    }
    
    public function resumeJob($jobId) {
        $stmt = $this->db->prepare("SELECT * FROM sync_jobs WHERE id = ? AND status = 'paused'");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['success' => false, 'message' => 'Job not found or not paused'];
        }
        
        $processId = $job['process_id'];
        
        if ($processId && $this->isProcessRunning($processId)) {
            // Send SIGCONT to resume the process
            if (function_exists('posix_kill')) {
                posix_kill($processId, SIGCONT);
            } else {
                exec("kill -CONT $processId 2>/dev/null");
            }
        }
        
        $stmt = $this->db->prepare("UPDATE sync_jobs SET status = 'running', resumed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$jobId]);
        
        $stmt = $this->db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$jobId, '', 'resumed', 'Job resumed by user request']);
        
        return ['success' => true, 'message' => 'Job resumed successfully'];
    }
    
    public function restartJob($jobId) {
        // Get original job details
        $stmt = $this->db->prepare("SELECT * FROM sync_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $originalJob = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$originalJob) {
            return ['success' => false, 'message' => 'Job not found'];
        }
        
        // Stop current job if running
        if ($originalJob['status'] === 'running' || $originalJob['status'] === 'paused') {
            $this->stopJob($jobId);
        }
        
        // Create new job with same settings
        $stmt = $this->db->prepare("INSERT INTO sync_jobs (name, local_path, s3_path, s3_settings_id, overwrite_files, status) 
                                      VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([
            $originalJob['name'] . ' (Restarted)',
            $originalJob['local_path'],
            $originalJob['s3_path'],
            $originalJob['s3_settings_id'],
            $originalJob['overwrite_files'] ?? 1
        ]);
        
        $newJobId = $db->lastInsertId();
        
        // Copy all paths
        $stmt = $this->db->prepare("SELECT local_path, path_type FROM job_paths WHERE job_id = ?");
        $stmt->execute([$jobId]);
        $jobPaths = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($jobPaths as $pathInfo) {
            $stmt = $this->db->prepare("INSERT INTO job_paths (job_id, local_path, path_type) VALUES (?, ?, ?)");
            $stmt->execute([$newJobId, $pathInfo['local_path'], $pathInfo['path_type']]);
        }
        
        // Execute sync in background
        $phpPath = PHP_BINARY;
        $workerPath = __DIR__ . '/../worker.php';
        exec("$phpPath $workerPath $newJobId > /dev/null 2>&1 &");
        
        return ['success' => true, 'message' => 'Job restarted successfully', 'new_job_id' => $newJobId];
    }
    
    public function getJobStatus($jobId) {
        $stmt = $this->db->prepare("SELECT status, process_id FROM sync_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['success' => false, 'message' => 'Job not found'];
        }
        
        $status = $job['status'];
        $processId = $job['process_id'];
        
        // Check if process is actually running
        if (($status === 'running' || $status === 'paused') && $processId) {
            if (!$this->isProcessRunning($processId)) {
                // Process died, update status
                $stmt = $this->db->prepare("UPDATE sync_jobs SET status = 'failed', completed_at = CURRENT_TIMESTAMP, error_message = 'Process died unexpectedly' WHERE id = ?");
                $stmt->execute([$jobId]);
                $status = 'failed';
            }
        }
        
        return ['success' => true, 'status' => $status];
    }
    
    private function isProcessRunning($pid) {
        if (!$pid) return false;
        
        if (function_exists('posix_kill')) {
            // posix_kill with signal 0 checks if process exists
            return posix_kill($pid, 0);
        } else {
            // Fallback method - check if process exists
            exec("ps -p $pid -o pid= 2>/dev/null", $output, $returnCode);
            return $returnCode === 0 && !empty($output);
        }
    }
    
    public function cleanupStaleJobs() {
        // Find jobs marked as running but process is not actually running
        $stmt = $this->db->query("SELECT id, process_id FROM sync_jobs WHERE status IN ('running', 'paused') AND process_id IS NOT NULL");
        $runningJobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($runningJobs as $job) {
            if (!$this->isProcessRunning($job['process_id'])) {
                $stmt = $this->db->prepare("UPDATE sync_jobs SET status = 'failed', completed_at = CURRENT_TIMESTAMP, error_message = 'Process died unexpectedly' WHERE id = ?");
                $stmt->execute([$job['id']]);
                
                $stmt = $this->db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
                $stmt->execute([$job['id'], '', 'failed', 'Process died unexpectedly - cleaned up by system']);
            }
        }
    }
    
    public function deleteJob($jobId) {
        try {
            // Get job details first
            $stmt = $this->db->prepare("SELECT * FROM sync_jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$job) {
                return ['success' => false, 'message' => 'Job not found'];
            }
            
            // Cannot delete running jobs
            if ($job['status'] === 'running' || $job['status'] === 'paused') {
                return ['success' => false, 'message' => 'Cannot delete running or paused job. Stop it first.'];
            }
            
            // Delete job with transaction and retry logic
            $this->database->executeWithRetry(function() use ($jobId) {
                $this->db->beginTransaction();
                
                // Delete job logs
                $stmt = $this->db->prepare("DELETE FROM job_logs WHERE job_id = ?");
                $stmt->execute([$jobId]);
                
                // Delete job paths
                $stmt = $this->db->prepare("DELETE FROM job_paths WHERE job_id = ?");
                $stmt->execute([$jobId]);
                
                // Delete the job itself
                $stmt = $this->db->prepare("DELETE FROM sync_jobs WHERE id = ?");
                $stmt->execute([$jobId]);
                
                $this->db->commit();
            });
            
            $this->logger->logJobEvent($jobId, 'deleted', [
                'job_name' => $job['name'],
                'job_status' => $job['status']
            ]);
            
            return ['success' => true, 'message' => 'Job deleted successfully'];
            
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->logException($e, ['operation' => 'delete_job', 'job_id' => $jobId]);
            return ['success' => false, 'message' => 'Error deleting job: ' . $e->getMessage()];
        }
    }
    
    public function deleteMultipleJobs($jobIds) {
        $results = [];
        $deleted = 0;
        
        foreach ($jobIds as $jobId) {
            $result = $this->deleteJob($jobId);
            $results[] = $result;
            if ($result['success']) {
                $deleted++;
            }
        }
        
        $this->logger->info("Bulk job deletion completed", [
            'requested' => count($jobIds),
            'deleted' => $deleted,
            'failed' => count($jobIds) - $deleted
        ]);
        
        return [
            'success' => $deleted > 0,
            'message' => "Deleted {$deleted} of " . count($jobIds) . " jobs",
            'results' => $results
        ];
    }
    
    public function killAllJobs() {
        $this->logger->warning("Killing all running jobs - emergency stop requested");
        
        // Get all running/paused jobs
        $stmt = $this->db->query("SELECT id, process_id, name FROM sync_jobs WHERE status IN ('running', 'paused') AND process_id IS NOT NULL");
        $runningJobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $killed = 0;
        $failed = 0;
        
        foreach ($runningJobs as $job) {
            $processId = $job['process_id'];
            
            if ($this->isProcessRunning($processId)) {
                $this->logger->info("Force killing job {$job['id']} (PID: $processId)");
                
                // Aggressive kill sequence
                if (function_exists('posix_kill')) {
                    // Try SIGKILL first for immediate termination
                    posix_kill($processId, SIGKILL);
                    usleep(500000); // 0.5 seconds
                    
                    if ($this->isProcessRunning($processId)) {
                        // If still running, use system kill -9
                        exec("kill -9 $processId 2>/dev/null");
                    }
                } else {
                    // Use system kill commands
                    exec("kill -9 $processId 2>/dev/null");
                }
                
                // Verify process is dead
                usleep(500000); // 0.5 seconds
                if (!$this->isProcessRunning($processId)) {
                    $killed++;
                } else {
                    $failed++;
                    $this->logger->error("Failed to kill process $processId for job {$job['id']}");
                }
            }
            
            // Update job status regardless
            $stmt = $this->db->prepare("UPDATE sync_jobs SET status = 'stopped', completed_at = CURRENT_TIMESTAMP, error_message = 'Emergency stop - all jobs killed' WHERE id = ?");
            $stmt->execute([$job['id']]);
            
            // Log the action
            $stmt = $this->db->prepare("INSERT INTO job_logs (job_id, file_path, status, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$job['id'], '', 'stopped', 'Job force-killed by emergency stop']);
        }
        
        // Also kill any orphaned worker processes
        exec("pkill -f 'worker.php' 2>/dev/null");
        
        $this->logger->warning("Emergency stop completed", [
            'total_jobs' => count($runningJobs),
            'killed' => $killed,
            'failed' => $failed
        ]);
        
        return [
            'success' => true,
            'message' => "Killed $killed processes, failed to kill $failed",
            'total_jobs' => count($runningJobs),
            'killed' => $killed,
            'failed' => $failed
        ];
    }
}