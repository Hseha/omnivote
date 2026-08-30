import React, { useState, useEffect } from 'react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import { 
  LayoutDashboard, 
  Users, 
  UserCheck, 
  Sliders, 
  BarChart2, 
  Settings, 
  Vote, 
  Check, 
  X, 
  Search,
  Clock,
  LogOut
} from 'lucide-react';
import './Candidates.css';

export default function Candidates({ onLogout, activeView = 'candidates', onNavigate }) {
  const { logout } = useAuth();
  const [candidates, setCandidates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedPosition, setSelectedPosition] = useState('All Positions');
  const [statusFilter, setStatusFilter] = useState('All');

  useEffect(() => {
    fetchCandidates();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  const fetchCandidates = async () => {
    try {
      setLoading(true);
      const res = await api.get('/admin/candidates');
      const data = res.data?.data ?? res.data ?? [];
      setCandidates(data);
    } catch (err) {
      console.warn('API unavailable, falling back to mock candidates.', err.message);
      setCandidates([
        { id: 1, name: 'Marcus Sterling', position: 'President', party: 'Legacy & Progress Alliance', submissionDate: 'Jul 28, 2024', status: 'pending', avatar: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100' },
        { id: 2, name: 'Siddharth Mehta', position: 'Vice President', party: 'Legacy & Progress Alliance', submissionDate: 'Jul 29, 2024', status: 'pending', avatar: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100' },
        { id: 3, name: 'Amara Adebayo', position: 'Secretary', party: 'Independent / Grassroots', submissionDate: 'Jul 31, 2024', status: 'pending', avatar: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=100' },
        { id: 4, name: 'Marcus Sterling', position: 'President', party: 'Legacy & Progress Alliance', submissionDate: 'Jul 28, 2024', status: 'approved', avatar: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=100' },
        { id: 5, name: 'Siddharth Mehta', position: 'Vice President', party: 'Legacy & Progress Alliance', submissionDate: 'Jul 29, 2024', status: 'pending', avatar: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100' },
        { id: 6, name: 'Amara Adebayo', position: 'Secretary', party: 'Independent / Grassroots', submissionDate: 'Jul 31, 2024', status: 'pending', avatar: 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=100' },
        { id: 7, name: 'Marcus Sterling', position: 'President', party: 'Legacy & Progress Alliance', submissionDate: 'Jul 28, 2024', status: 'pending', avatar: 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=100' },
        { id: 8, name: 'Siddharth Mehta', position: 'Vice President', party: 'Legacy & Progress Alliance', submissionDate: 'Jul 29, 2024', status: 'rejected', avatar: 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=100' }
      ]);
    } finally {
      setLoading(false);
    }
  };

  const handleStatusChange = async (id, status) => {
    const normalized = (status || '').toLowerCase();
    try {
      await api.patch(`/admin/candidates/${id}`, { status: normalized });
      setCandidates((prev) =>
        prev.map((c) => (c.id === id ? { ...c, status: normalized } : c))
      );
    } catch (err) {
      console.warn('Failed to update candidate status:', err.message);
    }
  };

  const filteredCandidates = candidates.filter(c => {
    const matchesSearch = c.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          c.position.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesPosition = selectedPosition === 'All Positions' || c.position === selectedPosition;
    const matchesStatus = statusFilter === 'All' || c.status.toLowerCase() === statusFilter.toLowerCase();
    return matchesSearch && matchesPosition && matchesStatus;
  });

  return (
    <div className="dashboard-container">
      {/* Sidebar Navigation */}
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon"><Vote size={20} /></div>
          <div>
            <h1 className="brand-name">OmniVote</h1>
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
          <button onClick={handleLogout} className="logout-button">
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer">
            <span className="status-dot-green"></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      {/* Main Candidates View */}
      <main className="main-content">
        <header className="top-header">
          <div className="breadcrumb">
            <span className="muted">System / </span>
            <strong className="breadcrumb-candidates">Candidates</strong>
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
                alt="System Administrator" 
                className="user-avatar" 
              />
            </div>
          </div>
        </header>

        {/* Search and Quick Filters Row */}
        <div className="filter-toolbar">
          <div className="search-group">
            <div className="search-bar">
              <Search size={16} />
              <input 
                type="text" 
                placeholder="Search candidates..." 
                value={searchTerm} 
                onChange={(e) => setSearchTerm(e.target.value)} 
              />
            </div>
            <select 
              className="position-dropdown"
              value={selectedPosition}
              onChange={(e) => setSelectedPosition(e.target.value)}
            >
              <option value="All Positions">All Positions</option>
              <option value="President">President</option>
              <option value="Vice President">Vice President</option>
              <option value="Secretary">Secretary</option>
            </select>
          </div>

          <div className="status-filter-buttons">
            <button 
              className={`filter-btn filter-pending ${statusFilter === 'pending' ? 'active' : ''}`}
              onClick={() => setStatusFilter(statusFilter === 'pending' ? 'All' : 'pending')}
            >
              Pending
            </button>
            <button 
              className={`filter-btn filter-approved ${statusFilter === 'approved' ? 'active' : ''}`}
              onClick={() => setStatusFilter(statusFilter === 'approved' ? 'All' : 'approved')}
            >
              Approved
            </button>
            <button 
              className={`filter-btn filter-rejected ${statusFilter === 'rejected' ? 'active' : ''}`}
              onClick={() => setStatusFilter(statusFilter === 'rejected' ? 'All' : 'rejected')}
            >
              Rejected
            </button>
          </div>
        </div>

        <section className="card table-card">
          <div className="card-header-actions">
            <h3>Candidates List</h3>
          </div>

          <table className="actions-table">
            <thead>
              <tr>
                <th>Candidate Name</th>
                <th>Target Position</th>
                <th>Party / Platform Name</th>
                <th>Submission Date</th>
                <th>Status</th>
                <th className="text-right">Admin Action</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="6" className="no-data-cell">Loading candidates...</td></tr>
              ) : filteredCandidates.length === 0 ? (
                <tr><td colSpan="6" className="no-data-cell">No candidates found.</td></tr>
              ) : (
                filteredCandidates.map((candidate) => (
                  <tr key={candidate.id}>
                    <td>
                      <div className="candidate-profile-cell">
                        <img 
                          src={candidate.avatar || 'https://via.placeholder.com/36'} 
                          alt={candidate.name} 
                          className="candidate-avatar" 
                        />
                        <span className="font-semibold">{candidate.name}</span>
                      </div>
                    </td>
                    <td>{candidate.position}</td>
                    <td className="muted-text">{candidate.party}</td>
                    <td className="muted-text">{candidate.submissionDate || 'Jul 28, 2024'}</td>
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

          {/* Table Pagination */}
          <div className="pagination-container">
            <span className="pagination-info">Showing 1-8 of 24 applicants</span>
            <div className="pagination-buttons">
              <button className="page-btn">Previous</button>
              <button className="page-btn active">1</button>
              <button className="page-btn">2</button>
              <button className="page-btn">3</button>
              <button className="page-btn">Next</button>
            </div>
          </div>
        </section>
      </main>
    </div>
  );
}