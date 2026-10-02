# OmniVote

SSG election system for one school: student self-enrollment, secret-ballot voting,
announcements, and results — with a console for the election committee.

**This is the index for the whole repo** — per-folder READMEs drifted out of sync, so add a
section here instead of creating a new one. The one exception is
[`deploy/README.md`](deploy/README.md), the 457-line production runbook, which deliberately
stays beside the scripts it describes.

[Repository map](#repository-map) · [Run it locally](#run-it-locally) · [Tests](#tests) ·
[Backend](#backend-layout) · [Console](#admin-console) · [Student app](#student-app) ·
[Things that bite](#things-that-bite) · [Docs index](#docs-index) · [Deploy](#deploy) ·
[House rules](#house-rules)

## Repository map

| Path | What it is | Stack |
|---|---|---|
| `backend-laravel/` | REST API, session auth, role/permission + phase enforcement. **Authoritative** for every rule. | PHP ^8.4.1, Laravel ^13.17, Sanctum ^4 (cookie sessions), google2fa, MySQL/MariaDB |
| `admin-react/` | Committee / registrar / auditor console (SPA). | React 19 + Vite 8, plain JS (no TS, no router lib) |
| `user-flutter/` | Student app (Android APK; web build also works). | Flutter, Dart ^3.13, Riverpod |
| `deploy/` | VPS bring-up, nginx/PHP-FPM/systemd units, hardened config, backup tooling. | Bash, nginx, systemd, MariaDB |
| `docs/` | Specs, audits, API contract, ops guides, process docs. | Markdown |
| `.github/workflows/deploy.yml` | `test` job, then gated `deploy` on a self-hosted runner over Tailscale. | GitHub Actions |

## Run it locally

```bash
# 1. API → http://127.0.0.1:8000  (the React proxy and Flutter dev builds expect this port)
cd backend-laravel
composer install && cp -n .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # catalog + the three panel logins
#   Panel-login passwords are never committed: the seeder prints a generated one
#   per account (copy it now), or set SEED_DEV_PASSWORD to choose your own.
php artisan serve --host=127.0.0.1 --port=8000   # or ./serve-dev.sh

# 2. Console → http://localhost:5173  (proxies /api and /sanctum to 127.0.0.1:8000)
cd ../admin-react && npm install && npm run dev

# 3. Student app (Android emulator: 10.0.2.2 reaches the host machine)
cd ../user-flutter && flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

## Tests

```bash
cd backend-laravel
./bin/test-local                # or: composer test:local
cd ../user-flutter && flutter test   # 10 test files
cd ../admin-react && npm run lint    # no JS test runner is configured
```

Backend suite today: **185 tests / 832 assertions**, 20 feature + 3 unit files. `phpunit.xml`
uses sqlite `:memory:` plus `array` session/cache stores, so tests never touch a real database.

**`pdo_sqlite` is required, and the suite refuses to start without it.** Every feature test
builds an isolated in-memory SQLite schema, so the driver is mandatory. `tests/bootstrap.php`
checks for it once at startup and `exit(1)`s with fix instructions if it is missing — it does
*not* skip. This matters: the per-test `markTestSkipped` guard it replaced produced a green
`185 tests / 21 passed / 164 skipped / exit 0` on any machine missing the extension, which
silently declined to verify ballot secrecy, electorate scoping and login backoff.

Use `bin/test-local` rather than `php artisan test` on a box where the driver is installed but
not enabled in `php.ini`. `artisan test` re-executes phpunit as a subprocess, so
`-d extension=…` flags never reach it; `bin/test-local` loads the driver only when it is
actually missing and calls `vendor/bin/phpunit` directly. CI installs `pdo_sqlite` via
`shivammathur/setup-php`, so it needs none of this.

## Backend layout

```
app/Http/Controllers/       21 controllers + Concerns/  (Admin* prefix, no Admin/ dir)
app/Http/Middleware/        CheckPhase, EnsurePermission, EnsureRole,
                            EnsurePasswordChanged, ApplySessionLifetime, SecurityHeaders
app/Http/Requests/          6 form requests (validation lives here, not in controllers)
app/Support/                ElectionTally, BackupManager, AppSettings, Notifier, TwoFactor,
                            RegistrarCode, TemporaryPassword, TermArchive, DepartmentCatalog,
                            TrustedHosts, ProductionConfigGuard
app/Console/Commands/       omnivote:backup, security:assert-production-config,
                            security:rotate-student-credentials
app/Models/                 User, Phase, Position, Candidate, BallotDraft, VoteLedger, …
routes/api.php              ~88 routes: per-route middleware + named rate limiters
config/permissions.php      permission → role map (authorization source of truth)
database/migrations/        38 migrations
```

Middleware aliases (in `bootstrap/app.php`): `checkPhase:<phase>`, `permission:<key>`,
`role:<role>`, `passwordChanged`. `SecurityHeaders` and `ApplySessionLifetime` are
*prepended* globally; trusted proxies and trusted hosts are configured in the same file.

## Admin console

Views are switched in `App.jsx` (there is no router): `allowedViews` / `defaultView` from
`lib/permissions.js` decide what a role can see — the API re-checks every call, so hiding a
button is a convenience, never a control.

- `src/lib/` — `api.js` (axios, `withCredentials`, CSRF bootstrap via `/sanctum/csrf-cookie`),
  `AuthContext.jsx`, `auth.js`, `permissions.js`, `ThemeContext.jsx`,
  `ElectionStatusContext.jsx`, `branding.js`, `avatar.js`, `mobileShell.js`
- `src/components/` — `Sidebar`, `Header`, `DashboardWidgets`, `NotificationCenter`,
  `PhaseStatusDialog`, `MobileMenuButton`
- Screens are flat in `src/`: `Candidates`, `StudentRegistry`, `ElectionSetup`, `Results`,
  `Settings`, `UserManagement`, `Departments`, `SsgPresident`, `Announcements`,
  `TwoFactorEnrollmentScreen` / `TwoFactorSetup`, `AdminLogin`, `ForgotPassword`,
  `ResetPassword` (each with a sibling `.css`)
- `VITE_API_BASE_URL` (see `.env.example`) overrides the API origin; empty means
  same-origin, which is how production serves it.

## Student app

`lib/core/constants/api_constants.dart` reads `--dart-define=API_BASE_URL` and defaults to
the production Tailscale origin. Students recover a password with the registrar-issued activation
code (`POST /api/auth/password/reset-with-code`), because a name-slug handle has no mailbox.

**The two clients authenticate differently, deliberately.** The console is cookie-only:
`admin-react/src/lib/api.js` bootstraps CSRF from `/sanctum/csrf-cookie` and sends
`withCredentials` + `withXSRFToken`, never a bearer token. The student app is the reverse — the API
issues a Sanctum token at login (`createToken('mobile')` in `StudentAuthController`), and
`api_client.dart` sends it as `Authorization: Bearer`. `TokenStore` keeps that token in memory on
web (so it is never written to `localStorage`/`shared_preferences`) and in platform secure storage
on native. Both therefore need `withCredentials`-style cookie support too, because Sanctum
issues the session cookie alongside the token.

## Things that bite

- **The two clients use different auth transports.** The console is Sanctum session-cookie only
  (`withCredentials` + `X-XSRF-TOKEN`); the student app carries a Sanctum bearer token
  (`createToken('mobile')`). Neither client is a JWT setup, and the API re-checks every call —
  hiding a button in the console is a convenience, never a control.
- **CSRF token mismatch** on an admin POST usually means the SPA origin is missing from
  `config/cors.php` `allowed_origins` — not a token bug. Only `POST /api/auth/login` is
  exempt from CSRF in `routes/api.php`.
- **A new student-facing route needs its own `checkPhase:` gate.** Several were missing one
  in the 2026-09-26 audit; the middleware will not guess for you.
- **Ballots are secret by design.** The ledger keeps no candidate reference per voter and
  receipts are HMACs of the anonymised tally — nothing may re-link a voter to a ballot.
- `AuditLog` stores changes in the **`details`** JSON column; there is no `description`.
- Never rename anything under `storage/` — vote photos and ballot backups live there.
- `artisan`/`php -l` need the right `PHP_INI_SCAN_DIR` (see [Tests](#tests)), or SQLite
  features disappear and results get misleading.

## Docs index

Code is the source of truth: when a doc and the code disagree, the code wins and the doc
gets corrected or labelled historical. Filenames are load-bearing — ~90 links point at
`docs/…` paths, including from migration comments and `config/session.php`, so don't move
or rename files in there.

**Start here**

| Doc | Covers |
|---|---|
| `docs/ARCHITECTURE_GUIDE.md` | Repo-wide architecture: apps, request flow, auth, phase gates |
| `docs/01_PROJECT_OVERVIEW.md` | What the system does, roles, election lifecycle |
| `docs/05_API_INTEGRATION.md` | Client ↔ API contract (endpoints, auth/CSRF, payloads) — the most-referenced doc |
| `docs/SECURITY_ASSESSMENT_2026_09_26.md` | Live security backlog (Critical→Low) + remediation checklist |

**Build-spec series `00`–`10`** — reference; written while the student app was the centre of
the project, so re-check any path against `user-flutter/lib/`:
`00_FILE_TREE_AND_FLOW`, `02_FOLDER_STRUCTURE`, `03_APP_FLOW` (journey), `04_SCREENS_SPEC`
(fields/states/validation per screen), `06_STATE_MANAGEMENT` (Riverpod), `07_DESIGN_SYSTEM`
(still the design rules), `08_BUILD_RELEASE` (APK release), `09_SCREENS_LOCATION_GUIDE`
("which file is screen X"), `10_FOLDER_MAPPING`.

**Operations** — [`deploy/README.md`](deploy/README.md) is the runbook kept beside the
scripts it describes; `docs/DEPLOYMENT_BACKUP_GUIDE.md` is the narrative version of the same
rollout (HTTPS terminates at Tailscale Funnel, not on the origin);
`docs/NGINX_TAILSCALE_TEAM_ACCESS.md`; `docs/SETUP_WINDOWS_DEV.md`.

**Historical snapshots** — useful as evidence of what was checked and why; do not treat their
file lists or line numbers as current: `docs/FLUTTER_AUDIT_2026_09_13.md`,
`docs/AUDIT_2026_09_12.md`, `docs/AUDIT.md`, `docs/API_INTEGRATION_REVIEW.md`,
`docs/API_INTEGRATION_FIXES.md`, `docs/FLUTTER_WEB_API_CONNECTIVITY_FIX.md`.

**Feature records** — `docs/ssg-president-role.md`, `docs/ADMIN_USER_MANAGEMENT_FEASIBILITY.md`,
`docs/ADMIN_USER_MANAGEMENT_IMPLEMENTATION.md`. **Process** — `docs/PR_GUIDE.md`,
`backend-laravel/AGENTS.md`.

Naming: point-in-time docs get a `_YYYY-MM-DD` suffix and a row above. When a doc stops
describing reality, label it historical inside the file instead of deleting it — the
reasoning is worth keeping. (One exception removed: `FLUTTER_AUDIT_FIXES_APPLIED.md` was a
byte-identical copy of `FLUTTER_AUDIT_2026_09_13.md`.)

## Deploy

[`deploy/README.md`](deploy/README.md) is the runbook: `setup-server.sh` brings up nginx +
PHP-FPM + systemd from the unit files in that folder, `deploy.sh` publishes a release, and
`omnivote:backup` (driven by `omnivote-backup.timer`) writes an AES-256-GCM snapshot.
`security:assert-production-config` is the fail-fast guard that refuses to serve on unsafe
settings. CI runs the tests on GitHub-hosted runners and deploys only from the self-hosted
runner on the app server, so nothing is exposed publicly. APK build steps:
`docs/08_BUILD_RELEASE.md`.

## House rules

- `backend-laravel/AGENTS.md` defines agent jurisdictions — backend vs. frontend ownership,
  the cross-domain limit, and "check `git status` before mass file operations". Two agents
  sharing this working tree have already caused lost work; commit before handing off.
- `docs/PR_GUIDE.md` for branch and PR conventions.
- Secrets stay out of git: `.env*`, `*.jks`, `*.keystore`, `keystore.properties` are ignored
  on purpose.

