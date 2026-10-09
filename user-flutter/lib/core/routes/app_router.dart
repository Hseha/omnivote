import 'package:flutter/foundation.dart';
import 'package:go_router/go_router.dart';
import '../../features/auth/screens/login_screen.dart';
import '../../features/auth/screens/splash_screen.dart';
import '../../features/auth/screens/change_password_screen.dart';
import '../../features/auth/screens/recover_password_screen.dart';
import '../../features/dashboard/screens/dashboard_screen.dart';
import '../../features/candidates/screens/candidates_list_screen.dart';
import '../../features/candidates/screens/candidate_profile_screen.dart';
import '../../data/models/candidate_model.dart';
import '../../features/voting/screens/vote_now_screen.dart';
import '../../features/ballot/screens/my_ballot_screen.dart';
import '../../features/results/screens/results_screen.dart';
import '../../features/candidacy/screens/candidacy_apply_screen.dart';
import '../../features/notifications/screens/notifications_screen.dart';
import '../../features/settings/screens/api_settings_screen.dart';
import '../../features/settings/screens/my_profile_screen.dart';
import '../../features/settings/screens/help_faq_screen.dart';
import '../../features/settings/screens/settings_screen.dart';
import 'app_shell.dart';

class AppRouter {
  // Splash is the bootstrap route: it checks auth once and redirects to
  // /dashboard or /login (audit §2 #11).
  //
  // Once authenticated, the five primary destinations (docs/03_APP_FLOW.md)
  // live inside a StatefulShellRoute so the bottom navigation bar persists and
  // each tab keeps its own state. Candidate Profile, Candidacy, Help & FAQ,
  // My Profile and API Settings sit above the shell as pushed routes.
  static final router = GoRouter(
    initialLocation: '/splash',
    routes: [
      GoRoute(
        path: '/splash',
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: '/',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/change-password',
        builder: (context, state) => const ChangePasswordScreen(),
      ),
      // Self-service recovery for a locked-out student (assessment M-3). Lives
      // outside the authenticated shell because the student has no session yet.
      GoRoute(
        path: '/recover-password',
        builder: (context, state) => const RecoverPasswordScreen(),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/dashboard',
                builder: (context, state) => const DashboardScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/vote-now',
                builder: (context, state) => const VoteNowScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/candidates',
                builder: (context, state) => const CandidatesListScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/ballot',
                builder: (context, state) => const MyBallotScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/results',
                builder: (context, state) => const ResultsScreen(),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: '/candidate-profile',
        builder: (context, state) {
          final candidate = state.extra as Candidate;
          return CandidateProfileScreen(candidate: candidate);
        },
      ),
      GoRoute(
        path: '/candidacy',
        builder: (context, state) => const CandidacyApplyScreen(),
      ),
      // Notification center, pushed above the shell (bell + Settings entry).
      GoRoute(
        path: '/notifications',
        builder: (context, state) => const NotificationsScreen(),
      ),
      if (kDebugMode)
        // Dev-only runtime base-URL editor. Registered only in debug builds so
        // a release app cannot be deep-linked into repointing at another host.
        GoRoute(
          path: '/api-settings',
          builder: (context, state) => const ApiSettingsScreen(),
        ),
      GoRoute(
        path: '/settings',
        builder: (context, state) => const SettingsScreen(),
      ),
      GoRoute(
        path: '/profile',
        builder: (context, state) => const MyProfileScreen(),
      ),
      GoRoute(
        path: '/faq',
        builder: (context, state) => const HelpFaqScreen(),
      ),
    ],
  );
}