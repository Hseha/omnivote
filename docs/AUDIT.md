# OmniVote Cross-Codebase Audit

**Date:** 2026-08-30
**Audited branch:** `userSide-code` @ `f5968cb`
**Layers audited:** `backend-laravel` (Laravel 13 / Sanctum 4) · `admin-react` (React 19 + Vite + axios) · `user-flutter` (Flutter / Riverpod / Dio / flutter_secure_storage)
**Contract sources:** `docs/05_API_INTEGRATION.md`, `docs/03_APP_FLOW.md`, `docs/04_SCREENS_SPEC.md` + the five core constraints in this audit request.

---

## 1. Executive Summary

The three repositories are in an **early shared-skeleton state**. The Laravel backend currently exposes exactly **one operational API surface** — `POST /api/login` (a closure that validates credentials, enforces `role ∈ {admin, teacher}`, and returns a JSON user **without issuing any token or session cookie**). A second, competing `POST /login` session flow exists in `AuthController` (uses the `web` guard, has no role check, and is wired to the same path the SPA never calls).

Every other endpoint both clients already call or expect is **unimplemented**:

| Client call site | Expected endpoint | Backend status |
|---|---|---|
| `user-flutter` AuthService / AuthRepository | `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`, `POST /api/auth/register` | ❌ Missing (only `/api/login` exists) |
| `user-flutter` (docs 05) | `GET /api/election/status`, `GET /api/registration/me` | ❌ Missing |
| `user-flutter` CandidateService | `GET /api/positions`, `GET /api/candidates`, `GET /api/candidates/{id}` | ❌ Missing |
| `user-flutter` (docs 05) | `GET/PUT /api/ballot/me`, `POST /api/ballot/me/submit`, `GET /api/results`, `POST /api/results/verify` | ❌ Missing |
| `user-flutter` (docs 05 + screen spec §8) | `GET /api/candidacy/me`, `POST /api/candidacy` (multipart) | ❌ Missing |
| `admin-react` AdminDashboard | `GET /api/admin/dashboard-overview` | ❌ Missing |
| `admin-react` Candidates | `GET /api/admin/candidates`, `PATCH /api/admin/candidates/{id}` | ❌ Missing |
| `admin-react` StudentRegistry | registrar CSV import (`POST /api/admin/registrar/import` implied) | ❌ Missing |
| `admin-react` ElectionSetup | election config read/write (implied) | ❌ Missing |

Sanctum is installed (`laravel/sanctum ^4.0`, `config/sanctum.php` published) but is **not wired**:
- `User` model does **not** use the `HasApiTokens` trait → personal access tokens cannot be issued.
- No `sanctum/csrf-cookie` route, no `EnsureFrontendRequestsAreStateful` alias/middleware (`bootstrap/app.php` has an empty `withMiddleware`).
- CORS (`vendor` default) runs with `supports_credentials => false` and `allowed_origins => *` → cookie-based admin SPA auth is impossible today.
- No logout endpoint revokes anything; token revocation tables (`personal_access_tokens`) are never populated.

The Flutter model layer is ~50% implemented (Models: Student, Candidate, Position, Registration done; Ballot, VoteReceipt, ElectionResult, CandidacyApplication are stubs). Ballot/Voting/Results/Candidacy features and providers are stubs. The dashboard/candidates screens render against hardcoded fallback data on API failure.

---

## 2. Constraint 1 — Global Lifecycle State Machine Enforcement (Phases 1/2/3)

### 2.1 Requirement
Backend must expose exactly **three mutually exclusive phases** — `Registration`, `Voting Open`, `Voting Closed` — and reject out-of-phase requests with **403 Forbidden**. Clients must toggle capabilities from the phase fed by the API:
- Phase 1 Registration: allow registration/candidacy application; **block** ballot edits + submit.
- Phase 2 Voting Open: allow ballot edit/submit; **block** registration.
- Phase 3 Voting Closed: read-only candidates, ballot becomes read-only; only results/verify allowed; **block** submit with 403.

### 2.2 Findings
**Backend**
- No `elections`/`election_phases` table migration exists. The only lifecycle-adjacent artifact is `users.has_voted` (a flag, not a phase).
- No phase-aware middleware, no `election/status` endpoint, no admin config endpoint. Nothing returns 403 for out-of-phase requests because no phase-modulated endpoint exists.
- The two login implementations use different auth guards with no shared phase concept.

**admin-react**
- `ElectionSetup.jsx` hardcodes a phase picker with **wrong labels**: `['Draft', 'Registration', 'Active', 'Closed']` (spec: `Registration / Voting Open / Voting Closed`); state is component-local, never persisted, never read by other views.
- `Admindashboard.jsx` initializes `electionPhase = 'Voting Open'` and renders a static "Voting Open" badge; Results still shows partial "live" tallies regardless of phase.
- No capability toggling is wired to any phase value anywhere in the SPA.

**user-flutter**
- `status_badge.dart` hardcodes the string **'Voting Open'** on every screen, in every phase.
- `ApiConstants.electionStatus = '/election/status'` exists but there is **no consumer** — no `ElectionStatus` model, no provider, no polling on resume, no pre-vote re-check (docs 05 explicitly requires polling before Vote Now / My Ballot).
- Vote Now / My Ballot / Election Results screens are stubs, so no phase gating exists client-side.

### 2.3 Required changes
**Backend**
1. New migration `create_elections_table`: `id`, `title`, `phase` enum(`registration`,`voting_open`,`voting_closed`) default `registration`, `registration_opens_at`/`registration_closes_at` (nullable), `voting_opens_at`/`voting_closes_at`, `created_by` (FK users), timestamps.
2. New `EnsureElectionPhase` middleware (`phase:registration|voting_open|voting_closed`) returning **`403 {success:false, message, phase}`** JSON for unauthorized phase actions; alias it in `bootstrap/app.php`.
3. Route groups and phase gates:
   - `auth` (student) group → always allowed.
   - Phase 1-only → `POST /auth/register`, `POST /candidacy`, `PUT /ballot/me`.
   - Phase 2-only → `PUT /ballot/me`, `POST /ballot/me/submit`.
   - Phase 3-only → `POST /results/verify` (submit + registration get 403).
   - `GET /election/status`, `GET /positions`, `GET /candidates*`, `GET /registration/me`, `GET /ballot/me`, `GET /results` → phase-agnostic (read) but content-gated (results only populated in phase 3).
4. Admin endpoints `GET` / `PUT /api/admin/election/config` (admin/teacher only) with transition guard: `registration → voting_open → voting_closed`, **no backwards skip**, only one active election; phase change persists in the same transaction as the window times.

**admin-react**
1. Replace phase labels in `ElectionSetup.jsx` with the canonical three; load from `GET /api/admin/election/config`; persist via `PUT`; disable saves for invalid transitions.
2. Introduce a single `electionPhase` context/provider consumed by Dashboard badge, Results (hide `live` tallies until phase 3), Candidates (freeze approve/reject when phase ≠ registration), and Settings ("Voting Open" chip must reflect server truth).
3. Remove the hardcoded 'Voting Open' / `14:32:05 EST` fallbacks; drive them from `/api/election/status`.

**user-flutter**
1. Add `ElectionStatus` model + `electionStatusProvider` (`FutureProvider` + `ref.invalidate` on app resume via `AppLifecycleListener`).
2. Replace the hardcoded `StatusBadge` text with phase-driven text/color; render disabling banners for `not_registered`/`voting_closed`.
3. Gate Vote Now / My Ballot / Candidate Vote buttons on `voting_open`; re-fetch status immediately before entering Vote Now and before `POST /ballot/me/submit`; handle server 403 gracefully (banner + refresh).

---

## 3. Constraint 2 — Authentication & Token Boundaries (Sanctum / HttpOnly cookies / secure storage)

### 3.1 Requirement
- Laravel issues Sanctum tokens correctly.
- `admin-react` relies on **HttpOnly/Secure cookies + CSRF protection** (stateful Sanctum / SPA authentication) — never tokens in `localStorage`.
- `user-flutter` stores tokens via `flutter_secure_storage` on mobile, **short-lived tokens** on web.

### 3.2 Findings

**Backend**
1. **Sanctum not wired.** `User` model (`app/Models/User.php`) uses `#[Fillable]`/`#[Hidden]` attributes but **no `HasApiTokens`** → `$user->createToken()` impossible. No `personal_access_tokens` usage anywhere.
2. **No token issuance.** `POST /api/login` returns `{success, user}` with no `token`. `AuthController::login` (web) is session-based. Neither satisfies the Flutter `{ token, student }` contract.
3. **No SPA session boundary.** No `/sanctum/csrf-cookie`, no `EnsureFrontendRequestsAreStateful` middleware, `bootstrap/app.php` `withMiddleware` is empty. `config/sanctum.php` `stateful` list exists but nothing mounts the middleware.
4. **CORS prevents cookies.** Framework default (`vendor/laravel/framework/config/cors.php`) is `allowed_origins => *` with `supports_credentials => false`. Admin SPA on `http://localhost:5173` cannot send cookies even with `withCredentials: true`.
5. **No logout/revocation, no me endpoint.** Token deletion / session invalidation impossible; `GET /auth/me` missing.
6. **Two competing login paths** with different semantics (`/api/login` role-gated closure vs `/login` AuthController without role check) create an inconsistent surface.
7. `users.email` is the only login identifier on the web side; Flutter `AuthService.login` posts `student_id`. The contract and the implementation disagree on the identifier.

**admin-react**
- `AdminLogin.jsx` uses raw `fetch` to `http://127.0.0.1:8000/api/login` with no `credentials: 'include'`, and **stores the returned user JSON in `localStorage['omnivote_user']`** (`App.jsx`). This violates the HttpOnly-cookie requirement and is XSS-sensitive.
- `Admindashboard.jsx` / `Candidates.jsx` use separate `axios` calls with `withCredentials: true` but nowhere against a cookie-authenticated session and with no CSRF header (`X-XSRF-TOKEN`); no axios instance centralizes this.
- No handling of 401/403 to force server-side logout.

**user-flutter**
- `flutter_secure_storage` is correctly used for the token (`secure_storage_service.dart`) — good for mobile.
- **No web branch:** Dio interceptor attaches the Bearer token from secure storage on **all platforms**, contradicting the "short-lived tokens (web)" requirement. On web, `flutter_secure_storage` falls back to WebCrypto-backed storage with no TTL / refresh flow.
- `AuthRepository.login()` parses `response.data['token']` then `Student.fromJson(response.data['student'])`, but the only implemented backend returns `{ success, user }` — parse mismatch guarantees failure.
- `GET /auth/me` response is parsed as a bare `Student`, while docs 05 says `me → student` (could be `{ student }`) — contract ambiguity to resolve.
- `auth_provider.dart` contains a **hardcoded mock-login bypass** (`studentId == '24-00001' && password == 'tester1'`) that authenticates a fake student without any server call — must be removed before anything ships.
- 401 handling forces logout globally (good) but there is no refresh/re-auth flow; a single 401 from any endpoint logs out the user.

### 3.3 Required changes
**Backend**
1. Add `use HasApiTokens;` to `User`.
2. Implement Sanctum SPAs:
   - `POST /sanctum/csrf-cookie` (stateful) → CSRF cookie for admin SPA.
   - `POST /api/admin/login` → validates credentials, enforces `role ∈ {admin, teacher}` (403 otherwise), personal-access-token issued **and** session cookie set via `EnsureFrontendRequestsAreStateful`.
   - `GET /api/admin/me`, `POST /api/admin/logout` (revoke current token + session).
   - `POST /api/auth/login` (students) → returns `{ token, student }` (Bearer used by Flutter).
   - `POST /api/auth/logout`, `GET /api/auth/me`, `POST /api/auth/register` (phase-1 gated).
3. Update `bootstrap/app.php`: alias `EnsureFrontendRequestsAreStateful` and register the Sanctum aliases; publish `config/cors.php` with `allowed_origins: [http://localhost:5173, ...env]`, `supports_credentials: true`, `allowed_headers: ['Content-Type', 'X-XSRF-TOKEN', 'Authorization']`.
4. Delete/replace the competing `/web/login` AuthController flow or align it with the admin SPA path.
5. Token expiry policy: student Bearer tokens TTL ~1 day (configurable), admin session per `SESSION_LIFETIME`, cookie `HttpOnly; SameSite=Lax; Secure` in prod.

**admin-react**
1. Centralize an axios instance (`src/lib/api.js`): `baseURL`, `withCredentials: true`, `xsrfCookieName: 'XSRF-TOKEN'`, `xsrfHeaderName: 'X-XSRF-TOKEN'`, `Accept: application/json`.
2. Login flow: `GET /sanctum/csrf-cookie` → `POST /api/admin/login` (with credentials) → `GET /api/admin/me` for profile. **Remove `localStorage['omnivote_user']` as an auth store**; keep it only as a display cache, and validate via `/api/admin/me` on app load.
3. Response interceptor: on `401` → purge state and route to login; on `403` → surface the phase/role message.

**user-flutter**
1. Branch token storage by platform:
   - `!kIsWeb` → `FlutterSecureStorage` (unchanged).
   - `kIsWeb` → in-memory (short-lived, Volatile), never persisted to `localStorage`/`shared_preferences`; token lost on reload → re-login.
2. Align parse code with the backend contract: `login` → `{ token, student }`; `me` → decide and document whether it returns `student` top-level or nested, then update `AuthRepository`/docs.
3. Remove the hardcoded `24-00001/tester1` bypass from `auth_provider.dart`.
4. Keep the 401→logout interceptor, but scope unauthorized events to the originating feature where possible, and add a guard against recursive logout on the login request itself.

---
---

## 4. Constraint 3 — Decoupled Ballot Privacy & Anonymity Engine

### 4.1 Requirement
The vote submission endpoint must execute as a **single ACID transaction** that updates the voter status flag **and** writes ballot choices to an **unindexed, decoupled tally ledger** with **zero relational link to user IDs**. Receipt-token verification must never reveal candidate choices.

### 4.2 Findings
**Backend**
1. **No submission endpoint exists.** There is no `POST /ballot/me/submit`, no transaction, no `lockForUpdate`, nothing.
2. **The only ballot table is the wrong shape.** `ballot_tallies` is an **aggregate** (`candidate_id`, `ssg_office`, `tally_count`) — not a per-ballot ledger. It has a primary key (indexed), no timestamps, no receipt, and its `candidate_id` is a plain `unsignedBigInteger` **without** an FK but **with** implicit relational meaning to `candidates`. It also duplicates the per-vote data that would be needed for recounts/audits.
3. `users.has_voted` (boolean, indexed) exists but nothing toggles it.
4. No `receipt_token`/HMAC storage anywhere — the required anonymous verification flow (`POST /results/verify`) cannot be built on the current schema.
5. No one-vote invariant: no unique constraint or transactional check prevents double-casting beyond the (never used) `has_voted` flag.

### 4.3 Required changes
**Backend**
1. New migration `create_vote_ledger_table` (the decoupled ledger):
   - `id` (PK — retained only for insert ordering, **no lookup index allowed** on candidate/receipt/position),
   - `election_id` FK,
   - `position_key` (string, e.g. `president`, `school/12` — replaces `ssg_office`),
   - `candidate_ref` (opaque token — a non-relational hash; **not** an FK to `candidates`, no index),
   - `receipt_hmac` (HMAC-SHA256 of the receipt token; **no index**),
   - `ledger_sequence` (monotonic int), `recorded_at` timestamp.
   - Deliberately: **no `user_id`, no FK to `users`, no index on any selection column.**
2. Replace `ballot_tallies` with this ledger (or keep it only as a post-close denormalized cache rebuilt from the ledger in a background job — never written transactionally during voting).
3. `POST /api/ballot/me/submit` — all inside **one `DB::transaction`**:
   1. Re-read election phase (must be `voting_open`) → else **403**.
   2. `User::whereKey($user->id)->lockForUpdate()->first()`; if `has_voted` → return the **existing receipt idempotently** (no double write).
   3. Re-validate selections against `positions`/`seat_count` (1..12 for senator).
   4. Insert one ledger row per selected position (`position_key` + `candidate_ref` hash); store `receipt_hmac = hash_hmac('sha256', $receipt, config('omnivote.receipt_key'))`.
   5. Set `has_voted = true` (and `voted_at`).
   6. `return { receiptToken }`. Any failure → rollback → `422/409`.
4. `POST /api/results/verify` — compare `hash_hmac` to `vote_ledger.receipt_hmac`; return only `{ counted: true|false }`. **Never** join back to candidates/users.
5. `GET /api/results` — aggregates from the ledger only when phase == `voting_closed`, per `position_key`, ranking candidates by `candidate_ref` count.

**user-flutter**
- `vote_repository.dart`, `vote_receipt_model.dart`, `ballot_model.dart`, `election_result_model.dart`, Results screen, and `voting_provider.dart`/`ballot_provider.dart` are **stubs** — implement the ballot draft → submit → receipt → verify flow with the exact `{ receiptToken }` contract; never compute tallies client-side.
- Persist `receiptToken` (via secure storage) so the user can paste it into Results; never transmit it with candidate names.

**admin-react**
- `Results.jsx` is 100% mock data with a `live` subview that shows per-candidate tallies mid-election — remove/disable `live` until `phase == voting_closed` and consume `GET /api/results`.

---
---

## 5. Constraint 4 — Candidate Application & Automated Sanitization

### 5.1 Requirement
Student candidacy submissions handle **multipart data** (photo file + multi-field platform text); the backend **automatically scans/sanitizes text inputs for XSS** before queueing for admin/teacher review.

### 5.2 Findings
**Backend**
1. **No candidacy endpoint.** `POST /api/candidacy` (multipart) and `GET /api/candidacy/me` are unimplemented; no controller, no `FormRequest`, no file storage rules.
2. **Schema incomplete.** `candidates` table has `user_id` FK, `ssg_office`, `platform_statement`, `approval_status`, but **no photo column**, no `party_name`, no `year_level`/`course`, no `platform_points` (JSON), no review metadata.
3. **No XSS sanitization.** No queue job, no HTML purifier, no `sanitize` helper. `platform_statement` is `longText` stored raw — a stored-XSS vector into the admin review UI and public profile.
4. **Office menu mismatch.** `ssg_office` enum only allows `President, Vice_President, Secretary, Treasurer, Auditor` — missing Press Officer, Senator, Year Level Representative, Property Custodian, and all seven provincial offices from specs.
5. `Candidate` model is empty (no `$fillable`, casts, relations) — cannot even mass-assign safely.
6. No workflow wiring: approving a candidate never flips `users.role` to `candidate`.

**admin-react**
- `Candidates.jsx` reads/writes `/api/admin/candidates` with **uppercase** status values `'Approved'/'Rejected'` while the backend enum is lowercase `pending/approved/rejected` — mismatch will 422.
- Shows `platformPoints`/avatar mock UI that has no backend contract; the review screen should display sanitized platform text and the uploaded photo.

**user-flutter**
- `candidacy_application_model.dart`, `candidacy_repository.dart`, `candidacy_provider.dart`, `validators.dart`, and `apply_for_candidacy_screen.dart` are **stubs**; only `photo_upload_field.dart` and `candidate_info_form.dart` widgets exist.
- The spec (§8): photo PNG/JPG/JPEG ≤ 5MB, `POST` multipart with `certify` flag; expected `GET /api/candidacy/me` returns `none|pending|approved|rejected`.

### 5.3 Required changes
**Backend**
1. Widen the schema (new migration):
   - `candidates.photo_path` (nullable string), `party_name` (nullable), `year_level` (nullable string), `course` (nullable string), `platform_points` (JSON or separate `candidate_platform_points` table), `sanitization_status` (`pending_scan|clean|flagged`) default `pending_scan`.
   - Prefer a real `positions` table (`id, slug, label, tier, seat_count, description`) seeded from `docs/04_SCREENS_SPEC.md` global list, replacing `ssg_office` with `position_id` FK.
2. `POST /api/candidacy` (multipart):
   - Validate with `FormRequest`: photo `image|mimes:jpeg,png,jpg|max:5120`; `platform_statement`/`party_name` max-length + required; certification flag required.
   - Sanitize **server-side** before persistence: strip/encode `<script>`, `on*` handlers, `javascript:` URIs, `<iframe>`, `<style>`, `data:` URIs in URL fields. Store sanitized text (and optionally a flagged raw copy for human review).
   - Enqueue `ScanCandidacyForXSS` job (queues: `database`) → sets `sanitization_status = clean|flagged` for the admin review queue. Route through the `phase:registration` gate (403 otherwise).
3. `GET /api/candidacy/me` → current user's application status.
4. Admin review:
   - `GET /api/admin/candidates` returns sanitized fields + photo URL.
   - `PATCH /api/admin/candidates/{id}` with lowercase `approved|rejected`; on `approved`, set `users.role = 'candidate'` in the same transaction.
5. Decision: do not trust client-side sanitization as the security boundary — the server scan is authoritative at submission time and at render time.

**user-flutter**
1. Implement multipart upload with Dio `FormData` (`/api/candidacy`), matching server field names (photo + platform statement + party + position + certify).
2. Implement `validators.dart` (required, email, ≤5MB, image MIME) and the `CandidacyApplication` model; wire `candidacy_provider` to `GET /api/candidacy/me` for status display.

**admin-react**
1. Normalize status values to lowercase (`approved`/`rejected`) in `Candidates.jsx` (`handleStatusChange`).
2. Render `photoUrl`, sanitized platform statement, and `sanitization_status` badge in the review table; disable Approve while `sanitization_status == 'pending_scan'`.

---
---

## 6. Constraint 5 — Data Model & Field Standardization

### 6.1 Field-conflict map (current state)

| Concept | backend-laravel | admin-react | user-flutter | Verdict |
|---|---|---|---|---|
| Grade / year | `registrar_imports.grade_level` (string) | `yearLevel` keys in mock rows, values `'BSIT-3'` (course-major, not grade) | `Student.gradeLevel` ← `json['grade_level']`; `Candidate.gradeLine` ← `json['gradeLine']`; candidacy form uses "Year Level" | 🔴 **Conflict:** standardize on `grade_level` on the wire; Flutter field `gradeLevel`; drop `gradeLine`; admin consumes `grade_level` |
| Position/office | `candidates.ssg_office` enum (5 values, underscores) | `position` display string (`'President'`, `'Vice President'`) | `Position{id,label,tier,seatCount,description}`; filter param `position` | 🔴 **Conflict:** no `positions` table; enum undersized + snake-cased vs camelCase/tier contract; spec requires two tiers + fixed seat counts |
| Candidate identity fields | `candidates.platform_statement` longText mono-field | `party`, `submissionDate`, mock `avatar` | `slogan`, `platformPoints[]`, `qualifications[]`, `photoUrl`, `videoUrl`, `party` | 🔴 **Mismatch:** single `platform_statement` cannot express `slogan`/`platformPoints`; photo URL not stored server-side |
| Approval status | enum `pending/approved/rejected` (lowercase) | sends uppercase `Approved/Rejected` | expects `none/pending/approved/rejected` in `/candidacy/me` | 🔴 **Conflict:** normalize to lowercase everywhere; add `none` semantics client-side |
| Voting flag | `users.has_voted` boolean (indexed) | n/a | n/a (uses turnout counts) | ✅ exists, but unmanaged (no code modifies it) |
| Student identity | `users.student_id` unique + `registrar_imports.student_id` | `id: 'STU-2024-0001'` mock | `Student.studentId` ← `json['student_id']` | ✅ mostly aligned; admin mock ids are display-only |
| Registration/turnout | none | n/a | `Registration{registration_date, eligibility_status, turnout{registered_students,total_students,actual_ballots_cast}}` | ❌ missing backend (`registrar_imports` lacks eligibility/registration_date) |
| Receipt token | none | n/a | `vote_receipt_model.dart` stub | ❌ missing ledger column (see Constraint 3) |
| Role enum | `users.role` ∈ admin/teacher/candidate/student | roleClass `role-student` etc. | n/a | ⚠️ assign `candidate` on approval; align UI badge labels |
| Ballot/selections | `ballot_tallies(candidate_id, ssg_office, tally_count)` | n/a | `selections: { [positionId]: [candidateId...] }` in `PUT /ballot/me` | 🔴 replace with ledger + selections schema (`position_key → candidate_ref`) |

### 6.2 Required standardization
1. **Wire keys:** all JSON APIs emit snake_case (`grade_level`, `student_id`, `receipt_token`, `position_key`, `approval_status`, `seat_count`, `photo_url`). Flutter `fromJson` maps to its own camelCase fields (already standard practice); **do not** invent `gradeLine` — use `json['grade_level']`.
2. **Positions:** add `positions` table, seed with the exact school (9) + provincial (7) lists from `docs/04_SCREENS_SPEC.md`; change `candidates` to `position_id` FK; remove `ssg_office`.
3. **Candidate profile:** store `slogan`, `platform_points` (JSON), `qualifications`, `party_name`, `photo_path`, `video_url` explicitly; the API response mirrors Flutter `Candidate.fromJson`.
4. **Turnout/registration:** back `GET /api/registration/me` with a `voter_registrations` table (or `users.*` columns `registration_date`, `eligibility_status`, `grade_level`, `course`, `homeroom`) so Flutter's `Registration` model parses cleanly.
5. **Status vocabularies:** single set `pending | approved | rejected` on the wire for both candidacy and admin review; `candidacy/me` adds `none` when no application exists.
6. **Docs drift:** `user-flutter/docs/*` have diverged from root `docs/*` (implementation-status notes, mock credentials). Re-sync so one contract doc is the single source of truth; remove the mock `24-00001/tester1` from docs and the auth provider.
---

## 7. Prioritized remediation roadmap

| # | Priority | Change | Layer | Blocks |
|---|---|---|---|---|
| 1 | 🔴 P0 | Sanctum wiring: `HasApiTokens`, stateful middleware, CORS credentials, `sanctum/csrf-cookie`, admin+student login/logout/me endpoints | backend | All auth-dependent work |
| 2 | 🔴 P0 | Elections + `EnsureElectionPhase` middleware + `GET /election/status` + admin config endpoints returning 403 on phase violations | backend | Client capability toggling |
| 3 | 🔴 P0 | Positions table + seed; candidates schema (photo, platform fields, `position_id`); vote-ledger migration; transactional submit + idempotent receipt; results + verify | backend | Constraints 3/4/5 |
| 4 | 🟠 P1 | Candidacy multipart endpoint + server-side XSS sanitize + queued scan job | backend | Constraint 4 |
| 5 | 🟠 P1 | admin: axios instance (credentials + XSRF), cookie-based login, remove localStorage auth, phase context, lowercase status PATCH, results gating | admin-react | Constraints 1/2 |
| 6 | 🟠 P1 | flutter: platform-branched token storage, remove mock bypass, ElectionStatus provider, phase-gated screens, implement ballot/vote/results/candidacy stubs | user-flutter | Constraints 1/2/3/4 |
| 7 | 🟡 P2 | Field standardization pass across migrations/models/DTOs (snake_case wire contract, `grade_level`, status vocab) | all | Constraint 5 |
| 8 | 🟡 P2 | Registrar import endpoint + StudentRegistry wiring; ElectionSetup persistence; dashboard stats endpoint | backend + admin | Feature parity |
| 9 | 🟡 P2 | Resync `user-flutter/docs` with root `docs`; add tests for 403 gating, idempotent submit, anonymity invariants | docs + backend tests | Full alignment |

## 8. Reference — key evidence files
- Backend routes: `backend-laravel/routes/api.php` (login only), `routes/web.php` (`/login`).
- Backend models: `app/Models/User.php` (no `HasApiTokens`), `app/Models/Candidate.php` (empty).
- Migrations: `database/migrations/2026_08_24_000003_create_candidates_table.php`, `...000004_create_ballot_tallies_table.php`.
- Admin: `admin-react/src/AdminLogin.jsx`, `App.jsx` (localStorage auth), `ElectionSetup.jsx` (phase labels), `Candidates.jsx` (uppercase statuses), `Results.jsx` (mock tallies).
- Flutter: `lib/data/services/api_client.dart` (Bearer interceptor), `lib/data/services/auth_service.dart`, `lib/features/auth/providers/auth_provider.dart` (mock bypass), `lib/features/candidacy/...` & `lib/data/repositories/vote_repository.dart` (stubs), `lib/core/widgets/status_badge.dart` (hardcoded 'Voting Open').
---

## 9. Cleanup audit — 2026-08-30 (post-audit pass)

### 9.1 Executive outcome

Repository-wide audit of `admin-react`, `backend-laravel`, and `user-flutter`. All tracked
legacy/duplicate/stub/mock artifacts that did not belong to the active Sanctum + phase-lifecycle
architecture were removed. No active dependency, route table, database model, or state provider
was left broken. Verified: backend routes load, React builds, Flutter analyzes clean.

### 9.2 Removed — backend-laravel

| File | Reason |
|---|---|
| `app/Http/Controllers/AuthController.php` | Competing legacy session login (no role check) superseded by `AdminAuthController` + `StudentAuthController`. Wired route `POST /login` removed from `routes/web.php`. |
| `app/Http/Requests/LoginRequest.php` | Unused form request; not referenced by any controller. |
| `resources/views/welcome.blade.php` | Default Laravel template stub; `/` route removed from `routes/web.php`. |
| `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Default framework template tests (Feature test asserted the deleted welcome page). |
| `database/migrations/2026_08_24_000004_create_ballot_tallies_table.php` | Obsolete tally scheme (`candidate_id` + `ssg_office`) superseded by `vote_ledger` per this audit's roadmap §7. Unreferenced by any model/controller. |
| `.gitkeep` | Empty placeholder in a content-full directory. |

Relocated instead of removed:

| File | Action |
|---|---|
| `backend-laravel/data` → `database/migrations/2026_08_30_000003_create_personal_access_tokens_table.php` | The Sanctum `personal_access_tokens` migration had been saved as a stray root-level file `data` (never executed by Laravel — migrations are only auto-loaded from `database/migrations`). Moved into place so `php artisan migrate` provisions the token table the Sanctum auth controllers require. |

### 9.3 Removed — user-flutter

| File(s) | Reason |
|---|---|
| `OmniVote/` (38 tracked files) | Stale nested project from the pre-flatten layout (commit `bbdc45e`); duplicated build artifacts + platform stubs. |
| 31 × `lib/**/*.dart` marked `// STATUS: stub` (ballot, vote_receipt, election_result, candidacy_application models; vote/result/candidacy repositories; ballot/voting/results/candidacy providers + screens + widgets; `app_spacing`, `date_formatter`, `validators`, `app_scaffold`, `side_nav`, `vote_button`, `position_filter_bar`, `position_tabs`, `help_faq`, `my_profile`) | Comment-only template stubs with zero code; unreferenced anywhere in `lib/`. |
| `lib/features/dashboard/widgets/turnout_card.dart` | Unreferenced duplicate of the active `turnout_progress.dart`; imported by nothing. |
| `lib/features/auth/screens/splash_screen.dart` | Unreferenced screen (imported but never routed; `initialLocation` is `/login` and auth navigation is handled by `OmniVoteApp`). |
| `test/widget_test.dart` | Default Flutter template test ("App pumps test"). |
| `docs/*` (00–10) | Drifted duplicate of the canonical root `docs/` (which also holds `AUDIT.md`); contained obsolete mock credentials (`24-00001/tester1`). Deleted so root `docs/` is the single source of truth; `README.md` links repointed to `../docs/…`. |
| `.gitkeep` | Empty placeholder in a content-full directory. |

Also confirmed the previously-flagged hardcoded mock bypass (`studentId == '24-00001' && password == 'tester1'`)
in `lib/features/auth/providers/auth_provider.dart` is gone — login now always flows through
`AuthRepository → POST /api/auth/login`, and the auth surface was normalized to `email`/`password`.

#### 9.3.1 Reconciliation note (concurrent Sanctum refactor in `user-flutter`)

While this cleanup pass ran, a parallel Sanctum/phase refactor re-created several of the removed
stub paths as **real implementations** (models `ballot_model`, `candidacy_application_model`,
`election_result_model`, `vote_receipt_model`; repositories `vote_repository`, `result_repository`,
`candidacy_repository`; providers `ballot_provider`→`voting_provider`, `results_provider`,
`candidacy_provider`; screens/widgets `splash_screen`, `vote_now_screen`, `my_ballot_screen`,
`results_bar_chart`, etc.; plus new `lib/main.dart`, `election_status_model.dart`,
`election_status_provider.dart`, and `vote/candidacy/result/election_status` services). Those files
now carry **live code and are retained**; the git index was reconciled so no staged-deletion remains.

Two adjustments were made after reconciliation:

| Change | Reason |
|---|---|
| `lib/app.dart` — removed duplicate `main()` | `lib/main.dart` is now the canonical Flutter entrypoint (`flutter run`/`build`); `app.dart` retained only the root widget tree (`OmniVoteApp`). |
| lint fixes across `candidate_service.dart`, `eligibility_faq.dart`, `eligibility_faq_card.dart`, `candidates_list_screen.dart` | Replaced `if (x != null)` collection elements with null-aware `?` entries and dropped 3 unused imports left over from the refactor — `flutter analyze` is now clean. |

`lib/features/auth/screens/splash_screen.dart` was re-created with a real bootstrap flow but is still
**not referenced by `AppRouter`** (no `GoRoute`; `initialLocation` is `/login` and `OmniVoteApp`
handles auth redirects). It is retained for the active refactor's intent, but remains a candidate
for wiring or removal — see §9.7.

### 9.4 Removed — admin-react

| File | Reason |
|---|---|
| `public/icons.svg` | Unreferenced public asset (nothing imports/links it; only `favicon.svg` is used by `index.html`). |

The legacy localStorage auth scheme (App.jsx persisting `omnivote_user` and trusting it on reload)
was removed in favor of the Sanctum session-cookie flow: `src/lib/AuthContext.jsx` + `src/lib/api.js`
(CSRF-cookie handshake, `withCredentials`, 401/419/403 interceptors) now back `AdminLogin`,
`AdminDashboard`, and `Candidates`. The display-name cache in `src/lib/auth.js` is server-validated
on bootstrap and is **not** an auth boundary. The dev proxy in `vite.config.js` forwards `/api` and
`/sanctum` to `http://127.0.0.1:8000` so cookies stay same-origin.

### 9.5 Removed — root

| File | Reason |
|---|---|
| `android/` (19 tracked files) | Orphaned duplicate native-Android skeleton left from the pre-flatten layout; the active Flutter Android host lives at `user-flutter/android/`. |
| `.gitignore` | Added `**/build/` so nested Flutter build outputs (e.g. `user-flutter/build/`, previously the committed `user-flutter/OmniVote/build/`) can never be re-committed. |

### 9.6 Explicitly retained

- `database/data/users.json` + `UserSeeder.php` — active seed path for the two dashboard accounts.
- `2026_08_24_000002_create_registrar_imports_table.php` — still required by
  `StudentRegistrationRequest::rules()` (`Rule::exists('registrar_imports', …)`).
- In-UI mock fallbacks in `admin-react` (Candidates/Results/ElectionSetup/Settings) — the backend
  endpoints they simulate (`/api/admin/…`, results) are roadmap items, so the fallbacks are still the
  only data source; removing them would blank the screens.
- `.github/modernize/` — untracked + gitignored local tooling hooks, untouched.
- Re-created feature surfaces under `user-flutter/lib` that the concurrent refactor implemented with
  live code — see §9.3.1.

### 9.7 Follow-up (not removed, flagged)

| Item | Status | Recommendation |
|---|---|---|
| `user-flutter/lib/features/auth/screens/splash_screen.dart` | Real implementation, but **no GoRoute references it** and `initialLocation` is `/login`. | Wire it as the app bootstrap route (check auth → redirect) or delete before release. |
| `database/data/users.json` seeds admin/teacher, but `StudentAuthController` / `RegistrationController` require a `student` row — the student seed (`24-00001`) was only in the deleted mock docs. | No student account is seeded by `UserSeeder`. | Add a `role: student` seed in `users.json` (or a dedicated seeder) so the mobile login path is testable end-to-end. |
| `VoteLedger`/`phases` tables exist but no seeder populates `phases` (`Phase::current()` returns `null` → defaults to `registration`). | Phase gating currently falls back to a default. | Seed the three phases (`registration`, `voting_open`, `voting_closed`) and add an active-flag lookup. |

### 9.8 Validation performed

- `php artisan route:list` — backend boots; admin (stateful) and student (token) Sanctum routes, plus
  candidate/vote phase-gated routes and `sanctum/csrf-cookie`, all resolve. No dangling references to
  removed controllers.
- `php -l` — clean across routes, bootstrap, models, config, middleware, requests, migrations.
- `npm run build` (admin-react) — production bundle builds successfully (`.js` 303 kB / `.css` 28 kB).
- `flutter analyze` (user-flutter) — **No issues found** after lint cleanup.
---

## Final Verification Log (2026-09-05)

All flows below were executed live against the local stack
(`php artisan serve` @ 127.0.0.1:8000, MySQL `omnivote_local`, seeded via
`DatabaseSeeder` + a real `POST /api/admin/registrar/import` round-trip).

| # | Flow | Result |
|---|------|--------|
| 1 | Admin CSRF cookie → session login (`POST /api/admin/login`) | 200 |
| 2 | Admin dashboard / candidates / imports / config / results reads | 200 |
| 3 | Registrar CSV import (`STU-2024-0009` Nina Park) → `registrar_imports` + provisioned user + temp password | 201 |
| 4 | Imported student login with temp password → bearer token → `GET /api/auth/me` | 200 |
| 5 | Ballot draft save/load (`PUT`/`GET /api/ballot/me`) incl. second-voter regression | 200 |
| 6 | Anonymous vote submit (`POST /api/vote`) — 3 voters, HMAC-anonymized ledger, receipt returned | 201 |
| 7 | Double-cast rejection | 409 |
| 8 | Phase gates (`CheckPhase`): vote/draft blocked when `voting_closed`, allowed when `voting_open` | 403 / 200 |
| 9 | Results (`GET /api/results`) + receipt verification (`POST /api/results/verify`) counted true/false | 200 |
| 10 | Admin election config (`PUT /api/admin/election/config`): title + 4 consecutive phase transitions echo correctly; public `/api/election/status` mirrors | 200 |
| 11 | Admin logout | 204 |
| 12 | `flutter analyze` (user-flutter) | No issues |
| 13 | `npm run build` (admin-react) | Success |

### Defect found & fixed during verification

**Admin SPA CSRF split-brain (fixed).** The `api/admin` route group carried
`['ensureFrontendRequestsAreStateful', 'web']` *on top of* the global
`$middleware->statefulApi()` in `bootstrap/app.php`. Session middleware
therefore ran twice per request; the response's session cookie was written
from one session store while the `XSRF-TOKEN` cookie was minted from another.
Every admin write after the first returned **419 CSRF token mismatch**, and
each 419 minted a new token-less session (visible as NULL-user rows churning
in the `sessions` table). Fix: rely on `statefulApi()` alone — the admin group
is no longer wrapped (see comment block in `routes/api.php`). Re-verified with
4 consecutive `PUT /api/admin/election/config` calls + logout, all green.
