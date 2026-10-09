# OmniVote — Code File Function Reference

> **Purpose:** A file-by-file reference of what every source file does and the
> key functions it contains, written so you can walk your team through the
> codebase. Generated on **2026-10-09** by reading the actual source (not
> auto-generated API docs).
>
> **Scope:** main application source only — `backend-laravel/app` +
> `backend-laravel/routes`, `admin-react/src`, `user-flutter/lib`. Tests,
> migrations, seeders, and deployment scripts are summarized in the appendix.

## How to read this document

Each entry has two parts:

- **Purpose** — what the file is responsible for, in plain language.
- **Key functions** — the real class/method/function names with a one-line
  description of what each one does.

## System at a glance

| Area | Directory | Stack | Auth to API | Responsibility |
|---|---|---|---|---|
| Laravel API | `backend-laravel/` | PHP 8.4, Laravel, Sanctum, MySQL | Admin: session cookie + CSRF; Student: bearer token | Source of truth: auth, election phases, authorization, voting, results |
| Admin console | `admin-react/` | React 19 + Vite | Sanctum stateful cookie (`/admin/*`) | Committee, registrar, auditor, SSG workflows |
| Student app | `user-flutter/` | Flutter + Riverpod + GoRouter | Sanctum bearer token (`/auth/*`, etc.) | Registration, candidacy, voting, results for students |
| Ops | `deploy/` | Bash, nginx, systemd | — | Server provisioning, backups, CI deploy (appendix) |

Cross-cutting rules worth stating up front in any walkthrough:

1. **The API is the only place business rules live.** Both clients just render
   what Laravel returns.
2. **Election phases gate everything** (`registration` → `voting_open` →
   `voting_closed`) via the `checkPhase:` middleware.
3. **Two auth styles:** the React SPA uses cookie sessions + CSRF (Sanctum
   stateful); the Flutter app uses bearer tokens (Sanctum personal access).
4. **Ballot secrecy:** the server never stores a voter→candidate link; the
   receipt is an HMAC held only on the student's device.

---

## 1. Backend — Laravel API (`backend-laravel/`)

The backend is the authoritative API. Controllers coordinate a request, form
requests validate input, Eloquent models touch the database, and middleware
rejects requests that are unauthenticated, unauthorized, or in the wrong
election phase. Support classes hold reusable domain logic (tallying, backup,
2FA, codes).

### Routes

#### `routes/api.php`
**Purpose:** The complete API contract and middleware wiring. Three big
surfaces: (1) `/admin/*` — stateful Sanctum session routes for the React SPA,
each guarded by `permission:*` middleware; (2) public, rate-limited reads
(`/election/status`, `/positions`, `/candidates`, `/announcements`,
`/branding`, `/results`); (3) student routes for the Flutter app, guarded by
`auth:sanctum` + `role:student` + `passwordChanged` + `checkPhase:*`.
**Key groups:**

- `Route::prefix('admin')` — login, 2FA, password reset (throttled), profile
  avatar, dashboard, candidate review/certification, registrar import +
  activation codes, election config, positions, results finalize/archive,
  settings, backups, notifications, users/departments/courses, SSG officers
  and announcements.
- Public `throttle:api-public` group — announcements, branding, election
  status, positions, candidates, departments, parties.
- `Route::prefix('auth')` — student login, `register`
  (`checkPhase:registration`), `password/reset-with-code`.
- Student-authenticated group — registration status, notifications, candidacy,
  ballot draft/submit, `/vote`, results + receipt verification (each with the
  correct phase gate or throttle bucket).

#### `routes/web.php`
**Purpose:** Single route: `GET /sanctum/csrf-cookie` — the CSRF cookie
endpoint the React SPA calls before mutating requests.

#### `routes/console.php`
**Purpose:** Scheduler definitions.
**Key functions:**

- `Schedule::command('omnivote:backup')` — daily encrypted backup, only when
  the `autoBackup` setting is on.
- `Schedule::call(TermArchive::runIfDue())` — daily 00:05 safety net that
  archives an expired term's certified winners even if no admin opened the
  panel.

### Middleware (`app/Http/Middleware/`)

#### `CheckPhase.php`
**Purpose:** Implements the `checkPhase:<phase>` route middleware — the election
lifecycle gate. A request only passes when the current `phases` row matches the
required phase (e.g. `checkPhase:voting_open` on `/vote`).
**Key functions:** `handle($request, $next, ?string $requiredPhase)` — resolves
the current phase and rejects with a 403 JSON "wrong phase" payload when it
doesn't match.

#### `EnsurePermission.php`
**Purpose:** Implements `permission:<key>` — server-side authorization from
`config/permissions.php` (e.g. `permission:manage_accounts`). Hiding UI in
React is cosmetic; this is the real boundary.
**Key functions:** `handle(..., string $permission)` — 403 unless the user's
role grants the permission.

#### `EnsureRole.php`
**Purpose:** Implements `role:<roles...>` — allows only the listed roles
(e.g. `role:student` on all student routes).
**Key functions:** `handle(..., string ...$roles)`.

#### `EnsurePasswordChanged.php`
**Purpose:** Implements `passwordChanged` — the temporary-credential gate.
Accounts flagged `must_change_password` (registrar-issued credentials) can only
reach the exempt routes (logout, `/me`, change-password).
**Key functions:** `handle(...)` — rejects until the flag is cleared.

#### `SecurityHeaders.php`
**Purpose:** Appends hardened security headers (CSP, frame options, etc.) to
every response.

#### `ApplySessionLifetime.php`
**Purpose:** Applies the configured session lifetime from settings before the
response is sent (security section drives cookie expiry).

### Form Requests (`app/Http/Requests/`)

Each is a `FormRequest` with `authorize()` + `rules()` (some with custom
`messages()`); validation failures return 422 automatically.

#### `StudentRegistrationRequest.php`
**Purpose:** Validates student self-registration payload (name, student number,
year/block, department/course, credentials) during the `registration` phase.

#### `CandidateApplicationRequest.php`
**Purpose:** Validates a student's candidacy application (position, tier,
platform/photo fields).

#### `StoreAdminCandidateRequest.php`
**Purpose:** Validates an admin/committee-created candidate record.

#### `UpdateCandidateStatusRequest.php`
**Purpose:** Validates approve/reject status transitions on a candidacy.

#### `ElectionConfigRequest.php`
**Purpose:** Validates the admin election configuration payload (phase window
dates, voting rules) written through `PUT /admin/election/config`.

#### `RegistrarImportRequest.php`
**Purpose:** Validates the registrar CSV import payload with custom error
messages.

### Console Commands (`app/Console/Commands/`)

#### `AssertProductionConfig.php`
**Purpose:** `php artisan security:assert-production-config` — exits non-zero
when a production install is still configured like a dev box (debug on,
insecure cookies, non-HTTPS URL). Run by the deploy script before serving.

#### `CreateBackup.php`
**Purpose:** `php artisan omnivote:backup {--encrypt=auto}` — creates an
election data snapshot via `BackupManager` and prunes expired backups. Scheduled
daily from `routes/console.php`.

#### `RotateStudentCredentials.php`
**Purpose:** `php artisan security:rotate-student-credentials` — rotates
passwords of accounts still flagged `must_change_password` to fresh random
credentials (assessment C-1).
**Key functions:** `handle()`, `resolveOutputPath(int $count)` — writes/prints
the new credential sheet.

### Controllers (`app/Http/Controllers/`)

#### `Controller.php`
**Purpose:** Abstract base controller — no behavior; just the Laravel starting
point every controller extends.

#### `Concerns/HandlesLoginThrottling.php`
**Purpose:** Shared trait giving both admin and student login the same
per-account and per-IP failure backoff (exponential delay after repeated bad
passwords).
**Key functions:** `loginThrottled()` (check before verifying),
`recordLoginFailure()`, `clearLoginThrottle()` (on success),
`backoffResponse()` / `tooManyResponse()` (429 + Retry-After),
`loginAccountMaxAttempts()`, `loginIpMaxAttempts()`, abstract
`loginLimiterPrefix()`.

#### `AdminAuthController.php`
**Purpose:** Admin console authentication: password login, the TOTP second
factor, current-identity restore, avatar updates, and the forgot/reset password
mail flow. Session-cookie based (Sanctum stateful).
**Key functions:**

- `login()` — validates credentials with throttling; when 2FA is enabled/required
  returns a "code required" state instead of a session.
- `completeTwoFactorLogin()` — verifies the TOTP code or a recovery code, then
  opens the session; enforces attempt limits and a 5-minute pending TTL.
- `me()` — returns the authenticated panel user (drives SPA bootstrap).
- `updateAvatar()` — sets/clears the custom avatar image URL.
- `sendPasswordResetLink()` / `resetPassword()` — throttled mail-token reset.
- `logout()` — invalidates the session.

#### `AdminTwoFactorController.php`
**Purpose:** Self-service 2FA enrollment for panel users. The pending TOTP
secret is kept in the session until confirmed, so half-finished setups never
lock anyone out.
**Key functions:** `status()`, `prepare()` (generate secret + otpauth URI),
`confirm()` (verify a code, then persist the encrypted secret),
`disable()` (password + code check), `storePendingSecret()` / `pullPendingSecret()`.

#### `StudentAuthController.php`
**Purpose:** Student token auth for the Flutter app (bearer tokens).
**Key functions:**

- `login()` — email + password against `role:student` accounts, returns the
  Sanctum token and `must_change_password` flag.
- `changePassword()` — changes the password on an authenticated student.
- `resetWithCode()` — self-service recovery using the registrar-issued
  activation code (students have no mailbox), throttled per IP.
- `logout()` — revokes the token; `me()` — restores identity.

#### `ElectionController.php`
**Purpose:** Election lifecycle endpoints shared by both clients plus the
admin config writer.
**Key functions:** `status()` (public phase + window timeline from the
`phases` table), `registrationMe()` (the caller's registration/turnout card for
the dashboard), `config()` / `updateConfig()` (admin read/write of election
configuration via `ElectionConfigRequest`).

#### `PositionController.php`
**Purpose:** Offices/positions.
**Key functions:** `index()` — public list with seats, tier/order and
electorate scope; `update()` — admin edit (seats, order, scope type/value,
active flag).

#### `CandidateController.php`
**Purpose:** Public/student-facing candidate endpoints and the student
candidacy application submission.
**Key functions:** `index()` (approved candidates with filters),
`departments()` / `parties()` (filter sources), `show($candidate)` (profile
detail), `store(CandidateApplicationRequest)` (apply during `registration`
phase), `payload()` (serializer).

#### `AdminCandidateController.php`
**Purpose:** Committee candidate review workflow.
**Key functions:** `index()` (filterable list incl. pending applications),
`store()` (admin-created candidate), `storeParty()`, `update()` (approve/reject
via `UpdateCandidateStatusRequest`), `certify()` / `revokeCertification()`
(ratify certified winners — drives the SSG grant flow), `resolveTie()`
(manual tie-break), `export()` (JSON dump).

#### `CandidacyController.php`
**Purpose:** `GET /candidacy/me` — the caller's own candidacy application
status for the Flutter app.

#### `BallotController.php`
**Purpose:** Student ballot **draft** endpoints (Flutter "My Ballot" flow). A
draft is the only user-scoped ballot artifact; after submission the selections
are deliberately cleared so the server can never join a voter to their choices
(security assessment H-1) — only `status='submitted'` remains.
**Key functions:** `me()` (returns `{status, selections}`),
`saveDraft()` (validates the `{position_slug: ref|ref[]}` shape against the
40-position / 20-ref-per-position caps), `submit()` (delegates straight to
`VoteController::submit()`), `selectionsProblem()` (human-readable shape
validation).

#### `VoteController.php`
**Purpose:** The actual vote cast — the heart of the system. Resolves the
submitted selections against approved candidates, enforces electorate scope
and the self-vote ban, writes one immutable `vote_ledger` row per selection,
and returns an HMAC receipt token held only on the student's device.
**Key functions:**

- `submit()` — validates phase (via route), resolves selections, checks
  one-vote-per-voter, writes the ledger + receipt.
- `resolveSelections()` — maps submitted refs to candidates, rejecting
  unknown/out-of-scope choices.
- `isSelfVote()` — a candidate may not vote for themselves.
- `candidateIsInScope()` — candidate's position scope must match the voter
  (year level / department / course).

#### `ResultsController.php`
**Purpose:** Publishing, certifying, and verifying results.
**Key functions:**

- `index()` — published results (admin before finalize, students only after
  `voting_closed`).
- `finalize()` — computes the certified tally (`ElectionTally`), persists
  winners, and triggers the results announcement.
- `refreshResultsAnnouncement()` — pushes the "results are out" notification
  through `Notifier`.
- `verify()` — receipt verification oracle (HMAC comparison), throttled per
  account (`throttle:receipt-verify`).
- `archivedWinners()` / `archiveTerm()` — past-term winners archive and manual
  term archiving (`TermArchive`).

#### `AnnouncementController.php`
**Purpose:** Announcements for both clients.
**Key functions:** `publicIndex()` (throttled public feed for students),
`index()`, `store()`, `update()`, `destroy()` (SSG announcement CRUD behind
`permission:announcements.*`).

#### `NotificationController.php`
**Purpose:** In-app notification center (React bell + Flutter bell).
**Key functions:** `index($limit)` — the caller's own feed;
`markRead()` — one or all; `broadcast()` — admin composes a school-wide notice
(target audience/year level) fanned out by `Notifier`, behind
`permission:announcements.create`.

#### `SsgOfficerController.php`
**Purpose:** `GET /admin/ssg/officers` — certified officers list powering the
SSG President dashboard.

#### `RegistrarImportController.php`
**Purpose:** Registrar bulk import — the largest controller. Parses the
registrar's student CSV, normalizes departments/courses through
`DepartmentCatalog`, upserts students, and manages the single-use activation
codes students use to recover accounts.
**Key functions:**

- `import()` — validates the file, previews/persists the import batch, and
  returns temporary credentials for newly created accounts.
- `index()` — import history.
- `issueCode($import)` / `issueCodes($request)` — (re)issue hashed,
  single-use activation codes for one or many imported students.

#### `RegistrationController.php`
**Purpose:** `POST /auth/register` — student self-registration, gated to the
`registration` phase, validated by `StudentRegistrationRequest`.

#### `AdminUserController.php`
**Purpose:** The largest controller: admin account management plus the
department/course catalog management used by the User Registry screens.
**Key functions:**

- `index()` — paginated user list with role/department/course/year filters and
  stats; `stats()` / `departments()` / `courses()` — dashboard aggregates.
- `store()`, `updateRole()`, `updateStatus()`, `updateEmail()`,
  `resetPassword()`, `unlock()`, `bulkUnlock()` — account lifecycle (locked
  accounts come from login backoff).
- `certifiedWinners()` — certified winners for grant flows;
  `grantSsg()` — appoint an SSG officer role.
- `storeDepartment()` / `renameDepartment()`, `storeCourse()` / `renameCourse()`
  / `deleteCourse()` — catalog CRUD (with `audit()` trail).
- `export()` — streamed CSV export honoring the active filters.

#### `AdminDashboardController.php`
**Purpose:** Aggregated metrics for the admin dashboard.
**Key functions:** `overview()` (KPI cards: registrations, turnout, candidates,
phase), `results()` (results payload for the admin Results screen).

#### `SettingsController.php`
**Purpose:** The five settings sections (security, voting, branding, backup,
notifications) — read and write — plus the public branding subset.
**Key functions:** `index()` (all sections), `show($section)`,
`update($section)` (validated write), `branding()` (public, unauthenticated:
site name/colors/logo for login screens of both clients).

#### `BackupController.php`
**Purpose:** Backup management UI endpoints.
**Key functions:** `index()` (list), `store()` (create, optional encryption),
`restore()` (import a backup file), `download()`, `destroy()` — all delegating
to `BackupManager` and permission-gated.

### Models (`app/Models/`)

#### `User.php`
**Purpose:** The single users table identity (students **and** panel users),
plus all the account-security logic: login backoff and TOTP 2FA state.
**Key constants:** `MAX_LOGIN_ATTEMPTS=5`, `LOCKOUT_MINUTES=15`,
`BACKOFF_BASE/MAX_MINUTES`, `TWO_FACTOR_MAX_ATTEMPTS=5`,
`TWO_FACTOR_VERIFY_TTL=300`.
**Key functions:** `casts()`, `hasTwoFactorEnabled()`,
`twoFactorRecoveryCodes()`, `isLocked()`, `effectiveFailedAttempts()`,
`backoffSecondsRemaining()`, `backoffMinutesFor()`, `recordFailedLogin()`,
`clearLockout()`, `candidate()`, `ballotDraft()`, `userNotifications()`,
`sendPasswordResetNotification()` (custom notification).

#### `Phase.php`
**Purpose:** The election lifecycle row — the source of truth for what phase
the election is in (registration / voting_open / voting_closed).
**Key functions:** `current()`, `setCurrent()`, `reconcile()` (keeps phase
consistent with the configured window dates), `timeline()` (window dates for
clients), `derivedName()` (derives the phase name from dates).

#### `Position.php`
**Purpose:** An office/seat group, including the **electorate scope** that
decides who may vote for it and who may run for it.
**Key functions:** `candidates()` (approved/scoped), `allCandidates()`,
`scopeAttribute()` (year_level/department/course), `isGloballyScoped()`,
`requiredValueFor()`, `voterIsInScope()`, `valuesMatch()`.

#### `Candidate.php`
**Purpose:** A candidacy application/approved candidate; relations to `user()`
and `position()`.

#### `BallotDraft.php`
**Purpose:** A student's saved in-progress ballot (selections JSON + status
`draft|submitted`); `user()` relation.

#### `VoteLedger.php`
**Purpose:** Immutable per-selection vote rows — the anonymized tally source.
No user_id column: this is what keeps ballots secret.

#### `Announcement.php`
**Purpose:** Announcement posts; `author()` relation and `scopePublished()`
query scope.

#### `UserNotification.php`
**Purpose:** Per-user notification row (bell feeds); `recipient()`,
`isRead()`.

#### `RegistrarImport.php`
**Purpose:** A registrar import batch; also stores the password-recovery fields
`activation_code_hash` / `activation_code_used_at`.

#### `Department.php` / `DepartmentAlias.php` / `Course.php` / `CourseAlias.php`
**Purpose:** The academic catalog (departments → courses) with alias tables
that map registrar spellings/abbreviations onto canonical names.
**Key functions:** `Department::courses()`, `Department::aliases()`,
`Course::department()`, `Course::aliases()`, `DepartmentAlias::department()`,
`CourseAlias::course()`.

#### `Party.php`
**Purpose:** Partylist/organization records referenced by candidates.

### Support classes (`app/Support/`)

Domain logic shared by controllers — pure PHP, unit-testable.

#### `AppSettings.php`
**Purpose:** Typed accessors over the `settings` rows.
**Key functions:** `security($key)`, `backup($key)`, `voting($key)`,
`notifications($key)`.

#### `ElectionTally.php`
**Purpose:** The counting algorithm used at finalize.
**Key functions:** `resolve(array $rows, int $seatCount)` — plurality tally
with tie detection over `vote_ledger` rows.

#### `BackupManager.php`
**Purpose:** DB backup lifecycle.
**Key functions:** `tables()`, `directory()`, `list()`, `create(bool $encrypted)`,
`restore($filename)`, `delete()`, `prune($days)`, `humanSize()`, `label()`.

#### `Notifier.php`
**Purpose:** Notification fan-out used by broadcasts/results/admin actions.
**Key functions:** `toAdmins()`, `notifyUser()`, `notifyStudents()`
(targeted by role/year/department), `emailUser()`.

#### `TwoFactor.php`
**Purpose:** TOTP implementation (RFC 6238) + recovery codes.
**Key functions:** `generateSecret()`, `otpauthUri()`, `verify()`,
`generateRecoveryCodes()`, `hashRecoveryCodes()`, `findRecoveryIndex()`.

#### `RegistrarCode.php`
**Purpose:** Single-use activation codes for student password recovery.
**Key functions:** `generate()`, `issue($import)` (hashed, stored),
`matches()`, `redeem()` (marks used).

#### `TemporaryPassword.php`
**Purpose:** `generate($length)` — random temporary credentials for imported
accounts.

#### `TermArchive.php`
**Purpose:** School-term (SY) archiving of certified winners.
**Key functions:** `termEndsAt()`, `due()`, `labelFor()` ("SY 2026-2027"),
`archiveNow()`, `runIfDue()` (called by the scheduler).

#### `DepartmentCatalog.php`
**Purpose:** Canonical college/department/course lists + alias tables that
normalize registrar CSV values.
**Key functions:** `departmentAliases()`, `courseAliases()`, `collegeNames()`,
`resolveCollege()`, `coursesOf()`, `resolveCourse()`.

#### `ProductionConfigGuard.php`
**Purpose:** Production safety gate.
**Key functions:** `problems()` (debug on, insecure cookies, http APP_URL,
missing key), `assertSafe()` (throws; used by AppServiceProvider boot +
artisan command).

#### `TrustedHosts.php`
**Purpose:** `patterns()` / `hosts()` — allowed host patterns for the trusted
hosts middleware.

### Mail, Notifications, Providers

#### `Mail/AdminNotificationMail.php`
**Purpose:** Mailable for admin-directed notices; `envelope()` + `content()`.

#### `Notifications/PasswordResetLink.php`
**Purpose:** Password-reset mail notification carrying the token for panel
users; `via()`, `toMail()`.

#### `Providers/AppServiceProvider.php`
**Purpose:** Application boot wiring — one place for security defaults.
**Key functions:**

- `boot()` — sets `Password::defaults()` policy, runs
  `ProductionConfigGuard::assertSafe()` on production HTTP boot, and defines
  every rate limiter bucket: `login`, `admin-login`, `api-public` (300/min per
  IP+path), `sensitive` (5/min), `password-reset` (3/min per IP),
  `receipt-verify` (20/min per account).
- `register()` — empty (no container bindings today).

---

## 2. Admin Console — React (`admin-react/`)

A single-page app served at `/admin/` in production, authenticated with
Sanctum **session cookies + CSRF** (never bearer tokens). There is no router
library — `App.jsx` holds the current view in state and renders the matching
page, with navigation gated by `lib/permissions.js`. React 19 + Vite.

### Entry points

#### `src/main.jsx`
**Purpose:** Boot file. Calls `loadBranding()` (site name/colors/favicon fetch,
non-blocking) then renders `<App />` in StrictMode.

#### `src/App.jsx`
**Purpose:** The shell and de-facto router.
**Key functions/components:**

- `App` — provider stack: `ThemeProvider` → `AuthProvider` → `AppShell`.
- `AppShell` — holds `currentView`, `authMode` (`login|forgot|reset`, inferred
  from `?token=&email=` in the URL), and `settingsTab`; computes the permitted
  view from the user's role; renders `TwoFactorEnrollmentScreen` when 2FA is
  required but not enrolled; `navigate(view, tab)` is the SPA navigation;
  `renderActiveView()` switches between the page components; wraps everything
  in `ElectionStatusProvider` (30 s poll).
- `useDocumentTitle()` / `ROUTE_TITLES` — unique tab title per role/route.
- `clearResetLink()` — strips reset tokens from the URL after use.

### Pages

#### `AdminLogin.jsx`
**Purpose:** Sign-in screen (email/password) with friendly error mapping
(`friendlyLoginError()`); swaps to the 2FA code step when
`AuthContext.requiresTwoFactor` is set; `onForgot` switches to the forgot view.

#### `ForgotPassword.jsx`
**Purpose:** Requests a reset mail via `POST /admin/password/email` (`onBack`
returns to login).

#### `ResetPassword.jsx`
**Purpose:** Consumes the emailed `?token=&email=` link →
`POST /admin/password/reset`; `onCompleted` returns to login so the link can't
be replayed.

#### `TwoFactorEnrollmentScreen.jsx`
**Purpose:** Full-screen forced TOTP enrollment (shown when
`two_factor_required && !two_factor_enabled`); wraps `TwoFactorSetup`.

#### `TwoFactorSetup.jsx`
**Purpose:** Reusable 2FA panel (`variant='panel'`) embedded in Settings:
status display, QR/secret preparation, code confirmation, and disable;
callbacks `onEnabled` / `onDisabled`.

#### `AdminDashboard.jsx`
**Purpose:** KPI overview page (phase, registrations, turnout, candidates).
**Key functions:** `fetchDashboardData()` (GET `/admin/dashboard-overview`,
on an interval tick), `displayPhase()`, `handlePhaseBadgeClick()` (deep-links
to the Voting Windows tab of Settings), `handleLogout()`; composes
`DashboardWidgets`.

#### `Candidates.jsx`
**Purpose:** Committee candidate review: filterable list of applications
(GET `/admin/candidates`), approve/reject (`handleStatusChange`), create
candidate (`handleSaveCandidate` → POST) and create party
(`handleSaveParty` → POST `/admin/parties`), modal open/close helpers,
`updateFilter()` / `clearFilters()`.

#### `Announcements.jsx`
**Purpose:** SSG announcement CRUD (GET/POST `/admin/ssg/announcements`);
`createAnnouncement()`, `deleteAnnouncement()` (gated by
`canDeleteAnnouncement()` against the user's permissions), `formatDate()`.

#### `StudentRegistry.jsx`
**Purpose:** Registrar import workflow.
**Key functions:** `parseCsvLine()` / `parseCsvPreview()` (client-side CSV
preview), `handleFileChange()`, `handleImport()` (POST
`/admin/registrar/import`), `handleCancel()`, `downloadTemplate()` (CSV
template), `downloadCsv()` / `escapeCell()` (exports),
`exportCredentials()` — downloads the temporary credentials returned by the
import as a CSV sheet.

#### `ElectionSetup.jsx`
**Purpose:** Election configuration editor.
**Key functions:** `loadConfig()` (GET `/admin/election/config`),
`saveConfig()` (PUT), `toggleNationalSwitch()` / `toggleProvincialSwitch()`
(enable/disable positions per tier), `openEdit()` / `closeEdit()` /
`saveEditedPosition()` (edit seats/order/scope), `displayPhase()`.

#### `Results.jsx`
**Purpose:** Admin results screen (phase-gated).
**Key functions:** `loadResults()` (GET `/admin/results`),
`finalizeResults()` (POST `/admin/results/finalize`),
`resolveTie(candidateId)` (POST `/admin/candidates/{id}/resolve-tie`),
`loadArchived()` / `archiveTermNow()` (GET/POST the archive endpoints),
`getFilteredCandidates()` and `renderResultSection()` (presentation),
`displayPhase()` / `phaseClass()` / `formatArchiveDate()`.

#### `Settings.jsx`
**Purpose:** The biggest screen — tabs for My Profile, Security, Voting
Windows, Branding, Backups, plus embedded 2FA and Notification Broadcast,
with a localStorage cache of unsaved settings.
**Key functions:**

- `handleSave(section, payload)` — PATCH `/admin/settings/{section}`;
  `update(section, field, value)` local state; `loadCachedSettings()` /
  `persistSettings()` / `mergeSettings()` cache layer.
- Voting windows: `VOTING_WINDOW_FIELDS`, `VotingWindowDialog` (guided
  two-step confirm so a stray click can't move a poll deadline),
  `ResetVotingWindowDialog`, `commitVotingWindow()`, `clearVotingWindows()`,
  `validateVoting()`, `shiftDraft()`, `schoolYearOf()` (SY label, mirrors the
  backend `TermArchive::labelFor()`).
- Avatar: `handlePickAvatar()`, `handleAvatarFile()`, `saveAvatar()`,
  `clearAvatar()` (PATCH `/admin/me/avatar`), pixel-avatar helpers
  (`pixelAvatarUrl()`, `fileToAvatarDataUrl()`).
- Backups: `loadBackups()`, `runBackupNow()`, `downloadBackup()`,
  `deleteBackup()`, `restoreBackup()` / `confirmRestore()`.
- Misc: `roleLabel()`, `initials()`, `SettingSwitch` / `ToggleRow` widgets.

#### `UserManagement.jsx`
**Purpose:** Account lifecycle management (GET/POST `/admin/users`, ...).
**Key functions:** `doSearch()` / `clearFilters()`, `doRoleChange()`,
`doStatusToggle()`, `startEmailEdit()` / `doEmailEdit()`, `doPwReset()`,
`doUnlock()`, `doBulkUnlock()`, `doCreate()`, `openGrant()` / `doGrant()`
(grant SSG role), `doExport()` (CSV blob), `flash()` (success toasts).

#### `Departments.jsx`
**Purpose:** Department & course catalog management plus per-department member
lists (the `/admin/users/departments|courses` endpoints).
**Key functions:** `loadMoreMembers()`, department modal flow
(`openManage`, `confirmAdd`, `startRename`, `confirmRename`,
`saveDepartment`), course modal flow (`openManageCourses`,
`confirmCourseAdd`, `startCourseRename`, `startCourseDelete`,
`saveCourse`), `flash()`.

#### `SsgPresident.jsx`
**Purpose:** Dedicated dashboard shell for the `ssg_president` role: certified
officers table (GET `/admin/ssg/officers`) + announcement composer
(`createAnnouncement`, `deleteAnnouncement`, `handleLogout`).

### Components (`src/components/`)

#### `DashboardWidgets.jsx`
**Purpose:** The card grid of the admin dashboard (phase card, stats,
quick actions, announcements feed, recent activity).
**Key functions:** default `DashboardWidgets(...)` component, plus metadata
maps `PHASE_META`, `ACTION_META`, `QUICK_ACTIONS`, helpers `normalizePhase()`,
`toNumber()`, `authorAvatarFor()`, `getTimeAgo()`, `handleNavigate()`.

#### `Header.jsx`
**Purpose:** Top bar: breadcrumb, live clock (1 s interval with timezone),
election-phase badge (from `useElectionStatus()`), notification bell slot,
user menu; props `breadcrumb`, `currentUser`, `onNavigate`; helpers
`roleLabel()`, `displayPhase()`.

#### `Sidebar.jsx`
**Purpose:** Left navigation; filters its link list by
`allowedViews(currentUser.role)` so each role only sees its own screens.

#### `MobileMenuButton.jsx`
**Purpose:** Hamburger button toggling the mobile drawer (used by the mobile
shell CSS in `lib/mobileShell.js`).

#### `NotificationCenter.jsx`
**Purpose:** Dropdown notification feed for panel users — polls
GET `/admin/notifications` every 30 s, marks one (`{id}`) or all as read;
`TYPE_META`, `timeAgo()`.

#### `NotificationBroadcast.jsx`
**Purpose:** "Broadcast" composer embedded in Settings: message type
(`NOTIFICATION_TYPES`), student deep link (`STUDENT_LINKS`), target year
level (`YEAR_LEVELS`) → POST `/admin/notifications/broadcast`.

#### `PhaseStatusDialog.jsx`
**Purpose:** Modal summarizing the current election phase and window dates
(opened from the phase badge).

#### `Header.css`, `Sidebar.css`, `NotificationBroadcast.css`, and each
page's `*.css` (e.g. `AdminLogin.css`, `Settings.css`, `Results.css`,
`Candidates.css`, `index.css`)
**Purpose:** Plain CSS stylesheets — one per component/page, plus
`index.css` for global tokens/reset/layout. No CSS framework is used.

### Libraries & contexts (`src/lib/`)

#### `api.js`
**Purpose:** The shared Axios client — every network call goes through here.
**Key functions:**

- `API_BASE_URL` — from `VITE_API_BASE_URL` (empty = same-origin in prod).
- `getCsrfCookie()` — fetches `/sanctum/csrf-cookie` once and caches the
  promise (with a generation counter so a stale in-flight fetch can never
  re-seed after a reset — prevents the logout→login→419 loop).
- `resetCsrfCookie()` — drops the cache on 401/419 so the next mutation gets a
  fresh token.
- default `api` instance — `withCredentials`, `withXSRFToken`, XSRF header
  names; **request interceptor** awaits the CSRF cookie for
  POST/PUT/PATCH/DELETE; **response interceptor** resets CSRF on 401/419,
  calls `onUnauthorizedHandler` (forces logout) and `onForbiddenHandler`
  (surfaces 403 messages) registered via `setAuthHandlers()`.

#### `AuthContext.jsx`
**Purpose:** React context holding the authenticated panel user.
**Key functions:** `AuthProvider` — bootstraps the session on load
(`getCurrentUser()` against the cached display user), wires the api 401/403
handlers; exposes `login()`, `verifyTwoFactor()`, `cancelTwoFactor()`,
`logout()`, `refreshUser()`, `updateUserAvatar()` (optimistic avatar update),
state `user / ready / loading / error / requiresTwoFactor`; `useAuth()` hook.

#### `auth.js`
**Purpose:** Thin service layer wrapping `/admin/*` auth endpoints + local
display caches.
**Key functions:** `login()`, `completeTwoFactorLogin()`,
`getTwoFactorStatus()`, `prepareTwoFactor()`, `confirmTwoFactor()`,
`disableTwoFactor()`, `getCurrentUser()`, `logoutRequest()`,
`readDisplayUser()` / `writeDisplayUser()` (localStorage cache so the shell
renders instantly before the server validates), `readLocalAvatar()` /
`writeLocalAvatar()` (per-account device avatar cache).

#### `ElectionStatusContext.jsx`
**Purpose:** Polls GET `/election/status` every 30 s and exposes the current
phase to the whole shell (header badge, dashboard phase card, Results gating);
`ElectionStatusProvider` + `useElectionStatus()`.

#### `ThemeContext.jsx`
**Purpose:** Light/dark theme persisted to localStorage (`omnivote-theme`);
`ThemeProvider` + `useTheme()` (`theme`, `toggleTheme`).

#### `branding.js`
**Purpose:** Loads the public GET `/branding` payload and applies it to CSS
variables, favicon, and tab title; caches in memory so all components share it.
**Key functions:** `loadBranding()`, `refreshBranding()`, `getBranding()`,
`useBranding()` hook, `applyToDocument()`, `DEFAULT_BRANDING`.

#### `permissions.js`
**Purpose:** UI-only role→view matrix (server permissions are the real gate).
**Key functions:** `allowedViews(role)` (from `ROLE_VIEWS`),
`defaultView(role)` (`ROLE_DEFAULT_VIEW`).

#### `avatar.js`
**Purpose:** Fallback avatar generation — `initialsAvatarDataUri()`,
`defaultUserIconDataUri()`, `fallbackAvatarOnError()`.

#### `mobileShell.js`
**Purpose:** Small DOM helpers for the responsive shell (`initMobileShell`):
body class toggles and tap-outside-to-close for the mobile drawer.

#### `passwordRules.js`
**Purpose:** `passwordProblem(value)` — client-side password policy check that
mirrors the backend rules (shown live in Settings).

#### Config files: `../vite.config.js`, `../eslint.config.js`
**Purpose:** Vite build/dev config (dev proxy) and ESLint flat config — not
app logic.

---

## 3. Student App — Flutter (`user-flutter/`)

A student-only mobile client (Android-first, Flutter web also supported) that
consumes the Laravel API with a Sanctum **bearer token**. Architecture: three
layers — `data/services` (Dio HTTP calls) → `data/repositories` (parse into
models) → `features/*` providers (Riverpod state) → screens/widgets. Routing
uses `go_router` with a persistent bottom-nav shell. **No business rules live
here** — eligibility, counting, and authorization are whatever the API returns.

### Entry & shell

#### `lib/main.dart`
**Purpose:** The `main()` Flutter tooling requires, plus everything that must
be known before the first frame.
**Key functions:** `main()` — refuses to run in release builds without
`--dart-define=API_BASE_URL` (shows `_MissingApiUrlErrorApp`), then reads the
stored API base URL and theme mode **in parallel** before handing off to
`OmniVoteApp`.

#### `lib/app.dart`
**Purpose:** Root widget `OmniVoteApp` and global navigation.
**Key functions:** `_OmniVoteAppState.build()` — `ref.listen(authProvider)`
reacts to auth transitions: login → `/dashboard` (or `/change-password` when
`mustChangePassword`), logout → `/login`; applies the school branding accent
into the theme (`brandAccent`, selected so unrelated branding changes don't
rebuild the app).

#### `lib/core/routes/app_router.dart`
**Purpose:** The complete `GoRouter` routing table (the Flutter equivalent of
`App.jsx`).
**Key entries:** `/splash` (bootstrap), `/login`, `/change-password`,
`/recover-password`; a `StatefulShellRoute.indexedStack` hosting the five
bottom-nav tabs — **Dashboard, Vote Now, Candidates, My Ballot, Results** — plus
detail routes: candidacy apply, notifications, app update, API settings,
settings, my profile, help/FAQ.

#### `lib/core/routes/app_shell.dart`
**Purpose:** `AppShell` — the scaffold with the persistent bottom navigation
bar that hosts the shell branches above.

### `lib/core/` — constants, theme, utils, widgets

#### `constants/api_constants.dart`
**Purpose:** `ApiConstants` — every API path as a named constant
(`/auth/login`, `/ballot/me`, `/vote`, `/results/verify`, …) and `baseUrl`
from `--dart-define=API_BASE_URL` with a dev fallback host.

#### `constants/app_colors.dart` / `constants/app_text_styles.dart`
**Purpose:** Design tokens — `AppColors` (palette constants) and
`AppTextStyles` (typography getters).

#### `theme/app_theme.dart`
**Purpose:** `AppTheme` — builds the light/dark `ThemeData`
(`_build()`, per-part builders for cards/app bar/buttons/inputs) and
`withAccent(base, color)` which re-derives the theme from the school's
branding color at runtime.

#### `theme/app_tokens.dart`
**Purpose:** `AppTokens extends ThemeExtension` — custom design tokens
(spacing, radii, custom colors) with `copyWith()` / `lerp()` and
`AppTokensContextX` extension for `context.appTokens`.

#### `theme/app_shape.dart` / `theme/app_spacing.dart`
**Purpose:** Shared shape radii and spacing widgets/constants (e.g.
`AppSpacing.vLg`) used across screens for visual consistency.

#### `theme/brand_accent.dart`
**Purpose:** `BrandAccentRefX` extension on `WidgetRef` —
`brandAccent()` reads the current branding color from the branding provider.

#### `utils/debouncer.dart`
**Purpose:** `Debouncer` — `run()` (delayed execution for search inputs),
`cancel()`, `dispose()`.

#### `utils/error_message.dart`
**Purpose:** `apiErrorMessage(error, {fallback})` — turns Dio/API errors into
user-facing messages (reads the Laravel `message` field when present).

#### `utils/relative_time.dart`
**Purpose:** `relativeTimeFromIso()` — "2 hours ago" style labels.

#### `utils/safe_json.dart`
**Purpose:** Defensive parsers for untrusted API payloads: `safeInt()`,
`safeString()`, `safeHttpImageUrl()` (only http/https URLs pass).

#### `utils/semver.dart`
**Purpose:** `SemVer` — `tryParse()`, `compareTo()` for app-update version
checks.

#### `utils/launch_url.dart`
**Purpose:** `launchExternalUrl()` — opens links in the external browser.

#### `utils/data_image_cache.dart`
**Purpose:** `DataImageCache` — decodes/caches data-URL images (branding/
avatars) so they aren't re-decoded every rebuild: `providerFor()`,
`bytesOf()`, `clear()`.

#### `providers/auth_event_provider.dart`
**Purpose:** `authEventProvider` (`StateProvider<AuthEvent>`) — a global
signal (`AuthEvent.unauthorized`, `AuthEvent.logout`) raised by the HTTP layer
to force logout without a circular dependency between auth and the API client.

### `lib/core/widgets/` — shared UI kit

#### `app_button.dart`
**Purpose:** `AppButton` — the styled button used everywhere (variants:
primary/secondary/ghost, loading state).

#### `app_card.dart`
**Purpose:** `AppCard` — standard card container; `AppSectionGap` spacing
widget.

#### `app_chip.dart`
**Purpose:** `AppChip` / `AppChipRow` — filter chips (tier/department/party
filters).

#### `app_text_field.dart`
**Purpose:** `AppTextField` — labeled input with validation/obscure/clear
support.

#### `brand_logo.dart`
**Purpose:** `BrandLogo` — renders the branding logo (data-URL or HTTP with
cache, `_dataImage()` / `_defaultIcon()` fallback box).

#### `cached_avatar.dart`
**Purpose:** `CachedAvatar` — avatar image with cache + initials fallback
(`_initialsOf()`, `_fallback()`).

#### `candidate_card.dart`
**Purpose:** `CandidateCard` — candidate summary card used in lists.

#### `notification_bell.dart`
**Purpose:** `NotificationBell` — bell icon with unread badge from
`notificationsProvider`; navigates to the notifications screen.

#### `top_bar.dart`
**Purpose:** `TopBar` (PreferredSizeWidget) — app bar with title and account
menu (`_handleAccountAction()` → profile/settings/logout).

#### `section_header.dart`
**Purpose:** `SectionHeader` — section title rows used across screens.

#### `empty_state.dart` / `error_state.dart`
**Purpose:** `EmptyState` / `ErrorState` — standard empty & error widgets
(with retry callback).

#### `loading_indicator.dart` / `loading_skeleton.dart`
**Purpose:** `LoadingIndicator` (spinner) and `LoadingSkeleton` /
`LoadingSkeletonBox` — shimmer placeholders while data loads.

#### `app_update_banner.dart`
**Purpose:** `AppUpdateBanner` — dismissible "new version available" banner
driven by `appUpdateProvider`.

### `lib/data/models/` — API payload parsers

All are immutable classes with `fromJson`-style parsing (defensive via
`safe_json` helpers) and no logic beyond field mapping.

| File | Classes | What it parses |
|---|---|---|
| `student_model.dart` | `Student` | The logged-in student profile (number, year, department, course, voting flags) |
| `election_status_model.dart` | `ElectionPhase` enum, `ElectionStatus` | Phase (`registration/registrationClosed/votingOpen/votingClosed/unknown`) + window dates |
| `position_model.dart` | `PositionTier` enum, `Position` | Office incl. electorate scope (`scopeType`/`scopeValue`) parsing |
| `candidate_model.dart` | `Candidate` | Candidate/profile fields with tolerant multi-key parsing, `toJson()` |
| `ballot_model.dart` | `Ballot` | Draft selections map; `copyWith()`, `isEmptyPosition()`, `toSubmitPayload()` |
| `vote_receipt_model.dart` | `VoteReceipt` | Submit response: receipt token + status |
| `election_result_model.dart` | `ElectionCandidateResult`, `ElectionResult` | Published results (per position, votes) |
| `registration_model.dart` | `Registration`, `Turnout` | Dashboard registration status + turnout figures |
| `announcement_model.dart` | `Announcement` | Announcement posts |
| `notification_model.dart` | `AppNotification`, `NotificationFeed` | Notification items + unread count; `copyWith(read)` |
| `branding_model.dart` | `Branding` | School branding (name/colors/logo); `fallback()`, color parsing |
| `app_release.dart` | `AppRelease` | GitHub release (version `SemVer`, notes, test channel flag) |
| `candidacy_application_model.dart` | `CandidacyApplication` | The student's candidacy application + status |

### `lib/data/services/` — HTTP layer (Dio)

#### `api_client.dart`
**Purpose:** The core networking file.
**Key functions:**

- `TokenStore` — `save()` / `read()` / `delete()` the Sanctum token (memory on
  web, platform secure storage on native).
- `tokenStoreProvider` / `apiClientProvider` — build the shared `Dio` instance:
  base URL from the runtime override, 10 s timeouts, 20 s upload timeout;
  request interceptor attaches `Authorization: Bearer` and applies the
  runtime-switchable base URL; error interceptor raises
  `AuthEvent.unauthorized` on 401 (except login) and on 403 "account disabled".
- `IdempotentRetryInterceptor` is registered first (see
  `retry_interceptor.dart`).
- `ApiValidationException` — carries the Laravel 422 `message` + `errors`.

#### `retry_interceptor.dart`
**Purpose:** `IdempotentRetryInterceptor` — automatically replays a
transiently failed **GET** once (network blips / 5xx) but never POSTs, so a
vote or form can't be double-submitted. `onRequest()` stamps retry metadata,
`onError()` decides via `_isRetryable()` / `_wasSlow()`.

#### `api_config.dart`
**Purpose:** `ApiConfigStorage` — `saveBaseUrl()` / `clearBaseUrl()` for the
in-app API base URL override (Settings → API), plus `normalizeApiBaseUrl()`;
`apiBaseUrlProvider` feeds every request.

#### `secure_storage_service.dart`
**Purpose:** `SecureStorageService` — `saveToken()`/`deleteToken()` and generic
`saveValue()`/`deleteValue()` over platform secure storage.

#### `login_prefs.dart`
**Purpose:** `LoginPrefs` — "remember me": `load()` / `save()` / `clear()` of
the last email + password used on the login screen.

#### `theme_mode.dart`
**Purpose:** `ThemeModeStorage` — `load()` / `save()` the light/dark/system
theme choice.

#### `auth_service.dart`
**Purpose:** Endpoints for `/auth/*`: `login()`, `logout()`, `getMe()`,
`changePassword()`, `resetPasswordWithCode()` — returns raw `Response`s for
the repository to parse.

#### `election_status_service.dart` — `getStatus()` (GET `/election/status`).
#### `registration_service.dart` — `getMyRegistration()` (GET `/registration/me`).
#### `candidate_service.dart` — `getPositions()`, `getCandidates({filters})`,
`getDepartments()`, `getParties()`, `getCandidate(id)`.
#### `candidacy_service.dart` — `submit()` (multipart with photo), `getMyApplication()`.
#### `vote_service.dart` — `submitVote()` / `submitBallot()` (POST `/vote` or
`/ballot/me/submit`), `getMyBallot()`, `saveDraft()` (PUT), `verifyReceipt()`.
#### `result_service.dart` — `getResults()`, `verify(receiptToken)`,
`getAnnouncements()`.
#### `notification_service.dart` — `getNotifications()`, `markRead({id})`.
#### `branding_service.dart` — `get()` (GET `/branding`).
#### `release_service.dart` — `createDio()` + `fetchReleases()` (GitHub releases
API for the in-app updater).

### `lib/data/repositories/` — parse + cache layer

Each wraps a service, converts `Response` → models (throwing
`ApiValidationException` on 422), and exposes app-friendly futures.

#### `auth_repository.dart`
**Purpose:** `login()` → `LoginResult { student, mustChangePassword }`
(raises the unauthorized event on failure paths), `logout()`,
`changePassword()`, `resetPasswordWithCode()`.

#### `vote_repository.dart`
**Purpose:** Ballot persistence for the voting flow: `submit(selections)` →
`VoteReceipt` **and saves the receipt locally** (`saveReceipt()`),
`saveDraft()`, `verify(receiptToken)`. Also exposes `myBallotProvider`-backed
reads used by My Ballot.

#### `candidate_repository.dart` — `getPositions()`, `getCandidates({filters})`,
`getAllCandidates()`, `getAllCandidatesForTier()`, `getDepartments()`,
`getParties()`, `getCandidate(id)`.

#### `candidacy_repository.dart` — `submit()` (application + photo),
`getApplicationStatus()`.

#### `result_repository.dart` — `getResults()`, `verify()` (receipt check),
`getAnnouncements()`.

#### `notification_repository.dart` — `getFeed()` (items + unread count),
`markRead({id})`.

#### `registration_repository.dart` — `getMyRegistration()`.

#### `branding_repository.dart` — `getBranding({fallback})` (never fails the
app — falls back to defaults).

#### `release_repository.dart` — `fetchLatestRelease()`: picks the highest
`X.Y.Z` from GitHub releases, preferring stable over a same-version test
prerelease; skips unparseable tags.

### `lib/features/` — providers, screens, widgets

#### `features/auth/providers/auth_provider.dart`
**Purpose:** The app's auth state — `authProvider` (StateNotifier).
**Key functions:** `AuthState` (student, isAuthenticated, mustChangePassword,
loading, error; `copyWith()`); `AuthNotifier`: `checkAuth()` (restore session
from stored token via `/auth/me`), `login()`, `changePassword()`,
`resetPasswordWithCode()`, `logout()`, `handleUnauthorized()` (reacts to the
`authEventProvider` 401 signal).

#### `features/auth/screens/splash_screen.dart`
**Purpose:** Initial route — `_bootstrap()` validates the stored session,
loads branding, then routes to `/dashboard`, `/login`, or
`/change-password`.

#### `features/auth/screens/login_screen.dart`
**Purpose:** Login form. `initState()` → `_prefillRemembered()` (LoginPrefs),
`_submit()` calls `authProvider.login`, shows API errors, remembers
credentials.

#### `features/auth/screens/change_password_screen.dart`
**Purpose:** Forced password rotation for registrar-issued temporary
credentials; `_validateNewPassword()`, `_submit()` → `changePassword()`.

#### `features/auth/screens/recover_password_screen.dart`
**Purpose:** Self-service recovery with the registrar activation code
(`resetPasswordWithCode`), with password validation + confirmation.

#### `features/dashboard/providers/dashboard_provider.dart`
**Purpose:** `registrationDataProvider = FutureProvider<Registration>` — the
dashboard's registration/turnout card data.

#### `features/dashboard/providers/announcements_provider.dart`
**Purpose:** `announcementsProvider = FutureProvider<List<Announcement>>` —
public announcements feed.

#### `features/dashboard/providers/election_status_provider.dart`
**Purpose:** Election status polling machinery shared by the whole app:
`electionStatusProvider` (FutureProvider for the status),
`electionStatusTickerProvider` (StreamProvider ticking each interval),
`electionStatusEpochProvider` (bump to force refresh after actions),
`electionStatusPollerProvider`, plus a `Ticker` helper with
`start()`/`stop()`/`emit()`.

#### `features/dashboard/providers/election_status_poller.dart`
**Purpose:** `ElectionStatusPoller` — de-duplicated, failure-tolerant polling
core: `poll()` (single-flight), `shouldSkip({epoch})`, `reset()`,
`_recordFailure()` (keeps last known state instead of erroring), `_run()`.

#### `features/dashboard/screens/dashboard_screen.dart`
**Purpose:** The home tab: composes hero/phase card, ballot progress,
quick actions, countdown, announcements, FAQ; `_showUpdateDialog()` prompts
for app updates when a newer release exists.

#### `features/dashboard/widgets/phase_hero_card.dart`
**Purpose:** `PhaseHeroCard` — the big phase-aware hero: `_greeting()`,
`_PhaseStepper` (registration → voting → results progress), `_PhaseContent`
and `_CopyWithCta` (context-specific call-to-action copy).

#### `features/dashboard/widgets/welcome_banner.dart`
**Purpose:** `WelcomeBanner` — greeting + phase line (`_greeting()`,
`_phaseLine()`) with the student's has-voted state.

#### `features/dashboard/widgets/election_countdown.dart`
**Purpose:** `ElectionCountdown` — live ticking countdown (`_format()`) to the
next window boundary.

#### `features/dashboard/widgets/ballot_progress_tile.dart`
**Purpose:** `BallotProgressTile` — shows draft/submitted ballot status and
links into the ballot/vote flow.

#### `features/dashboard/widgets/quick_actions_grid.dart`
**Purpose:** `QuickActionsGrid` — grid of role/phase-aware shortcut tiles
(vote, candidates, results, apply…).

#### `features/dashboard/widgets/announcements_card.dart` /
`announcements_carousel.dart`
**Purpose:** `AnnouncementsCard` (compact list with `_AnnouncementTile`) and
`AnnouncementsCarousel` (paged cards with `_AnnouncementCard`) — two
presentations of `announcementsProvider`.

#### `features/dashboard/widgets/eligibility_faq.dart`
**Purpose:** `EligibilityFAQ` — expandable FAQ tiles
(`_buildExpansionTile()`) explaining who can vote/run.

#### `features/voting/screens/vote_now_screen.dart`
**Purpose:** The voting screen (largest file, ~1000 lines) — where a student
marks and submits their ballot during `voting_open`.
**Key functions:** `VoteNowScreen` + `_VoteNowScreenState`:
`_buildForPhase()` (phase gating/placeholder), `_buildFlow()` (positions to
vote for), `_buildTabView()` + `_buildTierSwitch()` (National/Provincial
tiers), `_buildBallotSheet()` / `_sheetMasthead()` / `_sheetSectionHeader()` /
`_ballotRow()` (the bottom-sheet ballot UI), `_toggleCandidate()` (mark a
choice), `_buildPartyFilter()`, `_reviewBallot()` (review step with missing
positions), `_saveDraftAndContinue()` (PUT `/ballot/me`), `_isWithinElectorate()`;
private state widgets `_OutOfElectorate`, `_AlreadyVoted`, `_PhaseBanner`,
`_EmptyForParty`; top-level `voteTierCandidatesProvider` (per-tier candidate
family provider).

#### `features/voting/providers/voting_provider.dart`
**Purpose:** `votingProvider` (StateNotifier) — submission state machine.
**Key functions:** `VotingState` (`isSubmitting`, `receipt`, `errorMessage`,
`copyWith()`), `VotingNotifier.submitBallot()` (POST, stores receipt via
repository), `verifyReceipt(token)`.

#### `features/voting/electorate_scope.dart`
**Purpose:** Client-side **mirror** of the server's electorate-scope rules —
used only to hide/lock UI the API would reject anyway (server remains the
authority). **Key functions:** `voterMayVote(position, voter)`,
`candidateIsOnBallot(...)`, `_requiredValue()`, `_voterValueFor()`,
`_candidateValueFor()`, `_matches()`.

#### `features/ballot/screens/my_ballot_screen.dart`
**Purpose:** "My Ballot" — shows the draft ballot for editing/submitting, or
the submitted state with receipt instructions.
**Key functions:** top-level providers `myBallotProvider` (GET `/ballot/me`),
`savedReceiptProvider`, `allApprovedCandidatesProvider`; `MyBallotScreen` +
`_DraftBallot` (`_ballotSlivers()`, `_phaseNotice()`, `_submit()` →
`/ballot/me/submit`), `_BallotRow`, `_SubmittedBallot` (receipt token notice).

#### `features/candidates/providers/candidates_provider.dart`
**Purpose:** Candidates browsing state.
**Key functions:** `positionsProvider`, `departmentsProvider`,
`partiesProvider` (filter sources), `candidatesFilterProvider`
(`CandidatesFilter` state: search/tier/department/party, `copyWith()`),
`filteredCandidatesProvider` (applies the filter over
`allApprovedCandidates`).

#### `features/candidates/screens/candidates_list_screen.dart`
**Purpose:** The Candidates tab — searchable, filterable candidate grid with
tier switching and department locks; can also render the "ballot form" view of
who's on the ballot.
**Key functions:** `CandidatesListScreen` with `_onSearchChanged()` (debounced),
`_onTierChanged()` / `_onTierLabel()`, `_lockedDepartment()`, and private
widgets `_BallotForm` (ballot-style list: `_PositionHeader`, `_BallotRow`,
`_EmptySlot`), `_HeaderLine` / `_CandidateLine` / `_EmptySlotLine`.

#### `features/candidates/screens/candidate_profile_screen.dart`
**Purpose:** `CandidateProfileScreen` — full profile: photo, party, platform
sections (`_buildSectionCard()`), back navigation.

#### `features/candidates/widgets/platform_points_list.dart`
**Purpose:** `PlatformPointsList` — bullet list of a candidate's platform
points.

#### `features/candidacy/providers/candidacy_provider.dart`
**Purpose:** `candidacyProvider` (StateNotifier) — `CandidacyState`
(application + status), `CandidacyNotifier.submit()` (multipart application),
`_candidacyErrorMessage()`.

#### `features/candidacy/screens/candidacy_apply_screen.dart`
**Purpose:** The candidacy application form (registration phase only).
**Key functions:** `CandidacyApplyScreen`: `_loadStatus()` (existing
application → read-only `_StatusView`), `_submit()` (validated form + photo),
`_pickPhoto()` / `_photoSection()` (image picker with extension check),
`_readOnlyField()`, `_tierLabel()`.

#### `features/results/providers/results_provider.dart`
**Purpose:** `resultsProvider = FutureProvider<List<ElectionResult>>` (GET
`/results`, only meaningful after `voting_closed`) and
`verifyReceiptProvider = FutureProvider.autoDispose.family<bool, String>` —
per-token receipt verification.

#### `features/results/screens/results_screen.dart`
**Purpose:** The Results tab: published results per position + the receipt
verification tool.
**Key functions:** `ResultsScreen` with `_statusPlaceholder()` /
`_buildForStatus()` (phase-gated empty/offline states), `_buildResultCard()`
(rank, votes, share), `_ReceiptVerifier` (input + button),
`_VerificationResult` (shows whether the receipt matches — privacy-safe yes/no).

#### `features/notifications/providers/notifications_provider.dart`
**Purpose:** Notification center state + polling.
**Key functions:** `notificationTickerProvider` (30 s ticker while authed),
`NotificationsState` (items, unread, loading; `copyWith()`),
`NotificationsNotifier`: `refresh({silent})`, `markRead(id)`, `markAllRead()`,
`clear()`; `notificationsProvider`.

#### `features/notifications/screens/notifications_screen.dart`
**Purpose:** Notification list screen: `NotificationsScreen` with `_body()` /
`_scrollable()` (grouped items), `_openLink()` (deep-links from a
notification), unread handling and mark-read actions.

#### `features/settings/providers/branding_provider.dart`
**Purpose:** `brandingProvider = FutureProvider<Branding>` — school branding
(name, colors, logo) used by the theme, logos, and top bar.

#### `features/settings/screens/settings_screen.dart`
**Purpose:** `SettingsScreen` — the settings menu (profile, appearance,
notifications, app updates, API settings, help, about, logout) navigating to
the sub-screens; the App Updates tile reads the live installed version from
`appUpdateProvider.installed` (via `package_info_plus`) and the About dialog
displays it, with an entry to check for new versions.

#### `features/settings/screens/my_profile_screen.dart`
**Purpose:** `MyProfileScreen` — read-only student profile card
(`_InfoRow`) with identity, department/course, and voting status.

#### `features/settings/screens/api_settings_screen.dart`
**Purpose:** Developer/power-user API base-URL override.
**Key functions:** `_testConnection()` (probe the URL), `_applyUrl()`,
`_save()` (persist via `ApiConfigStorage` + SnackBar feedback).

#### `features/settings/screens/help_faq_screen.dart`
**Purpose:** `HelpFaqScreen` — static FAQ/help content.

#### `features/app_update/providers/app_update_provider.dart`
**Purpose:** In-app update flow.
**Key functions:** `AppUpdateState` (latest version/notes, dismissed, pending;
`copyWith()`), `AppUpdateNotifier`: `init()` (restore dismissed state),
`check({force})` (fetch releases, compare with `SemVer`, settle dialog/banner
state), `dismissBanner()`, `markDialogShown()`; top-level `appUpdateProvider`.

#### `features/app_update/providers/app_update_storage.dart`
**Purpose:** `AppUpdateStorage.saveSeenVersion()` — remembers the last version
the student was shown so prompts don't repeat.

#### `features/app_update/screens/app_update_screen.dart`
**Purpose:** `AppUpdateScreen` — "what's new / download" page:
`_latestSection()` (release notes + link), `_ChannelBadge` (stable vs test),
`_formatDate()`.

---

## Appendix — supporting files

### Notable backend config (not covered above)

| File | Role |
|---|---|
| `bootstrap/app.php` | App bootstrap: registers middleware aliases (`checkPhase`, `role`, `passwordChanged`, `permission`), `statefulApi()` for the SPA, exception handling |
| `config/permissions.php` | The role catalog: `admin` (full), `teacher`, `student`, `ssg_president` and their permissions — read by `EnsurePermission` |
| `routes/console.php` / `app/Providers/AppServiceProvider.php` | Scheduler + rate limiter definitions (described above) |

### Tests (`backend-laravel/tests/`)

Feature/Unit tests document expected behavior — useful for walkthroughs:

- **Auth & security:** `StudentAuthTest`, `AdminAuthTest`-adjacent suites,
  `TwoFactorLoginTest` + `Unit/TwoFactorTest`, `LoginRateLimitTest`,
  `LoginBackoffRecoveryTest`, `DisabledUserLoginTest`, `PasswordResetTest`,
  `SelfRegistrationActivationCodeTest`, `Unit/UserAccountStatusTest`,
  `Unit/TrustedHostsTest`, `AssertProductionConfigCommandTest`,
  `SeederProductionGuardTest`.
- **Election core:** `StudentVoteAndCandidateFieldsTest`,
  `Unit/ElectionTallyTest`, `PhaseTimelineTest`, `PositionScopeTest`,
  `BallotElectorateScopeTest`, `PositionUpdateTest`,
  `CandidateHierarchyOrderTest`, `InputBoundsAndTurnoutDisclosureTest`,
  `VotingWindowSettingsTest`, `PublicResultsTest`.
- **Admin workflows:** `AdminCandidateAndPartyCreateTest`,
  `AdminCourseCrudTest`, `DepartmentManagementTest`, `RegistrarImportTest`,
  `RegistrarImportCourseTest`, `AnnouncementAuthTest`, `BackupTest`,
  `StudentNotificationTest`.
- **Support:** `tests/TestCase.php`, `tests/bootstrap.php`,
  `Concerns/DisablesTwoFactorEnforcement`, `Unit/SeederPasswordResolutionTest`.

### Database (`backend-laravel/database/`)

- **`migrations/`** — schema history: users/phases/positions/candidates,
  ballot drafts & vote ledger, registrar imports with activation codes,
  electorate scopes on positions, catalog alias tables, notifications,
  settings. Read them top-to-bottom for the data model story.
- **`seeders/`** — `DatabaseSeeder` (entry), `UserSeeder` (panel accounts),
  `StudentSeeder`, `CatalogSeeder` (colleges/departments/courses + aliases),
  `TestBallotSeeder` (dev election data). Guarded against running in
  production.

### Deployment (`deploy/`) — scripts, not app logic

| File | Function |
|---|---|
| `setup-server.sh` / `setup-staging.sh` | One-time host provisioning (packages, nginx, PHP-FPM, MySQL, systemd units) |
| `deploy.sh` | CI entry: resets checkout to `origin/main`, migrations, caches, service restarts, runs `security:assert-production-config` |
| `fix-runtime-permissions.sh` | Repairs storage/bootstrap cache permissions |
| `archive-production-migration-data.sh` | One-off archival of legacy production rows |
| `nginx-omnivote.conf` / `nginx-funnel-api.conf` | nginx site configs (TLS, `/admin/` SPA, API funnel) |
| `php-fpm-pool.conf` | Hardened PHP-FPM pool |
| `omnivote-worker.service` / `omnivote-backup.service` / `omnivote-backup.timer` | Queue worker + encrypted daily backup systemd units |
| `README.md` | The full production runbook |

### How to present this to the team (suggested flow)

1. **Big picture** — system table above + `docs/ARCHITECTURE_GUIDE.md`.
2. **A request's life** — pick `POST /vote`: route → `checkPhase` →
   `VoteController::submit` → `vote_ledger` → receipt HMAC; then the Flutter
   `votingProvider.submitBallot`.
3. **Auth contrast** — `routes/api.php` cookie group vs bearer group,
   `AuthContext.jsx` vs `auth_provider.dart`.
4. **Admin day-in-the-life** — `App.jsx` view switch → one page (e.g.
   `StudentRegistry`) → its controller (`RegistrarImportController`).
5. **Security invariants** — ballot secrecy (H-1), phase gates, throttles,
   `EnsurePermission`.
