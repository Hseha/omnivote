import 'package:go_router/go_router.dart';
import '../../features/auth/screens/login_screen.dart';
import '../../features/auth/screens/register_screen.dart';
import '../../features/dashboard/screens/dashboard_screen.dart';
import '../../features/candidates/screens/candidates_list_screen.dart';
import '../../features/candidates/screens/candidate_profile_screen.dart';
import '../../data/models/candidate_model.dart';

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
    ],
  );
}
