import React, { useState } from 'react';
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
  Clock3 
} from 'lucide-react';
import './ElectionSetup.css';

export default function ElectionSetup({ activeView = 'setup', onNavigate, onLogout }) {
  // Toggle between 'main' (Ballot Positions) and 'general' (General Parameters)
  const [subView, setSubView] = useState('main');

  // Form states for General Parameters
  const [electionTitle, setElectionTitle] = useState('Student Council General Election 2024');
  const [startDate, setStartDate] = useState('Aug 10, 2024 8:00 AM');
  const [endDate, setEndDate] = useState('Aug 14, 2024 5:00 PM');
  const [currentPhase, setCurrentPhase] = useState('Active');

  // Sample Ballot Positions Data
  const [nationalsPositions, setNationalsPositions] = useState([
    { id: 1, title: '1 President', candidates: '6 candidates approved', active: true },
    { id: 2, title: '1 Vice President', candidates: '5 candidates approved', active: true },
    { id: 3, title: '1 Secretary', candidates: '4 candidates approved', active: true },
    { id: 4, title: '1 Treasurer', candidates: '4 candidates approved', active: true },
    { id: 5, title: '1 Auditor', candidates: '5 candidates approved', active: true },
    { id: 6, title: '1 Press Officer', candidates: '5 candidates approved', active: true },
    { id: 7, title: '1 Property Custodian', candidates: '5 candidates approved', active: true },
    { id: 8, title: '12 Senators', candidates: '5 candidates approved', active: true },
    { id: 9, title: '1 representative per year level (All Department)', candidates: '5 candidates approved', active: true },
  ]);

  const [provincialPositions, setProvincialPositions] = useState([
    { id: 1, title: '1 Governor', candidates: '6 candidates approved', active: true },
    { id: 2, title: '1 Vice Governor', candidates: '5 candidates approved', active: true },
    { id: 3, title: '1 Secretary', candidates: '4 candidates approved', active: true },
    { id: 4, title: '1 Treasurer', candidates: '4 candidates approved', active: true },
    { id: 5, title: '1 Auditor', candidates: '5 candidates approved', active: true },
    { id: 6, title: '1 Press Officer', candidates: '5 candidates approved', active: true },
    { id: 7, title: '1 Property Custodian', candidates: '5 candidates approved', active: true },
  ]);

  const toggleNationalSwitch = (id) => {
    setNationalsPositions(prev =>
      prev.map(pos => pos.id === id ? { ...pos, active: !pos.active } : pos)
    );
  };

  const toggleProvincialSwitch = (id) => {
    setProvincialPositions(prev =>
      prev.map(pos => pos.id === id ? { ...pos, active: !pos.active } : pos)
    );
  };

  return (
    <div className="dashboard-container">
      {/* Sidebar Navigation */}
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon"><Vote size={20} /></div>
          <div>
            <h1 className="brand-name">ElectBoard</h1>
            <p className="brand-sub">ELECTION CONSOLE</p>
          </div>
        </div>

        <nav className="nav-menu">
          <button 
            type="button" 
            className={`nav-item ${activeView === 'dashboard' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('dashboard')}
          >
            <LayoutDashboard size={18} /> Dashboard
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'candidates' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('candidates')}
          >
            <Users size={18} /> Candidates
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'voters' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('voters')}
          >
            <UserCheck size={18} /> Student Registry
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'setup' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('setup')}
          >
            <Sliders size={18} /> Election Setup
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'results' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('results')}
          >
            <BarChart2 size={18} /> Results
          </button>
          <button 
            type="button" 
            className={`nav-item ${activeView === 'settings' ? 'active' : ''}`}
            onClick={() => onNavigate && onNavigate('settings')}
          >
            <Settings size={18} /> Settings
          </button>
        </nav>

        <div className="sidebar-footer-container">
          <div className="sidebar-footer">
            <span className="status-dot-green"></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
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
              <span className="status-dot-green"></span> Voting Open
            </span>
            <div className="system-time">
              <Clock size={16} /> 14:32:05 EST
            </div>
            <div className="user-profile">
              <div className="user-info">
                <span className="user-name">Election Admin</span>
                <span className="user-role">System Administrator</span>
              </div>
              <img 
                src="https://i.pravatar.cc/100?img=32" 
                alt="Eleanor Vance" 
                className="user-avatar" 
              />
            </div>
          </div>
        </header>

        <div className="setup-body">
          {/* Action Header Row */}
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
              <button className="btn-reset">Reset</button>
              <button className="btn-save">Save Configuration</button>
              <button 
                className="btn-green-action" 
                onClick={() => setSubView(subView === 'main' ? 'general' : 'main')}
              >
                General Parameters / Set Election time
              </button>
            </div>
          </div>

          {/* VIEW 1: Main Ballot Positions View */}
          {subView === 'main' && (
            <div className="columns-grid">
              {/* Column 1: Ballot Positions Nationals */}
              <div className="column-card">
                <div className="column-header">
                  <h3>Ballot Positions Nationals</h3>
                  <span className="active-count-badge">5 Active</span>
                </div>

                <div className="position-list">
                  {nationalsPositions.map((pos) => (
                    <div className="position-item" key={pos.id}>
                      <div>
                        <div className="position-name">{pos.title}</div>
                        <div className="position-meta">{pos.candidates}</div>
                      </div>
                      <label className="toggle-switch">
                        <input 
                          type="checkbox" 
                          checked={pos.active} 
                          onChange={() => toggleNationalSwitch(pos.id)} 
                        />
                        <span className="slider round"></span>
                      </label>
                    </div>
                  ))}
                </div>

                <button className="btn-add-position">
                  <Plus size={16} /> Add Position
                </button>
              </div>

              {/* Column 2: Ballot Positions Provincial */}
              <div className="column-card">
                <div className="column-header">
                  <h3>Ballot Positions Provincial</h3>
                  <span className="active-count-badge">5 Active</span>
                </div>

                <div className="position-list">
                  {provincialPositions.map((pos) => (
                    <div className="position-item" key={pos.id}>
                      <div>
                        <div className="position-name">{pos.title}</div>
                        <div className="position-meta">{pos.candidates}</div>
                      </div>
                      <label className="toggle-switch">
                        <input 
                          type="checkbox" 
                          checked={pos.active} 
                          onChange={() => toggleProvincialSwitch(pos.id)} 
                        />
                        <span className="slider round"></span>
                      </label>
                    </div>
                  ))}
                </div>

                <button className="btn-add-position">
                  <Plus size={16} /> Add Position
                </button>
              </div>
            </div>
          )}

          {/* VIEW 2: General Parameters View */}
          {subView === 'general' && (
            <div className="general-parameters-card">
              <h3>General Parameters</h3>

              <div className="form-group">
                <label className="form-label">Election Title</label>
                <input 
                  type="text" 
                  className="form-input" 
                  value={electionTitle}
                  onChange={(e) => setElectionTitle(e.target.value)}
                />
              </div>

              <div className="form-row-2col">
                <div className="form-group">
                  <label className="form-label">Start Date & Time</label>
                  <div className="input-with-icon">
                    <Clock3 size={18} className="input-icon" />
                    <input 
                      type="text" 
                      className="form-input icon-padded" 
                      value={startDate}
                      onChange={(e) => setStartDate(e.target.value)}
                    />
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label">End Date & Time</label>
                  <div className="input-with-icon">
                    <Clock3 size={18} className="input-icon" />
                    <input 
                      type="text" 
                      className="form-input icon-padded" 
                      value={endDate}
                      onChange={(e) => setEndDate(e.target.value)}
                    />
                  </div>
                </div>
              </div>

              <div className="form-group">
                <label className="form-label">Current Election Phase</label>
                <div className="phase-segmented-control">
                  {['Draft', 'Registration', 'Active', 'Closed'].map((phase) => (
                    <button 
                      key={phase}
                      type="button"
                      className={`phase-btn ${currentPhase === phase ? 'active' : ''}`}
                      onClick={() => setCurrentPhase(phase)}
                    >
                      {phase}
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