# Deployment — Travel Office System (Laravel 13)

A bash-based, zero-downtime deploy flow for the `SafarakEalayna` Laravel app
on a Linux VPS (Ubuntu/Debian) with **Nginx + PHP-FPM + MySQL/MariaDB**.

---

## Folder layout

```
deploy/
├── deploy.sh                  # main entry point
├── deploy.conf.example        # copy → deploy.conf to override defaults
├── .env.production.example    # copy → .env on the server
└── README.md                  # this file
```

The script intentionally does **not** use atomic symlink releases — it
deploys in place. For larger infra, swap the `git pull` step with a
`releases/<timestamp>` + symlink rotation.

---

## One-time server setup

```bash
# 1. system packages
sudo apt update && sudo apt install -y nginx mysql-server php8.3-fpm \
    php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
    php8.3-bcmath php8.3-intl php8.3-sqlite3 unzip git nodejs npm

# 2. composer
curl -sS https://getcomposer.org/installer | sudo php -- \
    --install-dir=/usr/local/bin --filename=composer

# 3. create the deploy user (or reuse www-data)
sudo useradd -m -s /bin/bash deploy 2>/dev/null || true
sudo usermod -aG www-data deploy

# 4. clone the project
sudo mkdir -p /var/www/safarakEalayna
sudo chown deploy:www-data /var/www/safarakEalayna
sudo -u deploy git clone <your-repo-url> /var/www/safarakEalayna
cd /var/www/safarakEalayna
cp deploy/.env.production.example .env
php artisan key:generate
composer install --no-dev --optimize-autoloader

# 5. log dir
sudo mkdir -p /var/log/safarak-deploy
sudo chown deploy:www-data /var/log/safarak-deploy

# 6. permissions
sudo chown -R deploy:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} +
sudo find storage bootstrap/cache -type f -exec chmod 664 {} +
```

### Cache store

The application can use the database cache store, but database/file stores do
not support Laravel cache tags. Cache invalidation is therefore best-effort on
those stores and never performs a global `cache:clear` during a booking write.

If tag-based invalidation is required, use Redis in production and configure
both the cache and queue connections explicitly in `.env`:

```dotenv
CACHE_STORE=redis
REDIS_CLIENT=phpredis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

After changing `.env`, clear and rebuild the cached configuration:

```bash
php artisan config:clear
php artisan config:cache
```


### Nginx vhost (minimal)

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/safarakEalayna/public;

    index index.php;
    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Enable + reload:

```bash
sudo ln -s /etc/nginx/sites-available/safarak /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Diagnosing a flight-booking 504

If Nginx reports:

```text
upstream timed out ... while reading response header from upstream
request: "POST /api/v1/flight/bookings ..."
```

the browser did not cause the error. Nginx waited for PHP-FPM to send the
response headers and then reached its FastCGI read timeout. The booking
transaction may still finish after the client receives 504, so check the
bookings list before submitting the same booking again.

Use these commands on the VPS while reproducing the issue:

```bash
# Nginx: confirms the exact timeout and request timestamp
sudo tail -f /var/log/nginx/error.log

# PHP-FPM: look for worker exhaustion, fatal errors, or slow requests
sudo journalctl -u php8.3-fpm -f
sudo tail -f /var/log/php8.3-fpm.log

# MySQL: run while the request is stuck; inspect waiting transactions/locks
mysql -u<user> -p <database> -e 'SHOW FULL PROCESSLIST\G'
mysql -u<user> -p <database> -e 'SHOW ENGINE INNODB STATUS\G'
```

After deployment, `FlightBookingService::createBooking()` writes a
`duration_ms` value to `storage/logs/laravel.log`. Compare that value with the
Nginx timestamp:

- A large `duration_ms` means the delay is inside PHP/database work; inspect
  the MySQL lock/processlist output before changing proxy timeouts.
- No matching completion/failure log means PHP-FPM was killed or stalled before
  the service returned; inspect the PHP-FPM journal and worker limits.
- A completion log after the Nginx timeout confirms the transaction committed
  after the client had already received 504. Do not retry blindly.

Do not solve this symptom by increasing `fastcgi_read_timeout` alone. That only
makes the user wait longer and can leave more PHP-FPM workers occupied. Fix the
blocking query/lock first, then reload Nginx after any vhost change:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### Queue worker (systemd)

`/etc/systemd/system/safarak-queue.service`:

```ini
[Unit]
Description=Safarak queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/safarakEalayna
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=120 --max-jobs=500

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now safarak-queue.service
```

The deploy script calls `php artisan queue:restart`, which tells workers to
exit gracefully after their current job.

---

## Daily deploys

```bash
cd /var/www/safarakEalayna
sudo -u deploy ./deploy/deploy.sh
```

Useful flags:

| flag              | effect                                                    |
| ----------------- | --------------------------------------------------------- |
| `--dry-run`       | print every command, run nothing                          |
| `--no-build`      | skip `npm ci` + `npm run build`                           |
| `--skip-migrate`  | skip `php artisan migrate --force`                        |
| `--no-backup`     | don't snapshot `.env` before the run                      |
| `--branch=NAME`   | check out a specific branch before pulling                |
| `--dir=PATH`      | override `APP_DIR`                                        |
| `--user=USER`     | override the web user                                     |
| `--fpm=UNIT`      | override the systemd PHP-FPM unit name                    |
| `-h`, `--help`    | show usage                                                |

Each run writes:

- `deploy-<timestamp>.log` — full output
- `env.backup-<timestamp>` — snapshot of `.env` taken before deploy

Both land in `$LOG_DIR` (default `/var/log/safarak-deploy`).

---

## What the script does, in order

1. **pre-flight** — verifies PHP 8.3+, composer, git, node, and that `.env`
   and `artisan` exist.
2. **.env backup** — copies `.env` into the log dir.
3. **maintenance on** — `php artisan down` (retry 60s, refresh 15s).
4. **git fetch + pull** — `git pull --ff-only` (fast-forward only).
5. **composer install** — `--no-dev --optimize-autoloader`.
6. **npm build** — `npm ci && npm run build` (skippable).
7. **storage:link** — idempotent.
8. **migrate** — `php artisan migrate --force` (skippable).
9. **cache** — clear `config/route/view/event`, then warm them again.
10. **queue:restart** — workers exit gracefully after their current job.
11. **permissions** — `storage/`, `bootstrap/cache/`, `public/build/`
    chowned to the web user.
12. **PHP-FPM reload** — `sudo systemctl reload php8.3-fpm` if present.
13. **maintenance off** — `php artisan up`.

If anything between steps 3 and 12 fails, the trap restores
maintenance-off automatically so the site doesn't stay locked.

---

## Rollback

Because the script only does `git pull --ff-only`, rolling back is just:

```bash
cd /var/www/safarakEalayna
sudo -u deploy git reset --hard <previous-sha>
sudo -u deploy ./deploy/deploy.sh --no-build --skip-migrate
```

If a migration went out, write a forward-fixing migration rather than
reverting — never hand-edit the database in production.
