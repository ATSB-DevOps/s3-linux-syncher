# Changelog

All notable changes to S3 Sync Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-09-02

### Added
- **Initial Release** - Complete S3 synchronization web application
- **Multi-Path Sync** - Select multiple files and directories in flexible combinations
- **Job Control System** - Pause, resume, stop, restart, and delete sync jobs
- **Enhanced Security** - Rate limiting, login attempt tracking, security headers
- **Background Processing** - Jobs run independently of web interface
- **Scheduled Jobs** - Cron-based automatic synchronization
- **Preset Scheduling** - Easy daily/weekly/monthly options plus custom cron
- **S3 Compatibility** - Support for AWS S3, DigitalOcean Spaces, Cloudflare R2
- **File Overwrite Control** - Option to skip existing files or overwrite
- **Interactive File Browser** - Visual selection with checkboxes
- **Comprehensive Logging** - Per-file success/failure tracking with structured application logging
- **Job History** - Complete audit trail of all sync operations
- **Connection Testing** - Verify S3 credentials before saving
- **Production Configuration** - Security-hardened settings for deployment

### Features
- **Authentication System**
  - Session-based login with timeout
  - Account lockout after failed attempts
  - Security event logging
  - Default admin account with configurable password

- **Sync Capabilities**
  - One-way sync (Local → S3)
  - Directory structure preservation
  - Mixed file and folder selection
  - Concurrent processing support
  - Process ID tracking for job control

- **Web Interface**
  - Responsive design with clean UI
  - Real-time job status updates
  - Auto-refresh for running jobs
  - Form data preservation during testing
  - Intuitive file browser with navigation

- **Administration**
  - Job management dashboard with delete functionality
  - Performance statistics
  - Error reporting and retry functionality
  - Database optimization tools
  - Log rotation and cleanup
  - Structured application logging with multiple levels

### Security
- **Rate Limiting** - Protection against brute force attacks
- **Input Validation** - Sanitization of file paths and user input
- **SQL Injection Protection** - Prepared statements throughout
- **Session Security** - Secure session handling with timeout
- **File Access Control** - Protected sensitive directories
- **Security Headers** - XSS protection, content type enforcement

### Performance
- **Background Processing** - Non-blocking job execution
- **Database Optimization** - SQLite with proper indexing
- **Memory Efficient** - Stream processing for large files
- **Process Management** - Proper cleanup of stale jobs

### Documentation
- **Comprehensive README** - Feature overview and basic setup
- **Installation Guide** - Step-by-step installation instructions
- **Deployment Guide** - Production deployment with security best practices
- **API Documentation** - Job control endpoints
- **Configuration Reference** - All available options explained

### Technical Details
- **PHP Requirements**: 7.4+ (8.x recommended)
- **Dependencies**: AWS SDK for PHP via Composer
- **Database**: SQLite3 with automatic migrations
- **Web Servers**: Apache, Nginx, or PHP built-in server
- **Process Control**: POSIX signals for job management
- **Architecture**: MVC pattern with clean separation of concerns

### Known Limitations
- One-way sync only (Local → S3)
- No file deletion synchronization
- Basic authentication system (single user)
- No file compression before upload
- No bandwidth throttling

### Compatibility
- **S3 Services**: AWS S3, DigitalOcean Spaces, Cloudflare R2, MinIO
- **Operating Systems**: Linux (primary), macOS, Windows (limited)
- **PHP Versions**: 7.4, 8.0, 8.1, 8.2, 8.3
- **Browsers**: Modern browsers with JavaScript enabled

### Upgrade Notes
- First release - no upgrade path needed
- Future versions will include migration scripts
- Configuration file format is stable for v0.x series

## [Unreleased]

### Planned Features
- Two-way synchronization
- Multiple user accounts with roles
- Email notifications for job completion
- File compression before upload
- Bandwidth throttling
- REST API for external integration
- Docker containerization
- Web-based log viewer with filtering
- Incremental sync (only changed files)
- File versioning support

---

**Legend:**
- `Added` for new features
- `Changed` for changes in existing functionality
- `Deprecated` for soon-to-be removed features
- `Removed` for now removed features
- `Fixed` for any bug fixes
- `Security` for vulnerability fixes