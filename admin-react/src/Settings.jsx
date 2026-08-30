import React, { useState, useEffect } from 'react';
import { 
  BarChart2, 
  Users, 
  UserCheck, 
  Sliders, 
  Settings as SettingsIcon, 
  Clock,
  XCircle,
  LogOut
} from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './Settings.css';

export default function Settings({ activeView = 'settings', onNavigate, onLogout }) {
  const { logout } = useAuth();
  const [phase, setPhase] = useState('Voting Open');
  const [announcementText, setAnnouncementText] = useState(
    'Voting is now open! Cast your vote before August 14th.'
  );

  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  useEffect(() => {
    const loadPhase = async () => {
      try {
        const res = await api.get('/election/status');
        const data = res.data?.data ?? res.data ?? {};
        if (data.phase) {
          setPhase(
            data.phase === 'registration'
              ? 'Registration'
              : data.phase === 'voting_open'
                ? 'Voting Open'
                : data.phase === 'voting_closed'
                  ? 'Voting Closed'
                  : data.phase,
          );
        }
      } catch {
        // keep default
      }
    };
    loadPhase();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [users, setUsers] = useState([
    {
      id: 1,
      name: 'Eleanor Vance',
      email: 'e.vance@electboard.org',
      role: 'Admin',
      roleClass: 'role-admin',
      dateAdded: 'Aug 01, 2024',
      isOwner: true,
    },
    {
      id: 2,
      name: 'Dr. Evelyn Ross',
      email: 'e.ross@academy.edu',
      role: 'Teacher',
      roleClass: 'role-teacher',
      dateAdded: 'Aug 02, 2024',
      isOwner: false,
    },
    {
      id: 3,
      name: "Liam O'Connor",
      email: 'l.oconnor@academy.edu',
      role: 'Student',
      roleClass: 'role-student',
      dateAdded: 'Aug 02, 2024',
      isOwner: false,
    },
    {
      id: 4,
      name: 'Sarah Jenkins',
      email: 's.jenkins@academy.edu',
      role: 'Student',
      roleClass: 'role-student',
      dateAdded: 'Aug 01, 2024',
      isOwner: false,
    },
    {
      id: 5,
      name: 'Siddharth Mehta',
      email: 's.mehta@academy.edu',
      role: 'Student',
      roleClass: 'role-student',
      dateAdded: 'Aug 01, 2024',
      isOwner: false,
    },
    {
      id: 6,
      name: 'Prof. Arthur Pendelton',
      email: 'a.pendelton@academy.edu',
      role: 'Teacher',
      roleClass: 'role-teacher',
      dateAdded: 'Aug 04, 2024',
      isOwner: false,
    },
  ]);

  const handleRevokeAccess = (id) => {
    setUsers(users.filter((user) => user.id !== id));
  };

  const handleUpdateAnnouncement = () => {
    alert('Announcement updated successfully!');
  };

  return (
    <div className="settings-app-container">
      {/* Sidebar Navigation */}
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon-bg">
            <XCircle size={22} className="logo-icon" />
          </div>
          <div>
            <h1 className="brand-name">ElectBoard</h1>
            <p className="brand-sub">ELECTION CONSOLE</p>
          </div>
        </div>

        <nav className="nav-menu">
          <button 
            type="button" 
            className={`nav-item ${activeView === 'dashboard' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('dashboard')}
          >
            <BarChart2 size={18} /> Dashboard
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'candidates' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('candidates')}
          >
            <Users size={18} /> Candidates
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'voters' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('voters')}
          >
            <UserCheck size={18} /> Student Registry
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'setup' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('setup')}
          >
            <Sliders size={18} /> Election Setup
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'results' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('results')}
          >
            <BarChart2 size={18} /> Results
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'settings' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('settings')}
          >
            <SettingsIcon size={18} /> Settings
          </button>
        </nav>

        <div className="sidebar-footer-container">
          <button onClick={handleLogout} className="logout-button">
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer">
            <span className="status-dot-green"></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      {/* Main Content */}
      <main className="main-content">
        {/* Top Header */}
        <header className="top-header">
          <div className="breadcrumb">
            <span className="muted">System / </span>
            <strong className="breadcrumb-title">Settings & User Management</strong>
          </div>
          <div className="header-right">
            <span className="voting-status-badge">
              <span className="status-dot-green"></span> {phase}
            </span>
            <div className="system-time">
              <Clock size={16} /> 14:32:05 EST
            </div>
            <div className="user-profile">
              <div className="user-info">
                <span className="user-name">Election Admin</span>
                <span className="user-role">System Administrator</span>
              </div>
              <img 
                src="https://i.pravatar.cc/100?img=32" 
                alt="Eleanor Vance" 
                className="user-avatar" 
              />
            </div>
          </div>
        </header>

        {/* Content Body */}
        <div className="settings-body">
          {/* Card 1: System Announcement */}
          <section className="settings-card">
            <h2 className="card-heading">System Announcement</h2>
            <label className="field-label" htmlFor="announcement-input">
              Current Announcement Text
            </label>
            <textarea
              id="announcement-input"
              className="announcement-textarea"
              rows={3}
              value={announcementText}
              onChange={(e) => setAnnouncementText(e.target.value)}
            />
            <button 
              type="button" 
              className="btn-primary"
              onClick={handleUpdateAnnouncement}
            >
              Update Announcement
            </button>
          </section>

          {/* Card 2: User Access Management */}
          <section className="settings-card">
            <h2 className="card-heading">User Access Management</h2>
            <div className="table-wrapper">
              <table className="user-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Role Badge</th>
                    <th>Date Added</th>
                    <th className="text-right">Access Action</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.id}>
                      <td className="font-bold text-dark">{user.name}</td>
                      <td className="text-muted">{user.email}</td>
                      <td>
                        <span className={`role-badge ${user.roleClass}`}>
                          {user.role}
                        </span>
                      </td>
                      <td className="text-muted">{user.dateAdded}</td>
                      <td className="text-right">
                        {user.isOwner ? (
                          <span className="owner-label">System Owner</span>
                        ) : (
                          <button
                            type="button"
                            className="btn-revoke"
                            onClick={() => handleRevokeAccess(user.id)}
                          >
                            Revoke Access
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>
  );
}