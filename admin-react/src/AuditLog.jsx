import { useState, useEffect, useCallback, useMemo } from 'react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import { ScrollText, X, AlertTriangle, RefreshCw } from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import { formatDateTime } from './lib/datetime';
import './AuditLog.css';

/*
 * Audit Log — read-only view over the `audit_logs` trail.
 *
 * Shows who did what and when for sensitive administrator actions (role/status
 * changes, password resets, settings saves, candidate certification, 2FA
 * changes, and admin sign-in events). Backed by GET /api/admin/audit-logs,
 * which is admin-only (`audit.view`); teacher / SSG President never reach it.
 *
 * This screen is wired against the confirmed endpoint contract. Unknown action
 * codes are rendered with their raw value rather than crashing, so the backend
 * can ship new audited actions without a frontend release.
 */

const ACTION_LABELS = {
  // Admin sign-in lifecycle (added with the auth audit).
  login: 'Signed in',
  login_success: 'Signed in',
  admin_login: 'Signed in',
  login_failed: 'Sign-in failed',
  login_failure: 'Sign-in failed',
  admin_login_failed: 'Sign-in failed',
  account_locked: 'Account locked',
  login_locked: 'Account locked',
  lockout: 'Account locked',
  '2fa_failed': 'Two-factor failed',
  two_factor_failed: 'Two-factor failed',
  login_2fa_failed: 'Two-factor failed',
  logout: 'Signed out',
  // Existing audited actions.
  settings_updated: 'Settings updated',
  password_changed: 'Password changed',
  password_reset: 'Password reset',
  '2fa_enabled': 'Two-factor enabled',
  '2fa_disabled': 'Two-factor disabled',
  candidate_certified: 'Candidate certified',
  candidate_certification_revoked: 'Certification revoked',
};

const ACTION_OPTIONS = Object.keys(ACTION_LABELS).sort();

/*
 * Groups an action into a colour category for the badge. Prefix matching keeps
 * this working for future variants (e.g. `*_login_failed`) without edits.
 */
function actionCategory(action = '') {
  if (/(login|logout|lock|2fa|two_factor|password)/.test(action)) return 'auth';
  if (/(candidate|certif)/.test(action)) return 'candidate';
  if (/(settings|system|config)/.test(action)) return 'settings';
  return 'other';
}

function actionLabel(action) {
  return ACTION_LABELS[action] || action || 'Unknown action';
}

export default function AuditLog({ onLogout, activeView = 'audit', onNavigate, currentUser = null }) {
  const { logout } = useAuth();
  const [logs, setLogs] = useState([]);
  const [meta, setMeta] = useState({ total: 0, current_page: 1, last_page: 1 });
  const [loading, setLoading] = useState(true);
  const [srvErr, setSrvErr] = useState('');

  const [actionFilter, setActionFilter] = useState('');
  const [entityFilter, setEntityFilter] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const handleLogout = () => { if (typeof onLogout === 'function') return onLogout(); logout(); };

  const buildParams = useCallback((page) => {
    const p = { per_page: 25, page };
    if (actionFilter) p.action = actionFilter;
    if (entityFilter) p.entity_type = entityFilter;
    if (dateFrom) p.date_from = dateFrom;
    if (dateTo) p.date_to = dateTo;
    return p;
  }, [actionFilter, entityFilter, dateFrom, dateTo]);

  const loadLogs = useCallback(async (page = 1) => {
    setLoading(true); setSrvErr('');
    try {
      const res = await api.get('/admin/audit-logs', { params: buildParams(page) });
      const d = res.data;
      setLogs(d.data || []);
      if (d.meta) setMeta(d.meta);
    } catch (e) {
      const status = e.response?.status;
      if (status === 404) {
        // The audit endpoint ships with the backend-hardening work; until it is
        // deployed this is the expected state, so say so plainly.
        setSrvErr('The audit log endpoint is not available on this server yet.');
      } else if (status === 403) {
        setSrvErr('You do not have permission to view the audit log.');
      } else {
        setSrvErr(e.response?.data?.message || 'Failed to load the audit log.');
      }
      setLogs([]);
      setMeta({ total: 0, current_page: 1, last_page: 1 });
    } finally {
      setLoading(false);
    }
  }, [buildParams]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadLogs(1);
  }, [loadLogs]);

  const clearFilters = () => {
    setActionFilter(''); setEntityFilter(''); setDateFrom(''); setDateTo('');
    loadLogs(1);
  };
  const hasFilters = actionFilter || entityFilter || dateFrom || dateTo;

  // Offer any action codes present in the loaded rows that aren't in the static
  // label map, so operators can still filter on brand-new audited actions.
  const actionChoices = useMemo(() => {
    const seen = new Set(ACTION_OPTIONS);
    logs.forEach((l) => { if (l.action) seen.add(l.action); });
    return [...seen].sort();
  }, [logs]);

  const pages = Array.from({ length: Math.max(1, meta.last_page || 1) }, (_, i) => i + 1);

  const renderActor = (row) => row.user_name || (row.user_id != null ? `User #${row.user_id}` : 'System');

  const renderEntity = (row) => {
    if (!row.entity_type && row.entity_id == null) return '—';
    if (row.entity_type && row.entity_id != null) return `${row.entity_type} #${row.entity_id}`;
    return row.entity_type || `#${row.entity_id}`;
  };

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={handleLogout} currentUser={currentUser} brandSub="ACTIVITY LOG" statusText="Admin Panel" />
      <main className="main-content">
        <Header breadcrumb="Audit Log" onLogout={handleLogout} currentUser={currentUser} onNavigate={onNavigate} />
        <div className="al-body">
          <div className="al-header">
            <div>
              <h2 className="al-title"><ScrollText size={20} /> Audit Log</h2>
              <p className="al-subtext">A read-only record of sensitive administrator actions and sign-in events.</p>
            </div>
            <button type="button" className="al-refresh-btn" onClick={() => loadLogs(meta.current_page)} disabled={loading} title="Reload the current page">
              <RefreshCw size={15} /> Refresh
            </button>
          </div>

          <div className="al-filter-row">
            <select className="al-filter-select" value={actionFilter} onChange={e => setActionFilter(e.target.value)} aria-label="Filter by action">
              <option value="">All Actions</option>
              {actionChoices.map(a => <option key={a} value={a}>{actionLabel(a)}</option>)}
            </select>
            <select className="al-filter-select" value={entityFilter} onChange={e => setEntityFilter(e.target.value)} aria-label="Filter by entity type">
              <option value="">All Entities</option>
              <option value="user">User</option>
              <option value="candidate">Candidate</option>
              <option value="settings">Settings</option>
              <option value="election">Election</option>
            </select>
            <label className="al-filter-date">
              <span>From</span>
              <input type="date" value={dateFrom} max={dateTo || undefined} onChange={e => setDateFrom(e.target.value)} />
            </label>
            <label className="al-filter-date">
              <span>To</span>
              <input type="date" value={dateTo} min={dateFrom || undefined} onChange={e => setDateTo(e.target.value)} />
            </label>
            {hasFilters && <button type="button" className="al-clear-btn" onClick={clearFilters}><X size={14} /> Clear</button>}
          </div>

          {srvErr && <div className="al-banner al-banner-error"><AlertTriangle size={16} /> {srvErr}</div>}

          <div className="al-table-wrap">
            <table className="al-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Entity</th>
                  <th>Details</th>
                  <th>IP</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  [...Array(6)].map((_, i) => <tr key={i}>{[...Array(6)].map((_, j) => <td key={j} className="al-loading-cell" />)}</tr>)
                ) : logs.length === 0 ? (
                  <tr><td colSpan="6" className="al-empty-cell">{hasFilters ? 'No audit entries match these filters.' : 'No audit entries recorded yet.'}</td></tr>
                ) : (
                  logs.map(row => (
                    <tr key={row.id}>
                      <td className="al-time-cell">{formatDateTime(row.created_at)}</td>
                      <td className="al-actor-cell">{renderActor(row)}</td>
                      <td><span className={`al-action-badge al-action-${actionCategory(row.action)}`} title={row.action}>{actionLabel(row.action)}</span></td>
                      <td className="al-entity-cell">{renderEntity(row)}</td>
                      <td className="al-details-cell" title={row.details || ''}>{row.details || '—'}</td>
                      <td className="al-mono-cell">{row.ip_address || '—'}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {meta.last_page > 1 && (
            <div className="al-pagination">
              <button className="al-page-btn" onClick={() => loadLogs(meta.current_page - 1)} disabled={meta.current_page <= 1 || loading}>Previous</button>
              {pages.map(n => <button key={n} className={`al-page-btn ${n === meta.current_page ? 'al-page-active' : ''}`} onClick={() => loadLogs(n)} disabled={loading}>{n}</button>)}
              <button className="al-page-btn" onClick={() => loadLogs(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page || loading}>Next</button>
              <span className="al-page-info">Page {meta.current_page} of {meta.last_page} ({meta.total} total)</span>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
