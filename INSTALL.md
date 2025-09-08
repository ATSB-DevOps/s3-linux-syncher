# Installation Guide

## System Requirements

- **PHP**: Version 7.4 or 8.x
- **Operating System**: Linux/Unix (for cron jobs), Windows (without cron)
- **Web Server**: Apache, Nginx, or PHP built-in server
- **PHP Extensions Required**:
  - sqlite3
  - curl
  - mbstring
  - xml
  - json (usually included)
- **Composer**: PHP dependency manager
- **Git**: For cloning repository

## Step-by-Step Installation

### 1. Clone the Repository

```bash
git clone https://github.com/sanjayws/s3-local-sync-wsg.git
cd s3-local-sync-wsg
```

### 2. Install PHP and Required Extensions

#### Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install -y php php-sqlite3 php-curl php-mbstring php-xml
```

#### CentOS/RHEL/Fedora:
```bash
sudo yum install -y php php-sqlite3 php-curl php-mbstring php-xml
```

#### macOS (using Homebrew):
```bash
brew install php
```

### 3. Install Composer

```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Or download directly
wget https://getcomposer.org/installer
php installer --install-dir=/usr/local/bin --filename=composer
rm installer
```

### 4. Install Application Dependencies

```bash
# Navigate to application directory
cd s3-local-sync-wsg

# Install dependencies
composer install

# If you get permission errors, run as regular user:
COMPOSER_ALLOW_SUPERUSER=1 composer install
```

### 5. Set Directory Permissions

```bash
# Create required directories
mkdir -p data/logs data/jobs

# Set permissions
chmod 755 data/
chmod 755 data/logs/
chmod 755 data/jobs/
```

### 6. Configure Web Server

#### Option A: Using PHP Built-in Server (Development)

```bash
# Start the server on port 8080
php -S localhost:8080

# Or on all interfaces
php -S 0.0.0.0:8080
```

#### Option B: Apache Configuration

Create a virtual host configuration:

```apache
<VirtualHost *:80>
    ServerName s3sync.local
    DocumentRoot /path/to/s3-local-sync-wsg
    
    <Directory /path/to/s3-local-sync-wsg>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/s3sync-error.log
    CustomLog ${APACHE_LOG_DIR}/s3sync-access.log combined
</VirtualHost>
```

Enable the site:
```bash
sudo a2ensite s3sync.conf
sudo a2enmod rewrite
sudo systemctl reload apache2
```

#### Option C: Nginx Configuration

```nginx
server {
    listen 80;
    server_name s3sync.local;
    root /path/to/s3-local-sync-wsg;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    location ~ /\.(ht|db|git) {
        deny all;
    }
    
    location ~ ^/(data|config|src|vendor)/ {
        deny all;
    }
}
```

### 7. First Login

1. Open browser and navigate to your configured URL
2. Login with default credentials:
   - **Username**: `admin`
   - **Password**: `!!~~complex01`

### 8. Configure S3 Settings

1. Navigate to "S3 Settings" in the menu
2. Click "Add New S3 Configuration"
3. Enter your S3 credentials:
   - For AWS S3: Leave endpoint empty
   - For DigitalOcean Spaces: `https://[region].digitaloceanspaces.com`
   - For Cloudflare R2: `https://[account-id].r2.cloudflarestorage.com`
4. Test the connection
5. Save and activate the configuration

### 9. Set Up Cron Jobs (Optional)

For scheduled synchronization:

```bash
# Edit crontab
crontab -e

# Add this line (runs every minute to check for scheduled jobs)
* * * * * /usr/bin/php /path/to/s3-local-sync-wsg/cron.php >> /path/to/s3-local-sync-wsg/data/logs/cron.log 2>&1
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Composer Install Fails

**Error**: `Your requirements could not be resolved to an installable set of packages`

**Solution**:
```bash
# Update composer
composer self-update

# Clear composer cache
composer clear-cache

# Try installing with --ignore-platform-reqs
composer install --ignore-platform-reqs
```

#### 2. Permission Denied Errors

**Error**: `Warning: file_put_contents(data/s3sync.db): failed to open stream: Permission denied`

**Solution**:
```bash
# Fix ownership (replace www-data with your web server user)
sudo chown -R www-data:www-data data/
sudo chmod -R 755 data/
```

#### 3. SQLite Not Found

**Error**: `could not find driver`

**Solution**:
```bash
# Install SQLite PHP extension
sudo apt-get install php-sqlite3

# Restart web server
sudo systemctl restart apache2  # or nginx
```

#### 4. Jobs Not Running in Background

**Error**: Jobs stay in "pending" status

**Solution**:
```bash
# Check PHP CLI path
which php

# Update sync.php with correct PHP path
# Edit line: $phpPath = PHP_BINARY;
# Replace with: $phpPath = '/usr/bin/php';

# Check worker.php permissions
chmod +x worker.php
```

#### 5. Cannot Login

**Error**: Invalid username or password

**Solution**:
```bash
# Reset database (will delete all data!)
rm data/s3sync.db
# Login page will recreate with default credentials
```

#### 6. S3 Connection Test Fails

**Error**: `The request signature we calculated does not match`

**Solution**:
- Verify Access Key and Secret Key are correct
- Check system time is synchronized
- For S3-compatible services, ensure endpoint URL is correct
- Check region setting matches your bucket location

## Performance Optimization

### For Large File Uploads

Edit `php.ini`:
```ini
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
memory_limit = 256M
```

### For Better Background Processing

Consider using a process manager:
```bash
# Install supervisor
sudo apt-get install supervisor

# Create supervisor config
sudo nano /etc/supervisor/conf.d/s3sync-worker.conf
```

```ini
[program:s3sync-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /path/to/worker.php
autostart=false
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/data/logs/worker.log
```

## Security Hardening

### 1. Change Default Password

After first login, update the admin password in the database:
```php
// Create a script change-password.php
<?php
require_once 'vendor/autoload.php';
use S3Sync\Database;

$db = (new Database())->getConnection();
$newPassword = 'YourNewSecurePassword';
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$stmt->execute([$hashedPassword]);
echo "Password updated successfully\n";
```

### 2. Restrict Access

Add HTTP Basic Authentication:
```apache
<Directory /path/to/s3-local-sync-wsg>
    AuthType Basic
    AuthName "S3 Sync Admin"
    AuthUserFile /etc/apache2/.htpasswd
    Require valid-user
</Directory>
```

### 3. Use HTTPS

Configure SSL certificate with Let's Encrypt:
```bash
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
```

### 4. Firewall Rules

```bash
# Allow only necessary ports
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS
sudo ufw enable
```

## Backup and Recovery

### Backup Database

```bash
# Create backup
cp data/s3sync.db data/s3sync.db.backup

# Restore backup
cp data/s3sync.db.backup data/s3sync.db
```

### Export Configuration

```bash
# Export S3 settings (from SQLite)
sqlite3 data/s3sync.db "SELECT * FROM s3_settings;" > s3_settings_backup.csv
```

## Monitoring

### Check Application Logs

```bash
# View sync logs
tail -f data/logs/cron.log

# Check web server logs
tail -f /var/log/apache2/error.log
tail -f /var/log/nginx/error.log
```

### Monitor Sync Jobs

```sql
-- Connect to database
sqlite3 data/s3sync.db

-- Check failed jobs
SELECT * FROM sync_jobs WHERE status = 'failed';

-- View recent job logs
SELECT * FROM job_logs ORDER BY created_at DESC LIMIT 10;
```

## Uninstallation

```bash
# Stop web server
sudo systemctl stop apache2  # or nginx

# Remove cron job
crontab -e  # Remove the s3sync line

# Delete application files
rm -rf /path/to/s3-local-sync-wsg

# Remove composer (optional)
sudo rm /usr/local/bin/composer
```

## Support

For issues, feature requests, or questions:
- GitHub Issues: https://github.com/sanjayws/s3-local-sync-wsg/issues
- Documentation: See README.md

## Version Information

- Application Version: 1.0.0
- PHP Compatibility: 7.4+ and 8.x
- Last Updated: 2025