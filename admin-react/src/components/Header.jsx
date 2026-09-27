import { useEffect, useState } from 'react';
import { Clock } from 'lucide-react';
import { useAuth } from '../lib/AuthContext';
import { useElectionStatus } from '../lib/ElectionStatusContext';
import { fallbackAvatarOnError } from '../lib/avatar';
import NotificationCenter from './NotificationCenter';
import PhaseStatusDialog from './PhaseStatusDialog';
import MobileMenuButton from './MobileMenuButton';
import './Header.css';

/*
 * Shared admin page header.
 *
 * Renders:
 *  - breadcrumb (leading)
 *  - live election-phase badge (from the shared ElectionStatusContext, which
 *    polls GET /election/status every 30 s)
 *  - live running clock (setInterval, 1 s)
 *  - authenticated user name / role badge / avatar (from Sanctum session via useAuth)
 *
 * The user profile comes from the auth session (useAuth) — NOT hardcoded —
 * so the header updates when a different admin/teacher logs in.
 */
export default function Header({
  breadcrumb = '',
  children,
  currentUser = null,
  onNavigate = null,
}) {
  const { user } = useAuth();
  const me = currentUser ?? user;

  const { phase } = useElectionStatus();
  const [timeString, setTimeString] = useState('');
  const [showPhaseInfo, setShowPhaseInfo] = useState(false);

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

  return (
    <header className="top-header">
      <MobileMenuButton />
      <div className="breadcrumb">
        {breadcrumb === '' ? null : (
          <>
            <span className="muted">System / </span>
            <strong className="breadcrumb-title">{breadcrumb}</strong>
          </>
        )}
      </div>
      <div className="header-right">
        <button
          type="button"
          className="voting-status-badge"
          onClick={() => { if (!phase) setShowPhaseInfo(true); }}
          title={phase ? undefined : 'Tap to learn why no phase is set'}
        >
          <span className="status-dot-green" /> {displayPhase(phase)}
        </button>
        <div className="system-time">
          <Clock size={16} /> {timeString || '—'}
        </div>
        <NotificationCenter onNavigate={onNavigate} />
        <div className="user-profile">
          <div className="user-info">
            <span className="user-name">{me?.name || 'Admin User'}</span>
            <span className="user-role">{roleLabel(me?.role)}</span>
          </div>
          {me?.avatar_url ? (
            <img
              src={me.avatar_url}
              alt={me.name || 'User'}
              className="user-avatar"
              onError={fallbackAvatarOnError(me?.name)}
            />
          ) : (
            <img
              src={`https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(me?.name || 'U')}&backgroundColor=2563eb`}
              alt={me?.name || 'User'}
              className="user-avatar"
              onError={fallbackAvatarOnError(me?.name)}
            />
          )}
        </div>
        {children}
      </div>
      {showPhaseInfo && (
        <PhaseStatusDialog
          title="Election Not Configured Yet"
          message="The administrator has not set the registration and voting windows yet. Until they do, students and staff will see “Not Configured” and voting stays locked."
          onClose={() => setShowPhaseInfo(false)}
        />
      )}
    </header>
  );
}

function roleLabel(role) {
  switch (role) {
    case 'admin':
      return 'System Administrator';
            case 'teacher':
              return 'SSG Adviser';
    case 'ssg_president':
      return 'SSG President';
    default:
      return role || 'Administrator';
  }
}

function displayPhase(p) {
  if (!p) return 'Not Configured';
  switch (p) {
    case 'registration':
      return 'Registration';
    case 'registration_closed':
      return 'Registration Closed';
    case 'voting_open':
      return 'Voting Open';
    case 'voting_closed':
      return 'Voting Closed';
    default:
      return p;
  }
}
