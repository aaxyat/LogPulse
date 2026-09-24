# LogPulse cPanel Deployment Guide

This guide details deploying LogPulse to a shared cPanel hosting account.

## Prerequisites
- cPanel account with PHP 8.1+ support (native standard library: PDO, OpenSSL, cURL, JSON).
- 1 MySQL / MariaDB database and user created in cPanel.
- Subdomain (e.g. `logs.yourdomain.com`) configured with document root set to `public_html/logs/public` (or root mapped to `public`).

---

## Step 1: Upload Files
1. In cPanel **File Manager** (or via Git / SFTP), place project files in your directory (e.g., `/home/youruser/logs`):
   ```
   /home/youruser/logs/
   ├── bin/
   ├── config/
   ├── docs/
   ├── public/       <-- Point subdomain DocumentRoot here
   ├── src/
   └── AGENTS.md
   ```
2. If your cPanel requires all public web files in `public_html/logs`, you can place the application contents there and ensure the web server serves from `/public` or configure `.htaccess`.

---

## Step 2: Configure Database & Environment
1. In cPanel **MySQL Databases**, create:
   - Database: `youruser_logpulse`
   - User: `youruser_loguser`
   - Grant **ALL PRIVILEGES** to the user.
2. Open **phpMyAdmin**, select `youruser_logpulse`, go to **Import**, and upload [`config/schema.sql`](../config/schema.sql).
3. Copy `config/config.example.php` to `config/config.php`:
   ```php
   return [
       'app' => [
           'name' => 'LogPulse',
           'env' => 'production',
           'url' => 'https://logs.yourdomain.com',
           'jwt_secret' => 'ENTER_YOUR_SECURE_64_CHAR_HEX_TOKEN_HERE',
           'jwt_expiry_hours' => 72,
           'timezone' => 'UTC',
       ],
       'database' => [
           'driver' => 'mysql',
           'host' => 'localhost',
           'port' => 3306,
           'database' => 'youruser_logpulse',
           'username' => 'youruser_loguser',
           'password' => 'YourStrongDbPassword',
           'charset' => 'utf8mb4',
       ],
       // ...
   ];
   ```

---

## Step 3: Seed Admin Account
Via cPanel **Terminal** or SSH:
```bash
cd /home/youruser/logs
php bin/console seed:admin admin@yourdomain.com StrongSecretPassword2026! "Super Admin"
```

---

## Step 4: Configure cPanel Cron Job
LogPulse uses standard CLI cron commands for retention pruning, alert triggers, and uptime monitoring without memory-resident background daemons.

In cPanel **Cron Jobs**, add a new cron job:
- **Interval**: Once every 5 minutes (`*/5 * * * *`)
- **Command**:
  ```bash
  /usr/local/bin/php /home/youruser/logs/bin/console cron:run >> /home/youruser/logs/cron.log 2>&1
  ```

---

## Step 5: Verification & Smoke Test
1. Visit `https://logs.yourdomain.com`.
2. Authenticate using your admin email and password.
3. Create an endpoint, copy the ingest cURL command from the **API Console**, and send a test event.
4. Verify the event appears in the live streaming table.
