import { useCallback, useEffect, useState } from 'react';
import { LogOut, Megaphone, ShieldCheck, Vote } from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './SsgPresident.css';

function errorMessage(error, fallback) {
  return error.response?.data?.message || error.message || fallback;
}

export default function SsgPresident({ onLogout }) {
  const { user, logout } = useAuth();
  const [officers, setOfficers] = useState([]);
  const [announcements, setAnnouncements] = useState([]);
  const [draft, setDraft] = useState({ title: '', body: '', published: true });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [officerResponse, announcementResponse] = await Promise.all([
        api.get('/admin/ssg/officers'),
        api.get('/admin/ssg/announcements'),
      ]);
      setOfficers(officerResponse.data?.data || []);
      setAnnouncements(announcementResponse.data?.data || []);
      setError('');
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to load the SSG President dashboard.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // Load the server-backed panel state after mount.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData();
  }, [loadData]);

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
          <div className="logo-icon"><Vote size={20} /></div>
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
          <div className="breadcrumb"><span className="muted">SSG / </span><strong>President Dashboard</strong></div>
          <div className="user-profile">
            <span className="user-name">{user?.name || 'SSG President'}</span>
            <span className="user-role">SSG President</span>
          </div>
        </header>

        <div className="ssg-content">
          <div className="page-header">
            <h2>SSG President Dashboard</h2>
            <p className="page-subtext">Manage certified officers and publish student announcements.</p>
          </div>

          {error && <div className="warning-banner">{error}</div>}
          {notice && <div className="success-banner">{notice}</div>}

          <section className="card">
            <div className="card-header">
              <div><h3>Certified Officer Roster</h3><p className="muted-text">Available after voting is closed.</p></div>
            </div>
            {loading ? <p className="muted-text">Loading officer roster...</p> : officers.length === 0 ? (
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
                <div className="ssg-announcements">{announcements.slice(0, 5).map((announcement) => <article key={announcement.id}><strong>{announcement.title}</strong><p>{announcement.body}</p></article>)}</div>
              )}
            </section>
          </section>
        </div>
      </main>
    </div>
  );
}
