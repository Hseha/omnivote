import { useState, useEffect, useCallback } from 'react';
import api from './lib/api';
import { Check, X, Search, Plus, UserPlus, Flag, Users } from 'lucide-react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import { defaultUserIconDataUri, fallbackAvatarOnError } from './lib/avatar';
import './Candidates.css';

const TIER_LABELS = { national: 'National', provincial: 'Provincial' };

export default function Candidates({ onLogout, activeView = 'candidates', onNavigate, currentUser = null }) {
  const [candidates, setCandidates] = useState([]);
  const [loading, setLoading] = useState(true);

  // Server-side filter state (tier/department/party/status + debounced search).
  const [departments, setDepartments] = useState([]);
  const [parties, setParties] = useState([]);
  const [counts, setCounts] = useState({ pending: 0, approved: 0, rejected: 0 });

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [tier, setTier] = useState('');
  const [department, setDepartment] = useState('');
  const [party, setParty] = useState('');
  const [status, setStatus] = useState('');

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [pagination, setPagination] = useState(null);

  // Add Candidate / Add Party modal state + forms
  const [showCandidateModal, setShowCandidateModal] = useState(false);
  const [showPartyModal, setShowPartyModal] = useState(false);
  const [modalError, setModalError] = useState('');
  const [savingCandidate, setSavingCandidate] = useState(false);

  const [positions, setPositions] = useState([]);
  const [userSearch, setUserSearch] = useState('');
  const [debouncedUserSearch, setDebouncedUserSearch] = useState('');
  const [userResults, setUserResults] = useState([]);
  const [searchingUsers, setSearchingUsers] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);
  const [candidateForm, setCandidateForm] = useState({
    position_id: '',
    party_name: '',
    slogan: '',
    platform_statement: '',
  });

  const [partyName, setPartyName] = useState('');
  const [savingParty, setSavingParty] = useState(false);
  const [partyError, setPartyError] = useState('');

  // Debounce search so we don't hammer the API on each keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(t);
  }, [search]);

  // Debounce the Add Candidate student search input.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedUserSearch(userSearch), 350);
    return () => clearTimeout(t);
  }, [userSearch]);

  // Candidate creator: load active ballot positions once.
  useEffect(() => {
    if (showCandidateModal && positions.length === 0) {
      api
        .get('/positions')
        .then((res) => setPositions(res.data ?? []))
        .catch(() => setPositions([]));
    }
  }, [showCandidateModal]); // eslint-disable-line react-hooks/exhaustive-deps

  // Candidate creator: search registered students by name/email/student id.
  useEffect(() => {
    if (!debouncedUserSearch.trim()) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setUserResults([]);
      return;
    }
    let cancelled = false;
    setSearchingUsers(true);
    api
      .get('/admin/users', { params: { search: debouncedUserSearch, role: 'student', per_page: 8 } })
      .then((res) => {
        if (!cancelled) setUserResults(res.data?.data ?? []);
      })
      .catch(() => {
        if (!cancelled) setUserResults([]);
      })
      .finally(() => {
        if (!cancelled) setSearchingUsers(false);
      });
    return () => {
      cancelled = true;
    };
  }, [debouncedUserSearch]);

  const fetchCandidates = useCallback(async () => {
    try {
      const params = {};
      if (debouncedSearch) params.search = debouncedSearch;
      if (tier) params.tier = tier;
      if (department) params.department = department;
      if (party) params.party = party;
      if (status) params.status = status;
      params.page = page;
      params.per_page = perPage;

      const res = await api.get('/admin/candidates', { params });
      const payload = res.data ?? {};
      setCandidates(payload.data ?? []);
      setPagination(payload.meta ?? null);
      if (payload.meta) {
        setParties(payload.meta.parties ?? []);
        const deptArr = payload.meta.departments ?? [];
        if (Array.isArray(deptArr)) setDepartments(deptArr);
        const c = payload.meta.counts ?? {};
        setCounts({
          pending: c.pending ?? 0,
          approved: c.approved ?? 0,
          rejected: c.rejected ?? 0,
        });
      }
    } catch (err) {
      console.warn('Failed to load candidates:', err.message);
      setCandidates([]);
      setPagination(null);
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, tier, department, party, status, page, perPage]);

  // fetchCandidates only sets state after its awaited API call resolves; the
  // disable covers the conservative react-hooks set-state-in-effect analysis.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    fetchCandidates();
  }, [fetchCandidates]);

  const updateFilter = (apply) => {
    setPage(1);
    apply();
  };

  const handleStatusChange = async (id, newStatus) => {
    const normalized = (newStatus || '').toLowerCase();
    try {
      await api.patch(`/admin/candidates/${id}`, { status: normalized });
      await fetchCandidates();
    } catch (err) {
      console.warn('Failed to update candidate status:', err.message);
    }
  };

  const clearFilters = () => {
    setSearch('');
    setDebouncedSearch('');
    setTier('');
    setDepartment('');
    setParty('');
    setStatus('');
    setPage(1);
  };

  const hasActiveFilters = debouncedSearch || tier || department || party || status;

  const openCandidateModal = () => {
    setModalError('');
    setUserSearch('');
    setDebouncedUserSearch('');
    setUserResults([]);
    setSelectedUser(null);
    setCandidateForm({ position_id: '', party_name: '', slogan: '', platform_statement: '' });
    setShowCandidateModal(true);
  };

  const openPartyModal = () => {
    setPartyError('');
    setPartyName('');
    setShowPartyModal(true);
  };

  const handleSaveCandidate = async () => {
    if (!selectedUser) {
      setModalError('Search for and select the registered student first.');
      return;
    }
    if (!candidateForm.position_id) {
      setModalError('Pick the position this student is running for.');
      return;
    }
    setSavingCandidate(true);
    setModalError('');
    try {
      await api.post('/admin/candidates', {
        student_id: selectedUser.student_id,
        position_id: Number(candidateForm.position_id),
        party_name: candidateForm.party_name.trim() || null,
        slogan: candidateForm.slogan.trim() || null,
        platform_statement: candidateForm.platform_statement.trim() || null,
      });
      setShowCandidateModal(false);
      await fetchCandidates();
    } catch (err) {
      setModalError(err?.response?.data?.message || err.message);
    } finally {
      setSavingCandidate(false);
    }
  };

  const handleSaveParty = async () => {
    if (!partyName.trim()) {
      setPartyError('Enter a party name.');
      return;
    }
    setSavingParty(true);
    setPartyError('');
    try {
      await api.post('/admin/parties', { name: partyName.trim() });
      setShowPartyModal(false);
      setPartyName('');
      await fetchCandidates();
    } catch (err) {
      setPartyError(err?.response?.data?.message || err.message);
    } finally {
      setSavingParty(false);
    }
  };

  const lastPage = pagination?.last_page ?? 1;
  const total = pagination?.total ?? 0;
  const pageStart = total === 0 ? 0 : (page - 1) * perPage + 1;
  const pageEnd = Math.min(page * perPage, total);

  const pageNumbers = [];
  for (let i = 1; i <= lastPage; i++) {
    if (i === 1 || i === lastPage || Math.abs(i - page) <= 1) {
      pageNumbers.push(i);
    } else if (pageNumbers[pageNumbers.length - 1] !== '…') {
      pageNumbers.push('…');
    }
  }

  return (
    <div className="dashboard-container">
      {/* Sidebar Navigation */}
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={onLogout} currentUser={currentUser} />

      {/* Main Candidates View */}
      <main className="main-content">
        <Header breadcrumb="Candidates" onLogout={onLogout} currentUser={currentUser} onNavigate={onNavigate} />

        {/* Search and Quick Filters Row */}
        <div className="filter-toolbar">
          <div className="search-group">
            <div className="search-bar">
              <Search size={16} />
              <input
                type="text"
                placeholder="Search name, position, party..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <select
              className="position-dropdown"
              value={tier}
              onChange={(e) => updateFilter(() => setTier(e.target.value))}
            >
              <option value="">All Tiers</option>
              <option value="national">National</option>
              <option value="provincial">Provincial</option>
            </select>
            <select
              className="position-dropdown"
              value={department}
              onChange={(e) => updateFilter(() => setDepartment(e.target.value))}
            >
              <option value="">All Departments</option>
              {departments.map((d) => (
                <option key={d} value={d}>
                  {d}
                </option>
              ))}
            </select>
            <select
              className="position-dropdown"
              value={party}
              onChange={(e) => updateFilter(() => setParty(e.target.value))}
            >
              <option value="">All Parties</option>
              {parties.map((p) => (
                <option key={p} value={p}>
                  {p}
                </option>
              ))}
            </select>
          </div>

          <div className="status-filter-buttons">
            <button
              className={`filter-btn filter-pending ${status === 'pending' ? 'active' : ''}`}
              onClick={() => updateFilter(() => setStatus(status === 'pending' ? '' : 'pending'))}
            >
              Pending {counts.pending}
            </button>
            <button
              className={`filter-btn filter-approved ${status === 'approved' ? 'active' : ''}`}
              onClick={() => updateFilter(() => setStatus(status === 'approved' ? '' : 'approved'))}
            >
              Approved {counts.approved}
            </button>
            <button
              className={`filter-btn filter-rejected ${status === 'rejected' ? 'active' : ''}`}
              onClick={() => updateFilter(() => setStatus(status === 'rejected' ? '' : 'rejected'))}
            >
              Rejected {counts.rejected}
            </button>
            {/* Always rendered so its space is reserved and the three status
                pills never shift/wrap; hidden (but reserving width) when there
                is nothing to clear. */}
            <button className="page-btn" onClick={clearFilters} disabled={!hasActiveFilters}>
              Clear filters
            </button>
          </div>
        </div>

        <section className="card table-card">
          <div className="card-header-actions candidates-header">
            <h3>Candidates List</h3>
            <div className="candidates-action-buttons">
              <button type="button" className="cm-btn-primary" onClick={openCandidateModal}>
                <UserPlus size={16} /> Add Candidate
              </button>
              <button type="button" className="cm-btn-outline" onClick={openPartyModal}>
                <Flag size={16} /> Add Party
              </button>
            </div>
          </div>

          <table className="actions-table">
            <thead>
              <tr>
                <th>Candidate Name</th>
                <th>Target Position</th>
                <th>Tier</th>
                <th>Party / Platform Name</th>
                <th>Submission Date</th>
                <th>Status</th>
                <th className="text-right">Admin Action</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="7" className="no-data-cell">Loading candidates...</td></tr>
              ) : candidates.length === 0 ? (
                <tr><td colSpan="7" className="no-data-cell">No candidates found yet — submissions will appear here once students file their applications during Registration.</td></tr>
              ) : (
                candidates.map((candidate) => (
                  <tr key={candidate.id}>
                    <td>
                      <div className="candidate-profile-cell">
                        <img
                          src={candidate.avatar || defaultUserIconDataUri(72)}
                          alt={candidate.name}
                          className="candidate-avatar"
                          onError={fallbackAvatarOnError(candidate.name)}
                        />
                        <span className="font-semibold">{candidate.name}</span>
                      </div>
                    </td>
                    <td>{candidate.position}</td>
                    <td className="muted-text">{TIER_LABELS[candidate.tier] ?? candidate.tier ?? '—'}</td>
                    <td className="muted-text">{candidate.party || '—'}</td>
                    <td className="muted-text">{candidate.submissionDate || '—'}</td>
                    <td>
                      <span className={`status-badge ${candidate.status.toLowerCase()}`}>
                        {candidate.status}
                      </span>
                    </td>
                    <td className="actions-cell">
                      <button
                        className="btn-approve"
                        onClick={() => handleStatusChange(candidate.id, 'approved')}
                      >
                        <Check size={14} /> Approve
                      </button>
                      <button
                        className="btn-reject"
                        onClick={() => handleStatusChange(candidate.id, 'rejected')}
                      >
                        <X size={14} /> Reject
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>

          {/* Real pagination driven by the API meta */}
          {pagination && total > 0 && (
            <>
              <div className="table-page-size">
                <label htmlFor="per-page">Rows</label>
                <select
                  id="per-page"
                  className="position-dropdown"
                  value={perPage}
                  onChange={(e) => {
                    setPerPage(Number(e.target.value));
                    setPage(1);
                  }}
                >
                  <option value="10">10</option>
                  <option value="20">20</option>
                  <option value="50">50</option>
                </select>
              </div>
              <div className="pagination-container">
                <span className="pagination-info">
                  Showing {pageStart}–{pageEnd} of {total} candidates
                </span>
                <div className="pagination-buttons">
                  <button className="page-btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                    Previous
                  </button>
                  {pageNumbers.map((num, idx) =>
                    num === '…' ? (
                      <span key={`e-${idx}`} className="page-btn page-ellipsis">…</span>
                    ) : (
                      <button
                        key={num}
                        className={`page-btn ${num === page ? 'active' : ''}`}
                        onClick={() => setPage(num)}
                      >
                        {num}
                      </button>
                    ),
                  )}
                  <button className="page-btn" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
                    Next
                  </button>
                </div>
              </div>
            </>
          )}
        </section>
      </main>

      {showCandidateModal && (
        <div className="cm-modal-overlay" onClick={() => !savingCandidate && setShowCandidateModal(false)}>
          <div
            className="cm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cm-add-candidate-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="cm-modal-header">
              <div>
                <h3 id="cm-add-candidate-title">Add Candidate</h3>
                <p>Find a registered student and enter them for a position</p>
              </div>
              <button type="button" className="cm-modal-close" onClick={() => setShowCandidateModal(false)} disabled={savingCandidate} aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="cm-modal-body">
              <div className="cm-form-group">
                <label className="cm-form-label">Student</label>
                <div className="cm-user-search-wrap">
                  <div className="cm-user-search">
                    <Search size={15} />
                    <input
                      type="text"
                      placeholder="Search name, email, or student ID..."
                      value={userSearch}
                      onChange={(e) => setUserSearch(e.target.value)}
                      disabled={!!selectedUser}
                    />
                    {selectedUser && (
                      <button
                        type="button"
                        className="cm-user-clear"
                        onClick={() => {
                          setSelectedUser(null);
                          setUserSearch('');
                          setDebouncedUserSearch('');
                          setUserResults([]);
                        }}
                        aria-label="Clear selected student"
                      >
                        <X size={14} />
                      </button>
                    )}
                  </div>
                  {selectedUser ? (
                    <div className="cm-selected-user">
                      <Users size={15} />
                      <span>{selectedUser.name}</span>
                      <span className="cm-selected-user-meta">
                        {selectedUser.student_id} · {selectedUser.department || 'No department'}
                      </span>
                    </div>
                  ) : (
                    debouncedUserSearch.trim() !== '' && (
                      <div className="cm-user-results">
                        {searchingUsers ? (
                          <div className="cm-user-empty">Searching…</div>
                        ) : userResults.length === 0 ? (
                          <div className="cm-user-empty">No registered students match that search.</div>
                        ) : (
                          userResults.map((u) => (
                            <button
                              type="button"
                              key={u.id}
                              className="cm-user-result"
                              onClick={() => {
                                setSelectedUser(u);
                                setUserResults([]);
                              }}
                            >
                              <span className="cm-user-result-name">{u.name}</span>
                              <span className="cm-selected-user-meta">
                                {u.student_id} · {u.department || 'No department'}
                              </span>
                            </button>
                          ))
                        )}
                      </div>
                    )
                  )}
                </div>
              </div>

              <div className="cm-form-group">
                <label className="cm-form-label">Position</label>
                <select
                  className="cm-form-input"
                  value={candidateForm.position_id}
                  onChange={(e) => setCandidateForm((prev) => ({ ...prev, position_id: e.target.value }))}
                >
                  <option value="">Select a position…</option>
                  {positions.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.tier === 'provincial' ? 'Provincial — ' : 'National — '}
                      {p.name}
                      {p.seat_count > 1 ? ` (${p.seat_count} seats)` : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div className="cm-form-group">
                <label className="cm-form-label">Party</label>
                <select
                  className="cm-form-input"
                  value={candidateForm.party_name}
                  onChange={(e) => setCandidateForm((prev) => ({ ...prev, party_name: e.target.value }))}
                >
                  <option value="">No party</option>
                  {parties.map((p) => (
                    <option key={p} value={p}>
                      {p}
                    </option>
                  ))}
                </select>
              </div>

              <div className="cm-form-group">
                <label className="cm-form-label">Slogan (optional)</label>
                <input
                  type="text"
                  className="cm-form-input"
                  maxLength={255}
                  value={candidateForm.slogan}
                  onChange={(e) => setCandidateForm((prev) => ({ ...prev, slogan: e.target.value }))}
                />
              </div>

              <div className="cm-form-group">
                <label className="cm-form-label">Platform statement (optional)</label>
                <textarea
                  className="cm-form-input cm-form-textarea"
                  rows={4}
                  maxLength={5000}
                  value={candidateForm.platform_statement}
                  onChange={(e) => setCandidateForm((prev) => ({ ...prev, platform_statement: e.target.value }))}
                />
              </div>

              <p className="cm-modal-hint">The candidate will be created as <strong>Pending</strong> and needs your approval below.</p>

              {modalError && <div className="cm-modal-error">{modalError}</div>}
            </div>

            <div className="cm-modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setShowCandidateModal(false)} disabled={savingCandidate}>
                Cancel
              </button>
              <button type="button" className="cm-btn-primary" onClick={handleSaveCandidate} disabled={savingCandidate || !selectedUser}>
                <UserPlus size={16} /> {savingCandidate ? 'Adding…' : 'Add Candidate'}
              </button>
            </div>
          </div>
        </div>
      )}

      {showPartyModal && (
        <div className="cm-modal-overlay" onClick={() => !savingParty && setShowPartyModal(false)}>
          <div
            className="cm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cm-add-party-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="cm-modal-header">
              <div>
                <h3 id="cm-add-party-title">Add Party</h3>
                <p>Create a party students can pick on their candidacy forms</p>
              </div>
              <button type="button" className="cm-modal-close" onClick={() => setShowPartyModal(false)} disabled={savingParty} aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="cm-modal-body">
              <div className="cm-form-group">
                <label className="cm-form-label">Party name</label>
                <input
                  type="text"
                  className="cm-form-input"
                  maxLength={255}
                  placeholder="e.g. SVEA"
                  value={partyName}
                  onChange={(e) => setPartyName(e.target.value)}
                  autoFocus
                />
              </div>
              <p className="cm-modal-hint">New parties appear immediately in the student app and the party filter above.</p>
              {partyError && <div className="cm-modal-error">{partyError}</div>}
            </div>

            <div className="cm-modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setShowPartyModal(false)} disabled={savingParty}>
                Cancel
              </button>
              <button type="button" className="cm-btn-primary" onClick={handleSaveParty} disabled={savingParty || !partyName.trim()}>
                <Plus size={16} /> {savingParty ? 'Adding…' : 'Add Party'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}