import { useState, useEffect, useCallback } from 'react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import {
  Search, Eye, EyeOff, KeyRound, X, CheckCircle, AlertTriangle,
  Lock, Unlock, UserPlus, Download, ShieldCheck, Award, Mail,
  Archive, ArchiveRestore,
} from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './UserManagement.css';

const ROLES = {
  admin: 'Administrator',
  teacher: 'SSG Adviser',
  candidate: 'Candidate',
  student: 'Student',
  ssg_president: 'SSG President',
};
// ssg_president is intentionally NOT a generic dropdown option: it is a
// single-seat role granted only to a certified election winner via the
// dedicated "Grant SSG President Access" action.
const ROLE_OPTIONS = ['admin', 'teacher', 'candidate', 'student'];
const ROLE_CLS = {
  admin: 'role-admin', teacher: 'role-teacher', candidate: 'role-candidate',
  student: 'role-student', ssg_president: 'role-ssg',
};

export default function UserManagement({ onLogout, activeView = 'user_management', onNavigate, currentUser = null }) {
  const { logout } = useAuth();
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState({ total: 0, current_page: 1, last_page: 1 });
  const [stats, setStats] = useState(null);
  const [departments, setDepartments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(null);
  const [bulkUnlocking, setBulkUnlocking] = useState(false);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [deptFilter, setDeptFilter] = useState('');
  const [reviewOnly, setReviewOnly] = useState(false);
  const [showArchived, setShowArchived] = useState(false);
  const [srvErr, setSrvErr] = useState('');
  const [okMsg, setOkMsg] = useState('');

  // "Create Teacher Account" modal state
  const [showCreate, setShowCreate] = useState(false);
  const [createForm, setCreateForm] = useState({ name: '', email: '', role: 'teacher', department: '' });
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');
  const [createdCredential, setCreatedCredential] = useState(null);

  // "Grant SSG President Access" modal state
  const [showGrant, setShowGrant] = useState(false);
  const [winners, setWinners] = useState(null);
  const [winnersLoading, setWinnersLoading] = useState(false);
  const [grantLoading, setGrantLoading] = useState(null);

  // "Edit email address" modal state
  const [emailEditor, setEmailEditor] = useState(null);
  const [emailSaving, setEmailSaving] = useState(false);
  const [emailError, setEmailError] = useState('');

  // "Archive user" (strict, type-name-to-confirm) modal state
  const [archiveTarget, setArchiveTarget] = useState(null);
  const [archiveNameInput, setArchiveNameInput] = useState('');
  const [archiveBusy, setArchiveBusy] = useState(false);
  const [archiveError, setArchiveError] = useState('');

  const handleLogout = () => { if (typeof onLogout === 'function') return onLogout(); logout(); };

  const buildParams = useCallback((page) => {
    // Cap the list at 10 rows per page so the table never grows vertically;
    // the existing Previous/Next pagination covers the rest.
    const p = { per_page: 10, page };
    if (search) p.search = search;
    if (roleFilter) p.role = roleFilter;
    if (deptFilter) p.department = deptFilter;
    if (reviewOnly) p.needs_review = 'true';
    if (showArchived) p.archived = 'true';
    if (statusFilter === 'active') p.status = 'active';
    else if (statusFilter === 'inactive') p.status = 'inactive';
    else if (statusFilter === 'locked') p.status = 'locked';
    else if (statusFilter === 'enabled') p.is_active = 'true';
    else if (statusFilter === 'disabled') p.is_active = 'false';
    return p;
  }, [search, roleFilter, deptFilter, reviewOnly, showArchived, statusFilter]);

  const loadUsers = useCallback(async (page = 1) => {
    setLoading(true); setSrvErr('');
    try {
      const res = await api.get('/admin/users', { params: buildParams(page) });
      const d = res.data;
      setUsers(d.data || []);
      if (d.meta) setMeta(d.meta);
      // Global aggregates come with every listing response.
      if (d.stats) setStats(d.stats);
      if (d.departments) setDepartments(d.departments);
    } catch (e) { setSrvErr(e.response?.data?.message || 'Failed to load users.'); }
    finally { setLoading(false); }
  }, [buildParams]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadUsers(1);
  }, [loadUsers]);

  const doSearch = (e) => { e.preventDefault(); loadUsers(1); };
  const clearFilters = () => {
    setSearch(''); setRoleFilter(''); setStatusFilter(''); setDeptFilter(''); setReviewOnly(false); setShowArchived(false);
    loadUsers(1);
  };
  const hasFilters = search || roleFilter || statusFilter || deptFilter || reviewOnly || showArchived;

  const flash = (msg) => { setOkMsg(msg); setTimeout(() => setOkMsg(''), 4000); };

  const doRoleChange = async (uid, role) => {
    setActionLoading(uid);
    try { await api.patch(`/admin/users/${uid}/role`, { role }); setUsers(p => p.map(u => u.id === uid ? { ...u, role } : u)); flash('Role updated.'); }
    catch (e) { setSrvErr(e.response?.data?.message || 'Failed to update role.'); }
    finally { setActionLoading(null); }
  };

  const doStatusToggle = async (uid, active) => {
    setActionLoading(uid);
    try { await api.patch(`/admin/users/${uid}/status`, { is_active: active }); setUsers(p => p.map(u => u.id === uid ? { ...u, is_active: active } : u)); flash(active ? 'User enabled.' : 'User disabled.'); }
    catch (e) { setSrvErr(e.response?.data?.message || 'Failed to update status.'); }
    finally { setActionLoading(null); }
  };

  const startEmailEdit = (u) => {
    setEmailError('');
    setEmailEditor({ user: u, email: u.email || '' });
  };

  const doEmailEdit = async (e) => {
    e.preventDefault();
    if (!emailEditor) return;
    setEmailSaving(true); setEmailError('');
    try {
      const res = await api.patch(`/admin/users/${emailEditor.user.id}/email`, { email: emailEditor.email });
      const updated = res.data?.data ?? emailEditor.user;
      setUsers(p => p.map(u => u.id === emailEditor.user.id ? { ...u, ...updated } : u));
      setEmailEditor(null);
      flash('Email address updated.');
    } catch (err) {
      setEmailError(err.response?.data?.message || 'Failed to update email address.');
    } finally { setEmailSaving(false); }
  };

  const doPwReset = async (uid) => {
    if (!window.confirm('Generate a temporary password for this user?')) return;
    setActionLoading(uid);
    try {
      const res = await api.post(`/admin/users/${uid}/password-reset`);
      flash(`Temporary password: ${res.data.temporary_password}`);
    } catch (e) { setSrvErr(e.response?.data?.message || 'Failed to reset password.'); }
    finally { setActionLoading(null); }
  };

  // Item 7: clear the failed-login lockout (same state the login flow sets).
  const doUnlock = async (uid) => {
    setActionLoading(uid);
    try {
      const res = await api.post(`/admin/users/${uid}/unlock`);
      setUsers(p => p.map(u => u.id === uid ? { ...res.data.data } : u));
      flash('Account unlocked.');
    } catch (e) { setSrvErr(e.response?.data?.message || 'Failed to unlock account.'); }
    finally { setActionLoading(null); }
  };

  // Bulk clear of every account currently in a login backoff (assessment M-3).
  //
  // The backoff is now exponential rather than a permanent lock, so mass
  // lockouts should be self-limiting. They still happen: a school-wide NAT can
  // have many students hit the per-IP limiter together, and a bot spraying one
  // handle can put a single voter into backoff. This is the escape hatch that
  // stops an operator from having to click through hundreds of rows, and it only
  // touches accounts with a live window, so it cannot silently reset a healthy
  // account's failure history.
  const doBulkUnlock = async () => {
    setBulkUnlocking(true);
    setSrvErr('');
    try {
      const res = await api.post('/admin/users/bulk-unlock');
      const count = res.data.unlocked_count ?? 0;
      flash(count === 1 ? 'Cleared the backoff for 1 account.' : `Cleared the backoff for ${count} accounts.`);
      // The unlocked rows are still in the table, so refetch rather than
      // guessing which ones changed.
      await loadUsers();
    } catch (e) {
      setSrvErr(e.response?.data?.message || 'Failed to clear login backoffs.');
    } finally {
      setBulkUnlocking(false);
    }
  };


  // Item 3: staff account creation (teacher/admin) via POST /admin/users.
  const doCreate = async (e) => {
    e.preventDefault();
    setCreating(true); setCreateError('');
    try {
      const res = await api.post('/admin/users', createForm);
      setCreatedCredential(res.data);
      setUsers(p => [res.data.user, ...p]);
      setStats(s => s ? { ...s, total: s.total + 1, staff: s.staff + 1, by_role: { ...s.by_role, [res.data.user.role]: (s.by_role?.[res.data.user.role] || 0) + 1 } } : s);
      loadUsers(1);
    } catch (e2) {
      setCreateError(e2.response?.data?.message || 'Failed to create the account.');
    } finally { setCreating(false); }
  };

  const openArchive = (u) => {
    setArchiveError('');
    setArchiveNameInput('');
    setArchiveTarget(u);
  };

  const doArchive = async () => {
    if (!archiveTarget) return;
    // Strict confirmation: the typed name must match the account's full name
    // (case-insensitive). The Archive button stays disabled until it does.
    const typed = archiveNameInput.trim().toLowerCase();
    const expected = (archiveTarget.name || '').trim().toLowerCase();
    if (typed !== expected) return;
    setArchiveBusy(true); setArchiveError('');
    try {
      await api.post(`/admin/users/${archiveTarget.id}/archive`);
      setArchiveTarget(null);
      flash(`${archiveTarget.name} archived.`);
      loadUsers(meta.current_page);
    } catch (e) {
      setArchiveError(e.response?.data?.message || 'Could not archive the account.');
    } finally { setArchiveBusy(false); }
  };

  const doUnarchive = async (u) => {
    setActionLoading(u.id);
    try {
      await api.post(`/admin/users/${u.id}/unarchive`);
      flash(`${u.name} restored.`);
      loadUsers(meta.current_page);
    } catch (e) { setSrvErr(e.response?.data?.message || 'Could not restore the account.'); }
    finally { setActionLoading(null); }
  };

  // Item 4: load certified winners for the SSG grant dialog.
  const openGrant = async () => {
    setShowGrant(true); setWinnersLoading(true); setSrvErr('');
    try {
      const res = await api.get('/admin/users/certified-winners');
      setWinners(res.data);
    } catch (e) {
      setSrvErr(e.response?.data?.message || 'Failed to load certified winners.');
      setShowGrant(false);
    } finally { setWinnersLoading(false); }
  };

  const doGrant = async (uid, name) => {
    if (!window.confirm(`Grant the SSG President role to ${name}? Any previous holder loses access.`)) return;
    setGrantLoading(uid);
    try {
      await api.post(`/admin/users/${uid}/grant-ssg`);
      flash(`${name} is now SSG President.`);
      setShowGrant(false);
      loadUsers(meta.current_page);
    } catch (e) { setSrvErr(e.response?.data?.message || 'Failed to grant SSG President access.'); }
    finally { setGrantLoading(null); }
  };

  // Item 10: CSV export honouring the currently applied filters.
  const doExport = async () => {
    setSrvErr('');
    try {
      const res = await api.get('/admin/users/export', { params: buildParams(1), responseType: 'blob' });
      const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `omnivote-users-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      flash('Export downloaded.');
    } catch { setSrvErr('Failed to export users.'); }
  };

  const pages = Array.from({ length: Math.max(1, meta.last_page) }, (_, i) => i + 1);

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={handleLogout} currentUser={currentUser} brandSub="USER MANAGEMENT" statusText="Admin Panel" />
      <main className="main-content">
        <Header breadcrumb="User Access Management" onLogout={handleLogout} currentUser={currentUser} onNavigate={onNavigate} />
        <div className="um-body">
          <div className="um-header">
            <div className="um-breadcrumb"><strong>User Access Management</strong></div>
            <div className="um-header-actions">
              <button type="button" className="um-header-btn" onClick={doExport} title="Export the currently filtered user list as CSV">
                <Download size={16} /> Export CSV
              </button>
              <button type="button" className="um-header-btn um-header-btn-primary"         onClick={() => { setShowCreate(true); setCreateError(''); setCreatedCredential(null); setCreateForm({ name: '', email: '', role: 'teacher', department: '' }); }} title="Create an SSG Adviser or Administrator account">
                <UserPlus size={16} /> Create Account
              </button>
              <button type="button" className="um-header-btn um-header-btn-ssg" onClick={openGrant} title="Grant SSG President access to a certified election winner">
                <Award size={16} /> Grant SSG President
              </button>
          </div>
          </div>

          <form className="um-search-form" onSubmit={doSearch}>
            <Search size={16} className="um-search-icon" />
            <input type="text" className="um-search-input" placeholder="Search name, email, or student ID..." value={search} onChange={e => setSearch(e.target.value)} />
            {hasFilters && <button type="button" className="btn-clear-filters" onClick={clearFilters}><X size={14} /> Clear</button>}
          </form>
          <div className="um-filter-row">
            <select className="um-filter-select" value={roleFilter} onChange={e => setRoleFilter(e.target.value)} aria-label="Filter by role">
              <option value="">All Roles</option>
              {Object.entries(ROLES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <select className="um-filter-select" value={statusFilter} onChange={e => setStatusFilter(e.target.value)} aria-label="Filter by status">
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="locked">In login backoff</option>
            </select>
            <select className="um-filter-select" value={deptFilter} onChange={e => setDeptFilter(e.target.value)} aria-label="Filter by department">
              <option value="">All Departments</option>
              {[...departments].sort((a, b) => a.localeCompare(b)).map(d => <option key={d} value={d}>{d}</option>)}
            </select>
            <label className="um-filter-check" title="Show only accounts flagged by the registrar import">
              <input type="checkbox" checked={reviewOnly} onChange={e => setReviewOnly(e.target.checked)} />
              Needs review ({stats?.needs_review ?? 0})
            </label>
            <label className="um-filter-check" title="Show archived accounts (hidden from totals and the default list) so they can be restored">
              <input type="checkbox" checked={showArchived} onChange={e => setShowArchived(e.target.checked)} />
              Show archived ({stats?.archived ?? 0})
            </label>
            {(stats?.locked ?? 0) > 0 && (
              <button
                type="button"
                className="um-toggle-btn btn-unlock"
                onClick={doBulkUnlock}
                disabled={bulkUnlocking}
                title="Clear the exponential login backoff for every account currently waiting. Accounts without a live window are left untouched."
              >
                {bulkUnlocking ? 'Clearing…' : `Clear backoff (${stats.locked})`}
              </button>
            )}
          </div>
          <div className="um-stats-row">
            <div className="um-stat-card"><div className="um-stat-value">{stats ? stats.total : meta.total}</div><div className="um-stat-label">Total Users</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.active ?? '—'}</div><div className="um-stat-label">Active</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.inactive ?? '—'}</div><div className="um-stat-label">Inactive</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.locked ?? '—'}</div><div className="um-stat-label">In Backoff</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.by_role?.admin ?? '—'}</div><div className="um-stat-label">Admins</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.by_role?.teacher ?? '—'}</div><div className="um-stat-label">SSG Advisers</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.by_role?.student ?? '—'}</div><div className="um-stat-label">Students</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.by_role?.candidate ?? '—'}</div><div className="um-stat-label">Candidates</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats ? (stats.ssg_president.assigned > 0 ? 'Yes' : 'No') : '—'}</div><div className="um-stat-label">SSG President</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.staff ?? '—'}</div><div className="um-stat-label">Staff</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{stats?.archived ?? '—'}</div><div className="um-stat-label">Archived</div></div>
          </div>
          {okMsg && <div className="um-banner um-banner-success"><CheckCircle size={16} /> {okMsg}</div>}
          {srvErr && <div className="um-banner um-banner-error"><AlertTriangle size={16} /> {srvErr}</div>}
          <div className="um-table-wrap">
            <table className="um-users-table">
              <thead><tr><th>Name</th><th>Email</th><th>Student ID</th><th>Role</th><th>Status</th><th>Department</th><th>Year/Block</th><th>Added</th><th className="text-right">Actions</th></tr></thead>
              <tbody>
                {loading ? [...Array(5)].map((_, i) => <tr key={i}>{[...Array(9)].map((_, j) => <td key={j} className="um-loading-cell" />)}</tr>) :
                users.length === 0 ? <tr><td colSpan="9" className="um-empty-cell">{actionLoading ? 'Updating...' : 'No users found.'}</td></tr> :
                users.map(u => {
                  const active = !!u.is_active;
                  const locked = !!u.locked;
                  const archived = !!u.archived;
                  return (
                    <tr key={u.id} className={active && !archived ? '' : 'um-row-inactive'}>
                      <td>
                        <div className="um-user-cell">
                          <span className={`um-avatar-initials ${u.needs_review ? 'um-avatar-review' : ''}`} title={u.needs_review ? u.review_reason || 'Needs review' : undefined}>{(u.name || '?')[0]?.toUpperCase()}</span>
                          <span className="um-user-name">
                            {u.name || '—'}
                            {u.needs_review && <span className="um-review-flag" title={u.review_reason || 'Incomplete registrar record'}><AlertTriangle size={12} /> Needs review</span>}
                          </span>
                        </div>
                      </td>
                      <td className="um-email-cell" data-label="Email">{u.email || '—'}</td>
                      <td className="um-mono-cell" data-label="Student ID">{u.student_id || '—'}</td>
                      <td data-label="Role"><span className={`um-role-badge ${ROLE_CLS[u.role] || 'role-student'}`}>{ROLES[u.role] || u.role}</span></td>
                      <td data-label="Status">
                        {archived ? (
                          <span className="um-status-badge um-status-archived" title={`Archived${u.archived_at_date ? ' ' + u.archived_at_date : ''}`}><Archive size={12} /> Archived</span>
                        ) : locked ? (
                          <span className="um-status-badge um-status-locked" title={`Locked until ${u.locked_until || 'later'} after failed login attempts`}><Lock size={12} /> Locked</span>
                        ) : (
                          <span className={`um-status-badge ${active ? 'um-status-active' : 'um-status-inactive'}`}>{active ? <CheckCircle size={12} /> : <EyeOff size={12} />}{active ? 'Active' : 'Inactive'}</span>
                        )}
                      </td>
                      <td className="um-muted-cell" data-label="Department">{u.department || '—'}</td>
                      <td className="um-muted-cell" data-label="Year / Block">{u.year_level || u.block_number ? `${u.year_level || ''}${u.year_level && u.block_number ? '/' : ''}${u.block_number ? 'Blk ' + u.block_number : ''}` : '—'}</td>
                      <td className="um-muted-cell" data-label="Added">{u.date_added || '—'}</td>
                      <td className="text-right um-actions-td" data-label="Actions"><div className="um-action-btns">
                        {archived ? (
                          <button className="um-toggle-btn btn-enable" onClick={() => doUnarchive(u)} disabled={actionLoading === u.id} title="Restore this archived account — returns it to the counts and sign-in"><ArchiveRestore size={14} /> Restore</button>
                        ) : (
                          <>
                            <select className="um-role-select" value={u.role} onChange={e => doRoleChange(u.id, e.target.value)} disabled={actionLoading === u.id || u.id === 1} title="Change this user's role (SSG President is granted separately to certified winners only)">
                              {ROLE_OPTIONS.map(v => <option key={v} value={v}>{ROLES[v]}</option>)}
                            </select>
                            {u.id !== 1 && locked && <button className="um-toggle-btn btn-unlock" onClick={() => doUnlock(u.id)} disabled={actionLoading === u.id} title="Unlock account — clears the failed-login lockout"><Unlock size={14} /> Unlock</button>}
                            {u.id !== 1 && !locked && <button className={`um-toggle-btn ${active ? 'btn-disable' : 'btn-enable'}`} onClick={() => doStatusToggle(u.id, !active)} disabled={actionLoading === u.id} title={active ? 'Disable user — blocks sign-in without deleting the account' : 'Enable user — restores sign-in'}>{active ? <EyeOff size={14} /> : <Eye size={14} />}</button>}
                            {u.id !== 1 && <button className="um-action-link-btn" onClick={() => startEmailEdit(u)} disabled={actionLoading === u.id} title="Edit email address — fixes typos so students can still sign in"><Mail size={14} /></button>}
                            {u.id !== 1 && <button className="um-action-link-btn" onClick={() => doPwReset(u.id)} disabled={actionLoading === u.id} title="Reset password — generates a one-time temporary password and signs this user out everywhere"><KeyRound size={14} /></button>}
                            {u.id !== 1 && <button className="um-toggle-btn btn-archive" onClick={() => openArchive(u)} disabled={actionLoading === u.id} title="Archive user — removes them from Total Users and all stats, hides them from the list, and blocks sign-in. Kept for records; can be restored."><Archive size={14} /> Archive</button>}
                          </>
                        )}
                      </div></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {meta.last_page > 1 && (
            <div className="um-pagination">
              <button className="um-page-btn" onClick={() => loadUsers(meta.current_page - 1)} disabled={meta.current_page <= 1 || loading}>Previous</button>
              {pages.map(n => <button key={n} className={`um-page-btn ${n === meta.current_page ? 'um-page-active' : ''}`} onClick={() => loadUsers(n)} disabled={loading}>{n}</button>)}
              <button className="um-page-btn" onClick={() => loadUsers(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page || loading}>Next</button>
              <span className="um-page-info">Page {meta.current_page} of {meta.last_page} ({meta.total} total)</span>
            </div>
          )}
        </div>
      </main>

      {/* Item 3: Create Teacher/Admin account modal */}
      {showCreate && (
        <div className="um-modal-overlay" onClick={() => { setShowCreate(false); setCreatedCredential(null); }}>
          <div className="um-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Create staff account">
            {createdCredential ? (
              <>
                <h3 className="um-modal-title"><CheckCircle size={18} /> Account created</h3>
                <p className="um-modal-text">
                  <strong>{createdCredential.user.name}</strong> ({ROLES[createdCredential.user.role]}) was created.
                  Share this one-time temporary password — it is shown only now:
                </p>
                <div className="um-temp-password"><code>{createdCredential.temporary_password}</code></div>
                <div className="um-modal-actions">
                  <button type="button" className="um-btn-primary" onClick={() => { setShowCreate(false); setCreatedCredential(null); }}>Done</button>
                </div>
              </>
            ) : (
              <form onSubmit={doCreate}>
                <h3 className="um-modal-title"><UserPlus size={18} /> Create staff account</h3>
                {createError && <div className="um-banner um-banner-error"><AlertTriangle size={14} /> {createError}</div>}
                <label className="um-field">
                  <span>Full name</span>
                  <input type="text" required value={createForm.name} onChange={e => setCreateForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. Maria Santos" />
                </label>
                <label className="um-field">
                  <span>Email</span>
                  <input type="email" required value={createForm.email} onChange={e => setCreateForm(f => ({ ...f, email: e.target.value }))} placeholder="name@school.edu" />
                </label>
                <label className="um-field">
                  <span>Role</span>
                  <select value={createForm.role} onChange={e => setCreateForm(f => ({ ...f, role: e.target.value }))}>
                    <option value="teacher">SSG Adviser</option>
                    <option value="admin">Administrator</option>
                  </select>
                </label>
                <label className="um-field">
                  <span>Department (optional)</span>
                  <select value={createForm.department} onChange={e => setCreateForm(f => ({ ...f, department: e.target.value }))}>
                    <option value="">— None —</option>
                    {[...departments].sort((a, b) => a.localeCompare(b)).map(d => <option key={d} value={d}>{d}</option>)}
                  </select>
                </label>
                <div className="um-modal-actions">
                  <button type="button" className="um-btn-ghost" onClick={() => setShowCreate(false)} disabled={creating}>Cancel</button>
                  <button type="submit" className="um-btn-primary" disabled={creating}>{creating ? 'Creating…' : 'Create account'}</button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      {/* Item 4: Grant SSG President access (certified winners only) */}
      {showGrant && (
        <div className="um-modal-overlay" onClick={() => setShowGrant(false)}>
          <div className="um-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Grant SSG President access">
            <h3 className="um-modal-title"><ShieldCheck size={18} /> Grant SSG President access</h3>
            <p className="um-modal-text">
              Only certified election winners are eligible. This is a single-seat role —
              granting it removes any previous holder.
            </p>
            {winnersLoading && <p className="um-modal-text">Loading certified winners…</p>}
            {winners && winners.data.length === 0 && (
              <div className="um-banner um-banner-error">
                <AlertTriangle size={14} />
                <span>
                  No certified winners yet. Certify a candidate first (Results →
                  certify the ratified winner) before granting SSG President access.
                </span>
              </div>
            )}
            {winners && winners.data.length > 0 && (
              <>
                {winners.ssg_president && (
                  <p className="um-modal-text">
                    Current holder: <strong>{winners.ssg_president.name}</strong> ({winners.ssg_president.email})
                  </p>
                )}
                <div className="um-winner-list">
                  {winners.data.map(w => (
                    <div key={w.candidate_id} className="um-winner-row">
                      <div>
                        <div className="um-winner-name">{w.name || '—'} {w.already_ssg_president && <span className="um-review-flag">Current SSG President</span>}</div>
                        <div className="um-winner-meta">{w.position || 'Position'} • {w.email || 'no email'} • certified {w.certified_at || '—'}</div>
                      </div>
                      {!w.already_ssg_president && w.user_id && (
                        <button type="button" className="um-btn-primary" onClick={() => doGrant(w.user_id, w.name || w.email)} disabled={grantLoading === w.user_id}>
                          {grantLoading === w.user_id ? 'Granting…' : 'Grant'}
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              </>
            )}
            <div className="um-modal-actions">
              <button type="button" className="um-btn-ghost" onClick={() => setShowGrant(false)}>Close</button>
            </div>
          </div>
        </div>
      )}

      {/* Edit email address modal */}
      {emailEditor && (
        <div className="um-modal-overlay" onClick={() => { if (!emailSaving) setEmailEditor(null); }}>
          <div className="um-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Edit email address">
            <form onSubmit={doEmailEdit}>
              <h3 className="um-modal-title"><Mail size={18} /> Edit email address</h3>
              {emailError && <div className="um-banner um-banner-error"><AlertTriangle size={14} /> {emailError}</div>}
              <p className="um-modal-text">
                Changing the email for <strong>{emailEditor.user.name || emailEditor.user.email || 'this user'}</strong> updates
                their sign-in handle on the mobile app. Use a valid address they can access.
              </p>
              <label className="um-field">
                <span>Email address</span>
                <input
                  type="email"
                  required
                  value={emailEditor.email}
                  onChange={e => setEmailEditor(ed => ({ ...ed, email: e.target.value }))}
                  placeholder="name@school.edu"
                  autoFocus
                />
              </label>
              <div className="um-modal-actions">
                <button type="button" className="um-btn-ghost" onClick={() => setEmailEditor(null)} disabled={emailSaving}>Cancel</button>
                <button type="submit" className="um-btn-primary" disabled={emailSaving}>{emailSaving ? 'Saving…' : 'Save email'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Strict archive confirmation: requires typing the account's full name */}
      {archiveTarget && (
        <div className="um-modal-overlay" onClick={() => { if (!archiveBusy) setArchiveTarget(null); }}>
          <div className="um-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Archive user">
            <h3 className="um-modal-title um-modal-title-danger"><Archive size={18} /> Archive this account?</h3>
            {archiveError && <div className="um-banner um-banner-error"><AlertTriangle size={14} /> {archiveError}</div>}
            <p className="um-modal-text">
              Archiving <strong>{archiveTarget.name || archiveTarget.email}</strong> removes them from
              <strong> Total Users</strong> and every role/status count, hides them from this list, and
              blocks them from signing in. Their record is kept — votes, candidacy history, and audit
              trail stay intact — and the account can be restored from the archived view.
            </p>
            <p className="um-modal-text um-modal-danger-note">
              To prevent archiving the wrong person, type the account&rsquo;s full name exactly:
            </p>
            <div className="um-archive-confirm-name">{archiveTarget.name || '—'}</div>
            <label className="um-field">
              <span>Type the user&rsquo;s full name to confirm</span>
              <input
                type="text"
                value={archiveNameInput}
                onChange={e => setArchiveNameInput(e.target.value)}
                placeholder={archiveTarget.name || ''}
                autoFocus
                disabled={archiveBusy}
              />
            </label>
            <div className="um-modal-actions">
              <button type="button" className="um-btn-ghost" onClick={() => setArchiveTarget(null)} disabled={archiveBusy}>Cancel</button>
              <button
                type="button"
                className="um-btn-danger"
                onClick={doArchive}
                disabled={
                  archiveBusy ||
                  !(archiveTarget.name || '').trim() ||
                  archiveNameInput.trim().toLowerCase() !== (archiveTarget.name || '').trim().toLowerCase()
                }
              >
                {archiveBusy ? 'Archiving…' : 'Archive account'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
