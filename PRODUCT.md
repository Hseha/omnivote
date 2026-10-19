# OmniVote — Product Context

> Root-level product context so design tooling (and any agent) can orient
> quickly. The detailed specifications live in `docs/`; this file is the map.

## What OmniVote is

A digital student-council election system for a school, and — at a second
tier — for a province-wide student body spanning multiple schools. Two
council levels run in parallel during the same election window:

| Tier | Seats |
|---|---|
| **National** (school council) | President, Vice President, Secretary, Treasurer, Auditor, Press Officer, Senator (12 seats), Year Level Representative, Property Custodian |
| **Provincial** (province council) | Governor, Vice Governor, Provincial Secretary, Provincial Treasurer, Provincial Auditor, Provincial Press Officer, Provincial Custodian |

Students register once, then vote once per position across both tiers
during the voting window, and can check live turnout and — once polls close
— published results.

## Who uses what

Three distinct consumers share one Laravel backend. They are separate
codebases and separate audiences; do not blur them.

| Surface | Audience | Code | Mount |
|---|---|---|---|
| **Student app** | Students — one role, one app. No role switching. | `user-flutter/` (Flutter, Android primary) | Mobile app / Play Store |
| **Staff console** | Election committee, teachers, administrators | `admin-react/` (React + Vite) | `/admin/` — behind an HTTP Basic pre-auth gate on nginx |
| **President console** | The elected SSG President — a student, not staff | `admin-react/` (same React bundle) | `/ssg/` — ungated, session-auth only, `ssg_president` role |

The President console is the one that surprises people: it is served by the
*same* React bundle as the staff console, but at a different mount, with a
different role gate, and a deliberately narrower feature set.

## What each surface is for

**Student app** — register, check turnout, browse candidates, cast/edit a
ballot, verify a vote with a receipt token, apply for candidacy, get help.
It is a *read-and-act* surface: ballot security and clarity matter more
than density.

**Staff console** — the operational tool. Candidate approval, voter
registry, election setup and phase control, results, records, announcements,
audit log, settings, user management. Dense, keyboard-friendly, information-
first: the operator is often doing a repetitive task and scanning for one
value.

**President console** — governance transparency. The certified officer
roster (who actually holds which seat, in which electorate, and how many of
that seat's places are filled) and publishing announcements to students. Read-
mostly, and the audience is a student: it should be legible and calm, not
an operations dashboard.

## The phase model that shapes every surface

An election moves through phases — registration, candidacy, voting, voting
closed, results published — and the UI changes with it. Screens are
phase-aware rather than phase-blind: the roster does not exist before votes
close, results are not public until they are published, and the SSG
President's console is locked until the setup link is redeemed.

## Boundaries

- Business rules (eligibility, vote counting, seat caps, audit workflows)
  live in the **backend only**. Never re-implement them in a client.
- The Flutter app and the React console are **completely separate consumers**
  of the same API contract. No shared Dart/JS code.

## Further reading

| Doc | Covers |
|---|---|
| `docs/01_PROJECT_OVERVIEW.md` | Scope, audience, reference UI |
| `docs/03_APP_FLOW.md` | Navigation shell, screen-to-screen flow, voting-window states |
| `docs/04_SCREENS_SPEC.md` | Per-screen specification |
| `docs/07_DESIGN_SYSTEM.md` | Colour, typography, spacing, component rules |
| `docs/09_SCREENS_LOCATION_GUIDE.md` | Which file owns which screen |
| `docs/11_DEPLOYMENT_FLOW.md` | Build, deploy, server topology |
