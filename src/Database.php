<?php
namespace S3Sync;

class Database {
    private $db;
    private $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->init();
    }
    
    private function init() {
        $dbPath = $this->config['db_path'];
        $dbDir = dirname($dbPath);
        
        if (!file_exists($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        $this->db = new \PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        // Configure SQLite for better concurrency
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA cache_size=10000');
        $this->db->exec('PRAGMA temp_store=MEMORY');
        $this->db->exec('PRAGMA busy_timeout=5000');
        
        $this->createTables();
        $this->runMigrations();
    }
    
    private function createTables() {
        // Users table
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // S3 Settings table
        $this->db->exec("CREATE TABLE IF NOT EXISTS s3_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            endpoint TEXT,
            region TEXT NOT NULL,
            access_key TEXT NOT NULL,
            secret_key TEXT NOT NULL,
            bucket TEXT NOT NULL,
            is_active BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Sync jobs table
        $this->db->exec("CREATE TABLE IF NOT EXISTS sync_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            local_path TEXT NOT NULL,
            s3_path TEXT NOT NULL,
            s3_settings_id INTEGER NOT NULL,
            status TEXT DEFAULT 'pending',
            overwrite_files BOOLEAN DEFAULT 1,
            process_id INTEGER,
            can_be_stopped BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME,
            completed_at DATETIME,
            paused_at DATETIME,
            resumed_at DATETIME,
            error_message TEXT,
            files_synced INTEGER DEFAULT 0,
            files_failed INTEGER DEFAULT 0,
            files_skipped INTEGER DEFAULT 0,
            FOREIGN KEY (s3_settings_id) REFERENCES s3_settings(id)
        )");
        
        // Job logs table
        $this->db->exec("CREATE TABLE IF NOT EXISTS job_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            file_path TEXT NOT NULL,
            status TEXT NOT NULL,
            message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES sync_jobs(id)
        )");
        
        // Scheduled jobs table
        $this->db->exec("CREATE TABLE IF NOT EXISTS scheduled_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            local_path TEXT NOT NULL,
            s3_path TEXT NOT NULL,
            s3_settings_id INTEGER NOT NULL,
            cron_expression TEXT NOT NULL,
            schedule_preset TEXT,
            overwrite_files BOOLEAN DEFAULT 1,
            is_active BOOLEAN DEFAULT 1,
            last_run DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (s3_settings_id) REFERENCES s3_settings(id)
        )");
        
        // Job paths table for multi-path support
        $this->db->exec("CREATE TABLE IF NOT EXISTS job_paths (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            local_path TEXT NOT NULL,
            path_type TEXT NOT NULL CHECK(path_type IN ('file', 'directory')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES sync_jobs(id) ON DELETE CASCADE
        )");
        
        // Scheduled job paths table for multi-path support
        $this->db->exec("CREATE TABLE IF NOT EXISTS scheduled_job_paths (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scheduled_job_id INTEGER NOT NULL,
            local_path TEXT NOT NULL,
            path_type TEXT NOT NULL CHECK(path_type IN ('file', 'directory')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scheduled_job_id) REFERENCES scheduled_jobs(id) ON DELETE CASCADE
        )");
        
        // Create default admin user if not exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$this->config['default_username']]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $this->db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute([
                $this->config['default_username'],
                password_hash($this->config['default_password'], PASSWORD_DEFAULT)
            ]);
        }
    }
    
    private function runMigrations() {
        // Add new columns if they don't exist
        try {
            // Add overwrite_files and files_skipped columns to sync_jobs if not exists
            $this->db->exec("ALTER TABLE sync_jobs ADD COLUMN overwrite_files BOOLEAN DEFAULT 1");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            $this->db->exec("ALTER TABLE sync_jobs ADD COLUMN files_skipped INTEGER DEFAULT 0");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            // Add schedule_preset and overwrite_files columns to scheduled_jobs if not exists
            $this->db->exec("ALTER TABLE scheduled_jobs ADD COLUMN schedule_preset TEXT");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            $this->db->exec("ALTER TABLE scheduled_jobs ADD COLUMN overwrite_files BOOLEAN DEFAULT 1");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            // Add job control columns
            $this->db->exec("ALTER TABLE sync_jobs ADD COLUMN process_id INTEGER");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            $this->db->exec("ALTER TABLE sync_jobs ADD COLUMN can_be_stopped BOOLEAN DEFAULT 1");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            $this->db->exec("ALTER TABLE sync_jobs ADD COLUMN paused_at DATETIME");
        } catch (\Exception $e) {
            // Column might already exist
        }
        
        try {
            $this->db->exec("ALTER TABLE sync_jobs ADD COLUMN resumed_at DATETIME");
        } catch (\Exception $e) {
            // Column might already exist
        }
    }
    
    public function getConnection() {
        return $this->db;
    }
    
    /**
     * Execute a database operation with retry logic for handling locks
     */
    public function executeWithRetry(callable $operation, int $maxRetries = 3, int $delayMs = 100) {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            try {
                return $operation();
            } catch (\PDOException $e) {
                $lastException = $e;
                
                // Check if it's a database lock error
                if (strpos($e->getMessage(), 'database is locked') !== false || 
                    strpos($e->getMessage(), 'SQLITE_BUSY') !== false) {
                    $attempt++;
                    if ($attempt < $maxRetries) {
                        // Exponential backoff
                        usleep($delayMs * 1000 * pow(2, $attempt - 1));
                        continue;
                    }
                }
                
                // Re-throw non-lock exceptions immediately
                throw $e;
            }
        }
        
        throw $lastException;
    }
}