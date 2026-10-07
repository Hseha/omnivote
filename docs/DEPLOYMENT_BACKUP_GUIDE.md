# Deployment & Backup/Restore Guide

Target: Debian server, Tailscale access, Nginx + PHP-FPM, Laravel 13 backend
(`backend-laravel/`), React admin SPA (`admin-react/`), Flutter user app.

This guide covers (1) shipping the app to the server and (2) making the
Backup & Restore feature work in production, including its one deploy-only
requirement (cron).

---

## 1. Before deploying (developer machine)

### Commit everything first

`git status` must be clean before pushing. The backup/restore feature ships as:

- `backend-laravel/app/Support/BackupManager.php`
- `backend-laravel/app/Http/Controllers/BackupController.php`
- `backend-laravel/app/Console/Commands/CreateBackup.php`
- `backend-laravel/app/Support/AppSettings.php` (adds `backup()` reader)
- `backend-laravel/routes/api.php` (backup route group)
- `backend-laravel/routes/console.php` (daily schedule)
- `backend-laravel/database/migrations/2026_09_21_000003_create_audit_logs_table.php`
- `backend-laravel/tests/Feature/BackupTest.php`
- `admin-react/src/Settings.jsx` + `admin-react/src/Settings.css` (Backup tab)

Any file not committed locally cannot exist on the server.

### Preserve the APP_KEY

Backups are AES-256-CBC encrypted with a key derived from `APP_KEY`. The value
in the server's `.env` must be the **same** `APP_KEY` used when the backups
were created.

- Copy the current `APP_KEY` into the server `.env` verbatim.
- Do **not** run `php artisan key:generate` on a server that holds existing
  backups — old backups become permanently unreadable.

---

## 2. Deploy the app

Use `deploy/deploy.sh` (the CI/CD deploy job runs this via SSH). To run by
hand on the server:

```bash
# On the server
cd /var/www/omnivote
bash deploy/deploy.sh

# That script handles: git sync, admin SPA build, composer install,
# security assertions, migrations, caches, storage permissions, the
# public/storage symlink, queue worker + php-fpm restart, backup timer.
```

The admin SPA (`npm run build` in `admin-react/`) is built **by the deploy
script** and Vite writes it into `backend-laravel/public/admin/` so nginx
serves `/admin/` on the same origin as `/api/` (keeps Sanctum cookie auth
same-origin). See `deploy/README.md` §4 for the nginx vhosts and the
tailnet-only admin gate.

Known-good production `.env` values (from `docs/NGINX_TAILSCALE_TEAM_ACCESS.md`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://debian.tail7e9e1e.ts.net
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:5173
```

If the admin panel is served from a fixed Tailscale origin, add that exact
host and port to both `FRONTEND_URL`/CORS and `SANCTUM_STATEFUL_DOMAINS`.
Avoid broad wildcards for authenticated origins.

---

## 3. Enable automatic backups (cron — the only deploy-only step)

The self-hosted backup scheduler only runs when something asks it to. Without
cron, the **manual** "Run Backup Now" button works, but the "Auto Backup"
toggle and retention pruning never fire.

```bash
sudo crontab -u www-data -e
```

Add one line:

```
* * * * * php /var/www/omnivote/backend-laravel/artisan schedule:run >> /dev/null 2>&1
```

- Laravel evaluates `schedule:run` every minute and runs `omnivote:backup`
  once per day when the stored `autoBackup` setting is true.
- Retention pruning (the "Retention Period (days)" setting) is applied during
  each backup run.
- The schedule is currently hardcoded to `daily()`. The Hourly/Weekly dropdown
  in the UI does **not** change the interval yet.

Verify:

```bash
cd /var/www/omnivote/backend-laravel
sudo -u www-data php artisan schedule:list
#  0 0 * * * php artisan omnivote:backup .. Next Due: ...
```

---

## 4. How backup & restore works in production

### Snapshot creation

`POST /api/admin/backups` (gated by `permission:settings.update`, admin-only)

1. Reads all business tables via `BackupManager::tables()`:
   `users, phases, positions, candidates, election_settings, registrar_imports,
   vote_ledger, ballot_drafts, announcements, audit_logs, personal_access_tokens`
   (only those that exist in the schema are included).
2. Serializes them into a JSON snapshot and (when the Backup Encryption
   setting is on) encrypts it with the APP_KEY-derived AES key.
3. Writes `storage/app/backups/omnivote-<YmdHis>.omsnapshot`.
4. Prunes snapshots older than the stored retention days.
5. Writes an `audit_logs` row.

### Restore

`POST /api/admin/backups/restore` (gated by `permission:settings.update`)

1. Loads + decrypts the named snapshot.
2. Drops data from the business tables (children first) with FK checks off.
3. Replays the snapshot (parents first) — all inside **one transaction**, so a
   failure rolls everything back; no partial restore is ever left behind.
4. Writes an `audit_logs` row.

### Admin UI flow

Settings → Backup & Restore:

- **Run Backup Now** — creates a snapshot immediately.
- **Available Backups** — list with size/encrypted badge per row, plus
  download / restore / delete buttons.
- **Restore** — confirm modal warning that current election data will be
  overwritten.

---

## 5. Post-deploy verification checklist

```bash
# Server reachable
curl -i https://debian.tail7e9e1e.ts.net/up
curl -i https://debian.tail7e9e1e.ts.net/api/election/status

# Scheduler registered
cd /var/www/omnivote/backend-laravel
sudo -u www-data php artisan schedule:list

# Backups directory writable by the web user
ls -la /var/www/omnivote/backend-laravel/storage/app/backups
```

In the browser:

1. Log into the admin panel.
2. Settings → Backup & Restore → **Run Backup Now**.
3. Confirm a snapshot appears in **Available Backups** with an `encrypted`
   badge and nonzero size.
4. Click **Download** and confirm the file arrives.
5. Make a throwaway change, **Restore** the snapshot, confirm the change is
   gone.
6. If "Run Backup Now" errors: check `storage/app/backups` ownership and the
   Laravel log (`storage/logs/laravel.log`).

Auth note: unauthenticated admin API calls return `401` to SPA clients (which
send `Accept: application/json`). Browsing the API path directly without that
header returns the pre-existing `Route [login] not defined` 500 — expected,
not a regression.

---

## 6. Known limitations

| Item | Status | Implication |
|---|---|---|
| Backups stored on the same server | Snapshot is on the same disk as the DB | If the server dies, backups die too. Mirror `storage/app/backups/` off-site. |
| Remote Storage toggle | Storage-only — no code uploads | Turning it on does nothing yet. |
| Uploaded files | Not in the snapshot (DB rows only) | Candidate photos / branding logos are not covered; back those up at the OS level. |
| Frequency dropdown | Hardcoded `daily()` | Hourly/Weekly selection is decorative until the scheduler reads the setting. |
| APP_KEY changes | Breaks decryption of old backups | Never regenerate `APP_KEY` on a live server. |
| pdo_sqlite in tests | Not installed locally | New feature tests skip on the dev machine; they run in CI. |

---

## 7. Diagnostics cheatsheet

```bash
# List snapshots
php artisan tinker --execute="print_r(\App\Support\BackupManager::list());"

# Create one now (plaintext)
php artisan tinker --execute="print_r(\App\Support\BackupManager::create(false));"

# Scheduled backup runs + retention prune, manually
php artisan omnivote:backup
```