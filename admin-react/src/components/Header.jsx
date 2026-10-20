import { useEffect, useState } from 'react';
import { Clock } from 'lucide-react';
import { useAuth } from '../lib/AuthContext';
import { useElectionStatus } from '../lib/ElectionStatusContext';
import { fallbackAvatarOnError, initialsAvatarDataUri } from '../lib/avatar';
import { displayPhase, roleLabel } from '../lib/phase';
import { formatClock } from '../lib/datetime';
import { IS_SSG_CONSOLE } from '../lib/api';
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
  // The two consoles are separate mounts of the same bundle, so the breadcrumb
  // root follows the mount: "SSG / …" in the President console, "System / …"
  // in the staff console.
  breadcrumbPrefix = IS_SSG_CONSOLE ? 'SSG' : 'System',
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
    const tick = () => setTimeString(formatClock(new Date()));
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
            <span className="muted">{breadcrumbPrefix} / </span>
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
            <span className="user-role">{roleLabel(me?.role, 'Administrator')}</span>
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
              src={initialsAvatarDataUri(me?.name || 'U')}
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
