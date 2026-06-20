# ExamSphere — Production Deployment Checklist

**Stack:** Laravel 12 · PHP 8.3 · MySQL 8.0 · Redis 7 · Nginx · Horizon · Ubuntu 24.04 LTS

> Work through each section in order. Every checkbox must be ticked before proceeding
> to the next section. Commands prefixed with `$` run as your deploy user; `#` as root.

---

## 1. Server Requirements

### 1.1 Minimum Hardware

| Scale | vCPU | RAM | Disk |
|---|---|---|---|
| Up to 1,000 students | 4 vCPU | 16 GB | 100 GB NVMe |
| Up to 5,000 students | 8 vCPU | 32 GB | 200 GB NVMe |
| Up to 10,000 students | 16 vCPU | 64 GB | 500 GB NVMe |
| 10,000–20,000 students | Multi-server (see Section 13) | — | — |

Single-server deployments above 10,000 students are not supported. See `deployment/` for
multi-server architecture.

### 1.2 OS Preparation

```bash
# Update system packages
# apt update && apt upgrade -y

# Set timezone to IST (adjust to your locale)
# timedatectl set-timezone Asia/Kolkata

# Verify NTP sync — critical for offline answer timestamps
# timedatectl status | grep synchronized
# Expected: System clock synchronized: yes

# Set hostname
# hostnamectl set-hostname examsphere-prod

# Create non-root deploy user
# useradd -m -s /bin/bash deploy
# usermod -aG sudo deploy
```

**Checklist:**
- [ ] Ubuntu 24.04 LTS installed and updated
- [ ] NTP synchronisation confirmed active
- [ ] `deploy` user created; sudo access confirmed
- [ ] SSH key-based login configured for `deploy` user
- [ ] Root password login disabled in `/etc/ssh/sshd_config`

---

## 2. PHP Requirements

### 2.1 Install PHP 8.3 and Required Extensions

```bash
# Add Ondřej Surý PPA (provides PHP 8.3 on Ubuntu 24.04)
# apt install -y software-properties-common
# add-apt-repository ppa:ondrej/php -y
# apt update

# Install PHP 8.3 and all required extensions
# apt install -y \
#   php8.3-fpm \
#   php8.3-cli \
#   php8.3-mysql \
#   php8.3-redis \
#   php8.3-mbstring \
#   php8.3-xml \
#   php8.3-curl \
#   php8.3-zip \
#   php8.3-gd \
#   php8.3-bcmath \
#   php8.3-intl \
#   php8.3-tokenizer \
#   php8.3-fileinfo \
#   php8.3-opcache

# Verify php-redis is the C extension (not Predis)
# php8.3 -m | grep redis
# Expected output: redis
```

### 2.2 PHP-FPM Pool Configuration

Create `/etc/php/8.3/fpm/pool.d/examsphere.conf`:

```ini
; ── Main exam pool ────────────────────────────────────────────────────────────
[examsphere]
user  = deploy
group = deploy

listen = /run/php/php8.3-fpm-exam.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

; Dynamic scaling — prevents cold-start latency on exam open
pm                   = dynamic
pm.max_children      = 500
pm.start_servers     = 50
pm.min_spare_servers = 50
pm.max_spare_servers = 100

; Prevents memory leaks from accumulating across thousands of requests
pm.max_requests = 1000

; Health status endpoint (Nginx polls this for monitoring)
pm.status_path = /fpm-status

access.log = /var/log/php/examsphere-access.log
slowlog    = /var/log/php/examsphere-slow.log
request_slowlog_timeout = 5s

php_admin_value[error_log]          = /var/log/php/examsphere-error.log
php_admin_flag[log_errors]          = on
php_admin_value[memory_limit]       = 256M
php_admin_value[max_execution_time] = 300
php_admin_value[upload_max_filesize]= 50M
php_admin_value[post_max_size]      = 55M
```

Create `/etc/php/8.3/fpm/pool.d/examsphere-monitoring.conf`:

```ini
; ── Dedicated monitoring/SSE pool ─────────────────────────────────────────────
; Prevents 200 permanent SSE connections from consuming workers
; needed for student exam traffic.
[examsphere-monitoring]
user  = deploy
group = deploy

listen = /run/php/php8.3-fpm-monitoring.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

pm                   = static
pm.max_children      = 50
pm.max_requests      = 0

php_admin_value[error_log]          = /var/log/php/monitoring-error.log
php_admin_value[memory_limit]       = 128M
php_admin_value[max_execution_time] = 0
```

Remove the default pool:

```bash
# rm /etc/php/8.3/fpm/pool.d/www.conf
# mkdir -p /var/log/php
# chown deploy:deploy /var/log/php
# systemctl restart php8.3-fpm
# systemctl enable php8.3-fpm
```

### 2.3 PHP OPcache Configuration

Edit `/etc/php/8.3/fpm/conf.d/10-opcache.ini`:

```ini
opcache.enable                 = 1
opcache.enable_cli             = 0
opcache.memory_consumption     = 256
opcache.interned_strings_buffer= 16
opcache.max_accelerated_files  = 20000
opcache.revalidate_freq        = 0
opcache.validate_timestamps    = 0
; Set to 1 during deployments, revert to 0 afterward
opcache.save_comments          = 1
opcache.fast_shutdown          = 1
```

**Checklist:**
- [ ] `php8.3 -v` shows PHP 8.3.x
- [ ] `php8.3 -m | grep redis` outputs `redis` (C extension, not Predis wrapper)
- [ ] `php8.3 -m | grep opcache` outputs `Zend OPcache`
- [ ] Both FPM sockets exist: `/run/php/php8.3-fpm-exam.sock` and `-monitoring.sock`
- [ ] `systemctl status php8.3-fpm` shows active (running)

---

## 3. MySQL Configuration

### 3.1 Install MySQL 8.0

```bash
# apt install -y mysql-server
# systemctl enable mysql
# systemctl start mysql
# mysql_secure_installation
```

### 3.2 Apply Production Configuration

```bash
# cp /path/to/repo/deployment/mysql-production.cnf /etc/mysql/conf.d/examsphere.cnf
# systemctl restart mysql

# Verify critical settings applied:
# mysql -u root -p -e "
#   SELECT VARIABLE_NAME, VARIABLE_VALUE
#   FROM performance_schema.global_variables
#   WHERE VARIABLE_NAME IN (
#     'innodb_buffer_pool_size',
#     'innodb_flush_log_at_trx_commit',
#     'max_connections',
#     'innodb_io_capacity'
#   );"
```

Expected output:

| Variable | Expected Value |
|---|---|
| `innodb_buffer_pool_size` | 17179869184 (16G) |
| `innodb_flush_log_at_trx_commit` | 2 |
| `max_connections` | 700 |
| `innodb_io_capacity` | 4000 |

### 3.3 Create Database and Application User

```sql
-- Run as root in mysql shell
CREATE DATABASE examsphere CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'examsphere'@'127.0.0.1' IDENTIFIED BY 'STRONG_PASSWORD_HERE';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW,
      SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER
  ON examsphere.*
  TO 'examsphere'@'127.0.0.1';

-- Required for ExamMonitoringService::getRecoveryStatus() MySQL health check
GRANT PROCESS ON *.* TO 'examsphere'@'127.0.0.1';

FLUSH PRIVILEGES;
```

### 3.4 Verify Connection from Application User

```bash
# mysql -u examsphere -p -h 127.0.0.1 examsphere -e "SELECT 1;"
# Expected: 1
```

**Checklist:**
- [ ] MySQL 8.0 installed and running
- [ ] `deployment/mysql-production.cnf` applied and verified with `SHOW VARIABLES`
- [ ] `examsphere` database created with `utf8mb4_unicode_ci` collation
- [ ] `examsphere` DB user created with minimum required grants
- [ ] `PROCESS` privilege granted (required for health monitoring endpoint)
- [ ] Application user can connect: `mysql -u examsphere -p -h 127.0.0.1`

---

## 4. Redis Configuration

### 4.1 Install Redis 7

```bash
# apt install -y redis-server
# redis-server --version
# Expected: Redis server v=7.x
```

### 4.2 Apply Production Configuration

Edit `/etc/redis/redis.conf`:

```conf
# ── Bind and authentication ───────────────────────────────────────────────────
bind 127.0.0.1 ::1
protected-mode yes
requirepass REDIS_STRONG_PASSWORD_HERE

# ── Memory ────────────────────────────────────────────────────────────────────
# For 10,000 concurrent students: ~2 GB exam state + monitoring keys.
# Adjust based on available RAM after MySQL buffer pool and FPM workers.
maxmemory 4gb

# CRITICAL: never silently evict exam state keys.
# If memory is full, Redis returns an error (caught by circuit breaker)
# rather than discarding active student data.
maxmemory-policy noeviction

# ── AOF Persistence ───────────────────────────────────────────────────────────
# Survives Redis restart without losing active exam state.
# Without AOF, a Redis crash during an exam requires running
# php artisan examsphere:warmup-exam-cache to rebuild from MySQL.
appendonly yes
appendfilename "appendonly.aof"
appendfsync everysec
no-appendfsync-on-rewrite no
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb

# ── RDB snapshot (secondary durability) ──────────────────────────────────────
save 900 1
save 300 10
save 60 10000
dbfilename dump.rdb
dir /var/lib/redis

# ── Performance ───────────────────────────────────────────────────────────────
tcp-keepalive 300
timeout 300
tcp-backlog 511
hz 15

# ── Logging ───────────────────────────────────────────────────────────────────
loglevel notice
logfile /var/log/redis/redis-server.log
```

```bash
# systemctl restart redis-server
# systemctl enable redis-server

# Verify AOF is active:
# redis-cli -a REDIS_STRONG_PASSWORD_HERE CONFIG GET appendonly
# Expected: appendonly yes

# Verify noeviction policy:
# redis-cli -a REDIS_STRONG_PASSWORD_HERE CONFIG GET maxmemory-policy
# Expected: noeviction

# Test authentication:
# redis-cli -a REDIS_STRONG_PASSWORD_HERE PING
# Expected: PONG
```

### 4.3 Verify Database Separation

ExamSphere uses four Redis databases. Confirm they are all accessible:

```bash
# for db in 0 1 2 3; do
#   redis-cli -a REDIS_STRONG_PASSWORD_HERE -n $db PING
# done
# All four should return: PONG
```

**Checklist:**
- [ ] Redis 7.x installed
- [ ] `requirepass` set to a strong password (20+ characters, stored in vault)
- [ ] `maxmemory-policy noeviction` confirmed
- [ ] `appendonly yes` confirmed — AOF file exists at `/var/lib/redis/appendonly.aof`
- [ ] `redis-cli PING` returns `PONG` with the correct password
- [ ] All 4 database indices (0–3) accessible

---

## 5. Horizon Configuration

### 5.1 Install Composer and Application Dependencies

```bash
$ cd /var/www/examsphere
$ composer install --no-dev --optimize-autoloader --no-interaction
```

### 5.2 Supervisor — Horizon Process Manager

```bash
# apt install -y supervisor
# systemctl enable supervisor
```

Create `/etc/supervisor/conf.d/examsphere-horizon.conf`:

```ini
[program:examsphere-horizon]
process_name = %(program_name)s
command      = php /var/www/examsphere/artisan horizon
autostart    = true
autorestart  = true
user         = deploy
redirect_stderr  = true
stdout_logfile   = /var/log/supervisor/horizon.log
stdout_logfile_maxbytes = 50MB
stdout_logfile_backups  = 5
stopwaitsecs = 3600
```

```bash
# supervisorctl reread
# supervisorctl update
# supervisorctl start examsphere-horizon

# Verify Horizon is running:
# supervisorctl status examsphere-horizon
# Expected: examsphere-horizon RUNNING pid XXXXX, uptime 0:00:XX
```

### 5.3 Verify Worker Counts After Start

```bash
$ php artisan horizon:status
```

Expected output shows all supervisors running with correct `maxProcesses`:

| Supervisor | Min | Max |
|---|---|---|
| `results-supervisor` | 5 | 50 |
| `analytics-supervisor` | 2 | 10 |
| `notifications-supervisor` | 1 | 6 |
| `monitoring-supervisor` | 1 | 2 |
| `default-supervisor` | 1 | 4 |

**Checklist:**
- [ ] Supervisor installed and enabled
- [ ] `/etc/supervisor/conf.d/examsphere-horizon.conf` created
- [ ] `supervisorctl status examsphere-horizon` shows `RUNNING`
- [ ] `php artisan horizon:status` shows all 5 supervisors active
- [ ] Horizon dashboard accessible at `/horizon` (restricted — see Section 11)

---

## 6. Nginx Configuration

### 6.1 Install Nginx

```bash
# apt install -y nginx
# systemctl enable nginx
```

### 6.2 Main Server Block

Create `/etc/nginx/sites-available/examsphere`:

```nginx
# ── Rate limiting zones ───────────────────────────────────────────────────────
# These mirror the Laravel rate limiters in AppServiceProvider.
# Nginx-level limits protect PHP-FPM from obvious abuse before Laravel sees it.
limit_req_zone $binary_remote_addr zone=login:10m    rate=10r/m;
limit_req_zone $binary_remote_addr zone=public:10m   rate=60r/m;

# ── Upstream — exam traffic pool ──────────────────────────────────────────────
upstream php-fpm-exam {
    server unix:/run/php/php8.3-fpm-exam.sock;
    keepalive 64;
}

# ── Upstream — monitoring/SSE pool ───────────────────────────────────────────
upstream php-fpm-monitoring {
    server unix:/run/php/php8.3-fpm-monitoring.sock;
    keepalive 32;
}

# ── HTTP → HTTPS redirect ─────────────────────────────────────────────────────
server {
    listen 80;
    listen [::]:80;
    server_name examsphere.in www.examsphere.in;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

# ── HTTPS main server ─────────────────────────────────────────────────────────
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name examsphere.in www.examsphere.in;

    root  /var/www/examsphere/public;
    index index.php;

    # ── SSL ───────────────────────────────────────────────────────────────────
    ssl_certificate     /etc/letsencrypt/live/examsphere.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/examsphere.in/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache   shared:SSL:10m;
    ssl_stapling        on;
    ssl_stapling_verify on;

    # ── Security headers ──────────────────────────────────────────────────────
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options            DENY                                  always;
    add_header X-Content-Type-Options     nosniff                               always;
    add_header X-XSS-Protection           "1; mode=block"                       always;
    add_header Referrer-Policy            "strict-origin-when-cross-origin"     always;
    add_header Permissions-Policy         "camera=(), microphone=(), geolocation=()" always;

    # ── Upload limits ─────────────────────────────────────────────────────────
    client_max_body_size 55M;

    # ── Gzip ──────────────────────────────────────────────────────────────────
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript
               text/xml application/xml image/svg+xml;

    # ── Static assets ─────────────────────────────────────────────────────────
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|webp)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    # ── SSE monitoring stream — dedicated FPM pool ────────────────────────────
    # Each SSE connection holds one FPM worker for its lifetime.
    # The dedicated pool prevents SSE from consuming exam-traffic workers.
    location ~ ^/api/monitoring/exams/\d+/stream$ {
        try_files $uri /index.php$is_args$args;
        fastcgi_pass   php-fpm-monitoring;
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;

        # SSE-specific: disable all buffering so events reach the client immediately.
        # fastcgi_buffering off is the correct directive for FastCGI upstream buffering.
        # proxy_buffering off handles any reverse-proxy layer placed in front of Nginx.
        # X-Accel-Buffering is intentionally omitted: any add_header directive in a
        # child location block overrides ALL parent add_header directives (Nginx rule) —
        # using it here would drop HSTS and other security headers from SSE responses.
        fastcgi_buffering          off;
        fastcgi_read_timeout       0;
        proxy_buffering            off;
        proxy_cache                off;

        # Security headers must be restated here because Nginx does not inherit
        # add_header from parent blocks when a child location defines its own.
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header X-Frame-Options            DENY                                  always;
        add_header X-Content-Type-Options     nosniff                               always;
        add_header X-XSS-Protection           "1; mode=block"                       always;
        add_header Referrer-Policy            "strict-origin-when-cross-origin"     always;
        add_header Permissions-Policy         "camera=(), microphone=(), geolocation=()" always;
    }

    # ── Monitoring endpoints — dedicated FPM pool ─────────────────────────────
    location ~ ^/api/monitoring/ {
        try_files $uri /index.php$is_args$args;
        fastcgi_pass   php-fpm-monitoring;
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 60;
    }

    # ── Login endpoint — tighter Nginx rate limit ─────────────────────────────
    location = /api/login {
        limit_req zone=login burst=5 nodelay;
        try_files $uri /index.php$is_args$args;
        fastcgi_pass   php-fpm-exam;
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # ── Laravel application — main FPM pool ───────────────────────────────────
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri /index.php =404;
        fastcgi_pass   php-fpm-exam;
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param  PATH_INFO       $fastcgi_path_info;
        fastcgi_read_timeout 300;
        fastcgi_buffering on;
    }

    # ── Block sensitive paths ──────────────────────────────────────────────────
    location ~ /\.(?!well-known) {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~ \.(env|log|gitignore|gitattributes|htaccess)$ {
        deny all;
        access_log off;
        log_not_found off;
    }

    # ── Access and error logs ─────────────────────────────────────────────────
    access_log /var/log/nginx/examsphere-access.log combined buffer=16k flush=5s;
    error_log  /var/log/nginx/examsphere-error.log warn;
}
```

```bash
# ln -s /etc/nginx/sites-available/examsphere /etc/nginx/sites-enabled/examsphere
# rm -f /etc/nginx/sites-enabled/default
# nginx -t
# Expected: syntax is ok / test is successful
# systemctl reload nginx
```

**Checklist:**
- [ ] Nginx installed and running
- [ ] `/etc/nginx/sites-available/examsphere` created
- [ ] Symlink in `sites-enabled` exists
- [ ] `nginx -t` passes with no errors
- [ ] SSE endpoint routes to `php-fpm-monitoring` upstream
- [ ] Login endpoint has `limit_req zone=login`
- [ ] `.env` and `.log` paths return 403

---

## 7. SSL Setup

### 7.1 Obtain Let's Encrypt Certificate

```bash
# apt install -y certbot python3-certbot-nginx

# Obtain certificate (DNS must resolve to this server first)
# certbot --nginx -d examsphere.in -d www.examsphere.in \
#         --email admin@examsphere.in \
#         --agree-tos \
#         --no-eff-email

# Verify certificate:
# certbot certificates
# Expected: domains: examsphere.in, www.examsphere.in
#           expiry date: (90 days from today)
```

### 7.2 Auto-Renewal

```bash
# Test renewal without actually renewing:
# certbot renew --dry-run

# Certbot adds its own cron or systemd timer automatically.
# Verify it exists:
# systemctl list-timers | grep certbot
# or:
# crontab -l -u root | grep certbot
```

### 7.3 Verify HTTPS

```bash
# curl -I https://examsphere.in/api/monitoring/health
# Confirm:
#   HTTP/2 200
#   strict-transport-security: max-age=31536000
#   x-frame-options: DENY
```

**Checklist:**
- [ ] Certificate issued for all domains
- [ ] HTTP redirects to HTTPS (test with `curl -I http://examsphere.in`)
- [ ] `strict-transport-security` header present in response
- [ ] Auto-renewal dry run passes
- [ ] SSL Labs test score A or higher: https://www.ssllabs.com/ssltest/

---

## 8. Backup Strategy

### 8.1 MySQL Automated Backups

Create `/etc/cron.d/examsphere-backup`:

```cron
# Daily MySQL backup — 2 AM IST
0 20 * * * root /usr/local/bin/examsphere-mysql-backup.sh >> /var/log/examsphere-backup.log 2>&1
```

**Step 1 — Create a protected MySQL credentials file** (password never appears in shell arguments or process list):

```bash
# Create credentials file readable only by root
# cat > /etc/mysql/.examsphere-backup.cnf << 'EOF'
# [client]
# user     = examsphere
# password = STRONG_PASSWORD_HERE
# host     = 127.0.0.1
# EOF
# chmod 600 /etc/mysql/.examsphere-backup.cnf
# chown root:root /etc/mysql/.examsphere-backup.cnf
```

**Step 2 — Create `/usr/local/bin/examsphere-mysql-backup.sh`:**

```bash
#!/bin/bash
set -euo pipefail

DATE=$(date +%Y-%m-%d)
BACKUP_DIR=/var/backups/examsphere/mysql
CREDENTIALS_FILE=/etc/mysql/.examsphere-backup.cnf
DB_NAME=examsphere
RETAIN_DAYS=14

mkdir -p "$BACKUP_DIR"

# Dump with consistent snapshot (InnoDB-safe).
# --defaults-file reads credentials from a root-only file (chmod 600).
# This prevents the database password from appearing in `ps aux` output
# or shell history, and avoids issues with special characters in passwords.
mysqldump \
  --defaults-file="$CREDENTIALS_FILE" \
  --host=127.0.0.1 \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  "$DB_NAME" | gzip > "$BACKUP_DIR/examsphere-$DATE.sql.gz"

# Remove backups older than RETAIN_DAYS
find "$BACKUP_DIR" -name "*.sql.gz" -mtime "+$RETAIN_DAYS" -delete

echo "[$(date)] Backup complete: examsphere-$DATE.sql.gz ($(du -sh "$BACKUP_DIR/examsphere-$DATE.sql.gz" | cut -f1))"
```

```bash
# Restrict the script so only root can read or execute it
# chmod 700 /usr/local/bin/examsphere-mysql-backup.sh
# chown root:root /usr/local/bin/examsphere-mysql-backup.sh
# /usr/local/bin/examsphere-mysql-backup.sh    # run once to verify
# ls -lh /var/backups/examsphere/mysql/
```

### 8.2 Redis Backup

Redis AOF provides continuous durability. For off-site backup:

```cron
# Copy Redis RDB snapshot daily at 3 AM IST
0 21 * * * root cp /var/lib/redis/dump.rdb /var/backups/examsphere/redis/dump-$(date +\%Y-\%m-\%d).rdb
```

### 8.3 Application Files Backup

```bash
# Backup .env and storage/ daily (exclude logs)
# Files that matter: .env, storage/app/, public/storage/
```

Create `/usr/local/bin/examsphere-files-backup.sh`:

```bash
#!/bin/bash
set -euo pipefail

DATE=$(date +%Y-%m-%d)
BACKUP_DIR=/var/backups/examsphere/files
APP_DIR=/var/www/examsphere

mkdir -p "$BACKUP_DIR"

tar --exclude="$APP_DIR/storage/logs" \
    --exclude="$APP_DIR/storage/framework/cache" \
    --exclude="$APP_DIR/storage/framework/sessions" \
    --exclude="$APP_DIR/node_modules" \
    --exclude="$APP_DIR/vendor" \
    -czf "$BACKUP_DIR/files-$DATE.tar.gz" \
    "$APP_DIR/.env" \
    "$APP_DIR/storage/app/"

# Retain 7 days
find "$BACKUP_DIR" -name "files-*.tar.gz" -mtime +7 -delete

echo "[$(date)] Files backup complete: files-$DATE.tar.gz"
```

### 8.4 Off-Site Replication

```bash
# Sync backups to remote storage daily at 4 AM IST
# Option A: AWS S3
# aws s3 sync /var/backups/examsphere/ s3://your-bucket/examsphere-backups/ --delete

# Option B: rsync to a secondary server
# rsync -az /var/backups/examsphere/ backup-user@backup-server:/backups/examsphere/
```

### 8.5 Backup Verification

Verify backups monthly by restoring to a test instance:

```bash
# Restore test:
# gunzip < /var/backups/examsphere/mysql/examsphere-2026-06-19.sql.gz \
#   | mysql -u root -p examsphere_restore_test
# Expected: no errors
```

**Checklist:**
- [ ] Daily MySQL backup cron active and verified to produce `.sql.gz` file
- [ ] Redis AOF confirmed writing (`ls -lh /var/lib/redis/appendonly.aof`)
- [ ] Application files backup script runs successfully
- [ ] Off-site replication configured (S3 or secondary server)
- [ ] Backup restoration tested on a separate instance at least once

---

## 9. Monitoring Setup

### 9.1 Cron Health Check

The `examsphere:auto-submit-expired` command writes a heartbeat to Redis on every run. Poll `GET /api/monitoring/health` to surface it:

```bash
# curl -s https://examsphere.in/api/monitoring/health | python3 -m json.tool
# Confirm: "cron": { "auto_submit_status": "ok" }
```

Set up an external uptime monitor (UptimeRobot, BetterStack, or AWS CloudWatch) to:
- Poll `GET /api/monitoring/health` every 60 seconds
- Alert if `cron.auto_submit_status != "ok"`
- Alert if HTTP status is not 200

### 9.2 Horizon Dashboard Access

Restrict the `/horizon` dashboard to specific IPs via `.env`:

```bash
HORIZON_ALLOWED_IPS=YOUR_OFFICE_IP,YOUR_VPN_IP
```

Access the Horizon dashboard to verify all supervisors show as running.

### 9.3 Queue Depth Monitoring

```bash
# Check queue lengths (run during an active exam):
$ php artisan horizon:status

# Check pending job count per queue:
# redis-cli -a PASSWORD llen queues:results
# redis-cli -a PASSWORD llen queues:analytics
```

Alert threshold (from `config/horizon.php`):
- `redis:results` wait > 3 seconds → critical
- `redis:analytics` wait > 300 seconds → warning

### 9.4 Failed Job Monitoring

```bash
# Count critical failed jobs:
$ php artisan examsphere:recover-failed-jobs --stats

# Alert if critical failed jobs >= 5 (config: DLQ_ALERT_THRESHOLD)
```

### 9.5 Recovery Dashboard

During or after an exam, check system health:

```bash
# curl -s -H "Authorization: Bearer TOKEN" \
#   https://examsphere.in/api/monitoring/recovery | python3 -m json.tool
```

This endpoint shows circuit breaker states, failed job counts by category,
MySQL thread count, Redis memory, and an overall status (`healthy|degraded|critical`).

**Checklist:**
- [ ] External uptime monitor polling `/api/monitoring/health` every 60 seconds
- [ ] Alert configured for `cron.auto_submit_status != "ok"`
- [ ] Horizon dashboard accessible from office IP (not public internet)
- [ ] Recovery endpoint accessible and returning `"overall_status": "healthy"`

---

## 10. Log Rotation

### 10.1 Laravel Application Logs

Laravel is configured with the `daily` log channel. logrotate handles pruning.

Create `/etc/logrotate.d/examsphere`:

```
/var/www/examsphere/storage/logs/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    sharedscripts
    postrotate
        /bin/kill -USR1 $(cat /run/php/php8.3-fpm.pid 2>/dev/null) 2>/dev/null || true
    endscript
}
```

### 10.2 Nginx Logs

Create `/etc/logrotate.d/nginx-examsphere`:

```
/var/log/nginx/examsphere-*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    sharedscripts
    postrotate
        if [ -f /run/nginx.pid ]; then
            kill -USR1 $(cat /run/nginx.pid)
        fi
    endscript
}
```

### 10.3 MySQL Slow Query Log

```
/var/log/mysql/slow.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    postrotate
        mysqladmin -u root -pROOT_PASSWORD flush-logs
    endscript
}
```

### 10.4 PHP-FPM and Supervisor Logs

```
/var/log/php/*.log /var/log/supervisor/horizon.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
}
```

```bash
# Test logrotate configuration:
# logrotate -d /etc/logrotate.d/examsphere
# Expected: no errors, shows what would be rotated
```

**Checklist:**
- [ ] `/etc/logrotate.d/examsphere` created and tested with `-d` flag
- [ ] `/etc/logrotate.d/nginx-examsphere` created
- [ ] MySQL slow query log rotation configured
- [ ] PHP-FPM and Supervisor log rotation configured
- [ ] `logrotate --force /etc/logrotate.d/examsphere` runs without errors

---

## 11. Security Hardening

### 11.1 UFW Firewall

```bash
# ufw default deny incoming
# ufw default allow outgoing
# ufw allow ssh
# ufw allow 80/tcp
# ufw allow 443/tcp
# ufw enable
# ufw status
```

Deny all other incoming ports including MySQL (3306) and Redis (6379) — both are bound to `127.0.0.1` only.

### 11.2 SSH Hardening

Edit `/etc/ssh/sshd_config`:

```
PermitRootLogin         no
PasswordAuthentication  no
PubkeyAuthentication    yes
AllowUsers              deploy
MaxAuthTries            3
LoginGraceTime          30
```

```bash
# systemctl restart sshd
```

### 11.3 File Permissions

```bash
# Ownership: deploy user owns the application, www-data can read
$ sudo chown -R deploy:www-data /var/www/examsphere
$ sudo find /var/www/examsphere -type f -exec chmod 644 {} \;
$ sudo find /var/www/examsphere -type d -exec chmod 755 {} \;

# Storage and bootstrap/cache must be writable by the web process
$ sudo chmod -R 775 /var/www/examsphere/storage
$ sudo chmod -R 775 /var/www/examsphere/bootstrap/cache

# .env must not be readable by group or world
$ sudo chmod 600 /var/www/examsphere/.env
$ sudo chown deploy:deploy /var/www/examsphere/.env
```

### 11.4 Protect `.env`

The Nginx config already blocks `.env` access. Double-check:

```bash
# curl -I https://examsphere.in/.env
# Expected: 403 Forbidden (or 404)
```

Never commit `.env` to version control. Store production secrets in a password manager or vault and copy manually to the server.

### 11.5 PHP Security Settings

Add to `/etc/php/8.3/fpm/conf.d/99-security.ini`:

```ini
expose_php              = Off
display_errors          = Off
log_errors              = On
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
```

### 11.6 MySQL User Restriction

The `examsphere` DB user should only connect from `127.0.0.1`, never from `%`:

```sql
-- Verify no wildcard host bindings:
SELECT user, host FROM mysql.user WHERE user = 'examsphere';
-- Expected: only 127.0.0.1 row exists
```

### 11.7 Fail2Ban (Brute-Force Protection)

```bash
# apt install -y fail2ban

# Create /etc/fail2ban/jail.d/examsphere.conf:
```

```ini
[nginx-limit-req]
enabled  = true
port     = http,https
filter   = nginx-limit-req
logpath  = /var/log/nginx/examsphere-error.log
maxretry = 10
bantime  = 600
findtime = 60
```

```bash
# systemctl restart fail2ban
# fail2ban-client status nginx-limit-req
```

**Checklist:**
- [ ] UFW active: only ports 22, 80, 443 open
- [ ] Root SSH login disabled; password authentication disabled
- [ ] `.env` permissions are `600`, owned by `deploy`
- [ ] `curl https://examsphere.in/.env` returns 403
- [ ] `expose_php = Off` confirmed: `curl -I https://examsphere.in` shows no `X-Powered-By: PHP` header
- [ ] Fail2Ban installed and `nginx-limit-req` jail active
- [ ] MySQL `examsphere` user only accessible from `127.0.0.1`

---

## 12. Deployment Commands

### 12.1 First Deployment

Run once on initial setup:

```bash
# ── As deploy user ────────────────────────────────────────────────────────────

# 1. Clone the repository
$ git clone git@github.com:your-org/examsphere.git /var/www/examsphere
$ cd /var/www/examsphere

# 2. Install dependencies (production, no dev packages)
$ composer install --no-dev --optimize-autoloader --no-interaction

# 3. Copy and configure environment
$ cp .env.example .env
# Edit .env with production values:
#   APP_ENV=production
#   APP_DEBUG=false
#   APP_URL=https://examsphere.in
#   DB_HOST=127.0.0.1
#   DB_DATABASE=examsphere
#   DB_USERNAME=examsphere
#   DB_PASSWORD=STRONG_PASSWORD
#   REDIS_HOST=127.0.0.1
#   REDIS_PASSWORD=REDIS_STRONG_PASSWORD
#   REDIS_CLIENT=phpredis
#   QUEUE_CONNECTION=redis
#   SESSION_DRIVER=redis
#   CACHE_STORE=redis

# 4. Generate application key (do this ONCE — never regenerate on a running system)
$ php artisan key:generate

# 5. Run all migrations
$ php artisan migrate --force

# Verify migrations ran:
$ php artisan migrate:status | tail -20

# 6. Seed super admin (first deploy only)
$ php artisan db:seed --class=SuperAdminSeeder --force

# 7. Optimise the framework (caches routes, views, config)
$ php artisan optimize

# 8. Set correct permissions
$ sudo chown -R deploy:www-data /var/www/examsphere
$ sudo chmod -R 775 /var/www/examsphere/storage
$ sudo chmod -R 775 /var/www/examsphere/bootstrap/cache
$ sudo chmod 600 /var/www/examsphere/.env

# 9. Restart services
$ sudo systemctl restart php8.3-fpm
$ sudo systemctl reload nginx
$ sudo supervisorctl restart examsphere-horizon

# 10. Verify cron is configured
$ crontab -l
# Must include: * * * * * cd /var/www/examsphere && php artisan schedule:run >> /dev/null 2>&1
# If missing, add it:
$ (crontab -l 2>/dev/null; echo "* * * * * cd /var/www/examsphere && php artisan schedule:run >> /dev/null 2>&1") | crontab -

# 11. Confirm all systems healthy
$ php artisan horizon:status
$ curl -s https://examsphere.in/api/monitoring/health | python3 -m json.tool
```

### 12.2 Routine Code Deployment (Zero-Downtime)

Use this procedure for every subsequent release:

```bash
$ cd /var/www/examsphere

# ── Step 1: Pull new code ─────────────────────────────────────────────────────
$ git fetch origin
$ git status
# Confirm no uncommitted local changes before pulling
$ git pull origin main

# ── Step 2: Install/update Composer dependencies ──────────────────────────────
$ composer install --no-dev --optimize-autoloader --no-interaction

# ── Step 3: Run any new migrations ───────────────────────────────────────────
$ php artisan migrate --force

# ── Step 4: Clear and rebuild all caches ─────────────────────────────────────
$ php artisan optimize:clear
$ php artisan optimize
# optimize bundles: config:cache + route:cache + view:cache + event:cache

# ── Step 5: Restart Horizon ───────────────────────────────────────────────────
# horizon:terminate waits for in-progress jobs to complete before stopping.
# Supervisor restarts it automatically with the new code.
$ php artisan horizon:terminate
# Wait 10 seconds for Supervisor to restart Horizon, then verify:
$ supervisorctl status examsphere-horizon

# ── Step 6: Reload PHP-FPM (graceful — no active requests dropped) ────────────
$ sudo systemctl reload php8.3-fpm

# ── Step 7: Reload Nginx config if it changed ─────────────────────────────────
$ sudo nginx -t && sudo systemctl reload nginx

# ── Step 8: Verify deployment ─────────────────────────────────────────────────
$ curl -s https://examsphere.in/api/monitoring/health | python3 -m json.tool
# Confirm: overall_status healthy, cron ok, redis connected
```

### 12.3 Post-Deployment Smoke Test

Run after every deployment, before opening to students:

```bash
# 1. Health endpoint returns 200
$ curl -o /dev/null -s -w "%{http_code}" https://examsphere.in/api/monitoring/health
# Expected: 200

# 2. Cron heartbeat is recent
$ php artisan examsphere:circuit-status

# 3. No critical failed jobs
$ php artisan examsphere:recover-failed-jobs --stats

# 4. Horizon shows all supervisors running
$ php artisan horizon:status

# 5. Queue is draining (no stuck results)
# redis-cli -a PASSWORD llen queues:results
# Expected: 0 or very low during off-peak

# 6. Test login endpoint responds (should NOT expose debug info)
$ curl -s -X POST https://examsphere.in/api/login \
    -H "Content-Type: application/json" \
    -d '{"username":"invalid","password":"invalid"}' | python3 -m json.tool
# Expected: {"message": "Invalid credentials"} — NOT a stack trace
```

---

## 13. Rollback Procedure

### 13.1 Code Rollback

```bash
$ cd /var/www/examsphere

# Find the previous commit hash
$ git log --oneline -10

# Hard reset to the previous release tag or commit
$ git reset --hard <previous-commit-hash>

# Rebuild caches for the previous version
$ composer install --no-dev --optimize-autoloader --no-interaction
$ php artisan optimize:clear
$ php artisan optimize

# Restart Horizon and reload PHP-FPM
$ php artisan horizon:terminate
$ sudo systemctl reload php8.3-fpm

# Verify rollback
$ curl -o /dev/null -s -w "%{http_code}" https://examsphere.in/api/monitoring/health
```

### 13.2 Database Rollback

**IMPORTANT:** Only roll back a migration if no student data has been written to the affected tables since the migration ran. In an exam context, rolling back migrations is rarely safe. Prefer forward-only fixes (additive migrations).

```bash
# Roll back the single most recent migration:
$ php artisan migrate:rollback --step=1

# Roll back to a specific migration batch:
$ php artisan migrate:status         # identify batch numbers
$ php artisan migrate:rollback --batch=5

# Verify the rolled-back state:
$ php artisan migrate:status
```

If a migration cannot be safely rolled back and has corrupted data, restore from the MySQL backup taken before the deployment:

```bash
# DESTRUCTIVE — only if rollback is not possible:
# mysql -u root -p examsphere < /var/backups/examsphere/mysql/examsphere-YYYY-MM-DD.sql
```

### 13.3 Redis Cache Rollback

If a Redis key format change (e.g., from a Feature deployment) causes errors:

```bash
# Flush only the exam state keys (DB0) — does NOT affect sessions (DB3) or queue (DB2)
# redis-cli -a PASSWORD -n 0 FLUSHDB

# After flushing, rebuild exam state for active attempts:
$ php artisan examsphere:warmup-exam-cache

# Verify warmup completed:
$ php artisan examsphere:circuit-status
```

**Never run `FLUSHALL`** on production — this wipes sessions, queues, and all databases simultaneously, logging out all users and discarding queued jobs.

### 13.4 Horizon Worker Rollback

If a new Horizon configuration causes workers to crash:

```bash
# Revert config/horizon.php to previous values via git
$ git checkout <previous-commit> -- config/horizon.php

# Terminate and restart
$ php artisan horizon:terminate
$ supervisorctl restart examsphere-horizon
$ php artisan horizon:status
```

### 13.5 Rollback Decision Matrix

| Scenario | Action |
|---|---|
| Code bug with no migration | `git reset --hard` + cache rebuild |
| Code bug with additive migration (new column) | `git reset --hard` + `migrate:rollback --step=1` |
| Code bug with destructive migration (dropped column) | Restore from MySQL backup |
| Redis key format change breaks active exams | `FLUSHDB` on DB0 + `warmup-exam-cache` |
| Horizon workers crashing | Revert `config/horizon.php` + `horizon:terminate` |
| Nginx config broke routing | `git checkout -- deployment/` + `nginx -t` + `nginx reload` |

---

## Final Pre-Launch Gate

Before onboarding the first college, confirm every item below:

```
SERVER
[ ] Server RAM, CPU, disk meet specifications for target student count
[ ] NTP synchronisation confirmed active (timedatectl)
[ ] UFW firewall active — only ports 22, 80, 443 open

PHP
[ ] PHP 8.3 with phpredis C extension (not Predis)
[ ] OPcache enabled with validate_timestamps=0
[ ] Both FPM pools running (exam + monitoring)

MYSQL
[ ] innodb_buffer_pool_size confirmed (not 128MB default)
[ ] innodb_flush_log_at_trx_commit = 2 confirmed
[ ] max_connections = 700 confirmed
[ ] PROCESS privilege granted to application user
[ ] Daily backup running and verified with a restore test

REDIS
[ ] requirepass set
[ ] maxmemory-policy = noeviction confirmed
[ ] appendonly = yes confirmed, AOF file exists

HORIZON
[ ] results-supervisor maxProcesses = 50 confirmed
[ ] All 5 supervisors running in php artisan horizon:status
[ ] Horizon dashboard restricted to office IPs

NGINX
[ ] HTTPS redirect working
[ ] SSL certificate valid (90 days)
[ ] Security headers present (HSTS, X-Frame-Options)
[ ] SSE endpoint routes to monitoring pool
[ ] .env returns 403

CRON
[ ] schedule:run in crontab
[ ] /api/monitoring/health shows cron.auto_submit_status = "ok"

MIGRATIONS
[ ] php artisan migrate:status shows all migrations as Ran
[ ] exam_recovery_log table exists
[ ] failed_jobs table exists

SMOKE TEST
[ ] One end-to-end test: student identifies → starts exam → answers 5 questions → submits → score appears within 5 minutes
[ ] php artisan examsphere:recover-failed-jobs --stats shows 0 critical failed jobs
[ ] php artisan examsphere:circuit-status shows all circuits CLOSED
```
