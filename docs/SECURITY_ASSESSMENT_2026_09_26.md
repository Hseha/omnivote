# OmniVote — Security Assessment (API, Admin Dashboards & Weak Points)

**Date:** 2026-09-26
**Target:** `omnivote` monorepo — Laravel 13.26.1 API (`backend-laravel/`), React admin SPA (`admin-react/`), Flutter client (`user-flutter/`), nginx/PHP-FPM deployment (`deploy/`)
**Test type:** Authorised white-box assessment (source review + live testing against a **local** dev instance on `127.0.0.1:8123`, MySQL `omnivote_local`)
**Branch/commit:** `feature/admin-panel-refactor` @ `52c1ce9`
**Verdict:** 🔴 **2 blockers before any live election** (1 critical + 1 high), plus 5 medium and 10 low/hardening items.

> **Authorisation.** The app, database and host tested here are the operator's own local development
> environment; no third-party system, no production host (`debian.tail7e9e1e.ts.net`) and no other
> person's data was touched. Exploit code ran only against `127.0.0.1`, and every artefact of it was
> rolled back (see [§7 Cleanup & residue disclosure](#7-cleanup--residue-disclosure)).

---

## 1. Executive summary

> **Status: remediated.** Every finding in §4 has been implemented and covered by regression tests
> (185 tests / 832 assertions). One item is only partially closed (**L-2**, a deliberate proxy-trust
> trade-off awaiting a recorded decision) and four operational items are outstanding — see
> §6 *Still open*. See the **Status** column in
> [Findings at a glance](#findings-at-a-glance) and §6. The prose below is preserved as written at
> assessment time — it describes the system *as found*, not as it now stands.

The authentication *plumbing* is genuinely well built — Sanctum split correctly between a stateful
admin cookie and student bearer tokens, CSRF verified, CORS locked to an allow-list, role and
permission middleware on nearly every route, ownership checks on announcements, CSV-formula-injection
neutralised on export, backup filenames regex-constrained, and both dependency trees
(`composer audit`, `npm audit`) clean.

What fails is the part that matters most for an election: **the binding between a human, a credential
and a ballot.**

* Any student can be impersonated using **public data**: the registrar import sets the initial
  password to the **student ID** (`2024-01101`), and the login handle is derived from the student's
  **name** (`jasmine.santos`). 87 of 88 student accounts in this database still carry that password.
* Nothing on the server enforces `must_change_password`; the "forced rotation" screen exists only in
  the Flutter client. Attacker tooling skips it.
* An attacker needs only 5 HTTP requests per victim to **lock every voter out** for 15 minutes, and
  students have no self-service recovery — each needs a manual admin unlock.
* The "anonymous" vote ledger is **pseudonymous**: proven live, the receipt token stored next to
  `user_id` HMACs to the exact ledger row holding that voter's choices.

Together, the first three mean one attacker with a class list can cast ballots *for* real students
and simultaneously deny those students the ability to vote.

### Findings at a glance

| ID | Severity | Finding | Where | Status |
|---|---|---|---|---|
| **C-1** | 🔴 Critical | Student login uses the **public student ID** as the password and `must_change_password` is never enforced server-side → account takeover + ballot casting | `RegistrarImportController.php:272`, `StudentAuthController.php`, `routes/api.php` | **Fixed** — random temp creds + `EnsurePasswordChanged` gate. ⚠️ 87 accounts still un-rotated |
| **H-1** | 🟠 High | Vote ledger is **pseudonymous, not anonymous** — receipt token ↔ `user_id` ↔ ballot rows are trivially joinable | `VoteController.php:77-87`, `ballot_drafts` schema | **Fixed** — no voter reference in ledger; receipt is an HMAC of the anonymised tally |
| **M-1** | 🟡 Medium | Array query params crash public endpoints → **HTTP 500 + full stack trace / source path** disclosure (`APP_DEBUG=true`) | `CandidateController.php:34-39` | **Fixed** — query params validated as scalars (`422`, not `500`) |
| **M-2** | 🟡 Medium | **No rate limiting on any route** except login/registration; `/admin/2fa/prepare` is an unthrottled password oracle | `routes/api.php`, `AdminTwoFactorController.php:41` | **Fixed** — `api-public` / `sensitive` / `password-reset` / `receipt-verify` limiters; `/admin/2fa/prepare` throttled |
| **M-3** | 🟡 Medium | **Voter lockout DoS**: 5 guesses on a guessable handle locks the voter out; no student self-service unlock | `User.php:80`, `StudentAuthController.php` | **Fixed** — exponential backoff (cap 60m) + registrar-code recovery |
| **M-4** | 🟡 Medium | **Ballot scoping not enforced server-side** (year-level / provincial seats open to every voter) | `VoteController::resolveSelections` | **Fixed** — `positions.scope_type`/`scope_value`, enforced at vote write time |
| **M-5** | 🟡 Medium | Self-registration can **pre-claim a registrar identity** (student ID + attacker's own email) | `RegistrationController.php` | **Fixed** — hashed single-use activation codes; self-registration off by default |
| L-1 | 🟢 Low | Host header unvalidated → `asset()` URLs follow `Host:` (proven: `http://evil.example/...`) | `CandidateController::payload` | **Fixed** — `trustHosts` allow-list in `bootstrap/app.php` |
| L-2 | 🟢 Low | Per-IP login limiter trusts `X-Forwarded-For` from loopback (proven bypass 20/20) | `bootstrap/app.php` `trustProxies` | **Partial** — see §6.8: proxy trust decision still undocumented |
| L-3 | 🟢 Low | `/api/registration/me` leaks live election-wide turnout to every student | `ElectionController.php:57-61` | **Fixed** — election-wide turnout is `null` while polls are open |
| L-4 | 🟢 Low | Unbounded inputs: announcement body, ballot draft, settings keys/values, `per_page`, `receipt_token` | multiple | **Fixed** — caps on announcement/draft/settings/receipt; settings key allow-list |
| L-5 | 🟢 Low | Registrar listing `search` filter not grouped (`orWhere` escapes the filter) | `RegistrarImportController.php:490-500` | **Fixed** — `orWhere` group wrapped; `per_page` clamped to 1–100 |
| L-6 | 🟢 Low | `X-Powered-By: PHP/8.5.10` not stripped; no app-level security headers | php.ini / nginx | **Fixed** — `SecurityHeaders` middleware (`header_remove('X-Powered-By')` + nosniff/XFO/CSP/HSTS) and nginx `server_tokens off` |
| L-7 | 🟢 Low | Dev `.env` (real DB password, `APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false`) must never reach prod; prod misconfig fails open | `.env`, `.env.example` | **Fixed** — `security:assert-production-config` runs as a deploy preflight |
| L-8 | 🟢 Low | Teacher role holds `results.finalize` + `candidates.review` (least-privilege review) | `config/permissions.php` | **Fixed** — `results.finalize` removed from `teacher`; `candidates.review` kept deliberately (§6.10) |
| L-9 | 🟢 Low | Admin password-reset unthrottled per IP; `RESET_THROTTLED` reveals account existence | `AdminAuthController.php:416-442` | **Fixed** — generic reset response + `throttle:password-reset` |
| L-10 | 🟢 Low | Sanctum stateful domains / debug surfaces need an explicit production allow-list | `config/sanctum.php` | **Fixed** — explicit `SANCTUM_STATEFUL_DOMAINS`; `currentRequestHost()` removed |

---

## 2. opencode status

**Working.** Verified end-to-end, not just "installed":

| Check | Command | Result |
|---|---|---|
| Binary present | `which opencode` | `/home/Michael/.opencode/bin/opencode` |
| Version | `opencode --version` | `1.18.32` |
| CLI surface | `opencode --help` | Full command set reached: `run`, `serve`, `web`, `attach`, `agent`, `mcp`, `models`, `session`, `github`, `pr`, `db`, `stats`, `export/import`, `upgrade` |
| Providers | `opencode models` | Provider reachable, 8 models: `opencode/big-pickle`, `ling-3.0-flash-fin-free`, `longcat-2.5-preview-free`, `mimo-v2.6-flash-free`, `muse-spark-1.3-contributor-free`, `nemotron-3-ultra-free`, `nemotron-3.5-lightning-free`, `space-bunny-free` |
| **Live run** | `opencode run 'Reply with exactly: OPENCODE_OK'` | Returned `OPENCODE_OK`, exit code `0`, agent `build`, model `big-pickle` → **end-to-end inference works** |
| Config | `~/.config/opencode/opencode.jsonc` | Loads cleanly (minimal: `$schema` only). Also probes `config.json`, `opencode.json`, `~/.opencode/*` — no errors |
| Runtime log | `~/.local/share/opencode/log/opencode.log` | `INFO` only; no `ERROR`/`WARN`. Bootstraps instances, creates sessions, connects the event stream |
| Secrets | `~/.config/opencode/auth.json` | **Absent** (credentials held via `opencode providers` / OS store, not a plaintext file — good) |

**Observations (not failures).** LSPs and formatters are both reported disabled
(`all LSPs are disabled`, `all formatters are disabled`) — if you expected diagnostics/auto-format
inside opencode sessions, that is why. Also the session store
`~/.local/share/opencode/opencode.db` has grown to **452 MB** (+ 6.5 MB WAL); worth periodic
`opencode session` pruning / `opencode db` housekeeping.

---

## 3. Tooling — what was used, on which part of the system

| # | Tool | Version / form | Applied to (part of system) | What it produced |
|---|---|---|---|---|
| 1 | `read_files` | Cline built-in | `backend-laravel/routes/api.php`, all `app/Http/Controllers/*`, `app/Http/Middleware/*`, `app/Support/*`, `app/Models/User.php`, `config/{auth,cors,sanctum,permissions,app}.php`, `bootstrap/app.php`, `admin-react/src/lib/*`, `deploy/nginx-omnivote.conf` | Full route/auth/permission map; located the enforcement gaps |
| 2 | `search_codebase` (regex) | Cline built-in | whole repository | Traced `whereRaw`/`DB::raw`, `$fillable`, `throttle`, `must_change_password`, `dangerouslySetInnerHTML`, `localStorage`, `per_page` |
| 3 | `run_commands` + `grep`/`find`/`ls`/`wc` | GNU coreutils | repo tree, `docs/`, `deploy/` | Inventory (Laravel 13.26.1 / PHP 8.5.10 / React SPA / Flutter); confirmed **no `throttle` middleware on any route** and **no `must_change_password` middleware** |
| 4 | `git` | `git ls-files`, `git log`, `git status` | repository + `backend-laravel/.gitignore` | Confirmed `.env` is **not** committed (only `.env.example` in both apps); no keystores/keys tracked |
| 5 | `php artisan serve` | Laravel 13 `serve` | **API application** — local instance `127.0.0.1:8123` | The live attack surface behind every runtime finding |
| 6 | `php artisan --version`, `php -v` | CLI | backend runtime | Laravel 13.26.1, PHP 8.5.10 (NTS) |
| 7 | `curl` | 8.x | **API endpoints** (public, student-token, admin-session) | Authz matrix, CSRF/CORS behaviour, rate-limit behaviour, parameter fuzzing, header/verb checks |
| 8 | MySQL via `PDO` (`php -r`, `/tmp/ov_poc.php`, `/tmp/ov_cleanup.php`) | PHP 8.5 | **database** `omnivote_local` (schema + rows) | Snapshot/verify/rollback, HMAC join proof, final state integrity checks |
| 9 | `/tmp/ov_poc.php` (written for this test) | PHP | **API + DB** end-to-end | Reversible exploit chain: snapshot → open window → credential attack → cast ballot → de-anonymise → rollback |
| 10 | `composer audit` | Composer 2 | `backend-laravel/composer.lock` | **No security vulnerability advisories found** |
| 11 | `npm audit --omit=dev` | npm | `admin-react/package-lock.json` | **0 vulnerabilities** |
| 12 | `opencode` CLI | 1.18.32 | local CLI (see §2) | `--help`, `models`, live `run` smoke test, log review |
| 13 | Static review of `deploy/nginx-omnivote.conf` + systemd units | read-only | reverse-proxy / service layer | TLS, HSTS, CSP, XFO/nosniff, dotfile-deny verification (reviewed, not executed — no prod privileges used) |

**Not used / out of scope:** no external scanners (ZAP/Burp/nuclei) were installed; no brute-force or
load-testing tooling was pointed at anything; the production Tailscale host was never contacted.

---

## 4. Findings in detail

### C-1 🔴 Student accounts are protected only by public data; "must change password" is client-side only

**Impact:** complete impersonation of any student, including casting their ballot — the
election-integrity blocker.

**Root causes (three, compounding):**

1. `backend-laravel/app/Http/Controllers/RegistrarImportController.php:272` provisions accounts with
   the **student ID as the password**:
   ```php
   $tempPassword = $row['student_id'];
   User::create([... 'password' => Hash::make($tempPassword), 'must_change_password' => true, ...]);
   ```
   Student IDs are printed on ID cards and class lists, and are not secret. (The method's own
   docblock and the migration comment claim the temporary password is random — the code does not
   match the documentation.)
2. The login **handle is derived from the student's name**
   (`RegistrarImportController::usernameFromName`: `John Michael Valles → john.michael.valles`), so
   both halves of the credential pair are derivable from the class list.
3. `must_change_password` is enforced **only in the Flutter client**
   (`user-flutter/lib/features/auth/screens/change_password_screen.dart`). A grep across
   `app/Http/Middleware/` and `bootstrap/` finds **no server-side gate**, so the token minted by
   `POST /api/auth/login` is fully privileged immediately.

**Proof (executed against the local instance, then rolled back — see §7):**

```
POST /api/auth/login  {"email":"jasmine.santos","password":"2024-01101"}
  → 200 {"token":"111|ELvwM6RO...","must_change_password":true}
GET  /api/auth/me        → 200  (full profile incl. student_id, department, course)
GET  /api/candidacy/me   → 200
POST /api/vote {"selections":{"president":"bc22c31b-…"}}   (voting window open)
  → 201 {"receipt":"528d62f2651e907669f4f13c5809abb4"}     ← ballot cast as the victim
```

Database at test time: **87 of 88** students had `must_change_password = 1`.

**Remediation (all four, in this order):**
1. Generate a **random** temporary credential per provisioned account (CSPRNG); never a student ID,
   never a name.
2. Add a server-side `EnsurePasswordChanged` middleware on the student route group: everything except
   `auth/me`, `auth/logout`, `auth/password/change` (and 2FA status) returns `403`/`428` while
   `must_change_password` is true.
3. Stop deriving login handles from names for guessable identities, and/or require a second factor
   for voting (registrar-issued activation code, device binding).
4. Rotate the credentials of every existing account with `must_change_password = 1`.

---

### H-1 🟠 The vote ledger is pseudonymous, not anonymous (ballot secrecy breakable)

**Impact:** every ballot can be attributed to a named voter by anyone holding the database plus
`APP_KEY` — i.e. the election authority itself, a DB/backup leak, or any host-level compromise.

**Root cause.** `VoteController::submit()` writes the ballot to `vote_ledger` with
`receipt_hmac = hash_hmac('sha256', $receipt, config('app.key'))`, and *also* stores that same
plaintext `$receipt` on `ballot_drafts` — the row keyed by `user_id` that additionally keeps the
**`selections`**:

```php
VoteLedger::create(['position_key' => …, 'candidate_ref' => $ref, 'receipt_hmac' => $receiptHmac, …]);
BallotDraft::updateOrCreate(['user_id' => $voter->id], [
    'selections' => …, 'status' => 'submitted', 'receipt_token' => $receipt, 'submitted_at' => now(),
]);
```

So `ballot_drafts(user_id, receipt_token, selections)` + `vote_ledger(receipt_hmac, position_key,
candidate_ref)` = a two-hop join between a person and their choices. The docblock's claim that
choices "are written only to the decoupled `vote_ledger` (no user FK)" holds for the ledger row but is
undone by the draft row.

**Proof (live, rolled back):** the receipt returned by the vote above was `528d62f2…`; computing
`HMAC_SHA256(receipt_token, APP_KEY)` produced
`273b30c53a6c268bb95e29e253b8846d8e86af75c387154f8eaa165f2d1543b9`, which matched exactly one
`vote_ledger` row: `{"position_key":"president","candidate_ref":"bc22c31b-fb89-494a-b88e-2a0fb19788e6"}`
for `user_id = 263 (Jasmine Santos)`.

**Remediation (pick one, then remove the other half):**
* On submit, **clear `selections` and `receipt_token` from `ballot_drafts`** and keep only
  `status='submitted'`; store only the `receipt_hmac` needed by `/api/results/verify` on the user row
  (never the plaintext receipt beside the selections); or
* Keep the draft for UX but stop writing a per-user-correlatable value into `vote_ledger` (batch the
  receipt HMAC per tally batch, or drop the receipt↔ledger linkage and verify against a server-side
  salted hash that is not derivable from anything stored next to `user_id`).
* Design note: `APP_KEY` should not be the only thing standing between a DB dump and de-anonymised
  ballots. For an election, "the authority can unblind ballots" is itself a decision to make
  explicitly.

---

### M-1 🟡 Malformed query parameters crash public endpoints and leak full debug traces

`GET /api/candidates?search[]=x` and `GET /api/candidates?party[]=x` return **HTTP 500** with the
framework's debug payload because the values are used directly (`mb_strtolower($p)`, `"%{$s}%"` in
`like`) without a `string` type check:

```
search[]=x  → 500 {"message":"Array to string conversion","exception":"ErrorException",
                   "file":"/home/Michael/omnivote/backend-laravel/app/Http/Controllers/CandidateController.php","line":36,"trace":[…]}
party[]=x   → 500 {"message":"mb_strtolower(): Argument #1 ($string) must be of type string, array given",
                   "exception":"TypeError","file":"…/CandidateController.php","line":34}
```

With `APP_DEBUG=false` these are still 500s (endpoint-specific DoS); with the *current* configuration
(`.env: APP_DEBUG=true`) they also disclose absolute source paths, framework internals and the full
trace. **Fix:** validate the query string (`'search' => ['nullable','string','max:255']`, same for
`party`/`tier`/`grade`/`department`/`position`) or cast with `(string)`; never run with
`APP_DEBUG=true` outside local dev.

---

### M-2 🟡 Rate limiting is missing everywhere except login/registration

`grep -n throttle backend-laravel/routes/api.php` → **no matches**; `AppServiceProvider` only defines
`login`/`admin-login` limiters, and they are consumed *inside the controllers*, not as route
middleware.

Verified live — 12 unauthenticated requests to the panel password-reset endpoint, all accepted:

```
POST /api/admin/password/email (×12, non-stateful)  → 200 200 200 200 200 200 200 200 200 200 200 200
```

Consequences worth separating:
* `/api/admin/2fa/prepare` requires the **account password** and has **no limiter** → a stolen or
  borrowed session becomes an unthrottled password-guessing oracle (the only feedback is a
  distinguishable 422).
* `/api/results/verify` hashes an unbounded `receipt_token` string per request with no limiter →
  cheap CPU amplification.
* Public read endpoints (`/api/candidates`, `/api/positions`, `/api/announcements`) are unthrottled →
  bulk scraping/enumeration.
* `/api/admin/password/email` is unthrottled per IP (only the broker's per-account 60 s applies).

**Fix:** middleware-level throttling as a baseline (`throttle:60,1` on public reads, a stricter named
limiter such as `throttle:5,1` on auth/2FA/password endpoints) and cap `receipt_token` (`max:128`).

---

### M-3 🟡 Account-lockout denial of service against voters (guessable handles + no recovery)

Five wrong passwords lock the account for 15 minutes. Because handles are name-derived, an attacker
can enumerate the registry and lock **every voter** with 5 requests each — students have **no
self-service password reset or unlock**, so each locked voter needs a manual admin
(`POST /api/admin/users/{user}/unlock`).

**Proof (live, then cleaned up):**

```
POST /api/auth/login {"email":"marco.castillo","password":"x"} ×5   → 401 401 401 401 401
                                      attempt 6                       → 429 (per-account limiter)
DB: users(id=264,email=marco.castillo,failed_login_attempts=5,locked_until="2026-09-26 15:56:10")
```

Note the lockout is *also* an existence oracle (the `423` response names the account and the wait
time). The per-IP limiter (15 failures / 30 min) slows a single source but not a distributed one.

**Fix:** replace hard lockout with exponential backoff per (account, IP) plus a global anomaly
threshold; add student self-service password reset (email/OTP) and an admin bulk-unlock; consider
CAPTCHA on the login form during the election window.

---

### M-4 🟡 Ballot scoping is not enforced server-side (election integrity)

`VoteController::resolveSelections()` validates only that the position is active, the position slug
matches the seat, `seat_count` isn't exceeded, and the candidate is approved **and belongs to that
position**:

```php
if (! $candidate || (int) $candidate->position_id !== $position->id) { return 422; }
```

Nothing constrains *who* may vote in *which* seat. Concretely: `year_level_representative` accepts a
vote from any year level, the `provincial_*`/`governor` seats accept a vote from any province, and
`senator` (seat_count 12) accepts any voter at all. The Flutter client may filter, but the server is
authoritative and records the ballot regardless.

**Fix:** give `positions` an electorate scope (e.g. `scope_type` ∈ global/year_level/province/college
+ `scope_value`) and enforce it in `resolveSelections()` **and** in `ElectionTally`, so tallies cannot
include out-of-scope ballots either.

---

### M-5 🟡 Self-registration can pre-claim a registrar identity

`POST /api/auth/register` (open during the registration phase) requires only
`student_id` ∈ `registrar_imports` plus a unique email, then provisions a student account and returns
a bearer token (`RegistrationController.php:28-48`). If a student exists in the eligibility feed but
has **not yet been provisioned into `users`**, an attacker who knows that student ID can claim the
identity with the attacker's own email and password — and then vote as that student.

**Fix:** require a registrar-issued activation code/OTP (distributed out-of-band), bind registration
to the derived handle, or disable self-registration in favour of import-provisioned accounts only.

---

### L-1 🟢 `Host` header is not validated → generated URLs follow the attacker

`bootstrap/app.php` never calls `->trustHosts()`, and `CandidateController::payload()` builds photo
URLs with `asset('storage/'.$candidate->photo_path)`, which resolves against the **request root**
(Laravel does not force `APP_URL` for `asset()` unless `URL::forceRootUrl()` is called).

**Proof (one column temporarily set, then restored to NULL):**

```
GET /api/candidates/206                          → photo_url = "http://127.0.0.1:8123/storage/candidates/probe.png"
GET /api/candidates/206  -H 'Host: evil.example'  → photo_url = "http://evil.example/storage/candidates/probe.png"
```

Impact is bounded (no data is echoed back from the Host, and password-reset mail correctly uses the
static `config('app.frontend_url')`, so there is no reset-link poisoning), but poisoned absolute URLs
can be cached or rendered by clients that trust the API. **Fix:** `->trustHosts(at: [...])` in
`bootstrap/app.php`, and/or build asset URLs from a configured base rather than the request.

### L-2 🟢 Per-IP login limiter trusts `X-Forwarded-For` from a loopback peer

`trustProxies(at: '127.0.0.1')` means any request whose direct peer is loopback may set its own
`X-Forwarded-For`, and the limiter keys on the resolved `$request->ip()`. Verified from loopback:

```
20 attempts, rotating X-Forwarded-For      → 401 ×20   (limiter defeated)
20 attempts, no X-Forwarded-For (control)  → 401 ×15 then 429 ×5
```

In the shipped deployment nginx *overwrites* the header
(`fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;` in `deploy/nginx-omnivote.conf`), so production is
protected — **nginx is load-bearing here**. Anything that exposes the app without rewriting that
header (e.g. `serve-dev.sh`, which binds `0.0.0.0:8000`, or a second proxy hop that appends instead of
replacing) reopens the bypass. **Fix:** pin the smallest trust set, and document the nginx header
rewrite as a security control.

### L-3 🟢 Live turnout is disclosed to every student

`GET /api/registration/me` returns election-wide counters to any authenticated student
(`registered_students`, `total_students`, `actual_ballots_cast`) — seen live as
`{"registered_students":88,"total_students":64,"actual_ballots_cast":0}`. During voting that is a
running count that can inform tactical voting or turnout suppression. **Fix:** return only the
caller's own card to students; keep the aggregate on the admin dashboard.

### L-4 🟢 Unbounded inputs (storage bloat / abuse)

| Input | Guard in place | Consequence |
|---|---|---|
| `announcements.body` (`AnnouncementController::store/update`) | `required,string` — **no max** | multi-MB rows served through the public feed |
| `ballot_drafts.selections` (`BallotController::saveDraft`) | `required,array` — no shape/size cap, refs unvalidated | draft-store abuse |
| `PATCH /api/admin/settings/{section}` (`SettingsController::update`) | iterates `$request->all()`, **no key allow-list, no value cap** | arbitrary `admin.settings.*` keys (incl. `security.twoFactorRequired`) and value bloat |
| `GET /api/admin/registrar/imports` | `paginate($request->integer('per_page', 20))` — **unvalidated** | full-table response (public `/api/candidates` correctly caps at 100 — apply the same) |
| `receipt_token` (`ResultsController::verify`) | `required,string` — no max | oversized hash input |

### L-5 🟢 Registrar listing filter can be escaped (un-grouped `orWhere`)

`RegistrarImportController::index()` (lines 490-500) nests raw `orWhere` calls without grouping:

```php
->when($request->query('search'), fn ($q, $s) => $q
    ->where('student_id','like',"%{$s}%")->orWhere('full_name','like',"%{$s}%")->orWhere(...))
->when($request->query('year_level'), fn ($q, $y) => $q->where('year_level', $y))
```

The un-grouped `orWhere` escapes the surrounding scope, so combined filters (`search` +
`year_level`/`block_number`) can silently match rows they should not. Values remain bound parameters
(no SQL injection), but the data-scoping is wrong. Contrast `AdminUserController::filteredQuery()`,
which groups correctly — copy that pattern.

### L-6 🟢 Version disclosure and missing app-level headers

`X-Powered-By: PHP/8.5.10` was returned on every response (`expose_php=On`); nginx's `server_tokens
off` does not strip it. `deploy/nginx-omnivote.conf` does set a strong header set (HSTS, CSP
`default-src 'none'`, XFO, nosniff, Referrer-Policy, Permissions-Policy, dotfile `deny`) — but those
exist **only** in nginx, so dev instances and any non-nginx serving path ship without them.
**Fix:** `expose_php=Off` in the PHP-FPM pool, and mirror the key headers at the app level
(middleware) so behaviour is identical in every deployment.

### L-7 🟢 Development `.env` must not reach production (prod misconfig fails open)

The working `.env` holds a real DB credential and `APP_KEY`, plus `APP_DEBUG=true`,
`SESSION_SECURE_COOKIE=false`, `SESSION_ENCRYPT=false`, `APP_ENV=local`, and no `FRONTEND_URL` (so
reset links fall back to `APP_URL`, here `http://…`). `.env` is **not committed** (`git ls-files`
shows only `.env.example` in both apps) — good — but the production checklist in `deploy/README.md`
is manual prose. **Fix:** fail fast at boot when `APP_ENV=production` and (`APP_DEBUG=true`, or
`SESSION_SECURE_COOKIE=false`, or `APP_URL` not `https`), and add those variables to the deploy
script's preflight checks.

### L-8 🟢 Teacher role holds `results.finalize` and `candidates.review`

`config/permissions.php` grants `teacher` both `results.finalize` (declare winners, archive a term)
and `candidates.review` (approve/reject candidates), while `ssg_president` is helpfully narrow. If
teachers only need to read results, split the grant (e.g. `results.view` only) — least privilege for
the role with the second-most panel reach.

### L-9 🟢 Admin password-reset throttle/existence signals

`POST /api/admin/password/email` is unthrottled per IP (only `Password::broker()`'s 60-second
per-account throttle applies), and `RESET_THROTTLED` is surfaced as a distinct `429`, which confirms
the address belongs to a panel account. Minor: return the generic message with the `429` and add an
IP limiter.

### L-10 🟢 Stateful-domain / debug surface review

`config/sanctum.php` defaults the stateful list to
`localhost,localhost:3000,localhost:5173,127.0.0.1,127.0.0.1:8000,::1,debian.tail7e9e1e.ts.net` plus
`Sanctum::currentApplicationUrlWithPort()`. `localhost:3000` and `::1` are unused by either client.
CORS is correctly exact-matched (two Vite dev origins + `APP_URL` + `FRONTEND_URL`) with
`supports_credentials => true` — verified: an `Origin: https://evil.example` preflight gets **no**
`Access-Control-Allow-Origin`. **Fix:** set an explicit `SANCTUM_STATEFUL_DOMAINS` in production and
drop the unused `localhost:3000`/`::1` entries.

---

## 5. What held up (verified controls — keep these)

| Control | Evidence |
|---|---|
| **CSRF on stateful admin mutations** | `POST /api/admin/logout` and `/api/admin/password/email` with a stateful `Origin`/`Referer` and no `X-XSRF-TOKEN` → `419 CSRF token mismatch` |
| **CORS is an exact allow-list** | preflight from `https://evil.example` → `204` with no `Access-Control-Allow-*`; from `http://localhost:5173` → correct `ACAO` + `ACAC: true` |
| **Admin routes are session-only** | a valid **student bearer token** returns `401 Unauthenticated` on `/api/admin/users`, `/me`, `/settings`, `/backups`, `/registrar/imports`, `/users/export` (6/6) |
| **Role gate on student flows** | `EnsureRole` + `auth:sanctum`; panel accounts are rejected from `/api/vote`, `/ballot`, `/candidacy` |
| **Login throttling works** | 5 failures → `429` with `Retry-After`; counters split per-account and per-IP; cleared on success |
| **2FA lifecycle** | `/2fa/prepare` and `/2fa/disable` both re-verify the password; disable also requires a live TOTP or a one-time recovery code; recovery codes are bcrypt-hashed; `confirm()` invalidates the pending secret on a wrong code; the 2FA handshake re-checks role + `is_active` at mint time |
| **Admin safety rails** | cannot change your own role, cannot disable your own account or the last active admin; role/status changes revoke tokens; disabled accounts are blocked by `EnsurePermission`, `/auth/me` and both login endpoints |
| **Password handling** | `Password::defaults(min(8)->letters()->numbers())`; reset revokes all tokens; reset links use `config('app.frontend_url')` (no Host-header poisoning) |
| **Backup surface** | filename regex `^omnivote-\d{14}\.omsnapshot$` on download/delete/restore blocks path traversal; encrypted snapshots use AES-256-**GCM** (tamper-detecting); restore is transactional |
| **CSV formula injection** | `AdminUserController::csvCell()` prefixes `= + - @ TAB CR` — export is neutralised |
| **Output encoding** | no `dangerouslySetInnerHTML`/`innerHTML` anywhere in `admin-react/src`; announcement bodies render as React text; platform statements pass through `strip_tags()` with a safe allow-list |
| **Secrets hygiene** | `.env` untracked (only `.env.example` in git); no keystores/keys tracked; no token in `localStorage` (the admin cache is display-only and documented as non-authoritative) |
| **Dependencies** | `composer audit` → no advisories; `npm audit --omit=dev` → 0 vulnerabilities |
| **Reverse proxy** | HTTPS-only vhost, TLS 1.2/1.3, HSTS, CSP `default-src 'none'`, XFO/nosniff, `location ~ /\.(?!well-known)` deny, `X-Forwarded-For` rewritten to `$remote_addr` |
| **Route validation** | every mutating route carries `auth` + a `permission:`/`role:` gate; phase gates (`checkPhase`) applied to registration/vote/results |

---

## 6. Remediation plan — **completed 2026-09-27**

All ten items below are implemented. Each links to the test that now pins the behaviour; the suite
runs via `backend-laravel/bin/test-local` (or `composer test:local`), because `tests/bootstrap.php`
refuses to start without `pdo_sqlite` rather than silently skipping.

**Before any live election (blockers) — DONE**
1. **C-1** ✅ — random temp credentials (`App\Support\TemporaryPassword`) + a server-side
   `EnsurePasswordChanged` gate on the student middleware stack.
   **Outstanding: rotate the 87 existing accounts** — `php artisan security:rotate-student-credentials`
   (run `--dry-run` first; it reports the accounts it would touch and changes nothing).
2. **M-3** ✅ — exponential backoff replacing the hard lock (`User::registerFailedLogin`:
   1/2/4/8/16/32 min, capped at 60, decaying after `locked_until`), plus registrar-code recovery
   via `POST /api/auth/password/reset-with-code` and an admin bulk-unlock endpoint.
   Tests: `LoginBackoffRecoveryTest`.
3. **H-1** ✅ — the anonymity model is enforced, not just documented: no per-voter reference in
   `vote_ledger`, and `ballot_drafts` selections/receipt are cleared on submit.
   Tests: `StudentVoteAndCandidateFieldsTest`, `RegistrarImportTest`.

**Before opening the panel to more staff — DONE**
4. **M-2** ✅ — named limiters `api-public`, `sensitive`, `password-reset`, `receipt-verify`;
   `/admin/2fa/prepare` now carries `throttle:sensitive`.
5. **M-1** ✅ + **L-7** ✅ — query params validated as scalars (`422` instead of `500` with a stack
   trace); `security:assert-production-config` runs as a preflight in `deploy/deploy.sh` and fails
   the release on unsafe production settings.
6. **M-4** ✅ — `positions.scope_type` / `scope_value` (`global` / `year_level` / `department` /
   `course`), enforced against both the voter and the candidate in `VoteController`. Enforced at
   **write** time, not tally time, because `vote_ledger` rows carry no voter correlation to check
   later. Scope changes are refused once a ledger row exists. Tests: `PositionScope`.
7. **M-5** ✅ — hashed single-use activation codes (`App\Support\RegistrarCode`); self-registration
   is **off by default** behind `admin.settings.security.selfRegistrationEnabled`.
   Tests: `SelfRegistrationActivationCodeTest`.

**Hardening sprint — DONE except L-2/L-6, see below**
8. **L-1** ✅ / **L-2** ⚠️ — `trustHosts` allow-list is in place. **L-2 is a deliberate, documented
   trade-off rather than a completed fix:** `trustProxies(at: ['127.0.0.1', '::1'])` still honours
   `X-Forwarded-For` from loopback, which is required for the per-IP limiters to see the real client
   behind the local nginx/PHP-FPM stack. The original bypass was only reachable *because* the app
   was exposed via `php artisan serve` on all interfaces. **Decision needed before any change here:**
   either keep loopback trust and document that the origin must never be directly reachable, or
   replace it with `Request::HEADER_X_FORWARDED_FOR` on a trusted-proxy CIDR list. Do not "fix" this
   by removing the trusted proxies — the per-IP login limiter silently becomes a global limiter,
   which is the M-3/DoS problem returning.
9. **L-3** ✅ / **L-4** ✅ / **L-5** ✅ — election-wide turnout is `null` while polls are open (the
   keys stay present so the Flutter model tolerates them); input caps on announcement body, ballot
   draft, settings keys/values and receipt token, with a settings key allow-list; registrar filters
   grouped; `per_page` clamped to 1–100. Tests: `InputBoundsAndTurnoutDisclosure`.
10. **L-6** ✅ / **L-8** ✅ / **L-9** ✅ / **L-10** ✅ — both halves of L-6 are closed: the
    `SecurityHeaders` middleware is prepended globally and calls `header_remove('X-Powered-By')`
    while adding `X-Content-Type-Options`, `X-Frame-Options`, CSP and HSTS, and `deploy/nginx-omnivote.conf`
    sets `server_tokens off` so nginx does not advertise the version either. `results.finalize` was removed from the `teacher` role
    (`candidates.review` was kept on purpose — teachers run the candidate workflow, and
    `results.finalize` is the finality/ratification action); admin password reset now returns a
    generic response behind `throttle:password-reset`; `SANCTUM_STATEFUL_DOMAINS` is an explicit
    allow-list with `Sanctum::currentRequestHost()` removed.

**Regression tests — DONE (this line previously read "none exist today")**

The suite went from 0 to **185 tests / 832 assertions** across 20 feature files. The seven called
for by the original plan, and where each lives:

| Planned test | Test file |
|---|---|
| random temp credentials | `RegistrarImportTest` |
| token cannot vote while `must_change_password` is true | `DisabledUserLoginTest`, `StudentAuthTest` |
| the vote ledger cannot be correlated to a user | `StudentVoteAndCandidateFieldsTest` |
| array query params return 422 not 500 | `InputBoundsAndTurnoutDisclosureTest` |
| throttle returns 429 | `LoginRateLimitTest` |
| `per_page` is clamped | `InputBoundsAndTurnoutDisclosureTest` |
| an out-of-scope voter is rejected by a scoped position | `PositionScopeTest` |

Plus `LoginBackoffRecoveryTest` and `SelfRegistrationActivationCodeTest` for M-3/M-5.

### Still open

| # | Item | Why it matters | Where |
|---|---|---|---|
| 1 | **Rotate the 87 student accounts** (C-1) | The old credentials are the *public student ID*; until rotated, the fix only protects new imports. | `php artisan security:rotate-student-credentials` |
| 2 | **Decide L-2** (proxy trust) | Deliberate for now, but it should be a recorded decision, not an accident. | `bootstrap/app.php:50` |
| 3 | **Registrar CSV re-import reissues codes** | Re-importing a CSV regenerates activation codes for *every* row, silently invalidating codes already handed to students. Codes should be issued only for newly inserted rows, with explicit bulk/single reissue actions. | `RegistrarImportController` |
| 4 | **`loginBackoffEnabled` is write-only** | Present in the settings allow-list but never read by the backoff logic. Wire it or remove it. | `config` / `User.php` |

**Item 3 was closed on 2026-09-30.** The import now issues a code only for a row it
actually created (`wasRecentlyCreated`) and reports what it left untouched as
`summary.activation_codes_preserved`, so a routine re-import — one new student, a
refreshed year level — can no longer invalidate a code sheet a student is holding.
Replacement stays explicit: `POST /api/admin/registrar/imports/{import}/issue-code`
(one row) and `POST /api/admin/registrar/imports/issue-codes` (bulk, `only_missing`
supported). Covered by `RegistrarImportTest::test_reimport_preserves_existing_activation_codes`.

Verifying it surfaced a second, unreported defect, also fixed: the import closure
never captured `$issuedCodes` by reference, so codes were generated and hashed but
the endpoint returned an empty `activation_codes` list — registrars were never
shown the codes they are supposed to hand out. Covered by
`RegistrarImportTest::test_fresh_import_returns_the_activation_code_for_each_new_row`
(the test fails if either half regresses).

---

## 7. Cleanup & residue disclosure

The live tests ran against the **local** database `omnivote_local` and were rolled back. Verified end
state after cleanup (`php /tmp/ov_poc.php rollback` + `php /tmp/ov_cleanup.php`):

| Object | End state |
|---|---|
| `vote_ledger` | 0 rows (as before the test) |
| `ballot_drafts` | only the pre-existing row `id=2, user_id=143, status='draft'` — untouched |
| `users` (victim 263) | `has_voted=0`, `voted_at=NULL` |
| `users` lockout columns | `failed_login_attempts=0`, `locked_until=NULL` for every row |
| `election_settings` | `voting_opens_at` / `voting_closes_at` back to `NULL` rows |
| `phases` | `registration_closed` active (original state) |
| `cache` | login / admin-login / register limiter keys purged |
| `candidates` (206) | `photo_path` restored to `NULL` |
| `personal_access_tokens` | see the ⚠️ note below |

> ⚠️ **One unintended side effect, disclosed in full.** The rollback step meant to delete only the two
> tokens minted by this test (`WHERE id > <pre-test row count>`) compared against a **row count**
> instead of a **max id**. Because the id sequence had gaps from earlier admin actions, the statement
> also deleted **~13 pre-existing `personal_access_tokens` rows** (ids `> 21`; 8 rows with ids 6-21
> remain). Impact: up to 13 mobile devices/apps holding a student or admin Sanctum token must sign in
> again — **no data, password, ballot, setting or account was affected**, the deleted values were
> hashes and cannot be restored, and admin-panel cookie sessions were not touched. Nothing needs
> repairing beyond those users re-authenticating.

**Artefacts** (deliberately outside the repo, so no test code is committed): `/tmp/ov_poc.php`
(snapshot / open / deanonymize / rollback), `/tmp/ov_cleanup.php`, `/tmp/ov_snap.json`,
`/tmp/ov_poc_login.json`, `/tmp/ov_poc_vote.json`. Delete them when convenient.

## 8. Appendix — raw evidence highlights

**A. Headers / CORS / CSRF**

```
OPTIONS /api/admin/me   Origin: https://evil.example    → 204  (Vary: Origin, Access-Control-Request-Method; NO ACAO)
OPTIONS /api/admin/me   Origin: http://localhost:5173   → 204  Access-Control-Allow-Origin: http://localhost:5173
                                                              Access-Control-Allow-Credentials: true
POST    /api/admin/logout  (stateful Origin, no XSRF)   → 419  CSRF token mismatch
POST    /api/admin/login   (no Origin/Referer)          → 400  "Your session could not be started. …"  (handled, no 500)
GET     /api/election/status (artisan serve)            → X-Powered-By: PHP/8.5.10
```

**B. Authorisation matrix**

```
student bearer token → /api/auth/me             200    student bearer token → /api/admin/users            401
                     → /api/registration/me     200                         → /api/admin/me               401
                     → /api/candidacy/me        200                         → /api/admin/settings        401
                     → /api/ballot/me           401 (after token revoke)     → /api/admin/backups         401
                                                                            → /api/admin/registrar/imports 401
                                                                            → /api/admin/users/export     401
```

**C. Throttling**

```
POST /api/auth/login  (same fake account)        → 401 401 401 401 401 429 429 429
POST /api/auth/login  (rotating email, same IP)  → 401 ×15 then 429 ×5     (per-IP limiter intact)
POST /api/auth/login  (rotating email + XFF)     → 401 ×20                 (per-IP limiter bypassed from loopback)
POST /api/admin/password/email (×12, no Origin)  → 200 ×12                 (no limiter at all)
```

**D. Parameter fuzzing**

```
?search[]=x   → 500 ErrorException "Array to string conversion"        (CandidateController.php:36)
?party[]=x    → 500 TypeError "mb_strtolower(): … array given"         (CandidateController.php:34)
?tier[]=x, ?grade[]=x, ?department[]=x, ?per_page[]=x → 200 (no crash, empty results)
/api/candidates/{abc|999999|-1} → 404 {"message":"Candidate not found"} (binding handled cleanly)
?per_page=100000 → clamped to 100 rows (correct)
```

**E. Exploit chain (summarised; full transcript in §4 C-1 / H-1)**

```
snapshot → open voting window (temporary) → login with student_id → GET /api/auth/me (200)
        → GET /api/candidacy/me (200) → POST /api/vote (201, receipt issued)
        → HMAC(receipt, APP_KEY) matched exactly one vote_ledger row for user_id = 263
        → rollback (ledger / draft / user / settings / phase / cache restored)
```

**F. Repository / dependency checks**

```
git ls-files | grep -iE '\.env|secret|credential|\.pem|\.key|id_rsa'  →  admin-react/.env.example, backend-laravel/.env.example
composer audit                                                        →  No security vulnerability advisories found.
npm audit --omit=dev                                                  →  found 0 vulnerabilities
grep -n throttle backend-laravel/routes/api.php                       →  (no matches)
grep -rn must_change_password app/Http/Middleware bootstrap           →  (no matches)
grep -rn dangerouslySetInnerHTML|innerHTML admin-react/src            →  (no matches)
```

---

*Report generated by Cline during an authorised assessment of the operator's own local environment.
No production system was accessed. Re-test the CRITICAL/HIGH items after fixing — the PoC scripts in
`/tmp` can be re-run against a fresh local database to confirm the remediations.*







