import { useCallback, useEffect, useState } from 'react';
import { Megaphone, Send, Trash2 } from 'lucide-react';
import api from './lib/api';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import './Announcements.css';

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

function formatDate(value) {
  if (!value) return '';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
}

export default function Announcements({
  onLogout,
  activeView = 'announcements',
  onNavigate,
  currentUser = null,
}) {
  const [announcements, setAnnouncements] = useState([]);
  const [draft, setDraft] = useState({ title: '', body: '', published: true });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadAnnouncements = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await api.get('/admin/ssg/announcements');
      setAnnouncements(res.data?.data || []);
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to load announcements.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // Load the server-backed announcement list after mount.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadAnnouncements();
  }, [loadAnnouncements]);

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
      setNotice('Announcement published.');
      await loadAnnouncements();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to save the announcement.'));
    } finally {
      setSaving(false);
    }
  };

  const deleteAnnouncement = async (announcement) => {
    if (!announcement || announcement.id == null) return;
    if (!window.confirm(`Delete "${announcement.title}"? This cannot be undone.`)) return;
    setDeletingId(announcement.id);
    setError('');
    setNotice('');
    try {
      await api.delete(`/admin/ssg/announcements/${announcement.id}`);
      setNotice('Announcement deleted.');
      await loadAnnouncements();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to delete the announcement.'));
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={onLogout} currentUser={currentUser} />

      <main className="main-content">
        <Header breadcrumb="Announcements" onLogout={onLogout} currentUser={currentUser} onNavigate={onNavigate} />

        <div className="announcements-body">
          <div className="page-header">
            <h2>Staff Announcements</h2>
            <p className="page-subtext">Publish announcements that students will see in the OmniVote mobile app. Your name is shown as the author.</p>
          </div>

          {error && <div className="warning-banner">{error}</div>}
          {notice && <div className="success-banner">{notice}</div>}

          <section className="card announcements-form-card">
            <div className="card-header">
              <div>
                <h3>New Announcement</h3>
                <p className="muted-text">Share an update with students.</p>
              </div>
              <Megaphone size={20} />
            </div>
            <form className="announcements-form" onSubmit={createAnnouncement}>
              <label htmlFor="announcements-title">Title</label>
              <input
                id="announcements-title"
                value={draft.title}
                onChange={(event) => setDraft({ ...draft, title: event.target.value })}
                required
              />
              <label htmlFor="announcements-body">Message</label>
              <textarea
                id="announcements-body"
                value={draft.body}
                onChange={(event) => setDraft({ ...draft, body: event.target.value })}
                rows="5"
                required
              />
              <label className="announcements-checkbox">
                <input
                  type="checkbox"
                  checked={draft.published}
                  onChange={(event) => setDraft({ ...draft, published: event.target.checked })}
                />
                Publish immediately (visible to students on the mobile app)
              </label>
              <button type="submit" className="primary-button" disabled={saving}>
                <Send size={16} /> {saving ? 'Publishing…' : 'Publish Announcement'}
              </button>
            </form>
          </section>

          <section className="card">
            <div className="card-header">
              <div>
                <h3>Published Announcements</h3>
                <p className="muted-text">Every announcement posted by staff, newest first.</p>
              </div>
            </div>
            {loading ? (
              <p className="muted-text">Loading announcements…</p>
            ) : announcements.length === 0 ? (
              <p className="muted-text">No announcements yet. Publish the first one above.</p>
            ) : (
              <div className="announcements-list">
                {announcements.map((announcement) => (
                  <article className="announcement-item" key={announcement.id}>
                    <div className="announcements-item-header">
                      <strong className="announcements-item-title">{announcement.title}</strong>
                      <div className="announcements-item-actions">
                        <span className={`announcements-item-status ${announcement.published_at ? 'is-published' : 'is-draft'}`}>
                          {announcement.published_at ? 'Published' : 'Draft'}
                        </span>
                        {canDeleteAnnouncement(announcement, currentUser) && (
                          <button
                            type="button"
                            className="announcements-item-delete"
                            aria-label={`Delete "${announcement.title}"`}
                            disabled={deletingId === announcement.id}
                            onClick={() => deleteAnnouncement(announcement)}
                          >
                            <Trash2 size={14} />
                          </button>
                        )}
                      </div>
                    </div>
                    <p className="announcements-item-byline">
                      Posted by {announcement.author?.name || 'Staff'}
                      {formatDate(announcement.published_at) ? ` • ${formatDate(announcement.published_at)}` : ''}
                    </p>
                    <p className="announcements-item-body">{announcement.body}</p>
                  </article>
                ))}
              </div>
            )}
          </section>
        </div>
      </main>
    </div>
  );
}