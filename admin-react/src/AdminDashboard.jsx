import { useState, useEffect } from 'react';
import DashboardWidgets from './components/DashboardWidgets';
import NotificationCenter from './components/NotificationCenter';
import PhaseStatusDialog from './components/PhaseStatusDialog';
import { fallbackAvatarOnError, defaultUserIconDataUri } from './lib/avatar';
import './AdminDashboard.css';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import { useElectionStatus } from './lib/ElectionStatusContext';
import { allowedViews } from './lib/permissions';
import {
  Database,
  CheckCircle,
  Clock,
  Award,
} from 'lucide-react';
import Sidebar from './components/Sidebar';
import MobileMenuButton from './components/MobileMenuButton';

/*
 * Dashboard Overview.
 *
 * Backend contract (`GET /admin/dashboard-overview`): `stats`
 * (total_voters / votes_cast / turnout_rate / approved_candidates),
 * `election_phase`, `announcements`, `recent_actions`, `user`.
 *
 * Everything rendered comes from that response — there are deliberately no
 * fabricated metrics (accounts totals, flagged votes, uptime percentages,
 * demo activity entries) because the backend does not send them.
 */
export default function AdminDashboard({ onLogout, activeView = 'dashboard', onNavigate, currentUser = null }) {
  const { logout } = useAuth();
  const [statsData, setStatsData] = useState({
    total_voters: 0,
    votes_cast: 0,
    turnout_rate: 0,
    approved_candidates: 0,
  });
  const [announcements, setAnnouncements] = useState([]);
  const [recentActions, setRecentActions] = useState([]);
  // Empty (not "Registration") until a real phase arrives, so an unconfigured
  // election shows "Not Configured" instead of a misleading stale phase.
  const [electionPhase, setElectionPhase] = useState('');
  const [timeString, setTimeString] = useState('');
  const [loading, setLoading] = useState(true);
  // Real connectivity signal from the overview fetch (drives System Status).
  const [apiOk, setApiOk] = useState(true);
  // Latest cached server-health snapshot (admins only; null for other roles).
  const [systemHealth, setSystemHealth] = useState(null);
  // Live phase from the shared poller (every 30 s) so the dashboard phase card
  // flips on its own when a configured window boundary is crossed.
  const { phase: livePhase } = useElectionStatus();

  // Derive profile from authenticated user — no local state flickering.
  const userProfile = currentUser
    ? {
        time: timeString,
        name: currentUser.name || 'Admin User',
        role: currentUser.role === 'admin'
          ? 'System Administrator'
          : currentUser.role === 'teacher'
            ? 'SSG Adviser'
            : currentUser.role === 'ssg_president'
              ? 'SSG President'
              : (currentUser.role || 'Administrator'),
        avatar: currentUser.avatar_url
          ? currentUser.avatar_url
          : defaultUserIconDataUri(76),
      }
    : {
        time: timeString,
        name: 'Loading...',
        role: '',
        avatar: defaultUserIconDataUri(76),
      };

  // Update clock every second
  useEffect(() => {
    const tick = () => {
      const d = new Date();
      const tzLabel = new Intl.DateTimeFormat('en-US', {
        timeZoneName: 'short',
      }).formatToParts(d).find((part) => part.type === 'timeZoneName')?.value;
      setTimeString(
        d.toLocaleTimeString('en-US', {
          hour12: false,
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
        }) + ' ' + (tzLabel || 'LOCAL'),
      );
    };
    tick();
    const id = setInterval(tick, 1000);
    return () => clearInterval(id);
  }, []);

  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true);
        const response = await api.get('/admin/dashboard-overview');
        const data = response.data;
        if (data.stats) setStatsData(data.stats);
        if (data.announcements) setAnnouncements(data.announcements);
        if (data.recent_actions) setRecentActions(data.recent_actions);
        if (data.election_phase) setElectionPhase(data.election_phase);
        setSystemHealth(data.system_health || null);
        setApiOk(true);
      } catch (error) {
        console.warn('Backend API connection pending or unavailable:', error.message);
        setApiOk(false);
      } finally {
        setLoading(false);
      }
    };

    fetchDashboardData();
  }, []);

  // Live polled phase wins when known; otherwise fall back to the phase the
  // overview response carried. Kept as a derived value so the dashboard flips
  // on its own without an extra effect.
  const dashboardPhase = livePhase ?? electionPhase;
  const displayPhase = (p) => {
    switch (p) {
      case 'registration': return 'Registration';
      case 'registration_closed': return 'Registration Closed';
      case 'voting_open': return 'Voting Open';
      case 'voting_closed': return 'Voting Closed';
      default: return p || 'Not Configured';
    }
  };

  const isAdmin = (currentUser?.role ?? '') === 'admin';
  const [phasePrompt, setPhasePrompt] = useState(null);

  // Tapping the phase badge: admins are prompted to go configure the window;
  // teachers (who cannot open Settings) get an informational popup instead.
  const handlePhaseBadgeClick = () => {
    if (dashboardPhase) return;
    setPhasePrompt(isAdmin ? 'admin' : 'teacher');
  };

  // Short role label for the breadcrumb ("Admin /" vs "SSG Adviser /").
  const breadcrumbRole =
    currentUser?.role === 'teacher'
      ? 'SSG Adviser'
      : currentUser?.role === 'ssg_president'
        ? 'SSG President'
        : 'Admin';

  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  const stats = [
    {
      title: 'TOTAL REGISTERED VOTERS',
      value: (statsData.total_voters || 0).toLocaleString(),
      icon: <Database className="stat-icon blue" />,
    },
    {
      title: 'VOTES CAST',
      value: (statsData.votes_cast || 0).toLocaleString(),
      icon: <CheckCircle className="stat-icon green" />,
    },
    {
      title: 'TURNOUT RATE',
      value: `${statsData.turnout_rate || 0}%`,
      icon: <Clock className="stat-icon orange" />,
    },
    {
      title: 'APPROVED CANDIDATES',
      value: (statsData.approved_candidates || 0).toLocaleString(),
      icon: <Award className="stat-icon purple" />,
    },
  ];

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={handleLogout} permittedViews={allowedViews(currentUser?.role ?? '')} />

      <main className="main-content">
        <header className="top-header">
          <MobileMenuButton />
          <div className="breadcrumb">
            <span className="muted">{breadcrumbRole} /</span>
            <strong className="Dash">Dashboard Overview</strong>
          </div>
          <div className="header-actions">
            <button type="button" className="badge-open badge-action" onClick={handlePhaseBadgeClick} title={dashboardPhase ? undefined : 'No election window is set yet'}>
              <span className="dot"></span> {displayPhase(dashboardPhase)}
            </button>
            <NotificationCenter onNavigate={onNavigate} />
            <div className="user-profile">
              <Clock size={16} />
              <span className="user-time">{userProfile.time}</span>
              <div className="user-info">
                <span className="user-name">{userProfile.name}</span>
                <span className="user-role">{userProfile.role}</span>
              </div>
              <img src={userProfile.avatar || undefined} alt={userProfile.name} className="avatar"
                onError={userProfile.avatar ? fallbackAvatarOnError(userProfile.name) : undefined} />
            </div>
          </div>
        </header>

        {/* Personal greeting — visible to Admin, Teacher, and SSG President
            alike after signing in to their own console. */}
        <section className="welcome-strip" aria-label="Welcome">
          <h2 className="welcome-title">Welcome back, {(userProfile.name || 'Admin User').split(' ')[0]}</h2>
          <p className="welcome-sub">Here&apos;s the latest across your election console.</p>
        </section>

        {/* 1. Core metrics — the single, unified stat row. */}
        <section className="stats-grid">
          {stats.map((stat, idx) => (
            <div key={idx} className="stat-card">
              <div className="stat-header">
                <span className="stat-title">{stat.title}</span>
                {stat.icon}
              </div>
              <div className="stat-value">{loading ? '...' : apiOk ? stat.value : '—'}</div>
            </div>
          ))}
        </section>

        {/* 2 + 3. Quick actions, visuals, status & activity — one component. */}
        <DashboardWidgets
          stats={statsData}
          electionPhase={dashboardPhase}
          announcements={announcements}
          recentActions={recentActions}
          loading={loading}
          apiOk={apiOk}
          systemHealth={systemHealth}
          onNavigate={onNavigate}
          permittedViews={allowedViews(currentUser?.role ?? '')}
        />
      </main>

      {phasePrompt && (
        <PhaseStatusDialog
          title={isAdmin ? 'Registration Window Not Set' : 'Election Not Configured Yet'}
          message={isAdmin
            ? 'No registration or voting window has been configured yet, so everyone sees "Not Configured" and voting stays locked. Set the windows so the election can start.'
            : 'The administrator has not set the registration window yet. Registration has not been opened — please check back later.'}
          confirmLabel={isAdmin ? 'Configure Voting Windows' : null}
          onConfirm={isAdmin ? () => onNavigate('settings', 'voting') : null}
          onClose={() => setPhasePrompt(null)}
        />
      )}
    </div>
  );
}
