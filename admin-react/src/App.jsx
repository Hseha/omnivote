import React, { useState } from 'react';
import AdminLogin from './AdminLogin';
import AdminDashboard from './Admindashboard';
import Candidates from './Candidates';
import StudentRegistry from './StudentRegistry';
import ElectionSetup from './ElectionSetup';
import Results from './Results';
import Settings from './Settings';
import { AuthProvider, useAuth } from './lib/AuthContext';

function AppShell() {
  const { user, ready } = useAuth();
  const [currentView, setCurrentView] = useState('dashboard');

  if (!ready) {
    return null;
  }

  if (!user) {
    return <AdminLogin />;
  }

  switch (currentView) {
    case 'candidates':
      return (
        <Candidates
          onLogout={undefined}
          activeView={currentView}
          onNavigate={setCurrentView}
        />
      );
    case 'voters':
      return (
        <StudentRegistry
          onLogout={undefined}
          activeView={currentView}
          onNavigate={setCurrentView}
        />
      );
    case 'setup':
      return (
        <ElectionSetup
          onLogout={undefined}
          activeView={currentView}
          onNavigate={setCurrentView}
        />
      );
    case 'results':
      return (
        <Results
          onLogout={undefined}
          activeView={currentView}
          onNavigate={setCurrentView}
        />
      );
    case 'settings':
      return (
        <Settings
          onLogout={undefined}
          activeView={currentView}
          onNavigate={setCurrentView}
        />
      );
    case 'dashboard':
    default:
      return (
        <AdminDashboard
          currentUser={user}
          onLogout={undefined}
          activeView={currentView}
          onNavigate={setCurrentView}
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
