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
  CheckCircle2, 
  TrendingUp,
  Users2,
  ArrowLeft,
  Search,
  Download,
  ChevronDown
} from 'lucide-react';
import './Results.css';

export default function Results({ activeView = 'results', onNavigate }) {
  // Navigation state within Results view: 'live' | 'all-candidates' | 'elected' | 'unsuccessful'
  const [subView, setSubView] = useState('live');
  
  // Search and Filter states for detailed table views
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedPosition, setSelectedPosition] = useState('All');
  const [selectedParty, setSelectedParty] = useState('All');

  // Summary Card Data
  const liveSummaryData = {
    presidential: [
      { name: 'Maria Santos', votes: 342, percentage: '39.6%', status: 'WINNER', color: 'green' },
      { name: 'James Chen', votes: 289, percentage: '33.5%', status: 'RUNNER UP', color: 'blue' },
      { name: 'David Kim', votes: 232, percentage: '26.9%', status: 'RUNNER UP', color: 'blue' },
    ],
    vicePresidential: [
      { name: 'Siddharth Mehta', votes: 410, percentage: '47.5%', status: 'WINNER', color: 'green' },
      { name: 'Chloe Dupont', votes: 315, percentage: '36.5%', status: 'RUNNER UP', color: 'blue' },
      { name: 'Nolan Kross', votes: 138, percentage: '16.0%', status: 'RUNNER UP', color: 'blue' },
    ],
    secretary: [
      { name: 'Amara Adebayo', votes: 480, percentage: '55.6%', status: 'WINNER', color: 'green' },
      { name: 'Yuki Tanaka', votes: 383, percentage: '44.4%', status: 'RUNNER UP', color: 'blue' },
    ],
    treasurer: [
      { name: 'Lucas Vance', votes: 520, percentage: '60.3%', status: 'WINNER', color: 'green' },
      { name: 'Elena Rostova', votes: 343, percentage: '39.7%', status: 'RUNNER UP', color: 'blue' },
    ]
  };

  // Detailed Candidate List Data
  const detailedCandidatesData = [
    { rank: '#1', initials: 'LV', name: 'Lucas Vance', position: 'Treasurer', party: 'Progressive', votes: 520, percentage: 60.3, status: 'WINNER' },
    { rank: '#2', initials: 'AA', name: 'Amara Adebayo', position: 'Secretary', party: 'Progressive', votes: 480, percentage: 55.6, status: 'WINNER' },
    { rank: '#3', initials: 'SM', name: 'Siddharth Mehta', position: 'Vice President', party: 'National', votes: 410, percentage: 47.5, status: 'WINNER' },
    { rank: '#4', initials: 'MS', name: 'Maria Santos', position: 'President', party: 'National', votes: 342, percentage: 39.6, status: 'WINNER' },
    { rank: '#5', initials: 'ET', name: 'Elena Rostova', position: 'Treasurer', party: 'Progressive', votes: 343, percentage: 39.7, status: 'Eliminated' },
    { rank: '#6', initials: 'YT', name: 'Yuki Tanaka', position: 'Secretary', party: 'Independent', votes: 383, percentage: 44.4, status: 'Eliminated' },
    { rank: '#7', initials: 'CD', name: 'Chloe Dupont', position: 'Vice President', party: 'Progressive', votes: 315, percentage: 36.5, status: 'Eliminated' },
    { rank: '#8', initials: 'JC', name: 'James Chen', position: 'President', party: 'Independent', votes: 289, percentage: 33.5, status: 'Eliminated' },
    { rank: '#9', initials: 'DK', name: 'David Kim', position: 'President', party: 'Reform', votes: 232, percentage: 26.9, status: 'Eliminated' },
  ];

  const getFilteredCandidates = () => {
    return detailedCandidatesData.filter(item => {
      if (subView === 'elected' && item.status !== 'WINNER') return false;
      if (subView === 'unsuccessful' && item.status !== 'Eliminated') return false;
      if (selectedPosition !== 'All' && item.position !== selectedPosition) return false;
      if (selectedParty !== 'All' && item.party !== selectedParty) return false;
      if (searchTerm && !item.name.toLowerCase().includes(searchTerm.toLowerCase())) return false;
      return true;
    });
  };

  const renderResultSection = (title, candidates) => (
    <div className="result-card">
      <h3 className="result-card-title">{title}</h3>
      <div className="candidate-list">
        {candidates.map((candidate, idx) => (
          <div key={idx} className="candidate-item">
            <div className="candidate-row-header">
              <div className="candidate-info">
                <span className="candidate-name">{candidate.name}</span>
                <span className={`badge-tag ${candidate.status === 'WINNER' ? 'badge-winner' : 'badge-runner'}`}>
                  {candidate.status}
                </span>
              </div>
              <span className="vote-count">{candidate.votes} votes ({candidate.percentage})</span>
            </div>
            <div className="progress-bar-bg">
              <div 
                className={`progress-bar-fill fill-${candidate.color}`} 
                style={{ width: candidate.percentage }}
              />
            </div>
          </div>
        ))}
      </div>
    </div>
  );

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
            {subView === 'live' ? (
              <strong className="breadcrumb-title">Election Results — Live</strong>
            ) : (
              <>
                <span className="muted">Election Results / </span>
                <strong className="breadcrumb-title">All Candidates Results</strong>
              </>
            )}
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

        <div className="results-body">
          {/* VIEW 1: LIVE ELECTION RESULTS OVERVIEW */}
          {subView === 'live' && (
            <>
              {/* Metric Cards Row */}
              <div className="metrics-grid">
                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">TOTAL BALLOTS PROCESSES</span>
                    <div className="metric-icon-box green-icon-box">
                      <CheckCircle2 size={18} color="#16a34a" />
                    </div>
                  </div>
                  <div className="metric-value">863</div>
                  <div className="metric-subtitle">Last updated: 14:32:05 EST</div>
                </div>

                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">ESTIMATED TURNOUT</span>
                    <div className="metric-icon-box orange-icon-box">
                      <TrendingUp size={18} color="#d97706" />
                    </div>
                  </div>
                  <div className="metric-value">69.2%</div>
                  <div className="metric-subtitle">+124 received in the last hour</div>
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
              </div>

              {/* Position Category Cards Grid */}
              <div className="results-grid">
                {renderResultSection('Presidential Election Results', liveSummaryData.presidential)}
                {renderResultSection('Vice Presidential Election Results', liveSummaryData.vicePresidential)}
                {renderResultSection('Secretary Election Results', liveSummaryData.secretary)}
                {renderResultSection('Treasurer Election Results', liveSummaryData.treasurer)}
              </div>
            </>
          )}

          {/* VIEW 2: DETAILED CANDIDATE TABLE */}
          {subView !== 'live' && (
            <div className="detailed-results-container">
              <div className="detail-view-header">
                <button className="back-circle-btn" onClick={() => setSubView('live')}>
                  <ArrowLeft size={16} />
                </button>
                <h2 className="detail-view-title">
                  {subView === 'elected' ? 'All Elected Results' : subView === 'unsuccessful' ? 'All Unsuccessful' : 'All Candidates Results'}
                </h2>
                <div className="phase-text-badge">
                  Current Phase: <span className="green-phase">General Elections</span>
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
                  <div className="metric-value">24</div>
                  <div className="metric-subtitle">Registered across 4 races</div>
                </div>

                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">TOTAL VOTES CAST</span>
                    <div className="metric-icon-box green-icon-box">
                      <CheckCircle2 size={18} color="#16a34a" />
                    </div>
                  </div>
                  <div className="metric-value">2,847</div>
                  <div className="metric-subtitle">Updated 1 min ago</div>
                </div>

                <div className="metric-card">
                  <div className="metric-header">
                    <span className="metric-title">ESTIMATED TURNOUT</span>
                    <div className="metric-icon-box orange-icon-box">
                      <TrendingUp size={18} color="#d97706" />
                    </div>
                  </div>
                  <div className="metric-value">69.2%</div>
                  <div className="metric-subtitle">+124 received in the last hour</div>
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
                      <option value="Progressive">Progressive</option>
                      <option value="National">National</option>
                      <option value="Independent">Independent</option>
                      <option value="Reform">Reform</option>
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
                    {getFilteredCandidates().map((row, index) => (
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
                          <span className={`status-pill ${row.status === 'WINNER' ? 'pill-winner' : 'pill-eliminated'}`}>
                            {row.status}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                <div className="table-footer">
                  <span className="footer-pagination-info">Showing 9 of 30 active candidates</span>
                  <div className="pagination-buttons">
                    <button className="btn-page">Previous</button>
                    <button className="btn-page active">1</button>
                    <button className="btn-page">2</button>
                    <button className="btn-page">Next</button>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}