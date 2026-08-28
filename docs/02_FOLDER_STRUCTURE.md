# 02 — Folder Structure

This project uses a **feature-first** structure: shared/reusable code lives in `core/` and `data/`, and everything screen-specific lives under `features/<feature_name>/`.

```
omnivote_student_app/
├── docs/                        # you are reading this folder
├── assets/
│   ├── images/                  # onboarding art, empty states, logo
│   ├── icons/                   # custom icons not covered by an icon font
│   └── fonts/
├── lib/
│   ├── main.dart                # entrypoint
│   ├── app.dart                 # MaterialApp / router / theme wiring
│   │
│   ├── core/                    # things every feature depends on
│   │   ├── constants/           # colors, text styles, spacing, API base URL + paths
│   │   ├── routes/              # app_router.dart — single source of route names
│   │   ├── theme/               # ThemeData assembly
│   │   ├── utils/                # validators, date/time formatting
│   │   └── widgets/              # shared components used across 2+ features:
│   │                              #   app_scaffold, top_bar, side_nav (→ bottom nav on
│   │                              #   mobile), candidate_card, vote_button, status_badge,
│   │                              #   loading_indicator, empty_state
│   │
│   ├── data/                    # everything that talks to or models the Laravel API
│   │   ├── models/               # plain Dart data classes (student, candidate, position,
│   │                              #   ballot, vote_receipt, election_result, registration,
│   │                              #   candidacy_application)
│   │   ├── services/             # api_client (Dio + interceptors), auth_service,
│   │                              #   secure_storage_service
│   │   └── repositories/         # one repository per domain area; screens/providers only
│   │                              #   ever talk to repositories, never to services directly
│   │
│   ├── features/                # one folder per screen-group. Each feature folder has
│   │   │                         #   its own screens/, widgets/, providers/ subfolders —
│   │   │                         #   only create the subfolders a feature actually needs.
│   │   ├── auth/                 # login
│   │   ├── dashboard/            # registration status + turnout (the "Voter Registration"
│   │   │                         #   screen shown on first launch after login)
│   │   ├── candidates/           # candidate list (all positions, both tiers) + profile
│   │   ├── voting/               # guided "Vote Now" ballot flow
│   │   ├── ballot/               # "My Ballot" review/submit screen
│   │   ├── results/               # "Election Results" screen
│   │   ├── candidacy/            # "Apply for Candidacy" form
│   │   ├── help/                 # "Help & FAQ" screen
│   │   └── profile/              # student's own profile (behind the avatar)
│   │
│   └── ... (no other top-level folders under lib/)
│
├── test/
│   └── features/                # mirror the features/ tree 1:1 for widget/unit tests
├── pubspec.yaml
└── analysis_options.yaml
```

## Rules for adding new code

1. **A screen only lives in `features/<its feature>/screens/`.** If two features need the exact same screen, that's a sign it should be a shared widget in `core/widgets/`, driven by a parameter, instead of being duplicated.
2. **Never import one feature's `providers/` or `widgets/` from another feature.** Cross-feature communication goes through `core/routes` (navigation) or `data/repositories` (shared data), never widget-to-widget.
3. **All network calls go: Screen → Provider → Repository → Service (Dio) → Laravel API.** Screens never call `ApiClient` directly.
4. **Position-driven screens are parameterized, not duplicated.** e.g. `candidates_list_screen.dart` takes a `Position` (or list of positions) argument and renders President/VP/Secretary/... using the same code, rather than one file per position. See `docs/04_SCREENS_SPEC.md` for the full position list.
5. **Naming:** files `snake_case.dart`, classes `PascalCase`, one public widget per file matching the filename.
