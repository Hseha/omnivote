import React, { useState, useEffect } from 'react';
import { Users, Search, Eye, EyeOff, KeyRound, X, CheckCircle, AlertTriangle, Shield } from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './UserManagement.css';

const ROLES = { admin: 'Administrator', teacher: 'Teacher', candidate: 'Candidate', student: 'Student', ssg_president: 'SSG President' };
const ROLE_CLS = { admin: 'role-admin', teacher: 'role-teacher', candidate: 'role-candidate', student: 'role-student', ssg_president: 'role-ssg' };

export default function UserManagement({ onLogout, activeView = 'user_management', onNavigate }) {
  const { logout } = useAuth();
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState({ total: 0, current_page: 1, last_page: 1 });
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(null);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [srvErr, setSrvErr] = useState('');
  const [okMsg, setOkMsg] = useState('');

  const handleLogout = () => { if (typeof onLogout === 'function') return onLogout(); logout(); };

  const loadUsers = async (page = 1) => {
    setLoading(true); setSrvErr('');
    try {
      const p = { per_page: 20, page };
      if (search) p.search = search;
      if (roleFilter) p.role = roleFilter;
      if (statusFilter) p.is_active = statusFilter === 'active' ? 'true' : 'false';
      const res = await api.get('/admin/users', { params: p });
      const d = res.data;
      setUsers(d.data || []); if (d.meta) setMeta(d.meta);
    } catch (e) { setSrvErr(e.response?.data?.message || 'Failed to load users.'); }
    finally { setLoading(false); }
  };

  useEffect(() => { loadUsers(1); }, [search, roleFilter, statusFilter]);

  const doSearch = (e) => { e.preventDefault(); loadUsers(1); };
  const clearFilters = () => { setSearch(''); setRoleFilter(''); setStatusFilter(''); loadUsers(1); };
  const hasFilters = search || roleFilter || statusFilter;

  const doRoleChange = async (uid, role) => {
    setActionLoading(uid);
    try { await api.patch(`/admin/users/${uid}/role`, { role }); setUsers(p => p.map(u => u.id === uid ? { ...u, role } : u)); setOkMsg('Role updated.'); setTimeout(() => setOkMsg(''), 3000); }
    catch (e) { setSrvErr(e.response?.data?.message || 'Failed to update role.'); }
    finally { setActionLoading(null); }
  };

  const doStatusToggle = async (uid, active) => {
    setActionLoading(uid);
    try { await api.patch(`/admin/users/${uid}/status`, { is_active: active }); setUsers(p => p.map(u => u.id === uid ? { ...u, is_active: active } : u)); setOkMsg(active ? 'User enabled.' : 'User disabled.'); setTimeout(() => setOkMsg(''), 3000); }
    catch (e) { setSrvErr(e.response?.data?.message || 'Failed to update status.'); }
    finally { setActionLoading(null); }
  };

  const doPwReset = async (uid) => {
    if (!window.confirm('Generate a temporary password for this user?')) return;
    setActionLoading(uid);
    try { await api.post(`/admin/users/${uid}/password-reset`); setOkMsg('Temporary password generated.'); setTimeout(() => setOkMsg(''), 4000); }
    catch (e) { setSrvErr(e.response?.data?.message || 'Failed to reset password.'); }
    finally { setActionLoading(null); }
  };

  const pages = Array.from({ length: Math.max(1, meta.last_page) }, (_, i) => i + 1);

  return (
    <div className="um-app-container">
      <aside className="sidebar">
        <div className="logo-area"><div className="logo-icon-bg"><Users size={22} /></div><div><h1 className="brand-name">OmniVote</h1><p className="brand-sub">USER MANAGEMENT</p></div></div>
        <nav className="nav-menu">
          {[['dashboard','Dashboard'],['candidates','Candidates'],['voters','Student Registry'],['setup','Election Setup'],['results','Results'],['settings','Settings'],['user_management','User Access Mgmt']].map(([v,l]) => (
            <button key={v} className={`nav-item ${activeView === v ? 'active' : ''}`} onClick={() => onNavigate(v)}><Users size={18} /> {l}</button>
          ))}
        </nav>
        <div className="sidebar-footer-container">
          <button type="button" onClick={handleLogout} className="logout-button"><X size={18} /> Logout</button>
          <div className="sidebar-footer"><span className="status-dot-green" /> Admin Panel</div>
        </div>
      </aside>
      <main className="um-main">
        <header className="um-header">
          <div className="um-breadcrumb"><span className="muted">Users / </span><strong>User Access Management</strong></div>
          <span className="voting-status-badge"><span className="status-dot-green" /> Active Session</span>
        </header>
        <div className="um-body">
          <form className="um-search-form" onSubmit={doSearch}>
            <Search size={16} className="search-icon" />
            <input type="text" className="um-search-input" placeholder="Search name, email, or student ID..." value={search} onChange={e => setSearch(e.target.value)} />
            {hasFilters && <button type="button" className="btn-clear-filters" onClick={clearFilters}><X size={14} /> Clear</button>}
          </form>
          <div className="um-filter-row">
            <select className="um-filter-select" value={roleFilter} onChange={e => setRoleFilter(e.target.value)}>
              <option value="">All Roles</option>
              {Object.entries(ROLES).map(([v,l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <select className="um-filter-select" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div className="um-stats-row">
            <div className="um-stat-card"><div className="um-stat-value">{meta.total}</div><div className="um-stat-label">Total Users</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{users.filter(u => u.is_active).length}</div><div className="um-stat-label">Active</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{users.filter(u => !u.is_active).length}</div><div className="um-stat-label">Inactive</div></div>
            <div className="um-stat-card"><div className="um-stat-value">{users.filter(u => ['admin','teacher','ssg_president'].includes(u.role)).length}</div><div className="um-stat-label">Staff</div></div>
          </div>
          {okMsg && <div className="um-banner um-banner-success"><CheckCircle size={16} /> {okMsg}</div>}
          {srvErr && <div className="um-banner um-banner-error"><AlertTriangle size={16} /> {srvErr}</div>}
          <div className="um-table-wrap">
            <table className="um-users-table">
              <thead><tr><th>Name</th><th>Email</th><th>Student ID</th><th>Role</th><th>Status</th><th>Year/Block</th><th>Added</th><th className="text-right">Actions</th></tr></thead>
              <tbody>
                {loading ? [...Array(5)].map((_, i) => <tr key={i}>{[...Array(8)].map((_, j) => <td key={j} className="um-loading-cell" />)}</tr>) :
                users.length === 0 ? <tr><td colSpan="8" className="um-empty-cell">{actionLoading ? 'Updating...' : 'No users found.'}</td></tr> :
                users.map(u => {
                  const active = !!u.is_active;
                  return (
                    <tr key={u.id} className={active ? '' : 'um-row-inactive'}>
                      <td><div className="um-user-cell"><span className="um-avatar-initials">{(u.name || '?')[0]?.toUpperCase()}</span><span className="um-user-name">{u.name || '—'}</span></div></td>
                      <td className="um-email-cell">{u.email || '—'}</td>
                      <td className="um-mono-cell">{u.student_id || '—'}</td>
                      <td><span className={`um-role-badge ${ROLE_CLS[u.role] || 'role-student'}`}>{ROLES[u.role] || u.role}</span></td>
                      <td><span className={`um-status-badge ${active ? 'um-status-active' : 'um-status-inactive'}`}>{active ? <CheckCircle size={12} /> : <EyeOff size={12} />}{active ? 'Active' : 'Inactive'}</span></td>
                      <td className="um-muted-cell">{u.year_level || u.block_number ? `${u.year_level || ''}${u.year_level && u.block_number ? '/' : ''}${u.block_number ? 'Blk ' + u.block_number : ''}` : '—'}</td>
                      <td className="um-muted-cell">{u.date_added || '—'}</td>
                      <td className="text-right"><div className="um-action-btns">
                        <select className="um-role-select" value={u.role} onChange={e => doRoleChange(u.id, e.target.value)} disabled={actionLoading === u.id || u.id === 1} title={u.id === 1 ? 'Primary admin — cannot modify' : ''}>
                          {Object.entries(ROLES).map(([v,l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                        {u.id !== 1 && <button className={`um-toggle-btn ${active ? 'btn-disable' : 'btn-enable'}`} onClick={() => doStatusToggle(u.id, !active)} disabled={actionLoading === u.id} title={active ? 'Disable user' : 'Enable user'}>{active ? <EyeOff size={14} /> : <Eye size={14} />}</button>}
                        {u.id !== 1 && <button className="um-action-link-btn" onClick={() => doPwReset(u.id)} disabled={actionLoading === u.id} title="Reset password"><KeyRound size={14} /></button>}
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
    </div>
  );
}
