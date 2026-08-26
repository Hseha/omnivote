# Screens Location Guide

This guide details where the primary screen implementations and their associated components are located within the `OmniVote` project structure.

## 1. Login Screen
The Login Screen is the entry point for students, handling authentication via school email or student ID.

*   **Screen Implementation**: [login_screen.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/features/auth/screens/login_screen.dart)
*   **Logic/State**: [auth_provider.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/features/auth/providers/auth_provider.dart)

## 2. Dashboard (Main) Screen
The Dashboard serves as the central hub, displaying registration status, voter turnout, and FAQs.

*   **Screen Implementation**: [dashboard_screen.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/features/dashboard/screens/dashboard_screen.dart)
*   **Supporting Widgets**:
    *   [registration_details_card.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/features/dashboard/widgets/registration_details_card.dart)
    *   [turnout_progress.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/features/dashboard/widgets/turnout_progress.dart)
    *   [eligibility_faq_card.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/features/dashboard/widgets/eligibility_faq_card.dart)

---

## Shared Core Components
Both screens utilize shared constants and global widgets found in the `core` directory:

*   **Colors & Tokens**: [app_colors.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/core/constants/app_colors.dart)
*   **Typography**: [app_text_styles.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/core/constants/app_text_styles.dart)
*   **Routing**: [app_router.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/core/routes/app_router.dart)
*   **Top Navigation**: [top_bar.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/core/widgets/top_bar.dart)
*   **Status Indicators**: [status_badge.dart](file:///C:/Users/johnj/StudioProjects/OmniVote/lib/core/widgets/status_badge.dart)
