# OmniVote — nginx + PHP-FPM production setup (24/7 backend)

> Repo-wide orientation lives in the [root README](../README.md). This runbook is the one
> document that deliberately stays here, beside the scripts and unit files it describes.

This guide turns your Laravel backend (`backend-laravel/`) into a permanent
service using the **standard PHP-FPM** stack:

```
                 ┌──────────────┐        ┌──────────────────────────┐
   Flutter app   │    nginx     │  FCGI  │       php-fpm            │
 / React admin ──►   (443/80)   ├───────►│  (FastCGI socket)        │
                 │  static +    │  unix: │  ┌─ php worker 1 ─────┐  │
                 │  proxy_pass  │  socket│  └─ php worker N ─────┘  │
                 └──────────────┘   │    └──────────────────────────┘
                                    ▼
                       MySQL  ◄── Laravel  ◄── queue worker (systemd)
```

Why this beats `php artisan serve`:

|                        | `php artisan serve` | nginx + php-fpm |
|------------------------|---------------------|-----------------|
| Survives terminal close| ❌ dies             | ✅ systemd      |
| Survives server reboot | ❌                 | ✅ enabled at boot |
| Recovers after crash   | ❌                 | ✅ `Restart=on-failure` |
| Handles the /up health check | only while dev server runs | ✅ real 24/7 probe |
| Static/binary uploads  | dev server          | ✅ nginx + `client_max_body_size` |

Target: **Ubuntu 24.04 / Debian 12, PHP 8.4** (matching `composer.json`'s `^8.4`;
Laravel 13 requires PHP 8.4+, so 8.3 is no longer sufficient).

---

## 1. Install the software

```bash
# PHP 8.4 + FPM (FastCGI Process Manager) + the extensions Laravel needs
sudo apt update
sudo apt install -y \
  nginx \
  mysql-server \
  composer \
  "php8.4-fpm" \
  "php8.4-cli" \
  "php8.4-mysql" \
  "php8.4-mbstring" "php8.4-xml" "php8.4-curl" "php8.4-zip" \
  "php8.4-bcmath" "php8.4-intl" "php8.4-gd" "php8.4-opcache"
```

> If your distro only ships PHP lower than 8.4, add the
> [ondrej/php PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php) first:
> ```bash
> sudo add-apt-repository ppa:ondrej/php && sudo apt update
> ```
> (This repo's `composer.json` requires `php: ^8.4`, so don't go below it.)

Verify and enable the three always-on services right away:
```bash
php8.4 -v
sudo systemctl enable --now php8.4-fpm nginx mysql
systemctl is-active php8.3-fpm nginx mysql     # all three: active
```

---

## 2. Put the code on the server

```bash
sudo mkdir -p /var/www/omnivote
sudo chown www-data:www-data /var/www/omnivote
cd /var/www/omnivote
sudo -u www-data git clone <your-repo-url> .   # or copy backend-laravel/ in
cd backend-laravel
sudo -u www-data composer install --no-dev --optimize-autoloader
```

Permissions Laravel needs at runtime (logs, cached config, uploaded CSVs):
```bash
sudo chown -R www-data:www-data /var/www/omnivote/backend-laravel/storage \
                                 /var/www/omnivote/backend-laravel/bootstrap/cache
---

## 3. Configure `.env` for production

In `backend-laravel/.env`:

```ini
APP_NAME=OmniVote
APP_ENV=production
APP_DEBUG=false
APP_URL=https://debian.tail7e9e1e.ts.net     # Tailscale hostname (HTTPS)

FRONTEND_URL=http://localhost:5173            # React dev server (Sanctum cookie origin)
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=omnivote
DB_USERNAME=omnivote                 # or root
DB_PASSWORD=<strong-password>

SESSION_SECURE_COOKIE=true           # cookies only over HTTPS
```

Then:
```bash
sudo -u www-data php8.3 artisan key:generate          # fresh APP_KEY on the server
sudo -u www-data php8.3 artisan migrate --force
```

> **Important:** never commit the real `.env`. The one in the repo is a local
> dev copy — the server reads its own `.env` only.

### 3a. Exact diff from the dev `.env`

The dev copy at `backend-laravel/.env` differs from production only in these
keys. Edit the server copy to match the right-hand column:

| Key | Dev value | Production value |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `APP_URL` | `http://127.0.0.1:8000` | `https://debian.tail7e9e1e.ts.net` |
| `FRONTEND_URL` | *(unset)* | `http://localhost:5173` |
| `SANCTUM_STATEFUL_DOMAINS` | *(unset)* | `debian.tail7e9e1e.ts.net,localhost,localhost:5173,127.0.0.1` |
| `SESSION_SECURE_COOKIE` | *(unset → false)* | `true` |
| `SANCTUM_TOKEN_EXPIRATION` | *(unset → 43200)* | `43200` (default already 30 days) |
| `DB_DATABASE` | `omnivote_local` | `omnivote` |
| `DB_USERNAME` | `root` | `omnivote` (a dedicated user, or `root`) |
| `DB_PASSWORD` | *(local password)* | a strong password |
| `LOG_LEVEL` | `debug` | `warning` |
| `MAIL_MAILER` | `log` | `smtp` + real `MAIL_HOST/PORT/USERNAME/PASSWORD` |

Notes:

- `APP_KEY`: keep the existing key if this host shares the current database
  (a new key invalidates every encrypted value and session). Only run
  `artisan key:generate` on a brand-new deploy with an empty DB.
- The checked-in dev `.env` has a **leading space** before some `DB_*` values.
  Trim any whitespace when editing, or Laravel will treat `" omnivote_local"`
  as the database name.
- `TRUSTED_PROXIES` is not needed: `bootstrap/app.php` already trusts
  `127.0.0.1`, which is what nginx/php-fpm use on-loopback.

### 3b. Go-sequence (run on the host, with sudo where shown)

```bash
cd /var/www/omnivote/deploy

# 1) encrypted daily backups (timer)
sudo cp omnivote-backup.service omnivote-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now omnivote-backup.timer
systemctl list-timers omnivote-backup.timer

# 2) nginx vhost + php-fpm pool + queue worker
sudo cp nginx-omnivote.conf /etc/nginx/sites-available/omnivote
sudo ln -sf /etc/nginx/sites-available/omnivote /etc/nginx/sites-enabled/omnivote
sudo rm -f /etc/nginx/sites-enabled/default
sudo cp php-fpm-pool.conf /etc/php/8.3/fpm/pool.d/omnivote.conf
sudo cp omnivote-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl restart php8.3-fpm
sudo systemctl enable --now omnivote-worker

# 3) clear + rebuild caches with the production env
sudo -u www-data php8.3 artisan config:clear
sudo -u www-data php8.3 artisan config:cache
sudo systemctl restart php8.3-fpm

# 4) verify
systemctl is-active nginx php8.3-fpm mysql omnivote-worker omnivote-backup.timer
curl -k https://debian.tail7e9e1e.ts.net/up
curl -k https://debian.tail7e9e1e.ts.net/api/election/status
```

`php artisan serve` on port 8000 stays available for local testing and is
unaffected by any of the above — it binds its own port. Leave it running for
dev only; the deployed endpoint is nginx + php-fpm.

---

## 4. nginx — server block

Copy `deploy/nginx-omnivote.conf`:

```bash
sudo cp deploy/nginx-omnivote.conf /etc/nginx/sites-available/omnivote
sudo ln -s /etc/nginx/sites-available/omnivote /etc/nginx/sites-enabled/omnivote
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

What it does:

- **Port 80 → 301 → HTTPS** (your repo already enforces HTTPS everywhere).
- `root /var/www/omnivote/backend-laravel/public` — the only folder nginx can
  see; `.env`, `.git`, `vendor/src` stay unreachable.
- `try_files ... /index.php` sends every request to Laravel's front controller.
- `location ~ \.php$` → `fastcgi_pass unix:/run/php/php8.3-fpm.sock` (the php-fpm socket).
- Dotfiles are denied, uploads capped at 25 MB (registrar CSV import).

---

## 5. php-fpm — FastCGI process manager

On Ubuntu, the install already created the pool **`www`** (socket
`/run/php/php8.3-fpm.sock`) and the `php8.3-fpm.service` unit. Tune it with
`deploy/php-fpm-pool.conf` (dynamic workers, slow-query log), then:

```bash
sudo cp deploy/php-fpm-pool.conf /etc/php/8.3/fpm/pool.d/omnivote.conf
sudo systemctl restart php8.3-fpm
```

Why php-fpm is the right "24/7" piece: a pool of PHP workers stays warm at all
times, so the first request of the day isn't a slow PHP bootstrap — critical
for the sharp traffic spikes of an election window. And because it's a systemd
service, it restarts itself after crashes and after every reboot.

Sanity check the socket:
```bash
ls -l /run/php/php8.3-fpm.sock          # must be owned by www-data
```
---

## 6. Queue worker (background jobs run 24/7 too)

Your app uses `QUEUE_CONNECTION=database` — mailings, registrar imports, and
ballot jobs go through the queue. Keep one worker alive with the unit in
`deploy/omnivote-worker.service`:

```bash
sudo cp deploy/omnivote-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now omnivote-worker
journalctl -u omnivote-worker -f
```

On every deploy, restart it gracefully:
```bash
sudo -u www-data php8.3 artisan queue:restart
```

---

## Scheduled encrypted backups

`omnivote:backup` writes an AES-256-GCM snapshot to
`storage/app/backups/` and prunes files past the retention window. Nothing runs
it automatically by default, so install the timer (a real backup must exist
before election day):

```bash
sudo cp deploy/omnivote-backup.service /etc/systemd/system/
sudo cp deploy/omnivote-backup.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now omnivote-backup.timer

# check scheduling + last result
systemctl list-timers omnivote-backup.timer
journalctl -u omnivote-backup -n 20
```

Run one on demand (e.g. right before polls close):
```bash
sudo -u www-data php8.3 artisan omnivote:backup
```

---

## 7. HTTPS (SSL)

Your commits already force `https://` everywhere, so **HTTPS is not optional**.
Two routes:

**A. You have a domain → Let's Encrypt (auto-renewing)**
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.yourdomain.com
```
(The nginx config already opens `/.well-known/acme-challenge/` on port 80.)

**B. Tailscale hostname only (no public domain) → self-signed cert**
```bash
sudo mkdir -p /etc/ssl/omnivote
sudo openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
  -keyout /etc/ssl/omnivote/privkey.pem \
  -out    /etc/ssl/omnivote/fullchain.pem \
  -subj   "/CN=debian.tail7e9e1e.ts.net"
sudo systemctl reload nginx
```
The Flutter app already talks to this address over Tailscale.

---

## 8. Firewall

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp            # ssh — keep your hand in
sudo ufw enable
```
Optional: ensure nothing listens on port 8000 anymore (`sudo ss -tlnp | grep 8000`).
`php artisan serve` should NOT be running in production — nginx + php-fpm handle all requests.

---

## 9. Verify the 24/7 setup

```bash
# health endpoint Laravel ships with (bootstrap routes: health: '/up')
curl https://debian.tail7e9e1e.ts.net/up

# services that must never die
systemctl is-enabled php8.3-fpm nginx mysql omnivote-worker
systemctl is-active   php8.3-fpm nginx mysql omnivote-worker

# API smoke test
curl -i -k https://debian.tail7e9e1e.ts.net/sanctum/csrf-cookie
curl -i -k https://debian.tail7e9e1e.ts.net/api/election/status
```

Kill-test it — that's the whole point of 24/7:
```bash
sudo systemctl restart php8.3-fpm        # dev deploys / crashes
sudo reboot                              # everything comes back by itself
```

---

## 10. Deploying updates (repeatable)

```bash
cd /var/www/omnivote
sudo -u www-data git pull --ff-only
cd backend-laravel
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php8.3 artisan migrate --force
sudo -u www-data php8.3 artisan queue:restart
# opcache caches bytecode per worker process -> recycle FPM workers on deploys
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

---

## Continuous deployment (auto-deploy on push)

`.github/workflows/deploy.yml` turns a push to `main` into an automatic deploy:

```
git push origin main
   │
   ├─ test  (GitHub-hosted): phpunit + npm lint/build + flutter analyze/test
   │        └─ fails → deploy never runs
   └─ deploy (self-hosted runner on the app server) → deploy/deploy.sh
            ├─ git fetch && git reset --hard origin/main
            ├─ composer install --no-dev
            ├─ php artisan migrate --force
            ├─ config:cache / route:cache / view:cache
            └─ systemctl restart php8.3-fpm
```

### One-time: install the self-hosted runner (on the app server)

A self-hosted runner makes an **outbound** connection to GitHub, so the server
stays private (no inbound SSH or open ports needed — works fine over Tailscale).
The deploy script calls `sudo systemctl restart php8.3-fpm`, so the account
running the runner needs passwordless sudo for that command.

1. On GitHub: **Settings → Actions → Runners → New self-hosted runner**, copy
   the token and the follow-up commands for Linux x64.
2. On the server, run those commands (approx.):
   ```bash
   mkdir -p ~/actions-runner && cd ~/actions-runner
   curl -o actions-runner.tar.gz -L https://github.com/actions/runner/releases/latest/download/actions-runner-linux-x64.tar.gz
   tar xzf actions-runner.tar.gz
   ./config.sh --url https://github.com/<owner>/<repo> --token <TOKEN> \
               --name omnivote-server --labels self-hosted --unattended
   sudo ./svc.sh install && sudo ./svc.sh start
   ```
3. Give the runner account sudo for the two privileged commands:
   ```bash
   echo "$USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart php8.3-fpm, /usr/bin/chown" \
     | sudo tee /etc/sudoers.d/omnivote-runner
   sudo chmod 440 /etc/sudoers.d/omnivote-runner
   ```
4. Verify it shows **Idle** under Settings → Actions → Runners.

### One-time: make the server repo a clean mirror

The deploy script does `git reset --hard origin/main`, which **discards any
server-local edits** (your `.env` is untracked, so it survives). Make sure any
server-only edits are moved into the repo or stashed first.

### First deploy

1. Commit + push this updated code (the workflow and `deploy/deploy.sh` must be
   on `main` for CI/CD to have anything to run).
2. Push a trivial commit to `main` and watch **Actions** — `test` must pass,
   then `deploy` runs on the runner.

If you can already SSH to the server from the internet, you can skip the
self-hosted runner: replace the `deploy` job's `runs-on: self-hosted` with
`runs-on: ubuntu-latest` and add an `appleboy/ssh-action` step that runs
`bash /var/www/omnivote/deploy/deploy.sh` (store the key as a GitHub secret).

---

## Client access (React / Flutter)

All clients reach the backend at **`https://debian.tail7e9e1e.ts.net`**.
`php artisan serve` (port 8000) is **not** used in production.

| Client | Auth model | Requirement |
|---|---|---|
| React admin (local dev) | Sanctum cookie + CSRF | Vite proxy (committed) forwards `/api` + `/sanctum` to the HTTPS origin. Runs at `http://localhost:5173`, which is in `cors.php` and the Sanctum stateful domains default. |
| React admin (cross-origin) | Sanctum cookie + CSRF | Set `VITE_API_BASE_URL=https://debian.tail7e9e1e.ts.net`; add the exact frontend origin to `FRONTEND_URL`/CORS **and** `SANCTUM_STATEFUL_DOMAINS`. |
| Flutter native (Android/iOS) | Bearer token | Base URL default is `https://debian.tail7e9e1e.ts.net/api`. No browser CORS. Override with `--dart-define=API_BASE_URL=...`. |
| Flutter Web | Bearer token | Served origin must be allowed in Laravel CORS for browser requests. Add the exact origin (host + port) to `cors.php` `allowed_origins` (or `FRONTEND_URL`) and `SANCTUM_STATEFUL_DOMAINS`; there are **no CORS wildcards for authenticated requests**. |

After changing these values, clear Laravel's cached config:

```bash
cd /var/www/omnivote/backend-laravel
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan config:cache
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `502 Bad Gateway` from nginx | php-fpm down: `systemctl status php8.3-fpm`; socket must be owned by `www-data` |
| `Permission denied` on socket | `chown www-data:www-data /run/php/php8.3-fpm.sock && chmod 660` |
| White page / `500` | storage/`bootstrap/cache` ownership; check `storage/logs/laravel.log` |
| CSRF mismatch on `/api` | `SESSION_SECURE_COOKIE=true` requires HTTPS; check `APP_URL` scheme |
| Slow during peak voting | raise `pm.max_children` in the pool; watch the slow-query log; tune MySQL `slow_query_log` |
| New code not picked up | opcache caches per worker: `sudo systemctl restart php8.3-fpm` after deploys |

---

## Files in this folder

| File | Purpose |
|---|---|
| `README.md` | this guide |
| `nginx-omnivote.conf` | nginx server block (HTTP→HTTPS + FastCGI) |
| `php-fpm-pool.conf` | php-fpm pool tuning (dynamic workers) |
| `omnivote-worker.service` | Laravel queue worker, 24/7 |
| `setup-server.sh` | one-shot provisioning for Ubuntu 24.04 / Debian 12 |
```