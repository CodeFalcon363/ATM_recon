# Deployment Guide

This guide covers deploying the ATM Reconciliation application to various hosting environments.

## Table of Contents
1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [Shared Hosting Deployment](#shared-hosting-deployment)
3. [VPS/Cloud Server Deployment](#vpscloud-server-deployment)
4. [Post-Deployment Verification](#post-deployment-verification)
5. [Troubleshooting](#troubleshooting)

---

## Pre-Deployment Checklist

Before deploying, ensure:

- ✅ PHP 8.3 or higher is available on the server
- ✅ Required PHP extensions: `zip`, `xml`, `gd`, `mbstring`
- ✅ Composer dependencies are installed: `composer install --no-dev --optimize-autoloader`
- ✅ All tests pass locally: `./vendor/bin/phpunit`
- ✅ Write permissions on `tmp/` directory (if used for temp files)
- ✅ `.gitignore` is properly configured (sensitive files excluded)

---

## Shared Hosting Deployment

Most shared hosting (cPanel, Plesk, etc.) doesn't allow you to set the document root to `public/`. This app is configured to handle that.

### Method 1: Direct Upload (Repository Root as Document Root)

**When to use:** Your hosting points to the repository root (e.g., `public_html/` contains your repo).

**Setup:**
1. Upload all files to your hosting (e.g., `public_html/` or `www/`)
2. Ensure `.htaccess` in root redirects to `public/` (already configured)
3. The root `index.php` will redirect to `public/index.php`

**File Structure on Server:**
```
public_html/              ← Document root
├── .htaccess            ← Redirects to public/
├── index.php            ← Redirects to public/
├── public/
│   ├── .htaccess
│   ├── index.php        ← Main application entry
│   ├── process.php
│   ├── download.php
│   └── ...
├── src/
├── vendor/
└── composer.json
```

**How it works:**
- User visits: `yourdomain.com/`
- `.htaccess` rewrites to: `yourdomain.com/public/`
- Fallback `index.php` redirects to: `public/index.php`

### Method 2: Subdirectory Installation

**When to use:** Installing in a subdirectory (e.g., `yourdomain.com/atm-recon/`).

**Setup:**
1. Upload to subdirectory: `public_html/atm-recon/`
2. Access via: `yourdomain.com/atm-recon/public/` or `yourdomain.com/atm-recon/` (redirects automatically)

### Method 3: Point Document Root to Public Folder (Ideal)

**When to use:** Your hosting allows changing document root (some cPanel/Plesk configurations).

**Setup:**
1. Upload repository to `home/username/atm-recon/`
2. In hosting control panel, set document root to: `home/username/atm-recon/public/`
3. This is the most secure option (hides src/, vendor/, config/ from web access)

**File Structure:**
```
home/username/
└── atm-recon/           ← Repository (not web-accessible)
    ├── public/          ← Document root points here
    ├── src/
    ├── vendor/
    └── ...
```

---

## VPS/Cloud Server Deployment

For VPS (DigitalOcean, AWS EC2, Linode, etc.) with full server control.

### Apache Configuration

**Edit virtual host** (e.g., `/etc/apache2/sites-available/atm-recon.conf`):

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAdmin admin@yourdomain.com
    
    # Point document root to public folder
    DocumentRoot /var/www/atm-recon/public
    
    <Directory /var/www/atm-recon/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/atm-recon-error.log
    CustomLog ${APACHE_LOG_DIR}/atm-recon-access.log combined
</VirtualHost>
```

**Enable and restart:**
```bash
sudo a2ensite atm-recon.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Nginx Configuration

**Edit server block** (e.g., `/etc/nginx/sites-available/atm-recon`):

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    
    # Point root to public folder
    root /var/www/atm-recon/public;
    index index.php;
    
    # Disable directory listing
    autoindex off;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Deny access to sensitive files
    location ~ /\.(git|htaccess|env) {
        deny all;
    }
    
    location ~ /composer\.(json|lock)$ {
        deny all;
    }
}
```

**Enable and restart:**
```bash
sudo ln -s /etc/nginx/sites-available/atm-recon /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### PHP-FPM Configuration

Ensure sufficient resources in `php.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
memory_limit = 256M
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.1-fpm  # Adjust version as needed
```

---

## Post-Deployment Verification

After deployment, verify the setup:

### 1. Check Application Access
- Visit: `https://yourdomain.com/`
- Should display the upload form (public/index.php)

### 2. Test File Upload
- Upload sample GL and FEP Excel files
- Verify reconciliation results display correctly
- Check download functionality

### 3. Security Check
Test that these URLs return **403 Forbidden** or **404 Not Found**:
- `https://yourdomain.com/composer.json`
- `https://yourdomain.com/src/`
- `https://yourdomain.com/vendor/`
- `https://yourdomain.com/.env`
- `https://yourdomain.com/.git/`

### 4. Debug Tools (Optional)
If enabled for testing:
- `https://yourdomain.com/debug.php`
- `https://yourdomain.com/debug_matching.php`
- `https://yourdomain.com/verify.php`

**Important:** Remove or restrict access to debug files in production!

### 5. Check Permissions
```bash
# Ensure tmp/ is writable
chmod 755 tmp/
chown www-data:www-data tmp/  # Adjust user/group as needed
```

---

## Troubleshooting

### Issue: "500 Internal Server Error"

**Check:**
1. PHP error logs: `tail -f /var/log/apache2/error.log` or check cPanel error logs
2. Verify `.htaccess` syntax is correct
3. Ensure `mod_rewrite` is enabled (Apache)
4. Check PHP version compatibility: `php -v` (needs 7.4+)

**Fix:**
```bash
# Enable mod_rewrite (Apache)
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Issue: "Composer dependencies not found"

**Fix:**
```bash
cd /path/to/atm-recon
composer install --no-dev --optimize-autoloader
```

### Issue: "Permission denied" writing to temp directory

**Fix:**
```bash
chmod -R 755 tmp/
chown -R www-data:www-data tmp/  # Use appropriate web server user
```

### Issue: File uploads fail or timeout

**Check `php.ini` settings:**
```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
memory_limit = 256M
```

**Restart PHP/Web Server:**
```bash
sudo systemctl restart apache2  # or nginx + php-fpm
```

### Issue: CSS/Assets not loading

**Check:**
1. Ensure `.htaccess` in `public/` allows asset access
2. Verify file paths are relative (not hardcoded to localhost)
3. Check browser console for 404 errors

### Issue: Reconciliation returns incorrect results

**Verify:**
1. Excel files are `.xlsx` format (not `.xls` or encrypted)
2. Column headers match expected format (see TROUBLESHOOTING.md)
3. Check debug tools: `debug.php`, `verify.php`, `debug_matching.php`

---

## Security Best Practices

1. **Hide source files:** Point document root to `public/` when possible
2. **Remove debug files:** Delete or restrict `debug*.php` and `verify.php` in production
3. **Use HTTPS:** Install SSL certificate (Let's Encrypt is free)
4. **Restrict access:** Add `.htpasswd` authentication if internal use only
5. **Keep dependencies updated:** Run `composer update` regularly (test first!)
6. **Monitor logs:** Check error logs for suspicious activity

---

## SSL/HTTPS Setup (Let's Encrypt)

For VPS with Certbot:

```bash
# Install Certbot
sudo apt-get update
sudo apt-get install certbot python3-certbot-apache  # or python3-certbot-nginx

# Get certificate
sudo certbot --apache -d yourdomain.com  # or --nginx

# Auto-renewal (should be automatic, verify with)
sudo certbot renew --dry-run
```

---

## Backup Strategy

Regular backups should include:
1. **Database:** N/A (stateless app)
2. **Uploaded files:** If storing processed files (currently uses temp directory)
3. **Application code:** Git repository + deployed files
4. **Configuration:** `.htaccess`, server configs

**Simple backup script:**
```bash
#!/bin/bash
tar -czf atm-recon-backup-$(date +%Y%m%d).tar.gz /var/www/atm-recon/
```

---

## Need Help?

- Check `TROUBLESHOOTING.md` for common issues
- Review `README.md` for architecture details
- Test locally first: `cd public && php -S localhost:8000`

---

**Last Updated:** November 2025