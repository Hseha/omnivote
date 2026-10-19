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

// Tier-grouped roster sections: national seats first, provincial second.
// The backend already returns each tier in seating order.
const ROSTER_TIERS = [
  { tier: 'national', label: 'National Winners', empty: 'No national officers yet.' },
  { tier: 'provincial', label: 'Provincial Winners', empty: 'No provincial officers yet.' },
  // Safety net: a seat on an unrecognised tier (a legacy value, say) must
  // still reach the President rather than vanish from the roster.
  { tier: 'other', label: 'Other Officers', empty: 'No other officers yet.' },
];

/**
 * Groups the flat officer list into the console's tier -> position ->
 * electorate -> officer hierarchy.
 *
 * The backend already seats the rows in ballot order (national before
 * provincial, positions in their configured order, winners within a seat by
 * vote count), so the grouping below preserves that order instead of
 * re-sorting it — the President sees the roster in ballot order.
 *
 * `position_key` groups a position; a scoped position (one contested per
 * college / year level) is split further by its electorate label. A global
 * seat has no electorate, so it lands in the single `''` group.
 */
function buildRoster(officers) {
  const tiers = new Map();

  officers.forEach((officer) => {
    // A row without `tier` (older backend) counts as national; anything that
    // is neither national nor provincial lands in the `other` bucket so it is
    // never silently dropped.
    const rawTier = officer.tier || 'national';
    const tier = ROSTER_TIERS.some((entry) => entry.tier === rawTier) ? rawTier : 'other';

    if (!tiers.has(tier)) tiers.set(tier, new Map());
    const positions = tiers.get(tier);

    const positionKey = officer.position_key;
    if (!positions.has(positionKey)) {
      const seatCount = Number(officer.seat_count);
      positions.set(positionKey, {
        key: positionKey,
        label: officer.position_base_label || officer.position_label,
        scoped: Boolean(officer.position_scoped),
        seatCount: Number.isFinite(seatCount) ? seatCount : null,
        scopeGroups: new Map(),
      });
    }
    const position = positions.get(positionKey);

    // An unscoped seat has one electorate: the position itself.
    const scopeKey = officer.position_scope || '';
    if (!position.scopeGroups.has(scopeKey)) {
      position.scopeGroups.set(scopeKey, {
        key: scopeKey,
        label: officer.position_scope,
        officers: [],
      });
    }
    position.scopeGroups.get(scopeKey).officers.push(officer);
  });

  return ROSTER_TIERS
    .map((tier) => {
      const positions = tiers.get(tier.tier);
      return {
        ...tier,
        positions: positions ? Array.from(positions.values()) : [],
      };
    })
    // The `other` bucket is a safety net for an unrecognised tier, not a real
    // cabinet — it stays hidden until something actually lands in it.
    .filter((tier) => tier.tier !== 'other' || tier.positions.length > 0);
}

/** "3 of 12 seats" — how many of a seat's places are actually filled. */
function seatFillLabel(filled, seatCount) {
  if (!Number.isFinite(seatCount) || seatCount <= 0) return null;
  return `${filled} of ${seatCount} ${seatCount === 1 ? 'seat' : 'seats'}`;
}

/**
 * One seated officer. The status badge distinguishes a confirmed winner from
 * the provisional top-N the backend falls back to before a finalize has run.
 */
function OfficerRow({ officer }) {
  return (
    <li className="ssg-officer">
      <span className="ssg-officer-name">{officer.name}</span>
      {officer.is_elected ? (
        <span className="ssg-elected-badge">Elected</span>
      ) : (
        <span className="ssg-elected-badge ssg-provisional">Provisional</span>
      )}
      <span className="ssg-officer-votes">
        {Number(officer.votes || 0).toLocaleString()} votes
      </span>
    </li>
  );
}

function OfficerList({ officers }) {
  return (
    <ul className="ssg-officer-list">
      {officers.map((officer) => (
        <OfficerRow key={`${officer.position_key}-${officer.candidate_ref}`} officer={officer} />
      ))}
    </ul>
  );
}

/**
 * A position and the officers seated in it. A scoped seat (one contested per
 * college / year level) is broken out into its electorates, each with its own
 * fill count; a global seat lists its winners straight under the position.
 */
function PositionBlock({ position }) {
  const scopeGroups = Array.from(position.scopeGroups.values());
  const filled = scopeGroups.reduce((total, group) => total + group.officers.length, 0);

  return (
    <div className="ssg-position">
      <div className="ssg-position-head">
        <h4 className="ssg-position-title">{position.label}</h4>
        {/* A global seat has one electorate, so its fill count belongs on the
            position header; a scoped seat fills per electorate instead. */}
        {!position.scoped && seatFillLabel(filled, position.seatCount) && (
          <span className="ssg-seat-chip">{seatFillLabel(filled, position.seatCount)}</span>
        )}
      </div>

      {position.scoped
        ? scopeGroups.map((group) => (
          <div className="ssg-scope-group" key={group.key}>
            <div className="ssg-scope-head">
              <span className="ssg-scope-label">{group.label || 'Unassigned electorate'}</span>
              {seatFillLabel(group.officers.length, position.seatCount) && (
                <span className="ssg-seat-chip">{seatFillLabel(group.officers.length, position.seatCount)}</span>
              )}
            </div>
            <OfficerList officers={group.officers} />
          </div>
        ))
        : scopeGroups.map((group) => <OfficerList key={group.key} officers={group.officers} />)}
    </div>
  );
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

          <section className="ssg-roster">
            <div className="ssg-roster-header">
              <div><h3>Certified Officer Roster</h3><p className="muted-text">Available after voting is closed.</p></div>
            </div>
            {loading ? <div className="card"><p className="muted-text">Loading officer roster...</p></div> : officersUnavailable ? (
              <div className="card"><p className="muted-text">Certified officers are listed here once voting closes.</p></div>
            ) : (
              <div className="ssg-roster-grid">
                {buildRoster(officers).map((tier) => (
                  <section className={`card ssg-tier-card ssg-tier-${tier.tier}`} key={tier.tier}>
                    <div className="card-header ssg-tier-header">
                      <div>
                        <h3>{tier.label}</h3>
                        <p className="muted-text">
                          {tier.positions.length === 0
                            ? 'No positions seated yet.'
                            : `${tier.positions.length} ${tier.positions.length === 1 ? 'position' : 'positions'}`}
                        </p>
                      </div>
                      <span className="ssg-tier-badge">{tier.tier}</span>
                    </div>

                    {tier.positions.length === 0 ? (
                      <p className="muted-text">{tier.empty}</p>
                    ) : (
                      <div className="ssg-tier-positions">
                        {tier.positions.map((position) => (
                          <PositionBlock key={position.key} position={position} />
                        ))}
                      </div>
                    )}
                  </section>
                ))}
              </div>
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
