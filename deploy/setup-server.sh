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
log "3/8  Preparing web root + storage permissions"
mkdir -p "$APP_DIR"
chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
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
rm -f /etc/nginx/sites-enabled/default
nginx -t

# ---------------------------------------------------------------------------
log "6/8  Creating the OmniVote database + user"
MYSQL_DB_PASS="${MYSQL_DB_PASS:-}"
if [[ -z "$MYSQL_DB_PASS" ]]; then
    MYSQL_DB_PASS="$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)"
    echo "  Generated a random DB password — save it, it is shown here only:"
    echo "  >>  $MYSQL_DB_PASS"
fi
mysql <<SQL
CREATE DATABASE IF NOT EXISTS omnivote CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'omnivote'@'localhost' IDENTIFIED BY '$MYSQL_DB_PASS';
ALTER USER 'omnivote'@'localhost' IDENTIFIED BY '$MYSQL_DB_PASS';
GRANT ALL PRIVILEGES ON omnivote.* TO 'omnivote'@'localhost';
FLUSH PRIVILEGES;
SQL

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
    sudo -u "$WEB_USER" sh -c "cd $APP_DIR/backend-laravel && composer install --no-dev --optimize-autoloader"
fi

cat <<EOF

================================================================================
 DONE.  php artisan serve is NOT needed — nginx + php-fpm serve the app 24/7.

 Next steps (manual, in deploy/README.md):
  1. cp backend-laravel/.env.example backend-laravel/.env, then edit it:
     APP_ENV=production, APP_DEBUG=false, APP_URL=https://$APP_URL,
     DB_USERNAME/DB_PASSWORD (omnivote / the password printed above),
     SESSION_SECURE_COOKIE=true.  The example file documents every key.
  2. cd backend-laravel && php artisan key:generate
  3. sudo -u www-data php artisan migrate --force
  4. sudo -u www-data php artisan security:assert-production-config
     (the same gate deploy/deploy.sh runs — it must pass before going live)
  5. Build the admin console:  cd admin-react && npm ci && npm run build
     nginx serves /admin/ from admin-react/dist; nothing in this script builds
     it, so until you do, /admin/ is a 404.
  6. sudo -u www-data php artisan config:cache && php artisan route:cache
  7. systemctl enable --now omnivote-worker.service
  8. systemctl enable --now omnivote-backup.timer   (encrypted daily backups)
  9. Set up HTTPS (Let's Encrypt or self-signed) — see README §SSL
 10. Verify:  curl -k https://$APP_URL/up

 Steps 3-8 are exactly what deploy/deploy.sh does; once .env exists (steps 1-2)
 you can run it as www-data instead of doing them by hand, and every later
 release is then deployed the same way. (Do not put backticks in this heredoc:
 it is unquoted so that \$APP_URL expands, and backticks would be executed.)
================================================================================
EOF
