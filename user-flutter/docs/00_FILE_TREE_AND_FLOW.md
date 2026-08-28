# 00 — Full File Tree & File Flow

This is the single map of **every file in this repo** and **how files call each other**. Read this alongside `docs/02_FOLDER_STRUCTURE.md` (why the folders are organized this way) and `docs/03_APP_FLOW.md` (what the student sees on screen, in order).

## Full tree

```
omnivote_student_app/
├── README.md
├── docs/
│   ├── 00_FILE_TREE_AND_FLOW.md        ← this file
│   ├── 01_PROJECT_OVERVIEW.md
│   ├── 02_FOLDER_STRUCTURE.md
│   ├── 03_APP_FLOW.md
│   ├── 04_SCREENS_SPEC.md
│   ├── 05_API_INTEGRATION.md
│   ├── 06_STATE_MANAGEMENT.md
│   ├── 07_DESIGN_SYSTEM.md
│   └── 08_BUILD_RELEASE.md
│
├── lib/
│   ├── app.dart                         # 1. entrypoint + router + theme
│   │
│   ├── core/
│   │   ├── constants/
│   │   │   ├── app_colors.dart
│   │   │   ├── app_text_styles.dart
│   │   │   ├── app_spacing.dart
│   │   │   └── api_constants.dart
│   │   ├── routes/
│   │   │   └── app_router.dart
│   │   ├── theme/
│   │   │   └── app_theme.dart
│   │   ├── utils/
│   │   │   ├── validators.dart
│   │   │   └── date_formatter.dart
│   │   └── widgets/
│   │       ├── app_scaffold.dart
│   │       ├── top_bar.dart
│   │       ├── side_nav.dart
│   │       ├── candidate_card.dart
│   │       ├── vote_button.dart
│   │       ├── status_badge.dart
│   │       ├── loading_indicator.dart
│   │       └── empty_state.dart
│   │
│   ├── data/
│   │   ├── models/
│   │   │   ├── student_model.dart
│   │   │   ├── position_model.dart
│   │   │   ├── candidate_model.dart
│   │   │   ├── ballot_model.dart
│   │   │   ├── vote_receipt_model.dart
│   │   │   ├── election_result_model.dart
│   │   │   ├── registration_model.dart
│   │   │   └── candidacy_application_model.dart
│   │   ├── services/
│   │   │   ├── api_client.dart
│   │   │   ├── auth_service.dart
│   │   │   └── secure_storage_service.dart
│   │   └── repositories/
│   │       ├── auth_repository.dart
│   │       ├── registration_repository.dart
│   │       ├── candidate_repository.dart
│   │       ├── vote_repository.dart
│   │       ├── result_repository.dart
│   │       └── candidacy_repository.dart
│   │
│   └── features/
│       ├── auth/
│       │   ├── screens/
│       │   │   ├── login_screen.dart
│       │   │   └── splash_screen.dart
│       │   └── providers/auth_provider.dart
│       ├── dashboard/
│       │   ├── screens/dashboard_screen.dart
│       │   └── widgets/
│       │       ├── registration_details_card.dart
│       │       ├── turnout_progress.dart
│       │       └── eligibility_faq_card.dart
│       ├── candidates/
│       │   ├── screens/
│       │   │   ├── candidates_list_screen.dart
│       │   │   └── candidate_profile_screen.dart
│       │   ├── widgets/
│       │   │   ├── position_tabs.dart
│       │   │   ├── position_filter_bar.dart
│       │   │   └── platform_points_list.dart
│       │   └── providers/candidates_provider.dart
│       ├── voting/
│       │   ├── screens/vote_now_screen.dart
│       │   ├── widgets/
│       │   │   ├── vote_confirmation_dialog.dart
│       │   │   └── senator_multiselect.dart
│       │   └── providers/voting_provider.dart
│       ├── ballot/
│       │   ├── screens/my_ballot_screen.dart
│       │   ├── widgets/ballot_summary_card.dart
│       │   └── providers/ballot_provider.dart
│       ├── results/
│       │   ├── screens/election_results_screen.dart
│       │   ├── widgets/results_bar_chart.dart
│       │   └── providers/results_provider.dart
│       ├── candidacy/
│       │   ├── screens/apply_for_candidacy_screen.dart
│       │   ├── widgets/
│       │   │   ├── candidate_info_form.dart
│       │   │   └── photo_upload_field.dart
│       │   └── providers/candidacy_provider.dart
│       ├── help/
│       │   └── screens/help_faq_screen.dart
│       └── profile/
│           └── screens/my_profile_screen.dart
│
├── assets/
│   ├── images/
│   ├── icons/
│   └── fonts/
│
├── test/
│   └── features/                        # mirrors lib/features/ 1:1
│
├── pubspec.yaml
└── analysis_options.yaml
```

## File flow — who calls whom

Data only ever flows in **one direction**, left to right:

```
Screen (features/*/screens)
   │  reads/writes
   ▼
Provider (features/*/providers)
   │  calls
   ▼
Repository (data/repositories)
   │  calls
   ▼
Service (data/services → api_client.dart)
   │  HTTP
   ▼
Laravel API
```

- **Screens** never import `data/services` directly — only their own feature's `providers/`.
- **Providers** never build widgets — only hold state and call `repositories/`.
- **Repositories** never know about `Dio`/HTTP directly — they call `data/services/api_client.dart`, which is the only file that owns the HTTP client.
- **Models** (`data/models/`) are imported everywhere (screens, providers, repositories, services) — they're the shared vocabulary, not layer-specific.
- **`core/widgets/`** are imported by any screen, but never import a `features/` file back — keeps them reusable.
- **`core/routes/app_router.dart`** is the only file that imports every screen, since it's the map from route name → screen widget. No screen imports another screen directly; navigation always goes through the router.

## Boot sequence (first files touched, in order)

1. `app.dart` — initializes bindings, storage, runs `App`
2. `app_router.dart` — decides the first route based on `auth_provider.dart` (logged in → Dashboard, else → Login)
3. First screen (`splash_screen.dart`) — pulls its data through its provider → repository → service chain above

**IMPLEMENTATION STATUS**:
- [x] API Client & Secure Storage (Base infrastructure for Laravel integration).
- [x] Auth Service & Repository (REST API connection to Laravel Auth).
- [x] Splash Screen (Initial "Door" entry point with Auth check).
- [x] Login Screen connected to Dashboard Screen (Navigation flow + Auth Logic).

## Per-screen file group (how to find everything for one screen)

For any screen in `docs/04_SCREENS_SPEC.md`, its full file group lives together:

```
features/<name>/
├── screens/<name>_screen.dart      ← the screen itself
├── widgets/*.dart                  ← pieces only that screen uses
└── providers/<name>_provider.dart  ← its state, backed by a data/repositories/*.dart
```

Anything reused by **two or more** feature folders belongs in `core/widgets/` instead (e.g. `candidate_card.dart` is used by both `candidates_list_screen.dart` and, in a read-only mode, `my_ballot_screen.dart` — so it lives in `core/`, not inside `features/candidates/`).
