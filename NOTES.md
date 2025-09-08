# S3 Local Sync - Development and Usage Notes

## Quick Start

The application is now running on `http://0.0.0.0:8080` and accessible from any network interface.

### Access URLs
- **Local Access**: http://localhost:8080
- **Remote Access via VPN**: http://[YOUR_SERVER_IP]:8080
- **Login Page**: http://[YOUR_SERVER_IP]:8080/login.php

### Default Credentials
- **Username**: `admin`
- **Password**: `!!~~complex01`

## Current Server Status

PHP development server is running on all interfaces (0.0.0.0:8080), allowing remote access via VPN.

To check server status:
```bash
ps aux | grep "php -S"
```

To stop the server:
```bash
pkill -f "php -S 0.0.0.0:8080"
```

To restart the server:
```bash
php -S 0.0.0.0:8080
```

## Application Structure

### Core Features Implemented

1. **Authentication System**
   - Session-based authentication
   - Default admin user created on first run
   - Password hashing using bcrypt

2. **S3 Configuration Management**
   - Support for multiple S3 configurations
   - AWS S3 and S3-compatible services (DigitalOcean, Cloudflare R2)
   - Connection testing before saving
   - Active/inactive configuration states

3. **Sync Job Management**
   - One-way sync (Local → S3 only)
   - Background processing using PHP CLI
   - File and folder synchronization
   - Detailed logging per file

4. **Job Monitoring**
   - Real-time status updates
   - Success/failure tracking
   - Retry failed jobs
   - Detailed job logs

5. **Scheduled Jobs**
   - Cron-based scheduling
   - Multiple schedule configurations
   - Automatic background execution

6. **File Browser**
   - Visual file/folder selection
   - AJAX-based navigation
   - Path selection interface

## Database Schema

SQLite database located at: `data/s3sync.db`

### Tables:
- `users` - Authentication users
- `s3_settings` - S3 configuration profiles  
- `sync_jobs` - Job execution history
- `job_logs` - Per-file sync logs
- `scheduled_jobs` - Cron job configurations

## API Endpoints

- `/api/browse.php` - File system browser API

## Background Worker

The `worker.php` script handles actual file synchronization:
- Runs as separate PHP process
- Updates job status in real-time
- Logs each file operation
- Handles both files and directories recursively

## Security Considerations

1. **Authentication Required**: All pages except login require authentication
2. **Password Security**: Passwords hashed with PHP's password_hash()
3. **Directory Protection**: .htaccess prevents direct access to sensitive directories
4. **SQLite Security**: Database file protected from web access

## Performance Notes

### For Large File Transfers
- Jobs run in background, won't timeout
- Each file tracked individually
- Can handle thousands of files
- Memory efficient recursive directory traversal

### Optimization Tips
1. Use specific paths rather than entire drives
2. Schedule large syncs during off-peak hours
3. Monitor job logs for failures
4. Consider chunking very large datasets

## Common Use Cases

### 1. Daily Backup
```
Schedule: 0 2 * * *
Local Path: /home/user/documents
S3 Path: backups/daily/documents/
```

### 2. Hourly Data Sync
```
Schedule: 0 * * * *
Local Path: /var/data/exports
S3 Path: data/hourly/
```

### 3. Weekly Archive
```
Schedule: 0 0 * * 0
Local Path: /backup/weekly
S3 Path: archives/weekly/
```

## Troubleshooting Guide

### Issue: Can't access from remote machine

**Solution**:
1. Check firewall allows port 8080
2. Verify server is running on 0.0.0.0
3. Check VPN connection is active
4. Try accessing with IP instead of hostname

### Issue: Background jobs not starting

**Solution**:
1. Check PHP CLI is installed: `which php`
2. Verify worker.php has correct permissions
3. Check data/logs/ directory is writable
4. Review PHP error logs

### Issue: S3 upload fails

**Solution**:
1. Verify S3 credentials are correct
2. Check bucket exists and is accessible
3. Ensure proper IAM permissions for PutObject
4. Test connection in S3 Settings page

### Issue: Large files fail to sync

**Solution**:
1. Increase PHP memory limit
2. Check S3 multipart upload limits
3. Verify network stability
4. Consider splitting into smaller jobs

## Testing Checklist

- [x] PHP and extensions installed
- [x] Composer dependencies installed
- [x] Database initialized automatically
- [x] Web server running on 0.0.0.0:8080
- [x] Login page accessible
- [x] Default credentials working
- [ ] S3 configuration saved
- [ ] Test sync job created
- [ ] Background worker executing
- [ ] Job logs visible
- [ ] Scheduled job configured
- [ ] Cron integration tested

## Future Enhancements (Not Implemented)

1. Email notifications for job completion
2. Two-way synchronization
3. File compression before upload
4. Bandwidth throttling
5. Multiple user accounts with roles
6. REST API for external integration
7. Docker containerization
8. Incremental sync (only changed files)
9. File versioning support
10. Web-based log viewer with filtering

## Development Commands

### Monitor Application
```bash
# Watch job status
watch -n 1 'sqlite3 data/s3sync.db "SELECT id, name, status, files_synced, files_failed FROM sync_jobs ORDER BY id DESC LIMIT 5;"'

# Monitor active jobs
sqlite3 data/s3sync.db "SELECT * FROM sync_jobs WHERE status='running';"

# Check failed files
sqlite3 data/s3sync.db "SELECT * FROM job_logs WHERE status='failed';"
```

### Database Management
```bash
# Backup database
cp data/s3sync.db data/s3sync.db.$(date +%Y%m%d)

# Reset database (caution!)
rm data/s3sync.db

# Export data
sqlite3 data/s3sync.db .dump > backup.sql
```

### Testing S3 Connection
```bash
# Test with AWS CLI (if installed)
aws s3 ls s3://your-bucket/ --endpoint-url=https://your-endpoint

# Test with curl
curl -I https://your-bucket.s3.amazonaws.com/
```

## Log Files

- **Cron Log**: `data/logs/cron.log`
- **PHP Error Log**: Check `php -i | grep error_log`
- **Worker Output**: Redirected to /dev/null (modify for debugging)

## Support Resources

- **AWS S3 Documentation**: https://docs.aws.amazon.com/s3/
- **DigitalOcean Spaces**: https://docs.digitalocean.com/products/spaces/
- **Cloudflare R2**: https://developers.cloudflare.com/r2/
- **PHP AWS SDK**: https://docs.aws.amazon.com/sdk-for-php/

## License

MIT License - Free for commercial and personal use

## Version

Current Version: 1.0.0
PHP Compatibility: 7.4+ and 8.x
Last Updated: September 2, 2025