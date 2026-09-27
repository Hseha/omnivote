import { useState, useEffect } from 'react';
import {
  Plus,
  Pencil,
  X,
  Save,
} from 'lucide-react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import { useElectionStatus } from './lib/ElectionStatusContext';
import api from './lib/api';
import './ElectionSetup.css';

function displayPhase(phase) {
  switch (phase) {
    case 'registration':
      return 'Registration';
    case 'registration_closed':
      return 'Registration Closed';
    case 'voting_open':
      return 'Voting Open';
    case 'voting_closed':
      return 'Voting Closed';
    default:
      return phase || 'Not Configured';
  }
}

export default function ElectionSetup({ activeView = 'setup', onNavigate, onLogout, currentUser = null }) {
  const [subView, setSubView] = useState('main');

  const [electionTitle, setElectionTitle] = useState('Student Council General Election 2024');
  const [currentPhase, setCurrentPhase] = useState('registration');
  const [loadingConfig, setLoadingConfig] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  const [nationalsPositions, setNationalsPositions] = useState([]);

  const [provincialPositions, setProvincialPositions] = useState([]);

  const [editingPosition, setEditingPosition] = useState(null);
  const [editForm, setEditForm] = useState({
    title: '',
    seat_count: 1,
    tier: 'national',
    active: true,
    scope_type: 'global',
    scope_value: '',
  });
  const [editSaving, setEditSaving] = useState(false);
  const [editError, setEditError] = useState('');

  // Shared phase poller — push the manual override to the header badge
  // immediately instead of waiting for the 30 s poll.
  const { refresh } = useElectionStatus();

  useEffect(() => {
    const loadConfig = async () => {
      try {
        setLoadingConfig(true);
        const res = await api.get('/admin/election/config');
        const data = res.data?.config ?? res.data ?? {};
        if (data.title) setElectionTitle(data.title);
        if (data.phase) setCurrentPhase(data.phase);

        const positions = Array.isArray(data.positions) ? data.positions : [];
        if (positions.length > 0) {
          setNationalsPositions(positions.filter((p) => p.tier === 'national'));
          setProvincialPositions(positions.filter((p) => p.tier === 'provincial'));
        }
      } catch {
        // Keep defaults; server config endpoint not yet available.
        setMessage('Could not load current election configuration.');
      } finally {
        setLoadingConfig(false);
      }
    };
    loadConfig();
  }, []);

  const saveConfig = async () => {
    setSaving(true);
    setMessage('');
    try {
      const positions = [
        ...nationalsPositions.map((p) => ({ slug: p.slug ?? String(p.id), active: Boolean(p.active) })),
        ...provincialPositions.map((p) => ({ slug: p.slug ?? String(p.id), active: Boolean(p.active) })),
      ];

      await api.put('/admin/election/config', {
        title: electionTitle,
        positions,
      });
      setMessage('Configuration saved.');
      refresh();
    } catch (err) {
      setMessage(err.response?.data?.message || 'Failed to save configuration.');
    } finally {
      setSaving(false);
    }
  };

  const toggleNationalSwitch = (id) => {
    setNationalsPositions((prev) =>
      prev.map((pos) => (pos.id === id ? { ...pos, active: !pos.active } : pos))
    );
  };

  const toggleProvincialSwitch = (id) => {
    setProvincialPositions((prev) =>
      prev.map((pos) => (pos.id === id ? { ...pos, active: !pos.active } : pos))
    );
  };

  const openEdit = (pos) => {
    setEditingPosition(pos);
    setEditForm({
      title: pos.title ?? pos.label ?? '',
      seat_count: pos.seat_count ?? 1,
      tier: pos.tier ?? 'national',
      active: Boolean(pos.active),
      // Positions created before the electorate-scope feature have no scope
      // fields, and the server defaults them to 'global', so mirror that here
      // rather than rendering a blank select.
      scope_type: pos.scope_type ?? 'global',
      scope_value: pos.scope_value ?? '',
    });
    setEditError('');
  };

  const closeEdit = () => {
    setEditingPosition(null);
    setEditError('');
  };

  const saveEditedPosition = async () => {
    if (!editingPosition) return;
    const cleanTitle = editForm.title.trim();
    if (!cleanTitle) {
      setEditError('Position title is required.');
      return;
    }
    if (!Number.isInteger(editForm.seat_count) || editForm.seat_count < 1 || editForm.seat_count > 50) {
      setEditError('Seat count must be a whole number between 1 and 50.');
      return;
    }
    // A pinned electorate needs the value it is pinned to. Leaving it blank
    // means "scoped to each voter's own value", which is right for a
    // year-level seat but meaningless for a department/course seat.
    const wantsScopeValue = editForm.scope_type !== 'global';
    const scopeValue = editForm.scope_value.trim();
    if (wantsScopeValue && editForm.scope_type !== 'year_level' && !scopeValue) {
      setEditError('Enter the value this seat is restricted to, or switch the scope to Global.');
      return;
    }

    setEditSaving(true);
    setEditError('');
    try {
      await api.patch(`/admin/positions/${editingPosition.id}`, {
        label: cleanTitle,
        seat_count: editForm.seat_count,
        tier: editForm.tier,
        is_active: editForm.active,
        // A blank value is sent as null so the server stores "derive from each
        // voter" rather than an empty string.
        scope_type: editForm.scope_type,
        scope_value: scopeValue || null,
      });

      const updated = {
        ...editingPosition,
        title: cleanTitle,
        label: cleanTitle,
        seat_count: editForm.seat_count,
        tier: editForm.tier,
        active: editForm.active,
        scope_type: editForm.scope_type,
        scope_value: scopeValue || null,
      };

      if (updated.tier === 'national') {
        setNationalsPositions((prev) => prev.map((p) => (p.id === updated.id ? updated : p)));
      } else {
        setProvincialPositions((prev) => prev.map((p) => (p.id === updated.id ? updated : p)));
      }

      setMessage(`"${cleanTitle}" updated.`);
      closeEdit();
    } catch (err) {
      setEditError(err.response?.data?.message || 'Failed to update this position.');
    } finally {
      setEditSaving(false);
    }
  };

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={onLogout} currentUser={currentUser} />

      <main className="main-content">
        <Header
          breadcrumb={subView === 'main' ? 'Election Configuration' : 'Election Configuration / General Parameters'}
          currentUser={currentUser}
          onNavigate={onNavigate}
        />

        <div className="setup-body">
          <div className="setup-action-bar">
            {subView === 'general' ? (
              <button className="btn-back" onClick={() => setSubView('main')}>
                Back
              </button>
            ) : (
              <div>
                <h2 className="setup-title">Configure Election settings</h2>
                <p className="setup-subtitle">Manage timeline, metadata, and ballot definitions</p>
              </div>
            )}

            <div className="action-button-group">
              <button className="btn-reset" onClick={() => setMessage('')}>Reset</button>
              <button className="btn-save" onClick={saveConfig} disabled={saving || loadingConfig}>
                {saving ? 'Saving...' : 'Save Configuration'}
              </button>
              <button className="btn-green-action" onClick={() => setSubView(subView === 'main' ? 'general' : 'main')}>
                General Parameters
              </button>
            </div>
          </div>

          {message && <div className="setup-message">{message}</div>}

          {subView === 'main' && (
            <div className="columns-grid">
              <div className="column-card">
                <div className="column-header">
                  <h3>Ballot Positions Nationals</h3>
                  <span className="active-count-badge">{nationalsPositions.filter((p) => p.active).length} Active</span>
                </div>

                <div className="position-list">
                  {loadingConfig ? (
                    <p className="position-empty">Loading positions…</p>
                  ) : nationalsPositions.length === 0 ? (
                    <p className="position-empty">No national ballot positions added yet.<br />Add the first one below, then set the candidate tier in the audit fields.</p>
                  ) : (
                    nationalsPositions.map((pos) => (
                      <div className="position-item" key={pos.id}>
                        <div>
                          <div className="position-name">{pos.title}</div>
                          <div className="position-meta">{pos.candidates}</div>
                        </div>
                        <div className="position-actions">
                          <button className="btn-edit-position" onClick={() => openEdit(pos)} aria-label={`Edit ${pos.title}`}>
                            <Pencil size={14} />
                          </button>
                          <label className="toggle-switch">
                            <input type="checkbox" checked={pos.active} onChange={() => toggleNationalSwitch(pos.id)} />
                            <span className="slider round"></span>
                          </label>
                        </div>
                      </div>
                    ))
                  )}
                </div>

                <button className="btn-add-position"><Plus size={16} /> Add Position</button>
              </div>

              <div className="column-card">
                <div className="column-header">
                  <h3>Ballot Positions Provincial</h3>
                  <span className="active-count-badge">{provincialPositions.filter((p) => p.active).length} Active</span>
                </div>

                <div className="position-list">
                  {loadingConfig ? (
                    <p className="position-empty">Loading positions…</p>
                  ) : provincialPositions.length === 0 ? (
                    <p className="position-empty">No provincial ballot positions added yet.<br />Add the first one below to cover regional seats.</p>
                  ) : (
                    provincialPositions.map((pos) => (
                      <div className="position-item" key={pos.id}>
                        <div>
                          <div className="position-name">{pos.title}</div>
                          <div className="position-meta">{pos.candidates}</div>
                        </div>
                        <div className="position-actions">
                          <button className="btn-edit-position" onClick={() => openEdit(pos)} aria-label={`Edit ${pos.title}`}>
                            <Pencil size={14} />
                          </button>
                          <label className="toggle-switch">
                            <input type="checkbox" checked={pos.active} onChange={() => toggleProvincialSwitch(pos.id)} />
                            <span className="slider round"></span>
                          </label>
                        </div>
                      </div>
                    ))
                  )}
                </div>

                <button className="btn-add-position"><Plus size={16} /> Add Position</button>
              </div>
            </div>
          )}

          {subView === 'general' && (
            <div className="general-parameters-card">
              <h3>General Parameters</h3>

              <div className="form-group">
                <label className="form-label">Election Title</label>
                <input type="text" className="form-input" value={electionTitle} onChange={(e) => setElectionTitle(e.target.value)} />
              </div>

              <div className="form-group">
                <label className="form-label">Current Election Phase</label>
                <div className="phase-readonly">
                  <span className={`phase-badge ${currentPhase || 'not-configured'}`}>{displayPhase(currentPhase)}</span>
                  <p className="phase-hint">Automatically driven by the Voting Window schedule set in Settings. It advances on its own as each window opens and closes.</p>
                </div>
              </div>
            </div>
          )}
        </div>
      </main>

      {editingPosition && (
        <div className="setup-modal-overlay" onClick={() => !editSaving && closeEdit()}>
          <div
            className="setup-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="setup-edit-position-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="setup-modal-header">
              <div>
                <h3 id="setup-edit-position-title">Edit Position</h3>
                <p>Update the ballot position's details</p>
              </div>
              <button type="button" className="settings-modal-close" onClick={closeEdit} disabled={editSaving} aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="setup-modal-body">
              <div className="form-group">
                <label className="form-label">Position Title</label>
                <input
                  type="text"
                  className="form-input"
                  value={editForm.title}
                  onChange={(e) => setEditForm((prev) => ({ ...prev, title: e.target.value }))}
                />
              </div>

              <div className="form-row-2col">
                <div className="form-group">
                  <label className="form-label">Seat Count</label>
                  <input
                    type="number"
                    className="form-input"
                    min={1}
                    max={50}
                    value={editForm.seat_count}
                    onChange={(e) => setEditForm((prev) => ({ ...prev, seat_count: Number(e.target.value) }))}
                  />
                </div>

                <div className="form-group">
                  <label className="form-label">Tier</label>
                  <select
                    className="form-input"
                    value={editForm.tier}
                    onChange={(e) => setEditForm((prev) => ({ ...prev, tier: e.target.value }))}
                  >
                    <option value="national">National</option>
                    <option value="provincial">Provincial</option>
                  </select>
                </div>
              </div>

              <div className="form-group">
                <label className="form-label">Who can vote in this seat?</label>
                <select
                  className="form-input"
                  value={editForm.scope_type}
                  onChange={(e) => setEditForm((prev) => ({ ...prev, scope_type: e.target.value }))}
                >
                  <option value="global">Everyone (national seat)</option>
                  <option value="year_level">Their own year level</option>
                  <option value="department">One department</option>
                  <option value="course">One course</option>
                </select>
                <p className="form-hint">
                  {editForm.scope_type === 'global'
                    ? 'The whole student body may vote and stand in this seat.'
                    : editForm.scope_type === 'year_level'
                      ? 'Students vote for, and are voted for by, their own year level. Leave the value blank.'
                      : 'Only students in this group may vote. Enter the exact value below.'}
                </p>
              </div>

              {editForm.scope_type !== 'global' && (
                <div className="form-group">
                  <label className="form-label">
                    Restricted to
                    {editForm.scope_type === 'year_level' && (
                      <span className="form-hint-inline"> (optional)</span>
                    )}
                  </label>
                  <input
                    type="text"
                    className="form-input"
                    list={editForm.scope_type === 'year_level' ? 'scope-values-year' : undefined}
                    placeholder={
                      editForm.scope_type === 'year_level'
                        ? 'Blank = each voter uses their own year level'
                        : 'e.g. BSIT or BSBA'
                    }
                    value={editForm.scope_value}
                    onChange={(e) => setEditForm((prev) => ({ ...prev, scope_value: e.target.value }))}
                  />
                  <datalist id="scope-values-year">
                    {['7', '8', '9', '10', '11', '12'].map((y) => (
                      <option key={y} value={y} />
                    ))}
                  </datalist>
                </div>
              )}

              <label className="checkbox-row">
                <input
                  type="checkbox"
                  checked={editForm.active}
                  onChange={(e) => setEditForm((prev) => ({ ...prev, active: e.target.checked }))}
                />
                <span className="checkbox-label">Active on the ballot</span>
              </label>

              {editError && <div className="setup-dialog-error">{editError}</div>}
            </div>

            <div className="setup-modal-footer">
              <button type="button" className="btn-secondary" onClick={closeEdit} disabled={editSaving}>
                Cancel
              </button>
              <button type="button" className="btn-save" onClick={saveEditedPosition} disabled={editSaving}>
                <Save size={16} /> {editSaving ? 'Saving...' : 'Save Position'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
