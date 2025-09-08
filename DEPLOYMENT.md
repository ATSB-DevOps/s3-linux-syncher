# S3 Sync Manager - Production Deployment Guide

## System Requirements

### Minimum Requirements
- **OS**: Linux (Ubuntu 20.04+, CentOS 7+, Debian 10+)
- **PHP**: 7.4 or 8.x with extensions:
  - sqlite3, curl, mbstring, xml, json, pcntl (optional for job control)
- **RAM**: 512MB (minimum), 2GB+ recommended for large syncs
- **Storage**: 1GB for application, additional space for logs
- **Web Server**: Apache 2.4+ or Nginx 1.18+

### Recommended Requirements
- **OS**: Ubuntu 22.04 LTS or CentOS Stream 9
- **PHP**: 8.2 with OPcache enabled
- **RAM**: 4GB+ for concurrent syncs
- **CPU**: 2+ cores for parallel processing
- **Network**: Stable internet for S3 uploads

## Production Installation

### 1. System Preparation

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install -y php8.2 php8.2-cli php8.2-sqlite3 php8.2-curl \
                    php8.2-mbstring php8.2-xml php8.2-opcache \
                    nginx composer git unzip

# CentOS/RHEL
sudo dnf install -y php php-cli php-pdo php-sqlite3 php-curl \
                     php-mbstring php-xml php-opcache \
                     nginx composer git unzip
```

### 2. Application Deployment

```bash
# Clone repository
git clone https://github.com/sanjayws/s3-local-sync-wsg.git
cd s3-local-sync-wsg

# Install dependencies
composer install --no-dev --optimize-autoloader

# Set up directory structure
sudo mkdir -p /var/www/s3sync
sudo cp -r * /var/www/s3sync/
sudo chown -R www-data:www-data /var/www/s3sync
sudo chmod -R 755 /var/www/s3sync
sudo chmod -R 777 /var/www/s3sync/data
```

### 3. Configuration

```bash
# Copy production config
cd /var/www/s3sync
sudo cp config/production.php config/config.php

# Edit configuration
sudo nano config/config.php
```

**Important Configuration Changes:**

```php
// CRITICAL: Change default password!
'default_password' => 'YOUR_SECURE_PASSWORD_HERE',

// Production settings
'debug_mode' => false,
'timezone' => 'America/New_York', // Your timezone

// Security
'session_timeout' => 7200, // 2 hours
'max_login_attempts' => 3,

// Logging
'log_level' => 'warning', // error, warning, info, debug
'log_retention_days' => 90,
```

### 4. Web Server Configuration

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/s3sync;
    index index.php login.php;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy strict-origin-when-cross-origin;

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Security restrictions
    location ~ ^/(config|src|vendor|data)/ {
        deny all;
        return 403;
    }

    location ~ \.(db|log|sh|sql)$ {
        deny all;
        return 403;
    }

    # Main routing
    location / {
        try_files $uri $uri/ /login.php;
    }

    # API endpoints
    location /api/ {
        try_files $uri $uri/ =404;
    }

    # Disable access logs for static files
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        access_log off;
    }
}
```

#### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/s3sync
    
    # Security headers
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy strict-origin-when-cross-origin
    
    # PHP settings
    <FilesMatch \.php$>
        SetHandler application/x-httpd-php
    </FilesMatch>
    
    # Directory permissions
    <Directory /var/www/s3sync>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Protect sensitive directories
    <DirectoryMatch "^/var/www/s3sync/(config|src|vendor|data)">
        Require all denied
    </DirectoryMatch>
    
    # Protect sensitive files
    <FilesMatch "\.(db|log|sh|sql)$">
        Require all denied
    </FilesMatch>
    
    ErrorLog ${APACHE_LOG_DIR}/s3sync-error.log
    CustomLog ${APACHE_LOG_DIR}/s3sync-access.log combined
</VirtualHost>
```

### 5. SSL Configuration (Recommended)

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx  # For Nginx
# OR
sudo apt install certbot python3-certbot-apache  # For Apache

# Get SSL certificate
sudo certbot --nginx -d your-domain.com  # For Nginx
# OR
sudo certbot --apache -d your-domain.com  # For Apache
```

### 6. Process Management (Recommended)

Create systemd service for job cleanup:

```bash
sudo nano /etc/systemd/system/s3sync-cleanup.service
```

```ini
[Unit]
Description=S3 Sync Job Cleanup Service
After=network.target

[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/s3sync/cleanup.php
```

Create timer:

```bash
sudo nano /etc/systemd/system/s3sync-cleanup.timer
```

```ini
[Unit]
Description=Run S3 Sync cleanup every hour
Requires=s3sync-cleanup.service

[Timer]
OnCalendar=hourly
Persistent=true

[Install]
WantedBy=timers.target
```

Enable services:

```bash
sudo systemctl daemon-reload
sudo systemctl enable s3sync-cleanup.timer
sudo systemctl start s3sync-cleanup.timer
```

### 7. Firewall Configuration

```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp      # SSH
sudo ufw allow 80/tcp      # HTTP
sudo ufw allow 443/tcp     # HTTPS
sudo ufw enable

# Firewalld (CentOS)
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

### 8. Monitoring Setup

Create monitoring script:

```bash
sudo nano /var/www/s3sync/monitor.php
```

```php
<?php
require_once 'vendor/autoload.php';
use S3Sync\JobController;

$controller = new JobController();
$controller->cleanupStaleJobs();

// Check disk space
$diskUsage = disk_free_space('data/');
if ($diskUsage < 1073741824) { // 1GB
    error_log("S3 Sync: Low disk space warning");
}

// Check for stuck jobs
$db = (new S3Sync\Database())->getConnection();
$stmt = $db->query("SELECT COUNT(*) FROM sync_jobs WHERE status = 'running' AND started_at < datetime('now', '-2 hours')");
if ($stmt->fetchColumn() > 0) {
    error_log("S3 Sync: Long-running jobs detected");
}
```

Add to cron:

```bash
sudo crontab -e
# Add line:
*/15 * * * * /usr/bin/php /var/www/s3sync/monitor.php
```

## Production Checklist

### Security Checklist
- [ ] Changed default admin password
- [ ] Configured SSL/TLS encryption
- [ ] Set up firewall rules
- [ ] Protected sensitive directories
- [ ] Enabled security headers
- [ ] Configured rate limiting
- [ ] Set up log monitoring

### Performance Checklist
- [ ] Enabled PHP OPcache
- [ ] Configured appropriate PHP limits
- [ ] Set up log rotation
- [ ] Configured backup strategy
- [ ] Tested with expected load
- [ ] Set up monitoring alerts

### Backup Strategy
- [ ] Database backup (`data/s3sync.db`)
- [ ] Configuration backup (`config/config.php`)
- [ ] Log backup (`data/logs/`)
- [ ] Application backup (entire directory)

## Maintenance

### Daily Tasks
- Monitor job success rates
- Check error logs
- Verify disk space

### Weekly Tasks
- Review security logs
- Update system packages
- Test backup restoration
- Check SSL certificate expiry

### Monthly Tasks
- Review user access
- Update application (if needed)
- Performance optimization
- Security audit

## Troubleshooting

### Common Issues

**Jobs not starting:**
```bash
# Check PHP CLI
which php
php -v

# Check permissions
ls -la /var/www/s3sync/worker.php
ls -la /var/www/s3sync/data/

# Check processes
ps aux | grep php
```

**High memory usage:**
```bash
# Check PHP memory limit
php -i | grep memory_limit

# Monitor job processes
top -p $(pgrep -f worker.php)
```

**Database locks:**
```bash
# Check database file
ls -la /var/www/s3sync/data/s3sync.db
lsof /var/www/s3sync/data/s3sync.db
```

### Log Locations
- Application logs: `/var/www/s3sync/data/logs/`
- Web server logs: `/var/log/nginx/` or `/var/log/apache2/`
- System logs: `/var/log/syslog`
- PHP logs: `/var/log/php8.2-fpm.log`

### Support
For production issues:
1. Check logs first
2. Review configuration
3. Test S3 connectivity
4. Verify file permissions
5. Submit GitHub issue with logs

## Version History

- v0.1.0: Initial release with core functionality
  - Multi-path sync support
  - Job control (pause/resume/stop)
  - Enhanced security features
  - Production-ready configuration