# OmniVote Folder-to-File Mapping

This document maps the project directories to the specific Dart files implemented for the Login and Dashboard features.

```text
lib/
├── core/
│   ├── constants/
│   │   ├── app_colors.dart           # UI Color tokens (Blue, Navy, etc.)
│   │   └── app_text_styles.dart      # Typography scale (Titles, Body, Tags)
│   ├── routes/
│   │   └── app_router.dart           # Navigation map (GoRouter config)
│   └── widgets/
│       ├── top_bar.dart              # Shared header (Clock, Status)
│       └── status_badge.dart         # "Voting Open" indicator
│
├── features/
│   ├── auth/                         # Login Feature
│   │   └── screens/
│   │       └── login_screen.dart     # Login UI & Form Validation
│   │
│   └── dashboard/                    # Dashboard Feature
│       ├── screens/
│       │   └── dashboard_screen.dart # Main Screen layout
│       └── widgets/
│           ├── registration_details_card.dart # Student info display
│           └── turnout_progress.dart          # Turnout statistics bars
│
├── data/                             # Data Layer
│   └── models/
│       ├── student_model.dart        # Student data structure
│       ├── candidate_model.dart      # Candidate data structure
│       └── position_model.dart       # Position/Tier definition
│
├── app.dart                          # Root Widget (MaterialApp)
└── main.dart                         # Entry point (runApp)
```
