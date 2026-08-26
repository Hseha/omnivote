import React, { useEffect, useState } from 'react';
import AdminLogin from './AdminLogin';
import AdminDashboard from './Admindashboard';
import Candidates from './Candidates';
import StudentRegistry from './StudentRegistry';
import ElectionSetup from './ElectionSetup';
import Results from './Results';
import Settings from './Settings';

export default function App() {
  const [currentUser, setCurrentUser] = useState(() => {
    try {
      const storedUser = localStorage.getItem('omnivote_user');
      return storedUser ? JSON.parse(storedUser) : null;
    } catch (error) {
      console.error('Failed to read saved user from localStorage', error);
      return null;
    }
  });
  const [currentView, setCurrentView] = useState('dashboard');

  useEffect(() => {
    if (currentUser) {
      localStorage.setItem('omnivote_user', JSON.stringify(currentUser));
      return;
    }

    localStorage.removeItem('omnivote_user');
  }, [currentUser]);

  const handleLogin = (userData) => {
    const user = userData?.user ?? userData;

    if (!user) {
      return;
    }

    setCurrentUser(user);
    setCurrentView('dashboard');
  };

  const handleLogout = () => {
    setCurrentUser(null);
    setCurrentView('dashboard');
  };

  const handleNavigate = (view) => {
    setCurrentView(view);
  };

  if (!currentUser) {
    return <AdminLogin onLogin={handleLogin} />;
  }

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
          currentUser={currentUser}
          onLogout={handleLogout} 
          activeView={currentView} 
          onNavigate={handleNavigate} 
        />
      );
  }
}