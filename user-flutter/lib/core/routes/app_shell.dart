import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// Root scaffold for the authenticated area of the app (docs/03_APP_FLOW.md).
///
/// Hosts the five primary destinations — Dashboard, Vote Now, Candidates,
/// My Ballot, Election Results — in a bottom navigation bar so each tab keeps
/// its own scroll/selection state while the student moves between them. Each
/// tab screen keeps its own Scaffold/TopBar inside this shell's body.
class AppShell extends StatelessWidget {
  final StatefulNavigationShell navigationShell;

  const AppShell({
    super.key,
    required this.navigationShell,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: navigationShell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: navigationShell.currentIndex,
        onDestinationSelected: (index) {
          navigationShell.goBranch(
            index,
            // Re-tapping the active tab returns to its first screen.
            initialLocation: index == navigationShell.currentIndex,
          );
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard),
            label: 'Dashboard',
          ),
          NavigationDestination(
            icon: Icon(Icons.how_to_vote_outlined),
            selectedIcon: Icon(Icons.how_to_vote),
            label: 'Vote Now',
          ),
          NavigationDestination(
            icon: Icon(Icons.people_outline),
            selectedIcon: Icon(Icons.people),
            label: 'Candidates',
          ),
          NavigationDestination(
            icon: Icon(Icons.receipt_long_outlined),
            selectedIcon: Icon(Icons.receipt_long),
            label: 'My Ballot',
          ),
          NavigationDestination(
            icon: Icon(Icons.bar_chart),
            label: 'Results',
          ),
        ],
      ),
    );
  }
}