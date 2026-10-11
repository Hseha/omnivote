# Handoff → Candidacy Seat-Cap Enforcement

> **From:** Solar Mini4 session, 2026-10-11. **Goal:** enforce **1 candidate per party per position** (12 per party for Senator) at application time, with a "seat taken" popup.
> **Split:** Backend = Solar Mini4. Frontend = opencode. **Read §1 (frozen contract) before writing any code.**
> **Status:** plan only — no code written yet. Blocking decisions listed in §6.

---

## 0. The rule (confirmed)

| Position | Per-party cap | Why |
|---|---|---|
| Senator | **12** per party (12 ASLE + 12 SVEA = 24 total) | multi-seat, `seat_count = 12` |
| Every other national + provincial position | **1** per party (1 ASLE + 1 SVEA) | single-seat, `seat_count = 1` |

**Canonical rule:** a party may field at most **`position.seat_count`** candidates per position. This is exactly what the test seeder already does (`TestBallotSeeder::NATIONAL_NOMINEES_PER_PARTY = ['senator' => 12]` with a per-party top-up counter) — this plan only mirrors that logic in production code.

Enforced at **two** gates:
1. **Student apply** (`POST /api/candidate/apply`) — 409 + popup "seat taken".
2. **Admin approval** (`PATCH /admin/candidates/{id}`) — refuse to approve if it would exceed the cap (closes the race/loophole where two pending applications both get approved).

---

## 1. FROZEN API CONTRACT — neither side changes unilaterally

### 1.1 Cap rule (server-side, authoritative)
```
count(candidates where position_id = P AND normalized(party_name) = X
      AND approval_status IN ('pending','approved'))
  <= position(P).seat_count
```
- Normalization: `trim()` + case-insensitive compare (`asle` == `ASLE`). Store the value as submitted; compare normalized.
- Statuses that **block**: `pending`, `approved`. Statuses that **free the seat**: `rejected`, `withdrawn`, `legacy`.
- `party_name = null` ("No party") is its own bucket, same cap (decision D3, default).

### 1.2 NEW endpoint — availability (backend builds, frontend consumes)
`GET /api/candidacy/availability` — student-authenticated, same `api` throttle bucket.
```json
{
  "positions": [
    {
      "id": 3,
      "slug": "president",
      "label": "President",
      "tier": "school",
      "seat_count": 1,
      "party_counts": { "ASLE": 1, "SVEA": 0 }
    }
  ]
}
```
Frontend derives `taken = party_counts[party] >= seat_count`. Counts only — no `taken` booleans, so the server stays the single source of truth.

### 1.3 Apply 409 — seat taken (NEW code field)
`POST /api/candidate/apply` returns HTTP **409**:
```json
{
  "message": "Sorry — the ASLE seat for President is already taken.",
  "code": "seat_taken",
  "position_id": 3,
  "party_name": "ASLE"
}
```

### 1.4 Existing 409 — gets a code field (backward compatible)
`POST /api/candidate/apply` when the student already has an active application, HTTP **409**:
```json
{
  "message": "You already have an active application.",
  "code": "application_exists"
}
```
Frontend keys the popup on `code == "seat_taken"` only; `application_exists` keeps its current handling.

### 1.5 Admin approval refusal
`PATCH /admin/candidates/{id}` (approve) returns HTTP **422** when the cap would be exceeded:
```json
{
  "message": "Cannot approve — the ASLE seat for President already has 1 candidate.",
  "code": "seat_taken"
}
```

---

## 2. WORKSTREAM A — BACKEND (owner: Solar Mini4)

**Scope: `backend-laravel/**` ONLY. Do not touch `user-flutter/**`.**

| # | Task | File | Change |
|---|---|---|---|
| A1 | Party normalization + validation | `app/Http/Requests/CandidateApplicationRequest.php` | `party_name` → `['nullable','string','max:255','exists:parties,name']` + trim/normalize (case-insensitive) in a `prepareForValidation()`; allow null |
| A2 | Apply gate | `app/Http/Controllers/CandidateController.php::store()` | Before `Candidate::create`: count existing candidates for `(position_id, normalized party)` with status `pending|approved`, excluding the caller's own row; if `>= position->seat_count` → 409 with §1.3 payload. Existing per-student 409 gets `code: application_exists` |
| A3 | Availability endpoint | new `app/Http/Controllers/CandidacyAvailabilityController.php` | `GET /candidacy/availability` → §1.2 payload; counts only `pending|approved`; group by `party_name` |
| A4 | Route registration | `routes/api.php` | `Route::get('/candidacy/availability', ...)` inside the student-auth group (same group as `/candidate/apply`) |
| A5 | Admin approval guard | `app/Http/Controllers/AdminCandidateController.php::update()` | When `status == 'approved'`: count OTHER candidates (excluding `$candidate`) for same `(position_id, normalized party_name)` with `pending|approved`; if `>= position->seat_count` → 422 with §1.5 payload. Reject/withdraw stays unguarded |
| A6 | Tests | new `tests/Feature/CandidacySeatCapTest.php` | See §7 for the required cases |

**A6 — required backend test cases:**
1. Second ASLE student applies for President (already 1 ASLE president pending) → **409** `code: seat_taken`, no row created.
2. Same seat after the first is `rejected` → **201** (seat freed).
3. Same seat after the first is `withdrawn` → **201**.
4. ASLE senator 12th candidate → **201**; 13th → **409** `code: seat_taken`.
5. Student edits own application (PUT) with unchanged position+party → **200** (own row excluded from count).
6. Independent (null party) candidate applies when no independent exists → **201**; second independent → **409** (per D3 default).
7. `party_name = "asle"` (lowercase) → normalized, blocked against ASLE seat.
8. `party_name = "NOT_A_PARTY"` → validation error (not 409).
9. Admin approves a 2nd ASLE president → **422** `code: seat_taken`.
10. Admin approves the 13th ASLE senator → **422**.
11. Availability endpoint returns correct `party_counts`.

**Backend acceptance criteria:** `phpunit --filter CandidacySeatCapTest` green · `pint --test` clean on touched files · existing suites (`RegistrarImportTest`, `CandidateTest`, etc.) still green.

---

## 3. WORKSTREAM B — FRONTEND (owner: opencode)

**Scope: `user-flutter/**` ONLY. Do not touch `backend-laravel/**`.**

| # | Task | File | Change |
|---|---|---|---|
| B1 | Availability fetch | `lib/data/services/candidacy_service.dart` | add `Future<Response> getAvailability()` → `GET /candidacy/availability` |
| B2 | Availability repository | `lib/data/repositories/candidacy_repository.dart` | add `Future<Map<int, Map<String,int>>> getAvailability()` parsing §1.2 |
| B3 | "Seat taken" popup | `lib/features/candidacy/screens/candidacy_apply_screen.dart` | on submit failure: if `code == 'seat_taken'` → **showDialog** (AlertDialog: title "Seat taken", body = server `message`, single OK) instead of snackbar. Keep snackbar for every other error |
| B4 | Upfront taken-seat display | `lib/features/candidacy/screens/candidacy_apply_screen.dart` | on screen open, load availability; in the position picker, when the current party is selected, grey out / label positions where `party_counts[party] >= seat_count` as "(party) seat taken". Positions with no candidate for that party stay selectable |
| B5 | Error-code plumbing | `lib/data/services/candidacy_service.dart` + provider | surface the 409 `code` field up to the screen (e.g. `CandidacyException { code, message }`) |
| B6 | Tests | `test/candidacy_seat_cap_test.dart` | See §7 |

**B6 — required Flutter test cases:**
1. Submit → 409 `code: seat_taken` → AlertDialog with "Seat taken" title shown; dismiss with OK.
2. Submit → 409 `code: application_exists` → existing snackbar behavior (no popup regression).
3. Availability: President ASLE=1 → position disabled/labelled taken when party=ASLE; still enabled when party=SVEA.
4. Availability fetch failure → screen still renders, all positions selectable (graceful).

**Frontend acceptance criteria:** `flutter analyze` clean · `flutter test` green (all existing + new) · popup shown on the real 409 contract shape.

---

## 4. OVERLAP GUARDRAILS (both sides MUST respect)

| Owner | May touch | MUST NOT touch |
|---|---|---|
| Solar Mini4 (backend) | `backend-laravel/**` | `user-flutter/**`, `admin-react/**` |
| opencode (frontend) | `user-flutter/**` | `backend-laravel/**`, `admin-react/**` |

- **Contract changes:** neither side edits §1 unilaterally. If a shape is wrong, raise it in this file + notify the other owner; both sides update together.
- **Branching:** each workstream on its own branch off `main` (`feat/candidacy-seat-cap-backend`, `feat/candidacy-seat-cap-frontend`). No shared branch.
- **Commits:** never `git add -A` (the working tree carries other workstreams — registrar-import, monitoring, Flutter error handling). Stage explicit paths only.
- **No shared helper files:** the `code` string (`"seat_taken"`) is a literal in both places; do not create a shared constants file across the repo halves.

---

## 5. INTEGRATION SEQUENCE

```
Phase 0   Answer §6 decisions (Michael)  ── blocking
Phase 1   Backend implements A1-A5, tests A6 green        ── me
Phase 2   Frontend implements B1-B6 (against §1 contract;  ── opencode
          can mock §1.2/§1.3 while Phase 1 lands)
Phase 3   End-to-end (§7) — both sides, staging backend
```

Phase 2 can start in parallel with Phase 1 **only** against mocks that match §1 exactly. No contract drift.

---

## 6. OPEN DECISIONS (defaults proposed — answer to unblock Phase 1)

| # | Decision | Default |
|---|---|---|
| D1 | Cap rule | `seat_count` per party per position (1 everywhere, 12 senator) |
| D2 | Which statuses block | `pending` + `approved` block; `rejected`/`withdrawn` free the seat |
| D3 | Null party ("No party") | own bucket, same cap as a real party |
| D4 | Popup scope | popup on `seat_taken` 409 now; upfront taken-seat labels via availability endpoint |
| D5 | Edit flow | PUT skips the cap check for the applicant's own row |

All five defaults are recommended; a "go with defaults" answer unblocks Phase 1 immediately.

---

## 7. VERIFICATION PLAN

**Backend (me):** `cd backend-laravel && php -d extension=pdo_sqlite.so vendor/bin/phpunit --filter CandidacySeatCapTest` · full suite · `pint --test`.

**Frontend (opencode):** `cd user-flutter && flutter analyze && flutter test`.

**End-to-end (Phase 3, both):**
1. Register 2 ASLE students; both apply for President → 2nd gets the seat-taken popup.
2. Admin rejects the 1st → 2nd can re-apply (seat freed).
3. Apply as 12th ASLE senator → OK. 13th ASLE senator → popup.
4. Admin approves a 2nd ASLE president application → 422 in the admin panel.
5. Availability screen: with party=ASLE, President shows "seat taken"; switch party=ASLE→SVEA, label clears.

---

## 8. FILE INVENTORY (current state, verified 2026-10-11)

| File | Current behavior | Owner |
|---|---|---|
| `backend-laravel/app/Http/Controllers/CandidateController.php:138` | `store()` — per-student check only (409) | Backend |
| `backend-laravel/app/Http/Controllers/AdminCandidateController.php:229` | `update()` — plain status flip | Backend |
| `backend-laravel/app/Http/Requests/CandidateApplicationRequest.php:15` | `party_name` free text | Backend |
| `backend-laravel/routes/api.php:298,305` | apply/update routes exist | Backend |
| candidates table | **no** unique constraint on (position_id, party_name) | Backend |
| `user-flutter/lib/features/candidacy/screens/candidacy_apply_screen.dart` | lists all positions; party dropdown from `/parties`; snackbar on error | Frontend |
| `user-flutter/lib/data/repositories/candidacy_repository.dart` | submit/update/withdraw/getMyApplication | Frontend |
| `backend-laravel/database/seeders/TestBallotSeeder.php` | already implements the per-party rule (test data only) | n/a |
| `backend-laravel/database/migrations/2026_09_05_000001_create_positions_table.php` | seat_count defaults (senator 12, rest 1) | n/a |
