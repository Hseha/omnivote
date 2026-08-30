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
  UploadCloud,
  FileText,
  Info,
  AlertTriangle,
  LogOut
} from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './StudentRegistry.css';

export default function StudentRegistry({ onLogout, activeView = 'voters', onNavigate }) {
  const { logout } = useAuth();
  const [phase, setPhase] = useState('Voting Open');
  const [selectedFile, setSelectedFile] = useState('registrar_2024_fall.csv');

  const handleLogout = () => {
    if (typeof onLogout === 'function') return onLogout();
    logout();
  };

  useEffect(() => {
    const loadPhase = async () => {
      try {
        const res = await api.get('/election/status');
        const data = res.data?.data ?? res.data ?? {};
        if (data.phase) {
          setPhase(
            data.phase === 'registration'
              ? 'Registration'
              : data.phase === 'voting_open'
                ? 'Voting Open'
                : data.phase === 'voting_closed'
                  ? 'Voting Closed'
                  : data.phase,
          );
        }
      } catch {
        // keep default
      }
    };
    loadPhase();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const studentData = [
    { id: 'STU-2024-0001', name: 'Sarah Jenkins', email: 's.jenkins@academy.edu', yearLevel: 'BSIT-3', role: 'Student' },
    { id: 'STU-2024-0002', name: 'David Cho', email: 'd.cho@academy.edu', yearLevel: 'BSIT-3', role: 'Student' },
    { id: 'TCH-2024-0012', name: 'Dr. Evelyn Ross', email: 'e.ross@academy.edu', yearLevel: 'BSIT-3', role: 'Teacher' },
    { id: 'STU-2024-0003', name: 'Liam O\'Connor', email: 'l.oconnor@academy.edu', yearLevel: 'BSIT-3', role: 'Student' },
    { id: 'STU-2024-0004', name: 'Aisha Diallo', email: 'a.diallo@academy.edu', yearLevel: 'BSIT-3', role: 'Student' },
  ];

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
          <button onClick={handleLogout} className="logout-button">
            <LogOut size={18} /> Logout
          </button>
          <div className="sidebar-footer">
            <span className="status-dot-green"></span> System Live (v1.4)
          </div>
        </div>
      </aside>

      {/* Main Registry Content */}
      <main className="main-content">
        <header className="top-header">
          <div className="breadcrumb">
            <span className="muted">System / </span>
            <strong className="breadcrumb-title">Import Registrar List</strong>
          </div>
          <div className="header-right">
            <span className="voting-status-badge">
              <span className="status-dot-green"></span> {phase}
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

        <div className="registry-body">
          <div className="page-header">
            <h2>Import Registrar List</h2>
            <p className="page-subtext">
              Upload the official school registrar CSV file to validate and provision student and teacher accounts.
            </p>
          </div>

          {/* Drag & Drop Area */}
          <div className="dropzone-card">
            <div className="upload-icon-wrapper">
              <UploadCloud size={24} color="#3b82f6" />
            </div>
            <p className="dropzone-text">
              <strong>Drag & drop your CSV file here or</strong> <span className="browse-link">click to browse</span>
            </p>
            <span className="dropzone-sub">.csv files only, max 10MB</span>

            <div className="required-columns-pill">
              <strong>Required CSV columns:</strong> Student ID, Full Name, Email Address, Grade Level, Role (Student/Teacher)
            </div>
          </div>

          {/* Uploaded File Preview Card */}
          <div className="file-preview-card">
            <div className="file-header">
              <div className="file-info-left">
                <div className="file-icon"><FileText size={20} color="#16a34a" /></div>
                <div>
                  <div className="file-name">{selectedFile}</div>
                  <div className="file-meta">1,247 records found • File verified successfully</div>
                </div>
              </div>
              <span className="badge-ready">Ready to import</span>
            </div>

            <div className="status-alert-bar">
              <Info size={18} color="#16a34a" />
              <span><strong>1,247 valid records, 3 duplicates detected, 0 invalid entries</strong></span>
            </div>

            {/* Preview Table */}
            <table className="preview-table">
              <thead>
                <tr>
                  <th>Student ID</th>
                  <th>Full Name</th>
                  <th>Email Address</th>
                  <th>Year Level</th>
                  <th>Role</th>
                </tr>
              </thead>
              <tbody>
                {studentData.map((row, index) => (
                  <tr key={index}>
                    <td className="font-mono">{row.id}</td>
                    <td className="font-medium">{row.name}</td>
                    <td className="text-muted">{row.email}</td>
                    <td className="text-muted">{row.yearLevel}</td>
                    <td>
                      <span className={`role-badge ${row.role.toLowerCase()}`}>
                        {row.role}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="table-footer-info">
              Showing first 5 of 1,247 rows
            </div>
          </div>

          {/* Warning Banner */}
          <div className="warning-banner">
            <AlertTriangle size={18} color="#d97706" />
            <span>Importing will create new accounts for unmatched records and update existing ones. This action cannot be undone.</span>
          </div>

          {/* Action Buttons */}
          <div className="action-buttons-row">
            <button className="btn-cancel">Cancel</button>
            <button className="btn-primary">Import & Provision Accounts</button>
          </div>
        </div>
      </main>
    </div>
  );
}