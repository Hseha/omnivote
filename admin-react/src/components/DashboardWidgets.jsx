import {
  Users,
  Activity,
  BarChart2,
  Clock,
  Shield,
  TrendingUp,
  Megaphone,
  Eye,
} from 'lucide-react';
import { useState } from 'react';
import { fallbackAvatarOnError, initialsAvatarDataUri } from '../lib/avatar';
import './DashboardWidgets.css';

/*
 * Security & operational log severity tiers.
 *
 * `critical` — brute-force backoffs, denied sign-ins, disabled accounts.
 * `warning`  — role/settings overrides an operator should review.
 * `success`  — routine operational flags (sign-ins, backups, rotations).
 *
 * The backend sends `severity` directly on each analytics row; this map is
 * the presentation fallback for rows that only carry a raw action/type.
 */
const SEVERITY_META = {
  success: { color: '#10b981', className: 'sev-success', label: 'Operational' },
  warning: { color: '#f59e0b', className: 'sev-warning', label: 'Warning' },
  critical: { color: '#f87171', className: 'sev-critical', label: 'Critical' },
};

const DEFAULT_SEVERITY = SEVERITY_META.success;

/** Raw audit action → severity tier for the live stream. */
const SECURITY_SEVERITY = {
  account_locked: 'critical',
  login_backoff_triggered: 'critical',
  login_denied: 'critical',
  user_disabled: 'critical',
  '2fa_failed': 'critical',
  login_failed: 'warning',
  user_role_changed: 'warning',
  user_email_changed: 'warning',
  settings_updated: 'warning',
  password_reset: 'warning',
  backup_restored: 'warning',
  backup_deleted: 'warning',
  '2fa_disabled': 'warning',
  user_archived: 'warning',
};

function severityFor(log) {
  if (log && SEVERITY_META[log.severity]) return SEVERITY_META[log.severity];
  if (log) {
    // Personal-notification fallback rows carry a type of info/success/
    // warning/danger; analytics rows resolve via their raw audit action.
    if (log.type === 'danger') return SEVERITY_META.critical;
    if (SEVERITY_META[log.type]) return SEVERITY_META[log.type];
    const byAction = SECURITY_SEVERITY[log.action] || SECURITY_SEVERITY[log.type];
    if (byAction) return SEVERITY_META[byAction];
  }
  return DEFAULT_SEVERITY;
}

/** `login_backoff_triggered` → `Login Backoff Triggered`. */
function humanizeSecurityAction(action) {
  if (!action) return 'System Event';
  return String(action)
    .split('_')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/*
 * Hourly Voting Velocity — inline SVG timeline, no chart library.
 *
 * Maps the anonymous `vote_ledger` time-series ({hour, count}) into a bar
 * strip plus a smoothed polyline. Y coordinates scale off the series maximum
 * so the graph stays legible whether the peak is 3 votes or 3,000. Counts are
 * position-level ledger rows — never joined against user accounts (H-1).
 */
const VELOCITY_CHART = { width: 640, height: 170, padX: 8, padTop: 14, padBottom: 24 };

function VelocityTimeline({ data, loading, apiOk }) {
  const points = Array.isArray(data) ? data : [];
  const maxCount = Math.max(1, ...points.map((p) => toNumber(p.count)));
  const { width, height, padX, padTop, padBottom } = VELOCITY_CHART;
  const innerW = width - padX * 2;
  const innerH = height - padTop - padBottom;
  const slot = points.length > 0 ? innerW / points.length : innerW;
  const barW = Math.max(2, slot * 0.55);

  const yFor = (count) => padTop + innerH - (toNumber(count) / maxCount) * innerH;
  const xFor = (index) => padX + slot * index + slot / 2;

  const barNodes = points.map((point, index) => {
    const y = yFor(point.count);
    const x = xFor(index) - barW / 2;
    const isPeak = toNumber(point.count) === maxCount && maxCount > 0;
    return (
      <rect
        key={point.hour || index}
        className={`velocity-bar${isPeak ? ' velocity-bar-peak' : ''}`}
        x={x.toFixed(2)}
        y={y.toFixed(2)}
        width={barW.toFixed(2)}
        height={Math.max(1, padTop + innerH - y).toFixed(2)}
        rx={Math.min(3, barW / 2)}
      />
    );
  });

  const linePoints = points
    .map((point, index) => `${xFor(index).toFixed(2)},${yFor(point.count).toFixed(2)}`)
    .join(' ');

  // Axis ticks: first, middle and last hour labels only — keeps the strip
  // clean at 24 buckets without crowding.
  const ticks = points.length > 0
    ? [0, Math.floor((points.length - 1) / 2), points.length - 1]
    : [];

  return (
    <div className="velocity-chart-box">
      {points.length === 0 ? (
        <p className="no-data-text">
          {loading
            ? 'Loading voting velocity…'
            : apiOk
              ? 'No votes recorded in the last 24 hours.'
              : 'Analytics feed unreachable — velocity data may be stale.'}
        </p>
      ) : (
        <>
          <svg
            className="velocity-chart-svg"
            viewBox={`0 0 ${width} ${height}`}
            preserveAspectRatio="none"
            role="img"
            aria-label={`Hourly voting velocity, last ${points.length} hours, peak ${maxCount} votes`}
          >
            {/* Baseline + soft gridlines at 25/50/75/100 % of the peak */}
            {[0.25, 0.5, 0.75, 1].map((fraction) => (
              <line
                key={fraction}
                className="velocity-gridline"
                x1={padX}
                x2={width - padX}
                y1={(padTop + innerH - innerH * fraction).toFixed(2)}
                y2={(padTop + innerH - innerH * fraction).toFixed(2)}
              />
            ))}
            {barNodes}
            <polyline className="velocity-line" points={linePoints} fill="none" />
            <line
              className="velocity-baseline"
              x1={padX}
              x2={width - padX}
              y1={padTop + innerH}
              y2={padTop + innerH}
            />
          </svg>
          <div className="chart-x-axis" aria-hidden="true">
            {ticks.map((index) => (
              <span key={index}>{points[index]?.hour}</span>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

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
 * Server-health presentation (from the cached `system_health` snapshot written
 * by the backend `omnivote:health` timer; admin-only, may be null).
 */
const HEALTH_META = {
  ok: { color: '#10b981', label: 'Operational' },
  warn: { color: '#f59e0b', label: 'Degraded' },
  fail: { color: '#f87171', label: 'Down' },
};

const CHECK_DOT = { ok: '#10b981', warn: '#f59e0b', fail: '#f87171' };

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
  velocityData = [],
  securityLogs = [],
  loading = false,
  apiOk = true,
  systemHealth = null,
  onNavigate,
  permittedViews,
}) {
  const phase = PHASE_META[normalizePhase(electionPhase)] || DEFAULT_PHASE;
  // Real data only — the backend sends `recent_actions: []` until audit
  // logging exists, in which case the empty state is shown instead.
  const actions = Array.isArray(recentActions) ? recentActions.slice(0, 6) : [];
  // Live security / operational stream from the analytics endpoint. When the
  // feed is empty (older backend, offline) the widget falls back to the
  // signed-in admin's own recent actions so the card never lies about being
  // a global audit trail.
  const securityFeed = Array.isArray(securityLogs) && securityLogs.length > 0
    ? securityLogs.slice(0, 6)
    : actions;
  // Only surface shortcuts the signed-in role is actually allowed to open.
  const quickActions = permittedViews
    ? QUICK_ACTIONS.filter((action) => permittedViews.includes(action.target))
    : QUICK_ACTIONS;
  // The announcement being read in full (dashboard overview sends title +
  // body + author; the modal shows the whole thing).
  const [selected, setSelected] = useState(null);

  // Prefer the richer server-health snapshot when the backend sent one;
  // otherwise fall back to the coarse API-reachability signal.
  const healthMeta = systemHealth?.overall ? HEALTH_META[systemHealth.overall] : null;
  const healthChecks = systemHealth?.checks ? Object.values(systemHealth.checks) : [];
  const statusColor = healthMeta ? healthMeta.color : (apiOk ? '#10b981' : '#f87171');
  const statusLabel = healthMeta ? healthMeta.label : (apiOk ? 'Connected' : 'Unreachable');

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

      {/* ===== Hourly voting velocity — anonymous ledger aggregate ===== */}
      <section className="chart-card card" aria-label="Voting velocity">
        <div className="widget-header">
          <div>
            <h3>Voting Velocity</h3>
            <p className="muted-text">Ballot submissions per hour · last 24 h</p>
          </div>
          <span className="widget-header-icon"><BarChart2 size={16} /></span>
        </div>
        <VelocityTimeline data={velocityData} loading={loading} apiOk={apiOk} />
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
                    background: statusColor + '20',
                    color: statusColor,
                  }}
                >
                  <Activity size={18} />
                </span>
                <div>
                  <div className="widget-title">System Status</div>
                  <div className="widget-sub">{healthMeta ? 'Server health' : 'API connectivity'}</div>
                </div>
              </div>
              <span
                className="widget-pill"
                style={{
                  color: statusColor,
                  background: statusColor + '1a',
                  borderColor: statusColor + '33',
                }}
              >
                <span className="pill-dot" style={{ background: statusColor }} />
                {statusLabel}
              </span>
            </div>

            {healthMeta ? (
              <>
                <ul className="health-checks">
                  {healthChecks.map((check, idx) => (
                    <li key={check.label || idx} className="health-check">
                      <span
                        className="health-check-dot"
                        style={{ background: CHECK_DOT[check.status] || '#64748b' }}
                      />
                      <span className="health-check-label">{check.label}</span>
                      <span className="health-check-msg">{check.message}</span>
                    </li>
                  ))}
                </ul>
                <p className="widget-desc">Last checked {getTimeAgo(systemHealth.checked_at)}</p>
              </>
            ) : (
              <p className="widget-desc">
                {apiOk
                  ? 'Dashboard data is live from the election API.'
                  : 'Could not reach the election API. Metrics may be stale.'}
              </p>
            )}
          </div>
        </div>

        <section className="audit-widget card">
          <div className="widget-header">
            <div>
              <h3>Security &amp; System Log</h3>
              <p className="muted-text">
                {securityLogs.length > 0
                  ? 'Live operational &amp; security events'
                  : 'Your recent activity'}
              </p>
            </div>
            <span className="widget-header-icon"><Shield size={16} /></span>
          </div>
          <div className="audit-list">
            {securityFeed.length === 0 ? (
              <p className="no-data-text">
                {loading
                  ? 'Fetching system log…'
                  : apiOk
                    ? 'No system events logged yet.'
                    : 'System log unreachable — events may be missing.'}
              </p>
            ) : (
              securityFeed.map((item, index) => {
                // Analytics rows carry `{action,label,severity,detail,at}`;
                // the personal recent-actions fallback carries
                // `{type,title,body,created_at}` — both normalize here.
                const severity = severityFor(item);
                const eventLabel = item.label || item.title || humanizeSecurityAction(item.action || item.type);
                const eventDetail = item.detail || item.body || item.target;
                const occurredAt = item.at || item.created_at;
                return (
                  <div
                    key={item.id ?? index}
                    className={`audit-row security-row ${severity.className}`}
                  >
                    <span
                      className="security-dot"
                      style={{ background: severity.color }}
                      aria-hidden="true"
                    />
                    <div className="audit-text">
                      <div className="audit-desc">
                        <span className="audit-event">{eventLabel}</span>
                        {eventDetail && <span className="audit-target">: {eventDetail}</span>}
                      </div>
                      <div className="audit-time">
                        <span className="security-severity-tag">{severity.label}</span>
                        {' · '}
                        {getTimeAgo(occurredAt)}
                      </div>
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
  return (author && author.avatar_url) || initialsAvatarDataUri((author && author.name) || 'U');
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
