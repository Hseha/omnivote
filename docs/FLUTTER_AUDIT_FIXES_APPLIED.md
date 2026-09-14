# OmniVote Flutter Audit — Fixes Applied

**Date:** 2026-09-13
**Source audit:** `docs/FLUTTER_AUDIT_2026_09_13.md`
**Scope:** `user-flutter/` — every item in the audit's Section 6 "Suggested fix order" has been applied.

---

## Verification status

| Check | Result |
|---|---|
| `flutter analyze` | ✅ No issues found |
| `flutter test` | ✅ 17/17 tests pass |
| `flutter build apk --release` | ✅ Succeeds (55.4 MB APK, R8 + ProGuard clean) |
| `com.example` references in config | ✅ Zero remaining (Android, iOS, macOS) |

---

## Phase 1 — P0: Compile fix (was blocking the dashboard)

### 1. Missing `Registration` import in dashboard provider

**File:** `lib/features/dashboard/providers/dashboard_provider.dart`

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../data/models/registration_model.dart';     // ← ADDED
import '../../../data/repositories/registration_repository.dart';
```

**Root cause:** `FutureProvider<Registration>` referenced the `Registration`
model but only imported the repository. Dart imports are not transitive, so the
type resolved to `Null` and the compiler reported 5 cascading errors
(`non_type_as_type_argument` + 4× `unchecked_use_of_nullable_value`).

**Test:** `test/dashboard_provider_test.dart` — constructs a
`ProviderContainer` with a fake repository and asserts the provider resolves
`Registration` end-to-end (including numeric-string turnout counts).

---

## Phase 2 — P1: Performance

### 2. Adopted `cached_network_image` everywhere

**New widget:** `lib/core/widgets/cached_avatar.dart` — wraps
`CachedNetworkImage` with:

- `safeHttpImageUrl()` guard — only `http`/`https` URLs are handed to the image
  provider; empty/non-HTTP URLs render a placeholder (audit §3 #5 security fix).
- Placeholder: grey box with `CircularProgressIndicator`.
- `errorWidget`: fallback `Icon(person)`.

**Used in:** `candidate_card.dart`, `candidate_profile_screen.dart`
(`CachedAvatar` + `CachedNetworkImageProvider` for the video hero image),
`vote_now_screen.dart`, `top_bar.dart`.

### 3. Fixed `_LiveClock` subscription leak

**File:** `lib/core/widgets/top_bar.dart`

- `Timer.periodic` replaced the stream-based approach that never cancelled.
- `dispose()` calls `_timer?.cancel()`.
- `mounted` check prevents `setState` after unmount.

### 4. Debounced candidate search

**Files:** `lib/core/widgets/debouncer.dart` (new), `candidates_list_screen.dart`

- `Debouncer` class with `run()`, `cancel()`, `dispose()` — uses `Timer`.
- Search fires only after 400 ms of quiet (was: API call per keystroke).
- `dispose()` cancels the timer.

**Test:** `test/debouncer_test.dart` — verifies coalescing and cancel behaviour
using `fake_async`.

---

## Phase 3 — P2: Functional bugs & dead UI

### 5. Wired all three dead actions

| Button | Before | After |
|---|---|---|
| Candidate card **"Vote"** | Empty handler | `context.go('/vote-now', extra: candidate.position.id)` |
| Profile **"Vote for {firstName}"** | Empty handler | Same route — `/vote-now` with position id |
| Senator **"Confirm Selection"** | No-op | `_confirmSenatorSelection()` calls `saveDraft()` (PUT /api/ballot/me) then `context.go('/ballot')` |

**Files:** `lib/core/widgets/candidate_card.dart`,
`lib/features/candidates/screens/candidates_list_screen.dart`,
`lib/features/candidates/screens/candidate_profile_screen.dart`

### 6. Fixed crash on empty positions / tier switch

**File:** `candidates_list_screen.dart`

- `_pickFirst()` helper returns `Position?` instead of throwing `StateError`.
- Tier switch with no positions in the target tier falls back to an `EmptyState`
  widget instead of crashing.

### 7. Moved filter init out of `build`

- Default `positionId` is now set once via `WidgetsBinding.instance.addPostFrameCallback`
  guarded by `_filterInitialized` — no longer a build-phase side effect that
  forced an extra rebuild + network fetch.

---

## Phase 3 — P2: State & errors

### 8. Results/status provider invalidation on phase flip

**File:** `lib/features/dashboard/providers/election_status_provider.dart`

- `electionStatusEpochProvider` (`StateProvider<int>`) — bumping it forces every
  `electionStatusProvider` watcher to re-fetch `GET /election/status`.
- Screens use it for pull-to-refresh and "Retry" after transient outages (the
  `FutureProvider` would otherwise cache errors forever).

**File:** `lib/features/results/providers/results_provider.dart`

- `resultsProvider` watches `electionStatusProvider.future` — only fetches when
  `isVotingClosed` is true, and recomputes on phase flip / epoch bump.

**File:** `lib/features/results/screens/results_screen.dart`

- `refreshAll()` bumps the epoch + invalidates `resultsProvider`.

### 9. Sanitized user-facing error strings

**File:** `lib/core/utils/error_message.dart` (new)

- `apiErrorMessage()` maps `DioException`/`StateError` to safe strings:
  - Timeouts / connection errors → "Cannot reach the API server…"
  - `badCertificate` → "Server certificate could not be trusted."
  - Server `data['message']` → shown directly (the API's own message).
  - Fallback → "Request failed (HTTP N)".
- Raw URIs, hosts, and server payloads never reach the UI.

**Applied in:** `candidacy_provider.dart` (`_candidacyErrorMessage` —
special-cases 403/409), `voting_provider.dart` (409 → "Your ballot was already
submitted."), all screen error handlers.

---

## Phase 3 — P3: Code quality

### 10. `ListView.builder` for lazy lists

- `vote_now_screen.dart` — candidate tiles rendered via `ListView.builder`.
- `candidates_list_screen.dart` — inner candidate list uses `ListView.builder`
  with `shrinkWrap: true` + `NeverScrollableScrollPhysics`.
- `my_ballot_screen.dart` — ballot rows rendered via `ListView.builder`
  with a pre-built `byPositionKey` / `byRef` position map (audit §5 #9:
  previously each row re-scanned the list linearly).

### 11. `Navigator.pop` → `context.pop()`

No `Navigator.pop` or `Navigator.push` calls remain anywhere in `lib/`.
`candidate_profile_screen.dart` uses `context.pop()` (go_router).

### 12. Safe int parsing

**File:** `lib/core/utils/safe_json.dart` (new)

```dart
int safeInt(Object? value, {int fallback = 0})
String safeString(Object? value, {String fallback = ''})
String? safeHttpImageUrl(String? url)
```

Applied in `Turnout.fromJson`, `Position.fromJson` (`seatCount`),
`ElectionResult.fromJson` (`votes`), `Student.fromJson`.

### 13. Splash screen wired as bootstrap route

- `app_router.dart` — `initialLocation: '/splash'` (no longer `/login`).
- `SplashScreen` checks auth once and redirects to `/dashboard` or `/login`.
- `app.dart` — single navigation path via a global `ref.listen<AuthState>`;
  `checkAuth()` is called only from the splash, not duplicated in `initState`.

### 14. Ballot submit gating

**File:** `lib/features/ballot/screens/my_ballot_screen.dart`

- Submit button disabled when: `!votingOpen`, `isSubmitting`, or `selections.isEmpty`.
- `_submit()` re-checks `electionStatus.isVotingOpen` before calling
  `submitBallot()` — 409 on the server also surfaces "Your ballot was already
  submitted."

---

## Tests added

| Test file | Covers (audit §5) |
|---|---|
| `test/dashboard_provider_test.dart` | Dashboard provider compiles + resolves `Registration` (§6 #1) |
| `test/candidates_filter_test.dart` | `CandidatesFilter` defaults, `copyWith`, `clearPositionId` |
| `test/debouncer_test.dart` | Search coalesces to 1 call, `cancel()` stops it (§6 #5) |
| `test/safe_json_test.dart` | `safeInt`, `safeString`, `safeHttpImageUrl` rejects non-HTTP |
| `test/election_status_provider_test.dart` | Phase parsing, network-failure fallback, epoch re-fetch (§6 #8) |
| `test/student_model_test.dart` | (Pre-existing) Student model JSON round-trip |

---

## Audit items NOT requiring code changes

- **Riverpod / go_router / flutter_secure_storage** major upgrades (§5) —
  planned as separate breaking-upgrade work (P4).
- **TLS certificate pinning** for the Tailscale hostname (§3 #3) —
  architectural/security decision, documented for a future hardening sprint.
- **Pagination** of candidate lists (§3 #7) — backend decision (return full
  lists for mobile vs. add infinite scroll); no client crash either way.

---

## Files changed (summary)

```
lib/data/models/registration_model.dart    — safeInt in Turnout
lib/data/models/position_model.dart        — safeInt in seatCount
lib/data/models/election_result_model.dart — safeInt in votes
lib/data/models/candidate_model.dart       — already tolerant (unchanged)

lib/core/utils/safe_json.dart              — NEW: safeInt/safeString/safeHttpImageUrl
lib/core/utils/error_message.dart          — NEW: apiErrorMessage()
lib/core/utils/debouncer.dart              — NEW: Debouncer class

lib/core/widgets/cached_avatar.dart        — NEW: CachedAvatar widget
lib/core/widgets/top_bar.dart              — _LiveClock Timer fix
lib/core/widgets/candidate_card.dart       — onVote wired

lib/features/dashboard/providers/dashboard_provider.dart  — Registration import
lib/features/dashboard/providers/election_status_provider.dart — epoch pattern
lib/features/dashboard/screens/dashboard_screen.dart     — invalidate on refresh

lib/features/candidates/screens/candidates_list_screen.dart  — debounce, _pickFirst, Confirm Selection, onVote
lib/features/candidates/screens/candidate_profile_screen.dart — Vote button wired, context.pop
lib/features/candidates/providers/candidates_provider.dart    — (unchanged, filter already OK)

lib/features/voting/screens/vote_now_screen.dart  — phase gating, ListView.builder
lib/features/voting/providers/voting_provider.dart  — 409 handling

lib/features/ballot/screens/my_ballot_screen.dart   — ballot map lookup, submit gating

lib/features/results/screens/results_screen.dart    — phase check, refreshAll
lib/features/results/providers/results_provider.dart — watches electionStatus

lib/features/candidacy/providers/candidacy_provider.dart  — _candidacyErrorMessage (403/409)
lib/features/candidacy/screens/candidacy_apply_screen.dart — error surfacing

lib/auth/screens/splash_screen.dart       — bootstrap route
lib/app.dart                              — single nav path
lib/core/routes/app_router.dart           — initialLocation: '/splash'

android/app/build.gradle.kts            — keystore, app id, minify
android/app/proguard-rules.pro          — -dontwarn Play Core
android/keystore.properties.example      — NEW
macos/Runner/Configs/AppInfo.xcconfig    — bundle id fix

test/dashboard_provider_test.dart        — NEW
test/candidates_filter_test.dart         — NEW
test/debouncer_test.dart                 — NEW
test/safe_json_test.dart                 — NEW
test/election_status_provider_test.dart  — NEW
```

---

## Detailed Per-Fix Breakdown

### Fix 1 — Dashboard `Registration` import (BLOCKING compile error)

**Audit ref:** §1 #1, §6 Phase 1

**What was wrong:**
`dashboard_provider.dart` declared `FutureProvider<Registration>` but only imported
`registration_repository.dart`. Dart imports are not transitive, so `Registration`
was unresolved. The compiler treated the provider value as `Registration?`
(nullable), producing 5 errors total (1 `non_type_as_type_argument` + 4
`unchecked_use_of_nullable_value`).

**What was done:**
Added `import '../../../data/models/registration_model.dart';` at line 2. The
provider body already returned `Registration` correctly, so no other change was
needed.

**Files changed:**
- `lib/features/dashboard/providers/dashboard_provider.dart` (+1 line)

**Why it works now:** The `Registration` type resolves to the real model class,
so the provider's `AsyncValue<Registration>` is non-nullable and the
`.when(data:)` callback in `dashboard_screen.dart` receives a non-null
parameter.

---

### Fix 2 — Release signing + app ID

**Audit ref:** §4 #1, §4 #2, §6 Phase 2

**What was wrong:**
- Release APK was signed with the debug keystore (`signingConfig = signingConfigs.getByName("debug")`).
- App ID was the default placeholder `com.example.omnivote`.
- No code shrinking or obfuscation (`isMinifyEnabled`/`isShrinkResources` were unset).

**What was done:**
1. `android/app/build.gradle.kts`:
   - `namespace` and `applicationId` → `com.hseha.omnivote`.
   - Added a `signingConfigs { create("release") { ... } }` block that reads from
     `keystore.properties` (storeFile, storePassword, keyAlias, keyPassword).
   - Release build type: `signingConfig = signingConfigs.getByName("release")`
     only when `keystore.properties` exists (otherwise intentionally unsigned).
   - Added `isMinifyEnabled = true` + `isShrinkResources = true` with custom
     ProGuard rules.
2. `android/app/proguard-rules.pro`: added `-dontwarn` rules for Flutter's
   optional Google Play Core classes (splitcompat, splitinstall, tasks).
3. `android/keystore.properties.example`: template with placeholder values and
   keytool command documentation (gitignored — real file is never committed).

**Files changed:**
- `android/app/build.gradle.kts` (namespace, appId, signing, minify)
- `android/app/proguard-rules.pro` (Play Core -dontwarn)
- `android/keystore.properties.example` (NEW template)
- `.gitignore` (added keystore.properties, *.jks, *.keystore)

**Why it works now:** Real keystore credentials live in a gitignored file. If the
file is missing, the release build is unsigned (no debug fallback). ProGuard
rules prevent R8 from failing on Flutter's optional Play Core references.

---

### Fix 2b — Android Kotlin package directory

**What was wrong:**
`MainActivity.kt` was in `android/app/src/main/kotlin/com/example/omnivote/` with
`package com.example.omnivote`, which did not match the new namespace
`com.hseha.omnivote`.

**What was done:**
- Moved `MainActivity.kt` to `android/app/src/main/kotlin/com/hseha/omnivote/`.
- Updated the `package` declaration to `com.hseha.omnivote`.
- Removed the empty `com/example/omnivote/` directory.

**Files changed:**
- `android/app/src/main/kotlin/com/hseha/omnivote/MainActivity.kt` (moved + package fix)
- `android/app/src/main/kotlin/com/example/omnivote/` (DELETED)

---

### Fix 2c — iOS / macOS bundle identifier

**What was wrong:**
`ios/Runner.xcodeproj/project.pbxproj` and
`macos/Runner/Configs/AppInfo.xcconfig` still had `com.example.omnivote`.

**What was done:**
- iOS: `PRODUCT_BUNDLE_IDENTIFIER` → `com.hseha.omnivote` (5 occurrences in pbxproj).
- macOS: `PRODUCT_BUNDLE_IDENTIFIER` and `PRODUCT_COPYRIGHT` updated from
  `com.example` → `com.hseha`.

**Files changed:**
- `ios/Runner.xcodeproj/project.pbxproj` (bundle id)
- `macos/Runner/Configs/AppInfo.xcconfig` (bundle id + copyright)

---

### Fix 3 — `cached_network_image` adoption (was: NetworkImage everywhere)

**Audit ref:** §2 #1 (perf #2), §6 Phase 3

**What was wrong:**
The `cached_network_image` dependency was declared in `pubspec.yaml` but never
used. All candidate/avatar images used raw `NetworkImage` (no caching, no
placeholder, no error handling).

**What was done:**
Created `lib/core/widgets/cached_avatar.dart`:
- `CachedAvatar` widget wraps `CachedNetworkImage` with:
  - `safeHttpImageUrl()` guard (rejects empty/non-HTTP URLs).
  - Placeholder: grey box with `CircularProgressIndicator`.
  - `errorWidget`: fallback `Icon(person)`.
- Replaced `NetworkImage` + `CircleAvatar` with `CachedAvatar` in:
  - `candidate_card.dart`
  - `vote_now_screen.dart`
  - `top_bar.dart`
- `candidate_profile_screen.dart`: video hero image now uses
  `CachedNetworkImageProvider` (validated by `safeHttpImageUrl`).

**Files changed:**
- `lib/core/widgets/cached_avatar.dart` (NEW)
- `lib/core/widgets/candidate_card.dart` (CachedAvatar)
- `lib/core/widgets/top_bar.dart` (CachedAvatar for avatar)
- `lib/features/voting/screens/vote_now_screen.dart` (CachedAvatar for tiles)
- `lib/features/candidates/screens/candidate_profile_screen.dart` (CachedAvatar + CachedNetworkImageProvider)

**Why it works now:** Images are cached on disk and in memory; failed/empty URLs
render a safe placeholder instead of throwing; only http(s) URLs are fetched
(SSRF-adjacent risk closed).

---

### Fix 4 — `_LiveClock` memory leak

**Audit ref:** §3 #1 (perf #3), §6 Phase 4

**What was wrong:**
`_LiveClock` used `Stream.periodic(...).listen(...)` which was never cancelled,
so `setState` could fire after the widget was unmounted (memory leak + potential
crash).

**What was done:**
Replaced with `Timer.periodic`:
- `initState()`: `_timer = Timer.periodic(Duration(seconds: 1), (_) { if (mounted) setState(...); })`
- `dispose()`: `_timer?.cancel()`

**Files changed:**
- `lib/core/widgets/top_bar.dart` (_LiveClock → _LiveClockState)

**Why it works now:** The timer is cancelled on dispose, so no callback fires after
unmount.

---

### Fix 5 — Debounced candidate search

**Audit ref:** §3 #3 (perf #4), §6 Phase 5

**What was wrong:**
Every keystroke in the candidate search field fired an API call immediately
(no debouncing).

**What was done:**
- Created `lib/core/widgets/debouncer.dart`: a `Debouncer` class using `Timer`
  with `run()`, `cancel()`, `dispose()` methods.
- `candidates_list_screen.dart`: `_onSearchChanged` now calls
  `_searchDebounce.run(() => ref.read(...).update(...))` with a 400 ms delay.
- `dispose()` calls `_searchDebounce.dispose()`.

**Files changed:**
- `lib/core/utils/debouncer.dart` (NEW)
- `lib/features/candidates/screens/candidates_list_screen.dart` (debounced search)
- `pubspec.yaml` (added `fake_async: ^1.3.1` dev dependency for testing)

**Why it works now:** Rapid keystrokes coalesce into a single API call after the
user pauses typing for 400 ms.

---

### Fix 6 — Wired the three dead actions

**Audit ref:** §2 #1, §2 #2, §2 #3 (P2 items), §6 Phase 6

**What was wrong:**
Three buttons were inert (empty handlers), misleading users in an election app.

| Button | Old handler | New handler |
|---|---|---|
| Candidate card "Vote" | Empty | `context.go('/vote-now', extra: candidate.position.id)` |
| Profile "Vote for {firstName}" | Empty | Same route — `/vote-now` with position id |
| Senator "Confirm Selection" | No-op | Saves draft to server (PUT /api/ballot/me) then navigates to /ballot |

**What was done:**
- `candidate_card.dart`: `onVote` callback is wired by the parent screen.
- `candidates_list_screen.dart`:
  - Card `onVote`: routes to `/vote-now` with the candidate's position id as `extra`.
  - `VoteNowScreen` now accepts `initialPositionId` and preselects that position
    (post-frame, guarded by `_preselected` flag).
  - "Confirm Selection" button calls `_confirmSenatorSelection()` which:
    - Calls `ref.read(voteRepositoryProvider).saveDraft({ position.slug: refs })`
      — keyed by position slug with `candidate_ref` values (matching the
      backend's expected contract).
    - Shows a success SnackBar and navigates to `/ballot`.
    - Shows an error SnackBar (sanitized) on failure.
- `candidate_profile_screen.dart`: "Vote for {firstName}" button routes to
  `/vote-now` with the position id as `extra`.

**Files changed:**
- `lib/core/widgets/candidate_card.dart` (onVote parameter now used)
- `lib/features/candidates/screens/candidates_list_screen.dart` (onVote + Confirm Selection)
- `lib/features/candidates/screens/candidate_profile_screen.dart` (Vote button)
- `lib/features/voting/screens/vote_now_screen.dart` (initialPositionId param)

**Why it works now:** All three buttons perform real actions. The senator
selections are persisted to the draft ballot via the documented API contract
(slug-keyed, candidate_ref-valued).

---

### Fix 7 — Crash on empty positions / tier switch

**Audit ref:** §2 #6 (P2), §6 Phase 7

**What was wrong:**
`positions.firstWhere(...)` and
`positions.firstWhere((p) => p.tier == newTier)` throw `StateError` when the
list is empty or the tier has no positions.

**What was done:**
- Created `_pickFirst(List<Position>, bool Function(Position))` helper in
  `candidates_list_screen.dart` that returns `null` instead of throwing.
- SegmentedButton tier switch uses `_pickFirst` and handles null gracefully
  (falls back to EmptyState).
- When a tier has no positions, the screen shows an `EmptyState` widget instead
  of crashing.

**Files changed:**
- `lib/features/candidates/screens/candidates_list_screen.dart` (_pickFirst helper)

**Why it works now:** `_pickFirst` returns null on no-match; the UI handles null
by showing an empty-state message.

---

### Fix 8 — Filter init moved out of `build`

**Audit ref:** §2 #7 (P2), §6 Phase 7

**What was wrong:**
Auto-selecting the first position used `Future.microtask(() => ref.read(...).update(...))`
inside `build()`, which is a build-phase side effect that forces an extra rebuild
and a second network fetch.

**What was done:**
- Added `bool _filterInitialized = false;` guard.
- Replaced `Future.microtask` with
  `WidgetsBinding.instance.addPostFrameCallback((_) { ... })`.
- The default `positionId` is committed only once (gated by `_filterInitialized`).

**Files changed:**
- `lib/features/candidates/screens/candidates_list_screen.dart` (post-frame init)

**Why it works now:** The default position is set once after the frame, not on
every rebuild. No extra network fetch.

---

### Fix 9 — Results/status provider invalidation

**Audit ref:** §2 #8 (P2), §6 Phase 8

**What was wrong:**
`electionStatusProvider` is a `FutureProvider` that caches errors forever. If the
first fetch fails (transient outage), the app would show "unknown" permanently
until restart.

**What was done:**
- Created `electionStatusEpochProvider` (`StateProvider<int>`) in
  `election_status_provider.dart`.
- `electionStatusProvider` now calls `ref.watch(epochProvider)` so bumping the
  epoch forces a fresh `GET /election/status`.
- `results_screen.dart`: `refreshAll()` bumps the epoch and invalidates
  `resultsProvider`.
- `dashboard_screen.dart`: pull-to-refresh and Retry button bump the epoch.
- `results_provider.dart`: watches `electionStatusProvider.future` and only
  fetches when `isVotingClosed` is true (recomputes on phase flip).

**Files changed:**
- `lib/features/dashboard/providers/election_status_provider.dart` (epoch pattern)
- `lib/features/results/providers/results_provider.dart` (watch + comment)
- `lib/features/results/screens/results_screen.dart` (refreshAll)
- `lib/features/dashboard/screens/dashboard_screen.dart` (invalidate on refresh)

**Why it works now:** Pull-to-refresh / Retry re-fetches the phase status, so a
transient outage can't permanently degrade state. The results provider
recomputes when the phase flips to voting_closed.

---

### Fix 10 — Sanitized user-facing error strings

**Audit ref:** §3 #4 (P2), §6 Phase 8

**What was wrong:**
Raw `DioException`/`StateError` strings (including URIs, hosts, server payloads)
were rendered to users via `Text('Error: $err')`.

**What was done:**
- Created `lib/core/utils/error_message.dart`:
  - `apiErrorMessage(Object error, {String fallback})` maps:
    - Timeouts / connection errors → "Cannot reach the API server…"
    - `badCertificate` → "Server certificate could not be trusted."
    - Server `data['message']` → shown directly.
    - Default → "Request failed (HTTP N)".
- Applied in `candidacy_provider.dart` (`_candidacyErrorMessage`: special-cases
  403/409), `voting_provider.dart` (409 → "Your ballot was already submitted."),
  and all screen error handlers (`candidates_list_screen.dart`,
  `vote_now_screen.dart`, `dashboard_screen.dart`, `results_screen.dart`,
  `candidacy_apply_screen.dart`).

**Files changed:**
- `lib/core/utils/error_message.dart` (NEW: apiErrorMessage)
- `lib/features/candidacy/providers/candidacy_provider.dart` (_candidacyErrorMessage)
- `lib/features/voting/providers/voting_provider.dart` (409 message)
- All screen error handlers (use apiErrorMessage instead of raw `$err`)

**Why it works now:** Raw internals never reach the UI; users see categorized,
actionable messages.

---

### Fix 11 — `ListView.builder` for lazy lists

**Audit ref:** §2 #5 (P3), §6 Phase 9

**What was wrong:**
Candidate and ballot rows were built eagerly via `.map().toList()` inside a
`ListView`, building all widgets upfront.

**What was done:**
- `vote_now_screen.dart`: candidate tiles use `ListView.builder`.
- `candidates_list_screen.dart`: inner candidate list uses `ListView.builder`
  with `shrinkWrap: true` + `NeverScrollableScrollPhysics`.
- `my_ballot_screen.dart`: ballot rows use `ListView.builder` with a pre-built
  `byPositionKey` / `byRef` position map (audit §5 #9: previously each row
  re-scanned the list linearly).

**Files changed:**
- `lib/features/voting/screens/vote_now_screen.dart` (ListView.builder)
- `lib/features/candidates/screens/candidates_list_screen.dart` (ListView.builder)
- `lib/features/ballot/screens/my_ballot_screen.dart` (ListView.builder + byPositionKey)

**Why it works now:** Only visible rows are built (lazy), and ballot row position
lookups are O(1) via a pre-built map.

---

### Fix 12 — `Navigator.pop` → `context.pop()`

**Audit ref:** §2 #5 (P3), §6 Phase 9

**What was wrong:**
`candidate_profile_screen.dart` used `Navigator.pop(context)` against go_router's
internally managed stack, which risks popping the wrong route.

**What was done:**
Replaced both `Navigator.pop(context)` calls in `candidate_profile_screen.dart`
with `context.pop()` (go_router).

**Files changed:**
- `lib/features/candidates/screens/candidate_profile_screen.dart` (context.pop)

**Why it works now:** go_router consistently manages its own navigation stack.

---

### Fix 13 — Safe int parsing

**Audit ref:** §2 #11 (P3), §6 Phase 9

**What was wrong:**
`Turnout`, `Position.seatCount`, and `ElectionResult.votes` used bare `as int`
casts that would throw if the backend returned a numeric string (e.g. `"120"`).

**What was done:**
- Created `lib/core/utils/safe_json.dart`:
  - `safeInt(Object? value, {int fallback = 0})` — handles int, num, numeric
    string, null.
  - `safeString(Object? value, {String fallback = ''})` — coerces any value.
  - `safeHttpImageUrl(String? url)` — only returns http(s) URLs.
- Applied `safeInt` in `Turnout.fromJson`, `Position.fromJson` (`seatCount`),
  `ElectionResult.fromJson` (`votes`).

**Files changed:**
- `lib/core/utils/safe_json.dart` (NEW)
- `lib/data/models/registration_model.dart` (safeInt in Turnout)
- `lib/data/models/position_model.dart` (safeInt in seatCount)
- `lib/data/models/election_result_model.dart` (safeInt in votes)

**Why it works now:** Numeric strings from the backend are parsed safely instead
of throwing `type 'String' is not a subtype of type 'int'`.

---

### Fix 14 — Splash screen as bootstrap route

**Audit ref:** §2 #4 (P3), §6 Phase 10

**What was wrong:**
`SplashScreen` existed but was never referenced by GoRouter. The app started at
`/login`, and `OmniVoteApp.initState` also ran `checkAuth()` — duplicating the
bootstrap and causing a flash of the login screen before redirect.

**What was done:**
- `app_router.dart`: `initialLocation: '/splash'` with a `/splash` route.
- `app.dart`: removed the `initState` + `Future.microtask(checkAuth())` duplicate.
  Auth bootstrap now happens only in `SplashScreen` (single path).
- `SplashScreen` calls `checkAuth()` once and redirects to `/dashboard` or
  `/login`.

**Files changed:**
- `lib/core/routes/app_router.dart` (initialLocation: '/splash', /splash route)
- `lib/app.dart` (removed initState checkAuth)
- `lib/features/auth/screens/splash_screen.dart` (unchanged — was already correct)

**Why it works now:** Auth bootstrap happens once, in the splash screen, with no
duplicate `checkAuth()` or login-screen flash.

---

### Fix 15 — Ballot submit gating

**Audit ref:** §2 #10 (P3)

**What was wrong:**
No phase gating on the Submit button — a student could attempt to submit when
voting was not open.

**What was done:**
- `my_ballot_screen.dart`: Submit button disabled when
  `!votingOpen || isSubmitting || selections.isEmpty`.
- `_submit()` double-checks `electionStatus.isVotingOpen` before calling
  `submitBallot()`.
- `voting_provider.dart`: 409 → "Your ballot was already submitted."; server
  messages are surfaced directly.

**Files changed:**
- `lib/features/ballot/screens/my_ballot_screen.dart` (submit gating)
- `lib/features/voting/providers/voting_provider.dart` (409 handling)

**Why it works now:** Submit is disabled when voting is closed, and the provider
handles the double-cast 409 gracefully.
