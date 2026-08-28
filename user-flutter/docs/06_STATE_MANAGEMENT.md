# 06 — State Management

**Recommendation: Riverpod** (`flutter_riverpod`). It fits this app well because:
- Most screens are "fetch from repository → display" with light local UI state (filters, form fields, ballot-in-progress) — Riverpod's `FutureProvider`/`AsyncNotifier` maps directly to that.
- The ballot-in-progress state (`voting_provider.dart`) needs to be shared across the Candidates screen, Vote Now flow, and My Ballot screen without prop-drilling — a single `BallotNotifier` exposed app-wide solves this cleanly.
- No `BuildContext` requirement for reading state from repositories/services, which keeps `data/` layer testable in isolation.

If the team strongly prefers `provider` or `bloc` instead, the same provider-per-feature boundaries below still apply — only the implementation mechanics change.

## Provider map

| Provider | Lives in | Responsibility |
|---|---|---|
| `authProvider` | `features/auth/providers/` | current `Student?`, login/logout actions |
| `electionStatusProvider` | `core` (used everywhere) | current phase (`not_registered`/`voting_open`/`voting_closed`/`results_published`) — gates Vote Now/My Ballot/Results UI |
| `registrationProvider` | `features/dashboard/providers/` (create if not present) | registration details + turnout numbers |
| `candidatesProvider` | `features/candidates/providers/` | candidate list per `(tier, position, search, gradeFilter)` |
| `candidateProfileProvider` | `features/candidates/providers/` | single candidate detail by id |
| `ballotProvider` | `features/voting/providers/` and reused by `features/ballot/` | draft ballot selections, submit action, receipt token |
| `resultsProvider` | `features/results/providers/` | published results + verify-token action |
| `candidacyProvider` | `features/candidacy/providers/` | form state + submission status |

## Rules

1. One provider per bullet above — don't create a second provider that duplicates ballot or candidate state; screens read the same provider and rebuild.
2. Providers depend on **repositories**, never on `Dio`/`ApiClient` directly (see `docs/02_FOLDER_STRUCTURE.md` rule 3).
3. `ballotProvider` is the single source of truth for what a student has selected. Both the Candidates screen's "Vote" button and the Vote Now stepper write to it; My Ballot only reads + submits it. This prevents the three screens from drifting out of sync.
4. Gate write actions (`Vote`, `Submit Ballot`, `Submit Application`) on `electionStatusProvider` — never render an enabled Vote button when `phase != "voting_open"`.
