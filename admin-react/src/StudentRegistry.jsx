import React, { useState, useEffect, useRef } from 'react';
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
  LogOut,
} from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './StudentRegistry.css';

export default function StudentRegistry({ onLogout, activeView = 'voters', onNavigate }) {
  const { logout } = useAuth();
  const [phase, setPhase] = useState('Registration');
  const [selectedFile, setSelectedFile] = useState(null);
  const [previewRows, setPreviewRows] = useState([]);
  const [isImporting, setIsImporting] = useState(false);
  const [importResult, setImportResult] = useState(null);
  const [error, setError] = useState('');
  const fileInputRef = useRef(null);

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

  const parseCsvPreview = (text) => {
    const lines = text.split(/\r?\n/).filter((line) => line.trim().length > 0);
    const rows = lines
      .slice(1, 6)
      .map((line) => line.split(',').map((c) => c.trim()))
      .filter((row) => row.length >= 2);
    return rows.map((row) => ({
      id: row[0],
      name: row[1],
      email: row[2] || '—',
      yearLevel: row[3] || '—',
      role: row[4] || 'Student',
    }));
  };

  const handleFileChange = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setError('');
    setImportResult(null);
    setSelectedFile(file);

    const reader = new FileReader();
    reader.onload = () => setPreviewRows(parseCsvPreview(String(reader.result)));
    reader.readAsText(file);
  };

  const handleImport = async () => {
    if (!selectedFile) {
      setError('Choose a CSV file first.');
      return;
    }
    setIsImporting(true);
    setError('');
    setImportResult(null);
    try {
      const form = new FormData();
      form.append('file', selectedFile);
      const res = await api.post('/admin/registrar/import', form);
      setImportResult(res.data);
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Import failed. Check that the CSV has the required columns.');
    } finally {
      setIsImporting(false);
    }
  };

  const handleCancel = () => {
    setSelectedFile(null);
    setPreviewRows([]);
    setImportResult(null);
    setError('');
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const summary = importResult?.summary;

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
            <strong className="breadcrumb-title">Import Registrar List</strong>
          </div>
          <div className="header-right">
            <span className="voting-status-badge">
              <span className="status-dot-green"></span> {phase}
            </span>
            <div className="system-time">
              <Clock size={16} /> {new Date().toLocaleTimeString()}
            </div>
            <div className="user-profile">
              <div className="user-info">
                <span className="user-name">Election Admin</span>
                <span className="user-role">System Administrator</span>
              </div>
              <img src="https://i.pravatar.cc/100?img=32" alt="System Administrator" className="user-avatar" />
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

          {error && <div className="warning-banner"><AlertTriangle size={18} color="#dc2626" /><span>{error}</span></div>}

          {/* Drag & Drop Area */}
          <label className="dropzone-card" htmlFor="registrar-csv-input" style={{ cursor: 'pointer', display: 'block' }}>
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
            <input
              ref={fileInputRef}
              id="registrar-csv-input"
              type="file"
              accept=".csv,text/csv"
              style={{ display: 'none' }}
              onChange={handleFileChange}
            />
          </label>

          {/* Uploaded File Preview Card */}
          {selectedFile && !importResult && (
            <div className="file-preview-card">
              <div className="file-header">
                <div className="file-info-left">
                  <div className="file-icon"><FileText size={20} color="#16a34a" /></div>
                  <div>
                    <div className="file-name">{selectedFile.name}</div>
                    <div className="file-meta">{previewRows.length} records found • Ready for preview</div>
                  </div>
                </div>
                <span className="badge-ready">Ready to import</span>
              </div>

              <div className="status-alert-bar">
                <Info size={18} color="#16a34a" />
                <span><strong>Previewing the first {previewRows.length} rows below</strong></span>
              </div>

              {previewRows.length > 0 && (
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
                    {previewRows.map((row, index) => (
                      <tr key={index}>
                        <td className="font-mono">{row.id}</td>
                        <td className="font-medium">{row.name}</td>
                        <td className="text-muted">{row.email}</td>
                        <td className="text-muted">{row.yearLevel}</td>
                        <td><span className={`role-badge ${row.role.toLowerCase()}`}>{row.role}</span></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          )}

          {/* Import Result Summary */}
          {importResult && (
            <div className="file-preview-card">
              <div className="file-header">
                <div className="file-info-left">
                  <div className="file-icon"><CheckCircleIcon size={20} color="#16a34a" /></div>
                  <div>
                    <div className="file-name">Import completed</div>
                    <div className="file-meta">{importResult.message}</div>
                  </div>
                </div>
                <span className="badge-ready">Success</span>
              </div>
              {summary && (
                <div className="status-alert-bar">
                  <Info size={18} color="#16a34a" />
                  <span>
                    <strong>{summary.total_records} records</strong> • {summary.accounts_provisioned} new accounts provisioned •{' '}
                    {summary.updated_eligibility_rows} updated • {summary.duplicates_within_file} duplicates within file •{' '}
                    {summary.skipped_no_email} skipped (missing email)
                  </span>
                </div>
              )}
              {importResult.temporary_credentials?.length > 0 && (
                <table className="preview-table">
                  <thead>
                    <tr>
                      <th>Student ID</th>
                      <th>Full Name</th>
                      <th>Email</th>
                      <th>Temporary Password</th>
                    </tr>
                  </thead>
                  <tbody>
                    {importResult.temporary_credentials.slice(0, 5).map((c, i) => (
                      <tr key={i}>
                        <td className="font-mono">{c.student_id}</td>
                        <td className="font-medium">{c.full_name}</td>
                        <td className="text-muted">{c.email}</td>
                        <td className="font-mono">{c.temp_password}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          )}

          {/* Warning Banner */}
          <div className="warning-banner">
            <AlertTriangle size={18} color="#d97706" />
            <span>Importing will create new accounts for unmatched records and update existing ones. This action cannot be undone.</span>
          </div>

          {/* Action Buttons */}
          <div className="action-buttons-row">
            <button className="btn-cancel" onClick={handleCancel} disabled={isImporting}>
              Cancel
            </button>
            <button className="btn-primary" onClick={handleImport} disabled={isImporting || !selectedFile}>
              {isImporting ? 'Importing...' : 'Import & Provision Accounts'}
            </button>
          </div>
        </div>
      </main>
    </div>
  );
}

function CheckCircleIcon({ size = 18, color = 'currentColor' }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
      <polyline points="22 4 12 14.01 9 11.01" />
    </svg>
  );
}
