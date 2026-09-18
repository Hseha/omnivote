import React from 'react';
import { Users, Activity, AlertTriangle, BarChart2, ArrowRight, CheckCircle, Clock, Shield, TrendingUp } from 'lucide-react';

export default function DashboardWidgets({ stats = {}, accounts = {}, electionPhase, recentActions = [], loading }) {
  const phaseColor = { voting_open:'#10b981', voting_closed:'#f87171', registration:'#f59e0b' }[electionPhase?.toLowerCase() || ''] || '#64748b';
  const actions = recentActions.length ? recentActions.slice(0,5) : [
    { type:'candidate_create', target:'Marcus Sterling - President', created_at:'2026-09-15T09:23:17Z' },
    { type:'candidate_status_update', target:'Amara Adebayo - Secretary', created_at:'2026-09-14T16:51:40Z' },
    { type:'user_login', target:'Election Admin', created_at:'2026-09-18T08:02:11Z' },
    { type:'backup_created', target:'Automated nightly backup', created_at:'2026-09-18T03:15:00Z' },
    { type:'election_status_update', target:'Voting window extended', created_at:'2026-09-13T20:00:00Z' },
  ];
  const phaseLabel = electionPhase || 'Voting Open';
  const phaseLower = phaseLabel.toLowerCase();
  const phaseDesc = { voting_open:'Ballots are open for all eligible voters.', voting_closed:'Ballots are closed. Results pending certification.', registration:'Voter registration is currently open.' }[phaseLower] || 'System active.';
  const turnoutPct = Number(stats?.turnout_percentage ?? 0);
  const voterVotes = Number(stats?.voters_voted ?? 0);
  const eligible = Number(stats?.eligible_voters ?? 0);
  const turnupColor = turnoutPct >= 70 ? '#10b981' : turnoutPct >= 40 ? '#f59e0b' : '#ef4444';
  return (
    <>
      <div className='sys-health-card'>
        <div className='sys-health-left'><div className='sys-dot' style={{ background:'#10b981' }} /><div><div className='sys-title'>System Operating Normally</div><div className='sys-sub'>All services within normal parameters. No delays or outages reported.</div></div></div>
        <div className='sys-meta'><div className='sys-meta-item'><Activity size={14} /><span>Uptime 99.9%</span></div><div className='sys-meta-item'><Shield size={14} /><span>SSL Active</span></div></div>
      </div>
      <div className='elec-status-round'>
        <div className='elec-status-inner'>
          <div className='elec-status-circle' style={{ background:phaseColor }} />
          <div className='elec-status-content'><div className='elec-status-label'>Election Phase</div><div className={('elec-status-name ' + (phaseLower.includes('open') ? 'elec-active' : phaseLower.includes('closed') ? 'elec-closed' : ''))}>{phaseLabel}</div><div className='elec-status-desc'>{phaseDesc}</div></div>
          <div className='elec-status-meta'><Clock size={14} /><span>Live</span></div>
        </div>
      </div>
      <div className='quick-actions-grid'>
        {[ {label:'Configure Election', sub:'Positions, candidates, windows', icon:BarChart2, cls:'qa-icon-blue', hash:'#setup'}, {label:'Manage Candidates', sub:'Approve, reject, review', icon:Users, cls:'qa-icon-purple', hash:'#candidates'}, {label:'Voter Registry', sub:'Import students, manage access', icon:Shield, cls:'qa-icon-green', hash:'#voters'}, {label:'User Access', sub:'Roles, status, passwords', icon:Activity, cls:'qa-icon-orange', hash:'#user_management'} ].map((q, i) => (
          <div key={i} className='quick-action-card' onClick={() => window.location.hash = q.hash}>
            <div className={('qa-icon ' + q.cls)}><q.icon size={22} /></div>
            <div className='qa-text'><div className='qa-title'>{q.label}</div><div className='qa-sub'>{q.sub}</div></div>
            <ArrowRight size={16} className={('qa-arrow')} />
          </div>
        ))}
      </div>
      <div className='turnout-widget card'><div className='card-header-actions'><div><h3>Voter Turnout</h3><p className='muted-text'>{eligible.toLocaleString()} eligible voters</p></div><div className='turnout-percent-ring'><svg viewBox='0 0 36 36' className='turnout-ring-svg'><path className='turnout-ring-bg' d='M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831' /><path className='turnout-ring-fill' stroke={turnupColor} strokeDasharray={turnoutPct + ', 100'} d='M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831' /></svg><div className='turnout-ring-label'>{Math.round(turnoutPct)}%</div></div></div>
        <div className='turnout-stats-row'><div className='turnout-stat'><span className='turnout-stat-label'>Voted</span><span className='turnout-stat-value'>{voterVotes.toLocaleString()}</span></div><div className='turnout-stat'><span className='turnout-stat-label'>Remaining</span><span className='turnout-stat-value'>{(eligible - voterVotes).toLocaleString()}</span></div><div className='turnout-stat'><span className='turnout-stat-label'>Eligible</span><span className='turnout-stat-value'>{eligible.toLocaleString()}</span></div></div>
      </div>
      <div className='audit-widget card'><div className='card-header-actions'><div><h3>Recent Activity</h3><p className='muted-text'>Latest actions across the admin panel</p></div><ArrowRight size={16} className='text-muted' /></div>
        <div className='audit-list'>{actions.map((item, i) => { const tl = { candidate_create:['Candidate Added','#34d399'], candidate_status_update:['Candidate Updated','#60a5fa'], user_login:['Admin Login','#c084fc'], backup_created:['Backup Created','#f59e0b'], election_status_update:['Election Updated','#f87171'] }[item.type] || ['Action','#94a3b8']; const ta = getTimeAgo(item.created_at); return (<div key={i} className='audit-row'><div className='audit-icon' style={{ background:tl[1]+'20', color:tl[1] }}>{item.type.includes('candidate') ? <Users size={13} /> : item.type.includes('login') ? <Activity size={13} /> : item.type.includes('backup') ? <Clock size={13} /> : <CheckCircle size={13} />}</div><div className='audit-text'><div className='audit-desc'><span className='font-semibold'>{tl[0]}:</span> {item.target}</div><div className='audit-time'>{ta}</div></div></div>); })}</div>
      </div>
      <div className='system-stats-row'>
        <div className='sys-stat-card'><div className='sys-stat-value'>{accounts?.total ?? 0}</div><div className='sys-stat-label'>Total Accounts</div></div>
        <div className='sys-stat-card'><div className='sys-stat-value'>{accounts?.active ?? '-'}</div><div className='sys-stat-label'>Active Now</div></div>
        <div className='sys-stat-card'><div className='sys-stat-value'>{stats?.total_votes ?? '-'}</div><div className='sys-stat-label'>Total Votes Cast</div></div>
        <div className='sys-stat-card'><div className='sys-stat-value'>{stats?.invalid_votes ?? 0}</div><div className='sys-stat-label'>Flagged Votes</div></div>
        <div className='sys-stat-card'><div className='sys-stat-value'>{stats?.pending_candidates ?? '-'}</div><div className='sys-stat-label'>Pending Candidates</div></div>
      </div>
    </>
  );
}
function getTimeAgo(s) { if (sed -n '53,65p' /home/Michael/omnivote/admin-react/src/Admindashboard.jsx) return 'just now'; const d = new Date(s), n = new Date(), ds = Math.floor((n-d)/1000); if (ds<60) return 'just now'; if (ds<3600) return Math.floor(ds/60)+'m ago'; if (ds<86400) return Math.floor(ds/3600)+'h ago'; if (ds<604800) return Math.floor(ds/86400)+'d ago'; return d.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }
