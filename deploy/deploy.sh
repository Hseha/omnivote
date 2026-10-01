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
#   PHP          php binary                (default php8.4)
#   FPM_SERVICE  php-fpm systemd unit      (default php8.4-fpm)
#   RUN_AS       owner of storage/         (default www-data)
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/omnivote}"
BRANCH="${BRANCH:-main}"
PHP="${PHP:-php8.4}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
RUN_AS="${RUN_AS:-www-data}"

log() { printf '\n==> %s\n' "$1"; }

# --- 1. Preflight: read-only, and deliberately FIRST -------------------------
# `git reset --hard` below is the only step in this script that destroys state it
# cannot restore, so every check that can be made without changing anything runs
# before it: a missing tool, a missing .env or a sudo rule that does not match
# must be reported while the previous release is still whole and serving traffic.
if [ ! -d "$APP_DIR/.git" ]; then
  echo "ERROR: $APP_DIR is not a git checkout. Set APP_DIR, or clone the repo there first." >&2
  exit 1
fi
cd "$APP_DIR" || exit 1

for tool in git "$PHP" npm; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "ERROR: '$tool' is not on PATH, so this deploy cannot finish. Nothing has been changed." >&2
    echo "       The previous release is still live." >&2
    exit 1
  fi
done

# The reset keeps .env (it is untracked), but the release is unusable without it.
if [ ! -f backend-laravel/.env ]; then
  echo "ERROR: backend-laravel/.env is missing in $APP_DIR — nothing has been changed." >&2
  echo "       It must define APP_KEY, DB_* and SESSION_SECURE_COOKIE. See backend-laravel/.env.example" >&2
  exit 1
fi

# Warning only: a sudoers rule that grants specific binaries, not /bin/true, must
# not block an otherwise valid deploy. If it is genuinely wrong, the chown and
# systemctl calls below fail, and this warning tells you why they did.
if ! sudo -n /bin/true >/dev/null 2>&1; then
  echo "WARNING: 'sudo -n /bin/true' failed; the permission and restart steps may fail." >&2
  echo "         See deploy/README.md for the sudoers rule deploy.sh expects." >&2
fi

# --- 2. Safety gate, before the checkout is moved ----------------------------
# A production .env that fails the security assertions used to be discovered only
# after the reset: the server was left on the new commit, with the old vendor/ and
# config, until the operator noticed. .env survives the reset untouched, so the
# assertions can be made against the CURRENT release first and abort while it is
# still intact. artisan runs on the previous release's vendor/, which is why a
# fresh clone (no vendor yet) defers this gate to step 4 instead of failing.
# Side effect worth knowing: an abort here leaves the live site running with no
# config cache. That is slower, never wrong, and the next successful deploy
# restores the cache (and artisan keeps working, which is the point).
if [ -f backend-laravel/vendor/autoload.php ]; then
  log "Verifying production configuration (pre-sync)"
  # Clear the previous release's config cache first: artisan answers from
  # bootstrap/cache/config.php when it exists, which would certify the OLD
  # configuration instead of the .env we are actually about to run.
  ( cd backend-laravel && "$PHP" artisan config:clear ) || exit 1
  ( cd backend-laravel && "$PHP" artisan security:assert-production-config ) || exit 1
else
  log "No vendor/ yet — fresh checkout; configuration gate runs after composer install"
fi

# --- 3. Sync the checkout -----------------------------------------------------
log "Syncing $BRANCH in $APP_DIR"
PREVIOUS_HEAD="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
git fetch --prune origin
git checkout "$BRANCH"
# The server is a mirror of origin: discard any server-local edits so the pull
# can never conflict. .env is untracked, so it survives this reset.
git reset --hard "origin/$BRANCH"
log "Now at $(git rev-parse --short HEAD) (was $PREVIOUS_HEAD)"
# If a step below fails, the code is ahead of the database and/or vendor/. To put
# the code back (assess applied migrations by hand before doing it):
#   cd "$APP_DIR" && git reset --hard $PREVIOUS_HEAD
#   (cd backend-laravel && composer install --no-dev && php artisan config:cache)

# --- Build the React admin console ------------------------------------------
# The console is a separate Vite SPA and is NOT served by the API vhost: that
# vhost is deliberately JSON-only behind `default-src 'none'`, so the console
# needs its own host (see deploy/README.md). What is missing without this step
# is the build itself — nothing on the server ever produced admin-react/dist,
# so the console was never deployed at all.
#
# Ordered before composer/migrate on purpose: this is the cheapest step to fail
# and the only one that needs no database. Aborting here leaves the checkout
# updated but migrations uncached and php-fpm unrestarted, so the running site
# keeps serving the previous release instead of half of a new one.
log "Building the React admin console"
if ! command -v npm >/dev/null 2>&1; then
  echo "ERROR: npm not found on this host. The admin console cannot be built." >&2
  echo "       Install Node (>=20, matching .github/workflows/deploy.yml) and re-run." >&2
  exit 1
fi
cd admin-react || exit 1
npm ci --no-audit --no-fund
npm run build
cd "$APP_DIR" || exit 1

cd backend-laravel || exit 1
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
# Second pass of the same assertion, now against the release we just installed:
# its config/*.php defaults may turn a value that passed above into an unsafe one
# (a renamed env key silently falls back to its default). Still ahead of
# migrate, config:cache and the php-fpm restart, so a failure leaves the previous
# release serving traffic.
log "Verifying production configuration (post-composer)"
"$PHP" artisan security:assert-production-config

log "Running migrations"
"$PHP" artisan migrate --force

log "Rebuilding caches (config/route/view)"
"$PHP" artisan config:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

log "Fixing storage permissions"
# Absolute binary paths, deliberately, for every command run through sudo: the
# sudoers rule on the server grants specific binaries literally (Cmnd_Alias in
# deploy/README.md), and `sudo chown ...` would not match such a rule — the
# deploy would then stop here asking for a password CI cannot type.
sudo /bin/chown -R "$RUN_AS":"$RUN_AS" storage bootstrap/cache

log "Restarting queue worker + php-fpm"
# queue:restart only asks a worker to stop after its current job. It does not
# start one, and it does not reload the code a supervisor-less unit keeps running:
# a worker that died earlier, or one still executing the previous release, is only
# fixed by restarting the unit. Kept best-effort (|| true) so a host without the
# unit installed still completes the deploy.
"$PHP" artisan queue:restart || true
sudo /bin/systemctl restart omnivote-worker.service || true
sudo /bin/systemctl restart "$FPM_SERVICE"

# The encrypted backup is a timer-activated unit, and nothing outside
# deploy/README.md ever enables it: a rebuilt or reimaged server therefore stops
# taking election backups silently, days later. Idempotent, so it is safe to run
# on every deploy — this is the only place that guarantees the timer is on.
log "Ensuring the backup timer is enabled"
sudo /bin/systemctl enable --now omnivote-backup.timer || true

log "Deployed $(git rev-parse --short HEAD) on $BRANCH"
