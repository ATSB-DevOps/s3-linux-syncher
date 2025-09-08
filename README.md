# OREN S3 Manager

A powerful, production-ready PHP web application for synchronizing local files and directories to S3-compatible storage services.

© ORENCloud Sdn Bhd | For WSG

## 🌟 Features

### Core Functionality
- **Multi-Path Sync** - Select multiple files and directories in flexible combinations
- **One-Way Sync** - Safe local-to-S3 synchronization with directory structure preservation
- **Background Processing** - Jobs run independently of web interface with process management
- **Job Control** - Pause, resume, stop, restart, and delete sync jobs
- **Real-time Status** - Live job monitoring with auto-refresh capabilities

### Scheduling & Automation
- **Cron Integration** - Built-in cron job support for automated syncs
- **Preset Scheduling** - Easy daily, weekly, monthly options
- **Custom Schedules** - Full cron expression support for advanced scheduling

### S3 Compatibility
- **AWS S3** - Full support for Amazon S3
- **DigitalOcean Spaces** - Complete compatibility
- **Cloudflare R2** - Tested and working
- **MinIO** - Support for self-hosted S3-compatible storage
- **Connection Testing** - Verify credentials before saving settings

### Security & Production Ready
- **Authentication** - Session-based login with timeout protection
- **Rate Limiting** - Protection against brute force attacks
- **Security Headers** - XSS protection, content type enforcement
- **Input Validation** - Comprehensive sanitization of user input
- **Account Lockout** - Automatic lockout after failed login attempts
- **Security Event Logging** - Complete audit trail of security events

### Logging & Monitoring
- **Structured Logging** - Multi-level logging (ERROR, WARNING, INFO, DEBUG)
- **Per-File Tracking** - Success/failure status for every file
- **Job History** - Complete audit trail of all sync operations
- **Performance Statistics** - Success rates, duration tracking
- **Error Reporting** - Detailed error messages and retry functionality

### File Management
- **Interactive File Browser** - Visual selection with checkboxes
- **Overwrite Control** - Choose to skip or overwrite existing files
- **Large File Support** - Stream processing for memory efficiency
- **Path Preservation** - Maintains directory structure in S3

## 🚀 Quick Start

### Requirements
- **PHP 7.4+** (8.x recommended)
- **Extensions**: sqlite3, curl, mbstring, xml, json
- **Optional**: pcntl (for job control)
- **Web Server**: Apache, Nginx, or PHP built-in server

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/ATSB-DevOps/s3-linux-syncher.git
   cd s3-linux-syncher
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Set permissions**
   ```bash
   chmod -R 755 .
   chmod -R 777 data/
   ```

4. **Start the server**
   ```bash
   php -S 0.0.0.0:8080
   ```

5. **Access the application**
   - Open http://localhost:8080
   - **Default login**: admin / !!~~complex01
   - **⚠️ Change the password immediately in production!**

## ⚙️ Configuration

### Basic Setup
1. **Configure S3 Settings**
   - Go to Settings page
   - Add your S3 credentials
   - Test connection before saving
   - Activate the configuration

2. **Create Sync Jobs**
   - Navigate to Sync page
   - Select files/directories using the browser
   - Set S3 destination path
   - Choose overwrite behavior
   - Start sync or schedule for later

### Production Deployment
For production deployment, see [DEPLOYMENT.md](DEPLOYMENT.md) for comprehensive instructions including:
- System requirements and optimization
- Web server configuration (Nginx/Apache)
- SSL/TLS setup
- Security hardening
- Monitoring and maintenance

## 📋 Usage

### Creating a Sync Job
1. **Select Source Files**
   - Use the file browser to navigate
   - Check files and directories to include
   - Switch between single and multi-path selection modes

2. **Configure Destination**
   - Set S3 bucket path (preserves directory structure)
   - Choose overwrite behavior for existing files

3. **Schedule or Run**
   - Run immediately
   - Schedule with preset options (daily/weekly/monthly)
   - Create custom cron schedule

### Managing Jobs
- **Monitor Progress** - Real-time status updates
- **Control Jobs** - Pause, resume, stop running jobs
- **View Logs** - Detailed per-file success/failure reports
- **Retry Failed** - Restart failed jobs with one click
- **Delete Old Jobs** - Clean up completed job history

## 🔧 API Endpoints

The application includes REST API endpoints for job control:

- `POST /api/job-control.php` - Control jobs (pause/resume/stop/restart)
- `POST /api/delete-job.php` - Delete jobs
- `POST /api/browse.php` - File system browsing
- `POST settings.php` - S3 connection testing (via settings page)

## 🛠️ Maintenance

### Cleanup Script
Run the built-in cleanup script to maintain the application:

```bash
php cleanup.php
```

This script:
- Cleans up stale jobs
- Removes old logs based on retention settings
- Optimizes database performance
- Reports disk usage and performance statistics

### Scheduled Maintenance
Set up automatic cleanup with cron:

```bash
# Run cleanup every hour
0 * * * * /usr/bin/php /path/to/s3sync/cleanup.php
```

## 🔐 Security

### Default Configuration
- Session timeout: 1 hour
- Max login attempts: 5 (15-minute lockout)
- Rate limiting enabled
- Security headers enforced

### Production Security
- Change default password immediately
- Use HTTPS in production
- Configure firewall rules
- Enable fail2ban for additional protection
- Regular security updates

## 📊 Monitoring

### Built-in Statistics
- Success/failure rates
- Job duration tracking
- Disk usage monitoring
- Performance metrics

### Log Files
- Application logs: `data/logs/app-YYYY-MM-DD.log`
- Security events logged to system log
- Structured JSON logging for easy parsing

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

- **Issues**: [GitHub Issues](https://github.com/ATSB-DevOps/s3-linux-syncher/issues)
- **Documentation**: See [DEPLOYMENT.md](DEPLOYMENT.md) for detailed setup
- **Changelog**: See [CHANGELOG.md](CHANGELOG.md) for version history

## 🎯 Roadmap

### Planned Features (v0.2+)
- Two-way synchronization
- Multiple user accounts with roles
- Email notifications for job completion
- File compression before upload
- Bandwidth throttling
- REST API expansion
- Docker containerization
- Web-based log viewer with filtering
- Incremental sync (only changed files)
- File versioning support

---

**Made with ❤️ for reliable S3 synchronization**

Version: 0.1.0 | Released: 2025-09-02