import { useCallback, useEffect, useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import { allowedViews } from './lib/permissions';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import './SsgPresident.css';

function errorMessage(error, fallback) {
  return error.response?.data?.message || error.message || fallback;
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

/** One seated officer. The status badge distinguishes a confirmed winner from
 *  the provisional top-N the backend falls back to before a finalize has run. */
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

/** A position and the officers seated in it. A scoped seat (one contested per
 *  college / year level) is broken out into its electorates, each with its
 *  own fill count; a global seat lists its winners straight under the position. */
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

/**
 * SSG President console.
 *
 * The page now uses the SAME shell as the Admin/Teacher dashboard — the shared
 * <Sidebar> for navigation and the shared <Header> for the breadcrumb, phase
 * badge, clock, notification bell and profile block. The President's two
 * screens (President Dashboard and Announcements) are sibling views in the
 * sidebar, exactly like every staff screen, so the console no longer needs a
 * bespoke layout.
 *
 * The announcement composer/feed lives in the shared Announcements view
 * (Announcements.jsx), which already talks to the /ssg/console/ssg/announcements
 * API surface. This page is the certified-officer roster only.
 */
export default function SsgPresident({ onLogout, activeView = 'ssg', onNavigate = null, currentUser = null }) {
  const { user, logout } = useAuth();
  const [officers, setOfficers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  // Officers 403 until voting closes — that gate is the expected state, not a
  // failure, so it gets its own inline message instead of an error banner.
  const [officersUnavailable, setOfficersUnavailable] = useState(false);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/admin/ssg/officers');
      setOfficers(res.data?.data || []);
      setOfficersUnavailable(false);
      setError('');
    } catch (requestError) {
      setOfficers([]);
      if (requestError.response?.status === 403) {
        setOfficersUnavailable(true);
        setError('');
      } else {
        setOfficersUnavailable(false);
        setError(errorMessage(requestError, 'Unable to load the certified officer roster.'));
      }
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

  // Fall back to the auth-context user, then to the President's own role, so
  // the sidebar never briefly renders the full staff nav while the session
  // object is still resolving.
  const role = currentUser?.role ?? user?.role ?? 'ssg_president';
  const displayName = currentUser?.name || user?.name || 'SSG President';
  const roster = buildRoster(officers);
  const seatedCount = officers.length;

  const rosterStatus = loading
    ? 'Loading the certified roster…'
    : officersUnavailable
      ? 'Locked until voting closes.'
      : seatedCount === 0
        ? 'No officers have been seated yet.'
        : `${seatedCount} ${seatedCount === 1 ? 'seat' : 'seats'} filled — listed in ballot order, national first.`;

  return (
    <div className="dashboard-container ssg-president">
      <Sidebar
        activeView={activeView}
        onNavigate={onNavigate}
        onLogout={handleLogout}
        currentUser={currentUser}
        permittedViews={allowedViews(role)}
      />

      <main className="main-content">
        <Header
          breadcrumb="President Dashboard"
          breadcrumbPrefix="SSG"
          currentUser={currentUser}
          onNavigate={onNavigate}
        />

        <section className="ssg-page-header">
          <span className="ssg-console-badge"><ShieldCheck size={14} /> SSG President</span>
          <h2>Welcome back, {displayName.split(' ')[0]}</h2>
          <p className="page-subtext">
            Certified Officer Roster — who holds each seat, in which electorate, and how filled.
          </p>
        </section>

        {error && <div className="warning-banner">{error}</div>}

        <section className="ssg-roster">
          <div className="ssg-roster-header">
            <div>
              <h3>Certified Officer Roster</h3>
              <p className="muted-text">{rosterStatus}</p>
            </div>
          </div>
          {loading ? (
            <div className="card"><p className="muted-text">Loading officer roster…</p></div>
          ) : officersUnavailable ? (
            <div className="card"><p className="muted-text">Certified officers are listed here once voting closes.</p></div>
          ) : (
            <div className="ssg-roster-grid">
              {roster.map((tier) => (
                <section className={`card ssg-tier-card ssg-tier-${tier.tier}`} key={tier.tier}>
                  <div className="ssg-tier-header">
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
      </main>
    </div>
  );
}
