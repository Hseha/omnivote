import React, { useState } from 'react';
import AdminLogin from './AdminLogin';
import AdminDashboard from './AdminDashboard';
import Candidates from './Candidates';
import StudentRegistry from './StudentRegistry';
import ElectionSetup from './ElectionSetup';
import Results from './Results';
import Settings from './Settings';

export default function App() {
  // Always starts unauthenticated (forces user to Login form first)
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [currentView, setCurrentView] = useState('dashboard');

  const handleLogin = () => {
    setIsAuthenticated(true);
    setCurrentView('dashboard');
  };

  const handleLogout = () => {
    setIsAuthenticated(false);
    setCurrentView('dashboard');
  };

  const handleNavigate = (view) => {
    setCurrentView(view);
  };

  // 1. Force display Login Form first when not authenticated
  if (!isAuthenticated) {
    return <AdminLogin onLogin={handleLogin} />;
  }

  // 2. View Router once logged in
  switch (currentView) {
    case 'candidates':
      return (
        <Candidates 
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
    case 'voters':
      return (
        <StudentRegistry 
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
    case 'setup':
      return (
        <ElectionSetup 
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
    case 'results':
      return (
        <Results 
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
    case 'settings':
      return (
        <Settings 
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
    case 'dashboard':
    default:
      return (
        <AdminDashboard 
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
  }
}