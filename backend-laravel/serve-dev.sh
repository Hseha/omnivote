#!/usr/bin/env bash
# Dev server starter - ASCII hyphens guaranteed, no typing of --flags needed.
# Load user-level PHP extensions (pdo_sqlite + sqlite3) so the isolated
# in-memory test database and any sqlite tooling work without sudo editing
# /etc/php/php.ini.
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-$HOME/.config/php}"
cd "$(dirname "$0")"
php artisan serve --host=0.0.0.0 --port=8000
