<?php
namespace S3Sync;

class Logger {
    const ERROR = 0;
    const WARNING = 1;
    const INFO = 2;
    const DEBUG = 3;
    
    private $config;
    private $logPath;
    private $logLevel;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->logPath = $this->config['logs_path'] ?? __DIR__ . '/../data/logs/';
        $this->logLevel = $this->getLogLevel($this->config['log_level'] ?? 'info');
        
        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }
    
    private function getLogLevel($level) {
        switch (strtolower($level)) {
            case 'error': return self::ERROR;
            case 'warning': return self::WARNING;
            case 'info': return self::INFO;
            case 'debug': return self::DEBUG;
            default: return self::INFO;
        }
    }
    
    private function shouldLog($level) {
        return $level <= $this->logLevel;
    }
    
    private function getLevelName($level) {
        switch ($level) {
            case self::ERROR: return 'ERROR';
            case self::WARNING: return 'WARNING';
            case self::INFO: return 'INFO';
            case self::DEBUG: return 'DEBUG';
            default: return 'UNKNOWN';
        }
    }
    
    private function writeLog($level, $message, $context = []) {
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $levelName = $this->getLevelName($level);
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        $logEntry = "[{$timestamp}] {$levelName}: {$message}{$contextStr}\n";
        
        // Write to daily log file
        $filename = $this->logPath . 'app-' . date('Y-m-d') . '.log';
        file_put_contents($filename, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also log to system log for errors
        if ($level <= self::WARNING) {
            error_log("S3 Sync {$levelName}: {$message}");
        }
    }
    
    public function error($message, $context = []) {
        $this->writeLog(self::ERROR, $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->writeLog(self::WARNING, $message, $context);
    }
    
    public function info($message, $context = []) {
        $this->writeLog(self::INFO, $message, $context);
    }
    
    public function debug($message, $context = []) {
        $this->writeLog(self::DEBUG, $message, $context);
    }
    
    public function logException(\Exception $e, $context = []) {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        
        $this->error('Exception occurred: ' . $e->getMessage(), $context);
    }
    
    public function logJobEvent($jobId, $event, $details = []) {
        $context = array_merge(['job_id' => $jobId], $details);
        $this->info("Job {$event}", $context);
    }
    
    public function logSecurityEvent($event, $details = []) {
        $context = array_merge([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ], $details);
        
        $this->warning("Security event: {$event}", $context);
    }
    
    public function logS3Operation($operation, $success, $details = []) {
        $level = $success ? self::INFO : self::ERROR;
        $status = $success ? 'SUCCESS' : 'FAILED';
        $message = "S3 {$operation}: {$status}";
        
        $this->writeLog($level, $message, $details);
    }
}