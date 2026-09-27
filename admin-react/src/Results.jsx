import { useState, useEffect } from 'react';
import { 
  CheckCircle2, 
  TrendingUp,
  Users2,
  ArrowLeft,
  Search,
  Download,
  ChevronDown,
} from 'lucide-react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import { useElectionStatus } from './lib/ElectionStatusContext';
import api from './lib/api';
import './Results.css';

export default function Results({ activeView = 'results', onNavigate, onLogout, currentUser = null }) {
  // Only the admin may break a tie — a subjective call with accountability.
  const isAdmin = (currentUser?.role ?? '') === 'admin';
  // Navigation state within Results view: 'live' | 'all-candidates' | 'elected' | 'unsuccessful'
  const [subView, setSubView] = useState('live');
  // Live phase from the shared poller — flips on its own the moment the
  // configured window boundary is crossed, unlocking results without a reload.
  const { phase } = useElectionStatus();
  const [resultsData, setResultsData] = useState(null);
  const [loadingResults, setLoadingResults] = useState(false);
  const [finalizing, setFinalizing] = useState(false);
  const [resultMessage, setResultMessage] = useState(null);
  const [resolvingId, setResolvingId] = useState(null);
  const [archiveData, setArchiveData] = useState(null);
  const [loadingArchive, setLoadingArchive] = useState(false);
  const [archiving, setArchiving] = useState(false);
  
  // Search and Filter states for detailed table views
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedPosition, setSelectedPosition] = useState('All');
  const [selectedParty, setSelectedParty] = useState('All');

  function displayPhase(p) {
    switch (p) {
      case 'registration':
        return 'Registration';
      case 'registration_closed':
        return 'Registration Closed';
      case 'voting_open':
        return 'Voting Open';
      case 'voting_closed':
        return 'Voting Closed';
      default:
        // null = no configured window yet; never fall back to a real-looking
        // phase, or the banner lies after the admin clears the schedule.
        return p || 'Not Configured';
    }
  }

  // Live badge colors: green while voting is open, muted otherwise.
  function phaseClass(p) {
    if (p === 'voting_open') return 'green-phase';
    if (!p) return 'muted-phase';
    return 'blue-phase';
  }

  function formatArchiveDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  async function loadResults() {
    try {
      setLoadingResults(true);
      const res = await api.get('/admin/results');
      setResultsData(res.data ?? null);
    } catch {
      setResultsData(null);
    } finally {
      setLoadingResults(false);
    }
  }

  useEffect(() => {
    if (phase !== 'voting_closed' && phase !== 'voting_open') return;
    // Deferred so the synchronous setState in loadResults isn't executed
    // inline in the effect body (avoids cascading renders).
    const t = window.setTimeout(loadResults, 0);
    return () => window.clearTimeout(t);
  }, [phase]);

  async function finalizeResults() {
    setFinalizing(true);
    setResultMessage(null);
    try {
      const res = await api.post('/admin/results/finalize');
      setResultMessage({ ok: true, text: res.data?.message || 'Results finalized.' });
      await loadResults();
    } catch (err) {
      setResultMessage({ ok: false, text: err.response?.data?.message || 'Could not finalize results.' });
    } finally {
      setFinalizing(false);
    }
  }

  async function resolveTie(candidateId) {
    setResolvingId(candidateId);
    setResultMessage(null);
    try {
      const res = await api.post(`/admin/candidates/${candidateId}/resolve-tie`);
      setResultMessage({ ok: true, text: res.data?.message || 'Tie resolved.' });
      await loadResults();
      setSubView('live');
    } catch (err) {
      setResultMessage({ ok: false, text: err.response?.data?.message || 'Could not resolve the tie.' });
    } finally {
      setResolvingId(null);
    }
  }

  async function loadArchived() {
    setLoadingArchive(true);
    try {
      const res = await api.get('/admin/results/archive');
      setArchiveData(res.data ?? null);
    } catch {
      setArchiveData(null);
    } finally {
      setLoadingArchive(false);
    }
  }

  // Entering/leaving the Past Terms view is driven from the button handler
  // (which calls loadArchived() directly), so no effect is needed to watch
  // subView — a data fetch triggered by a render-time effect would cause a
  // cascading re-render on every subView change.

  async function archiveTermNow() {
    if (!window.confirm('End the current term and archive all certified winners now? They will move out of the live results into the Past Terms archive.')) return;
    setArchiving(true);
    setResultMessage(null);
    try {
      const res = await api.post('/admin/results/archive-term');
      setResultMessage({ ok: true, text: res.data?.message || 'Term archived.' });
      await Promise.all([loadResults(), loadArchived()]);
    } catch (err) {
      setResultMessage({ ok: false, text: err.response?.data?.message || 'Could not archive the term.' });
    } finally {
      setArchiving(false);
    }
  }

  const positionResults = Array.isArray(resultsData?.results)
    ? resultsData.results
    : [];
  const candidateRows = positionResults.flatMap((result) => {
    const candidates = Array.isArray(result.candidates) ? result.candidates : [];
    const totalVotes = candidates.reduce((sum, candidate) => sum + Number(candidate.votes || 0), 0);
    const sortedCandidates = [...candidates].sort(
      (a, b) => Number(b.votes || 0) - Number(a.votes || 0),
    );
    const winningVotes = Number(sortedCandidates[0]?.votes || 0);

    // The backend flags a candidate `elected`, `tied`, or `pending` once
    // results are finalized. Until that happens every row is still `pending`,
    // so fall back to the raw top-vote heuristic for the live preview.
    let statusFor = (candidate) => {
      const s = candidate.election_status;
      if (s === 'elected') return 'WINNER';
      if (s === 'tied') return 'TIE';
      if (s && s !== 'pending') return 'Eliminated';
      return candidate.votes === winningVotes ? 'WINNER' : 'Eliminated';
    };

    return sortedCandidates.map((candidate, index) => {
      const votes = Number(candidate.votes || 0);
      const percentage = totalVotes ? (votes / totalVotes) * 100 : 0;
      const name = String(candidate.name || candidate.candidate_ref || 'Unknown candidate');
      return {
        id: candidate.id,
        electionStatus: candidate.election_status || 'pending',
        rank: `#${index + 1}`,
        initials: name
          .split(/\s+/)
          .map((part) => part[0])
          .join('')
          .slice(0, 2)
          .toUpperCase(),
        name,
        position: result.position_label || result.position_key,
        party: '—',
        votes,
        percentage,
        status: statusFor(candidate),
      };
    });
  });
  const totalVotes = candidateRows.reduce((sum, candidate) => sum + candidate.votes, 0);

  const getFilteredCandidates = () => {
    return candidateRows.filter(item => {
      if (subView === 'elected' && item.status !== 'WINNER') return false;
      if (subView === 'unsuccessful' && item.status !== 'Eliminated') return false;
      if (selectedPosition !== 'All' && item.position !== selectedPosition) return false;
      if (selectedParty !== 'All' && item.party !== selectedParty) return false;
      if (searchTerm && !item.name.toLowerCase().includes(searchTerm.toLowerCase())) return false;
      return true;
    });
  };
  const filteredCandidates = getFilteredCandidates();

  // A finalize has run once any candidate carries a non-pending status.
  const isFinalized = candidateRows.some((c) => c.electionStatus !== 'pending');
  const tiedCandidates = candidateRows.filter((c) => c.status === 'TIE');
  const hasTies = tiedCandidates.length > 0;

  const renderResultSection = (title, candidates) => (
    <div className="result-card">
      <h3 className="result-card-title">{title}</h3>
      <div className="candidate-list">
        {candidates.map((candidate, idx) => (
          <div key={idx} className="candidate-item">
            <div className="candidate-row-header">
              <div className="candidate-info">
                <span className="candidate-name">{candidate.name}</span>
                <span className={`badge-tag ${candidate.status === 'WINNER' ? 'badge-winner' : candidate.status === 'TIE' ? 'badge-tie' : 'badge-runner'}`}>
                  {candidate.status === 'TIE' ? (isAdmin ? 'TIE — RESOLVE' : 'TIE') : candidate.status}
                </span>
              </div>
              <span className="vote-count">{candidate.votes} votes ({candidate.percentage})</span>
            </div>
            <div className="progress-bar-bg">
              <div 
                className={`progress-bar-fill ${candidate.status === 'WINNER' ? 'fill-green' : candidate.status === 'TIE' ? 'fill-red' : 'fill-blue'}`} 
                style={{ width: candidate.percentage }}
              />
            </div>
            {candidate.status === 'TIE' && isAdmin && (
              <button
                className="btn-action btn-blue tie-resolve-btn"
                disabled={resolvingId === candidate.id}
                onClick={() => resolveTie(candidate.id)}
              >
                {resolvingId === candidate.id ? 'Resolving…' : 'Declare Winner'}
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  );

  return (
    <div className="dashboard-container">
      {/* Sidebar Navigation */}
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={onLogout} currentUser={currentUser} />

      {/* Main Content Area */}
      <main className="main-content">
        <Header
          breadcrumb={subView === 'live' ? 'Election Results — Live' : 'All Candidates Results'}
          currentUser={currentUser}
          onNavigate={onNavigate}
        />

        <div className="results-body">
          {/* VIEW 1: LIVE ELECTION RESULTS OVERVIEW */}
          {subView === 'live' && phase !== 'voting_closed' && (
            <div className="no-results-banner">
              <p>
                Election results are locked until voting closes. Current phase:{' '}
                <strong>{displayPhase(phase)}</strong>.
              </p>
            </div>
          )}
          {subView === 'live' && phase === 'voting_closed' && (
            <>
              {resultMessage && (
                <div className={`finalize-banner ${resultMessage.ok ? 'finalize-ok' : 'finalize-error'}`}>
                  {resultMessage.text}
                </div>
              )}

              {/* Finalize / recount action */}
              <div className="finalize-panel">
                <div className="finalize-panel-text">
                  <h3 className="finalize-panel-title">
                    {isFinalized ? 'Official Results' : 'Finalize Results'}
                  </h3>
                  <p className="finalize-panel-body">
                    {isFinalized
                      ? 'Winners have been determined from the sealed ledger. Recount anytime to recompute from the raw votes.'
                      : 'Auto-determine every seat from the raw vote ledger. Winners are certified automatically; tied seats are held for your decision.'}
                  </p>
                  {isFinalized && hasTies && (
                    <p className="finalize-panel-body finalize-tie-warning">
                      ⚠ {tiedCandidates.length} candidate(s) in a pending tie.
                      {isAdmin ? ' Use "Declare Winner" on a tied card to break it.' : ' An admin must break this tie before the seat is official.'}
                    </p>
                  )}
                </div>
                <div className="finalize-panel-actions">
                  <button
                    className="btn-action btn-blue"
                    disabled={finalizing}
                    onClick={finalizeResults}
                  >
                    {finalizing ? 'Finalizing…' : isFinalized ? 'Recount & Re-finalize' : 'Finalize Winners'}
                  </button>
                  {isFinalized && isAdmin && (
                    <button
                      className="btn-action btn-purple"
                      disabled={archiving}
                      onClick={archiveTermNow}
                      title="End the current term and archive all certified winners to Past Terms"
                    >
                      {archiving ? 'Archiving…' : 'End Term & Archive Winners'}
                    </button>
                  )}
                </div>
              </div>

              {/* Metric Cards Row */}
              <div className="metrics-grid">
                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">TOTAL VOTES RECORDED</span>
                    <div className="metric-icon-box green-icon-box">
                      <CheckCircle2 size={18} color="#16a34a" />
                    </div>
                  </div>
                  <div className="metric-value">{loadingResults ? '…' : totalVotes}</div>
                  <div className="metric-subtitle">Total candidate votes recorded</div>
                </div>

                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">WINNERS CERTIFIED</span>
                    <div className="metric-icon-box blue-icon-box">
                      <TrendingUp size={18} color="#d97706" />
                    </div>
                  </div>
                  <div className="metric-value">{loadingResults ? '…' : candidateRows.filter((c) => c.status === 'WINNER').length}</div>
                  <div className="metric-subtitle">Officially elected seats</div>
                </div>
              </div>

              {/* Navigation Action Buttons Row */}
              <div className="results-action-buttons">
                <button 
                  className="btn-action btn-blue"
                  onClick={() => setSubView('all-candidates')}
                >
                  View All Candidates Results
                </button>
                <button 
                  className="btn-action btn-green"
                  onClick={() => setSubView('elected')}
                >
                  View All Elected
                </button>
                <button 
                  className="btn-action btn-red"
                  onClick={() => setSubView('unsuccessful')}
                >
                  View All Unsuccessful
                </button>
                <button 
                  className="btn-action btn-purple"
                  onClick={() => { setArchiving(false); setSubView('past-terms'); loadArchived(); }}
                >
                  Past Terms
                </button>
              </div>

              {/* Position Category Cards Grid */}
              <div className="results-grid">
                {loadingResults && <p className="no-results-banner">Loading published results...</p>}
                {!loadingResults && positionResults.map((result) => {
                  const candidates = candidateRows.filter(
                    (candidate) => candidate.position === (result.position_label || result.position_key),
                  );
                  return renderResultSection(
                    result.position_label || result.position_key,
                    candidates.map((candidate) => ({
                      ...candidate,
                      percentage: `${candidate.percentage.toFixed(1)}%`,
                      status: candidate.status === 'WINNER' ? 'WINNER'
                        : candidate.status === 'TIE' ? 'TIE'
                        : 'RUNNER UP',
                    })),
                  );
                })}
              </div>
              {!resultsData && !loadingResults && (
                <p className="no-results-banner">No published results are available.</p>
              )}
            </>
          )}

          {/* VIEW: PAST TERMS ARCHIVE */}
          {subView === 'past-terms' && (
            <div className="detailed-results-container">
              <div className="detail-view-header">
                <button className="back-circle-btn" onClick={() => setSubView('live')}>
                  <ArrowLeft size={16} />
                </button>
                <h2 className="detail-view-title">Past Terms — Winner Archive</h2>
                <div className="phase-text-badge">
                  Current Phase: <span className={phaseClass(phase)}>{displayPhase(phase)}</span>
                </div>
              </div>

              {resultMessage && (
                <div className={`finalize-banner ${resultMessage.ok ? 'finalize-ok' : 'finalize-error'}`}>
                  {resultMessage.text}
                </div>
              )}

              {(archiveData?.term_due && !isAdmin) ? (
                <p className="no-results-banner">The current term has ended and its winners have been moved to the archive.</p>
              ) : (
                <div className="finalize-panel">
                  <div className="finalize-panel-text">
                    <h3 className="finalize-panel-title">End of Term Archive</h3>
                    <p className="finalize-panel-body">
                      Winners record as "archived" once their term ends, so you can always look up
                      who won each school year — even after a new election starts.
                      {archiveData?.term_ends_at
                        ? <> Term Ends is configured for {formatArchiveDate(archiveData.term_ends_at)}.</>
                        : ' No Term Ends date is set; winners are archived manually.'}
                    </p>
                  </div>
                  {isAdmin && (
                    <div className="finalize-panel-actions">
                      <button className="btn-action btn-purple" disabled={archiving} onClick={archiveTermNow}>
                        {archiving ? 'Archiving…' : 'End Term & Archive Winners'}
                      </button>
                    </div>
                  )}
                </div>
              )}

              {loadingArchive ? (
                <p className="no-results-banner">Loading archived terms…</p>
              ) : !Array.isArray(archiveData?.terms) || archiveData.terms.length === 0 ? (
                <p className="no-results-banner">No archived terms yet. Winners stay here after their term ends.</p>
              ) : (
                archiveData.terms.map((term) => (
                  <div className="result-card" key={term.term_label}>
                    <h3 className="result-card-title">
                      {term.term_label}
                      <span className="term-archive-sub">
                        {term.winners.length} winner{term.winners.length === 1 ? '' : 's'} · archived{' '}
                        {formatArchiveDate(term.archived_at)}
                      </span>
                    </h3>
                    <div className="candidate-list">
                      {term.winners.map((w, idx) => (
                        <div key={idx} className="candidate-item">
                          <div className="candidate-row-header">
                            <div className="candidate-info">
                              <span className="candidate-name">{w.name}</span>
                              <span className="badge-tag badge-winner">WINNER</span>
                            </div>
                            <span className="vote-count">
                              {w.position || w.tier || 'Position'} · {w.vote_total ?? 0} votes
                            </span>
                          </div>
                          {w.party && <p className="archive-party">Party: {w.party}</p>}
                        </div>
                      ))}
                    </div>
                  </div>
                ))
              )}
            </div>
          )}

          {/* VIEW 2: DETAILED CANDIDATE TABLE */}
          {subView !== 'live' && subView !== 'past-terms' && (
            <div className="detailed-results-container">
              <div className="detail-view-header">
                <button className="back-circle-btn" onClick={() => setSubView('live')}>
                  <ArrowLeft size={16} />
                </button>
                <h2 className="detail-view-title">
                  {subView === 'elected' ? 'All Elected Results' : subView === 'unsuccessful' ? 'All Unsuccessful' : 'All Candidates Results'}
                </h2>
                <div className="phase-text-badge">
                  Current Phase: <span className={phaseClass(phase)}>{displayPhase(phase)}</span>
                </div>
              </div>

              <div className="metrics-grid-3col">
                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">TOTAL CANDIDATES</span>
                    <div className="metric-icon-box blue-icon-box">
                      <Users2 size={18} color="#2563eb" />
                    </div>
                  </div>
                  <div className="metric-value">{loadingResults ? '…' : candidateRows.length}</div>
                  <div className="metric-subtitle">Candidates included in published results</div>
                </div>

                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">TOTAL VOTES CAST</span>
                    <div className="metric-icon-box green-icon-box">
                      <CheckCircle2 size={18} color="#16a34a" />
                    </div>
                  </div>
                  <div className="metric-value">{loadingResults ? '…' : totalVotes}</div>
                  <div className="metric-subtitle">Total candidate votes recorded</div>
                </div>

                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">ESTIMATED TURNOUT</span>
                    <div className="metric-icon-box orange-icon-box">
                      <TrendingUp size={18} color="#d97706" />
                    </div>
                  </div>
                  <div className="metric-value">—</div>
                  <div className="metric-subtitle">Turnout is not included in this response</div>
                </div>
              </div>

              <div className="table-controls-card">
                <div className="search-filter-group">
                  <div className="search-box-container">
                    <Search size={16} className="search-icon" />
                    <input 
                      type="text" 
                      placeholder="Search candidate..." 
                      className="search-input"
                      value={searchTerm}
                      onChange={(e) => setSearchTerm(e.target.value)}
                    />
                  </div>

                  <div className="select-dropdown-container">
                    <select 
                      className="filter-select"
                      value={selectedPosition}
                      onChange={(e) => setSelectedPosition(e.target.value)}
                    >
                      <option value="All">Position: All</option>
                      <option value="President">President</option>
                      <option value="Vice President">Vice President</option>
                      <option value="Secretary">Secretary</option>
                      <option value="Treasurer">Treasurer</option>
                    </select>
                    <ChevronDown size={14} className="dropdown-arrow" />
                  </div>

                  <div className="select-dropdown-container">
                    <select 
                      className="filter-select"
                      value={selectedParty}
                      onChange={(e) => setSelectedParty(e.target.value)}
                    >
                      <option value="All">Party: All</option>
                    </select>
                    <ChevronDown size={14} className="dropdown-arrow" />
                  </div>
                </div>

                <button className="btn-export-csv">
                  <Download size={15} /> Export CSV
                </button>
              </div>

              <div className="results-table-card">
                <table className="results-table">
                  <thead>
                    <tr>
                      <th>RANK</th>
                      <th>CANDIDATE</th>
                      <th>POSITION</th>
                      <th>PARTY</th>
                      <th>VOTES</th>
                      <th>PERCENTAGE</th>
                      <th>STATUS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredCandidates.length === 0 ? (
                      <tr>
                        <td colSpan="7" className="no-data-cell">No candidates match your search or filter selections.</td>
                      </tr>
                    ) : (
                      filteredCandidates.map((row, index) => (
                      <tr key={index}>
                        <td className="rank-cell">{row.rank}</td>
                        <td>
                          <div className="candidate-name-cell">
                            <span className="avatar-initials">{row.initials}</span>
                            <span className="candidate-table-name">{row.name}</span>
                          </div>
                        </td>
                        <td className="text-muted-cell">{row.position}</td>
                        <td className="text-muted-cell">{row.party}</td>
                        <td className="votes-cell">{row.votes}</td>
                        <td className="percentage-cell">
                          <div className="table-progress-wrap">
                            <span className="pct-text">{row.percentage}%</span>
                            <div className="table-progress-bg">
                              <div 
                                className={`table-progress-fill ${row.status === 'WINNER' ? 'fill-green' : 'fill-red'}`}
                                style={{ width: `${row.percentage}%` }}
                              />
                            </div>
                          </div>
                        </td>
                        <td>
                          <span className={`status-pill ${row.status === 'WINNER' ? 'pill-winner' : row.status === 'TIE' ? 'pill-tie' : 'pill-eliminated'}`}>
                            {row.status === 'TIE' ? 'TIE' : row.status}
                          </span>
                        </td>
                      </tr>
                      ))
                    )}
                  </tbody>
                </table>

                <div className="table-footer">
                  <span className="footer-pagination-info">
                    Showing {filteredCandidates.length} of {candidateRows.length} candidates
                    {searchTerm || selectedPosition !== 'All' || selectedParty !== 'All' ? ' after filters' : ''}
                  </span>
                </div>
              </div>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}