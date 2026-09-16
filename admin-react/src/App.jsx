import { useState } from 'react';
import AdminLogin from './AdminLogin';
import AdminDashboard from './Admindashboard';
import Candidates from './Candidates';
import StudentRegistry from './StudentRegistry';
import ElectionSetup from './ElectionSetup';
import Results from './Results';
import Settings from './Settings';
import SsgPresident from './SsgPresident';
import { AuthProvider, useAuth } from './lib/AuthContext';
import { allowedViews, defaultView } from './lib/permissions';

function AppShell() {
  const { user, ready } = useAuth();
  const [currentView, setCurrentView] = useState('dashboard');

  if (!ready) {
    return null;
  }

  if (!user) {
    return <AdminLogin />;
  }

  const permittedViews = allowedViews(user.role);
  const navigate = (view) => {
    if (permittedViews.includes(view)) setCurrentView(view);
  };
  const activeView = permittedViews.includes(currentView)
    ? currentView
    : defaultView(user.role);

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
        />
      );
    case 'voters':
      return (
        <StudentRegistry
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
        />
      );
    case 'setup':
      return (
        <ElectionSetup
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
        />
      );
    case 'results':
      return (
        <Results
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
        />
      );
    case 'settings':
      return (
        <Settings
          onLogout={undefined}
          activeView={currentView}
          onNavigate={navigate}
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
}

export default function App() {
  return (
    <AuthProvider>
      <AppShell />
    </AuthProvider>
  );
}
