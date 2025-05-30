# University Council Election System (UCES) - Installation Guide

## 🎯 Overview

The University Council Election System (UCES) is a comprehensive, secure web-based voting platform designed for educational institutions. This system provides end-to-end election management with advanced security features, user-friendly interfaces, and comprehensive administrative tools.

## ✨ Features

### 🔒 Security Features
- **Advanced Authentication**: Multi-factor authentication with rate limiting
- **Data Encryption**: All passwords hashed using bcrypt with high cost factor
- **CSRF Protection**: Cross-site request forgery prevention
- **SQL Injection Prevention**: Prepared statements and input validation
- **Session Security**: Secure session management with regeneration
- **Audit Logging**: Comprehensive activity logging and monitoring
- **Rate Limiting**: Protection against brute force attacks
- **Input Sanitization**: XSS prevention and data validation

### 👥 User Management
- **Student Registration**: Secure student ID verification
- **Role-based Access**: Separate student and admin interfaces  
- **Account Lockout**: Automatic lockout after failed attempts
- **Profile Management**: User information and preferences

### 🗳️ Voting System
- **Secure Voting**: Anonymous, tamper-proof ballot casting
- **Real-time Validation**: Instant vote verification
- **Single Vote Enforcement**: Prevents multiple voting
- **Candidate Management**: Full nominee administration
- **Position Management**: Multiple election positions

### 📊 Analytics & Reporting
- **Real-time Results**: Live vote counting and display
- **Statistical Dashboard**: Comprehensive election metrics
- **Export Capabilities**: Data export for analysis
- **Visual Charts**: Interactive result visualization

## 🛠️ System Requirements

### Server Requirements
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **PHP**: Version 8.0 or higher
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **Storage**: Minimum 1GB free space
- **Memory**: Minimum 512MB RAM (2GB recommended)

### PHP Extensions Required
```bash
php-mysqli
php-pdo
php-pdo-mysql
php-gd
php-json
php-mbstring
php-openssl
php-session
php-fileinfo
```

### Browser Compatibility
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## 📦 Installation Steps

### Step 1: Download and Extract Files
```bash
# Download the system files
git clone https://github.com/your-repo/uces.git
cd uces

# Or extract from zip
unzip uces-system.zip
cd uces-system
```

### Step 2: Database Setup
1. **Create Database**:
```sql
CREATE DATABASE uces_election CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. **Import Database Schema**:
```bash
mysql -u username -p uces_election < database.sql
```

3. **Create Database User** (optional but recommended):
```sql
CREATE USER 'uces_user'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON uces_election.* TO 'uces_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step 3: Configuration
1. **Update Database Configuration** in `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'uces_user');
define('DB_PASSWORD', 'your_secure_password');
define('DB_NAME', 'uces_election');
```

2. **Set File Permissions**:
```bash
chmod 755 /path/to/uces
chmod 644 /path/to/uces/*.php
chmod 755 /path/to/uces/logs
chmod 755 /path/to/uces/uploads
chmod 600 /path/to/uces/config.php
```

3. **Configure Web Server**:

#### Apache Configuration
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/uces
    
    <Directory /path/to/uces>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Security headers
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>
```

#### Nginx Configuration
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/uces;
    index index.html index.php;
    
    location / {
        try_files $uri $uri/ =404;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
}
```

### Step 4: SSL Certificate (Recommended)
```bash
# Using Let's Encrypt
certbot --apache -d your-domain.com

# Or for Nginx
certbot --nginx -d your-domain.com
```

### Step 5: Initial Setup
1. **Access Admin Panel**: Navigate to `https://your-domain.com/admin_login.php`
2. **Default Admin Credentials**:
   - Username: `admin`
   - Password: `admin123` (⚠️ **Change immediately!**)

3. **Change Default Password**:
   - Log in to admin panel
   - Navigate to profile settings
   - Update password immediately

## 🔧 Post-Installation Configuration

### Security Hardening
1. **Update Default Passwords**:
   ```sql
   UPDATE admins SET password = '$2y$12$new_hashed_password' WHERE username = 'admin';
   ```

2. **Configure Email Settings** (if needed):
   ```php
   // In config.php
   define('SMTP_HOST', 'your-smtp-server.com');
   define('SMTP_PORT', 587);
   define('SMTP_USERNAME', 'your-email@domain.com');
   define('SMTP_PASSWORD', 'your-email-password');
   ```

3. **Set Up Log Rotation**:
   ```bash
   # Add to /etc/logrotate.d/uces
   /path/to/uces/logs/*.log {
       weekly
       rotate 52
       compress
       delaycompress
       missingok
       create 644 www-data www-data
   }
   ```

### Performance Optimization
1. **Enable PHP OPcache**:
   ```ini
   ; In php.ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.interned_strings_buffer=8
   opcache.max_accelerated_files=4000
   opcache.revalidate_freq=2
   ```

2. **Database Optimization**:
   ```sql
   -- Add indexes for better performance
   CREATE INDEX idx_votes_user_date ON votes(user_id, created_at);
   CREATE INDEX idx_nominations_position ON nominations(position, is_approved);
   ```

## 🧪 Testing the Installation

### Functional Tests
1. **Student Registration**:
   - Navigate to registration page
   - Test with valid student ID
   - Verify email validation
   - Check password strength requirements

2. **Login System**:
   - Test student login
   - Test admin login
   - Verify rate limiting (try multiple failed attempts)
   - Check session timeout

3. **Voting Process**:
   - Add test candidates via admin panel
   - Log in as student
   - Cast test votes
   - Verify vote recording

4. **Admin Functions**:
   - User management
   - Candidate management
   - Results viewing
   - System statistics

### Security Tests
1. **SQL Injection**: Test with malicious inputs
2. **XSS Prevention**: Test script injection
3. **CSRF Protection**: Verify token validation
4. **File Upload**: Test image upload security
5. **Session Security**: Check session hijacking prevention

## 🚀 Going Live

### Pre-Launch Checklist
- [ ] Database backup created
- [ ] SSL certificate installed and tested
- [ ] All default passwords changed
- [ ] Security headers configured
- [ ] Rate limiting tested
- [ ] Error pages customized
- [ ] Log monitoring set up
- [ ] Backup strategy implemented

### Launch Steps
1. **Final Security Scan**:
   ```bash
   # Run security scanner
   nikto -h https://your-domain.com
   
   # Check SSL configuration
   testssl.sh your-domain.com
   ```

2. **Performance Testing**:
   ```bash
   # Load testing with Apache Bench
   ab -n 1000 -c 10 https://your-domain.com/
   ```

3. **Monitoring Setup**:
   - Set up log monitoring
   - Configure alerts for errors
   - Monitor database performance
   - Set up uptime monitoring

## 📱 Usage Guide

### For Students
1. **Registration**:
   - Visit the main site
   - Click "Register to Vote"
   - Enter student ID and details
   - Verify email (if enabled)

2. **Voting**:
   - Log in with credentials
   - Review candidates
   - Select one candidate per position
   - Submit vote (irreversible)

3. **Results**:
   - View live results (if enabled)
   - See voting statistics

### For Administrators
1. **User Management**:
   - Add/edit/delete users
   - Search and filter users
   - Manage user status

2. **Election Management**:
   - Add/edit candidates
   - Set election dates
   - Configure voting settings
   - Monitor voting progress

3. **Results & Analytics**:
   - View real-time results
   - Export data
   - Generate reports
   - Monitor system statistics

## 🔧 Maintenance

### Regular Tasks
1. **Daily**:
   - Monitor error logs
   - Check system performance
   - Verify backup completion

2. **Weekly**:
   - Review security logs
   - Update system statistics
   - Check disk space usage

3. **Monthly**:
   - Security updates
   - Database maintenance
   - Performance optimization
   - Backup verification

### Database Maintenance
```sql
-- Optimize tables monthly
OPTIMIZE TABLE users, nominations, votes, audit_logs;

-- Clean old audit logs (older than 1 year)
DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Update table statistics
ANALYZE TABLE users, nominations, votes;
```

### Log Management
```bash
# Archive old logs
find /path/to/uces/logs -name "*.log" -mtime +30 -exec gzip {} \;

# Clean very old logs
find /path/to/uces/logs -name "*.gz" -mtime +365 -delete
```

## 🛡️ Security Best Practices

### Server Security
1. **Keep Software Updated**:
   ```bash
   # Update system packages
   sudo apt update && sudo apt upgrade
   
   # Update PHP
   sudo apt install php8.1
   ```

2. **Firewall Configuration**:
   ```bash
   # Basic UFW setup
   sudo ufw enable
   sudo ufw allow 22/tcp
   sudo ufw allow 80/tcp
   sudo ufw allow 443/tcp
   ```

3. **Fail2Ban Setup**:
   ```bash
   # Install and configure fail2ban
   sudo apt install fail2ban
   
   # Create custom jail for UCES
   sudo nano /etc/fail2ban/jail.d/uces.conf
   ```

### Application Security
1. **Regular Security Audits**
2. **Monitor Failed Login Attempts**
3. **Review User Permissions**
4. **Check File Integrity**
5. **Update Dependencies**

## 🔄 Backup Strategy

### Automated Backups
```bash
#!/bin/bash
# backup-uces.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups/uces"
DB_NAME="uces_election"
WEB_DIR="/path/to/uces"

# Create backup directory
mkdir -p $BACKUP_DIR/$DATE

# Database backup
mysqldump -u username -p$password $DB_NAME > $BACKUP_DIR/$DATE/database.sql

# Files backup
tar -czf $BACKUP_DIR/$DATE/files.tar.gz $WEB_DIR

# Remove old backups (keep 30 days)
find $BACKUP_DIR -type d -mtime +30 -exec rm -rf {} \;

echo "Backup completed: $BACKUP_DIR/$DATE"
```

### Backup Schedule
```bash
# Add to crontab
0 2 * * * /path/to/backup-uces.sh
```

## 📞 Support & Troubleshooting

### Common Issues

1. **Database Connection Error**:
   - Check database credentials
   - Verify MySQL service is running
   - Check firewall settings

2. **Permission Denied Errors**:
   ```bash
   # Fix file permissions
   sudo chown -R www-data:www-data /path/to/uces
   sudo chmod -R 755 /path/to/uces
   ```

3. **Session Issues**:
   - Check PHP session configuration
   - Verify session directory permissions
   - Clear browser cache

4. **Upload Errors**:
   - Check upload directory permissions
   - Verify PHP upload limits
   - Check file size restrictions

### Log Files
- **Error Log**: `/path/to/uces/logs/error.log`
- **Security Log**: Database `audit_logs` table
- **Access Log**: Web server access logs
- **PHP Error Log**: `/var/log/php/error.log`

### Performance Issues
1. **Slow Database Queries**:
   ```sql
   -- Enable slow query log
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 2;
   ```

2. **High Memory Usage**:
   - Check PHP memory limits
   - Monitor database connections
   - Review log file sizes

### Getting Help
- **Documentation**: Check system documentation
- **Error Messages**: Review detailed error logs
- **Community**: Search for similar issues
- **Professional Support**: Contact system administrator

## 📋 System Information

### File Structure
```
uces/
├── config.php              # Main configuration
├── index.html              # Landing page
├── login.php               # Student login
├── register.php            # Student registration
├── welcome.php             # Student dashboard
├── admin_login.php         # Admin login
├── admin_dashboard.php     # Admin panel
├── nomination.php          # Candidates page
├── process_voting.php      # Vote processing
├── results.html            # Results display
├── error_handler.php       # Error pages
├── get_nomination.php      # API endpoint
├── get_user.php           # API endpoint
├── fetch_results.php      # Results API
├── logout.php             # Logout handler
├── .htaccess              # Security config
├── logs/                  # Log directory
├── uploads/               # Upload directory
└── database.sql           # Database schema
```

### Database Tables
- **users**: Student accounts
- **admins**: Administrator accounts
- **nominations**: Candidate information
- **votes**: Cast votes
- **audit_logs**: Security events
- **election_settings**: System configuration
- **user_sessions**: Session management

## 🔄 Updates & Upgrades

### Checking for Updates
1. Monitor release notes
2. Test updates in staging environment
3. Backup before updating
4. Follow semantic versioning

### Update Process
```bash
# Backup current system
./backup-uces.sh

# Download new version
wget https://releases.com/uces/latest.zip

# Extract to staging directory
unzip latest.zip -d /staging/uces

# Test in staging environment
# Apply updates to production

# Update database schema if needed
mysql -u username -p uces_election < updates/v2.0.sql
```

---

## 📄 License & Credits

**UCES** - University Council Election System
Version 2.0 - Enhanced Security Edition

This system includes comprehensive security features, user management, and administrative tools designed specifically for educational institutions.

**Security Features**: Advanced authentication, CSRF protection, SQL injection prevention, audit logging, and more.

**Support**: For technical support and customization services, please contact your system administrator.

---

*Last Updated: 2024*
*Installation Guide Version: 2.0*