# Royal Pay CyberPanel VPS Deployment Guide

## 1. Target VPS Environment Specifications

- **Operating System**: Ubuntu 24.04 LTS / 22.04 LTS
- **Control Panel**: CyberPanel with OpenLiteSpeed
- **Target Domain**: `pay.royaltechbd.com`
- **Database Server**: MariaDB 10.11+ (InnoDB Engine)
- **PHP Version**: LSPHP 8.2 or 8.3
- **PHP Extensions**: `bcmath`, `curl`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `zip`, `intl`, `redis` (optional)

---

## 2. Pre-Deployment Server Verification

Verify server resources and avoid interfering with existing websites:
```bash
# Check CPU, RAM, and Disk space
lscpu
free -h
df -h

# Check existing websites hosted in CyberPanel
cyberpanel listWebsites
```

---

## 3. Step-by-Step Deployment Procedure

### Step 1: Create Website / Subdomain in CyberPanel
1. Log in to CyberPanel at `https://<VPS-IP>:8090`.
2. Go to **Websites > Create Website**:
   - Domain: `pay.royaltechbd.com`
   - Package: Default
   - Admin Email: `admin@royaltechbd.com`
   - PHP Version: `PHP 8.2` or `PHP 8.3`
   - Additional Features: Check `SSL`, `DKIM Support`, `open_basedir`.
3. Click **Create Website**.

### Step 2: Configure MariaDB Database
1. Go to **Databases > Create Database**:
   - Website: `pay.royaltechbd.com`
   - Database Name: `royalpay_db`
   - Username: `royalpay_user`
   - Password: `<GENERATE_STRONG_RANDOM_PASSWORD>`

### Step 3: Configure OpenLiteSpeed `.htaccess` & Rewrite Rules
OpenLiteSpeed requires proper routing for WordPress REST API permalinks. Ensure `/home/pay.royaltechbd.com/public_html/.htaccess` contains:
```apache
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
```

### Step 4: Configure `wp-config.php` Security Constants
Add the following to `/home/pay.royaltechbd.com/public_html/wp-config.php`:
```php
// Disable traffic-triggered WP-Cron in favor of system cron
define('DISABLE_WP_CRON', true);

// Royal Pay Master AES-256 Encryption Key (32 bytes base64 encoded)
define('ROYAL_PAY_ENCRYPTION_KEY', 'base64:...secure-32-byte-key...');

// Environment
define('ROYAL_PAY_ENV', 'production');
```

### Step 5: System Cron Configuration
Open crontab:
```bash
crontab -e
```
Add the following two entries:
```bash
# 1. WordPress Scheduled Events (Every minute)
* * * * * wget -q -O - https://pay.royaltechbd.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1

# 2. Royal Pay Asynchronous Background Job Runner (Every minute)
* * * * * /usr/local/lsws/lsphp82/bin/php /home/pay.royaltechbd.com/public_html/wp-content/plugins/royal-pay-saas/bin/queue-worker.php --max-jobs=50 >/dev/null 2>&1
```

---

## 4. Encrypted Backup & Disaster Recovery

A daily backup script `deployment/scripts/backup-database.sh` is provided. It:
1. Performs `mysqldump --single-transaction --quick`.
2. Encrypts the archive using OpenSSL AES-256-CBC.
3. Retains backups for 30 days.

```bash
# Run backup manually
bash /home/pay.royaltechbd.com/public_html/wp-content/plugins/royal-pay-saas/deployment/scripts/backup-database.sh
```

---

## 5. Rollback Procedure

If a deployment or database migration encounters an error:
1. Roll back database migrations using `php bin/migrate.php rollback`.
2. Restore previous database snapshot from the encrypted backup.
3. Switch OpenLiteSpeed virtual host symlink to the previous stable release.
4. Restart OpenLiteSpeed: `systemctl restart lsws`.
