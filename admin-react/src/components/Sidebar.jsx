import {
  LayoutDashboard,
  Users,
  UserCheck,
  Sliders,
  BarChart2,
  Megaphone,
  Settings as SettingsIcon,
  ShieldCheck,
  Building2,
  LogOut,
} from 'lucide-react';
import { useAuth } from '../lib/AuthContext';
import { useBranding } from '../lib/branding';
import { allowedViews } from '../lib/permissions';
import OmniVoteMark from './OmniVoteMark';
import './Sidebar.css';

/*
 * Shared admin application sidebar.
 *
 * Final navigation order:
 *   1. Dashboard
 *   2. Candidates
 *   3. Student Registry
 *   4. User Management    ← promoted from a Student-Registry sub-item to a
 *                          standalone top-level entry (view id `user_management`)
 *   5. Departments        ← add/rename departments + browse their members
 *   6. Election Setup
 *   7. Results
 *   8. Announcements      ← staff announcements (admin + teacher compose UI)
 *   9. Settings
 *
 * Base shell classes (`.sidebar`, `.nav-item`, …) intentionally come from
 * the host page's stylesheet — this file only adds the submenu/toggle
 * classes so it cannot fight the per-page shell styles.
 */
export default function Sidebar({
  activeView,
  onNavigate,
  onLogout,
  permittedViews,
  currentUser = null,
  brandName = undefined,
  brandSub = undefined,
  statusText = 'System Live (v1.4)',
}) {
  const { user, logout } = useAuth();
  const branding = useBranding();
  const resolvedName = brandName ?? branding.siteName;
  const resolvedSub = brandSub ?? 'ELECTION CONSOLE';
  const logoUrl = branding.logoUrl;
  // Prefer the explicit currentUser prop (App.jsx always passes it); fall
  // back to the auth context so standalone/preview renders still work.
  const role = currentUser?.role ?? user?.role ?? '';
  const views = Array.isArray(permittedViews) ? permittedViews : allowedViews(role);
  /*
   * Role-based filtering. When the role is known (non-empty views list),
   * hide entries the role cannot open so navigation never shows dead
   * buttons (App.jsx's navigate() would silently ignore them). When the
   * role is unknown, the main entries render and only the
   * permission-gated User Management item is hidden.
   */
  const can = (view) => views.length === 0 || views.includes(view);

  const go = (view) => () => {
    if (typeof onNavigate === 'function') onNavigate(view);
  };
  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  return (
    <aside className="sidebar">
      <div className="logo-area">
        <div className="logo-icon">{logoUrl ? <img src={logoUrl} alt="" className="sidebar-logo-img" /> : <OmniVoteMark className="sidebar-logo-img" />}</div>
        <div>
          <h1 className="brand-name">{resolvedName}</h1>
          <p className="brand-sub">{resolvedSub}</p>
        </div>
      </div>

      <nav className="nav-menu">
        {/* 1. Dashboard */}
        {can('dashboard') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'dashboard' ? 'active' : ''}`}
            onClick={go('dashboard')}
          >
            <LayoutDashboard size={18} /> Dashboard
          </button>
        )}
        {/* 2. Candidates */}
        {can('candidates') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'candidates' ? 'active' : ''}`}
            onClick={go('candidates')}
          >
            <Users size={18} /> Candidates
          </button>
        )}
        {/* 3. Student Registry */}
        {can('voters') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'voters' ? 'active' : ''}`}
            onClick={go('voters')}
          >
            <UserCheck size={18} /> Student Registry
          </button>
        )}
        {/* 4. User Management (promoted to top-level) */}
        {can('user_management') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'user_management' ? 'active' : ''}`}
            onClick={go('user_management')}
          >
            <ShieldCheck size={18} /> User Management
          </button>
        )}
        {/* 5. Departments */}
        {can('departments') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'departments' ? 'active' : ''}`}
            onClick={go('departments')}
          >
            <Building2 size={18} /> Departments
          </button>
        )}
        {/* 6. Election Setup */}
        {can('setup') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'setup' ? 'active' : ''}`}
            onClick={go('setup')}
          >
            <Sliders size={18} /> Election Setup
          </button>
        )}
        {/* 6. Results */}
        {can('results') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'results' ? 'active' : ''}`}
            onClick={go('results')}
          >
            <BarChart2 size={18} /> Results
          </button>
        )}
        {/* 7. Announcements */}
        {can('announcements') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'announcements' ? 'active' : ''}`}
            onClick={go('announcements')}
          >
            <Megaphone size={18} /> Announcements
          </button>
        )}
        {/* 8. Settings */}
        {can('settings') && (
          <button
            type="button"
            className={`nav-item ${activeView === 'settings' ? 'active' : ''}`}
            onClick={go('settings')}
          >
            <SettingsIcon size={18} /> Settings
          </button>
        )}
      </nav>

      <div className="sidebar-footer-container">
        <button onClick={handleLogout} className="logout-button">
          <LogOut size={18} /> Logout
        </button>
        <div className="sidebar-footer">
          <span className="status-dot-green"></span> {statusText}
        </div>
        <div className="sidebar-about">© 2026 OmniVote</div>
      </div>
    </aside>
  );
}
