# Handoff → Cline — Harden the Public Admin Console (Tailscale Funnel)

> **From:** OpenCode session, 2026-10-14. **To:** Cline (primary backend/nginx jurisdiction).
> **Purpose:** secure the admin console while it stays publicly reachable via Funnel.
> **Scope:** read fully before touching anything. `git status` first (AGENTS.md §3). Do not re-derive the facts in §2 — they are verified from the live server.
> **Out of scope (OpenCode):** React admin UI — see `docs/HANDOFF_TO_OPENCODE_AUDIT_LOG.md` (audit-log screen + `noindex`).

---

## 1. Objective

The admin console must remain reachable by anyone who has the URL
`https://debian.tail7e9e1e.ts.net/admin/` (Funnel), but be properly secured.
Two layers:

1. **nginx HTTP Basic** in front of the admin surface (only viable pre-auth gate; the URL is already being scanned — see §2).
2. Existing app auth (Sanctum session + mandatory TOTP 2FA) stays underneath.

Michael's locked decisions (2026-10-14):
- Pre-auth gate = **nginx HTTP Basic** (shared secret).
- Containment = **revoke admin sessions + rotate panel passwords & 2FA** (precautionary).
- Keep **2FA mandatory** for all panel roles.

### Branching (do this first)
Create a feature branch before making any changes — do **not** work on `main`:
```bash
git checkout main && git pull
git checkout -b security/admin-hardening-backend
```
Branch off `main`. OpenCode works the frontend slice on `security/admin-audit-ui`. If the audit-log UI needs Cline's endpoint, merge that branch in; otherwise keep them separate and merge both to `main` via PR.

---

## 2. VERIFIED STATE — do not re-investigate

Server: `nyx@debian` (`debian.tail7e9e1e.ts.net`), repo `/var/www/omnivote`.

| Fact | Evidence |
|---|---|
| Funnel `1.98.10` → `http://127.0.0.1:8080` (the funnel vhost), `server_name debian.tail7e9e1e.ts.net` | `tailscale funnel status` |
| **Public-admin root cause:** every `if ($funnel_client) { return 404; }` in the live `/etc/nginx/sites-available/omnivote-funnel-api` is **commented out** (nginx -T lines 307, 311, 331, 337, 343, 348) | `sudo nginx -T` |
| **Repo↔server drift:** `deploy/nginx-funnel-api.conf` in the repo still has those gates **active** → a redeploy re-copies it and **silently re-closes admin** | compare repo vs live |
| Main vhost `:443` (`deploy/nginx-omnivote.conf`) also serves `/admin/` with **no gate** | `nginx -T` |
| **No real client IP:** every access-log line is `127.0.0.1`; default log format has no `$http_x_forwarded_for` | `/var/log/nginx/access.log` |
| Server `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://debian.tail7e9e1e.ts.net`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=false`. Guard passes. `SANCTUM_STATEFUL_DOMAINS`, `TRUSTED_HOSTS`, `FRONTEND_URL`, `SESSION_SAME_SITE` are **unset** | `artisan security:assert-production-config --check` + `.env` grep |
| External recon seen: `GET /admin/index.php.yml` (200 via SPA fallback), `curl/8.22.0` probes | access log |
| **No proven breach:** all *sensitive* admin API 2xx calls (`/users`, `/settings`, `/backups`, `/registrar/import`, `/election/config`) are from the owner's Firefox UA; `curl` only ever hit `/api/admin/login` (Oct 7) with no authenticated follow-through | `zgrep` over rotated logs |
| Admin auth core is strong and must stay: Sanctum stateful cookie + CSRF, bcrypt, generic credential errors, per-account + per-IP throttle, exponential backoff, mandatory TOTP 2FA, server-side role/permission re-checked per request | `AdminAuthController`, `HandlesLoginThrottling`, `EnsurePermission` |
| `admin-login` / `login` named limiters in `AppServiceProvider` are **dead code** (trait uses raw `RateLimiter` keys with those strings as prefixes; no route uses `throttle:admin-login`) | grep |
| `ProductionConfigGuard` checks only `app.debug`, `session.secure`, `app.url` https, `app.key` — **not** `SESSION_ENCRYPT` | `ProductionConfigGuard.php` |

---

## 3. TASKS

### Phase 0 — Contain (server; preserve evidence first)

```bash
cd /var/www/omnivote/backend-laravel
# snapshot evidence (read-only) BEFORE revoking anything
sudo -u omnivote-deploy php8.4 artisan tinker --execute="
file_put_contents('/tmp/omni-incident-'.date('Ymd-His').'.json', json_encode([
 'panel' => DB::table('users')->whereIn('role',['admin','teacher','ssg_president'])
   ->get(['id','email','role','is_active','two_factor_enabled','failed_login_attempts','locked_until']),
 'sessions' => DB::table('sessions')->orderByDesc('last_activity')->limit(200)
   ->get(['user_id','ip_address','user_agent','last_activity']),
 'audit' => DB::table('audit_logs')->orderByDesc('created_at')->limit(300)->get(),
], JSON_PRETTY_PRINT)); echo 'saved';"
sudo cp /var/log/nginx/access.log /tmp/omni-access-$(date +%Y%m%d-%H%M%S).log
```

Then:
1. Revoke all admin sessions: `DELETE FROM sessions WHERE user_id IN (<panel ids>)`.
2. Force password reset for every panel account.
3. Rotate 2FA (disable + re-enroll) and regenerate recovery codes for every panel account.
4. Skim `audit_logs` for 2026-10-06 → 2026-10-09 for anything not attributable to the owner Firefox UA (expected: none).

### Phase 1 — nginx: pre-auth gate + reconcile drift

Edit **both** `deploy/nginx-omnivote.conf` and `deploy/nginx-funnel-api.conf`:

- Add to the admin-only locations (`= /admin`, `/admin/`, `= /api/admin`, `^~ /api/admin/`, `^~ /sanctum/`):
  ```nginx
  auth_basic "OmniVote Admin";
  auth_basic_user_file /etc/nginx/.htpasswd-omnivote;
  ```
  Use the **same realm** in both vhosts so the browser reuses the Basic credentials for same-origin XHRs to `/api/admin/*`.
- On `/admin/`: `add_header X-Robots-Tag "noindex, nofollow" always;`
- Add a `/robots.txt` serving `User-agent: *` / `Disallow: /admin/`.
- In `nginx-funnel-api.conf`: **remove the dead** `map $http_tailscale_funnel_request $funnel_client` and the commented gates; write the intentional public-admin design as a comment. (Optionally keep a gate on `/up` only.)
- **Do NOT** put Basic on the public student API (`/api/*` non-admin) — students don't hold it.

Create the password file out-of-band (never commit):
```bash
sudo htpasswd -c /etc/nginx/.htpasswd-omnivote omnivote
sudo nginx -t && sudo systemctl reload nginx
```
Reconcile the live server to the repo templates (copy → `nginx -t` → reload) so a future deploy can't flip the gate back.

### Phase 2 — Real client IP

After a public login, check `DB::table('sessions')->latest('last_activity')->first()->ip_address`.
- If it is `127.0.0.1`/null (expected — tailscaled passes no XFF): **neutralize the per-IP login limiter** in `app/Http/Controllers/Concerns/HandlesLoginThrottling.php` so a scanner cannot trigger a global (loopback-keyed) lockout. Keep per-account throttling.
- Document the limitation in `deploy/README.md`.

### Phase 3 — Laravel / env / guard / limiter

- `.env` (server):
  ```
  SANCTUM_STATEFUL_DOMAINS=debian.tail7e9e1e.ts.net
  TRUSTED_HOSTS=debian.tail7e9e1e.ts.net,100.84.115.25,127.0.0.1,localhost
  FRONTEND_URL=https://debian.tail7e9e1e.ts.net
  SESSION_ENCRYPT=true
  ```
  Then `php8.4 artisan config:cache` + `sudo systemctl restart php8.4-fpm`. (Note: `SESSION_ENCRYPT=true` invalidates all existing sessions — expected, and pairs with the Phase 0 revoke.)
- `app/Support/ProductionConfigGuard.php`: add assertions for `SESSION_ENCRYPT`, `SANCTUM_STATEFUL_DOMAINS`, `TRUSTED_HOSTS`.
- `app/Providers/AppServiceProvider.php`: delete (or wire) the dead `admin-login` limiter; add a route-level `throttle` on `POST /admin/login`.
- Tighten Settings → Security defaults for an internet-facing panel (max attempts, password policy, session timeout).

### Phase 4 — Admin-auth audit (backend half)

- In `AdminAuthController` / `HandlesLoginThrottling`, write `AuditLog` rows for: **login success, failed login, lockout, 2FA failure, logout**. Record `user_agent` (IP may be loopback).
- Add `GET /api/admin/audit-logs` behind a new admin-only `audit.view` permission (`config/permissions.php` → `admin`). Coordinate the response shape with OpenCode — the UI handoff assumes:
  ```json
  {
    "data": [
      { "id", "user_id", "user_name", "action", "entity_type", "entity_id",
        "details", "ip_address", "user_agent", "created_at" }
    ],
    "meta": { "current_page", "last_page", "per_page", "total" }
  }
  ```
  Support filters `action`, `user_id`, `entity_type`, `date_from`, `date_to`, `page`, `per_page`.

### Phase 5 — Verify & document

- Off-tailnet:
  - `/admin/` → Basic prompt → app login → 2FA → in.
  - `GET /admin/index.php.yml` returns **401** (Basic), not 200.
  - Scanner UAs get 401.
- `php8.4 artisan security:assert-production-config --check` passes with the new assertions.
- Update `deploy/README.md`: admin intentionally public behind Basic; IP caveat; post-suspicion rotation runbook; keep both vhosts in sync.

---

## 4. ACCEPTANCE CRITERIA

- Off-tailnet, any unauthenticated request to `/admin*`, `/api/admin*`, `/sanctum*` is rejected at nginx with `401` (Basic), not served.
- Both vhost files in the repo match the server (no drift); a redeploy leaves admin public-but-gated.
- `SESSION_ENCRYPT=true`; stateful/hosts env explicit; `ProductionConfigGuard` enforces them.
- Admin auth events (success / failure / lockout / 2FA failure / logout) appear in `audit_logs`.
- `nginx -t` clean; `deploy/README.md` updated.

---

## 5. FILES TOUCHED (Cline)

- `deploy/nginx-omnivote.conf`, `deploy/nginx-funnel-api.conf`, `deploy/README.md`
- `backend-laravel/.env`
- `backend-laravel/app/Support/ProductionConfigGuard.php`
- `backend-laravel/app/Providers/AppServiceProvider.php`
- `backend-laravel/app/Http/Controllers/AdminAuthController.php`
- `backend-laravel/app/Http/Controllers/Concerns/HandlesLoginThrottling.php`
- `backend-laravel/app/Support/AuditLog.php` (call sites)
- `backend-laravel/routes/api.php`, `backend-laravel/config/permissions.php`
- `backend-laravel/tests/Feature/` (auth-audit; guard assertions)

## 6. OUT OF SCOPE (OpenCode)

- `admin-react/src/**`: Audit Log screen, `noindex` meta, wiring `GET /api/admin/audit-logs`.
- See `docs/HANDOFF_TO_OPENCODE_AUDIT_LOG.md`.
