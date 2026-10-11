# ⏳ PENDING — Server Monitoring Plan

> **Status:** NOT STARTED — plan only, no code written.
> **Created:** 2026-10-09 · **Owner decision needed before implementation.**
> Tackle later: answer the 3 decisions in §1, then hand to opencode (or pick up manually).

## 1. Decisions needed (blocking)

- [ ] **Who gets alerts** — all panel staff (`admin` + `teacher` + `ssg_president`, like `Notifier::toAdmins` does today) or admins only?
- [ ] **Is production SMTP live?** Yes → email alerts from day one. No/unknown → in-app alerts only + add an "email broken" check.
- [ ] **Thresholds** — accept defaults (disk <15% free = red · no backup in 26 h = red · oldest queued job >30 min = red, 5-min cadence) or custom numbers?

## 2. Background (why this shape)

- Health route exists: Laravel `/up` enabled in `backend-laravel/bootstrap/app.php:20`. Keep it coarse/public.
- Alert channel exists: `app/Support/Notifier.php` → `toAdmins()` writes in-app rows always, email only if the matching `settings → notifications` gate is on. Reuse it; add a `systemAlerts` gate.
- Scheduling pattern exists: `deploy/omnivote-backup.{service,timer}` (systemd timer, 5-min style allowed). **No `schedule:run` cron was found** in `deploy/` — so new timer files, not Laravel scheduler.
- Dashboard pattern exists: `AdminDashboardController::overview()` already piggybacks `TermArchive::runIfDue()`; permission `settings.view` fits the detail endpoint (`config/permissions.php`).
- In-repo checks cannot report a fully dead box → pair with one free external pinger (doc-only).

## 3. Backend

- [ ] `backend-laravel/app/Support/SystemHealth.php` — pure check logic (ok/warn/fail + detail), unit-testable. Checks:
  - [ ] DB ping + query latency
  - [ ] Disk free % + `storage/` writable
  - [ ] Queue: pending count, oldest pending age, `failed_jobs` delta
  - [ ] Last successful backup age vs retention setting
  - [ ] Phase vs configured windows drift (e.g. window open but phase ≠ expected)
  - [ ] Pending migrations
- [ ] `backend-laravel/app/Console/Commands/HealthCheck.php` (`omnivote:health`) — runs checks, stores snapshot, alerts **only on state transitions** (green→red, red→green), never repeats on steady state.
- [ ] `GET /admin/system/health` — admin-only via `permission:settings.view`; returns check detail. Keep public `/up` coarse.
- [ ] `systemAlerts` gate in Settings → Notifications (email where SMTP exists, in-app always).
- [ ] `backend-laravel/tests/Feature/SystemHealthTest.php` — green path, red path, no-spam-on-repeat path.

## 4. Scheduling + deploy

- [ ] `deploy/omnivote-health.service` + `deploy/omnivote-health.timer` (5-min, same pattern as backup timer).
- [ ] Register in `deploy/setup-server.sh` + document in `deploy/README.md`.
- [ ] Verify on server: `systemctl list-timers` shows it.

## 5. Admin UI

- [ ] `system_health` block in `AdminDashboardController::overview()` — read the stored snapshot (no extra queries on the hot path).
- [ ] Dashboard status card: green/amber/red + "last checked X min ago" + link to alert history (existing Notification center).
- [ ] Reuse `dashboard.view` permission — no new roles.

## 6. External pinger (doc-only, ~15 min in a browser)

- [ ] Free UptimeRobot/cron-job.org hitting public `GET /api/election/status` every 5 min.
- [ ] Record it in `deploy/README.md` next to the backup timer.

## 7. Go-live verification

- [ ] Stop MySQL briefly → admin alert within ~10 min → restart → "recovered" alert.
- [ ] Break a backup run (or simulate old backup) → red state, **single** alert, no repeat spam.
- [ ] Non-admin roles get 403 on `GET /admin/system/health`.
- [ ] Dashboard card reflects green/amber/red correctly.

## 8. Explicitly out of scope

Prometheus/Grafana · Sentry-style APM · mobile push to admins · log aggregation beyond `storage/logs` + `journalctl`.
