# 05 — API Integration (Laravel backend)

This Flutter app is a **pure API client**. It never computes eligibility, tallies, or approval status locally — it always reflects what the Laravel API returns. Coordinate the exact contract with the Laravel repo; this doc defines what this app *expects* to exist so both sides can build against the same shape.

## Auth

- `POST /api/auth/login` → `{ token, student }`
- `POST /api/auth/logout`
- `GET /api/auth/me` → current `student` (used on app relaunch to validate the stored token)

Auth uses **Laravel Sanctum** bearer tokens. Store the token via `secure_storage_service.dart` (never `SharedPreferences` for the token). Every request through `api_client.dart` attaches `Authorization: Bearer <token>` via a Dio interceptor; a 401 response should force logout + route to Login.

## Election / registration status

- `GET /api/election/status` → `{ phase: "not_registered" | "voting_open" | "voting_closed" | "results_published", serverTime, votingWindow: { opensAt, closesAt } }`
- `GET /api/registration/me` → registration details + turnout numbers for the Dashboard card

Poll or refresh `election/status` on app resume and before entering Vote Now / My Ballot, so a student can't submit a ballot after the window closes client-side.

## Positions & candidates

- `GET /api/positions` → list of positions with `tier`, `seatCount`, `description` (see `docs/04_SCREENS_SPEC.md` for the fixed list)
- `GET /api/candidates?position={id}&tier={school|provincial}&search=&grade=` → paginated candidate list
- `GET /api/candidates/{id}` → full profile (platform points, qualifications, campaign video URL)

## Ballot

- `GET /api/ballot/me` → current draft/submitted ballot: `{ status: "draft"|"submitted", selections: { [positionId]: [candidateId, ...] } }`
- `PUT /api/ballot/me` → upsert a selection for one position (called every time a student taps "Vote" on a card or profile)
- `POST /api/ballot/me/submit` → finalizes the ballot, returns `{ receiptToken }`

## Results

- `GET /api/results` → only populated once `phase == "results_published"`; per-position candidate vote counts
- `POST /api/results/verify` `{ receiptToken }` → `{ counted: true|false }` — must never return candidate names for a token

## Candidacy application

- `GET /api/candidacy/me` → existing application status if any (`none`, `pending`, `approved`, `rejected`)
- `POST /api/candidacy` (multipart, includes photo file) → submits the form described in screen spec section 8

## Error handling convention

Laravel validation errors return `422` with `{ message, errors: { field: [msg] } }`. `api_client.dart` should map this into a typed `ApiValidationException` that form screens catch to show inline field errors, distinct from generic network/server errors (which show a snackbar/retry state via `loading_indicator.dart` / `empty_state.dart`).

## Environment config

`lib/core/constants/api_constants.dart` should read the base URL from a build-time flavor (`--dart-define=API_BASE_URL=...`) so the same APK build pipeline can target local Laravel (`http://10.0.2.2:8000/api` on the Android emulator), staging, and production without code changes.
