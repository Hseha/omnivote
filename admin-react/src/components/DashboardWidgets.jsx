import {
  Users,
  Activity,
  AlertTriangle,
  BarChart2,
  CheckCircle,
  Clock,
  Shield,
  TrendingUp,
  Megaphone,
  Eye,
} from 'lucide-react';
import { useState } from 'react';
import { fallbackAvatarOnError } from '../lib/avatar';
import './DashboardWidgets.css';

/*
 * Event-type presentation metadata: label + accent colour + icon.
 * Used when the backend starts populating `recent_actions`; until then the
 * list renders an honest empty state (no demo data is ever shown).
 */
const ACTION_META = {
  info: { label: 'Info', color: '#60a5fa', Icon: Users },
  success: { label: 'Success', color: '#34d399', Icon: CheckCircle },
  warning: { label: 'Warning', color: '#f59e0b', Icon: AlertTriangle },
  danger: { label: 'Alert', color: '#f87171', Icon: AlertTriangle },
  candidate_create: { label: 'Candidate Added', color: '#34d399', Icon: Users },
  candidate_status_update: { label: 'Candidate Updated', color: '#60a5fa', Icon: Users },
  user_login: { label: 'Admin Login', color: '#c084fc', Icon: Activity },
  backup_created: { label: 'Backup Created', color: '#f59e0b', Icon: Clock },
  election_status_update: { label: 'Election Updated', color: '#f87171', Icon: AlertTriangle },
};

const DEFAULT_ACTION = { label: 'Action', color: '#94a3b8', Icon: CheckCircle };

/*
 * Election-phase presentation. Keys are normalised so both machine values
 * (`voting_open`) and display values (`Voting Open`) resolve to the same entry.
 */
const PHASE_META = {
  voting_open: {
    label: 'Voting Open',
    color: '#10b981',
    pill: 'Live',
    desc: 'Ballots are open for all eligible voters.',
  },
  voting_closed: {
    label: 'Voting Closed',
    color: '#f87171',
    pill: 'Closed',
    desc: 'Ballots are closed. Results pending certification.',
  },
  registration: {
    label: 'Registration',
    color: '#f59e0b',
    pill: 'Pending',
    desc: 'Voter registration is currently open.',
  },
  registration_closed: {
    label: 'Registration Closed',
    color: '#94a3b8',
    pill: 'Idle',
    desc: 'Registration has closed; voting has not opened yet.',
  },
};

const DEFAULT_PHASE = {
  label: 'Not Configured',
  color: '#64748b',
  pill: 'Idle',
  desc: 'No election phase is currently active.',
};

/*
 * Quick-action shortcuts. `target` matches the view ids defined in
 * `lib/permissions.js`, so the buttons navigate to real views.
 */
const QUICK_ACTIONS = [
  { label: 'Configure Election', Icon: BarChart2, cls: 'qa-icon-blue', target: 'setup' },
  { label: 'Manage Candidates', Icon: Users, cls: 'qa-icon-purple', target: 'candidates' },
  { label: 'Voter Registry', Icon: Shield, cls: 'qa-icon-green', target: 'voters' },
  { label: 'User Access', Icon: Activity, cls: 'qa-icon-orange', target: 'user_management' },
];

/** `Voting Open` / `voting-open` -> `voting_open`. */
const normalizePhase = (value) => String(value || '').trim().toLowerCase().replace(/[\s-]+/g, '_');

const toNumber = (value, fallback = 0) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
};

export default function DashboardWidgets({
  stats = {},
  electionPhase,
  announcements = [],
  recentActions = [],
  loading = false,
  apiOk = true,
  onNavigate,
  permittedViews,
}) {
  const phase = PHASE_META[normalizePhase(electionPhase)] || DEFAULT_PHASE;
  // Real data only — the backend sends `recent_actions: []` until audit
  // logging exists, in which case the empty state is shown instead.
  const actions = Array.isArray(recentActions) ? recentActions.slice(0, 6) : [];
  // Only surface shortcuts the signed-in role is actually allowed to open.
  const quickActions = permittedViews
    ? QUICK_ACTIONS.filter((action) => permittedViews.includes(action.target))
    : QUICK_ACTIONS;
  // The announcement being read in full (dashboard overview sends title +
  // body + author; the modal shows the whole thing).
  const [selected, setSelected] = useState(null);

  /*
   * `/admin/dashboard-overview` returns `total_voters` / `votes_cast` /
   * `turnout_rate`. This widget previously read `eligible_voters` /
   * `voters_voted` / `turnout_percentage`, which the endpoint never sends, so
   * turnout always rendered as 0. Both shapes are now supported.
   */
  const eligible = toNumber(stats.eligible_voters ?? stats.total_voters);
  const votesCast = toNumber(stats.voters_voted ?? stats.votes_cast);
  const turnoutPct = toNumber(stats.turnout_percentage ?? stats.turnout_rate);
  const remaining = Math.max(eligible - votesCast, 0);
  const turnoutColor = turnoutPct >= 70 ? '#10b981' : turnoutPct >= 40 ? '#f59e0b' : '#ef4444';

  const handleNavigate = (target) => () => {
    if (typeof onNavigate === 'function') onNavigate(target);
  };

  return (
    <>
      {/* ===== 2. Quick actions — one compact strip, no card noise ===== */}
      <section className="quick-actions-row" aria-label="Quick actions">
        <span className="qa-row-label">Quick actions</span>
        <div className="qa-pill-row">
          {quickActions.map((action) => (
            <button
              key={action.label}
              type="button"
              className="qa-pill"
              onClick={handleNavigate(action.target)}
            >
              <span className={`qa-icon ${action.cls}`}><action.Icon size={14} /></span>
              {action.label}
            </button>
          ))}
        </div>
      </section>

      {/* ===== Primary visuals: turnout ring | announcements ===== */}
      <section className="primary-grid">
        <section className="turnout-widget card">
          <div className="widget-header">
            <div>
              <h3>Voter Turnout</h3>
              <p className="muted-text">{eligible.toLocaleString()} eligible voters</p>
            </div>
            <span className="widget-header-icon"><TrendingUp size={16} /></span>
          </div>

          {/*
            Geometry, sizing and `fill` are set as SVG attributes (not only CSS) so
            the ring can never collapse into a full-width black disc if the
            stylesheet is missing — the failure mode this widget used to have.
          */}
          <div className="turnout-chart-box">
            <div className="turnout-percent-ring">
              <svg
                viewBox="0 0 36 36"
                className="turnout-ring-svg"
                width="56"
                height="56"
                role="img"
                aria-label={`Voter turnout ${Math.round(turnoutPct)} percent`}
              >
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(51, 65, 85, 0.5)" strokeWidth="3" />
                <circle
                  className="turnout-ring-fill"
                  cx="18"
                  cy="18"
                  r="15.9155"
                  fill="none"
                  stroke={turnoutColor}
                  strokeWidth="3"
                  strokeLinecap="round"
                  strokeDasharray={`${turnoutPct} 100`}
                />
              </svg>
              <div className="turnout-ring-label">{loading ? '…' : `${Math.round(turnoutPct)}%`}</div>
            </div>

            <div className="turnout-legend">
              <div className="turnout-stat">
                <span className="turnout-stat-label">
                  <span className="legend-swatch" style={{ background: turnoutColor }} /> Voted
                </span>
                <span className="turnout-stat-value">{loading ? '…' : votesCast.toLocaleString()}</span>
              </div>
              <div className="turnout-stat">
                <span className="turnout-stat-label">
                  <span className="legend-swatch" style={{ background: '#475569' }} /> Remaining
                </span>
                <span className="turnout-stat-value">{loading ? '…' : remaining.toLocaleString()}</span>
              </div>
              <div className="turnout-stat">
                <span className="turnout-stat-label">
                  <span className="legend-swatch" style={{ background: '#3b82f6' }} /> Eligible
                </span>
                <span className="turnout-stat-value">{loading ? '…' : eligible.toLocaleString()}</span>
              </div>
            </div>
          </div>
        </section>

        <section className="announcements-widget card">
          <div className="widget-header">
            <div>
              <h3>Admin Announcements</h3>
              <p className="muted-text">Posted notices for voters &amp; staff</p>
            </div>
            <span className="widget-header-icon"><Megaphone size={16} /></span>
          </div>
          <div className="announcements-list">
            {announcements.length === 0 ? (
              <p className="no-data-text">
                {loading ? 'Loading announcements…' : 'No announcements posted.'}
              </p>
            ) : (
              announcements.map((item, idx) => (
                <div
                  key={item.id || idx}
                  className="announcement-item"
                  role="button"
                  tabIndex={0}
                  aria-label={`View announcement: ${item.title || 'Untitled post'}`}
                  onClick={() => setSelected(item)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setSelected(item);
                    }
                  }}
                >
                  <div className="announcement-meta">
                    <span className="tag system">• Announcement</span>
                    <span className="time">{getTimeAgo(item.published_at)}</span>
                  </div>
                  <p className="announcement-text">{item.title || item.text || 'Untitled post'}</p>
                  <span className="announcement-view">
                    <Eye size={12} /> View full announcement
                  </span>
                </div>
              ))
            )}
          </div>
        </section>
      </section>

      {/* ===== Secondary: election phase + system status | recent activity ===== */}
      <section className="secondary-grid">
        <div className="status-pair">
          <div className="elec-status-card">
            <div className="widget-header">
              <div className="widget-title-row">
                <span
                  className="widget-icon-badge"
                  style={{ background: phase.color + '20', color: phase.color }}
                >
                  <Clock size={18} />
                </span>
                <div>
                  <div className="widget-title">Election Phase</div>
                  <div className="widget-sub">Current cycle</div>
                </div>
              </div>
              <span
                className="widget-pill"
                style={{
                  color: phase.color,
                  background: phase.color + '1a',
                  borderColor: phase.color + '33',
                }}
              >
                <span className="pill-dot" style={{ background: phase.color }} /> {phase.pill}
              </span>
            </div>
            <div className="elec-status-name" style={{ color: phase.color }}>{phase.label}</div>
            <p className="widget-desc">{phase.desc}</p>
          </div>

          <div className="elec-status-card">
            <div className="widget-header">
              <div className="widget-title-row">
                <span
                  className="widget-icon-badge"
                  style={{
                    background: (apiOk ? '#10b981' : '#f87171') + '20',
                    color: apiOk ? '#10b981' : '#f87171',
                  }}
                >
                  <Activity size={18} />
                </span>
                <div>
                  <div className="widget-title">System Status</div>
                  <div className="widget-sub">API connectivity</div>
                </div>
              </div>
              <span
                className="widget-pill"
                style={{
                  color: apiOk ? '#10b981' : '#f87171',
                  background: (apiOk ? '#10b981' : '#f87171') + '1a',
                  borderColor: (apiOk ? '#10b981' : '#f87171') + '33',
                }}
              >
                <span className="pill-dot" style={{ background: apiOk ? '#10b981' : '#f87171' }} />
                {apiOk ? 'Connected' : 'Unreachable'}
              </span>
            </div>
            <p className="widget-desc">
              {apiOk
                ? 'Dashboard data is live from the election API.'
                : 'Could not reach the election API. Metrics may be stale.'}
            </p>
          </div>
        </div>

        <section className="audit-widget card">
          <div className="widget-header">
            <div>
              <h3>Recent Activity</h3>
              <p className="muted-text">Your recent activity</p>
            </div>
          </div>
          <div className="audit-list">
            {actions.length === 0 ? (
              <p className="no-data-text">
                {loading ? 'Fetching activity…' : 'No activity logged yet. Admin actions will appear here.'}
              </p>
            ) : (
              actions.map((item, index) => {
                const { label, color, Icon } = ACTION_META[item.type] || DEFAULT_ACTION;
                const eventLabel = item.title || label;
                const eventDetail = item.body || item.target;
                return (
                  <div key={item.id ?? index} className="audit-row">
                    <span
                      className="audit-icon"
                      style={{ background: color + '20', color }}
                      aria-hidden="true"
                    >
                      <Icon size={14} />
                    </span>
                    <div className="audit-text">
                      <div className="audit-desc">
                        <span className="audit-event">{eventLabel}</span>
                        {eventDetail && <span className="audit-target">: {eventDetail}</span>}
                      </div>
                      <div className="audit-time">{getTimeAgo(item.created_at)}</div>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </section>
      </section>

      {selected && (
        <div className="announcement-modal-overlay" onClick={() => setSelected(null)}>
          <div
            className="announcement-modal"
            role="dialog"
            aria-modal="true"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="announcement-modal-header">
              <div className="announcement-modal-title-block">
                <div className="announcement-modal-meta">
                  <span className="tag system">• Announcement</span>
                  <span className="time">{getTimeAgo(selected.published_at)}</span>
                </div>
                <h3>{selected.title || 'Untitled post'}</h3>
              </div>
              <button
                type="button"
                className="announcement-modal-close"
                aria-label="Close"
                onClick={() => setSelected(null)}
              >
                ×
              </button>
            </div>
            <div className="announcement-modal-body">
              {selected.author && (
                <div className="announcement-author">
                  <img
                    className="announcement-author-avatar"
                    src={authorAvatarFor(selected.author)}
                    alt=""
                    onError={fallbackAvatarOnError(selected.author?.name)}
                  />
                  <span>Posted by {selected.author.name}</span>
                </div>
              )}
              <p className="announcement-detail-body">
                {selected.body || 'This announcement has no message.'}
              </p>
            </div>
            <div className="announcement-modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setSelected(null)}>
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function authorAvatarFor(author) {
  return (
    (author && author.avatar_url) ||
    `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent((author && author.name) || 'U')}&backgroundColor=2563eb`
  );
}

function getTimeAgo(value) {
  if (!value) return 'just now';
  const then = new Date(value);
  if (Number.isNaN(then.getTime())) return 'just now';
  const now = new Date();
  const seconds = Math.floor((now - then) / 1000);
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
  return then.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}
