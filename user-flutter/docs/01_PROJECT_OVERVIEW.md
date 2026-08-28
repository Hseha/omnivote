# 01 — Project Overview

## What is OmniVote?

OmniVote is a digital student-council election system used by a school (and, at a second tier, a province-wide student body spanning multiple schools). It has two council levels running in parallel during the same election window:

- **School Council** — Presidential, Vice Presidential, Secretary, Treasurer, Auditor, Press Officer, Senator (12 seats), Year Level Representative, Property Custodian
- **Provincial Council** — Governor, Vice Governor, Provincial Secretary, Provincial Treasurer, Provincial Auditor, Provincial Press Officer, Provincial Custodian

Students register once, then vote once per position across both tiers during the voting window, and can check live turnout and, once polls close, published results.

## Who uses this Flutter app

**Students only.** One role, one app. There is no admin, election-committee, or multi-role switching inside this client — that all lives in the separate React admin web app talking to the same Laravel backend.

A student can:
- View their voter registration status and school-wide turnout (Dashboard)
- Browse candidates for every position, in both tiers (Candidates)
- Cast/edit their ballot before submitting (Vote Now / My Ballot)
- View published results once polls close, and verify their own vote with a receipt token (Election Results)
- Apply to become a candidate themselves (Apply for Candidacy)
- Get help (Help & FAQ)

## Explicit scope boundaries for this repo

- ✅ Flutter mobile app (Android APK primary target; iOS optional, same codebase)
- ✅ Consumes the Laravel REST API as a client
- ❌ No backend logic — never implement business rules like eligibility checks, vote counting, or audit workflows locally; always call the API and render what it returns
- ❌ No admin/election-committee screens (candidate approval, results publishing, audits) — those belong to the React web app
- ❌ No shared Dart/JS code with the React app — treat it as a completely separate consumer of the same API contract

## Reference UI

The visual reference for this app is a set of desktop web screenshots of the "OmniVote Student Portal." Flutter screens should be **re-designed for mobile** (bottom navigation instead of a permanent left sidebar, single-column cards instead of a grid, etc.) while preserving the same information, labels, and flow described in `docs/03_APP_FLOW.md` and `docs/04_SCREENS_SPEC.md`.
