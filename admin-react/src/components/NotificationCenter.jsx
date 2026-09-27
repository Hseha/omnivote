import { useCallback, useEffect, useRef, useState } from 'react';
import { Bell, CheckCheck, MailOpen, ShieldAlert } from 'lucide-react';
import api from '../lib/api';
import './NotificationCenter.css';

/*
 * Notification bell + dropdown for the shared admin header.
 *
 * Backend contract (`GET /api/admin/notifications`): `{ notifications, unread }`
 * where each notification is `{ id, type, title, body, link, read, created_at }`.
 * Reads and “mark all read” go to `POST /api/admin/notifications/read`.
 *
 * The bell polls every 30 s so the unread badge stays fresh after admin actions
 * elsewhere (imports, registrations, votes) without requiring a page reload.
 */
const TYPE_META = {
  info: { Icon: Bell, color: '#60a5fa' },
  success: { Icon: CheckCheck, color: '#34d399' },
  warning: { Icon: ShieldAlert, color: '#f59e0b' },
  danger: { Icon: ShieldAlert, color: '#f87171' },
};

export default function NotificationCenter({ onNavigate = null }) {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const boxRef = useRef(null);

  const fetchNotifications = useCallback(async () => {
    try {
      const { data } = await api.get('/admin/notifications');
      if (Array.isArray(data.notifications)) {
        setItems(data.notifications);
        setUnread(Number(data.unread) || 0);
      }
    } catch (error) {
      // Polling must never blow up the header; a 401 triggers the shared
      // logout handler via the api interceptor.
      if (error.response?.status === 401) return;
      console.warn('Notification fetch failed:', error.message);
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    const id = setInterval(fetchNotifications, 30000);
    // Defer the initial fetch so the poller and the first refresh share one
    // code path instead of calling setState synchronously inside the effect.
    const initial = setTimeout(fetchNotifications, 0);
    return () => {
      clearInterval(id);
      clearTimeout(initial);
    };
  }, [open, fetchNotifications]);

  // Close when clicking anywhere outside the component.
  useEffect(() => {
    if (!open) return;
    const onClick = (e) => {
      if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  const markRead = async (id) => {
    try {
      await api.post('/admin/notifications/read', { id });
      setItems((prev) => prev.map((n) => (n.id === id ? { ...n, read: true } : n)));
      setUnread((u) => Math.max(u - 1, 0));
    } catch (error) {
      console.warn('Mark read failed:', error.message);
    }
  };

  const markAllRead = async () => {
    try {
      await api.post('/admin/notifications/read', {});
      setItems((prev) => prev.map((n) => ({ ...n, read: true })));
      setUnread(0);
    } catch (error) {
      console.warn('Mark all read failed:', error.message);
    }
  };

  const openLink = (item) => {
    if (typeof onNavigate === 'function' && item.link) onNavigate(item.link);
    setOpen(false);
  };

  return (
    <div className="notification-center" ref={boxRef}>
      <button
        type="button"
        className="notification-bell"
        onClick={() => setOpen((o) => !o)}
        aria-label={`Notifications${unread ? `, ${unread} unread` : ''}`}
      >
        <Bell size={18} />
        {unread > 0 && <span className="notification-badge">{unread > 99 ? '99+' : unread}</span>}
      </button>

      {open && (
        <div className="notification-panel">
          <div className="notification-panel-header">
            <strong>Notifications</strong>
            {unread > 0 && (
              <button type="button" className="notification-mark-all" onClick={markAllRead}>
                Mark all read
              </button>
            )}
          </div>
          <div className="notification-list">
            {items.length === 0 ? (
              <p className="notification-empty">You're all caught up.</p>
            ) : (
              items.map((item) => {
                const { Icon, color } = TYPE_META[item.type] || { Icon: Bell, color: '#94a3b8' };
                return (
                  <button
                    key={item.id}
                    type="button"
                    className={`notification-row${item.read ? ' is-read' : ''}${item.link ? ' has-link' : ''}`}
                    onClick={() => (item.read || !item.link ? markRead(item.id) : (markRead(item.id), openLink(item)))}
                  >
                    <span className="notification-icon" style={{ background: color + '20', color }}>
                      <Icon size={14} />
                    </span>
                    <span className="notification-body">
                      <span className="notification-title">{item.title}</span>
                      {item.body && <span className="notification-desc">{item.body}</span>}
                      <span className="notification-time">{timeAgo(item.created_at)}</span>
                    </span>
                    {!item.read && <span className="notification-dot" />}
                  </button>
                );
              })
            )}
          </div>
          <div className="notification-panel-footer">
            <MailOpen size={12} /> In-app feed — email depends on settings
          </div>
        </div>
      )}
    </div>
  );
}

function timeAgo(value) {
  if (!value) return 'just now';
  const then = new Date(value);
  const seconds = Math.floor((Date.now() - then.getTime()) / 1000);
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
  return then.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}