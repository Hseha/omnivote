import { useState, useEffect, useCallback } from 'react';
import AdminLogin from './AdminLogin';
import ForgotPassword from './ForgotPassword';
import ResetPassword from './ResetPassword';
import AdminDashboard from './AdminDashboard';
import Candidates from './Candidates';
import Announcements from './Announcements';
import StudentRegistry from './StudentRegistry';
import ElectionSetup from './ElectionSetup';
import Results from './Results';
import ElectionRecords from './ElectionRecords';
import Settings from './Settings';
import UserManagement from './UserManagement';
import Departments from './Departments';
import AuditLog from './AuditLog';
import SsgPresident from './SsgPresident';
import SetupConsole from './SetupConsole';
import TwoFactorEnrollmentScreen from './TwoFactorEnrollmentScreen';
import ErrorBoundary from './components/ErrorBoundary';
import OmniVoteMark from './components/OmniVoteMark';
import { AuthProvider, useAuth } from './lib/AuthContext';
import { IS_SSG_CONSOLE } from './lib/api';
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
  records: 'Election Records',
  announcements: 'Announcements',
  audit: 'Audit Log',
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

  // --- SSG President console onboarding -----------------------------------
  // The setup link (/ssg/setup?token=...) is public; a signed-in President who
  // was promoted while already holding a student credential lands here too
  // (ssg_setup_pending), where the gate blocks the console until they finish.
  // Captured once so clearing ?token= from the URL after a successful setup
  // does not blank the token out from under the (still-mounted) screen.
  const [setupToken] = useState(() => new URLSearchParams(window.location.search).get('token'));
  const onSetupRoute = IS_SSG_CONSOLE && /^\/ssg\/setup\/?$/.test(window.location.pathname);
  const needsConsoleSetup =
    onSetupRoute || (IS_SSG_CONSOLE && Boolean(user?.ssg_setup_pending));

  // Drop ?token= after a successful setup so the one-time link cannot be
  // replayed from browser history once it has been burned server-side.
  const clearSetupLink = useCallback(() => {
    const url = new URL(window.location.href);
    url.search = '';
    window.history.replaceState({}, '', url);
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

  // Session bootstrap is in flight (validating a cached session against the
  // server). Show a branded loading screen instead of returning null, so a
  // slow or briefly unreachable API shows "loading" instead of a blank white
  // page. The global request timeout in lib/api.js guarantees this resolves.
  if (!ready) {
    return <BootScreen siteName={siteName} />;
  }

  // Onboarding takes precedence over both the login screen and the shell: a
  // pending President cannot use the console until the setup link is redeemed.
  if (needsConsoleSetup) {
    return <SetupConsole token={onSetupRoute ? setupToken : null} onCompleted={clearSetupLink} />;
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
    switch (activeView) {
    case 'ssg':
      return (
        <SsgPresident
          onLogout={undefined}
          /* The computed view, not the stored one: the shell boots on
             'dashboard', which the President's role does not hold, so the
             stored view and the permitted default differ until the first
             navigation. Passing the computed value keeps the sidebar's
             active entry correct from the first paint. */
          activeView={activeView}
          onNavigate={navigate}
          currentUser={user}
        />
      );
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
    case 'records':
      return (
        <ElectionRecords
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
    case 'audit':
      return (
        <AuditLog
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

/*
 * First-paint placeholder shown while the cached session is validated against
 * the server. Static markup only — it must render even before the API answers.
 */
function BootScreen({ siteName }) {
  return (
    <div className="boot-screen" role="status" aria-live="polite">
      <div className="boot-brand">
        <OmniVoteMark className="boot-logo" />
        <span className="boot-brand-name">{siteName}</span>
      </div>
      <div className="boot-spinner" aria-hidden="true" />
      <p className="boot-text">Loading console…</p>
    </div>
  );
}

export default function App() {
  return (
    <ErrorBoundary>
      <ThemeProvider>
        <AuthProvider>
          <AppShell />
        </AuthProvider>
      </ThemeProvider>
    </ErrorBoundary>
  );
}
