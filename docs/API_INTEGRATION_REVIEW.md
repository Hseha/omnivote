# API Integration Review — Cross-Repository Verification

**Date:** 2026-09-11
**Scope:** `backend-laravel` (Laravel 13 + Sanctum 4) · `admin-react` (React 19 + Vite + axios 1.20) · `user-flutter` (Flutter + Riverpod + Dio + flutter_secure_storage)
**Target API base:** `http://100.84.115.25:8000/api`
**Method:** Static source review across all three repos — `routes/api.php`, controllers, requests, middleware, models, migrations, config, frontend service/repository/provider/screen layers, and data models.

---

## 1. Base URL & Routes

### 1.1 Route Alignment — ✅ All Correct

Every endpoint path, HTTP method, and middleware gate in both frontends matches the route definitions in `backend-laravel/routes/api.php`.

#### Flutter → Backend Route Mapping

| Flutter Service / Constant | HTTP Method | Path | Backend Route | Middleware |
|---|---|---|---|---|
| `auth_service.dart` → `login()` | POST | `/auth/login` | `POST /api/auth/login` | none |
| `auth_service.dart` → `register()` | POST | `/auth/register` | `POST /api/auth/register` | `checkPhase:registration` |
| `auth_service.dart` → `logout()` | POST | `/auth/logout` | `POST /api/auth/logout` | `auth:sanctum` |
| `auth_service.dart` → `getMe()` | GET | `/auth/me` | `GET /api/auth/me` | `auth:sanctum` |
| `election_status_service.dart` | GET | `/election/status` | `GET /api/election/status` | none |
| `registration_service.dart` | GET | `/registration/me` | `GET /api/registration/me` | `auth:sanctum` |
| `candidate_service.dart` → `getPositions()` | GET | `/positions` | `GET /api/positions` | none |
| `candidate_service.dart` → `getCandidates()` | GET | `/candidates` | `GET /api/candidates` | none |
| `candidate_service.dart` → `getCandidate()` | GET | `/candidates/{id}` | `GET /api/candidates/{candidate}` | none |
| `candidacy_service.dart` → `getMyApplication()` | GET | `/candidacy/me` | `GET /api/candidacy/me` | `auth:sanctum` |
| `candidacy_service.dart` → `submit()` | POST | `/candidate/apply` | `POST /api/candidate/apply` | `auth:sanctum`, `checkPhase:registration` |
| `vote_service.dart` → `getMyBallot()` | GET | `/ballot/me` | `GET /api/ballot/me` | `auth:sanctum` |
| `vote_service.dart` → `saveDraft()` | PUT | `/ballot/me` | `PUT /api/ballot/me` | `auth:sanctum`, `checkPhase:voting_open` |
| `vote_service.dart` → `submitBallot()` | POST | `/ballot/me/submit` | `POST /api/ballot/me/submit` | `auth:sanctum`, `checkPhase:voting_open` |
| `vote_service.dart` → `submitVote()` | POST | `/vote` | `POST /api/vote` | `auth:sanctum`, `checkPhase:voting_open` |
| `vote_service.dart` → `verifyReceipt()` | POST | `/results/verify` | `POST /api/results/verify` | `auth:sanctum` |
| `result_service.dart` → `getResults()` | GET | `/results` | `GET /api/results` | `auth:sanctum`, `checkPhase:voting_closed` |

#### React Admin → Backend Route Mapping

| React Component | Axios Call | Backend Route | Middleware |
|---|---|---|---|
| `AdminLogin.jsx` | `POST /admin/login` | `POST /api/admin/login` | none (session via `Auth::login`) |
| `auth.js` → `getCurrentUser()` | `GET /admin/me` | `GET /api/admin/me` | `auth` (session) |
| `auth.js` → `logoutRequest()` | `POST /admin/logout` | `POST /api/admin/logout` | `auth` |
| `Admindashboard.jsx` | `GET /admin/dashboard-overview` | `GET /api/admin/dashboard-overview` | `auth` |
| `Candidates.jsx` | `GET /admin/candidates` | `GET /api/admin/candidates` | `auth` |
| `Candidates.jsx` | `PATCH /admin/candidates/{id}` | `PATCH /api/admin/candidates/{candidate}` | `auth` |
| `StudentRegistry.jsx` | `POST /admin/registrar/import` | `POST /api/admin/registrar/import` | `auth` |
| `StudentRegistry.jsx` (listed but not called in source) | `GET /admin/registrar/imports` | `GET /api/admin/registrar/imports` | `auth` |
| `ElectionSetup.jsx` | `GET /admin/election/config` | `GET /api/admin/election/config` | `auth` |
| `ElectionSetup.jsx` | `PUT /admin/election/config` | `PUT /api/admin/election/config` | `auth` |
| `Results.jsx` | `GET /admin/results` | `GET /api/admin/results` | `auth` |
| `StudentRegistry.jsx`, `Settings.jsx` | `GET /election/status` | `GET /api/election/status` | none |

### 1.2 Base URL Configuration

#### Flutter (`lib/core/constants/api_constants.dart`)

```dart
static const String baseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8000/api',
);
```

- **Status:** ⚠️ **WARNING**
- The hardcoded default `http://10.0.2.2:8000/api` points to the Android emulator's loopback, not the required `http://100.84.115.25:8000/api`.
- The `--dart-define=API_BASE_URL=...` mechanism exists (documented in `docs/08_BUILD_RELEASE.md`), but a production build run **without** the flag will silently fail to connect.
- **Recommendation:** Change the default to `http://100.84.115.25:8000/api` or ensure the build pipeline always passes the flag.

#### React Admin (`src/lib/api.js` + `vite.config.js`)

```js
// api.js
export const API_BASE_URL = '';  // ← empty; resolves to relative /api/*

// vite.config.js
server: {
  proxy: {
    '/api': { target: 'http://localhost:8000', ... },
    '/sanctum': { target: 'http://localhost:8000', ... },
  },
}
```

- **Status:** ⚠️ **WARNING**
- `API_BASE_URL = ''` means the React app uses relative URLs (`/api/...`), which only works if served from the same origin as the Laravel backend.
- There is **no `VITE_API_BASE_URL`** env variable wired through, so there is no way to override the base URL in production without modifying source code.
- The Vite dev proxy targets `localhost:8000`, not `100.84.115.25:8000`.
- **Recommendation:** Add `VITE_API_BASE_URL` env support to `api.js` and `vite.config.js`.

---

## 2. Authentication & Headers

### 2.1 Flutter — Sanctum Bearer Token

| Aspect | Implementation | Status |
|---|---|---|
| Token issuance | `StudentAuthController::login` returns `{ token, student }` with `$user->createToken('mobile')->plainTextToken` | ✅ |
| Token storage | `TokenStore` → `SecureStorageService` (native) / in-memory (web) | ✅ |
| Bearer header | `api_client.dart` Dio interceptor: `options.headers['Authorization'] = 'Bearer $token'` | ✅ |
| `Accept` header | `Accept: application/json` in `BaseOptions` | ✅ |
| `Content-Type` | `application/json` global default; overridden to `multipart/form-data` for candidacy photo upload | ✅ |
| Logout | `POST /auth/logout` (revokes token server-side) + `TokenStore.delete()` | ✅ |
| 401 handling | Interceptor sets `authEventProvider = AuthEvent.unauthorized`; `AuthNotifier.handleUnauthorized()` triggers logout. Login/register paths excluded from auto-logout. | ✅ |
| `/auth/me` | `AuthRepository.getAuthenticatedUser()` extracts `data['user']` from `{ user: {...} }` | ✅ |
| Login response | `{ token, student }` → Repository reads `data['token']` and `data['student']` | ✅ |

### 2.2 React Admin — Sanctum Stateful Sessions

| Aspect | Implementation | Status |
|---|---|---|
| CSRF handshake | `getCsrfCookie()` → `GET /sanctum/csrf-cookie` before mutating requests | ✅ |
| Session auth | `AdminAuthController::login` → `Auth::login($user)` + `$request->session()->regenerate()` | ✅ |
| `withCredentials` | `withCredentials: true` on axios instance | ✅ |
| `Accept` header | `Accept: application/json` | ✅ |
| `X-Requested-With` | `XMLHttpRequest` | ✅ |
| CSRF header | `xsrfCookieName: 'XSRF-TOKEN'`, `xsrfHeaderName: 'X-XSRF-TOKEN'` — axios auto-reads cookie | ✅ |
| 401/419/403 handling | Response interceptor resets CSRF cache on 401/419, calls `onUnauthorizedHandler()` | ✅ |
| Double middleware (fixed) | `bootstrap/app.php` `$middleware->statefulApi()` applies `EnsureFrontendRequestsAreStateful` globally; admin route group is NOT additionally wrapped (per `routes/api.php` comment) | ✅ |

### 2.3 Admin Token Redundancy

`AdminAuthController::login` issues a Sanctum personal access token via `$user->createToken('admin-session')` and returns it in the `{ user, token }` response. The React client **does not use** this token — it relies solely on the session cookie. The `token` field is returned but ignored by `auth.js` (which only reads `data.user`).

- **Status:** ℹ️ Informational — not a bug, but misleading dead output.

### 2.4 Sanctum Stateful Domains — ⚠️ Production IP Not Configured

`config/sanctum.php` builds the `stateful` array from:

```php
'localhost,localhost:3000,localhost:5173,127.0.0.1,127.0.0.1:8000,::1'
+ Sanctum::currentApplicationUrlWithPort()  // derived from APP_URL
```

With `APP_URL=http://localhost:8000` (current `.env`), the stateful domains include `localhost:8000` but **NOT** `100.84.115.25:8000`.

- **Impact:** When the admin browser accesses the app at `http://100.84.115.25:8000`, Sanctum's `EnsureFrontendRequestsAreStateful` will not recognize the origin as stateful. Session/CSRF middleware will not run, and all `auth` (session) routes will return 401.
- **Fix:** Set `APP_URL=http://100.84.115.25:8000` in the production `.env`.

---

## 3. CORS Configuration

### 3.1 Current `config/cors.php`

```php
'paths' => ['api/*', 'sanctum/csrf-cookie', 'sanctum/*'],
'allowed_methods' => ['*'],
'allowed_origins' => array_filter([
    'http://localhost:5173',
    env('APP_URL'),         // = http://localhost:8000 (current .env)
    env('FRONTEND_URL'),    // = null (unset, filtered out by array_filter)
]),
'allowed_headers' => ['Content-Type', 'X-XSRF-TOKEN', 'Authorization', 'Accept', 'Origin', 'X-Requested-With'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

### 3.2 Origin Allowlist Analysis

| Origin | Allowed? | Context |
|---|---|---|
| `http://localhost:5173` | ✅ | Vite dev server |
| `http://localhost:8000` | ✅ | API server itself (degenerate — not a frontend origin) |
| `http://100.84.115.25:8000` | ❌ | **NOT allowed** with current `.env` |
| `null` (unset `FRONTEND_URL`) | filtered out | `array_filter` removes null entries |

- **Status:** ⚠️ **WARNING**
- For production at `http://100.84.115.25:8000`, CORS will block the admin SPA's browser requests unless `APP_URL` is changed to the production URL.
- The `FRONTEND_URL` env var exists as an escape hatch for serving the admin panel from a different origin, but it's currently unset.

### 3.3 All Other CORS Settings

| Setting | Value | Status |
|---|---|---|
| `paths` | `api/*`, `sanctum/csrf-cookie`, `sanctum/*` | ✅ |
| `allowed_methods` | `*` | ✅ |
| `allowed_headers` | `Content-Type`, `X-XSRF-TOKEN`, `Authorization`, `Accept`, `Origin`, `X-Requested-With` | ✅ All headers used by clients |
| `supports_credentials` | `true` | ✅ Required for cookie-based admin auth |
| `exposed_headers` | `[]` | ✅ Sufficient |

### 3.4 Flutter — Not Affected

CORS does not apply to the Flutter mobile app (native HTTP client, not browser-based). ✅

---

## 4. Data Contract Alignment

### 4.1 Authentication

#### Login

| Client | Payload | Backend Validation | Match |
|---|---|---|---|
| Flutter `AuthService.login()` | `{ email, password }` | `email` (required, email), `password` (required) | ✅ |
| React `AdminLogin.jsx` | `{ email, password }` | Same + `role ∈ {admin, teacher}` check (403 otherwise) | ✅ |

#### Register (Flutter only)

| Field | Flutter (`register_screen.dart`) | Backend (`StudentRegistrationRequest`) | Match |
|---|---|---|---|
| `student_id` | Text input | `required`, `string`, `exists:registrar_imports,student_id`, `unique:users,student_id` | ✅ |
| `name` | Text input | `required`, `string`, `max:255` | ✅ |
| `email` | Text input | `required`, `email`, `max:255`, `unique:users,email` | ✅ |
| `password` | Text input | `required`, `confirmed`, `min:8`, `regex:/[a-z]/`, `regex:/[A-Z]/`, `regex:/[0-9]/` | ✅ Flutter UI enforces same rules client-side |
| `password_confirmation` | Text input | Validated by `confirmed` rule | ✅ |

#### Login Response Parsing

| Client | Reads | Backend Returns | Match |
|---|---|---|---|
| Flutter `AuthRepository.login()` | `data['token']`, `data['student']` | `{ token, student }` | ✅ |
| Flutter `AuthRepository.getAuthenticatedUser()` | `data['user']` (tolerates bare object) | `{ user }` | ✅ |
| React `auth.js` → `login()` | `data.user` | `{ user, token }` | ✅ (token ignored, session cookie used) |
| React `auth.js` → `getCurrentUser()` | `data.user` | `{ user }` | ✅ |

### 4.2 Candidate Application (multipart)

| Field | Flutter (`CandidacyService.submit()`) | Backend (`CandidateApplicationRequest`) | Match |
|---|---|---|---|
| `position_id` | `positionId` (string from dropdown) | `required`, `integer`, `exists:positions,id` | ✅ (Dio converts string→int in form data) |
| `slogan` | Optional text field | `nullable`, `string`, `max:255` | ✅ |
| `party_name` | Optional text field | `nullable`, `string`, `max:255` | ✅ |
| `platform_statement` | Required text field | `required`, `string`, `max:5000` | ✅ |
| `photo` | `MultipartFile.fromBytes` (optional) | `nullable`, `image`, `mimes:jpg,jpeg,png`, `max:5120` | ✅ |
| `certify` | `true` (hardcoded in form data) | `required`, `accepted` | ✅ |

### 4.3 Ballot & Vote Submission

| Client Send | Backend Validation | Match |
|---|---|---|
| `{ selections: { position_slug: candidate_ref \| [refs] } }` | `selections` required, array, min:1; `resolveSelections()` validates each ref belongs to an approved candidate in the correct position; checks `seat_count` per position | ✅ |

- The `Ballot.toSubmitPayload()` method correctly serializes multi-select positions as arrays and single-select positions as scalar strings.
- The `VoteController::resolveSelections()` validates that every `candidate_ref` belongs to an approved candidate in the correct position and respects `seat_count` limits. ✅

### 4.4 Results & Receipt Verification

| Client | Payload | Backend | Response | Match |
|---|---|---|---|---|
| `GET /results` | — | `ResultsController::index` | `{ results: [{ position_key, position_label, candidates: [{ name, position_key, votes, candidate_ref }] }] }` | ✅ Flutter `ElectionResult` + `ElectionCandidateResult` models map all fields |
| `POST /results/verify` | `{ receipt_token }` | `ResultsController::verify` → HMAC lookup in `vote_ledger.receipt_hmac` | `{ counted: bool }` | ✅ Flutter `vote_repository.verify()` reads `data['counted']` |

### 4.5 Election Status & Registration

| Endpoint | Backend Response Shape | Flutter Model | Match |
|---|---|---|---|
| `GET /election/status` | `{ phase, phase_label, server_time, voting_opens_at, voting_closes_at, registration_open }` | `ElectionStatus` reads `phase`, `phase_label`, `voting_opens_at`, `voting_closes_at` | ✅ Extra fields (`server_time`, `registration_open`) harmlessly ignored |
| `GET /registration/me` | `{ registration_date, eligibility_status, turnout: { registered_students, total_students, actual_ballots_cast } }` | `Registration` + `Turnout` read all fields exactly | ✅ |

#### Phase Name Mapping

| Backend `phase` value | Flutter `ElectionPhase` enum | Match |
|---|---|---|
| `registration` | `ElectionPhase.registration` | ✅ |
| `voting_open` | `ElectionPhase.votingOpen` | ✅ |
| `voting_closed` | `ElectionPhase.votingClosed` | ✅ |

### 4.6 Candidacy Status

| Endpoint | Backend Response | Flutter Handling | Match |
|---|---|---|---|
| `GET /candidacy/me` | `{ status: 'none' }` or `{ status, candidate: { id, position_id, slogan, party_name, platform_statement, photo_path, approval_status, created_at } }` | `CandidacyRepository.getApplicationStatus()` reads `data['status']` or `data['approval_status']` | ✅ |

### 4.7 Admin Dashboard & Candidates

| Endpoint | Backend Response | React Usage | Match |
|---|---|---|---|
| `GET /admin/dashboard-overview` | `{ stats: { total_voters, votes_cast, turnout_rate, approved_candidates }, election_phase, announcements, recent_actions, user: { name, role } }` | `Admindashboard.jsx` reads `data.stats`, `data.election_phase`, `data.user` | ✅ |
| `GET /admin/candidates` | `{ data: [{ id, name, email, student_id, position, position_id, position_slug, party, slogan, platform_statement, status, submissionDate, avatar, meta }] }` | `Candidates.jsx` reads `res.data?.data`, maps `candidate.name`, `.status`, `.party`, `.submissionDate`, `.avatar` | ✅ |
| `PATCH /admin/candidates/{id}` | Updates `approval_status` | Sends `{ status: lowercase }` | ✅ React normalizes to lowercase before sending |
| `GET /admin/election/config` | `{ config: { title, phase, registration_opens_at, voting_closes_at, positions: [{ id, slug, title, label, tier, seat_count, active, candidates }] } }` | `ElectionSetup.jsx` reads `config.title`, `config.phase`, `config.positions`, `p.tier`, `p.slug`, `p.active`, `p.title`, `p.candidates` | ✅ |
| `PUT /admin/election/config` | `ElectionConfigRequest` validates: `title` (nullable string), `phase` (in:registration,voting_open,voting_closed), `registration_opens_at` (nullable date), `voting_opens_at` (nullable date), `voting_closes_at` (nullable date, after_or_equal:registration_opens_at), `positions` (nullable array, each with `slug`, `seat_count`, `active`) | Sends `{ title, phase, registration_opens_at, voting_closes_at, positions: [{ slug, active }] }` | ✅ Backend maps `registration_opens_at` → `voting_opens_at` setting; `seat_count` is nullable in validation |
| `POST /admin/registrar/import` | N/A (import) | `FormData` with `file` field | `RegistrarImportRequest` validates `file` (required, file, mimes:csv,txt, max:10240) | ✅ |

### 4.8 Database Schema Alignment

The database schema (14 migrations) is consistent with the API contracts and frontend expectations:

| Table | Key Columns | Consumed By | Alignment |
|---|---|---|---|
| `users` | `student_id` (unique), `role` enum, `has_voted` (bool), `voted_at`, `year_level`, `block_number` | Login (student_id), Dashboard stats (has_voted, role) | ✅ |
| `positions` | `slug` (unique), `label`, `tier` enum(school,provincial), `seat_count`, `is_active` | Ballot `position_key` = `positions.slug`; vote validation | ✅ |
| `candidates` | `user_id` FK, `position_id` FK, `candidate_ref` (unique UUID), `approval_status` enum, `slogan`, `party_name`, `photo_path`, `platform_points` (JSON) | Candidate listing, ballot refs, vote validation | ✅ |
| `phases` | `name` (unique), `is_active` (bool) | `Phase::current()` for `CheckPhase` middleware | ✅ |
| `vote_ledger` | `position_key`, `candidate_ref`, `receipt_hmac`, `ledger_sequence` | Anonymous tally; receipt verification | ✅ |
| `ballot_drafts` | `user_id` (unique), `selections` (JSON), `status`, `receipt_token`, `submitted_at` | `GET/PUT /ballot/me` | ✅ |
| `registrar_imports` | `student_id` (unique), `full_name`, `email`, `grade_level`, `year_level`, `block_number` | `StudentRegistrationRequest` validates `student_id` exists here | ✅ |
| `personal_access_tokens` | Standard Sanctum table | Bearer token storage | ✅ |
| `election_settings` | `key` (unique), `value` (text) | `ElectionController::settings()` for title/dates | ✅ |

---

## Additional Findings

### A. ⚠️ No Seeded Student Account

The `UserSeeder` (`database/data/users.json`) only seeds two accounts:
- `admin@omnivote.test` / `admin123` (role: admin)
- `teacher@omnivote.test` / `teacher123` (role: teacher)

There is **no student account** seeded. The `StudentRegistrationRequest` requires `student_id` to already exist in the `registrar_imports` table (`Rule::exists('registrar_imports', 'student_id')`), so self-registration only works after a CSV import has been performed. The mobile login flow (`POST /api/auth/login`) has no `role: student` user to authenticate against out of the box.

- **Recommendation:** Add a student seed entry to `users.json` or create a dedicated seeder.

### B. ⚠️ Flutter Base URL Default Mismatch

The default in `api_constants.dart` is `http://10.0.2.2:8000/api` (Android emulator loopback). This does **not** match the required production URL `http://100.84.115.25:8000/api`. If a production APK is built without `--dart-define=API_BASE_URL=...`, the app will silently fail to connect.

- **Recommendation:** Change the default to `http://100.84.115.25:8000/api`.

### C. ⚠️ React Admin `API_BASE_URL` Not Environment-Configurable

`API_BASE_URL = ''` is a hardcoded constant in `src/lib/api.js`. There is no Vite env variable (e.g., `import.meta.env.VITE_API_BASE_URL`) wired through. In production, if the admin panel needs to be served from a different origin than the API, there is no configuration path.

- **Recommendation:**
  - In `api.js`: `export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';`
  - In `vite.config.js`: update the proxy target to use the env variable.
    - Add `VITE_API_BASE_URL=` to a `.env` file in `admin-react/`.

### D. ℹ️ Docs Discrepancies (Not Code Bugs)

| Doc File | Claim | Actual Backend | Impact |
|---|---|---|---|
| `docs/05_API_INTEGRATION.md` | Phase values: `not_registered`, `voting_open`, `voting_closed`, `results_published` | Phases: `registration`, `voting_open`, `voting_closed` | Docs outdated; Flutter code correctly handles actual phases |
| `docs/05_API_INTEGRATION.md` | `POST /api/candidacy` (multipart) | `POST /api/candidate/apply` | Docs outdated; Flutter code uses correct path |
| `docs/03_APP_FLOW.md` | References `not_registered` phase | Does not exist | Docs outdated |
| `docs/05_API_INTEGRATION.md` | `POST /api/ballot/me/submit` returns `{ receiptToken }` | Returns `{ receipt }` | Docs outdated; Flutter `VoteReceipt.fromJson` handles `receipt` as fallback key |
| `docs/05_API_INTEGRATION.md` | `GET /api/election/status` returns `votingWindow: { opensAt, closesAt }` | Returns `voting_opens_at`, `voting_closes_at` as top-level keys | Docs outdated; Flutter reads correct keys |
| `docs/05_API_INTEGRATION.md` | `GET /api/ballot/me` returns `selections: { [positionId]: [candidateId, ...] }` | Returns `selections: { position_slug: ref \| [refs] }` | Docs outdated; Flutter `Ballot` model handles the actual shape |

### E. ℹ️ Unimplemented Admin UI Features

- `Settings.jsx`: Uses a hardcoded user list with no API integration. No user management endpoints exist on the backend.
- `Results.jsx`: Fetches `/admin/results` but renders primarily from hardcoded `liveSummaryData` mock data. The API response is stored in state (`resultsData`) but the primary rendering uses mock data.

### F. ℹ️ Dead / Unused Code

| Location | Issue |
|---|---|
| `vote_service.dart` → `submitVote()` | Calls `POST /vote` (`ApiConstants.voteSubmit`) but is never used. `VoteRepository.submit()` calls `submitBallot()` (POST `/ballot/me/submit`) instead. Both hit `VoteController::submit` via `BallotController::submit`, so no contract issue — just dead code. |
| `api_client.dart` → `ApiValidationException` | Class is defined but never thrown or caught anywhere in the codebase. |
| `AdminCandidateController::export()` | Method exists but is not registered as a route in `api.php`. Returns HTTP 501. |
| `splash_screen.dart` | Has real auth-check logic but is not referenced by any `GoRoute` in `AppRouter`. App starts at `/login` with auth redirects handled by `OmniVoteApp`. |

### G. ℹ️ Admin Candidate `meta` Pagination Shape

`AdminCandidateController::index` embeds pagination metadata (`total`, `per_page`, `current_page`) inside **each candidate item** as a `meta` sub-object, rather than at the top level as Laravel's standard paginator does. The React `Candidates.jsx` does not consume this `meta` (it shows a static "Showing 1-8 of 24 applicants" string). This is non-standard but not a functional breaking issue.

---

## Summary: Critical Issues Requiring Action

| # | Issue | Severity | Fix |
|---|---|---|---|
| 1 | `APP_URL=http://localhost:8000` in production `.env` breaks Sanctum stateful domains + CORS for `100.84.115.25:8000` | 🔴 Critical | Set `APP_URL=http://100.84.115.25:8000` in production `.env` |
| 2 | Flutter default base URL is `http://10.0.2.2:8000/api`, not the production URL | 🟠 High | **FIXED** — default changed to `http://100.84.115.25:8000/api` in `api_constants.dart` |
| 3 | No seeded student account → mobile login untested end-to-end | 🟡 Medium | Add a `role: student` seed to `users.json` or a seeder |
| 4 | React `API_BASE_URL` not env-configurable for cross-origin production | 🟡 Medium | **FIXED** — now reads `import.meta.env.VITE_API_BASE_URL` in `api.js`; `.env.example` added |
| 5 | Outdated docs (`05_API_INTEGRATION.md`, `03_APP_FLOW.md`) reference non-existent phases/endpoints | ℹ️ Info | Update docs to match actual contracts |

### Verified Clean (No Issues)

- ✅ All 29 endpoint paths, methods, and middleware gates align between frontends and backend
- ✅ Sanctum bearer token flow (Flutter) — login/logout/me, secure storage, 401 handling
- ✅ Sanctum stateful session + CSRF flow (React admin) — cookie handshake, interceptors, token rotation
- ✅ All request/response field contracts match (login, register, candidacy, ballot, vote, results, election status, candidacy status)
- ✅ Database schema (14 migrations) fully aligned with API contracts
- ✅ CORS paths, methods, headers, and credentials settings all correct for the configured origins
- ✅ Phase gating (`CheckPhase` middleware) enforced on all phase-sensitive routes
- ✅ Backend boots cleanly, `php artisan route:list` resolves all routes without dangling references (per AUDIT.md §9.8)
