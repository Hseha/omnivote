import { useState } from 'react';
import { Settings as SettingsIcon, Lock, Bell, Shield, Database, Palette, Clock, Trash2, Save, Eye, EyeOff, Check, RefreshCw, Users, Mail, Tablet, Monitor, KeyRound, AlertTriangle, CheckCircle, ToggleLeft, ToggleRight } from 'lucide-react';
import api from './lib/api';
import { useAuth } from './lib/AuthContext';
import './Settings.css';

export default function Settings({ onLogout, activeView = 'settings', onNavigate }) {
  const { user, logout } = useAuth();
  const [activeTab, setActiveTab] = useState('security');
  const [form, setForm] = useState({
    security: { sessionTimeout: 30, maxLoginAttempts: 5, twoFactorRequired: false, passwordMinLength: 12, passwordRequireSpecial: true, passwordRequireNumber: true, passwordRequireUpper: true },
    voting: { registrationStart: '2026-09-01T00:00:00', registrationEnd: '2026-09-15T23:59:59', votingStart: '2026-09-18T08:00:00', votingEnd: '2026-09-22T20:00:00', maxVotesPerVoter: 1, allowVoteChange: false, voteConfirmationRequired: true, showResultsAfterClose: true },
    branding: { siteName: 'OmniVote', logoUrl: '', primaryColor: '#2563eb', secondaryColor: '#64748b', faviconUrl: '', headerText: 'Secure Election Platform', footerText: 'Powered by OmniVote Administration Console' },
    backup: { autoBackup: true, backupFrequency: 'daily', backupRetention: 30, remoteStorage: false, backupEncryption: true },
    notifications: { emailEnabled: true, smsEnabled: false, pushEnabled: true, emailOnVote: false, emailOnResult: true, emailOnAdminAction: true, smsOnCritical: true, dailyDigest: false, notifyOnRegistration: true }
  });
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState('');
  const [error, setError] = useState('');

  const handleLogout = () => { if (typeof onLogout === 'function') return onLogout(); logout(); };

  const update = (section, field, value) => {
    setForm(prev => ({ ...prev, [section]: { ...prev[section], [field]: value } }));
  };

  const handleSave = async (section) => {
    setSaving(true); setError(''); setSaved('');
    try {
      await api.patch(`/admin/settings/${section}`, form[section]);
      setSaved(`${section.charAt(0).toUpperCase() + section.slice(1)} settings saved.`);
      setTimeout(() => setSaved(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || `Failed to save ${section} settings.`);
    } finally {
      setSaving(false);
    }
  };

  const T = ({ on, setOn, onChange }) => (
    <button type='button' className={`toggle-switch ${on ? 'on' : ''}`} onClick={() => { const v = !on; setOn(v); onChange(v); }} aria-pressed={on}>
      {on ? <ToggleRight size={16} className='toggle-knob-on' /> : <ToggleLeft size={16} className='toggle-knob-off' />}
    </button>
  );

  const tabs = [
    { id: 'security', label: 'Security', icon: Shield },
    { id: 'voting', label: 'Voting Windows', icon: Clock },
    { id: 'branding', label: 'Branding', icon: Palette },
    { id: 'backup', label: 'Backup & Restore', icon: Database },
    { id: 'notifications', label: 'Notifications', icon: Bell },
  ];

  return (
    <div className='settings-app'>
      <aside className='sidebar'>
        <div className='logo-area'>
          <div className='logo-icon-bg'><SettingsIcon size={22} /></div>
          <div><h1 className='brand-name'>OmniVote</h1><p className='brand-sub'>SETTINGS</p></div>
        </div>
        <nav className='nav-menu'>
          {tabs.map(t => (
            <button key={t.id} className={`nav-item ${activeTab === t.id ? 'active' : ''}`} onClick={() => setActiveTab(t.id)}>
              <t.icon size={18} /> {t.label}
            </button>
          ))}
        </nav>
        <div className='sidebar-footer-container'>
          <button type='button' onClick={handleLogout} className='logout-button'><Trash2 size={18} /> Logout</button>
          <div className='sidebar-footer'><span className='status-dot-green' /> {user?.name || 'Admin'}</div>
        </div>
      </aside>

      <main className='settings-main'>
        <header className='settings-header'>
          <div>
            <div className='breadcrumb'><span className='muted'>Administration / </span><strong>Settings</strong></div>
            <h2 className='settings-title'>Configuration</h2>
            <p className='settings-subtitle'>Manage platform security, voting windows, branding, backups, and notifications.</p>
          </div>
          <div className='settings-profile'>
            <span className='voting-status-badge'><span className='status-dot-green' /> Configured</span>
          </div>
        </header>

        <div className='settings-content'>
          {saved && <div className='settings-banner settings-banner-success'><CheckCircle size={16} /> {saved}</div>}
          {error && <div className='settings-banner settings-banner-error'><AlertTriangle size={16} /> {error}</div>}

          {activeTab === 'security' && (
            <div className='settings-section'>
              <div className='section-header'><div><h3>Security Policies</h3><p className='muted-text'>Session, authentication, and access controls</p></div><Lock size={20} className='section-icon' /></div>
              <div className='settings-grid'>
                <div className='setting-card'><div className='setting-label'>Session Timeout (minutes)</div><p className='setting-desc'>Automatically log out inactive admin sessions</p></div><input type='number' className='setting-input' min={5} max={480} value={form.security.sessionTimeout} onChange={e => update('security', 'sessionTimeout', Number(e.target.value))} /><div className='setting-hint'>30 minutes recommended for shared devices</div></div>
                <div className='setting-card'><div className='setting-label'>Max Login Attempts</div><p className='setting-desc'>Lock account after consecutive failed attempts</p></div><input type='number' className='setting-input' min={1} max={20} value={form.security.maxLoginAttempts} onChange={e => update('security', 'maxLoginAttempts', Number(e.target.value))} /><div className='setting-hint'>5 attempts balances security and usability</div></div>
                <div className='setting-card'><div className='setting-label'>Minimum Password Length</div><p className='setting-desc'>Enforced for all user accounts</p></div><input type='number' className='setting-input' min={6} max={64} value={form.security.passwordMinLength} onChange={e => update('security', 'passwordMinLength', Number(e.target.value))} /><div className='setting-hint'>NIST recommends at least 8 characters</div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Password Complexity Requirements</div><p className='setting-desc'>Rules applied when users set or reset passwords</p></div>
                <div className='setting-card setting-card-full'>
                  <label className='checkbox-row'><input type='checkbox' checked={form.security.passwordRequireSpecial} onChange={e => update('security', 'passwordRequireSpecial', e.target.checked)} /><span>Require special characters (!@#\$%^&*...)</span></label>
                </div>
                <div className='setting-card setting-card-full'>
                  <label className='checkbox-row'><input type='checkbox' checked={form.security.passwordRequireNumber} onChange={e => update('security', 'passwordRequireNumber', e.target.checked)} /><span>Require at least one number</span></label>
                </div>
                <div className='setting-card setting-card-full'>
                  <label className='checkbox-row'><input type='checkbox' checked={form.security.passwordRequireUpper} onChange={e => update('security', 'passwordRequireUpper', e.target.checked)} /><span>Require at least one uppercase letter</span></label>
                </div>
                <div className='setting-card setting-card-full' style={{ borderTop: '1px solid rgba(51,65,85,0.4)', paddingTop: 16, marginTop: 12 }}>
                  <div className='setting-label' style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <span>Two-Factor Authentication (2FA) Required</span>
                    <T toggle={form.security.twoFactorRequired} setToggle={v => update('security', 'twoFactorRequired', v)} />
                  </div>
                  <p className='setting-desc' style={{ marginTop: 4 }}>Enforce 2FA for all admin and staff accounts</p>
                </div>
              </div>
              <button className='btn-save' onClick={() => handleSave('security')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Security Settings'}</button>
            </div>
          )}

          {activeTab === 'voting' && (
            <div className='settings-section'>
              <div className='section-header'><div><h3>Voting Windows</h3><p className='muted-text'>Schedule registration, voting, and results visibility</p></div><Clock size={20} className='section-icon' /></div>
              <div className='settings-grid'>
                <div className='setting-card'><div className='setting-label'>Registration Opens</div><p className='setting-desc'>When students can begin registering</p></div><input type='datetime-local' className='setting-input' value={form.voting.registrationStart.slice(0, 16)} onChange={e => update('voting', 'registrationStart', e.target.value + ':00')} /></div>
                <div className='setting-card'><div className='setting-label'>Registration Closes</div><p className='setting-desc'>Deadline for voter registration</p></div><input type='datetime-local' className='setting-input' value={form.voting.registrationEnd.slice(0, 16)} onChange={e => update('voting', 'registrationEnd', e.target.value + ':00')} /></div>
                <div className='setting-card'><div className='setting-label'>Voting Opens</div><p className='setting-desc'>When the ballot becomes available</p></div><input type='datetime-local' className='setting-input' value={form.voting.votingStart.slice(0, 16)} onChange={e => update('voting', 'votingStart', e.target.value + ':00')} /></div>
                <div className='setting-card'><div className='setting-label'>Voting Closes</div><p className='setting-desc'>Ballots are no longer accepted after this time</p></div><input type='datetime-local' className='setting-input' value={form.voting.votingEnd.slice(0, 16)} onChange={e => update('voting', 'votingEnd', e.target.value + ':00')} /></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Maximum Votes Per Voter</div><p className='setting-desc'>Number of positions a voter can cast in one session</p></div><input type='number' className='setting-input' min={1} max={20} value={form.voting.maxVotesPerVoter} onChange={e => update('voting', 'maxVotesPerVoter', Number(e.target.value))} /></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Allow Vote Change</div><p className='setting-desc'>Voters may modify their ballot before the voting window closes</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.voting.allowVoteChange} setToggle={v => update('voting', 'allowVoteChange', v)} /><span className={form.voting.allowVoteChange ? 'toggle-label-on' : 'toggle-label-off'}>{form.voting.allowVoteChange ? 'Enabled — voters may update selections' : 'Disabled — ballot is final on submission'}</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Vote Confirmation Required</div><p className='setting-desc'>Show a review screen before finalizing the ballot</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.voting.voteConfirmationRequired} setToggle={v => update('voting', 'voteConfirmationRequired', v)} /><span className={form.voting.voteConfirmationRequired ? 'toggle-label-on' : 'toggle-label-off'}>{form.voting.voteConfirmationRequired ? 'Enabled' : 'Disabled'}</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Show Results After Close</div><p className='setting-desc'>Automatically reveal results when voting ends</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.voting.showResultsAfterClose} setToggle={v => update('voting', 'showResultsAfterClose', v)} /><span className={form.voting.showResultsAfterClose ? 'toggle-label-on' : 'toggle-label-off'}>{form.voting.showResultsAfterClose ? 'Enabled' : 'Disabled'}</span></div></div>
              </div>
              <button className='btn-save' onClick={() => handleSave('voting')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Voting Window Settings'}</button>
            </div>
          )}

          {activeTab === 'branding' && (
            <div className='settings-section'>
              <div className='section-header'><div><h3>Platform Branding</h3><p className='muted-text'>Customize the look and feel of the election portal</p></div><Palette size={20} className='section-icon' /></div>
              <div className='settings-grid'>
                <div className='setting-card'><div className='setting-label'>Site Name</div><p className='setting-desc'>Displayed in headers and emails</p></div><input type='text' className='setting-input' value={form.branding.siteName} onChange={e => update('branding', 'siteName', e.target.value)} /></div>
                <div className='setting-card'><div className='setting-label'>Header Text</div><p className='setting-desc'>Tagline shown on the voting portal</p></div><input type='text' className='setting-input' value={form.branding.headerText} onChange={e => update('branding', 'headerText', e.target.value)} /></div>
                <div className='setting-card'><div className='setting-label'>Footer Text</div><p className='setting-desc'>Displayed at the bottom of all pages</p></div><input type='text' className='setting-input' value={form.branding.footerText} onChange={e => update('branding', 'footerText', e.target.value)} /></div>
                <div className='setting-card'><div className='setting-label'>Primary Color</div><p className='setting-desc'>Main accent color used across the interface</p></div><div className='color-picker-row'><input type='color' className='color-picker' value={form.branding.primaryColor} onChange={e => update('branding', 'primaryColor', e.target.value)} /><input type='text' className='setting-input color-hex-input' value={form.branding.primaryColor} onChange={e => update('branding', 'primaryColor', e.target.value)} pattern='^#[0-9a-fA-F]{6}$' /></div></div>
                <div className='setting-card'><div className='setting-label'>Secondary Color</div><p className='setting-desc'>Supporting accent and text colors</p></div><div className='color-picker-row'><input type='color' className='color-picker' value={form.branding.secondaryColor} onChange={e => update('branding', 'secondaryColor', e.target.value)} /><input type='text' className='setting-input color-hex-input' value={form.branding.secondaryColor} onChange={e => update('branding', 'secondaryColor', e.target.value)} pattern='^#[0-9a-fA-F]{6}$' /></div></div>
                <div className='setting-card'><div className='setting-label'>Logo URL</div><p className='setting-desc'>Public URL to the organization logo (PNG or SVG)</p></div><input type='url' className='setting-input' value={form.branding.logoUrl} onChange={e => update('branding', 'logoUrl', e.target.value)} placeholder='https://example.edu/logo.png' /></div>
                <div className='setting-card'><div className='setting-label'>Favicon URL</div><p className='setting-desc'>Small icon shown in browser tabs</p></div><input type='url' className='setting-input' value={form.branding.faviconUrl} onChange={e => update('branding', 'faviconUrl', e.target.value)} placeholder='https://example.edu/favicon.ico' /></div>
              </div>
              <button className='btn-save' onClick={() => handleSave('branding')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Branding Settings'}</button>
            </div>
          )}

          {activeTab === 'backup' && (
            <div className='settings-section'>
              <div className='section-header'><div><h3>Backup & Restore</h3><p className='muted-text'>Automated backups protect your election data</p></div><Database size={20} className='section-icon' /></div>
              <div className='backup-status-banner'><Database size={18} /><div><strong>Last Backup:</strong> Sep 18, 2026 at 03:15 AM EST <span className='muted-text'>(2 hours ago)</span></div><div><strong>Next Scheduled:</strong> Sep 19, 2026 at 03:00 AM EST</div><div><strong>Backup Size:</strong> 4.7 MB encrypted</div></div>
              <div className='settings-grid'>
                <div className='setting-card'><div className='setting-label'>Auto Backup</div><p className='setting-desc'>Automatically create backups on schedule</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.backup.autoBackup} setToggle={v => update('backup', 'autoBackup', v)} /><span className={form.backup.autoBackup ? 'toggle-label-on' : 'toggle-label-off'}>{form.backup.autoBackup ? 'Enabled' : 'Disabled'}</span></div></div>
                <div className='setting-card'><div className='setting-label'>Backup Frequency</div><p className='setting-desc'>How often to create backups</p></div><select className='setting-select' value={form.backup.backupFrequency} onChange={e => update('backup', 'backupFrequency', e.target.value)}><option value='hourly'>Hourly</option><option value='daily'>Daily</option><option value='weekly'>Weekly</option></select></div>
                <div className='setting-card'><div className='setting-label'>Retention Period (days)</div><p className='setting-desc'>How many days of backups to keep</p></div><input type='number' className='setting-input' min={1} max={365} value={form.backup.backupRetention} onChange={e => update('backup', 'backupRetention', Number(e.target.value))} /></div>
                <div className='setting-card'><div className='setting-label'>Remote Storage</div><p className='setting-desc'>Upload encrypted backups to off-site storage</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.backup.remoteStorage} setToggle={v => update('backup', 'remoteStorage', v)} /><span className={form.backup.remoteStorage ? 'toggle-label-on' : 'toggle-label-off'}>{form.backup.remoteStorage ? 'Enabled' : 'Disabled'}</span></div></div>
                <div className='setting-card'><div className='setting-label'>Backup Encryption</div><p className='setting-desc'>Encrypt all backups with AES-256</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.backup.backupEncryption} setToggle={v => update('backup', 'backupEncryption', v)} /><span className={form.backup.backupEncryption ? 'toggle-label-on' : 'toggle-label-off'}>{form.backup.backupEncryption ? 'Enabled' : 'Disabled'}</span></div></div>
              </div>
              <div className='backup-actions'>
                <button className='btn-secondary' onClick={() => { setSaving(true); setTimeout(() => { setSaving(false); setSaved('Manual backup initiated.'); setTimeout(() => setSaved(''), 3000); }, 1500); }} disabled={saving}><RefreshCw size={16} /> Run Backup Now</button>
                <button className='btn-danger'><Trash2 size={16} /> Restore From Backup</button>
              </div>
              <button className='btn-save' onClick={() => handleSave('backup')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Backup Settings'}</button>
            </div>
          )}

          {activeTab === 'notifications' && (
            <div className='settings-section'>
              <div className='section-header'><div><h3>Notification Preferences</h3><p className='muted-text'>Configure alerts and email/SMS digests</p></div><Bell size={20} className='section-icon' /></div>
              <div className='settings-grid'>
                <div className='setting-card'><div className='setting-label'>Email Notifications</div><p className='setting-desc'>Send email alerts for platform events</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.emailEnabled} setToggle={v => update('notifications', 'emailEnabled', v)} /><span className={form.notifications.emailEnabled ? 'toggle-label-on' : 'toggle-label-off'}>{form.notifications.emailEnabled ? 'Enabled' : 'Disabled'}</span></div></div>
                <div className='setting-card'><div className='setting-label'>SMS Notifications</div><p className='setting-desc'>Send text messages for critical alerts</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.smsEnabled} setToggle={v => update('notifications', 'smsEnabled', v)} /><span className={form.notifications.smsEnabled ? 'toggle-label-on' : 'toggle-label-off'}>{form.notifications.smsEnabled ? 'Enabled' : 'Disabled'}</span></div></div>
                <div className='setting-card'><div className='setting-label'>Push Notifications</div><p className='setting-desc'>Browser push notifications for real-time updates</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.pushEnabled} setToggle={v => update('notifications', 'pushEnabled', v)} /><span className={form.notifications.pushEnabled ? 'toggle-label-on' : 'toggle-label-off'}>{form.notifications.pushEnabled ? 'Enabled' : 'Disabled'}</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Notify on New Registration</div><p className='setting-desc'>Alert admin when a student registers</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.notifyOnRegistration} setToggle={v => update('notifications', 'notifyOnRegistration', v)} /><span className={form.notifications.notifyOnRegistration ? 'toggle-label-on' : 'toggle-label-off'}>Enabled</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Email on Vote Cast</div><p className='setting-desc'>Send confirmation email after each vote</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.emailOnVote} setToggle={v => update('notifications', 'emailOnVote', v)} /><span className={form.notifications.emailOnVote ? 'toggle-label-on' : 'toggle-label-off'}>Disabled (not recommended for large elections)</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Email on Results Published</div><p className='setting-desc'>Notify admin when election results are released</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.emailOnResult} setToggle={v => update('notifications', 'emailOnResult', v)} /><span className={form.notifications.emailOnResult ? 'toggle-label-on' : 'toggle-label-off'}>Enabled</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Email on Admin Action</div><p className='setting-desc'>Notify on role changes, status updates, etc.</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.emailOnAdminAction} setToggle={v => update('notifications', 'emailOnAdminAction', v)} /><span className={form.notifications.emailOnAdminAction ? 'toggle-label-on' : 'toggle-label-off'}>Enabled</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>SMS on Critical Events</div><p className='setting-desc'>Immediate text for security incidents or system issues</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.smsOnCritical} setToggle={v => update('notifications', 'smsOnCritical', v)} /><span className={form.notifications.smsOnCritical ? 'toggle-label-on' : 'toggle-label-off'}>Enabled</span></div></div>
                <div className='setting-card setting-card-full'><div className='setting-label'>Daily Digest</div><p className='setting-desc'>Summary of election activity sent each morning</p></div><div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' }}><T toggle={form.notifications.dailyDigest} setToggle={v => update('notifications', 'dailyDigest', v)} /><span className={form.notifications.dailyDigest ? 'toggle-label-on' : 'toggle-label-off'}>Disabled</span></div></div>
              </div>
              <button className='btn-save' onClick={() => handleSave('notifications')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Notification Settings'}</button>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
