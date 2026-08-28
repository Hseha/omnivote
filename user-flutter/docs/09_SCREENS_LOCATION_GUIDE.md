# Screens Location Guide

This guide details where the primary screen implementations and their associated components are located within the `OmniVote` project structure.

## 0. Splash Screen (Entry Point)
The "Door" to the app that handles initial branding and authentication routing.

*   **Screen Implementation**: [splash_screen.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/auth/screens/splash_screen.dart)
*   **Routing Logic**: [app_router.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/routes/app_router.dart)

## 1. Login Screen
The Student Portal login, handling authentication via school email or student ID.

*   **Screen Implementation**: [login_screen.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/auth/screens/login_screen.dart)
*   **Logic/State**: [auth_provider.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/auth/providers/auth_provider.dart)

## 2. Dashboard (Main) Screen
The Dashboard serves as the central hub, displaying registration status, voter turnout, and FAQs.

*   **Screen Implementation**: [dashboard_screen.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/dashboard/screens/dashboard_screen.dart)
*   **Supporting Widgets**:
    *   [registration_details_card.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/dashboard/widgets/registration_details_card.dart)
    *   [turnout_progress.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/dashboard/widgets/turnout_progress.dart)
    *   [eligibility_faq_card.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/features/dashboard/widgets/eligibility_faq_card.dart)

---

## Shared Core Components
Both screens utilize shared constants and global widgets found in the `core` directory:

*   **API Constants**: [api_constants.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/constants/api_constants.dart)
*   **Colors & Tokens**: [app_colors.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/constants/app_colors.dart)
*   **Typography**: [app_text_styles.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/constants/app_text_styles.dart)
*   **Theme**: [app_theme.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/theme/app_theme.dart)
*   **Top Navigation**: [top_bar.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/widgets/top_bar.dart)
*   **Status Indicators**: [status_badge.dart](file:///C:/Users/johnj/omnivote/user-flutter/lib/core/widgets/status_badge.dart)
