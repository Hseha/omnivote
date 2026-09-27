# 05 — API Integration (Laravel backend)

This Flutter app is a **pure API client**. It never computes eligibility, tallies, or approval status locally — it always reflects what the Laravel API returns. Coordinate the exact contract with the Laravel repo; this doc defines what this app *expects* to exist so both sides can build against the same shape.

## Auth

| Endpoint | Purpose |
|---|---|
| `POST /api/auth/login` | `{ email, password }` → `{ token, student, must_change_password }` |
| `POST /api/auth/logout` | Revoke the current token |
| `GET /api/auth/me` | Current `student` (used on app relaunch to validate the stored token) |
| `POST /api/auth/password/change` | Replace a registrar-issued temporary password; revokes every other token |
| `POST /api/auth/password/reset-with-code` | Unattended recovery with a registrar code — see below |
| `POST /api/auth/register` | Self-registration; **disabled by default**, see below |

Auth uses **Laravel Sanctum**. The token is a Sanctum personal-access token minted at login
(`createToken('mobile')`) and returned as `token`; every request through `api_client.dart` attaches
it as `Authorization: Bearer <token>` via a Dio interceptor. Store it via
`secure_storage_service.dart` (never `SharedPreferences`) — and on **web** keep it in `TokenStore`'s
in-memory slot only, so it is never written to `localStorage`/`shared_preferences`.

Sanctum *also* issues the session cookie, so the client must stay cookie-capable; a 401 on a
non-login request should force logout and route to Login.

> The admin console is deliberately **not** part of this contract: it is cookie-only
> (`withCredentials` + `X-XSRF-TOKEN`, CSRF bootstrapped from `/sanctum/csrf-cookie`) and never
> sends a bearer token. Don't copy the console's transport into this app, or vice versa.

### Password recovery (registrar code)

`email` is a name-slug handle (`john.michael.valles`), so a student has **no mailbox** to receive a
reset link. Recovery is therefore by registrar-issued single-use activation code:

```
POST /api/auth/password/reset-with-code
{ "student_id": "2024-01101", "code": "X7K2M9QP", "password": "…", "password_confirmation": "…" }
```

The response is **deliberately generic** — a wrong student ID, a wrong code, and a success all
return the same `200` body, so the endpoint cannot be used to discover who is registered. A correct
code clears `must_change_password`, revokes all existing tokens, and burns the code. Codes are
issued by the registrar on CSV import.

### Self-registration

`POST /api/auth/register` requires `{ student_id, activation_code, … }` and is **off by default**
(`admin.settings.security.selfRegistrationEnabled`). When enabled, the activation code is consumed on
success and rate-limited per IP. Treat it as opt-in; the normal path is registrar import.

## Election / registration status

- `GET /api/election/status` → `{ phase, phase_label, server_time, registration_opens_at, registration_closes_at, voting_opens_at, voting_closes_at, registration_open }`
- `GET /api/registration/me` → `{ eligibility_status, turnout: { has_voted, voted_at, registered_students, total_students, actual_ballots_cast } }`

Poll or refresh `election/status` on app resume and before entering Vote Now / My Ballot, so a student can't submit a ballot after the window closes client-side.

> **Turnout is suppressed while polls are open.** `has_voted` / `voted_at` always describe *you* and
> are safe to render. The election-wide counters (`registered_students`, `total_students`,
> `actual_ballots_cast`) are **`null` until `voting_closed`** — they are deliberately withheld to
> avoid bandwagon effects. The keys stay present rather than being omitted, so the Flutter turnout
> model must tolerate a `null` count and render the card without a figure. Do not substitute a
> locally-computed value.

## Positions & candidates

- `GET /api/positions` → list of positions with `tier`, `seat_count`, `description`, **and the
  electorate scope** `scope_type` + `scope_value` (see below)
- `GET /api/candidates?position={id}&tier={school|provincial}&search=&grade=` → paginated candidate list
- `GET /api/candidates/{id}` → full profile (platform points, qualifications, campaign video URL)

### Electorate scope (positions are not open to everyone)

Every position carries an electorate, returned as two fields:

| Field | Values |
|---|---|
| `scope_type` | `global` \| `year_level` \| `department` \| `course` |
| `scope_value` | the required value for the non-`global` types; `null` when `global` |

`global` is the default for pre-existing positions. A scoped position is enforced **server-side at
vote-write time** against both the voter and the candidate, so the client never has to filter the
ballot — but a UI that greys out out-of-scope positions is a better experience, not a control.
`scope_type` is validated against a fixed set; `province` is not a scope and is rejected.

## Ballot

- `GET /api/ballot/me` → current draft/submitted ballot: `{ status: "draft"|"submitted", selections: { [positionId]: [candidateId, ...] } }`
- `PUT /api/ballot/me` → upsert a selection for one position (called every time a student taps "Vote" on a card or profile)
- `POST /api/ballot/me/submit` → finalizes the ballot, returns `{ receiptToken }`

## Results

- `GET /api/results` → only populated once `phase == "results_published"`; per-position candidate vote counts
- `POST /api/results/verify` `{ receiptToken }` → `{ counted: true|false }` — must never return candidate names for a token

## Candidacy application

- `GET /api/candidacy/me` → existing application status if any (`none`, `pending`, `approved`, `rejected`)
- `POST /api/candidate/apply` (multipart, includes photo file) → submits the form described in screen spec section 8. Note the asymmetry: the read is `/candidacy/me` but the write is `/candidate/apply`.

## Error handling convention

Laravel validation errors return `422` with `{ message, errors: { field: [msg] } }`. `api_client.dart` should map this into a typed `ApiValidationException` that form screens catch to show inline field errors, distinct from generic network/server errors (which show a snackbar/retry state via `loading_indicator.dart` / `empty_state.dart`).

## Environment config

`lib/core/constants/api_constants.dart` should read the base URL from a build-time flavor (`--dart-define=API_BASE_URL=...`) so the same APK build pipeline can target local Laravel (`http://10.0.2.2:8000/api` on the Android emulator), staging, and production without code changes.
