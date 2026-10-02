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

Target: **Ubuntu 24.04 / Debian 12, PHP 8.4.1+** (matching `composer.json`;
the locked Symfony dependencies require PHP 8.4.1 or newer).

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

> If your distro only ships PHP lower than 8.4.1, add the
> [ondrej/php PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php) first:
> ```bash
> sudo add-apt-repository ppa:ondrej/php && sudo apt update
> ```
> (This repo's `composer.json` requires `php: ^8.4.1`, so don't go below it.)

Verify and enable the three always-on services right away:
```bash
php8.4 -v
sudo systemctl enable --now php8.4-fpm nginx mysql
systemctl is-active php8.4-fpm nginx mysql     # all three: active
```

---

## 2. Put the code on the server

```bash
sudo mkdir -p /var/www/omnivote
getent passwd omnivote-deploy >/dev/null || sudo useradd --system --create-home --shell /bin/bash omnivote-deploy
sudo usermod -a -G www-data omnivote-deploy
sudo chown omnivote-deploy:www-data /var/www/omnivote
sudo chmod 2775 /var/www/omnivote
cd /var/www/omnivote
sudo -u omnivote-deploy git clone <your-repo-url> .   # or copy backend-laravel/ in
cd backend-laravel
sudo -u omnivote-deploy composer install --no-dev --optimize-autoloader
```

Make Laravel's runtime directories writable by both the deploy account and
PHP-FPM, which runs as `www-data`:
```bash
sudo chown -R omnivote-deploy:www-data storage bootstrap/cache
sudo chmod -R g+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
```

Create the production `.env` as `omnivote-deploy` and restrict it to the deploy
account and the PHP-FPM group:
```bash
sudo -u omnivote-deploy cp .env.example .env
sudo chmod 640 .env
```

`omnivote-deploy` owns the release checkout; `www-data` can read the app and
write only Laravel's runtime directories. `deploy.sh` maintains shared group
write access on `storage/` and `bootstrap/cache/`.
Re-running `setup-server.sh` preserves an existing database user's password
unless you explicitly provide `MYSQL_DB_PASS` to rotate it.
---

## 3. Configure `.env` for production

In `backend-laravel/.env`:

```ini
APP_NAME=OmniVote
APP_ENV=production
APP_DEBUG=false
APP_URL=https://debian.tail7e9e1e.ts.net     # Tailscale hostname (HTTPS)

# Same-origin /admin and /api: leave both unset in production.
# config/app.php falls FRONTEND_URL back to APP_URL and Sanctum adds APP_URL.
# Set these only for a genuinely separate frontend origin.
# FRONTEND_URL=https://admin.example.edu
# SANCTUM_STATEFUL_DOMAINS=omnivote.example.edu,admin.example.edu

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
sudo -u omnivote-deploy php8.4 artisan key:generate   # fresh APP_KEY on the server
sudo -u omnivote-deploy php8.4 artisan migrate --force
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
| `FRONTEND_URL` | *(unset)* | *(unset; falls back to `APP_URL` for same-origin admin)* |
| `SANCTUM_STATEFUL_DOMAINS` | *(unset)* | *(unset; config includes `APP_URL` and local development hosts)* |
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
- The console is served at `/admin/` on the same origin as `/api/`. Keep
  `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` unset for this default layout;
  setting them to localhost in production breaks cookie authentication. For a
  split-origin frontend, add its exact HTTPS origin/host to the respective
  environment settings and CORS allow-list.

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
sudo cp php-fpm-pool.conf /etc/php/8.4/fpm/pool.d/omnivote.conf
sudo cp omnivote-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl restart php8.4-fpm
sudo systemctl enable --now omnivote-worker

# 3) clear + rebuild caches with the production env
sudo -u omnivote-deploy php8.4 artisan config:clear
sudo -u omnivote-deploy php8.4 artisan config:cache
sudo systemctl restart php8.4-fpm

# 4) verify
systemctl is-active nginx php8.4-fpm mysql omnivote-worker omnivote-backup.timer
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
- `location ~ \.php$` → `fastcgi_pass unix:/run/php/php8.4-fpm.sock` (the php-fpm socket).
- Dotfiles are denied, uploads capped at 25 MB (registrar CSV import).

---

## 5. php-fpm — FastCGI process manager

On Ubuntu, the install already created the pool **`www`** (socket
`/run/php/php8.4-fpm.sock`) and the `php8.4-fpm.service` unit. Tune it with
`deploy/php-fpm-pool.conf` (dynamic workers, slow-query log), then:

```bash
sudo cp deploy/php-fpm-pool.conf /etc/php/8.4/fpm/pool.d/omnivote.conf
sudo systemctl restart php8.4-fpm
```

Why php-fpm is the right "24/7" piece: a pool of PHP workers stays warm at all
times, so the first request of the day isn't a slow PHP bootstrap — critical
for the sharp traffic spikes of an election window. And because it's a systemd
service, it restarts itself after crashes and after every reboot.

Sanity check the socket:
```bash
ls -l /run/php/php8.4-fpm.sock          # must be owned by www-data
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
sudo -u omnivote-deploy php8.4 artisan queue:restart
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
sudo -u www-data php8.4 artisan omnivote:backup
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
systemctl is-enabled php8.4-fpm nginx mysql omnivote-worker
systemctl is-active   php8.4-fpm nginx mysql omnivote-worker

# API smoke test
curl -i -k https://debian.tail7e9e1e.ts.net/sanctum/csrf-cookie
curl -i -k https://debian.tail7e9e1e.ts.net/api/election/status
```

Kill-test it — that's the whole point of 24/7:
```bash
sudo systemctl restart php8.4-fpm        # dev deploys / crashes
sudo reboot                              # everything comes back by itself
```

---

## 10. Deploying updates (repeatable)

```bash
cd /var/www/omnivote
sudo -u omnivote-deploy git pull --ff-only
cd backend-laravel
sudo -u omnivote-deploy composer install --no-dev --optimize-autoloader
sudo -u omnivote-deploy php8.4 artisan migrate --force
sudo -u omnivote-deploy php8.4 artisan queue:restart
# opcache caches bytecode per worker process -> recycle FPM workers on deploys
sudo systemctl restart php8.4-fpm
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
   └─ deploy (temporary GitHub-hosted runner → Tailscale → restricted SSH)
            → deploy/deploy.sh on the app server
            ├─ git fetch && git reset --hard origin/main
            ├─ composer install --no-dev
            ├─ php artisan migrate --force
            ├─ config:cache / route:cache / view:cache
            └─ systemctl restart php8.4-fpm
```

Do **not** install a persistent GitHub Actions runner on this production server.
This repository is public, so an unsafe workflow change could execute code on a
persistent production runner. The deploy job instead uses a disposable hosted
runner, joins the tailnet with GitHub OIDC, and connects over SSH. It runs only
after CI succeeds on `main`; pull-request jobs do not receive production
environment credentials.

### One-time: configure GitHub and Tailscale

1. In GitHub **Settings → Environments**, create `production` and restrict
   deployments to the `main` branch. Protect `main` so changes are reviewed
   before merging.
2. In the Tailscale admin console, create an OpenID Connect trust credential
   for GitHub Actions:
   - Issuer: `https://token.actions.githubusercontent.com`
   - Subject: `repo:Hseha/omnivote:environment:production`
   - Tag: `tag:omnivote-ci`
   - Scope: `auth_keys` only

   Record its client ID and audience; these are identifiers, not secrets. Add
   `tag:omnivote-ci` to your tailnet policy and authorize it to reach only the
   production server's SSH port (TCP 22). Tag the server as
   `tag:omnivote-server` or use its exact Tailscale IP as the destination.
   Review existing grants/ACL rules as well: a broader existing rule can
   override the intended narrow access. See Tailscale's
   [GitHub Action](https://tailscale.com/kb/1276/github-action) and
   [workload identity federation](https://tailscale.com/kb/1581/workload-identity-federation)
   documentation.
3. In the GitHub `production` environment, add these variables:
   - `TS_OAUTH_CLIENT_ID`: client ID from the Tailscale credential
   - `TS_AUDIENCE`: audience from the Tailscale credential
   - `DEPLOY_HOST`: server Tailscale DNS name (for example,
     `debian.tail7e9e1e.ts.net`)
   - `DEPLOY_KNOWN_HOSTS`: verified SSH host-key line for that DNS name

### One-time: prepare restricted SSH deployment access

Do these steps on the server only after checking the existing account and
checkout ownership. Do not rerun `setup-server.sh` on the live production
server just to create a deploy account; that script also changes server
configuration.

1. Confirm `omnivote-deploy` exists, can write the `/var/www/omnivote` checkout,
   and belongs to `www-data`. The checkout must contain the production
   `backend-laravel/.env` with mode `640`. Preserve the existing backup
   directory's `www-data` access.
2. Generate a dedicated Ed25519 SSH key on a trusted admin computer (not in the
   public repository). Store the **private** key as the `DEPLOY_SSH_PRIVATE_KEY`
   secret in the GitHub `production` environment. Do not paste it into chat or
   commit it. Install only the public key for `omnivote-deploy`, with a
   forced-command restriction:
   ```bash
   sudo install -d -o omnivote-deploy -g omnivote-deploy -m 700 \
     /home/omnivote-deploy/.ssh
   sudoedit /home/omnivote-deploy/.ssh/authorized_keys
   ```

   Put one line in `authorized_keys`, prefixing the generated public key:
   `restrict,command="/usr/local/sbin/omnivote-deploy" ssh-ed25519 ...`.
   Set the file owner to `omnivote-deploy` and mode to `600`. Install this
   root-owned wrapper at `/usr/local/sbin/omnivote-deploy`:
   ```bash
   #!/bin/sh
   set -eu
   exec /bin/bash /var/www/omnivote/deploy/deploy.sh
   ```

   Install it as root-owned and executable (`root:root`, mode `755`). The SSH
   key cannot request a shell or forward ports; every connection runs only the
   deploy script.
3. Give `omnivote-deploy` passwordless sudo only for the exact operations
   `deploy.sh` needs. First install its root-owned permission helper from the
   reviewed `main` checkout:
   ```bash
   sudo install -o root -g root -m 0755 \
     /var/www/omnivote/deploy/fix-runtime-permissions.sh \
     /usr/local/sbin/omnivote-deploy-permissions
   sudo stat -c '%U:%G %a %n' /usr/local/sbin/omnivote-deploy-permissions
   ```
   It preserves `storage/app/backups` as `www-data:www-data` mode `2700`, and
   refuses to run if that directory is missing or has different permissions.
   Then create `/etc/sudoers.d/omnivote-deploy` with:
   ```sudoers
   omnivote-deploy ALL=(root) NOPASSWD: /usr/local/sbin/omnivote-deploy-permissions, /bin/systemctl restart omnivote-worker.service, /bin/systemctl restart php8.4-fpm, /bin/systemctl enable --now omnivote-backup.timer
   ```
   Validate it with `sudo visudo -cf /etc/sudoers.d/omnivote-deploy`; keep
   mode `440`. Do not grant general passwordless sudo.
4. Verify the SSH host key out of band before adding its known-hosts line to
   `DEPLOY_KNOWN_HOSTS`. Compare the server's Ed25519 host-key fingerprint
   (`sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`) with the key
   obtained over the trusted tailnet connection. Do not trust an unverified
   `ssh-keyscan` result.

The deploy script does `git reset --hard origin/main`, which **discards any
server-local tracked edits**. Confirm the checkout is a clean mirror first;
the untracked production `.env` survives.

### First deploy

1. Confirm the `production` environment variables and private-key secret are
   set, the OIDC credential is restricted to the subject above, the tailnet
   policy allows only SSH to this server, and the server-side forced command
   and sudoers rule pass review.
2. Merge the workflow to `main`. Watch **Actions**: `test` and `size` must pass
   before the ephemeral hosted deploy job starts. Verify the run log and
   `/up`, `/admin/`, and `/api/election/status` after deployment.

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
sudo -u omnivote-deploy php artisan config:clear
sudo -u omnivote-deploy php artisan config:cache
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx
```

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `502 Bad Gateway` from nginx | php-fpm down: `systemctl status php8.4-fpm`; socket must be owned by `www-data` |
| `Permission denied` on socket | `chown www-data:www-data /run/php/php8.4-fpm.sock && chmod 660` |
| White page / `500` | storage/`bootstrap/cache` ownership; check `storage/logs/laravel.log` |
| CSRF mismatch on `/api` | `SESSION_SECURE_COOKIE=true` requires HTTPS; check `APP_URL` scheme |
| Slow during peak voting | raise `pm.max_children` in the pool; watch the slow-query log; tune MySQL `slow_query_log` |
| New code not picked up | opcache caches per worker: `sudo systemctl restart php8.4-fpm` after deploys |

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