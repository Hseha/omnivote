import { useState } from 'react';
import { Megaphone, Send, Users } from 'lucide-react';
import api from '../lib/api';
import './NotificationBroadcast.css';

/*
 * Broadcast composer (Settings → Notifications).
 *
 * Writes one in-app notification row per active student via
 * `POST /api/admin/notifications/broadcast`. Targeting is optional: a blank
 * form reaches every active student; year level / department / course narrow
 * the audience. Success reports the precise recipient count the backend
 * computed, so a real "0 received" is surfaced honestly instead of a fake
 * "sent".
 */

const NOTIFICATION_TYPES = [
  { value: 'info', label: 'Info' },
  { value: 'success', label: 'Success' },
  { value: 'warning', label: 'Warning' },
  { value: 'danger', label: 'Important' },
];

// Destinations the student app resolves (`/notifications` deep links). A blank
// entry means "no link — informational only".
const STUDENT_LINKS = [
  { value: '', label: 'No destination' },
  { value: '/dashboard', label: 'Dashboard' },
  { value: '/vote-now', label: 'Vote Now' },
  { value: '/candidates', label: 'Candidates' },
  { value: '/ballot', label: 'My Ballot' },
  { value: '/results', label: 'Results' },
  { value: '/profile', label: 'My Profile' },
  { value: '/candidacy', label: 'Candidacy' },
  { value: '/faq', label: 'Help & FAQ' },
];

const YEAR_LEVELS = ['', '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];

function errorMessage(error, fallback) {
  return error.response?.data?.message || error.message || fallback;
}

export default function NotificationBroadcast() {
  const [draft, setDraft] = useState({
    type: 'info',
    title: '',
    body: '',
    link: '',
    year_level: '',
    department: '',
    course: '',
  });
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const update = (field, value) => setDraft((d) => ({ ...d, [field]: value }));

  const hasTarget = draft.year_level || draft.department || draft.course;

  const send = async (event) => {
    event.preventDefault();
    setSending(true);
    setError('');
    setNotice('');
    try {
      const { data } = await api.post('/admin/notifications/broadcast', {
        type: draft.type,
        title: draft.title.trim(),
        body: draft.body.trim(),
        link: draft.link || null,
        year_level: draft.year_level || null,
        department: draft.department.trim() || null,
        course: draft.course.trim() || null,
      });
      setNotice(
        data?.message ||
          `Notification sent to ${data?.recipients ?? 0} student(s).`,
      );
      setDraft({ type: 'info', title: '', body: '', link: '', year_level: '', department: '', course: '' });
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to send the notification.'));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="notification-broadcast settings-panel">
      <div className="section-header">
        <div>
          <h3>Broadcast a Notification</h3>
          <p className="muted-text">Send an in-app message straight to students</p>
        </div>
        <Megaphone size={20} className="section-icon" />
      </div>

      {error && <div className="warning-banner">{error}</div>}
      {notice && <div className="success-banner">{notice}</div>}

      <form onSubmit={send} className="notification-broadcast-form">
        <div className="settings-grid settings-grid-3">
          <label className="setting-card setting-card-full notification-broadcast-field">
            <span className="setting-label">Type</span>
            <select
              value={draft.type}
              onChange={(e) => update('type', e.target.value)}
            >
              {NOTIFICATION_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        <label className="notification-broadcast-field">
          <span className="setting-label">Title</span>
          <input
            value={draft.title}
            onChange={(e) => update('title', e.target.value)}
            placeholder="e.g. Voting closes at 8 PM tonight"
            maxLength={200}
            required
          />
        </label>

        <label className="notification-broadcast-field">
          <span className="setting-label">Message</span>
          <textarea
            value={draft.body}
            onChange={(e) => update('body', e.target.value)}
            placeholder="Optional supporting detail shown under the title"
            rows={4}
            maxLength={20000}
          />
        </label>

        <label className="notification-broadcast-field">
          <span className="setting-label">Destination</span>
          <select
            value={draft.link}
            onChange={(e) => update('link', e.target.value)}
          >
            {STUDENT_LINKS.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </select>
        </label>

        <div className="settings-grid settings-grid-3">
          <label className="setting-card setting-card-full notification-broadcast-field">
            <span className="setting-label">Year level</span>
            <select
              value={draft.year_level}
              onChange={(e) => update('year_level', e.target.value)}
            >
              <option value="">All year levels</option>
              {YEAR_LEVELS.slice(1).map((y) => (
                <option key={y} value={y}>
                  {y}
                </option>
              ))}
            </select>
          </label>

          <label className="setting-card setting-card-full notification-broadcast-field">
            <span className="setting-label">Department</span>
            <input
              value={draft.department}
              onChange={(e) => update('department', e.target.value)}
              placeholder="Any department"
              maxLength={128}
            />
          </label>

          <label className="setting-card setting-card-full notification-broadcast-field">
            <span className="setting-label">Course</span>
            <input
              value={draft.course}
              onChange={(e) => update('course', e.target.value)}
              placeholder="Any course"
              maxLength={128}
            />
          </label>
        </div>

        <p className="notification-broadcast-audience">
          <Users size={14} />
          {hasTarget
            ? 'Only matching active students receive this notification.'
            : 'All active students receive this notification.'}
        </p>

        <div className="settings-panel-footer">
          <button type="submit" className="btn-save" disabled={sending}>
            <Send size={16} /> {sending ? 'Sending…' : 'Send Notification'}
          </button>
        </div>
      </form>
    </div>
  );
}