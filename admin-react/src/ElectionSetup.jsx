import React, { useState, useEffect } from 'react';
import {
  LayoutDashboard,
  Users,
  UserCheck,
  Sliders,
  BarChart2,
  Settings,
  Vote,
  Clock,
  Plus,
  Clock3,
  LogOut,
} from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './ElectionSetup.css';

const PHASE_LABELS = ['registration', 'voting_open', 'voting_closed'];

function displayPhase(phase) {
  switch (phase) {
    case 'registration':
      return 'Registration';
    case 'voting_open':
      return 'Voting Open';
    case 'voting_closed':
      return 'Voting Closed';
    default:
      return phase || 'Registration';
  }
}

export default function ElectionSetup({ activeView = 'setup', onNavigate, onLogout }) {
  const { logout } = useAuth();
  const [subView, setSubView] = useState('main');

  const [electionTitle, setElectionTitle] = useState('Student Council General Election 2024');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [currentPhase, setCurrentPhase] = useState('registration');
  const [loadingConfig, setLoadingConfig] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  const [nationalsPositions, setNationalsPositions] = useState([]);

  const [provincialPositions, setProvincialPositions] = useState([]);

  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  useEffect(() => {
    const loadConfig = async () => {
      try {
        setLoadingConfig(true);
        const res = await api.get('/admin/election/config');
        const data = res.data?.config ?? res.data ?? {};
        if (data.title) setElectionTitle(data.title);
        if (data.phase) setCurrentPhase(data.phase);
        if (data.registration_opens_at) setStartDate(String(data.registration_opens_at));
        if (data.voting_closes_at) setEndDate(String(data.voting_closes_at));

        const positions = Array.isArray(data.positions) ? data.positions : [];
        if (positions.length > 0) {
          setNationalsPositions(positions.filter((p) => p.tier === 'school'));
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
        phase: currentPhase,
        registration_opens_at: startDate || null,
        voting_closes_at: endDate || null,
        positions,
      });
      setMessage('Configuration saved.');
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

  return (
    <div className="dashboard-container">
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon"><Vote size={20} /></div>
          <div>
            <h1 className="brand-name">ElectBoard</h1>
            <p className="brand-sub">ELECTION CONSOLE</p>
          </div>
        </div>

        <nav className="nav-menu">
          <button type="button" className={`nav-item ${activeView === 'dashboard' ? 'active' : ''}`} onClick={() => onNavigate && onNavigate('dashboard')}>
            <LayoutDashboard size={18} /> Dashboard
          </button>
          <button type="button" className={`nav-item ${activeView === 'candidates' ? 'active' : ''}`} onClick={() => onNavigate && onNavigate('candidates')}>
            <Users size={18} /> Candidates
          </button>
          <button type="button" className={`nav-item ${activeView === 'voters' ? 'active' : ''}`} onClick={() => onNavigate && onNavigate('voters')}>
            <UserCheck size={18} /> Student Registry
          </button>
          <button type="button" className={`nav-item ${activeView === 'setup' ? 'active' : ''}`} onClick={() => onNavigate && onNavigate('setup')}>
            <Sliders size={18} /> Election Setup
          </button>
          <button type="button" className={`nav-item ${activeView === 'results' ? 'active' : ''}`} onClick={() => onNavigate && onNavigate('results')}>
            <BarChart2 size={18} /> Results
          </button>
          <button type="button" className={`nav-item ${activeView === 'settings' ? 'active' : ''}`} onClick={() => onNavigate && onNavigate('settings')}>
            <Settings size={18} /> Settings
          </button>
        </nav>

        <div className="sidebar-footer-container">
          <button onClick={handleLogout} className="logout-button">
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer">
            <span className="status-dot-green"></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      <main className="main-content">
        <header className="top-header">
          <div className="breadcrumb">
            <span className="muted">System / </span>
            <strong className="breadcrumb-title">
              {subView === 'main' ? 'Election Configuration' : 'Election Configuration / General Parameters'}
            </strong>
          </div>
          <div className="header-right">
            <span className="voting-status-badge">
              <span className="status-dot-green"></span> {displayPhase(currentPhase)}
            </span>
            <div className="system-time">
              <Clock size={16} /> 14:32:05 EST
            </div>
            <div className="user-profile">
              <div className="user-info">
                <span className="user-name">Election Admin</span>
                <span className="user-role">System Administrator</span>
              </div>
              <img src="https://i.pravatar.cc/100?img=32" alt="Eleanor Vance" className="user-avatar" />
            </div>
          </div>
        </header>

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
                General Parameters / Set Election time
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
                  {nationalsPositions.map((pos) => (
                    <div className="position-item" key={pos.id}>
                      <div>
                        <div className="position-name">{pos.title}</div>
                        <div className="position-meta">{pos.candidates}</div>
                      </div>
                      <label className="toggle-switch">
                        <input type="checkbox" checked={pos.active} onChange={() => toggleNationalSwitch(pos.id)} />
                        <span className="slider round"></span>
                      </label>
                    </div>
                  ))}
                </div>

                <button className="btn-add-position"><Plus size={16} /> Add Position</button>
              </div>

              <div className="column-card">
                <div className="column-header">
                  <h3>Ballot Positions Provincial</h3>
                  <span className="active-count-badge">{provincialPositions.filter((p) => p.active).length} Active</span>
                </div>

                <div className="position-list">
                  {provincialPositions.map((pos) => (
                    <div className="position-item" key={pos.id}>
                      <div>
                        <div className="position-name">{pos.title}</div>
                        <div className="position-meta">{pos.candidates}</div>
                      </div>
                      <label className="toggle-switch">
                        <input type="checkbox" checked={pos.active} onChange={() => toggleProvincialSwitch(pos.id)} />
                        <span className="slider round"></span>
                      </label>
                    </div>
                  ))}
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

              <div className="form-row-2col">
                <div className="form-group">
                  <label className="form-label">Registration / Start Date &amp; Time</label>
                  <div className="input-with-icon">
                    <Clock3 size={18} className="input-icon" />
                    <input type="text" className="form-input icon-padded" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label">Voting Close Date &amp; Time</label>
                  <div className="input-with-icon">
                    <Clock3 size={18} className="input-icon" />
                    <input type="text" className="form-input icon-padded" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                  </div>
                </div>
              </div>

              <div className="form-group">
                <label className="form-label">Current Election Phase</label>
                <div className="phase-segmented-control">
                  {PHASE_LABELS.map((phase) => (
                    <button
                      key={phase}
                      type="button"
                      className={`phase-btn ${currentPhase === phase ? 'active' : ''}`}
                      onClick={() => setCurrentPhase(phase)}
                    >
                      {displayPhase(phase)}
                    </button>
                  ))}
                </div>
              </div>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
