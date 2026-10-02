#!/bin/sh
set -eu

APP_DIR=/var/www/omnivote/backend-laravel
STORAGE_DIR="$APP_DIR/storage"
BACKUP_DIR="$STORAGE_DIR/app/backups"
CACHE_DIR="$APP_DIR/bootstrap/cache"
BACKUP_PERMISSIONS=www-data:www-data:2700

if [ "$(/usr/bin/id -u)" -ne 0 ]; then
  echo "ERROR: this helper must run as root." >&2
  exit 1
fi

if [ ! -d "$STORAGE_DIR" ] || [ ! -d "$CACHE_DIR" ] || [ ! -d "$BACKUP_DIR" ] || [ -L "$BACKUP_DIR" ]; then
  echo "ERROR: expected Laravel runtime or backup directory is missing or invalid." >&2
  exit 1
fi

if [ "$(/usr/bin/stat -c '%U:%G:%a' "$BACKUP_DIR")" != "$BACKUP_PERMISSIONS" ]; then
  echo "ERROR: backup directory ownership or mode changed; refusing to alter runtime permissions." >&2
  exit 1
fi

/usr/bin/find "$STORAGE_DIR" -xdev -path "$BACKUP_DIR" -prune -o \
  -exec /bin/chown -h omnivote-deploy:www-data {} +
/usr/bin/find "$CACHE_DIR" -xdev \
  -exec /bin/chown -h omnivote-deploy:www-data {} +

/usr/bin/find "$STORAGE_DIR" -xdev -path "$BACKUP_DIR" -prune -o \
  -exec /bin/chmod g+rwX {} +
/usr/bin/find "$STORAGE_DIR" -xdev -path "$BACKUP_DIR" -prune -o \
  -type d -exec /bin/chmod g+s {} +
/usr/bin/find "$CACHE_DIR" -xdev \
  -exec /bin/chmod g+rwX {} +
/usr/bin/find "$CACHE_DIR" -xdev -type d \
  -exec /bin/chmod g+s {} +

if [ "$(/usr/bin/stat -c '%U:%G:%a' "$BACKUP_DIR")" != "$BACKUP_PERMISSIONS" ]; then
  echo "ERROR: backup directory ownership or mode changed unexpectedly." >&2
  exit 1
fi

printf 'Runtime permissions updated; preserved %s (%s).\n' \
  "$BACKUP_DIR" "$BACKUP_PERMISSIONS"
