import { useCallback, useEffect, useState } from 'react';
import { LogOut, Megaphone, ShieldCheck, Trash2 } from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import MobileMenuButton from './components/MobileMenuButton';
import OmniVoteMark from './components/OmniVoteMark';
import './SsgPresident.css';

function errorMessage(error, fallback) {
  return error.response?.data?.message || error.message || fallback;
}

// Mirrors the backend rule in AnnouncementController::destroy: only the
// announcement's author may delete it — no role, admin included, can remove a
// post made by another user.
function canDeleteAnnouncement(announcement, user) {
  if (!announcement || !user) return false;
  return announcement.user_id === user.id;
}

export default function SsgPresident({ onLogout }) {
  const { user, logout } = useAuth();
  const [officers, setOfficers] = useState([]);
  const [announcements, setAnnouncements] = useState([]);
  const [draft, setDraft] = useState({ title: '', body: '', published: true });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  // Officers 403 until voting closes — that gating must NOT break the
  // announcement half of this page, so it is tracked separately.
  const [officersUnavailable, setOfficersUnavailable] = useState(false);

  const loadData = useCallback(async () => {
    setLoading(true);
    // Load the two halves independently: the roster is correctly locked until
    // voting closes (officers 403), but a single failed fetch must not blank
    // out the announcement management that the SSG President can always use.
    const [officerResult, announcementResult] = await Promise.allSettled([
      api.get('/admin/ssg/officers'),
      api.get('/admin/ssg/announcements'),
    ]);

    if (officerResult.status === 'fulfilled') {
      setOfficers(officerResult.value.data?.data || []);
      setOfficersUnavailable(false);
    } else {
      setOfficers([]);
      setOfficersUnavailable(true);
    }

    if (announcementResult.status === 'fulfilled') {
      setAnnouncements(announcementResult.value.data?.data || []);
      setError('');
    } else {
      setError(errorMessage(announcementResult.reason, 'Unable to load the SSG President dashboard.'));
    }

    setLoading(false);
  }, []);

  useEffect(() => {
    // Load the server-backed panel state after mount.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData();
  }, [loadData]);

  const deleteAnnouncement = async (announcement) => {
    if (!announcement || announcement.id == null) return;
    if (!window.confirm(`Delete "${announcement.title}"? This cannot be undone.`)) return;
    setDeletingId(announcement.id);
    setError('');
    setNotice('');
    try {
      await api.delete(`/admin/ssg/announcements/${announcement.id}`);
      setNotice('Announcement deleted.');
      await loadData();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to delete the announcement.'));
    } finally {
      setDeletingId(null);
    }
  };

  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  const createAnnouncement = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      await api.post('/admin/ssg/announcements', {
        title: draft.title.trim(),
        body: draft.body.trim(),
        published: draft.published,
      });
      setDraft({ title: '', body: '', published: true });
      setNotice('Announcement saved.');
      await loadData();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to save the announcement.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="dashboard-container">
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon"><OmniVoteMark className="sidebar-logo-img" /></div>
          <div><h1 className="brand-name">OmniVote</h1><p className="brand-sub">ELECTION CONSOLE</p></div>
        </div>
        <div className="ssg-sidebar-title"><ShieldCheck size={16} /> SSG PRESIDENT</div>
        <div className="sidebar-footer-container">
          <button type="button" onClick={handleLogout} className="logout-button">
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer"><span className="status-dot-green" /> {user?.name || 'SSG President'}</div>
        </div>
      </aside>

      <main className="main-content">
        <header className="top-header">
          <MobileMenuButton />
          <div className="breadcrumb"><span className="muted">SSG / </span><strong>President Dashboard</strong></div>
          <div className="user-profile">
            <span className="user-name">{user?.name || 'SSG President'}</span>
            <span className="user-role">SSG President</span>
          </div>
        </header>

        <div className="ssg-content">
          <div className="page-header">
            <h2>Welcome back, {(user?.name || 'SSG President').split(' ')[0]}</h2>
            <p className="page-subtext">Manage certified officers and publish student announcements.</p>
          </div>

          {error && <div className="warning-banner">{error}</div>}
          {notice && <div className="success-banner">{notice}</div>}

          <section className="card">
            <div className="card-header">
              <div><h3>Certified Officer Roster</h3><p className="muted-text">Available after voting is closed.</p></div>
            </div>
            {loading ? <p className="muted-text">Loading officer roster...</p> : officersUnavailable ? (
              <p className="muted-text">Certified officers are listed here once voting closes.</p>
            ) : officers.length === 0 ? (
              <p className="muted-text">No certified officers are available yet.</p>
            ) : (
              <div className="ssg-table-wrap"><table className="ssg-table"><thead><tr><th>Position</th><th>Officer</th><th>Votes</th></tr></thead><tbody>
                {officers.map((officer) => <tr key={`${officer.position_key}-${officer.candidate_ref}`}><td>{officer.position_label}</td><td>{officer.name}</td><td>{officer.votes}</td></tr>)}
              </tbody></table></div>
            )}
          </section>

          <section className="ssg-grid">
            <form className="card ssg-form" onSubmit={createAnnouncement}>
              <div className="card-header"><div><h3>Publish Announcement</h3><p className="muted-text">Share updates with students.</p></div><Megaphone size={20} /></div>
              <label htmlFor="announcement-title">Title</label>
              <input id="announcement-title" value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} required />
              <label htmlFor="announcement-body">Message</label>
              <textarea id="announcement-body" value={draft.body} onChange={(event) => setDraft({ ...draft, body: event.target.value })} rows="5" required />
              <label className="ssg-checkbox"><input type="checkbox" checked={draft.published} onChange={(event) => setDraft({ ...draft, published: event.target.checked })} /> Publish immediately</label>
              <button type="submit" className="primary-button" disabled={saving}>{saving ? 'Saving...' : 'Save announcement'}</button>
            </form>

            <section className="card">
              <div className="card-header"><div><h3>Recent Announcements</h3><p className="muted-text">Your published updates.</p></div></div>
              {loading ? <p className="muted-text">Loading announcements...</p> : announcements.length === 0 ? <p className="muted-text">No announcements yet.</p> : (
                <div className="ssg-announcements">{announcements.slice(0, 5).map((announcement) => <article key={announcement.id}>
                <div className="ssg-announcement-head">
                  <strong>{announcement.title}</strong>
                  {canDeleteAnnouncement(announcement, user) && (
                    <button
                      type="button"
                      className="ssg-announcement-delete"
                      aria-label={`Delete "${announcement.title}"`}
                      disabled={deletingId === announcement.id}
                      onClick={() => deleteAnnouncement(announcement)}
                    >
                      <Trash2 size={14} />
                    </button>
                  )}
                </div>
                <p className="ssg-announcement-byline">Posted by {announcement.author?.name || 'Staff'}</p>
                <p>{announcement.body}</p>
              </article>)}</div>
              )}
            </section>
          </section>
        </div>
      </main>
    </div>
  );
}
