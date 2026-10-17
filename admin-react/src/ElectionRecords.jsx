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
  const [resultValues, setResultValues] = useState([
    'elected', 'lost', 'pending', 'rejected', 'withdrawn',
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

  const termGroups = Array.isArray(recordsData?.terms) ? recordsData.terms : [];

  function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
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
                Every candidate from every reset cycle — winners and non-winners, all parties —
                preserved after clean-slate resets.
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
                {termGroups.map((t) => (
                  <option key={t.term_label} value={t.term_label}>{t.term_label}</option>
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

          {termGroups.length === 0 ? (
            <div className="er-empty">
              No election records yet. Records are created whenever the election is reset.
            </div>
          ) : (
            termGroups.map((term) => (
              <section key={term.term_label} className="er-term-section">
                <div className="er-term-header">
                  <h3>{term.term_label}</h3>
                  <span className="er-term-meta">
                    {term.total} candidate{plural(term.total)} &middot; {term.winners} winner{plural(term.winners)} &middot; archived {formatDate(term.snapshot_at)}
                  </span>
                </div>
                {(term.parties ?? []).map((party) => (
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
