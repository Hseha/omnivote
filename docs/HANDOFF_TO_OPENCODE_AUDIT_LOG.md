# Handoff → OpenCode — Audit Log UI + Admin `noindex`

> **From:** OpenCode session, 2026-10-14. **To:** OpenCode / frontend implementer.
> **Purpose:** add the admin Audit Log screen and `noindex` meta that complement Cline's backend work (`docs/HANDOFF_TO_CLINE_ADMIN_HARDENING.md`).
> **Scope:** `admin-react/src/**` only. `git status` first (AGENTS.md §3). Agree the endpoint shape with Cline before wiring.

---

## 1. Context — do not re-derive

- The admin console stays intentionally public via Funnel (`https://debian.tail7e9e1e.ts.net/admin/`). Cline is adding an **nginx HTTP Basic** gate in front of `/admin*`, `/api/admin*`, `/sanctum*`. Basic is transparent to the SPA (the browser reuses the creds for same-origin XHR), so **no SPA auth changes are needed for it**.
- Admin auth uses Sanctum stateful cookies via `src/lib/api.js` (`API_BASE_URL=''`, same-origin) and `src/lib/auth.js`. Keep using the existing `api` axios instance — do not add a new HTTP client.
- View routing: screens are switched in `src/App.jsx`; allowed views per role live in `src/lib/permissions.js` (`ROLE_VIEWS`); nav items in `src/components/Sidebar.jsx`; settings tabs in `src/Settings.jsx` (+ `Settings.css`).
- The dashboard's "audit" widget is actually fed by **notifications**, not `audit_logs` (`src/components/DashboardWidgets.jsx:117,368-401`). Don't confuse the two.

### Branching (do this first)
Create a feature branch before making any changes — do **not** work on `main`:
```bash
git checkout main && git pull
git checkout -b security/admin-audit-ui
```
Branch off `main`. Cline works the backend/nginx slice on `security/admin-hardening-backend`. If this UI needs Cline's `GET /api/admin/audit-logs`, merge/rebase that branch in; otherwise merge both to `main` via PR separately.

## 2. Dependency on Cline (agree before coding)

Cline adds `GET /api/admin/audit-logs` behind an admin-only `audit.view` permission. Proposed contract (confirm and match exactly):

```
GET /api/admin/audit-logs?action=&user_id=&entity_type=&date_from=&date_to=&page=1&per_page=25
200 {
  "data": [
    { "id", "user_id", "user_name", "action", "entity_type", "entity_id",
      "details", "ip_address", "user_agent", "created_at" }
  ],
  "meta": { "current_page", "last_page", "per_page", "total" }
}
```

> **Schema note:** `audit_logs.user_agent` is a **nullable** column added by this work
> (migration `2026_10_14_000001_add_user_agent_to_audit_logs_table.php`); the API records
> the client user-agent on each row, and the UI renders it when present. `user_id` is
> nullable (failed pre-auth logins record `null` → UI shows "System").
>
> **Implemented (2026-10-14):** backend `GET /api/admin/audit-logs` (permission `audit.view`),
> `user_agent` column + recording, and admin sign-in auditing (success/failure/lockout/denied/
> 2FA-failure/logout); frontend `noindex` meta, `src/AuditLog.jsx` + `src/AuditLog.css`,
> `formatDateTime()` in `src/lib/datetime.js`, `audit` added to `ROLE_VIEWS.admin`, and the
> route/nav wiring. The screen shows a clear message on `403` (no `audit.view`) and `404`
> (endpoint not deployed yet).

## 3. Tasks

### 3.1 `noindex` (do first)
- Add `<meta name="robots" content="noindex, nofollow" />` to `admin-react/index.html`.

### 3.2 New `src/AuditLog.jsx` + `src/AuditLog.css`
- Model on `src/UserManagement.jsx` (filters, paginated table, loading/empty/error states).
- Columns: **When** (`created_at` via `src/lib/datetime.js`; note times are `+0800`), **Actor** (`user_name`/`user_id`), **Action** (human label), **Entity** (`entity_type` + `entity_id`), **Details** (`details`), **IP** (`ip_address`).
- Filters: action (dropdown of known codes), actor, entity type, date range. Pagination via `meta`.
- Keep an `ACTION_LABELS` dictionary with a raw-code fallback (Cline keeps adding codes — don't crash on unknowns).
- Read-only; no mutations.

### 3.3 Register the view
- `src/App.jsx`: import + add the screen to the view switch (mirror `Settings`/`UserManagement`).
- `src/lib/permissions.js`: add `'audit'` to `ROLE_VIEWS.admin` **only** (server `audit.view` remains the real boundary; ROLE_VIEWS is UX-only).
- `src/components/Sidebar.jsx`: add the nav entry, gated by role. Suggested under Settings, or a "Security" group.

### 3.4 Settings integration (choose one)
- Dedicated sidebar view (recommended), **or** a "Security" tab in `src/Settings.jsx` following the existing tab pattern.

### 3.5 Optional — fix the mislabeled dashboard widget
- `src/components/DashboardWidgets.jsx` labels `recent_actions` audit-ish but it is notifications (`AdminDashboardController:53`). Rename to "Recent notifications", or point at a real recent-audit feed if Cline exposes one. Coordinate; keep minimal.

## 4. Acceptance criteria
- Admin sees an "Audit Log" nav item; `teacher`/`ssg_president` do not; a 403 is handled via the existing `onForbiddenHandler`.
- Screen lists real rows with working filters + pagination and clean loading/empty/error states.
- `noindex` meta present in the built `index.html`.
- All calls go through `src/lib/api.js` (consistent CSRF/session/401 handling).

## 5. Out of scope (Cline)
- `GET /api/admin/audit-logs` + `audit.view` + route middleware.
- Writing audit rows (login/failure/lockout/2FA-failure/logout).
- nginx Basic gate, `.env`, `ProductionConfigGuard`, limiter changes.
