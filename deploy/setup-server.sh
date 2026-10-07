#!/usr/bin/env bash
# ============================================================================
# OmniVote — one-shot server provisioning script (Ubuntu 24.04 / Debian 12)
# ----------------------------------------------------------------------------
# Installs nginx + PHP-FPM + MySQL, configures everything for the Laravel
# backend, and enables 24/7 systemd services.
#
#   sudo bash deploy/setup-server.sh
#
# You normally need to run this only once per server. Re-running is safe.
# ============================================================================
set -euo pipefail

PHP_VER="8.4"
APP_DIR="/var/www/omnivote"
WEB_USER="www-data"
DEPLOY_USER="omnivote-deploy"
APP_URL="${APP_URL:-https://$(hostname)}"

# ---------------------------------------------------------------------------
log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo bash $0" >&2; exit 1
fi

# ---------------------------------------------------------------------------
log "1/8  Updating package lists"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y

# ---------------------------------------------------------------------------
log "2/8  Installing PHP $PHP_VER + extensions + FPM + nginx + MySQL + Composer"
apt-get install -y --no-install-recommends \
    nginx \
    mysql-server \
    composer \
    unzip \
    curl \
    "php$PHP_VER-fpm" \
    "php$PHP_VER-cli" \
    "php$PHP_VER-mysql" \
    "php$PHP_VER-mbstring" \
    "php$PHP_VER-xml" \
    "php$PHP_VER-curl" \
    "php$PHP_VER-zip" \
    "php$PHP_VER-bcmath" \
    "php$PHP_VER-intl" \
    "php$PHP_VER-gd" \
    "php$PHP_VER-opcache"

# ---------------------------------------------------------------------------
log "3/8  Preparing deploy account + web root"
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    useradd --system --create-home --shell /bin/bash "$DEPLOY_USER"
fi
usermod -a -G "$WEB_USER" "$DEPLOY_USER"
mkdir -p "$APP_DIR"
chown -R "$DEPLOY_USER:$WEB_USER" "$APP_DIR"
chmod 2775 "$APP_DIR"
if [[ -f "$APP_DIR/backend-laravel/.env" ]]; then
    chown "$DEPLOY_USER:$WEB_USER" "$APP_DIR/backend-laravel/.env"
    chmod 640 "$APP_DIR/backend-laravel/.env"
fi
if [[ -d "$APP_DIR/backend-laravel/storage" && -d "$APP_DIR/backend-laravel/bootstrap/cache" ]]; then
    chown -R "$DEPLOY_USER:$WEB_USER" \
        "$APP_DIR/backend-laravel/storage" \
        "$APP_DIR/backend-laravel/bootstrap/cache"
    chmod -R g+rwX \
        "$APP_DIR/backend-laravel/storage" \
        "$APP_DIR/backend-laravel/bootstrap/cache"
    find "$APP_DIR/backend-laravel/storage" \
         "$APP_DIR/backend-laravel/bootstrap/cache" \
         -type d -exec chmod g+s {} +
fi
install -d -o "$WEB_USER" -g "$WEB_USER" /var/www/omnivote/letsencrypt

# ---------------------------------------------------------------------------
log "4/8  Hardening PHP-FPM (see deploy/php-fpm-pool.conf for tuning)"
PHP_INI="/etc/php/$PHP_VER/fpm/php.ini"
sed -i "s/^;?cgi.fix_pathinfo=.*/cgi.fix_pathinfo=0/" "$PHP_INI" || true
grep -q "^cgi.fix_pathinfo=0" "$PHP_INI" || echo "cgi.fix_pathinfo=0" >> "$PHP_INI"

# ---------------------------------------------------------------------------
log "5/8  Installing nginx site config"
cp deploy/nginx-omnivote.conf /etc/nginx/sites-available/omnivote
sed -i "s|debian.tail7e9e1e.ts.net|$(hostname)|g" /etc/nginx/sites-available/omnivote
ln -sf /etc/nginx/sites-available/omnivote /etc/nginx/sites-enabled/omnivote
# The Funnel / tailnet-serve vhost (127.0.0.1:8080). Keep the ts.net
# servername so `tailscale funnel` and `tailscale serve` host headers match.
cp deploy/nginx-funnel-api.conf /etc/nginx/sites-available/omnivote-funnel-api
ln -sf /etc/nginx/sites-available/omnivote-funnel-api /etc/nginx/sites-enabled/omnivote-funnel-api
rm -f /etc/nginx/sites-enabled/default
nginx -t

# ---------------------------------------------------------------------------
log "6/8  Creating the OmniVote database + user"
MYSQL_DB_PASS="${MYSQL_DB_PASS:-}"
MYSQL_USER_EXISTS="$(mysql --batch --skip-column-names -e \
    "SELECT EXISTS(SELECT 1 FROM mysql.user WHERE User='omnivote' AND Host='localhost')")"
if [[ -z "$MYSQL_DB_PASS" && "$MYSQL_USER_EXISTS" != "1" ]]; then
    MYSQL_DB_PASS="$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)"
    echo "  Generated a random DB password — save it, it is shown here only:"
    echo "  >>  $MYSQL_DB_PASS"
elif [[ -z "$MYSQL_DB_PASS" ]]; then
    echo "  Existing omnivote DB user found — preserving its password."
fi
if [[ -n "$MYSQL_DB_PASS" ]]; then
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS omnivote CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'omnivote'@'localhost' IDENTIFIED BY '$MYSQL_DB_PASS';
ALTER USER 'omnivote'@'localhost' IDENTIFIED BY '$MYSQL_DB_PASS';
GRANT ALL PRIVILEGES ON omnivote.* TO 'omnivote'@'localhost';
FLUSH PRIVILEGES;
SQL
else
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS omnivote CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON omnivote.* TO 'omnivote'@'localhost';
FLUSH PRIVILEGES;
SQL
fi

# ---------------------------------------------------------------------------
log "7/8  Enabling 24/7 systemd services (auto-start + auto-restart)"
systemctl enable --now php$PHP_VER-fpm
systemctl enable --now nginx
systemctl enable --now mysql

# ---------------------------------------------------------------------------
log "8/8  Deploying the Laravel app"
if [[ -d "$APP_DIR/backend-laravel/vendor" ]]; then
    echo "  vendor/ already present, skipping composer install"
else
    echo "  composer install ..."
    sudo -u "$DEPLOY_USER" sh -c "cd $APP_DIR/backend-laravel && composer install --no-dev --optimize-autoloader"
fi
if [[ -d "$APP_DIR/backend-laravel/storage" && -d "$APP_DIR/backend-laravel/bootstrap/cache" ]]; then
    chown -R "$DEPLOY_USER:$WEB_USER" \
        "$APP_DIR/backend-laravel/storage" \
        "$APP_DIR/backend-laravel/bootstrap/cache"
    chmod -R g+rwX \
        "$APP_DIR/backend-laravel/storage" \
        "$APP_DIR/backend-laravel/bootstrap/cache"
    find "$APP_DIR/backend-laravel/storage" \
         "$APP_DIR/backend-laravel/bootstrap/cache" \
         -type d -exec chmod g+s {} +
fi

cat <<EOF

================================================================================
 DONE.  php artisan serve is NOT needed — nginx + php-fpm serve the app 24/7.

 Next steps (manual, in deploy/README.md):
  1. sudo -u $DEPLOY_USER cp backend-laravel/.env.example backend-laravel/.env,
     then edit it and run: chmod 640 backend-laravel/.env
     APP_ENV=production, APP_DEBUG=false, APP_URL=https://$APP_URL,
     DB_USERNAME/DB_PASSWORD (omnivote / the password printed above),
     SESSION_SECURE_COOKIE=true.  The example file documents every key.
  2. cd backend-laravel && sudo -u $DEPLOY_USER php8.4 artisan key:generate
  3. sudo -u $DEPLOY_USER php8.4 artisan migrate --force
  4. sudo -u $DEPLOY_USER php8.4 artisan security:assert-production-config
     (the same gate deploy/deploy.sh runs — it must pass before going live)
  5. Install the queue worker and backup systemd units from deploy/.
  6. sudo -u $DEPLOY_USER php8.4 artisan config:cache
     sudo -u $DEPLOY_USER php8.4 artisan route:cache
  7. sudo systemctl enable --now omnivote-worker.service
  8. sudo systemctl enable --now omnivote-backup.timer   (encrypted daily backups)
  9. Set up HTTPS (Let's Encrypt or self-signed) — see README §SSL
 10. Verify:  curl -k https://$APP_URL/up

 The dedicated $DEPLOY_USER owns the checkout; PHP-FPM and workers run as
 www-data. Later releases are deployed by deploy/deploy.sh. (Do not put backticks in this heredoc:
 it is unquoted so that \$APP_URL expands, and backticks would be executed.)
================================================================================
EOF
