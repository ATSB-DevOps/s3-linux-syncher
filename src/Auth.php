<?php
namespace S3Sync;

class Auth {
    private $db;
    private $database;
    private $config;
    private $security;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->security = new Security();
        
        // Set security headers
        $this->security->setSecurityHeaders();
        
        session_name($this->config['session_name']);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->database = new Database();
        $this->db = $this->database->getConnection();
        
        // Check session timeout
        $this->checkSessionTimeout();
    }
    
    public function login($username, $password) {
        // Check rate limiting
        if (!$this->security->checkRateLimit('login', 5, 300)) { // 5 attempts per 5 minutes
            $this->security->logSecurityEvent('rate_limit_exceeded', ['action' => 'login', 'username' => $username]);
            return false;
        }
        
        // Check if account is locked
        if ($this->security->isAccountLocked($username)) {
            $this->security->logSecurityEvent('account_locked', ['username' => $username]);
            return false;
        }
        
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['login_time'] = time();
            
            $this->security->recordLoginAttempt($username, true);
            $this->security->logSecurityEvent('login_success', ['username' => $username]);
            return true;
        }
        
        $this->security->recordLoginAttempt($username, false);
        $this->security->logSecurityEvent('login_failed', ['username' => $username]);
        return false;
    }
    
    public function logout() {
        session_destroy();
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            // Include path helper for subdirectory support
            require_once __DIR__ . '/../includes/path_helper.php';
            appRedirect('login.php');
        }
    }
    
    public function getCurrentUser() {
        if ($this->isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username']
            ];
        }
        return null;
    }
    
    public function changePassword($currentPassword, $newPassword) {
        if (!$this->isLoggedIn()) {
            return ['success' => false, 'message' => 'User not logged in'];
        }
        
        $userId = $_SESSION['user_id'];
        
        // Verify current password
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $this->security->logSecurityEvent('password_change_failed', [
                'username' => $_SESSION['username'],
                'reason' => 'invalid_current_password'
            ]);
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        // Validate new password
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'New password must be at least 8 characters long'];
        }
        
        // Update password with retry logic
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->database->executeWithRetry(function() use ($hashedPassword, $userId) {
            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
        });
        
        $this->security->logSecurityEvent('password_changed', [
            'username' => $_SESSION['username']
        ]);
        
        return ['success' => true, 'message' => 'Password changed successfully'];
    }
    
    private function checkSessionTimeout() {
        if ($this->isLoggedIn()) {
            $timeout = $this->config['session_timeout'] ?? 3600;
            $loginTime = $_SESSION['login_time'] ?? time();
            
            if (time() - $loginTime > $timeout) {
                $this->security->logSecurityEvent('session_timeout', ['username' => $_SESSION['username'] ?? 'unknown']);
                $this->logout();
            }
        }
    }
}