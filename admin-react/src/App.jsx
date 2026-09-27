import { useState, useEffect, useCallback } from 'react';
import AdminLogin from './AdminLogin';
import ForgotPassword from './ForgotPassword';
import ResetPassword from './ResetPassword';
import AdminDashboard from './Admindashboard';
import Candidates from './Candidates';
import Announcements from './Announcements';
import StudentRegistry from './StudentRegistry';
import ElectionSetup from './ElectionSetup';
import Results from './Results';
import Settings from './Settings';
import UserManagement from './UserManagement';
import Departments from './Departments';
import SsgPresident from './SsgPresident';
import TwoFactorEnrollmentScreen from './TwoFactorEnrollmentScreen';
import { AuthProvider, useAuth } from './lib/AuthContext';
import { ThemeProvider } from './lib/ThemeContext';
import { ElectionStatusProvider } from './lib/ElectionStatusContext';
import { useBranding } from './lib/branding';
import { allowedViews, defaultView } from './lib/permissions';

/* Unique, descriptive browser-tab title per role/route. The suffix reuses the
 * branded site name so an admin-updated site name is reflected here too. */
const ROUTE_TITLES = {
  ssg: 'SSG President Dashboard',
  dashboard: 'Dashboard',
  candidates: 'Candidates',
  voters: 'Student Registry',
  setup: 'Election Setup',
  results: 'Results',
  announcements: 'Announcements',
  settings: 'Settings',
  user_management: 'User Management',
  departments: 'Departments',
};

function useDocumentTitle(routeTitle, siteName) {
  useEffect(() => {
    const title = routeTitle ? `${routeTitle} · ${siteName}` : siteName || 'OmniVote';
    document.title = title;
  }, [routeTitle, siteName]);
}

function AppShell() {
  const { user, ready } = useAuth();
  const branding = useBranding();
  const siteName = branding.siteName || 'OmniVote';
  const [currentView, setCurrentView] = useState('dashboard');
  // Lets the dashboard phase prompt drop an admin directly on the Voting
  // Windows tab; plain "Settings" navigation resets to the primary tab
  // (My Profile).
  const [settingsTab, setSettingsTab] = useState('profile');
  // Public auth screens: 'login' | 'forgot' | 'reset'.
  const [authMode, setAuthMode] = useState(() => {
    const params = new URLSearchParams(window.location.search);
    return params.get('token') && params.get('email') ? 'reset' : 'login';
  });
  const [resetParams, setResetParams] = useState(() => {
    const params = new URLSearchParams(window.location.search);
    return params.get('token') && params.get('email')
      ? { token: params.get('token'), email: params.get('email') }
      : null;
  });

  // After a successful reset, strip ?token= & ?email= from the URL so the
  // link can't be replayed / the password re-set from the shared link.
  const clearResetLink = useCallback(() => {
    const url = new URL(window.location.href);
    url.search = '';
    window.history.replaceState({}, '', url);
    setResetParams(null);
    setAuthMode('login');
  }, []);

  // Unique browser-tab title per role/route. Computed up front and applied
  // through one unconditional hook so the Rules of Hooks are never violated
  // by the early returns below (login screen → 2FA interstitial → panel).
  const permittedViews = allowedViews(user?.role);
  const activeView = !user
    ? null
    : permittedViews.includes(currentView)
      ? currentView
      : defaultView(user.role);
  const routeTitle = !user
    ? 'Sign In'
    : user.two_factor_required && !user.two_factor_enabled
      ? 'Two-Factor Setup'
      : ROUTE_TITLES[activeView] || 'Dashboard';
  useDocumentTitle(routeTitle, siteName);

  if (!ready) {
    return null;
  }

  if (!user) {
    if (authMode === 'forgot') {
      return <ForgotPassword onBack={() => setAuthMode('login')} />;
    }
    if (authMode === 'reset' && resetParams) {
      return (
        <ResetPassword
          token={resetParams.token}
          email={resetParams.email}
          onCompleted={clearResetLink}
        />
      );
    }
    return <AdminLogin onForgot={() => setAuthMode('forgot')} />;
  }

  // Security → 2FA Required: an enrolled staff member passes straight through;
  // anyone who is not yet enrolled lands on the mandatory setup screen instead
  // of a half-working panel (the server rejects their panel calls anyway).
  if (user.two_factor_required && !user.two_factor_enabled) {
    return <TwoFactorEnrollmentScreen />;
  }

  // Canonical view ids. `users` is accepted as an alias for
  // `user_management` (User Access lives under Student Registry) so stale
  // links can never land on a dead/unknown view.
  const canonicalView = (view) => (view === 'users' ? 'user_management' : view);
  const navigate = (view, settingsTab_) => {
    const target = canonicalView(view);
    if (permittedViews.includes(target)) {
      if (target === 'settings') {
        setSettingsTab(settingsTab_ ?? 'profile');
      }
      setCurrentView(target);
    }
  };

  const renderActiveView = () => {
    if (activeView === 'ssg') {
      return <SsgPresident onLogout={undefined} />;
    }

    switch (activeView) {
    case 'candidates':
      return (
        <Candidates
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'voters':
      return (
        <StudentRegistry
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'setup':
      return (
        <ElectionSetup
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'results':
      return (
        <Results
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'announcements':
      return (
        <Announcements
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'settings':
      return (
        <Settings
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          initialTab={settingsTab}
        />
      );
    case 'user_management':
      return (
        <UserManagement
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'departments':
      return (
        <Departments
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
    case 'dashboard':
    default:
      return (
        <AdminDashboard
          currentUser={user}
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
        />
      );
    }
  };

  // The phase provider polls `/election/status` every 30 s and is shared by
  // the header badge, dashboard phase card, and Results gating.
  return <ElectionStatusProvider>{renderActiveView()}</ElectionStatusProvider>;
}

export default function App() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <AppShell />
      </AuthProvider>
    </ThemeProvider>
  );
}
