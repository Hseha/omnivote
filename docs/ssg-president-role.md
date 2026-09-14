# SSG President Role — Design & Implementation Record

**Date:** 2026-09-13
**Status:** Approved design — not yet implemented
**Scope:** `backend-laravel/`, `admin-react/`, `user-flutter/`
**Decision:** Add `ssg_president` as a **fourth fixed role** (`admin`, `teacher`, `student`, `ssg_president`) with a hardcoded permission catalog per role. The proposed per-user permission-toggle system (`user_permissions` table) was reviewed and **deliberately deferred** — this is an engineering decision, not an oversight. See sections 4, 5, and 8.

---

## 1. Overview

The SSG President role gives the winning student — after election results are certified — a post-election presence in the same React admin panel used by Admin and Teacher, restricted to exactly two governance concerns: viewing the SSG officer roster and managing SSG announcements (create, edit). It exists because the school needs a standing channel from the elected student government to the student body (an always-visible announcements feed in the Flutter app) without handing the winner any election-administration power. Keeping the President as a separate, minimal role — rather than reusing Admin or Teacher — enforces a conflict-of-interest boundary: the person who benefits from election outcomes must never control election data, phase timing, registrar imports, account provisioning, or candidate approval, all of which remain with Admin, and the latter two of which remain with Teacher. The President account is not a new account; it is an existing student account whose `role` is promoted by an Admin once results are certified.

---

## 2. Role Definition

Four fixed roles, stored in `users.role` (string ENUM, default `student`). Permissions are a hardcoded catalog per role in `config/permissions.php` (to be created — see §3). Roles are mutually exclusive: one user, one role, one catalog.

| Role | Permissions (catalog keys) | What it means concretely |
|---|---|---|
| `admin` | Full system access: `import_registrar`, `control_election_phase`, `provision_accounts`, `manage_accounts`, `approve_reject_candidacy`, `view_results_dashboard`, `publish_results`, `view_officer_roster`, `manage_announcements` | Superset of everything. Only role that can create accounts and assign roles. |
| `teacher` | `review_applications`, `approve_reject_candidacy`, `view_results_dashboard`, `publish_results` | Election-commission work only. No registrar import, no phase control, no account management, no announcements. |
| `student` | `vote`, `apply_as_candidate`, `view_results`, `view_announcements` | Flutter app user. |
| `ssg_president` | `view_officer_roster`, `post_announcement`, `edit_announcement` | Post-election governance only. No election administration of any kind. |

Notes on the catalog:

- **`publish_results` (teacher + admin) vs `control_election_phase` (admin only).** In the current code these are entangled: results become publicly visible when the phase flips to `voting_closed`, and the phase flip lives in `PUT /api/admin/election/config` (`backend-laravel/app/Http/Controllers/ElectionController.php::updateConfig`, lines 88–118). To honor the catalog above, the implementation adds an explicit, separate publish action — `POST /api/admin/results/publish`, writing a `results_published` flag to the existing `election_settings` key/value table (created by migration `2026_09_05_000005_activate_phases_and_election_settings.php`) — and the public `GET /api/results` gains a `results_published` check on top of its existing `checkPhase:voting_closed` gate. Phase control (`PUT /admin/election/config`) becomes admin-only; `publish_results` becomes a distinct action available to admin and teacher.
- **`edit_announcement` covers the edit lifecycle** (update, and unpublish/remove) of announcements created by the President. Admin retains `manage_announcements` (full CRUD on any announcement).
- **`ssg_president` is post-certification only.** The role is granted by Admin after results are certified and (optionally) revoked after the officer's term, returning the account to `student`. Nothing in the voting pipeline depends on it, and it must never be granted before `voting_closed`.
- **Known behavior change:** `StudentAuthController::login` (`backend-laravel/app/Http/Controllers/StudentAuthController.php:32`) rejects any user whose role is not exactly `student`. Once an account is promoted to `ssg_president`, it can no longer log into the Flutter student app. This is acceptable — the election is closed by the time promotion happens — and is listed in §6 so nobody treats it as a bug.

## 3. How to Implement

Follows the same pattern the codebase already uses for `teacher`. Every path below is a real file verified to exist during the review passes, except files marked **NEW**.

### Step 1 — Widen the role ENUM

**NEW** `backend-laravel/database/migrations/2026_09_13_000001_add_ssg_president_role_to_users.php`

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->enum('role', ['admin', 'teacher', 'candidate', 'student', 'ssg_president'])
              ->default('student')->change();
    });
}
```

The existing column is defined in `backend-laravel/database/migrations/2026_08_24_000001_add_omnivote_fields_to_users_table.php:13`. Keep the vestigial `candidate` value — rows may reference it and dropping it would fail. Note the dev environment runs SQLite (see `config/database.php`), where enum `change()` needs `doctrine/dbal` or a rebuild-table migration; the safest pattern is the rebuild one already used by `2026_09_05_000004_extend_registrar_imports_table.php` (create temp table → copy → rename). Verify with `php artisan migrate` against a fresh dev DB before shipping.

### Step 2 — Create the permission catalog

**NEW** `backend-laravel/config/permissions.php`

```php
return [
    'roles' => [
        'admin'         => '*',   // superuser: bypasses grants, see §4
        'teacher'       => ['review_applications', 'approve_reject_candidacy',
                            'view_results_dashboard', 'publish_results'],
        'student'       => ['vote', 'apply_as_candidate', 'view_results',
                            'view_announcements'],
        'ssg_president' => ['view_officer_roster', 'post_announcement',
                            'edit_announcement'],
    ],
];
```

### Step 3 — Add the permission middleware (dual check: catalog ∧ grant)

**NEW** `backend-laravel/app/Http/Middleware/EnsurePermission.php`

```php
public function handle(Request $request, Closure $next, string $permission)
{
    $user  = $request->user();
    $perms = config('permissions.roles.' . $user?->role, []);

    // (a) role-catalog check, with (b) superuser bypass for admin
    $allowed = $perms === '*' || in_array($permission, $perms, true);

    if (! $user || ! $allowed) {
        return response()->json(['message' => 'Forbidden'], 403);
    }
    return $next($request);
}
```

Register the alias in `backend-laravel/bootstrap/app.php` (lines 17–20, next to the existing `checkPhase` alias):

```php
->alias([
    'checkPhase' => \App\Http\Middleware\CheckPhase::class,
    'permission' => \App\Http\Middleware\EnsurePermission::class,
]);
```

This middleware is also the retrofit point for every existing `/api/admin/*` route (Step 5), which currently sits behind bare `auth` with **no role check** — the single most important change in this feature.

### Step 4 — Open the panel to `ssg_president`

Edit `backend-laravel/app/Http/Controllers/AdminAuthController.php:33`:

```php
if (! in_array($user->role, ['admin', 'teacher', 'ssg_president'], true)) {
    return response()->json(['message' => 'Forbidden'], 403);
}
```

Also fix the panel's role label in `backend-laravel/app/Http/Controllers/AdminDashboardController.php:33` — it currently maps any non-admin to `'Teacher'`; map `ssg_president` to `'SSG President'`.


### Step 5 — Retrofit permission gates onto every admin route

In `backend-laravel/routes/api.php` (admin group, lines 40–63) — add `'permission:…'` to each route. Current enforcement is **auth-only**, so this step closes existing holes as well as adding President-scoped routes:

```php
Route::middleware(['auth', 'permission'])->group(function () {
    Route::get('/dashboard-overview', ...)            // any panel role
    Route::get('/candidates', ...)                    ->permission('review_applications');
    Route::patch('/candidates/{candidate}', ...)      ->permission('approve_reject_candidacy');
    Route::post('/registrar/import', ...)             ->permission('import_registrar');
    Route::get('/registrar/imports', ...)             ->permission('import_registrar');
    Route::get('/election/config', ...)               ->permission('control_election_phase');
    Route::put('/election/config', ...)               ->permission('control_election_phase');
    Route::get('/results', ...)                       ->permission('view_results_dashboard');
    Route::post('/results/publish', ...)              ->permission('publish_results');   // NEW endpoint
    Route::get('/users', ...)                         ->permission('provision_accounts');// NEW endpoint
    Route::post('/users', ...)                        ->permission('provision_accounts');// NEW endpoint
    Route::patch('/users/{user}/role', ...)           ->permission('manage_accounts');   // NEW endpoint
    Route::get('/ssg/officers', ...)                  ->permission('view_officer_roster');
    Route::apiResource('/ssg/announcements', ...)     ->only(['store','update','destroy'])
                                                      ->permission('manage_announcements'); // see note
});
```

Also add the Flutter-facing read (public, outside both auth groups, mirroring `GET /election/status`):

```php
Route::get('/announcements', [AnnouncementController::class, 'publicIndex']); // no phase gate
```

The announcement **write** routes need a middleware composition: `permission:manage_announcements` *or* `permission:edit_announcement` — since EnsurePermission takes one permission, either register a second alias (`permissionEither`) or pass both and OR them inside the middleware. President's `edit_announcement` should additionally be scoped server-side to announcements authored by that user (a policy check in `AnnouncementController`, not just middleware).

### Step 6 — Admin promotes the student account (role assignment, not creation)

The requirement is: *Admin grants President access to an already-existing student account after certification.* This is a role UPDATE, and needs the first real account-provisioning endpoints in the codebase (today, accounts come only from the seeder `backend-laravel/database/seeders/UserSeeder.php` + `database/data/users.json`, and the React `admin-react/src/Settings.jsx` user table is mock data with no API behind it).

**NEW** `backend-laravel/app/Http/Controllers/AdminUserController.php`:

- `index()` — list panel-relevant users (search by name/email, filter by role).
- `updateRole(Request, User $user)` — validate `'role' => ['required', 'in:teacher,ssg_president,student']` (admin cannot be granted through this endpoint; promote admins only via seeder/CLI), and **refuse promoting to `ssg_president` unless the current phase is `voting_closed`** — enforcing the post-certification rule server-side:
  ```php
  if ($data['role'] === 'ssg_president'
      && (Phase::current()?->name ?? 'registration') !== 'voting_closed') {
      return response()->json(['message' => 'SSG President can only be assigned after results are certified.'], 422);
  }
  ```
  On successful promotion, also `$user->tokens()->delete()` to force re-login (session/token staleness — see §6).

**NEW** `backend-laravel/app/Http/Requests/UpdateUserRoleRequest.php` — with a real `authorize()` (check `manage_accounts`), **not** the copy-pasted `return true;` pattern found in the three existing FormRequests (`RegistrarImportRequest.php:9`, `UpdateCandidateStatusRequest.php:9`, `ElectionConfigRequest.php:9`).

### Step 7 — New domain: announcements + officer roster

**NEW** `backend-laravel/database/migrations/2026_09_13_000002_create_announcements_table.php` — `id`, `user_id` FK → users (author), `title`, `body` (text), `published_at` nullable, timestamps.

**NEW** `backend-laravel/app/Models/Announcement.php` — fillable, `author(): BelongsTo`, `scopePublished()`.

**NEW** `backend-laravel/app/Http/Controllers/AnnouncementController.php` — `publicIndex()` for Flutter (published only, no phase gate), `store/update/destroy` for the panel (President limited to own announcements).

**NEW** `backend-laravel/app/Http/Controllers/SsgOfficerController.php` — officer roster read. Derive officers from certified results rather than a new table: join `vote_ledger` tallies (top per SSG-tier position) with `candidates` + `positions` (`positions.tier` exists — school-tier offices seeded in `2026_09_05_000001_create_positions_table.php:35-39`). This auto-syncs with certification and adds no schema. Note: `docs/AUDIT.md:200` references an older `ssg_office` enum on candidates that no longer exists — do not model from that stale doc.

### Step 8 — React admin panel

`admin-react/src/App.jsx` has **no router** — navigation is `useState('dashboard')` + a `switch (currentView)` at lines 23–74. Follow that existing pattern (do not introduce react-router just for this):

- `admin-react/src/lib/roles.js` — **NEW**: `ROLE_LABELS = { admin: 'System Administrator', teacher: 'Teacher', student: 'Student', ssg_president: 'SSG President' }`, `ROLE_VIEWS` (allowed view keys per role), `useRolePermissions()` hook reading `useAuth().user.role`.
- `admin-react/src/App.jsx` — gate the `switch` by role (redirect President to their default view); add cases `'ssg-officers'`, `'ssg-announcements'`.
- `admin-react/src/Admindashboard.jsx` — replace the binary role label at lines 42–46 (it maps any non-admin to `'Teacher'` and any unknown role to `'System Administrator'` — a President would render as an admin) with `roles.js`; hide admin-only sidebar items for President.
- **NEW** `admin-react/src/SsgOfficers.jsx` + `SsgOfficers.css`, **NEW** `admin-react/src/SsgAnnouncements.jsx` + `SsgAnnouncements.css` — new screens following the existing screen/CSS-pair pattern.
- `admin-react/src/Settings.jsx` — wire the mock user table (lines 52–80) to `GET /admin/users` and add the role-promote action (student → ssg_president, phase-gated) hitting `PATCH /admin/users/{id}/role`.
- Every screen hardcodes a fake user in the sidebar ("Election Admin" / "Eleanor Vance" / pravatar): `Candidates.jsx:163-166`, `StudentRegistry.jsx:163-166`, `ElectionSetup.jsx:176-179`, `Results.jsx:247-250`, `Settings.jsx:245-248`. Replace with the auth-context user + `roles.js` label so the President sees their own name and role.
- `admin-react/src/lib/AuthContext.jsx` + `lib/auth.js` cache a display copy of the user in localStorage (`omnivote_user_display`) — explicitly not an authorization boundary; fine to keep, but the server remains the only source of truth.

### Step 9 — Flutter announcements feed

- **NEW** `user-flutter/lib/data/models/announcement_model.dart` — tolerant JSON parsing per `lib/data/models/student_model.dart` and `lib/core/utils/safe_json.dart` (null-safe, string-coercing).
- **NEW** `user-flutter/lib/data/repositories/announcement_repository.dart` — `GET /announcements` via the existing `lib/data/services/api_client.dart`.
- **NEW** `user-flutter/lib/features/dashboard/providers/announcements_provider.dart` — FutureProvider using the epoch-invalidation pattern from `lib/features/dashboard/providers/election_status_provider.dart`.
- **NEW** `user-flutter/lib/features/dashboard/widgets/announcements_feed.dart` — card widget.
- Edit `user-flutter/lib/features/dashboard/screens/dashboard_screen.dart` — insert the feed above `RegistrationBanner` (top of the ListView so it is always visible), rendered regardless of election phase; include it in the pull-to-refresh invalidation.
- **NEW** `user-flutter/test/announcement_model_test.dart` — follow the existing five test files' pattern.

No Flutter auth changes: the feed endpoint is a public read; the existing Dio client sends the student bearer token anyway, which is harmless.

### Step 10 — Seeder + backend tests

- Extend `backend-laravel/database/data/users.json` / `UserSeeder.php` with one `ssg_president` dev account so the panel flow is testable locally.
- **NEW** `backend-laravel/tests/Feature/EnsurePermissionTest.php` — the `tests/` directory contains only `TestCase.php` today (zero test coverage); these are the first feature tests in the suite. Cover: admin bypass, teacher blocked from registrar import, president blocked from candidates/election config, president allowed on officers/announcements, role-promotion refused before `voting_closed`, president cannot edit another author's announcement.

---

## 4. Why Fixed Roles, Not a Toggle System

**This IS RBAC.** Role-based access control is satisfied by fixed roles with fixed permission sets — that is the textbook definition. A per-user permission-override system would be an enhancement *beyond* RBAC (hybrid RBAC/ABAC), not a requirement of it. The design in §2 uses a `role` column plus a hardcoded catalog, and that is a complete, defensible RBAC implementation.

**Principle of least privilege.** Each role's catalog is the minimal set needed for its real-world job:

- Teacher gets election-commission duties only — no registrar import, no phase control, no account management. A teacher reviewing applications has no legitimate reason to upload the eligibility roster or change when voting ends.
- President gets governance duties only — no election administration at all. The President is a *product* of the election; giving them any election control creates a conflict of interest with future elections.
- Student gets voter/candidate duties only, enforced server-side by `StudentAuthController::login` rejecting any non-`student` role from the mobile app.

**Every account in a role needs identical permissions right now.** This is the decisive point. There is no concrete user story in which one teacher should approve applications while another cannot, or where one President can post announcements but another can only view them. A per-user toggle system solves a problem that has no owner, no request, and no example. When that changes — e.g., "department-specific teacher moderators" — the toggle system can be revisited with an actual requirement in hand (see §5).

**Cost asymmetry.** The toggle design adds: a `user_permissions` table, a grant-management API (which becomes the single most sensitive attack surface in the system — a bug there *widens* access rather than narrowing it), a toggle UI, dual-check logic in the middleware, session/token-revocation handling so revoked grants take effect immediately, and a test suite for all of the above. The fixed catalog adds: one config file, one middleware, and the same route-level gates either way. The fixed approach delivers 100% of the requirements at a fraction of the risk surface.

**Separation of concerns (election administration vs. SSG governance).** Admin and Teacher exist to run elections; the President exists to run student government afterwards. Encoding this separation in the role catalog — rather than in ad-hoc UI hiding — means the boundary is enforced server-side, where it actually binds, and survives any frontend change.

## 5. Permission-Toggle System — Considered and Deferred

**The proposal that was reviewed:** keep the `role` enum, define a hardcoded catalog per role in `config/permissions.php`, add a `user_permissions` table so Admin can toggle individual permissions per user **only within that user's role catalog**, and enforce both checks together at the middleware/policy level: (a) is the permission valid for this user's role, and (b) has it actually been granted to this specific user.

**Outcome: evaluated and intentionally NOT implemented for this version.** The decision is documented here so the reasoning is on record for project defense.

**Reasoning (from the feasibility review):**

1. **Over-engineered for current requirements.** Every stated requirement (Teacher's 4 permissions, President's 3, Admin's superset) is satisfiable with a static catalog + middleware. No requirement demands per-user variation.
2. **YAGNI.** No concrete user story exists for differential permissions within a role. The toggle system would be built "just in case," with no user to validate it against.
3. **New, high-value attack surface.** The grant-management endpoint would be the single most sensitive endpoint in the system (self-grant protection, admin-gating of the toggle API itself). A bug there widens access instead of narrowing it — the opposite of the intent. The existing codebase has zero feature tests and three FormRequests with `authorize() => true`, which is exactly the copy-paste pattern that would produce such a bug.
4. **Revocation semantics are non-trivial.** The panel authenticates with stateful Sanctum cookie sessions plus an `admin-session` token. If grants are resolved at login, a revoked teacher keeps working until re-login unless the implementation also revokes live tokens. Correct handling is possible but adds real complexity to be solved *before* any user needs it.
5. **Timeline.** The project has a fixed election deadline; toggle infrastructure is an estimated 3–5 additional days on top of the ~2 days the fixed-role path needs, with all of the risk and none of the requirement coverage.

**Cheap substitute adopted instead:** if the operational need is "lock one person out without deleting them," add a `users.is_active` boolean column and a login-time check — a one-column migration and a two-line check, covering ~90% of the same operational need with ~5% of the complexity.

**Revisit trigger:** implement the toggle system when a concrete requirement appears — e.g., the school asks for department-specific teacher moderators, or per-president announcement scopes that differ from the default author-only rule. The §3 design (catalog + single middleware) is deliberately shaped so that `config('permissions.roles.*')` can later be unioned with per-user grants from a `user_permissions` table without rewriting every route — the middleware remains the only enforcement point to change.

---

## 6. Possible Drawbacks of the Current (Fixed-Role) Approach

Honest tradeoffs accepted by this decision:

- **Less flexibility if requirements change.** Adding "a teacher who can import the registrar but not approve candidates" means either a migration + new role value (or a catalog redefinition in code) — a deploy, not a config change. With the toggle system it would have been a UI action. Mitigation: catalog lives in one file (`config/permissions.php`), so a code change is still cheap and reviewable.
- **New use case ⇒ new role, or the deferred toggle system.** Each distinct permission combination that becomes a real requirement forces a role fork (`ssg_president` this time; possibly `moderator` later). Five or six roles is where fixed-role designs start to creak and where the §5 revisit trigger should fire.
- **Manual post-certification step.** An Admin must remember to promote the winning student's account to `ssg_president` after certification — a human action in `admin-react/src/Settings.jsx` (once wired to `PATCH /admin/users/{id}/role`). Forgetting it means the announcements feed stays empty; nothing automates the handover. Mitigations: the server refuses promotion before `voting_closed` (§3 Step 6), and the dashboard can surface a "no president assigned" reminder when the phase is closed.
- **Role promotion breaks the Flutter app for that account.** `StudentAuthController::login` rejects non-`student` roles, so a promoted President can no longer log into the mobile app (e.g., to see the results feed they can already see in the panel). Accepted: the election is over by then, and the panel shows the same public results.
- **Fixed catalog can drift from reality.** If a route is added without a `permission:…` gate (the current state of every admin route), it is silently available to any panel user. The fixed-role design only binds if the §3 Step 5 retrofit is done *completely* — this is the main enforcement risk to test for (§3 Step 10).
- **Admin is a superuser with no accountability trail.** Admin bypasses the catalog by design (§3 Step 3); there is no audit log of admin actions in the codebase (`recent_actions` in the dashboard response is a hardcoded empty array — `AdminDashboardController.php:30`). A malicious or compromised admin account is the residual risk the toggle system would *not* have solved either.

---

## 7. AI Agent Implementation Guide

Ordered, actionable checklist to build this end-to-end. Backend work (Steps 1–7, 10) falls under Cline's jurisdiction per `AGENTS.md`; React/Flutter screens (Steps 8–9) under OpenCode's — do not cross-edit concurrently.

**Backend — schema & config**

1. [ ] Read `backend-laravel/database/migrations/2026_08_24_000001_add_omnivote_fields_to_users_table.php` to confirm the current `role` enum values, and `config/database.php` for the dev driver (SQLite needs the rebuild-table pattern).
2. [ ] Create `backend-laravel/database/migrations/2026_09_13_000001_add_ssg_president_role_to_users.php` widening the enum to include `ssg_president` (keep `candidate`). Run `php artisan migrate` against a fresh DB and verify with a seeder account.
3. [ ] Create `backend-laravel/config/permissions.php` with the four role catalogs from §2 (`admin => '*'`).
4. [ ] Create `backend-laravel/app/Http/Middleware/EnsurePermission.php` (catalog check + `'*'` superuser bypass + 403 JSON body) and register the `permission` alias in `backend-laravel/bootstrap/app.php`.

**Backend — routes & controllers**

5. [ ] Edit `backend-laravel/app/Http/Controllers/AdminAuthController.php` line 33: whitelist `['admin', 'teacher', 'ssg_president']`.
6. [ ] Edit `backend-laravel/routes/api.php`: attach `permission:…` to every route in the admin group per §3 Step 5; add `POST /admin/results/publish`; add `GET/POST /admin/users` + `PATCH /admin/users/{user}/role`; add `GET /admin/ssg/officers`; add `apiResource` for `/admin/ssg/announcements` (store/update/destroy); add public `GET /announcements` outside both auth groups.
7. [ ] Create `backend-laravel/app/Http/Requests/UpdateUserRoleRequest.php` with a real `authorize()` (must NOT copy the `return true;` pattern from the existing FormRequests).
8. [ ] Create `backend-laravel/app/Http/Controllers/AdminUserController.php` with `index()` and `updateRole()` including the `voting_closed` phase guard and `$user->tokens()->delete()` on promotion.
9. [ ] Edit `backend-laravel/app/Http/Controllers/ElectionController.php`: add a `results_published` flag in `election_settings`; phase changes in `updateConfig()` stay reachable only via `permission:control_election_phase`. Edit `backend-laravel/app/Http/Controllers/ResultsController.php::index()` to require the flag in addition to `checkPhase:voting_closed`.
10. [ ] Create migration `2026_09_13_000002_create_announcements_table.php`, `backend-laravel/app/Models/Announcement.php`, `backend-laravel/app/Http/Controllers/AnnouncementController.php` (publicIndex + CRUD, author-scoped writes for president), and `backend-laravel/app/Http/Controllers/SsgOfficerController.php` (results-derived roster).
11. [ ] Extend `backend-laravel/database/data/users.json` + `UserSeeder.php` with an `ssg_president` dev account.

**Backend — tests**

12. [ ] Create `backend-laravel/tests/Feature/EnsurePermissionTest.php` (first feature tests in the repo): admin bypass; teacher blocked from `registrar/import` and `election/config`; teacher allowed on `candidates` and `results`; president blocked from `candidates`, `election/config`, `registrar/*`, `users/*`; president allowed on `ssg/officers` and own `ssg/announcements`; president blocked from editing another author's announcement; role promotion to `ssg_president` refused while phase ≠ `voting_closed`; public `GET /announcements` requires no auth.
13. [ ] Run `php artisan test` — all green; run `php artisan migrate:fresh --seed` to prove the migration path is clean.

**React admin (OpenCode jurisdiction)**

14. [ ] Create `admin-react/src/lib/roles.js` (`ROLE_LABELS`, `ROLE_VIEWS`, `useRolePermissions()`).
15. [ ] Edit `admin-react/src/App.jsx`: role-gate the `switch (currentView)`; add `'ssg-officers'` and `'ssg-announcements'` cases.
16. [ ] Edit `admin-react/src/Admindashboard.jsx` lines 42–46: use `roles.js` labels (fixes the unknown-role → "System Administrator" fallback); hide admin/teacher-only nav items for president.
17. [ ] Create `admin-react/src/SsgOfficers.jsx` + `.css` and `admin-react/src/SsgAnnouncements.jsx` + `.css` following the existing screen/CSS-pair pattern (`Candidates.jsx`/`Candidates.css` as the template).
18. [ ] Edit `admin-react/src/Settings.jsx`: replace the mock `users` state (lines 52–80) with `GET /admin/users`; add the promote-to-president action (phase-gated, per the 422 from Step 8); add a `role-president` badge class to `Settings.css`.
19. [ ] Replace the hardcoded "Election Admin"/"Eleanor Vance" sidebar blocks in `Candidates.jsx`, `StudentRegistry.jsx`, `ElectionSetup.jsx`, `Results.jsx`, `Settings.jsx` with the auth-context user + `roles.js` label.
20. [ ] Run `npm run build` (or the dev server) and manually verify: president login sees only officers + announcements; teacher sees candidates/results but no registry/setup; admin sees everything.

**Flutter (OpenCode jurisdiction)**

21. [ ] Create `user-flutter/lib/data/models/announcement_model.dart` with tolerant parsing (pattern: `student_model.dart` + `safe_json.dart`).
22. [ ] Create `user-flutter/lib/data/repositories/announcement_repository.dart` using `lib/data/services/api_client.dart`.
23. [ ] Create `user-flutter/lib/features/dashboard/providers/announcements_provider.dart` (epoch-invalidation pattern from `election_status_provider.dart`).
24. [ ] Create `user-flutter/lib/features/dashboard/widgets/announcements_feed.dart`; insert it at the top of `dashboard_screen.dart`'s ListView (above `RegistrationBanner`), phase-independent; add it to the pull-to-refresh invalidation.
25. [ ] Create `user-flutter/test/announcement_model_test.dart`; run `flutter analyze` and `flutter test` — expect no issues and all tests passing.

**Definition of done**

- [ ] Zero routes in the `/api/admin` group reachable with `auth` alone (spot-check: unauthenticated 401; president 403 on registrar/config/users; teacher 403 on same).
- [ ] `php artisan test`, `flutter analyze`, `flutter test`, `npm run build` all clean.
- [ ] This doc updated: move the `Status` at the top from "not yet implemented" to implemented, with the merge/commit reference.

---

## 8. Review Summary

**Pass 1 — Implementation-plan review ("where does this fit?").** Mapped the codebase's actual authorization state: `users.role` is a string ENUM (`admin`/`teacher`/`candidate`/`student`) from migration `2026_08_24_000001`; role checks exist **only at the two login controllers** (`AdminAuthController.php:33` whitelist, `StudentAuthController.php:32` exact-match) — every `/api/admin/*` route is behind bare `auth` with no per-endpoint role gate; there are no Policies, no Gates, no permission middleware (only `checkPhase` is registered in `bootstrap/app.php`); the React panel has no router (view switch in `App.jsx`), role labels are cosmetic and binary (`Admindashboard.jsx:42-46`), and account "provisioning" exists only as a seeder — the `Settings.jsx` user table is mock data with no API. This pass produced the phased plan now condensed into §3 (enum widening → catalog → middleware → login whitelist → route retrofit → role-promotion endpoint → announcements/officers domain → React screens → Flutter feed → tests) and flagged: the vestigial `candidate` enum value, the stale `ssg_office` reference in `docs/AUDIT.md:200`, the hardcoded fake users in five sidebar components, and the entanglement of "publish results" with the phase mechanism.

**Pass 2 — Feasibility review of the permission-toggle proposal.** Assessed the proposed `user_permissions` design (per-user toggles scoped to each role's catalog, enforced by dual checks: role-catalog validity + per-user grant). Findings: the dual-check pattern is sound in principle, but in *this* codebase it would create (a) the system's most sensitive attack surface via the grant-management endpoint (a bug there widens access instead of narrowing it), (b) an admin lockout failure mode unless admin bypasses grants entirely, (c) a revocation-staleness window through the stateful Sanctum session + `admin-session` token unless promotion/revocation also revokes live tokens, and (d) a heavy testing burden in a repo with zero existing feature tests. Verdict: the fixed-role catalog covers 100% of the stated requirements at a fraction of the risk; the toggle system solves a problem with no current user story (estimated 3–5 extra days vs ~2 for the fixed path). Also surfaced bypass risks to avoid during implementation: FormRequests hardcoding `authorize() => true`, and "publish results" not existing as an action (today any panel user can flip the phase, which is what actually publishes results).
