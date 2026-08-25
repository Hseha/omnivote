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
  LogOut
} from 'lucide-react';

export default function AdminDashboard() {
  // 1. Dynamic State Definitions
  const [statsData, setStatsData] = useState({
    total_voters: 0,
    votes_cast: 0,
    turnout_rate: 0,
    approved_candidates: 0,
  });
  const [announcements, setAnnouncements] = useState([]);
  const [recentActions, setRecentActions] = useState([]);
  const [electionPhase, setElectionPhase] = useState('Loading...');
  const [userProfile, setUserProfile] = useState({
    name: 'Election Admin',
    role: 'System Administrator',
    avatar: 'https://i.pravatar.cc/100?img=32',
  });
  const [loading, setLoading] = useState(true);

  // 2. Data Fetching via Axios (Prepared for Laravel Sanctum)
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

  // 3. Logout Handler
  const handleLogout = async () => {
    try {
      await axios.post('http://localhost:8000/api/logout', {}, {
        withCredentials: true,
        headers: { 'Accept': 'application/json' },
      });
      window.location.href = '/login';
    } catch (error) {
      console.error('Logout error:', error);
      window.location.href = '/login';
    }
  };

  // Structured metrics derived from state
  const stats = [
    { 
      title: 'TOTAL REGISTERED VOTERS', 
      value: (statsData.total_voters || 0).toLocaleString(), 
      icon: <Database style={{ color: '#3b82f6' }} /> 
    },
    { 
      title: 'VOTES CAST', 
      value: (statsData.votes_cast || 0).toLocaleString(), 
      icon: <CheckCircle style={{ color: '#10b981' }} /> 
    },
    { 
      title: 'TURNOUT RATE', 
      value: `${statsData.turnout_rate || 0}%`, 
      icon: <Clock style={{ color: '#f59e0b' }} /> 
    },
    { 
      title: 'APPROVED CANDIDATES', 
      value: (statsData.approved_candidates || 0).toLocaleString(), 
      icon: <Award style={{ color: '#8b5cf6' }} /> 
    },
  ];

  return (
    <div className="dashboard-container" style={styles.container}>
      {/* Sidebar Navigation */}
      <aside className="sidebar" style={styles.sidebar}>
        <div className="logo-area" style={styles.logoArea}>
          <div className="logo-icon" style={styles.logoIcon}>☒</div>
          <div>
            <h1 className="brand-name" style={styles.brandName}>ElectBoard</h1>
            <p className="brand-sub" style={styles.brandSub}>ELECTION CONSOLE</p>
          </div>
        </div>

        <nav className="nav-menu" style={styles.navMenu}>
          <a href="#dashboard" className="nav-item active" style={{...styles.navItem, ...styles.navItemActive}}>
            <LayoutDashboard size={18} /> Dashboard
          </a>
          <a href="#candidates" className="nav-item" style={styles.navItem}><Users size={18} /> Candidates</a>
          <a href="#voters" className="nav-item" style={styles.navItem}><UserCheck size={18} /> Voter Registry</a>
          <a href="#setup" className="nav-item" style={styles.navItem}><Sliders size={18} /> Election Setup</a>
          <a href="#results" className="nav-item" style={styles.navItem}><BarChart2 size={18} /> Results</a>
          <a href="#settings" className="nav-item" style={styles.navItem}><Settings size={18} /> Settings</a>
        </nav>

        {/* Sidebar Footer with Logout & System Status */}
        <div className="sidebar-footer-container" style={styles.sidebarFooterContainer}>
          <button onClick={handleLogout} className="logout-button" style={styles.logoutBtn}>
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer" style={styles.sidebarFooter}>
            <span style={styles.statusDot}></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
      <main className="main-content" style={styles.mainContent}>
        {/* Top Header */}
        <header className="top-header" style={styles.topHeader}>
          <div className="breadcrumb" style={styles.breadcrumb}>
            <span style={{ color: '#64748b' }}>System /</span> <strong>Dashboard Overview</strong>
          </div>
          <div className="header-actions" style={styles.headerActions}>
            <span className="badge-open" style={styles.badgeOpen}>
              <span style={styles.badgeDot}></span> {electionPhase}
            </span>
            <div className="user-profile" style={styles.userProfile}>
              <div className="user-info" style={styles.userInfo}>
                <span style={styles.userName}>{userProfile.name}</span>
                <span style={styles.userRole}>{userProfile.role}</span>
              </div>
              <img 
                src={userProfile.avatar} 
                alt={userProfile.name} 
                style={styles.avatar} 
              />
            </div>
          </div>
        </header>

        {/* Dynamic Metric Cards */}
        <section className="stats-grid" style={styles.statsGrid}>
          {stats.map((stat, idx) => (
            <div key={idx} className="stat-card" style={styles.statCard}>
              <div className="stat-header" style={styles.statHeader}>
                <span style={styles.statTitle}>{stat.title}</span>
                {stat.icon}
              </div>
              <div style={styles.statValue}>{loading ? "..." : stat.value}</div>
            </div>
          ))}
        </section>

        {/* Middle Grid Section */}
        <section className="middle-grid" style={styles.middleGrid}>
          {/* Chart Placeholder */}
          <div className="card chart-card" style={styles.card}>
            <div className="card-header" style={styles.cardHeader}>
              <div>
                <h3 style={styles.cardTitle}>Voting Activity Timeline</h3>
                <p style={styles.mutedText}>Hourly accumulation of votes processed today</p>
              </div>
              <div style={styles.chartLegend}>
                <span style={{ ...styles.badgeDot, backgroundColor: '#3b82f6' }}></span> Votes Cast
              </div>
            </div>
            <div style={styles.chartPlaceholder}>
              <svg viewBox="0 0 500 150" style={{ width: '100%', height: '120px' }}>
                <polyline
                  fill="none"
                  stroke="#3b82f6"
                  strokeWidth="3"
                  points="20,110 80,95 140,105 200,70 260,78 320,45 380,55 440,30"
                />
              </svg>
              <div style={styles.chartXAxis}>
                <span>08:00</span><span>10:00</span><span>12:00</span><span>14:00</span>
                <span>16:00</span><span>18:00</span><span>20:00</span><span>22:00</span>
              </div>
            </div>
          </div>

          {/* Announcements Board */}
          <div className="card announcements-card" style={styles.card}>
            <div className="card-header" style={styles.cardHeader}>
              <h3 style={styles.cardTitle}>Admin Announcements</h3>
              <a href="#new" style={styles.newPostLink}><Plus size={14} /> New Post</a>
            </div>
            <div style={styles.announcementsList}>
              {announcements.length === 0 ? (
                <p style={styles.noDataText}>
                  {loading ? "Loading announcements..." : "No announcements posted."}
                </p>
              ) : (
                announcements.map((item, idx) => (
                  <div key={item.id || idx} style={styles.announcementItem}>
                    <div style={styles.announcementMeta}>
                      <span style={{ fontWeight: 600, color: getTagColor(item.type) }}>
                        • {item.type || 'SYSTEM'}
                      </span>
                      <span style={{ color: '#64748b' }}>{item.time_ago || item.time}</span>
                    </div>
                    <p style={styles.announcementText}>{item.text}</p>
                  </div>
                ))
              )}
            </div>
          </div>
        </section>

        {/* Bottom Section: Action Logs */}
        <section className="card table-card" style={styles.card}>
          <h3 style={styles.cardTitle}>Recent System Actions</h3>
          <table style={styles.actionsTable}>
            <thead>
              <tr style={{ borderBottom: '1px solid #334155' }}>
                <th style={styles.th}>Timestamp</th>
                <th style={styles.th}>Action Description</th>
                <th style={styles.th}>Triggered By</th>
              </tr>
            </thead>
            <tbody>
              {recentActions.length === 0 ? (
                <tr>
                  <td colSpan="3" style={styles.noDataCell}>
                    {loading ? "Fetching activity log..." : "No recent system actions logged."}
                  </td>
                </tr>
              ) : (
                recentActions.map((row, idx) => (
                  <tr key={row.id || idx} style={{ borderBottom: '1px solid #1e293b' }}>
                    <td style={{ ...styles.td, color: '#64748b' }}>{row.timestamp || row.time}</td>
                    <td style={styles.td}>{row.description || row.action}</td>
                    <td style={{ ...styles.td, color: '#94a3b8' }}>{row.triggered_by || row.trigger}</td>
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

// Helper for tag color formatting
const getTagColor = (type) => {
  switch ((type || '').toUpperCase()) {
    case 'APPROVAL': return '#10b981';
    case 'DEADLINE': return '#ef4444';
    default: return '#3b82f6';
  }
};

// Component Styles Blueprint
const styles = {
  container: { display: 'flex', minHeight: '100vh', backgroundColor: '#0f172a', color: '#f8fafc', fontFamily: 'sans-serif' },
  sidebar: { width: '260px', backgroundColor: '#1e293b', borderRight: '1px solid #334155', display: 'flex', flexDirection: 'column', padding: '24px 16px', flexShrink: 0 },
  logoArea: { display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '32px', padding: '0 8px' },
  logoIcon: { backgroundColor: '#2563eb', color: '#fff', width: '36px', height: '36px', borderRadius: '8px', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' },
  brandName: { fontSize: '1.1rem', fontWeight: 700, margin: 0 },
  brandSub: { fontSize: '0.65rem', color: '#94a3b8', margin: 0, letterSpacing: '0.05em' },
  navMenu: { display: 'flex', flexDirection: 'column', gap: '6px', flexGrow: 1 },
  navItem: { display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 14px', color: '#94a3b8', textDecoration: 'none', borderRadius: '8px', fontSize: '0.9rem', fontWeight: 500 },
  navItemActive: { backgroundColor: '#2563eb', color: '#ffffff' },
  sidebarFooterContainer: { marginTop: 'auto', display: 'flex', flexDirection: 'column', gap: '12px', paddingTop: '16px', borderTop: '1px solid #334155' },
  logoutBtn: { display: 'flex', alignItems: 'center', gap: '10px', background: 'rgba(239, 68, 68, 0.08)', border: '1px solid rgba(239, 68, 68, 0.2)', color: '#f87171', fontSize: '0.88rem', fontWeight: 500, padding: '10px 14px', borderRadius: '8px', cursor: 'pointer', width: '100%' },
  sidebarFooter: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: '0.75rem', color: '#64748b' },
  statusDot: { width: '8px', height: '8px', backgroundColor: '#10b981', borderRadius: '50%' },
  mainContent: { flexGrow: 1, padding: '32px', display: 'flex', flexDirection: 'column', gap: '24px', overflowY: 'auto' },
  topHeader: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' },
  breadcrumb: { fontSize: '0.9rem', color: '#f8fafc' },
  headerActions: { display: 'flex', alignItems: 'center', gap: '20px' },
  badgeOpen: { display: 'flex', alignItems: 'center', gap: '6px', backgroundColor: 'rgba(16, 185, 129, 0.1)', color: '#10b981', border: '1px solid rgba(16, 185, 129, 0.2)', padding: '6px 12px', borderRadius: '20px', fontSize: '0.8rem', fontWeight: 600 },
  badgeDot: { width: '6px', height: '6px', backgroundColor: '#10b981', borderRadius: '50%', display: 'inline-block' },
  userProfile: { display: 'flex', alignItems: 'center', gap: '12px' },
  userInfo: { display: 'flex', flexDirection: 'column', textAlign: 'right' },
  userName: { fontSize: '0.88rem', fontWeight: 600 },
  userRole: { fontSize: '0.75rem', color: '#64748b' },
  avatar: { width: '38px', height: '38px', borderRadius: '50%', border: '1px solid #334155', objectFit: 'cover' },
  statsGrid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '16px' },
  statCard: { backgroundColor: '#1e293b', border: '1px solid #334155', borderRadius: '12px', padding: '20px', display: 'flex', flexDirection: 'column', gap: '12px' },
  statHeader: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' },
  statTitle: { fontSize: '0.7rem', fontWeight: 700, color: '#64748b', letterSpacing: '0.05em' },
  statValue: { fontSize: '1.6rem', fontWeight: 700, color: '#f8fafc' },
  middleGrid: { display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '16px' },
  card: { backgroundColor: '#1e293b', border: '1px solid #334155', borderRadius: '12px', padding: '20px' },
  cardHeader: { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px' },
  cardTitle: { margin: 0, fontSize: '1rem', fontWeight: 600 },
  mutedText: { margin: '4px 0 0 0', fontSize: '0.8rem', color: '#64748b' },
  chartLegend: { display: 'flex', alignItems: 'center', gap: '6px', fontSize: '0.75rem', color: '#94a3b8' },
  chartPlaceholder: { display: 'flex', flexDirection: 'column', gap: '8px', marginTop: '16px' },
  chartXAxis: { display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: '#64748b', padding: '0 10px' },
  newPostLink: { display: 'flex', alignItems: 'center', gap: '4px', color: '#3b82f6', textDecoration: 'none', fontSize: '0.8rem', fontWeight: 500 },
  announcementsList: { display: 'flex', flexDirection: 'column', gap: '12px' },
  announcementItem: { backgroundColor: '#0f172a', border: '1px solid #334155', borderRadius: '8px', padding: '12px', display: 'flex', flexDirection: 'column', gap: '6px' },
  announcementMeta: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '0.75rem' },
  announcementText: { margin: 0, fontSize: '0.85rem', color: '#cbd5e1' },
  actionsTable: { width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.85rem' },
  th: { color: '#64748b', fontWeight: 600, padding: '12px', fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.05em' },
  td: { padding: '12px', color: '#cbd5e1' },
  noDataText: { color: '#64748b', fontSize: '0.85rem', padding: '20px 0', textAlign: 'center' },
  noDataCell: { textAlign: 'center', color: '#64748b', padding: '20px' }
};