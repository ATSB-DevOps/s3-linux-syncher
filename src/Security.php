<?php
namespace S3Sync;

class Security {
    private $config;
    private $db;
    private $database;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->database = new Database();
        $this->db = $this->database->getConnection();
        $this->initSecurityTables();
    }
    
    private function initSecurityTables() {
        // Login attempts tracking
        $this->db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            username TEXT NOT NULL,
            success BOOLEAN NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            user_agent TEXT
        )");
        
        // Rate limiting
        $this->db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            action TEXT NOT NULL,
            count INTEGER DEFAULT 1,
            window_start DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ip_address, action)
        )");
    }
    
    public function recordLoginAttempt($username, $success, $ipAddress = null, $userAgent = null) {
        $ipAddress = $ipAddress ?? $this->getClientIP();
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        $this->database->executeWithRetry(function() use ($ipAddress, $username, $success, $userAgent) {
            $stmt = $this->db->prepare("INSERT INTO login_attempts (ip_address, username, success, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->execute([$ipAddress, $username, $success ? 1 : 0, $userAgent]);
        });
        
        // Clean old attempts
        $this->cleanOldLoginAttempts();
    }
    
    public function isAccountLocked($username, $ipAddress = null) {
        $ipAddress = $ipAddress ?? $this->getClientIP();
        $lockoutDuration = $this->config['lockout_duration'] ?? 900;
        $maxAttempts = $this->config['max_login_attempts'] ?? 5;
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE (ip_address = ? OR username = ?) 
            AND success = 0 
            AND attempted_at > datetime('now', '-{$lockoutDuration} seconds')
        ");
        $stmt->execute([$ipAddress, $username]);
        
        return $stmt->fetchColumn() >= $maxAttempts;
    }
    
    public function checkRateLimit($action, $limit, $window = 60, $ipAddress = null) {
        $ipAddress = $ipAddress ?? $this->getClientIP();
        
        return $this->database->executeWithRetry(function() use ($action, $limit, $window, $ipAddress) {
            $stmt = $this->db->prepare("
                SELECT count, window_start FROM rate_limits 
                WHERE ip_address = ? AND action = ?
            ");
            $stmt->execute([$ipAddress, $action]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$result) {
                // First request
                $stmt = $this->db->prepare("INSERT INTO rate_limits (ip_address, action, count) VALUES (?, ?, 1)");
                $stmt->execute([$ipAddress, $action]);
                return true;
            }
            
            $windowStart = strtotime($result['window_start']);
            $now = time();
            
            if ($now - $windowStart >= $window) {
                // Reset window
                $stmt = $this->db->prepare("UPDATE rate_limits SET count = 1, window_start = CURRENT_TIMESTAMP WHERE ip_address = ? AND action = ?");
                $stmt->execute([$ipAddress, $action]);
                return true;
            }
            
            if ($result['count'] >= $limit) {
                return false;
            }
            
            // Increment counter
            $stmt = $this->db->prepare("UPDATE rate_limits SET count = count + 1 WHERE ip_address = ? AND action = ?");
            $stmt->execute([$ipAddress, $action]);
            
            return true;
        });
    }
    
    public function sanitizeFilePath($path) {
        // Remove null bytes and control characters
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);
        
        // Resolve path traversal attempts
        $path = str_replace(['../', '..\\'], '', $path);
        
        // Normalize path separators
        $path = str_replace('\\', '/', $path);
        
        return $path;
    }
    
    public function validateS3BucketName($name) {
        // AWS S3 bucket naming rules
        if (strlen($name) < 3 || strlen($name) > 63) {
            return false;
        }
        
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name)) {
            return false;
        }
        
        if (preg_match('/\.\./', $name) || preg_match('/\.-|-\./', $name)) {
            return false;
        }
        
        return true;
    }
    
    public function getClientIP() {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    public function setSecurityHeaders() {
        $headers = $this->config['security_headers'] ?? [];
        
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        
        // Additional security measures
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    private function cleanOldLoginAttempts() {
        $retentionDays = $this->config['log_retention_days'] ?? 30;
        $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-{$retentionDays} days')");
        $this->db->exec("DELETE FROM rate_limits WHERE window_start < datetime('now', '-1 hour')");
    }
    
    public function logSecurityEvent($event, $details = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $this->getClientIP(),
            'event' => $event,
            'details' => $details,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        $logFile = ($this->config['logs_path'] ?? __DIR__ . '/../data/logs/') . 'security.log';
        $logDir = dirname($logFile);
        
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    }
}