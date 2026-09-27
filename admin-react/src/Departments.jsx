import { useState, useEffect, useCallback } from 'react';
import Sidebar from './components/Sidebar';
import Header from './components/Header';
import { Plus, Pencil, CheckCircle, AlertTriangle, Building2, GraduationCap, Trash2 } from 'lucide-react';
import api from './lib/api';
import './Departments.css';

export default function Departments({ onLogout, activeView = 'departments', onNavigate, currentUser = null }) {
  const [depts, setDepts] = useState([]);
  const [selected, setSelected] = useState('');
  const [loadingDepts, setLoadingDepts] = useState(true);
  const [members, setMembers] = useState([]);
  const [membersTotal, setMembersTotal] = useState(0);
  const [membersPage, setMembersPage] = useState(1);
  const [membersLastPage, setMembersLastPage] = useState(1);
  const [membersLoading, setMembersLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [memberSearch, setMemberSearch] = useState('');
  const [okMsg, setOkMsg] = useState('');
  const [srvErr, setSrvErr] = useState('');

  // Manage modal (add + rename, each gated behind confirmation)
  const [showManage, setShowManage] = useState(false);
  const [deptNew, setDeptNew] = useState('');
  const [deptPendingAdd, setDeptPendingAdd] = useState('');
  const [deptRenameFrom, setDeptRenameFrom] = useState('');
  const [deptRenameTo, setDeptRenameTo] = useState('');
  const [deptPendingRename, setDeptPendingRename] = useState(null);
  const [deptBusy, setDeptBusy] = useState(false);
  const [deptError, setDeptError] = useState('');

  // Courses list (flat [{ name, college }]) + courses manage modal
  const [courses, setCourses] = useState([]);
  const [showManageCourses, setShowManageCourses] = useState(false);
  const [courseNew, setCourseNew] = useState('');
  const [coursePendingAdd, setCoursePendingAdd] = useState('');
  const [courseRenameFrom, setCourseRenameFrom] = useState('');
  const [courseRenameTo, setCourseRenameTo] = useState('');
  const [coursePendingRename, setCoursePendingRename] = useState(null);
  const [coursePendingDelete, setCoursePendingDelete] = useState(null);
  const [courseBusy, setCourseBusy] = useState(false);
  const [courseError, setCourseError] = useState('');

  const flash = (msg) => { setOkMsg(msg); setTimeout(() => setOkMsg(''), 4000); };

  const loadDepts = useCallback(async () => {
    setLoadingDepts(true); setSrvErr('');
    try {
      const res = await api.get('/admin/users', { params: { per_page: 1 } });
      const list = (res.data?.departments ?? []).sort((a, b) => a.localeCompare(b));
      setCourses(res.data?.courses ?? []);
      setDepts(list);
      if (list.length > 0) {
        setSelected(prev => (prev && list.includes(prev)) ? prev : list[0]);
      }
    } catch { setSrvErr('Failed to load departments.'); }
    finally { setLoadingDepts(false); }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadDepts();
  }, [loadDepts]);

  const loadMembers = useCallback(async () => {
    if (!selected) { setMembers([]); setMembersTotal(0); return; }
    setMembersLoading(true); setSrvErr('');
    try {
      const res = await api.get('/admin/users', { params: { department: selected, role: 'student', per_page: 100, page: 1 } });
      setMembers(res.data?.data ?? []);
      setMembersTotal(res.data?.meta?.total ?? 0);
      setMembersLastPage(res.data?.meta?.last_page ?? 1);
      setMembersPage(1);
    } catch { setSrvErr('Failed to load department members.'); }
    finally { setMembersLoading(false); }
  }, [selected]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadMembers();
  }, [loadMembers]);

  const loadMoreMembers = async () => {
    if (membersPage >= membersLastPage || loadingMore) return;
    setLoadingMore(true); setSrvErr('');
    try {
      const res = await api.get('/admin/users', { params: { department: selected, role: 'student', per_page: 100, page: membersPage + 1 } });
      setMembers(prev => [...prev, ...(res.data?.data ?? [])]);
      setMembersTotal(res.data?.meta?.total ?? membersTotal);
      setMembersLastPage(res.data?.meta?.last_page ?? membersLastPage);
      setMembersPage(res.data?.meta?.current_page ?? membersPage + 1);
    } catch { setSrvErr('Failed to load more members.'); }
    finally { setLoadingMore(false); }
  };

  const hasMoreMembers = membersPage < membersLastPage;
  const votedCount = members.filter(u => u.has_voted).length;

  const searchTerm = memberSearch.trim().toLowerCase();
  const filteredMembers = searchTerm
    ? members.filter(u =>
        [u.name, u.student_id, u.email, u.year_level, u.block_number]
          .filter(Boolean)
          .some(v => String(v).toLowerCase().includes(searchTerm)))
    : members;

  // Programs offered by the selected college, in catalog order.
  const collegeCourses = courses.filter(c => c.college === selected).map(c => c.name);

  // Manage modal actions
  const openManage = () => {
    setShowManage(true); setDeptNew(''); setDeptPendingAdd('');
    setDeptRenameFrom(''); setDeptRenameTo(''); setDeptPendingRename(null); setDeptError('');
  };
  const closeManage = () => { if (deptBusy) return; setShowManage(false); setDeptPendingRename(null); setDeptPendingAdd(''); };

  const confirmAdd = () => {
    const name = deptNew.trim();
    if (!name) { setDeptError('Enter a department name first.'); return; }
    setDeptError(''); setDeptPendingAdd(name);
  };
  const startRename = (name) => {
    setDeptError(''); setDeptPendingRename(null); setDeptPendingAdd('');
    setDeptRenameFrom(name); setDeptRenameTo(name);
  };
  const confirmRename = () => {
    const to = deptRenameTo.trim();
    if (!to) { setDeptError('The new department name cannot be empty.'); return; }
    setDeptError(''); setDeptPendingRename({ from: deptRenameFrom, to });
  };

  const saveDepartment = async (payload) => {
    setDeptBusy(true); setDeptError('');
    try {
      const res = await (payload.name
        ? api.post('/admin/users/departments', { name: payload.name })
        : api.patch('/admin/users/departments', { from: payload.from, to: payload.to }));
      const list = (res.data?.departments ?? depts).sort((a, b) => a.localeCompare(b));
      setDepts(list);
      if (payload.to && selected === payload.from) setSelected(payload.to);
      flash(res.data?.message ?? 'Department saved.');
      setDeptNew(''); setDeptPendingAdd(''); setDeptRenameFrom(''); setDeptRenameTo(''); setDeptPendingRename(null);
    } catch (e) { setDeptError(e.response?.data?.message || 'Failed to save department.'); }
    finally { setDeptBusy(false); }
  };

  // Courses manage modal actions
  const openManageCourses = () => {
    setShowManageCourses(true); setCourseNew(''); setCoursePendingAdd('');
    setCourseRenameFrom(''); setCourseRenameTo(''); setCoursePendingRename(null);
    setCoursePendingDelete(null); setCourseError('');
  };
  const closeManageCourses = () => { if (courseBusy) return; setShowManageCourses(false); setCoursePendingRename(null); setCoursePendingAdd(null); setCoursePendingDelete(null); };

  const confirmCourseAdd = () => {
    const name = courseNew.trim();
    if (!name) { setCourseError('Enter a course name first.'); return; }
    setCourseError(''); setCoursePendingAdd(name);
  };
  const startCourseRename = (name) => {
    setCourseError(''); setCoursePendingRename(null); setCoursePendingDelete(null); setCoursePendingAdd('');
    setCourseRenameFrom(name); setCourseRenameTo(name);
  };
  const confirmCourseRename = () => {
    const to = courseRenameTo.trim();
    if (!to) { setCourseError('The new course name cannot be empty.'); return; }
    setCourseError(''); setCoursePendingRename({ from: courseRenameFrom, to });
  };
  const startCourseDelete = (name) => {
    setCourseError(''); setCoursePendingDelete(name); setCoursePendingRename(null); setCoursePendingAdd('');
  };

  const saveCourse = async (payload) => {
    setCourseBusy(true); setCourseError('');
    try {
      const res = payload.delete
        ? await api.delete('/admin/users/courses', { params: { department: selected, name: payload.delete } })
        : payload.name
          ? await api.post('/admin/users/courses', { name: payload.name, department: selected })
          : await api.patch('/admin/users/courses', { department: selected, from: payload.from, to: payload.to });
      setCourses(res.data?.courses ?? courses);
      flash(res.data?.message ?? 'Course saved.');
      setCourseNew(''); setCoursePendingAdd(''); setCourseRenameFrom(''); setCourseRenameTo('');
      setCoursePendingRename(null); setCoursePendingDelete(null);
    } catch (e) { setCourseError(e.response?.data?.message || 'Failed to save course.'); }
    finally { setCourseBusy(false); }
  };

  return (
    <div className="dashboard-container">
      <Sidebar activeView={activeView} onNavigate={onNavigate} onLogout={onLogout} currentUser={currentUser} brandSub="DEPARTMENTS" statusText="Admin Panel" />
      <main className="main-content">
        <Header breadcrumb="Departments" onLogout={onLogout} currentUser={currentUser} onNavigate={onNavigate} />
        <div className="dept-body">
          <div className="dept-header">
            <div className="dept-header-text">
              <h2>Departments</h2>
              <p className="dept-subtext">Manage the departments shown across filters and account forms. Select a department to browse its enrolled students and their vote status.</p>
            </div>
            <button type="button" className="dept-btn-primary" onClick={openManage} title="Add or rename a department">
              <Building2 size={16} /> Manage Departments
            </button>
          </div>
          {okMsg && <div className="dept-banner dept-banner-success"><CheckCircle size={16} /> {okMsg}</div>}
          {srvErr && <div className="dept-banner dept-banner-error"><AlertTriangle size={16} /> {srvErr}</div>}

          {loadingDepts ? (
            <p className="dept-muted-block">Loading departments…</p>
          ) : depts.length === 0 ? (
            <p className="dept-muted-block">No departments yet. Add the first one via the Manage button above.</p>
          ) : (
            <div className="dept-chip-row">
              {depts.map(d => (
                <button key={d} type="button" className={`dept-chip ${selected === d ? 'dept-chip-active' : ''}`} onClick={() => setSelected(d)}>
                  <Building2 size={14} /> {d}
                </button>
              ))}
            </div>
          )}

          {selected && (
            <>
              <section className="dept-members-section">
              <div className="dept-members-header">
                <div className="dept-title-group">
                  <h3 className="dept-section-title">{selected} <span className="dept-count-pill">{membersTotal}</span></h3>
                  <span className="dept-staff-label">Enrolled Students</span>
                </div>
                {votedCount > 0 && (
                  <span className={`dept-voted-chip${hasMoreMembers ? ' dept-voted-chip-partial' : ''}`}>
                    {votedCount} of {membersTotal} voted{hasMoreMembers ? ' (so far)' : ''}
                  </span>
                )}
              </div>
              <div className="dept-search-wrap">
                <input
                  type="text"
                  className="dept-search-input"
                  placeholder="Search by name, ID, email, year or block…"
                  value={memberSearch}
                  onChange={e => setMemberSearch(e.target.value)}
                />
                {memberSearch && (
                  <button type="button" className="dept-search-clear" onClick={() => setMemberSearch('')} title="Clear search">×</button>
                )}
              </div>
              {membersLoading ? (
                <p className="dept-muted-block">Loading students…</p>
              ) : members.length === 0 ? (
                <p className="dept-muted-block">No students enrolled in {selected} yet.</p>
              ) : filteredMembers.length === 0 ? (
                <p className="dept-muted-block">No students match “{memberSearch}”.</p>
              ) : (
                <div className="dept-table-wrap">
                  <table className="dept-users-table">
                    <thead><tr><th>Name</th><th>Student ID</th><th>Email</th><th>Year / Block</th><th>Vote Status</th></tr></thead>
                    <tbody>
                      {filteredMembers.map(u => (
                        <tr key={u.id}>
                          <td>
                            <div className="dept-user-cell">
                              <span className="dept-avatar">{(u.name || '?')[0]?.toUpperCase()}</span>
                              <span className="dept-user-name">{u.name || '—'}</span>
                            </div>
                          </td>
                          <td className="dept-muted">{u.student_id || '—'}</td>
                          <td className="dept-muted">{u.email || '—'}</td>
                          <td className="dept-muted">{u.year_level || u.block_number ? `${u.year_level || ''}${u.year_level && u.block_number ? '/' : ''}${u.block_number ? 'Blk ' + u.block_number : ''}` : '—'}</td>
                          <td>
                            <span className={`dept-vote-badge ${u.has_voted ? 'dept-vote-voted' : 'dept-vote-not'}`}>
                              {u.has_voted ? 'Voted' : 'Not Voted'}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {hasMoreMembers && !searchTerm && (
                    <div className="dept-load-more-wrap">
                      <button type="button" className="dept-btn-ghost" onClick={loadMoreMembers} disabled={loadingMore}>
                        {loadingMore ? 'Loading more…' : `Load more students (${members.length} of ${membersTotal})`}
                      </button>
                    </div>
                  )}
                </div>
              )}
            </section>

            <section className="dept-courses-section">
              <div className="dept-members-header">
                <div className="dept-title-group">
                  <h3 className="dept-section-title"><GraduationCap size={16} /> Courses / Programs <span className="dept-count-pill">{collegeCourses.length}</span></h3>
                  <span className="dept-courses-hint">Programs offered under {selected}</span>
                </div>
                <button type="button" className="dept-btn-ghost" onClick={openManageCourses} title="Add, rename or remove courses">
                  <Plus size={14} /> Manage Courses
                </button>
              </div>
              {collegeCourses.length === 0 ? (
                <p className="dept-muted-block">No programs under {selected} yet. Add the first one via Manage Courses.</p>
              ) : (
                <div className="dept-course-chips">
                  {collegeCourses.map(c => (
                    <span key={c} className="dept-course-chip">{c}</span>
                  ))}
                </div>
              )}
            </section>
            </>
          )}
        </div>
      </main>

      {/* Manage Departments modal */}
      {showManage && (
        <div className="dept-modal-overlay" onClick={closeManage}>
          <div className="dept-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Manage Departments">
            {deptPendingRename ? (
              <>
                <h3 className="dept-modal-title"><Pencil size={18} /> Confirm rename</h3>
                <p className="dept-modal-text">
                  Rename <strong>{deptPendingRename.from}</strong> to <strong>{deptPendingRename.to}</strong>?
                  Every account and registrar record currently assigned to{' '}
                  <strong>{deptPendingRename.from}</strong> will be updated. This cannot be undone.
                </p>
                {deptError && <div className="dept-banner dept-banner-error"><AlertTriangle size={14} /> {deptError}</div>}
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={() => setDeptPendingRename(null)} disabled={deptBusy}>Back</button>
                  <button type="button" className="dept-btn-primary" onClick={() => saveDepartment({ from: deptPendingRename.from, to: deptPendingRename.to })} disabled={deptBusy}>
                    {deptBusy ? 'Renaming…' : 'Confirm rename'}
                  </button>
                </div>
              </>
            ) : deptPendingAdd ? (
              <>
                <h3 className="dept-modal-title"><Plus size={18} /> Confirm add</h3>
                <p className="dept-modal-text">
                  Add department <strong>{deptPendingAdd}</strong>? It will appear in department filters and account forms.
                </p>
                {deptError && <div className="dept-banner dept-banner-error"><AlertTriangle size={14} /> {deptError}</div>}
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={() => setDeptPendingAdd('')} disabled={deptBusy}>Back</button>
                  <button type="button" className="dept-btn-primary" onClick={() => saveDepartment({ name: deptPendingAdd })} disabled={deptBusy}>
                    {deptBusy ? 'Adding…' : 'Confirm add'}
                  </button>
                </div>
              </>
            ) : (
              <>
                <h3 className="dept-modal-title"><Building2 size={18} /> Manage Departments</h3>
                <label className="dept-field">
                  <span>Add a department</span>
                  <div className="dept-add-row">
                    <input
                      type="text"
                      className="dept-input"
                      placeholder="e.g. Engineering"
                      value={deptNew}
                      onChange={e => setDeptNew(e.target.value)}
                      onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmAdd(); } }}
                      maxLength={100}
                    />
                    <button type="button" className="dept-btn-primary" onClick={confirmAdd} disabled={deptBusy}><Plus size={14} /> Add</button>
                  </div>
                </label>
                <div className="dept-list">
                  {depts.map(d => (
                    <div key={d} className="dept-row">
                      {deptRenameFrom === d ? (
                        <>
                          <input
                            type="text"
                            className="dept-input"
                            value={deptRenameTo}
                            onChange={e => setDeptRenameTo(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmRename(); } }}
                            maxLength={100}
                            autoFocus
                          />
                          <div className="dept-row-actions">
                            <button type="button" className="dept-btn-primary" onClick={confirmRename} disabled={deptBusy}>Save</button>
                            <button type="button" className="dept-btn-ghost" onClick={() => { setDeptRenameFrom(''); setDeptRenameTo(''); }} disabled={deptBusy}>Cancel</button>
                          </div>
                        </>
                      ) : (
                        <>
                          <span className="dept-name">{d}</span>
                          <div className="dept-row-actions">
                            <button type="button" className="dept-btn-ghost" onClick={() => startRename(d)} title={`Rename ${d}`}><Pencil size={14} /> Rename</button>
                          </div>
                        </>
                      )}
                    </div>
                  ))}
                </div>
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={closeManage} disabled={deptBusy}>Done</button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Manage Courses modal */}
      {showManageCourses && (
        <div className="dept-modal-overlay" onClick={closeManageCourses}>
          <div className="dept-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Manage Courses">
            {coursePendingDelete ? (
              <>
                <h3 className="dept-modal-title"><Trash2 size={18} /> Confirm remove</h3>
                <p className="dept-modal-text">
                  Remove course <strong>{coursePendingDelete}</strong> from {selected}?
                  Existing students keep their recorded program; it only leaves the selectable list.
                </p>
                {courseError && <div className="dept-banner dept-banner-error"><AlertTriangle size={14} /> {courseError}</div>}
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={() => setCoursePendingDelete(null)} disabled={courseBusy}>Back</button>
                  <button type="button" className="dept-btn-primary" onClick={() => saveCourse({ delete: coursePendingDelete })} disabled={courseBusy}>
                    {courseBusy ? 'Removing…' : 'Confirm remove'}
                  </button>
                </div>
              </>
            ) : coursePendingRename ? (
              <>
                <h3 className="dept-modal-title"><Pencil size={18} /> Confirm rename</h3>
                <p className="dept-modal-text">
                  Rename course <strong>{coursePendingRename.from}</strong> to <strong>{coursePendingRename.to}</strong> under {selected}?
                </p>
                {courseError && <div className="dept-banner dept-banner-error"><AlertTriangle size={14} /> {courseError}</div>}
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={() => setCoursePendingRename(null)} disabled={courseBusy}>Back</button>
                  <button type="button" className="dept-btn-primary" onClick={() => saveCourse({ from: coursePendingRename.from, to: coursePendingRename.to })} disabled={courseBusy}>
                    {courseBusy ? 'Renaming…' : 'Confirm rename'}
                  </button>
                </div>
              </>
            ) : coursePendingAdd ? (
              <>
                <h3 className="dept-modal-title"><Plus size={18} /> Confirm add</h3>
                <p className="dept-modal-text">
                  Add course <strong>{coursePendingAdd}</strong> under {selected}? It will be selectable in account and registrar forms.
                </p>
                {courseError && <div className="dept-banner dept-banner-error"><AlertTriangle size={14} /> {courseError}</div>}
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={() => setCoursePendingAdd('')} disabled={courseBusy}>Back</button>
                  <button type="button" className="dept-btn-primary" onClick={() => saveCourse({ name: coursePendingAdd })} disabled={courseBusy}>
                    {courseBusy ? 'Adding…' : 'Confirm add'}
                  </button>
                </div>
              </>
            ) : (
              <>
                <h3 className="dept-modal-title"><GraduationCap size={18} /> Manage Courses — {selected}</h3>
                <label className="dept-field">
                  <span>Add a course</span>
                  <div className="dept-add-row">
                    <input
                      type="text"
                      className="dept-input"
                      placeholder="e.g. Bachelor of Science in Information Technology"
                      value={courseNew}
                      onChange={e => setCourseNew(e.target.value)}
                      onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmCourseAdd(); } }}
                      maxLength={100}
                    />
                    <button type="button" className="dept-btn-primary" onClick={confirmCourseAdd} disabled={courseBusy}><Plus size={14} /> Add</button>
                  </div>
                </label>
                <div className="dept-list">
                  {collegeCourses.map(c => (
                    <div key={c} className="dept-row">
                      {courseRenameFrom === c ? (
                        <>
                          <input
                            type="text"
                            className="dept-input"
                            value={courseRenameTo}
                            onChange={e => setCourseRenameTo(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmCourseRename(); } }}
                            maxLength={100}
                            autoFocus
                          />
                          <div className="dept-row-actions">
                            <button type="button" className="dept-btn-primary" onClick={confirmCourseRename} disabled={courseBusy}>Save</button>
                            <button type="button" className="dept-btn-ghost" onClick={() => { setCourseRenameFrom(''); setCourseRenameTo(''); }} disabled={courseBusy}>Cancel</button>
                          </div>
                        </>
                      ) : (
                        <>
                          <span className="dept-name">{c}</span>
                          <div className="dept-row-actions">
                            <button type="button" className="dept-btn-ghost" onClick={() => startCourseRename(c)} title={`Rename ${c}`}><Pencil size={14} /> Rename</button>
                            <button type="button" className="dept-btn-ghost dept-btn-danger" onClick={() => startCourseDelete(c)} title={`Remove ${c}`}><Trash2 size={14} /> Remove</button>
                          </div>
                        </>
                      )}
                    </div>
                  ))}
                </div>
                <div className="dept-modal-actions">
                  <button type="button" className="dept-btn-ghost" onClick={closeManageCourses} disabled={courseBusy}>Done</button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}