import { useState, useRef, useEffect } from 'react';
import {
  ShieldCheck,
  UploadCloud,
  FileText,
  Info,
  AlertTriangle,
  Download,
} from 'lucide-react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import api from './lib/api';
import { allowedViews } from './lib/permissions';
import './StudentRegistry.css';

export default function StudentRegistry({ onLogout, activeView = 'voters', onNavigate, currentUser = null }) {
  const [selectedFile, setSelectedFile] = useState(null);
  const [previewRows, setPreviewRows] = useState([]);
  const [previewHeaderRecognized, setPreviewHeaderRecognized] = useState(false);
  const [isImporting, setIsImporting] = useState(false);
  const [importResult, setImportResult] = useState(null);
  const [error, setError] = useState('');
  const fileInputRef = useRef(null);

  // Live catalog for the downloadable CSV template's example row, so the
  // sample never bakes in a department or program name that a rename would
  // invalidate. When the catalog is unavailable the template falls back to a
  // header-only sheet.
  const [templateDepartments, setTemplateDepartments] = useState([]);
  const [templateCourses, setTemplateCourses] = useState([]);

  useEffect(() => {
    let active = true;
    api.get('/admin/users', { params: { per_page: 1 } })
      .then((res) => {
        if (!active) return;
        setTemplateDepartments(res.data?.departments ?? []);
        setTemplateCourses(res.data?.courses ?? []);
      })
      .catch(() => {
        // The catalog only feeds the template example; a failure just means
        // the exported template stays header-only.
      });
    return () => {
      active = false;
    };
  }, []);

  const parseCsvLine = (line) => {
    const cells = [];
    let cell = '';
    let quoted = false;

    for (let index = 0; index < line.length; index += 1) {
      const character = line[index];
      if (character === '"') {
        if (quoted && line[index + 1] === '"') {
          cell += '"';
          index += 1;
        } else {
          quoted = !quoted;
        }
      } else if (character === ',' && !quoted) {
        cells.push(cell.trim());
        cell = '';
      } else {
        cell += character;
      }
    }

    cells.push(cell.trim());
    return cells;
  };

  const parseCsvPreview = (text) => {
    const lines = text.split(/\r?\n/).filter((line) => line.trim().length > 0);
    if (lines.length < 1) return { rows: [], headerRecognized: false };

    const headers = parseCsvLine(lines[0]).map((header) => header
      .replace(/^\uFEFF/, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_|_$/g, ''));
    const findColumn = (...names) => headers.findIndex((header) => names.includes(header));
    const columns = {
      student_id: findColumn('student_id', 'studentid', 'id'),
      full_name: findColumn('student_name', 'full_name', 'fullname', 'name'),
      year_level: findColumn('year_level', 'yearlevel'),
      block_number: findColumn('block_number', 'blocknumber'),
      department: findColumn('department'),
      course: findColumn('course', 'program'),
    };

    // Column order is irrelevant — the importer matches headers by text.
    const headerRecognized = columns.student_id >= 0 && columns.full_name >= 0;
    if (lines.length < 2) return { rows: [], headerRecognized };

    const rows = lines.slice(1, 6)
      .map(parseCsvLine)
      .filter((row) => row.length >= 2)
      .map((row) => {
        const value = (column, fallback = '—') => (column >= 0 && row[column] ? row[column] : fallback);
        const fullName = value(columns.full_name, '');
        // Mirrors the backend username rule: name words lowercased and joined
        // with dots ('John Michael Valles' -> 'john.michael.valles').
        const loginEmail = fullName !== '—'
          ? fullName.toLowerCase().replace(/[^a-z0-9]+/g, '.').replace(/^\.|\.$/g, '')
          : '—';
        return {
          student_id: value(columns.student_id, ''),
          full_name: fullName,
          login_email: loginEmail,
          year_level: value(columns.year_level),
          block_number: value(columns.block_number),
          department: value(columns.department),
          course: value(columns.course, '—'),
          role: 'Student',
        };
      });

    return { rows, headerRecognized };
  };

  const handleFileChange = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setError('');
    setImportResult(null);
    setSelectedFile(file);

    const reader = new FileReader();
    reader.onload = () => {
      const { rows, headerRecognized } = parseCsvPreview(String(reader.result));
      setPreviewRows(rows);
      setPreviewHeaderRecognized(headerRecognized);
    };
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
    setPreviewHeaderRecognized(false);
    setImportResult(null);
    setError('');
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  // One-time handout after an import: a CSV of every account created by this
  // import (login email + randomly generated temporary password) plus the
  // existing accounts it touched (login email only — their password is never
  // reset here). The temporary password is a random CSPRNG value, never the
  // student ID.
  const escapeCell = (value = '') => {
    const text = String(value);
    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  };

  const downloadCsv = (filename, lines) => {
    const blob = new Blob([`\uFEFF${lines.join('\n')}\n`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  // Blank roster showing the exact format the importer expects: the five
  // required columns plus the optional Course. The header names line up with
  // the importer ("Full Name") and the credentials export, and an example row
  // built from the live catalog shows how a record should look.
  const downloadTemplate = () => {
    const header = ['Student ID', 'Full Name', 'Year Level', 'Department', 'Block Number', 'Course'];
    const lines = [header.join(',')];

    const exampleDepartment = templateDepartments[0];
    if (exampleDepartment) {
      const exampleCourse = (templateCourses.find((course) => course.college === exampleDepartment)?.name ?? '');
      const example = ['2024-0001', 'Juan Dela Cruz', '1', exampleDepartment, '1', exampleCourse];
      lines.push(example.map(escapeCell).join(','));
    }

    downloadCsv('omnivote-student-import-template.csv', lines);
  };

  const exportCredentials = () => {
    const newCredentials = importResult?.temporary_credentials ?? [];
    const existingCredentials = importResult?.existing_credentials ?? [];
    if (newCredentials.length === 0 && existingCredentials.length === 0) return;

    const header = ['Student ID', 'Full Name', 'Email Address (Login)', 'Status', 'Year Level', 'Block Number', 'Department', 'Course', 'Temporary Password'];
    const rows = [
      ...newCredentials.map((c) => [
        escapeCell(c.student_id),
        escapeCell(c.full_name),
        escapeCell(c.email),
        escapeCell('New'),
        escapeCell(c.year_level),
        escapeCell(c.block_number),
        escapeCell(c.department ?? ''),
        escapeCell(c.course ?? ''),
        escapeCell(c.temp_password),
      ]),
      // Existing accounts are listed so the registrar has one sheet for
      // everyone, but no password is generated or reset for them.
      ...existingCredentials.map((c) => [
        escapeCell(c.student_id),
        escapeCell(c.full_name),
        escapeCell(c.email),
        escapeCell('Existing'),
        '', '', '', '',
        '',
      ]),
    ];
    const lines = [header.join(','), ...rows.map((row) => row.join(','))];

    downloadCsv(`omnivote-login-credentials-${new Date().toISOString().slice(0, 10)}.csv`, lines);
  };

  // Registrar-issued activation / recovery codes are returned once, in
  // plaintext, only for the rows an import added. Export them so the registrar
  // can hand them out; the server keeps only a bcrypt hash.
  const exportActivationCodes = () => {
    const codes = importResult?.activation_codes ?? [];
    if (codes.length === 0) return;

    const header = ['Student ID', 'Full Name', 'Activation Code'];
    const lines = [
      header.join(','),
      ...codes.map((c) => [escapeCell(c.student_id), escapeCell(c.full_name), escapeCell(c.activation_code)].join(',')),
    ];

    downloadCsv(`omnivote-activation-codes-${new Date().toISOString().slice(0, 10)}.csv`, lines);
  };

  const summary = importResult?.summary;
  const tempCredentials = importResult?.temporary_credentials ?? [];
  const existingCredentials = importResult?.existing_credentials ?? [];
  const activationCodes = importResult?.activation_codes ?? [];

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={onLogout} currentUser={currentUser} />

      <main className="main-content">
        <Header breadcrumb="Import Registrar List" onLogout={onLogout} currentUser={currentUser} onNavigate={onNavigate} />

        <div className="registry-body">
          <div className="page-header">
            <div className="page-header-text">
              <h2>Import Registrar List</h2>
              <p className="page-subtext">
                Upload the official school registrar CSV file to validate and provision student and teacher accounts.
              </p>
            </div>
            <button type="button" className="btn-export-creds" onClick={downloadTemplate} title="Download a blank CSV showing the required format">
              <Download size={16} /> Export CSV Template
            </button>
          </div>

          {/* Cross-link into the nested User Access view (Student Registry group). */}
          {onNavigate && allowedViews(currentUser?.role ?? '').includes('user_management') && (
            <button
              type="button"
              className="registry-crosslink"
              onClick={() => onNavigate('user_management')}
            >
              <span className="registry-crosslink-icon"><ShieldCheck size={16} /></span>
              <span className="registry-crosslink-text">
                <strong>Manage user access</strong>
                <span>Review roles, enable or disable accounts, and reset passwords.</span>
              </span>
              <span className="registry-crosslink-arrow" aria-hidden="true">→</span>
            </button>
          )}

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
              <strong>Required CSV columns:</strong> Student ID, Full Name, Year Level, Department, Block Number
              <span className="optional-course-note"> · Optional: Course (stored as-is when it doesn&apos;t match an offered program)</span>
              <span className="optional-course-note">
                {' '}· Department accepts the college name or its code (e.g. &quot;CCS&quot;); Course accepts the program name or code
                (e.g. &quot;BSIT&quot;); Block Number accepts &quot;1&quot; or &quot;Block 1&quot;; Year Level is &quot;1&quot;–&quot;4&quot;. The login
                email is generated from the name, never read from the CSV.
              </span>
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
                    <div className="file-meta">
                      {previewRows.length > 0
                        ? `${previewRows.length} records found • Ready for preview`
                        : previewHeaderRecognized
                          ? 'Header recognized • add student rows to preview them'
                          : 'No valid records found in this file'}
                    </div>
                  </div>
                </div>
                <span className="badge-ready">Ready to import</span>
              </div>

              <div className="status-alert-bar">
                <Info size={18} color="#16a34a" />
                {previewRows.length > 0 ? (
                  <span><strong>Previewing the first {previewRows.length} rows below</strong></span>
                ) : previewHeaderRecognized ? (
                  <span><strong>This looks like the blank template.</strong> Add student rows under the header row to preview them.</span>
                ) : (
                  <span><strong>No valid rows to preview.</strong> Check that the header row includes the required columns (Student ID, Full Name, Year Level, Department, Block Number). Column order doesn&apos;t matter.</span>
                )}
              </div>

              {previewRows.length > 0 ? (
                <table className="preview-table">
                  <thead>
                    <tr>
                      <th>Student ID</th>
                      <th>Full Name</th>
                      <th>Login (auto-generated)</th>
                      <th>Year Level</th>
                      <th>Block Number</th>
                      <th>Department</th>
                      <th>Course</th>
                    </tr>
                  </thead>
                  <tbody>
                    {previewRows.map((row, index) => (
                      <tr key={index}>
                        <td className="font-mono">{row.student_id}</td>
                        <td className="font-medium">{row.full_name}</td>
                        <td className="text-muted">{row.login_email}</td>
                        <td className="text-muted">{row.year_level}</td>
                        <td className="text-muted">{row.block_number}</td>
                        <td className="text-muted">{row.department}</td>
                        <td className="text-muted">{row.course}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <p className="registry-csv-empty">
                  {previewHeaderRecognized
                    ? 'The header row is recognized — add one student per line to import them.'
                    : 'Fix the file and pick it again — the header row must include the required columns (Student ID, Full Name, Year Level, Department, Block Number).'}
                </p>
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
                    {summary.updated_eligibility_rows} eligibility rows updated • {summary.accounts_updated ?? 0} existing accounts updated
                    {(summary.accounts_provisioned ?? 0) === 0 && (summary.accounts_updated ?? 0) > 0 && (
                      <> (these Student IDs already existed — no new accounts)</>
                    )}
                    {' '}• {summary.duplicates_within_file} duplicates within file •{' '}
                    {summary.skipped_incomplete} incomplete rows (missing values → feed only, no account) •{' '}
                    {summary.skipped_unknown_department} skipped (department not in the current list → feed only, no account)
                    {(summary.courses_not_offered ?? 0) > 0 && (
                      <>
                        {' '}•{' '}
                        <span className="text-amber-700">
                          {summary.courses_not_offered} with a course the college doesn&apos;t offer (kept as typed,
                          account still created)
                        </span>
                      </>
                    )}
                    {(summary.name_duplicates ?? 0) > 0 && (
                      <>
                        {' '}•{' '}
                        <span className="text-amber-700">
                          {summary.name_duplicates} row(s) share a full name with a different Student ID (accounts were
                          still created — verify they aren&apos;t duplicate people)
                        </span>
                      </>
                    )}
                    {(summary.same_first_name_distinct ?? 0) > 0 && (
                      <>
                        {' '}• {summary.same_first_name_distinct} with the same first name but a different surname (kept
                        as separate students)
                      </>
                    )}
                  </span>
                </div>
              )}
              {(tempCredentials.length > 0 || existingCredentials.length > 0) && (
                <>
                  {tempCredentials.length > 0 && (
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
                        {tempCredentials.slice(0, 5).map((c, i) => (
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
                  <p className="table-footer-info">
                    {tempCredentials.length > 0 && (
                      <>Showing the first {Math.min(5, tempCredentials.length)} of {tempCredentials.length} newly created
                        account(s) — download the CSV below for every account. </>
                    )}
                    {existingCredentials.length > 0 && (
                      <>{existingCredentials.length} existing account(s) are listed in the CSV by login email only; an
                        import never resets their password. Reset them deliberately from User Management if needed. </>
                    )}
                    Students sign in to the mobile app with their generated Email Address (their name, lowercased with
                    dots) and the randomly generated Temporary Password from the CSV.
                  </p>
                  <div className="credentials-export-row">
                    <button type="button" className="btn-export-creds" onClick={exportCredentials}>
                      <Download size={16} /> Export Login Credentials ({tempCredentials.length + existingCredentials.length})
                    </button>
                  </div>
                </>
              )}
              {activationCodes.length > 0 && (
                <>
                  <table className="preview-table">
                    <thead>
                      <tr>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th>Activation Code</th>
                      </tr>
                    </thead>
                    <tbody>
                      {activationCodes.slice(0, 5).map((c, i) => (
                        <tr key={i}>
                          <td className="font-mono">{c.student_id}</td>
                          <td className="font-medium">{c.full_name}</td>
                          <td className="font-mono">{c.activation_code}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  <p className="table-footer-info">
                    Showing the first {Math.min(5, activationCodes.length)} of {activationCodes.length} activation
                    code(s). These one-time account-recovery codes are shown only once — distribute them securely and
                    delete the file afterward.
                  </p>
                  <div className="credentials-export-row">
                    <button type="button" className="btn-export-creds" onClick={exportActivationCodes}>
                      <Download size={16} /> Export Activation Codes ({activationCodes.length})
                    </button>
                  </div>
                </>
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
