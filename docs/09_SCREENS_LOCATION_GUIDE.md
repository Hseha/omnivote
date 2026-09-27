# 09 — Screens Location Guide

"Which file is screen X?" for the Flutter student app. All paths are relative to `user-flutter/`.

> Links here are repo-relative on purpose. An earlier revision of this file used
> `file:///C:/Users/…` absolute paths from one developer's machine, which resolved for nobody else.

## 1. Auth

| Screen | File | Notes |
|---|---|---|
| Splash | `lib/features/auth/screens/splash_screen.dart` | Decides start destination from stored session |
| Login | `lib/features/auth/screens/login_screen.dart` | Name-slug handle, not an email address. Links to recovery, and surfaces a `Retry-After` wait when the account is under backoff |
| Recover password | `lib/features/auth/screens/recover_password_screen.dart` | Unattended recovery with a registrar activation code — `POST /api/auth/password/reset-with-code`. Reached from the login screen; unauthenticated |
| Change password | `lib/features/auth/screens/change_password_screen.dart` | Clears a registrar-issued temporary password. Reached when `must_change_password` is true |

State: `lib/features/auth/providers/auth_provider.dart`

## 2. Dashboard

`lib/features/dashboard/screens/dashboard_screen.dart` — the central hub.

| Widget | File |
|---|---|
| Welcome banner | `lib/features/dashboard/widgets/welcome_banner.dart` |
| Announcements card | `lib/features/dashboard/widgets/announcements_card.dart` |
| Eligibility FAQ | `lib/features/dashboard/widgets/eligibility_faq.dart` |

Turnout figures are **withheld while polls are open** (see `docs/05_API_INTEGRATION.md`), so this
screen must render without a count until `voting_closed`. There is no `turnout_progress.dart` or
`registration_details_card.dart` — the card was folded into `welcome_banner.dart`.

## 3. Ballot & voting

| Screen | File |
|---|---|
| My ballot | `lib/features/ballot/screens/my_ballot_screen.dart` |
| Vote now | `lib/features/voting/screens/vote_now_screen.dart` |

## 4. Candidates & candidacy

| Screen | File |
|---|---|
| Candidates list | `lib/features/candidates/screens/candidates_list_screen.dart` |
| Candidate profile | `lib/features/candidates/screens/candidate_profile_screen.dart` |
| Candidacy apply | `lib/features/candidacy/screens/candidacy_apply_screen.dart` |

Supporting widget: `lib/features/candidates/widgets/platform_points_list.dart`

## 5. Results

`lib/features/results/screens/results_screen.dart`

## 6. Settings

| Screen | File |
|---|---|
| Settings root | `lib/features/settings/screens/settings_screen.dart` |
| My profile | `lib/features/settings/screens/my_profile_screen.dart` |
| API settings | `lib/features/settings/screens/api_settings_screen.dart` |
| Help / FAQ | `lib/features/settings/screens/help_faq_screen.dart` |

## Shared core

| Concern | File |
|---|---|
| Routing | `lib/core/routes/app_router.dart`, `lib/core/routes/app_shell.dart` |
| Theme | `lib/core/theme/app_theme.dart`, `lib/core/theme/app_tokens.dart` |
| Colors | `lib/core/constants/app_colors.dart` |
| Typography | `lib/core/constants/app_text_styles.dart` |
| API base URL | `lib/core/constants/api_constants.dart` |
| Top navigation | `lib/core/widgets/top_bar.dart` |
| Brand mark | `lib/core/widgets/brand_logo.dart` |
| Candidate card | `lib/core/widgets/candidate_card.dart` |
| Loading / empty states | `lib/core/widgets/loading_indicator.dart`, `lib/core/widgets/empty_state.dart` |
| Cached avatar | `lib/core/widgets/cached_avatar.dart` |

> `lib/core/widgets/status_badge.dart` was removed — status is rendered inline where it is needed.
