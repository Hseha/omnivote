import 'package:go_router/go_router.dart';
import '../../features/auth/screens/login_screen.dart';
import '../../features/auth/screens/register_screen.dart';
import '../../features/dashboard/screens/dashboard_screen.dart';
import '../../features/candidates/screens/candidates_list_screen.dart';
import '../../features/candidates/screens/candidate_profile_screen.dart';
import '../../data/models/candidate_model.dart';
import '../../features/voting/screens/vote_now_screen.dart';
import '../../features/ballot/screens/my_ballot_screen.dart';
import '../../features/results/screens/results_screen.dart';
import '../../features/candidacy/screens/candidacy_apply_screen.dart';

class AppRouter {
  static final router = GoRouter(
    initialLocation: '/login',
    routes: [
      GoRoute(
        path: '/',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/register',
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: '/dashboard',
        builder: (context, state) => const DashboardScreen(),
      ),
      GoRoute(
        path: '/candidates',
        builder: (context, state) => const CandidatesListScreen(),
      ),
      GoRoute(
        path: '/candidate-profile',
        builder: (context, state) {
          final candidate = state.extra as Candidate;
          return CandidateProfileScreen(candidate: candidate);
        },
      ),
      GoRoute(
        path: '/vote-now',
        builder: (context, state) => const VoteNowScreen(),
      ),
      GoRoute(
        path: '/ballot',
        builder: (context, state) => const MyBallotScreen(),
      ),
      GoRoute(
        path: '/results',
        builder: (context, state) => const ResultsScreen(),
      ),
      GoRoute(
        path: '/candidacy',
        builder: (context, state) => const CandidacyApplyScreen(),
      ),
    ],
  );
}
