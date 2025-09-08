<?php
/**
 * Production Configuration
 * Copy this file to config.php and modify for your environment
 */

return [
    // Database configuration
    'db_path' => __DIR__ . '/../data/s3sync.db',
    'logs_path' => __DIR__ . '/../data/logs/',
    'jobs_path' => __DIR__ . '/../data/jobs/',
    
    // Security settings
    'default_username' => 'admin',
    'default_password' => '!!~~complex01', // CHANGE THIS IMMEDIATELY
    'session_name' => 's3sync_session',
    'session_timeout' => 3600, // 1 hour in seconds
    'max_login_attempts' => 5,
    'lockout_duration' => 900, // 15 minutes in seconds
    
    // Application settings
    'app_name' => 'OREN S3 Manager',
    'app_version' => '0.1.0',
    'timezone' => 'UTC',
    'debug_mode' => false,
    
    // File upload limits
    'max_file_size' => 1073741824, // 1GB in bytes
    'allowed_file_extensions' => [], // Empty array = all extensions allowed
    
    // Rate limiting
    'max_requests_per_minute' => 60,
    'max_sync_jobs_per_hour' => 10,
    
    // S3 settings
    'default_s3_region' => 'us-east-1',
    'multipart_threshold' => 104857600, // 100MB
    'max_concurrent_uploads' => 5,
    
    // Logging
    'log_level' => 'info', // error, warning, info, debug
    'log_retention_days' => 30,
    'enable_access_log' => true,
    
    // Security headers
    'security_headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'"
    ]
];