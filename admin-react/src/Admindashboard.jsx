import React, { useState, useEffect } from 'react';
import './AdminDashboard.css';
import axios from 'axios';
import { 
  LayoutDashboard, 
  Users, 
  UserCheck, 
  Sliders, 
  BarChart2, 
  Settings, 
  Database, 
  CheckCircle, 
  Clock, 
  Award, 
  Plus,
  LogOut,
  Vote
} from 'lucide-react';

export default function AdminDashboard({ onLogout, activeView = 'dashboard', onNavigate, currentUser = null }) {
  const [statsData, setStatsData] = useState({
    total_voters: 0,
    votes_cast: 0,
    turnout_rate: 0,
    approved_candidates: 0,
  });
  const [announcements, setAnnouncements] = useState([]);
  const [recentActions, setRecentActions] = useState([]);
  const [electionPhase, setElectionPhase] = useState('Voting Open');
  const [userProfile, setUserProfile] = useState({
    time : '14:32:05 EST',
    name: 'Election Admin',
    role: 'System Administrator',
    avatar: 'https://i.pravatar.cc/100?img=32',
  });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (currentUser) {
      const normalizedRole = currentUser.role === 'admin'
        ? 'System Administrator'
        : currentUser.role === 'teacher'
          ? 'Teacher'
          : currentUser.role || 'System Administrator';

      setUserProfile((previous) => ({
        ...previous,
        name: currentUser.name || previous.name,
        role: normalizedRole,
      }));
    }
  }, [currentUser]);

  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true);
        const response = await axios.get('http://localhost:8000/api/admin/dashboard-overview', {
          withCredentials: true,
          headers: { 'Accept': 'application/json' },
        });

        const data = response.data;
        if (data.stats) setStatsData(data.stats);
        if (data.announcements) setAnnouncements(data.announcements);
        if (data.recent_actions) setRecentActions(data.recent_actions);
        if (data.election_phase) setElectionPhase(data.election_phase);
        if (data.user) setUserProfile(data.user);
      } catch (error) {
        console.warn('Backend API connection pending or unavailable:', error.message);
      } finally {
        setLoading(false);
      }
    };

    fetchDashboardData();
  }, []);

  const stats = [
    { 
      title: 'TOTAL REGISTERED VOTERS', 
      value: (statsData.total_voters || 0).toLocaleString(), 
      icon: <Database className="stat-icon blue" /> 
    },
    { 
      title: 'VOTES CAST', 
      value: (statsData.votes_cast || 0).toLocaleString(), 
      icon: <CheckCircle className="stat-icon green" /> 
    },
    { 
      title: 'TURNOUT RATE', 
      value: `${statsData.turnout_rate || 0}%`, 
      icon: <Clock className="stat-icon orange" /> 
    },
    { 
      title: 'APPROVED CANDIDATES', 
      value: (statsData.approved_candidates || 0).toLocaleString(), 
      icon: <Award className="stat-icon purple" /> 
    },
  ];

  return (
    <div className="dashboard-container">
      {/* Sidebar Navigation */}
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon">
            <Vote size={20} />
          </div>
          <div>
            <h1 className="brand-name">OmniVote</h1>
            <p className="brand-sub">ELECTION CONSOLE</p>
          </div>
        </div>

        <nav className="nav-menu">
          <button 
            type="button" 
            className={`nav-item ${activeView === 'dashboard' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('dashboard')}
          >
            <LayoutDashboard size={18} /> Dashboard
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
            <Settings size={18} /> Settings
          </button>
        </nav>

        {/* Sidebar Footer */}
        <div className="sidebar-footer-container">
          <button onClick={onLogout} className="logout-button">
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer">
            <span className="status-dot-green"></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
      <main className="main-content">
        <header className="top-header">
          <div className="breadcrumb">
            <span className="muted">Admin /</span> 
            <strong className='Dash'>Dashboard Overview</strong>

          </div>
          <div className="header-actions">
            <span className="badge-open">
              <span className="dot"></span> {electionPhase}
            </span>
            <div className="user-profile">
              <Clock size={16} />
              <span className="user-time">{userProfile.time}</span>
              <div className="user-info">
                
                <span className="user-name">{userProfile.name}</span>
                <span className="user-role">{userProfile.role}</span>
              </div>
              <img 
                src={userProfile.avatar} 
                alt={userProfile.name} 
                className="avatar" 
              />
            </div>
          </div>
        </header>

        {/* Dynamic Metric Cards */}
        <section className="stats-grid">
          {stats.map((stat, idx) => (
            <div key={idx} className="stat-card">
              <div className="stat-header">
                <span className="stat-title">{stat.title}</span>
                {stat.icon}
              </div>
              <div className="stat-value">{loading ? "..." : stat.value}</div>
            </div>
          ))}
        </section>

        {/* Middle Section */}
        <section className="middle-grid">
          <div className="card chart-card">
            <div className="card-header">
              <div>
                <h3>Voting Activity Timeline</h3>
                <p className="muted-text">Hourly accumulation of votes processed today</p>
              </div>
              <div className="chart-legend">
                <span className="legend-dot"></span> Votes Cast
              </div>
            </div>
            <div className="chart-placeholder">
              <svg viewBox="0 0 500 150" className="chart-svg">
                <polyline
                  fill="none"
                  stroke="#3b82f6"
                  strokeWidth="3"
                  points="20,110 80,95 140,105 200,70 260,78 320,45 380,55 440,30"
                />
              </svg>
              <div className="chart-x-axis">
                <span>08:00</span><span>10:00</span><span>12:00</span><span>14:00</span>
                <span>16:00</span><span>18:00</span><span>20:00</span><span>22:00</span>
              </div>
            </div>
          </div>

          <div className="card announcements-card">
            <div className="card-header">
              <h3>Admin Announcements</h3>
              <a href="#new" className="new-post-link"><Plus size={14} /> New Post</a>
            </div>
            <div className="announcements-list">
              {announcements.length === 0 ? (
                <p className="no-data-text">
                  {loading ? "Loading announcements..." : "No announcements posted."}
                </p>
              ) : (
                announcements.map((item, idx) => (
                  <div key={item.id || idx} className="announcement-item">
                    <div className="announcement-meta">
                      <span className={`tag ${(item.type || 'SYSTEM').toLowerCase()}`}>
                        • {item.type}
                      </span>
                      <span className="time">{item.time_ago || item.time}</span>
                    </div>
                    <p className="announcement-text">{item.text}</p>
                  </div>
                ))
              )}
            </div>
          </div>
        </section>

        {/* Action Logs */}
        <section className="card table-card">
          <h3>Recent System Actions</h3>
          <table className="actions-table">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Action Description</th>
                <th>Triggered By</th>
              </tr>
            </thead>
            <tbody>
              {recentActions.length === 0 ? (
                <tr>
                  <td colSpan="3" className="no-data-cell">
                    {loading ? "Fetching activity log..." : "No recent system actions logged."}
                  </td>
                </tr>
              ) : (
                recentActions.map((row, idx) => (
                  <tr key={row.id || idx}>
                    <td className="timestamp">{row.timestamp || row.time}</td>
                    <td className="action">{row.description || row.action}</td>
                    <td className="trigger">{row.triggered_by || row.trigger}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </section>
      </main>
    </div>
  );
}