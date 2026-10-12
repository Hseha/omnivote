#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/var/www/omnivote-staging"
DB_NAME="omnivote_staging"
DB_USER="omnivote_staging"
REPO_URL="${REPO_URL:-https://github.com/Hseha/omnivote.git}"
# The tailnet MagicDNS name clients reach staging on. `hostname -f` frequently
# returns the bare short name, so prefer `tailscale status --json` when present.
# Detection is shared with setup-server.sh via deploy/lib/host.sh.
# shellcheck source=lib/host.sh
source "$(dirname "$0")/lib/host.sh"
STAGING_HOST="${STAGING_HOST:-$(omnivote_detect_primary_host)}"
LOCAL_HTTP_PORT="8081"
TAILSCALE_HTTPS_PORT="8443"
NGINX_SITE="/etc/nginx/sites-available/omnivote-staging"
NGINX_LINK="/etc/nginx/sites-enabled/omnivote-staging"

log() { printf '\n==> %s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

if [[ $EUID -ne 0 ]]; then
    fail "Run this setup script on the server with sudo bash."
fi

if [[ ! "$STAGING_HOST" =~ ^[A-Za-z0-9.-]+$ ]]; then
    fail "STAGING_HOST must be a DNS hostname."
fi

for tool in git php8.4 composer npm mysql runuser nginx tailscale openssl ss curl awk sort; do
    command -v "$tool" >/dev/null 2>&1 || fail "Required tool '$tool' is missing."
done

PHP_VERSION="$(php8.4 -v | awk 'NR == 1 { print $2 }')"
[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
    || fail "Could not determine the PHP CLI version (reported: ${PHP_VERSION:-empty})."
printf '8.4.1\n%s\n' "$PHP_VERSION" | sort -V -C \
    || fail "PHP 8.4.1 or newer is required (found ${PHP_VERSION})."
id www-data >/dev/null 2>&1 || fail "The www-data account is missing."
systemctl is-active --quiet nginx || fail "nginx must be active."
systemctl is-active --quiet php8.4-fpm || fail "php8.4-fpm must be active."
systemctl is-active --quiet mysql || fail "MySQL must be active."
systemctl is-active --quiet tailscaled || fail "Tailscale must be active."

[[ ! -e "$APP_DIR" ]] || fail "$APP_DIR already exists; refusing to overwrite it."
[[ ! -e "$NGINX_SITE" && ! -e "$NGINX_LINK" ]] \
    || fail "The staging nginx site already exists; refusing to replace it."
! ss -ltn | grep -qE "127\\.0\\.0\\.1:${LOCAL_HTTP_PORT}[[:space:]]" \
    || fail "127.0.0.1:${LOCAL_HTTP_PORT} is already in use."
tailscale serve status 2>&1 | grep -q "No serve config" \
    || fail "Tailscale Serve already has a configuration; refusing to replace it."

database_exists="$(mysql --batch --skip-column-names -e \
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${DB_NAME}'")"
[[ "$database_exists" == "0" ]] || fail "Database ${DB_NAME} already exists."
user_exists="$(mysql --batch --skip-column-names -e \
    "SELECT COUNT(*) FROM mysql.user WHERE User='${DB_USER}'")"
[[ "$user_exists" == "0" ]] || fail "MySQL account ${DB_USER} already exists."

log "Cloning current main into an isolated staging directory"
install -d -o www-data -g www-data "$APP_DIR"
runuser -u www-data -- git clone --depth 1 --branch main \
    "$REPO_URL" "$APP_DIR"

DB_PASSWORD="$(openssl rand -hex 32)"
log "Creating a new, staging-only MySQL database"
mysql <<SQL
CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

log "Writing a separate production-mode staging environment"
cat > "$APP_DIR/backend-laravel/.env" <<EOF
APP_NAME="OmniVote Staging"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${STAGING_HOST}:${TAILSCALE_HTTPS_PORT}
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=omnivote_staging_session
SESSION_PATH=/
SANCTUM_STATEFUL_DOMAINS=${STAGING_HOST}:${TAILSCALE_HTTPS_PORT}
SANCTUM_TOKEN_EXPIRATION=43200
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
MAIL_FROM_ADDRESS="staging@example.invalid"
MAIL_FROM_NAME="OmniVote Staging"
EOF
chown www-data:www-data "$APP_DIR/backend-laravel/.env"
chmod 640 "$APP_DIR/backend-laravel/.env"

log "Installing isolated staging dependencies"
runuser -u www-data -- bash -c \
    "cd '$APP_DIR/admin-react' && npm ci --no-audit --no-fund && npm run build"
runuser -u www-data -- bash -c \
    "cd '$APP_DIR/backend-laravel' && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader"
runuser -u www-data -- bash -c \
    "cd '$APP_DIR/backend-laravel' && php8.4 artisan key:generate --force"
runuser -u www-data -- bash -c \
    "cd '$APP_DIR/backend-laravel' && php8.4 artisan security:assert-production-config && php8.4 artisan migrate --force"

log "Creating throwaway staging-only panel accounts"
runuser -u www-data -- env APP_ENV=local bash -c \
    "cd '$APP_DIR/backend-laravel' && php8.4 artisan db:seed --class='Database\\Seeders\\UserSeeder' --force"

runuser -u www-data -- bash -c \
    "cd '$APP_DIR/backend-laravel' && php8.4 artisan config:cache && php8.4 artisan route:cache && php8.4 artisan view:cache"

log "Installing loopback-only nginx site for the staging app"
cat > "$NGINX_SITE" <<EOF
server {
    listen 127.0.0.1:${LOCAL_HTTP_PORT};
    server_name ${STAGING_HOST} localhost;
    root ${APP_DIR}/backend-laravel/public;
    index index.php;

    server_tokens off;
    charset utf-8;
    client_max_body_size 25M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header Content-Security-Policy "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" always;

    location = / { return 302 /admin/; }
    location = /admin { return 301 /admin/; }

    location /admin/ {
        try_files \$uri \$uri/ /admin/index.html;
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "no-referrer" always;
        add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
        add_header Strict-Transport-Security "max-age=31536000" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'" always;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param HTTP_X_FORWARDED_FOR \$remote_addr;
        fastcgi_param HTTP_X_FORWARDED_PROTO \$http_x_forwarded_proto;
        include fastcgi_params;
    }

    location ~ /\\.(?!well-known).* { deny all; }
}
EOF
ln -s "$NGINX_SITE" "$NGINX_LINK"
nginx -t
systemctl reload nginx

log "Verifying the local staging app before exposing it to the tailnet"
curl --fail --silent --show-error "http://127.0.0.1:${LOCAL_HTTP_PORT}/up" >/dev/null \
    || fail "Staging health check failed; Tailscale Serve was not enabled."

log "Publishing staging only through Tailscale HTTPS"
tailscale serve --bg --https="${TAILSCALE_HTTPS_PORT}" \
    "http://127.0.0.1:${LOCAL_HTTP_PORT}"

printf '\nStaging setup complete.\n'
printf 'Staging URL: https://%s:%s/admin/\n' "$STAGING_HOST" "$TAILSCALE_HTTPS_PORT"
printf 'Staging database: %s (separate from production)\n' "$DB_NAME"
printf 'Generated staging panel passwords were printed once above; save them privately.\n'
printf 'Production checkout, database, and environment were not modified.\n'
printf 'Staging uses a separate loopback-only nginx site and Tailscale Serve HTTPS.\n'
