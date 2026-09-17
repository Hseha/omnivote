# OmniVote Architecture Guide

This document is the system-level reference for OmniVote. It explains how the
web admin panel, Flutter student app, Laravel API, database, and production
services fit together.

## 1. System at a glance

```text
                         HTTPS / Tailscale
                                |
                    Nginx (TLS, static files)
                                |
                    Laravel public/index.php
                                |
                 PHP-FPM + Laravel application
                    /                    \
          React admin SPA              Flutter app
       cookie + CSRF (Sanctum)     bearer token (Sanctum)
                                |
                         MariaDB (production)
                    SQLite (local development)
```

The repository contains three deployable application areas:

| Area | Directory | Responsibility |
| --- | --- | --- |
| Laravel API | `backend-laravel/` | Authentication, election phases, authorization, voting, results, imports, and persistence |
| React admin | `admin-react/` | Browser-based administration and role-specific dashboards |
| Flutter client | `user-flutter/` | Student registration, candidacy, voting, ballot receipt, and published results |

The `deploy/` directory contains server provisioning, Nginx, PHP-FPM, and
queue-worker configuration. The `docs/` directory contains focused operational
and feature documentation.

## 2. Repository structure

```text
omnivote/
├── backend-laravel/
│   ├── app/
│   │   ├── Http/Controllers/       API use cases
│   │   ├── Http/Middleware/        phase and permission gates
│   │   ├── Http/Requests/          request validation
│   │   └── Models/                 Eloquent entities and relationships
│   ├── config/permissions.php      server-side role catalog
│   ├── database/migrations/        schema history
│   ├── database/seeders/           development/bootstrap data
│   ├── routes/api.php               API contract and middleware wiring
│   └── public/                     Nginx document root
├── admin-react/
│   ├── src/App.jsx                  auth-aware view shell
│   ├── src/Admindashboard.jsx       admin/teacher navigation
│   ├── src/SsgPresident.jsx         SSG dashboard
│   ├── src/Results.jsx               live API results presentation
│   └── src/lib/                    Axios, auth, and view permissions
├── user-flutter/
│   ├── lib/data/                   models, repositories, services, API client
│   ├── lib/features/               Riverpod providers and screens
│   └── lib/core/                   constants, routing, shared widgets, errors
├── deploy/                         server setup and service configuration
└── docs/                           architecture, audits, and runbooks
```

## 3. Backend architecture

Laravel is the source of truth for business rules. Controllers coordinate a
request, form requests validate input, models access the database, and
middleware rejects requests that are unauthenticated, unauthorized, or in the
wrong election phase.

### Authentication

- **React admin:** Laravel Sanctum stateful session cookies plus CSRF protection.
- **Flutter student app:** Laravel Sanctum personal access tokens sent as
  `Authorization: Bearer <token>`.
- Passwords are hashed through the `User` model cast and are never returned in
  API responses.
- `GET /api/admin/me` and `GET /api/auth/me` restore the current identity.

### Authorization

`backend-laravel/config/permissions.php` defines the permissions for:

- `admin`: full administration
- `teacher`: dashboard, candidate review, and results
- `ssg_president`: certified officers and announcements
- `student`: mobile voting and student flows

`EnsurePermission` enforces these permissions on the server. React navigation
in `admin-react/src/lib/permissions.js` only improves usability; hiding a
screen is not a security boundary.

### Election phases

The lifecycle is controlled by the `phases` table and exposed through
`GET /api/election/status`:

1. `registration` - registration and candidacy applications are available.
2. `voting_open` - ballot drafting and submission are available.
3. `voting_closed` - results and certified officer data are available.

`CheckPhase` protects phase-specific routes. Clients must refresh phase state
and show a clear unavailable/offline state rather than inventing data.

## 4. API surface

All routes below are under the configured API base URL.

| Surface | Key endpoints | Client |
| --- | --- | --- |
| Admin auth | `/admin/login`, `/admin/me`, `/admin/logout` | React |
| Admin dashboard | `/admin/dashboard-overview` | React |
| Candidate review | `/admin/candidates` | React |
| Registrar | `/admin/registrar/import`, `/admin/registrar/imports` | React |
| Election setup | `/admin/election/config` | React |
| Admin results | `/admin/results` | React |
| SSG | `/admin/ssg/officers`, `/admin/ssg/announcements` | React |
| Public election state | `/election/status` | React and Flutter |
| Public reads | `/positions`, `/candidates`, `/announcements` | Both clients |
| Student auth | `/auth/login`, `/auth/register`, `/auth/me`, `/auth/logout` | Flutter |
| Student registration | `/registration/me` | Flutter |
| Candidacy | `/candidacy/me`, `/candidate/apply` | Flutter |
| Ballot and voting | `/ballot/me`, `/ballot/me/submit`, `/vote` | Flutter |
| Results | `/results`, `/results/verify` | Flutter |

The admin results response is structured as:

```json
{
  "results": [
    {
      "position_key": "president",
      "position_label": "President",
      "candidates": [
        { "id": 1, "name": "Candidate", "votes": 10 }
      ]
    }
  ]
}
```

The React results screen calculates totals, percentages, and rankings from this
payload. It must not use fallback mock totals.

## 5. Data model

Important entities and their roles:

- `users`: students and panel users; includes role, student identity, year/block,
  and voting status.
- `positions`: offices, tier/order, seat count, and active state.
- `candidates`: candidacy applications and approval status.
- `phases`: current election lifecycle state.
- `ballot_drafts`: a student's saved in-progress ballot.
- `vote_ledger`: immutable vote records used for counting and auditability.
- `registrar_imports`: imported registrar/student records and import history.
- `announcements`: public or panel-managed SSG announcements.
- `personal_access_tokens`: Sanctum tokens for Flutter authentication.
- `sessions`, `cache`, and `jobs`: Laravel operational tables when configured for
  database-backed storage.

The migration directory is append-only in normal development. Never edit an
already-applied production migration; add a new migration for a schema change.

## 6. Client data flow

### React admin

```text
AdminLogin
  -> AuthContext restores/logs in user
  -> App.jsx chooses permitted view
  -> feature screen calls src/lib/api.js
  -> Laravel session + CSRF middleware
  -> JSON response rendered by the screen
```

`AuthContext` handles session identity and forwards server messages for 403
responses. `api.js` handles the CSRF cookie, credentials, session expiry, and
centralized unauthorized/forbidden callbacks.

### Flutter

```text
Screen
  -> Riverpod provider
  -> repository
  -> service
  -> Dio apiClient
  -> Sanctum bearer token
  -> Laravel JSON response
```

Tokens are kept in memory on web and platform secure storage on native
platforms. Providers should expose loading, data, empty, offline/error, and
phase-blocked states. Network failures must never be replaced with fabricated
student, turnout, candidate, or result values.

## 7. Voting and integrity rules

1. The server phase gate is authoritative; client button state is only a UX
   safeguard.
2. A ballot submission is a state-changing operation and must be treated as
   non-retryable until its result is known.
3. HTTP `409` means the ballot was already submitted and must be shown as a
   distinct message.
4. Original server messages should be surfaced when available.
5. Results are only available after `voting_closed`.
6. Candidate approval and election configuration are protected admin actions.
7. Do not manually change vote counts or user voting flags in production without
   an auditable, reviewed procedure.

## 8. Configuration and environments

### Development

- Laravel may use SQLite.
- React can use Vite's development proxy.
- Flutter can override the API with:
  `--dart-define=API_BASE_URL=http://10.0.2.2:8000/api` on an Android emulator.
- Keep secrets in local `.env` files; commit only `.env.example` templates.

### Production

- Public host: `https://debian.tail7e9e1e.ts.net`
- Laravel API base: `https://debian.tail7e9e1e.ts.net/api`
- Nginx root: `/var/www/omnivote/backend-laravel/public`
- Database: MariaDB
- Runtime: Nginx + PHP-FPM
- Set `APP_ENV=production`, `APP_DEBUG=false`, real database credentials, a
  stable `APP_KEY`, secure session settings, and the correct Sanctum stateful
  domains.

Do not copy a development `.env` over the production `.env`. Production
credentials, TLS private keys, and tokens must remain on the server or in the
deployment secret store.

## 9. Deployment runbook

Preferred flow:

```text
feature branch -> reviewed PR -> GitHub main -> server fast-forward pull
```

On the server, inspect local work before changing branches:

```bash
cd /var/www/omnivote
git status --short
git fetch origin
git log --oneline --left-right origin/main...main
```

After approved changes are merged:

```bash
git switch main
git pull --ff-only origin main

cd backend-laravel
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

If the server hosts the React build:

```bash
cd ../admin-react
npm install
npm run build
```

Validate and reload services:

```bash
sudo nginx -t
sudo systemctl reload nginx
curl -sS -o /dev/null -w 'HTTP status: %{http_code}\n' \
  https://debian.tail7e9e1e.ts.net/up
curl -sS https://debian.tail7e9e1e.ts.net/api/election/status
```

The Flutter source on the server does not update installed devices. Build and
release the mobile application separately.

## 10. Operations and troubleshooting

Useful checks:

```bash
git status --short
sudo systemctl status nginx
sudo systemctl status php8.3-fpm
sudo tail -f /var/log/nginx/omnivote-error.log
php artisan about
php artisan migrate:status
```

Interpret common failures as follows:

- **401:** missing/expired session or bearer token; log in again.
- **403:** role/permission or election-phase restriction; show the server
  message and do not retry blindly.
- **409:** ballot already submitted; do not resubmit.
- **419:** stale/missing CSRF token; refresh the CSRF cookie/session.
- **5xx:** inspect Laravel and Nginx logs; do not replace the response with
  mock data.
- **Empty results:** verify the election is `voting_closed` and that the API
  returned the expected payload.

## 11. Safe change checklist

Before merging:

- Confirm the change belongs in the correct client or Laravel layer.
- Add or update a migration rather than altering production schema manually.
- Preserve server-side authentication, permission, and phase middleware.
- Check all loading, empty, offline, forbidden, conflict, and success states.
- Run the existing targeted lint, build, PHP syntax, or test commands.
- Review `git diff --check` and `git status --short`.
- Never commit `.env`, passwords, private keys, tokens, `vendor/`, `node_modules/`,
  build output unless the deployment process explicitly tracks it, or runtime
  logs.

Related references:

- [API integration fixes](API_INTEGRATION_FIXES.md)
- [SSG President role](ssg-president-role.md)
- [Nginx and Tailscale access](NGINX_TAILSCALE_TEAM_ACCESS.md)
- [Flutter audit fixes](FLUTTER_AUDIT_FIXES_APPLIED.md)
- [Build and release guide](08_BUILD_RELEASE.md)
