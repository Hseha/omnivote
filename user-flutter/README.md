# OmniVote — Student App (Flutter)

This repo is the **student-facing client only**, built with **Flutter** and shipped as an **APK** (Android) for students to register, browse candidates, vote, and view results.

It is one of three pieces of the overall OmniVote system:

| Layer | Tech | Owner / Repo |
|---|---|---|
| Student client (this repo) | **Flutter** | you are here |
| Admin / Election Committee web client | **React** | separate repo |
| Backend API | **Laravel** | separate repo |

This Flutter app talks to the Laravel backend **only through the REST API** described in [`docs/05_API_INTEGRATION.md`](docs/05_API_INTEGRATION.md). It does not share code with the React admin app — they are independent clients of the same API.

## Start here (read in order)

1. [`docs/01_PROJECT_OVERVIEW.md`](docs/01_PROJECT_OVERVIEW.md) — what OmniVote is, who uses this app, scope boundaries
2. [`docs/02_FOLDER_STRUCTURE.md`](docs/02_FOLDER_STRUCTURE.md) — where everything lives and why
3. [`docs/03_APP_FLOW.md`](docs/03_APP_FLOW.md) — screen-by-screen navigation flow, derived from the reference UI
4. [`docs/04_SCREENS_SPEC.md`](docs/04_SCREENS_SPEC.md) — exact spec for every screen/widget
5. [`docs/05_API_INTEGRATION.md`](docs/05_API_INTEGRATION.md) — Laravel endpoints this app expects
6. [`docs/06_STATE_MANAGEMENT.md`](docs/06_STATE_MANAGEMENT.md) — state approach and provider map
7. [`docs/07_DESIGN_SYSTEM.md`](docs/07_DESIGN_SYSTEM.md) — colors, type, spacing, component rules pulled from the reference screens
8. [`docs/08_BUILD_RELEASE.md`](docs/08_BUILD_RELEASE.md) — how to build the release APK

## Current state of this repo

`lib/` already contains the full **feature-first folder skeleton** with stub files (each has a `PURPOSE` comment). Nothing is implemented yet — this is a scaffold for an AI coding agent or developer to fill in, screen by screen, following `docs/04_SCREENS_SPEC.md`.
