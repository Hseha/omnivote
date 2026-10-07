# OmniVote


OmniVote is a school election system with student enrollment, secret-ballot voting,
announcements, election results, and an administration console.

This README is the repository index and deployment starting point. The detailed
server procedure lives in [`deploy/README.md`](deploy/README.md); use that runbook
for commands and server-specific configuration rather than treating this overview
as a substitute.

## Before deploying

Production deployment is not just a `git push`. Complete the server setup and
launch checks in [`deploy/README.md`](deploy/README.md) first. At a minimum:

- Use a supported Ubuntu 24.04 or Debian 12 server with PHP 8.4.1+, nginx,
  PHP-FPM, MySQL, Node.js 20, and the required PHP extensions.
- Create the production checkout and its **untracked** `backend-laravel/.env`.
  Configure `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL`, a
  persistent `APP_KEY`, the production database credentials, and
  `SESSION_SECURE_COOKIE=true`. Never copy development credentials into
  production or commit a real `.env`.
- Install and enable the nginx/PHP-FPM configuration, Laravel queue worker, and
  encrypted backup timer. Run
  `php artisan security:assert-production-config` and verify the `/up` health
  endpoint before opening the service to users.
- Configure the GitHub `production` environment for automatic deployment. The
  deploy job uses a disposable GitHub-hosted runner that joins the tailnet via
  GitHub OIDC (Tailscale trust credential) and connects to the server over
  restricted SSH. Do **not** install a persistent runner on the production
  server; see the runbook for the GitHub/Tailscale one-time setup.
- Confirm the recovery plan and test a backup restore before election day.

After setup, pushes to `main` run CI: `test`, then the `Release size budget`
job, then — only for `main` — the `Deploy` job. Deployment runs on a disposable
GitHub-hosted runner that authenticates to the tailnet with GitHub OIDC and
invokes a restricted SSH deploy command on the server. The deploy script resets
the server checkout to `origin/main`, so do not keep tracked server-only edits
there. The server `.env` is untracked and remains outside that reset.

**Do not deploy until the complete preflight, first-deploy, and verification
steps in [`deploy/README.md`](deploy/README.md) pass.** In particular, the
deployment script expects the server, `.env`, systemd units, and the
GitHub/Tailscale identity to be provisioned before it runs.

## Repository map

| Path | Purpose | Stack |
|---|---|---|
| [`backend-laravel/`](backend-laravel/) | Authoritative API, election rules, authentication, authorization, and phase enforcement | PHP 8.4.1+, Laravel 13, Sanctum, MySQL |
| [`admin-react/`](admin-react/) | Committee, registrar, and auditor administration console | React 19, Vite |
| [`user-flutter/`](user-flutter/) | Student mobile app; Flutter web builds are also supported | Flutter, Dart, Riverpod |
| [`deploy/`](deploy/) | Server provisioning, nginx/PHP-FPM/systemd configuration, backups, and deployment scripts | Bash, nginx, systemd |
| [`docs/`](docs/) | Architecture, API contract, product references, audits, and operations guides | Markdown |
| [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) | CI checks, Android release-size gate, and gated production deployment | GitHub Actions |

## Run locally

Requirements: PHP and Composer, Node.js and npm, Flutter, and the PHP SQLite
driver for backend tests. Use separate terminals for the API and console.

### Backend API

```bash
cd backend-laravel
composer install
cp -n .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

The development seeders provide catalog data and local panel accounts. They
generate passwords for development; never use these accounts or development
seed data as production credentials.

### Admin console

```bash
cd admin-react
npm ci
npm run dev
```

The Vite development proxy forwards `/api` and `/sanctum` to the local API.

### Student app

```bash
cd user-flutter
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

`10.0.2.2` lets an Android emulator reach the host machine. For a different
device or API host, set `API_BASE_URL` to the appropriate address.

## Checks

Run the relevant checks before proposing or deploying changes:

```bash
# Backend tests (pdo_sqlite is required)
cd backend-laravel
./bin/test-local

# Admin console
cd ../admin-react
npm ci
npm run lint
npm run build

# Student app
cd ../user-flutter
flutter pub get
flutter analyze
flutter test
```

The backend tests use an in-memory SQLite database and array-backed session and
cache stores; they do not exercise a production database. The test bootstrap
fails if `pdo_sqlite` is unavailable instead of silently skipping feature tests.
CI installs the required PHP extension and runs these checks. It also builds the
obfuscated arm64 release APK and enforces the size budget in
[`user-flutter/tool/release_size.sh`](user-flutter/tool/release_size.sh).

## Application overview

### Backend

The Laravel API is the authority for election rules and access control. Routes
use role, permission, password-change, and election-phase middleware; client-side
visibility is only a user-interface convenience. The backend also owns ballot
handling, tallying, audit records, notifications, two-factor authentication,
and production configuration checks.

Ballot secrecy is a core constraint: voter records must not be linkable to
individual selections. Do not add a voter-to-candidate relationship or weaken
the anonymized tally and receipt design.

### Admin console

The React single-page app serves committee, registrar, and auditor workflows.
Production serves it under `/admin/` on the same origin as the API. The console
authenticates with Sanctum session cookies and CSRF protection; it does not use
bearer tokens. `VITE_API_BASE_URL` is normally unset in production, allowing
same-origin requests.

### Student app

The Flutter app authenticates to the API with a Sanctum bearer token. Native
token storage uses platform secure storage; web token storage is in memory.
Students who need to reset a password use the registrar-issued activation code.
The API base URL can be overridden with
`--dart-define=API_BASE_URL=https://your-host/api`.

## Deployment and operations

Read [`deploy/README.md`](deploy/README.md) for the supported deployment
procedure, including:

- One-time host provisioning with `deploy/setup-server.sh`
- Production `.env` configuration and the production safety check
- nginx, PHP-FPM, queue worker, backup timer, and HTTPS setup
- GitHub `production` environment, Tailscale OIDC trust credential, and
  restricted SSH setup for automatic deployment
- First deployment, verification, client access, and troubleshooting

The production stack uses nginx and PHP-FPM; `php artisan serve` is for local
development only. The deployment process runs database migrations and refreshes
Laravel caches. Backups are encrypted; configure and verify the timer and a
restore procedure before collecting real election data.

## Authentication and configuration notes

- **Admin console:** Sanctum cookie session plus CSRF token. For a separate
  frontend origin, configure the exact origin in Laravel's CORS and Sanctum
  stateful-domain settings; do not use wildcard origins for authenticated
  requests.
- **Student app:** Sanctum bearer token. Do not treat this as a JWT flow.
- **Production safety:** production requests are guarded against debug mode,
  insecure session cookies, non-HTTPS `APP_URL`, and missing `APP_KEY`. The
  deployment script runs the corresponding Artisan safety check before serving
  the new release.
- **Secrets:** `.env`, signing keys, and keystore files must stay out of git.
  Preserve an existing `APP_KEY` when retaining an existing database; rotating
  it can invalidate encrypted data and sessions.
- **API changes:** give each new student-facing route its required
  `checkPhase:` middleware and keep validation and authorization enforced by the
  API.

## Documentation index

| Document | Purpose |
|---|---|
| [`deploy/README.md`](deploy/README.md) | Production server and deployment runbook |
| [`docs/ARCHITECTURE_GUIDE.md`](docs/ARCHITECTURE_GUIDE.md) | System architecture and request flow |
| [`docs/01_PROJECT_OVERVIEW.md`](docs/01_PROJECT_OVERVIEW.md) | Roles, election lifecycle, and product overview |
| [`docs/05_API_INTEGRATION.md`](docs/05_API_INTEGRATION.md) | Client/API contract |
| [`docs/DEPLOYMENT_BACKUP_GUIDE.md`](docs/DEPLOYMENT_BACKUP_GUIDE.md) | Backup and recovery operations |
| [`docs/08_BUILD_RELEASE.md`](docs/08_BUILD_RELEASE.md) | Flutter release build process |
| [`docs/PR_GUIDE.md`](docs/PR_GUIDE.md) | Branch and pull-request conventions |

Audit documents with dates in their filenames are point-in-time records, not
necessarily a description of the current code. When documentation conflicts
with implementation, verify the code and update the relevant current guide.
