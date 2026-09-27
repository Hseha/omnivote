#!/usr/bin/env bash
# ============================================================================
# OmniVote — server-side deploy script.
# ----------------------------------------------------------------------------
# Run automatically by CI/CD after a push to main (see
# .github/workflows/deploy.yml), or by hand:  bash deploy/deploy.sh
#
# Assumes the server already has the repo checked out at $APP_DIR with its own
# production backend-laravel/.env, and that nginx + php-fpm are installed.
#
# Overridable via environment:
#   APP_DIR      repo root on the server   (default /var/www/omnivote)
#   BRANCH       deploy branch             (default main)
#   PHP          php binary                (default php8.3)
#   FPM_SERVICE  php-fpm systemd unit      (default php8.3-fpm)
#   RUN_AS       owner of storage/         (default www-data)
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/omnivote}"
BRANCH="${BRANCH:-main}"
PHP="${PHP:-php8.3}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"
RUN_AS="${RUN_AS:-www-data}"

log() { printf '\n==> %s\n' "$1"; }

cd "$APP_DIR"

log "Syncing $BRANCH in $APP_DIR"
git fetch --prune origin
git checkout "$BRANCH"
# The server is a mirror of origin: discard any server-local edits so the pull
# can never conflict. .env is untracked, so it survives this reset.
git reset --hard "origin/$BRANCH"

cd backend-laravel

log "Installing PHP dependencies"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction

# Fail the deploy BEFORE anything goes live if the production .env is unsafe
# (APP_DEBUG=true, SESSION_SECURE_COOKIE=false, a non-https APP_URL, or a missing
# APP_KEY). The same rules are enforced per-request by
# App\Support\ProductionConfigGuard, but that guard is intentionally HTTP-only so
# a bad .env cannot lock an operator out of artisan — which meant a deploy would
# happily install a debug-enabled release and only find out when the panel served
# a stack trace. This runs ahead of config:cache, while the values are still
# readable from the .env file rather than frozen into a cache.
log "Verifying production configuration"
"$PHP" artisan security:assert-production-config

log "Running migrations"
"$PHP" artisan migrate --force

log "Rebuilding caches (config/route/view)"
"$PHP" artisan config:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

log "Fixing storage permissions"
sudo chown -R "$RUN_AS":"$RUN_AS" storage bootstrap/cache

log "Restarting queue worker + php-fpm"
"$PHP" artisan queue:restart || true
sudo systemctl restart "$FPM_SERVICE"

log "Deployed $(git rev-parse --short HEAD) on $BRANCH"
