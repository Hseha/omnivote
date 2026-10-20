import { useState, useEffect } from 'react';
import { Archive, CheckCircle2, AlertCircle } from 'lucide-react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import api from './lib/api';
import './ElectionRecords.css';

/* Maps the stored election_status onto a badge style. `elected` is the
 * certified-winner status, so it borrows the winner colour rather than
 * rendering an unstyled badge. */
const RESULT_STYLE = {
  elected: 'winner',
  lost: 'lost',
  pending: 'pending',
  rejected: 'rejected',
  withdrawn: 'withdrawn',
};

/* How a cycle ended. A cycle that closed but was never finalized has no
 * winners to show; one that was reset before the polls closed never ran. */
const CYCLE_STATUS = {
  finalized: { label: 'Finalized', style: 'finalized' },
  voting_closed: { label: 'Closed — not finalized', style: 'closed' },
  aborted: { label: 'Aborted by reset', style: 'aborted' },
  unknown: { label: 'No cycle header', style: 'unknown' },
};

export default function ElectionRecords({
  activeView = 'records',
  onNavigate,
  onLogout,
  currentUser = null,
}) {
  const [recordsData, setRecordsData] = useState(null);
  const [message, setMessage] = useState(null);

  const [selectedParty, setSelectedParty] = useState('All');
  const [selectedPosition, setSelectedPosition] = useState('All');
  const [selectedResult, setSelectedResult] = useState('All');
  const [selectedTerm, setSelectedTerm] = useState('All');

  const [parties, setParties] = useState([]);
  const [positions, setPositions] = useState([]);
  const [terms, setTerms] = useState([]);
  const [resultValues, setResultValues] = useState([
    'elected', 'tied', 'lost', 'undecided', 'rejected', 'withdrawn',
  ]);

  async function loadRecords() {
    setMessage(null);
    try {
      const params = new URLSearchParams();
      if (selectedParty !== 'All') params.set('party', selectedParty);
      if (selectedPosition !== 'All') params.set('position', selectedPosition);
      if (selectedResult !== 'All') params.set('result', selectedResult);
      if (selectedTerm !== 'All') params.set('term', selectedTerm);

      const res = await api.get(`/admin/election-records?${params.toString()}`);
      const data = res.data ?? {};
      setRecordsData(data);
      setParties(Array.isArray(data.filters?.parties) ? data.filters.parties : []);
      setPositions(Array.isArray(data.filters?.positions) ? data.filters.positions : []);
      setTerms(Array.isArray(data.filters?.terms) ? data.filters.terms : []);
      if (Array.isArray(data.filters?.results)) setResultValues(data.filters.results);
    } catch (err) {
      setMessage({
        ok: false,
        text: err.response?.data?.message || 'Could not load election records.',
      });
    }
  }

  useEffect(() => {
    // Defer the fetch so the synchronous setState in loadRecords isn't
    // executed inline in the effect body (avoids cascading renders), mirroring
    // the pattern used in Results.jsx.
    const t = window.setTimeout(loadRecords, 0);
    return () => window.clearTimeout(t);
  }, [selectedParty, selectedPosition, selectedResult, selectedTerm]);

  const cycles = Array.isArray(recordsData?.cycles) ? recordsData.cycles : [];

  function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('en-US', {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  }

  function plural(n) {
    return n === 1 ? '' : 's';
  }

  function resultBadge(result) {
    const key = String(result ?? '').toLowerCase();
    const label = key ? key.charAt(0).toUpperCase() + key.slice(1) : 'Unknown';
    const style = RESULT_STYLE[key] || 'pending';
    return <span className={`er-badge er-badge-${style}`}>{label}</span>;
  }

  function statusBadge(status) {
    const meta = CYCLE_STATUS[status] || CYCLE_STATUS.unknown;
    return <span className={`er-status er-status-${meta.style}`}>{meta.label}</span>;
  }

  function turnoutLabel(turnout) {
    if (!turnout) return '—';
    const registered = turnout.registered_students;
    const voted = turnout.voters_who_voted;
    if (registered === null || registered === undefined) return '—';
    const pct = voted === null || voted === undefined
      ? null
      : Math.round((voted / Math.max(registered, 1)) * 100);
    return pct === null ? `${voted ?? 0} voted` : `${voted} of ${registered} voted (${pct}%)`;
  }

  function windowLabel(from, to) {
    if (!from && !to) return '—';
    return `${formatDateTime(from)} → ${formatDateTime(to)}`;
  }

  return (
    <div className="dashboard-container">
      <Sidebar
        activeView={activeView}
        onNavigate={onNavigate}
        onLogout={onLogout}
        currentUser={currentUser}
        brandSub="ELECTION RECORDS"
        statusText="System Live (v1.4)"
      />

      <main className="main-content">
        <Header breadcrumb="Election Records" onLogout={onLogout} currentUser={currentUser} onNavigate={onNavigate} />

        <div className="er-body">
          <div className="er-header">
            <div>
              <h2 className="er-title"><Archive size={20} /> Election Records</h2>
              <p className="er-subtext">
                Every election cycle on record — when it ran, how it ended, and the full roster
                (winners and non-winners, all parties) preserved after clean-slate resets.
              </p>
            </div>
            <button type="button" className="er-refresh-btn" onClick={loadRecords} title="Reload records">
              <Archive size={15} /> Refresh
            </button>
          </div>

          {message && (
            <div className={`er-banner ${message.ok ? 'er-banner-ok' : 'er-banner-error'}`}>
              {message.ok ? <CheckCircle2 size={14} /> : <AlertCircle size={14} />}&nbsp; {message.text}
            </div>
          )}

          <div className="er-toolbar">
            <label className="er-field">
              <span>Term</span>
              <select className="er-select" value={selectedTerm} onChange={(e) => setSelectedTerm(e.target.value)}>
                <option value="All">All terms</option>
                {terms.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </select>
            </label>
            <label className="er-field">
              <span>Party</span>
              <select className="er-select" value={selectedParty} onChange={(e) => setSelectedParty(e.target.value)}>
                <option value="All">All parties</option>
                {parties.map((p) => (
                  <option key={p} value={p}>{p}</option>
                ))}
              </select>
            </label>
            <label className="er-field">
              <span>Position</span>
              <select className="er-select" value={selectedPosition} onChange={(e) => setSelectedPosition(e.target.value)}>
                <option value="All">All positions</option>
                {positions.map((p) => (
                  <option key={p} value={p}>{p}</option>
                ))}
              </select>
            </label>
            <label className="er-field">
              <span>Result</span>
              <select className="er-select" value={selectedResult} onChange={(e) => setSelectedResult(e.target.value)}>
                <option value="All">All results</option>
                {resultValues.map((r) => (
                  <option key={r} value={r}>{String(r).charAt(0).toUpperCase() + String(r).slice(1)}</option>
                ))}
              </select>
            </label>
          </div>

          {cycles.length === 0 ? (
            <div className="er-empty">
              No election records yet. A cycle is captured when voting closes, and again if the
              election is reset before it can conclude.
            </div>
          ) : (
            cycles.map((cycle) => (
              <section key={cycle.cycle_id || cycle.term_label} className="er-term-section">
                <div className="er-term-header">
                  <div className="er-term-heading">
                    <h3>{cycle.term_label}</h3>
                    {statusBadge(cycle.status)}
                  </div>
                  <span className="er-term-meta">
                    {cycle.total} candidate{plural(cycle.total)} &middot; {cycle.winners} winner{plural(cycle.winners)} &middot; archived {formatDate(cycle.snapshot_at)}
                  </span>
                </div>

                <div className="er-cycle-facts">
                  <div className="er-fact">
                    <span className="er-fact-label">Registration</span>
                    <span className="er-fact-value">
                      {windowLabel(cycle.schedule?.registration_opens_at, cycle.schedule?.registration_closes_at)}
                    </span>
                  </div>
                  <div className="er-fact">
                    <span className="er-fact-label">Voting</span>
                    <span className="er-fact-value">
                      {windowLabel(cycle.schedule?.voting_opens_at, cycle.schedule?.voting_closes_at)}
                    </span>
                  </div>
                  <div className="er-fact">
                    <span className="er-fact-label">Turnout</span>
                    <span className="er-fact-value">{turnoutLabel(cycle.turnout)}</span>
                  </div>
                  <div className="er-fact">
                    <span className="er-fact-label">Seats filled</span>
                    <span className="er-fact-value">{cycle.seats_filled ?? '—'}</span>
                  </div>
                </div>

                {(cycle.positions ?? []).length > 0 && (
                  <div className="er-ballot">
                    <span className="er-ballot-label">Ballot</span>
                    <div className="er-ballot-chips">
                      {cycle.positions.map((p) => (
                        <span key={p.slug || p.label} className="er-ballot-chip">
                          {p.label || p.slug}
                          {p.seat_count ? <span className="er-ballot-seats">×{p.seat_count}</span> : null}
                        </span>
                      ))}
                    </div>
                  </div>
                )}

                {(cycle.parties ?? []).map((party) => (
                  <div key={party.party} className="er-party-block">
                    <div className="er-party-label">{party.party}</div>
                    <div className="er-table-wrap">
                      <table className="er-table">
                        <thead>
                          <tr>
                            <th>Candidate</th>
                            <th>Student ID</th>
                            <th>Department / Year</th>
                            <th>Position</th>
                            <th>Tier</th>
                            <th>Result</th>
                            <th>Votes</th>
                            <th>Rank</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(party.candidates ?? []).map((c) => (
                            <tr
                              key={`${c.student_id}|${c.position}|${c.name}`}
                              className={c.certified_winner ? 'er-row-winner' : ''}
                            >
                              <td className="er-candidate-cell">
                                <div className="er-candidate-name">{c.name}</div>
                                {c.slogan && <div className="er-candidate-slogan">{c.slogan}</div>}
                              </td>
                              <td>{c.student_id || '—'}</td>
                              <td>{[c.department, c.year_level].filter(Boolean).join(' / ') || '—'}</td>
                              <td>{c.position || '—'}</td>
                              <td>{c.tier || '—'}</td>
                              <td>{resultBadge(c.result)}</td>
                              <td>{c.vote_total}</td>
                              <td>{c.winner_rank ?? '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ))}
              </section>
            ))
          )}

          <div className="er-footer">
            <p className="er-foot-note">
              Certified winners remain in the live candidates table until the next reset and are also
              shown in Results &rarr; Past Terms. This view keeps the complete roster of every cycle for review.
            </p>
          </div>
        </div>
      </main>
    </div>
  );
}
