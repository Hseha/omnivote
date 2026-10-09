import { useState, useEffect } from 'react';
import {
  Shield,
  Clock,
  Palette,
  Database,
  Bell,
  Lock,
  Moon,
  ArrowLeft,
  LogOut,
  Trash2,
  Save,
  RefreshCw,
  CheckCircle,
  AlertTriangle,
  Download,
  RotateCcw,
  CircleUserRound,
  Upload,
} from 'lucide-react';
import api from './lib/api';
import { fallbackAvatarOnError } from './lib/avatar';
import { writeLocalAvatar } from './lib/auth';
import { useAuth } from './lib/AuthContext';
import { useTheme } from './lib/ThemeContext';
import { refreshBranding } from './lib/branding';
import { useElectionStatus } from './lib/ElectionStatusContext';
import TwoFactorSetup from './TwoFactorSetup';
import NotificationBroadcast from './components/NotificationBroadcast';
import OmniVoteMark from './components/OmniVoteMark';
import './Settings.css';

const STORAGE_KEY = 'omnivote:admin:settings';

const DEFAULT_SETTINGS = {
  security: { sessionTimeout: 30, maxLoginAttempts: 5, twoFactorRequired: false, selfRegistrationEnabled: false, passwordMinLength: 12, passwordRequireSpecial: true, passwordRequireNumber: true, passwordRequireUpper: true },
  voting: { registrationStart: '2026-09-01T00:00:00', registrationEnd: '2026-09-15T23:59:59', votingStart: '2026-09-18T08:00:00', votingEnd: '2026-09-22T20:00:00', termEndsAt: '', maxVotesPerVoter: 1, allowVoteChange: false, voteConfirmationRequired: true, showResultsAfterClose: true },
  branding: { siteName: 'OmniVote', logoUrl: '', primaryColor: '#2563eb', secondaryColor: '#64748b', faviconUrl: '', headerText: 'Secure Election Platform', footerText: 'Powered by OmniVote Administration Console' },
  backup: { autoBackup: true, backupFrequency: 'daily', backupRetention: 30, remoteStorage: false, backupEncryption: true },
  notifications: { emailEnabled: true, smsEnabled: false, pushEnabled: true, emailOnVote: false, emailOnResult: true, emailOnAdminAction: true, smsOnCritical: true, dailyDigest: false, notifyOnRegistration: true },
};

/* Deep merge remote sections over the defaults, keeping unknown future keys. */
function mergeSettings(base, sections) {
  const merged = { ...base };
  Object.keys(sections).forEach((section) => {
    merged[section] = { ...(merged[section] || {}), ...(sections[section] || {}) };
  });
  return merged;
}

function loadCachedSettings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return null;
    return mergeSettings(DEFAULT_SETTINGS, parsed);
  } catch {
    return null;
  }
}

function persistSettings(settings) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
  } catch {
    /* storage full / private mode — settings still work in-memory */
  }
}

/* ------------------------------------------------------------------------
   School-year quick set
   Hand-typing the Term Ends date is the most error-prone thing on this screen:
   an off-by-one year files last term's winners under the wrong "SY ####-####"
   label forever, and a date left in the past silently archives every certified
   winner on the next page load. So instead of only a free-text clock, the Term
   Ends dialog offers the two school years an admin realistically needs.

   A Philippine school year runs June-May, which is exactly the derivation
   TermArchive::labelFor() uses on the backend: a term ending in the first half
   of the calendar year belongs to the SY that started the year before. Both
   sides must agree or the Past Terms grouping drifts from the SY chip shown
   here, so the arithmetic is kept deliberately identical.
   ------------------------------------------------------------------------ */

/* The "SY ####-####" label a term ending at `iso` will be filed under.
   Deliberately reads UTC fields, because that is what TermArchive::labelFor()
   does on the backend (app.timezone is UTC). Reading local fields instead
   drifts by a year for any admin whose offset pushes local May 31 23:59 past
   midnight UTC — a -4h zone turns the "End of SY 2026-2027" chip into
   2027-06-01T03:59Z, which Past Terms then groups as SY 2027-2028. */
function schoolYearOf(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  const start = d.getUTCMonth() + 1 <= 5 ? d.getUTCFullYear() - 1 : d.getUTCFullYear();
  return `SY ${start}-${start + 1}`;
}

/* ------------------------------------------------------------------------
   Guided Voting Windows editing
   Each of the four date/time windows is edited through a small dialog that
   asks a plain-language question, then shows a confirmation step ("Set X to
   …?") before anything is written to the backend — so a stray click can't
   accidentally move a poll deadline.
   ------------------------------------------------------------------------ */

const VOTING_WINDOW_FIELDS = {
  registrationStart: {
    label: 'Registration Opens',
    question: 'When should registration open?',
    desc: 'When students can begin registering',
  },
  registrationEnd: {
    label: 'Registration Closes',
    question: 'When should registration close?',
    desc: 'Deadline for voter registration',
  },
  votingStart: {
    label: 'Voting Opens',
    question: 'When should voting open?',
    desc: 'When the ballot becomes available',
  },
  votingEnd: {
    label: 'Voting Closes',
    question: 'When should voting close?',
    desc: 'Ballots are no longer accepted after this time',
  },
  termEndsAt: {
    label: 'Term Ends',
    question: 'When does the winners\' term end?',
    desc: 'After this time the term is archived for record-keeping',
  },
};

/*
 * Quick-shift presets shown inside each Edit window dialog. Instead of hand-
 * typing a new date, the admin pushes the current value later (e.g. "voting
 * starts in two days because students are at the candidates' meet-and-greet").
 */
const QUICK_SHIFT_HOURS = [
  { label: '+1 hour', hours: 1 },
  { label: '+12 hours', hours: 12 },
  { label: '+24 hours', hours: 24 },
  { label: '+48 hours', hours: 48 },
  { label: '+72 hours', hours: 72 },
];

function formatDateTime(iso) {
  if (!iso) return 'Not set';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  });
}

/* Date-only variant used by the profile timestamps. */
function formatCalendarDate(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

/* Profile initials rendered inside the avatar. */
function initials(name) {
  if (!name) return '?';
  return String(name)
    .trim()
    .split(/\s+/)
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase();
}

/* Human label for every account role the panel can hold. */
function roleLabel(role) {
  switch (role) {
    case 'admin':
      return 'System Administrator';
    case 'teacher':
      return 'SSG Adviser';
    case 'ssg_president':
      return 'SSG President';
    case 'candidate':
      return 'Candidate';
    case 'student':
      return 'Student';
    default:
      return role || 'Panel Account';
  }
}

/* ------------------------------------------------------------------------
   Avatar upload.
   Uploaded images are cropped square and exported as a PNG data URL so the
   value can be stored in one `avatar_url` string column.
   ------------------------------------------------------------------------ */

/* Reads a device image, cover-crops it square, and (optionally) pixelates it
 * by drawing at a tiny resolution then upscaling with nearest-neighbour
 * sampling. Returns a compact PNG data URL suitable for avatar_url. */
function fileToAvatarDataUrl(file, pixelate) {
  return new Promise((resolve, reject) => {
    const size = 160;
    const objectUrl = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const min = Math.min(img.width, img.height);
      const sx = (img.width - min) / 2;
      const sy = (img.height - min) / 2;

      const flat = document.createElement('canvas');
      flat.width = size;
      flat.height = size;
      const ctx = flat.getContext('2d');

      if (!pixelate) {
        ctx.drawImage(img, sx, sy, min, min, 0, 0, size, size);
        URL.revokeObjectURL(objectUrl);
        resolve(flat.toDataURL('image/png'));
        return;
      }

      // 1) downscale to a 16×16 cell, then 2) upscale nearest-neighbour.
      const cell = 16;
      const tiny = document.createElement('canvas');
      tiny.width = cell;
      tiny.height = cell;
      const tinyCtx = tiny.getContext('2d');
      tinyCtx.imageSmoothingEnabled = false;
      tinyCtx.drawImage(img, sx, sy, min, min, 0, 0, cell, cell);

      const up = new Image();
      up.onload = () => {
        const upCanvas = document.createElement('canvas');
        upCanvas.width = size;
        upCanvas.height = size;
        const upCtx = upCanvas.getContext('2d');
        upCtx.imageSmoothingEnabled = false;
        upCtx.drawImage(up, 0, 0, size, size);
        URL.revokeObjectURL(objectUrl);
        resolve(upCanvas.toDataURL('image/png'));
      };
      up.onerror = reject;
      up.src = tiny.toDataURL('image/png');
    };
    img.onerror = reject;
    img.src = objectUrl;
  });
}

function toInputValue(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/*
 * Applies a relative quick-shift to a datetime-local draft. If the window has
 * no value yet, shifts from "now"; otherwise from the current draft so the
 * admin can stack chips ("+24 hours" then "+24 hours" = +48).
 */
function shiftDraft(draft, hours) {
  const base = draft ? new Date(`${draft}:00`) : new Date();
  return toInputValue(new Date(base.getTime() + hours * 3600 * 1000).toISOString());
}

function validateVoting(voting) {
  const errors = [];
  const opens = voting.registrationStart ? new Date(voting.registrationStart) : null;
  const regCloses = voting.registrationEnd ? new Date(voting.registrationEnd) : null;
  const voteOpens = voting.votingStart ? new Date(voting.votingStart) : null;
  const voteCloses = voting.votingEnd ? new Date(voting.votingEnd) : null;

  if (opens && regCloses && regCloses <= opens) errors.push('Registration Closes must be after Registration Opens.');
  if (regCloses && voteOpens && voteOpens < regCloses) errors.push('Voting Opens must be after Registration Closes.');
  if (voteOpens && voteCloses && voteCloses <= voteOpens) errors.push('Voting Closes must be after Voting Opens.');
  if (opens && voteCloses && voteCloses <= opens) errors.push('Voting Closes must be after Registration Opens.');
  const termEnd = voting.termEndsAt ? new Date(voting.termEndsAt) : null;
  if (termEnd && voteCloses && termEnd < voteCloses) errors.push('Term Ends must be after Voting Closes.');
  if (termEnd && opens && termEnd < opens) errors.push('Term Ends must be after Registration Opens.');
  return errors;
}

function VotingWindowDialog({ field, voting, onCommit, onClose }) {
  const cfg = VOTING_WINDOW_FIELDS[field];
  const [step, setStep] = useState('pick');
  const [draft, setDraft] = useState(() => toInputValue(voting[field]));
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  // datetime-local gives a wall-clock time in the admin's local zone. Convert
  // it to a UTC instant so the backend compares it against server time (UTC)
  // correctly — otherwise a "9:03 PM" deadline could fire 8 hours late.
  const nextIso = draft ? new Date(`${draft}:00`).toISOString() : '';

  // A term end that is already behind us fires TermArchive::runIfDue() the
  // moment the setting is read back, which archives every certified winner and
  // drops them off the live SSG roster. That is recoverable (Past Terms) but it
  // is not something an admin should discover by clicking Save, so the confirm
  // step says so out loud.
  const isStaleTermEnd = field === 'termEndsAt' && nextIso !== '' && new Date(nextIso) <= new Date();

  // Hour-scale quick shifts make no sense for a year-scale term, and shifting
  // from "now" when nothing is set would silently pick a term that ends in
  // hours. Only offer them once there is a real value to nudge.
  const showQuickShift = field !== 'termEndsAt' || Boolean(draft);

  const goToConfirm = () => {
    const errs = validateVoting({ ...voting, [field]: nextIso });
    if (errs.length) {
      setError(errs.join(' '));
      return;
    }
    setError('');
    setStep('confirm');
  };

  const confirm = async () => {
    setSubmitting(true);
    setError('');
    const result = await onCommit(nextIso);
    if (result?.ok) {
      onClose();
    } else {
      setError(result?.message || 'Could not save this window. Please try again.');
    }
    setSubmitting(false);
  };

  return (
    <div
      className="settings-modal-overlay"
      onClick={() => { if (!submitting) onClose(); }}
    >
      <div
        className="settings-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="settings-window-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="settings-modal-header">
          <div>
            <h3 id="settings-window-title">{cfg.label}</h3>
            <p>{cfg.desc}</p>
          </div>
          <button
            type="button"
            className="settings-modal-close"
            aria-label="Close"
            onClick={onClose}
            disabled={submitting}
          >
            ×
          </button>
        </div>

        <div className="settings-modal-body">
          {step === 'pick' ? (
            <>
              <label className="settings-modal-question" htmlFor="settings-window-input">
                {cfg.question}
              </label>
              <input
                id="settings-window-input"
                type="datetime-local"
                className="setting-input settings-window-input"
                value={draft}
                onChange={(e) => { setDraft(e.target.value); setError(''); }}
              />
              {draft && <p className="settings-window-preview">This sets it to {formatDateTime(nextIso)}.</p>}
              {!draft && <p className="settings-window-preview">Leave empty to clear this window (results and gating depend on the other configured times).</p>}
              {showQuickShift && (
                <div className="settings-quick-shift">
                  <span className="settings-quick-shift-label">Quick shift</span>
                  {QUICK_SHIFT_HOURS.map(({ label, hours }) => (
                    <button
                      key={label}
                      type="button"
                      className="shift-chip"
                      onClick={() => { setDraft(shiftDraft(draft, hours)); setError(''); }}
                      title={`Push ${cfg.label} later by ${hours} hour${hours > 1 ? 's' : ''}`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              )}
              {error && <div className="settings-dialog-error"><AlertTriangle size={14} /> {error}</div>}
            </>
          ) : (
            <>
              {nextIso ? (
                <p className="settings-modal-confirm-text">
                  Set <strong>{cfg.label}</strong> to <strong>{formatDateTime(nextIso)}</strong>?
                </p>
              ) : (
                <p className="settings-modal-confirm-text">
                  Clear <strong>{cfg.label}</strong> (no time set)?
                </p>
              )}
              {field === 'termEndsAt' && (
                <p className="settings-window-hint">
                  {isStaleTermEnd
                    ? 'This time has already passed, so the term closes as soon as the setting is saved: winners are archived to Past Terms and leave the live officer roster.'
                    : 'On this date the term closes automatically — winners are archived to Past Terms under the school-year label and leave the live officer roster.'}
                </p>
              )}
              <p className="settings-window-hint">
                This will be applied immediately to the election timeline that the
                dashboard, status badge, and results visibility follow.
              </p>
              {isStaleTermEnd && (
                <div className="settings-dialog-caution">
                  <AlertTriangle size={14} /> This term ends in the past.
                </div>
              )}
              {error && <div className="settings-dialog-error"><AlertTriangle size={14} /> {error}</div>}
            </>
          )}
        </div>

        <div className="settings-modal-footer">
          <button
            type="button"
            className="btn-secondary"
            onClick={() => (step === 'pick' ? onClose() : (setError(''), setStep('pick')))}
            disabled={submitting}
          >
            {step === 'pick' ? 'Cancel' : 'Back'}
          </button>
          {step === 'pick' ? (
            <button type="button" className="btn-save" onClick={goToConfirm}>
              Next
            </button>
          ) : (
            <button type="button" className="btn-save" onClick={confirm} disabled={submitting}>
              <Save size={16} /> {submitting ? 'Saving…' : 'Confirm'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

/*
 * Confirmation dialog for clearing the whole Voting Windows schedule. Guarded
 * like the per-field Edit dialogs so a stray click can't wipe the timeline.
 */
function ResetVotingWindowDialog({ onConfirm, onClose }) {
  return (
    <div className="settings-modal-overlay" onClick={onClose}>
      <div
        className="settings-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reset-window-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="settings-modal-header">
          <div>
            <h3 id="reset-window-title">Reset Voting Windows</h3>
            <p>Clear the scheduled election timeline</p>
          </div>
          <button type="button" className="settings-modal-close" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </div>

        <div className="settings-modal-body">
          <p className="settings-modal-confirm-text">
            Clear <strong>Registration Opens, Registration Closes, Voting Opens, and Voting Closes</strong>?
          </p>
          <p className="settings-window-hint">
            The schedule will be unset on this screen. Maximum Votes Per Voter and the
            toggles below are kept. Press Save to apply the cleared timeline.
          </p>
        </div>

        <div className="settings-modal-footer">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button type="button" className="btn-save" onClick={onConfirm}>
            <Trash2 size={16} /> Clear All Windows
          </button>
        </div>
      </div>
    </div>
  );
}

/*
 * Real track + thumb switch.
 * Mirrors the reference implementation used by Election Setup's position toggles
 * (hidden checkbox + styled track/thumb), but namespaced so the two stylesheets
 * cannot fight over `.toggle-switch`.
 */
function SettingSwitch({ checked, onChange, ariaLabel }) {
  return (
    <label className="setting-switch">
      <input
        type="checkbox"
        className="setting-switch-input"
        checked={Boolean(checked)}
        onChange={(e) => onChange(e.target.checked)}
        aria-label={ariaLabel}
      />
      <span className="setting-switch-slider" />
    </label>
  );
}

/* Label + helper text on the left, switch + state text on the right. */
function ToggleRow({ label, desc, checked, onChange, onLabel = 'Enabled', offLabel = 'Disabled' }) {
  return (
    <div className="setting-toggle-row">
      <div className="setting-toggle-copy">
        <div className="setting-label">{label}</div>
        {desc && <p className="setting-desc">{desc}</p>}
      </div>
      <div className="setting-toggle-control">
        <SettingSwitch checked={checked} onChange={onChange} ariaLabel={label} />
        <span className={checked ? 'toggle-label-on' : 'toggle-label-off'}>
          {checked ? onLabel : offLabel}
        </span>
      </div>
    </div>
  );
}

export default function Settings({ onLogout, onNavigate, initialTab = 'profile' }) {
  const { user, logout, updateUserAvatar } = useAuth();
  const { theme, toggleTheme } = useTheme();
  // Shared phase poller. The voting window decides the derived phase, and the
  // provider is mounted above every view (App.jsx), so navigating away from
  // Settings never remounts it. Without an explicit refresh() after a save the
  // header badge, dashboard phase card, and Results gating would keep showing
  // the previous phase until the 30 s tick landed — or until a hard refresh
  // remounted the provider. Voting is the only section that moves the phase.
  const { refresh: refreshElectionStatus } = useElectionStatus();
  // Platform configuration tabs (security, voting, branding, backup,
  // notifications) are admin-only; teacher and SSG President accounts get the
  // read-only My Profile view plus the personal Appearance tab. A deep link to
  // a managed tab the signed-in role cannot open is clamped to Profile so the
  // panel never renders an empty section.
  const ROLE_MANAGED_TABS = ['security', 'voting', 'branding', 'backup', 'notifications'];
  const canManageSettings = user?.role === 'admin';
  const [activeTab, setActiveTab] = useState(
    () => (!canManageSettings && ROLE_MANAGED_TABS.includes(initialTab) ? 'profile' : initialTab),
  );
  const [windowDialog, setWindowDialog] = useState(null);
  const [resetDialog, setResetDialog] = useState(false);
  const [form, setForm] = useState(() => loadCachedSettings() ?? DEFAULT_SETTINGS);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState('');
  const [error, setError] = useState('');
  const [backups, setBackups] = useState([]);
  const [backupsLoading, setBackupsLoading] = useState(true);
  const [creatingBackup, setCreatingBackup] = useState(false);
  const [restoreDialog, setRestoreDialog] = useState(null);

  // Avatar state. The chosen value (a saved avatar URL or an uploaded PNG
  // data URL) is the same avatar_url the Header renders.
  const [avatar, setAvatar] = useState(user?.avatar_url || '');
  const [avatarSaving, setAvatarSaving] = useState(false);
  const [pixelateUpload, setPixelateUpload] = useState(false);

  const handleAvatarFile = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    try {
      const dataUrl = await fileToAvatarDataUrl(file, pixelateUpload);
      setAvatar(dataUrl);
    } catch {
      setError('Could not read that image. Try a PNG or JPG.');
    }
  };

  // Save to the account via PATCH /admin/me/avatar. A 404/405 means the
  // account server is still on the pre-avatar build, so fall back to the
  // per-account device cache. A 422 is a real validation rejection (e.g. an
  // oversized data URL) and must surface the server's reason, not fake success.
  const saveAvatar = async () => {
    if (!avatar || avatarSaving) return;
    setAvatarSaving(true);
    setError('');
    setSaved('');
    try {
      await api.patch('/admin/me/avatar', { avatar_url: avatar });
      updateUserAvatar(avatar);
      writeLocalAvatar(user, avatar);
      setSaved('Avatar saved to your account.');
    } catch (err) {
      const status = err.response?.status;
      if (status === 404 || status === 405) {
        updateUserAvatar(avatar);
        writeLocalAvatar(user, avatar);
        setSaved('Avatar saved on this device. It will sync to the account after the server app is updated.');
      } else if (status === 422) {
        setError(err.response?.data?.message || 'Your avatar was rejected by the server. Try a smaller image.');
      } else {
        setError(err.response?.data?.message || 'Could not save your avatar. Please try again.');
      }
    } finally {
      setAvatarSaving(false);
    }
  };

  // Clear the avatar: PATCH null back to the initials fallback. On an
  // pre-avatar server just reflect it locally.
  const clearAvatar = async () => {
    if (avatarSaving) return;
    setAvatarSaving(true);
    setError('');
    setSaved('');
    try {
      await api.patch('/admin/me/avatar', { avatar_url: null });
      setSaved('Avatar removed. Back to your initial.');
    } catch (err) {
      const status = err.response?.status;
      if (status === 404 || status === 405) {
        setSaved('Avatar removed on this device.');
      } else {
        setError(err.response?.data?.message || 'Could not remove your avatar. Please try again.');
      }
    } finally {
      updateUserAvatar(null);
      writeLocalAvatar(user, '');
      setAvatar('');
      setAvatarSaving(false);
    }
  };

  // Warm from the server on mount; falls back to the cached copy if the
  // request fails (offline / backend not yet reached). Admin only: teacher and
  // SSG President accounts have no settings.view permission.
  useEffect(() => {
    if (!canManageSettings) return undefined;
    let alive = true;
    api
      .get('/admin/settings')
      .then((res) => {
        if (!alive) return;
        const merged = mergeSettings(DEFAULT_SETTINGS, res.data?.settings ?? {});
        setForm(merged);
        persistSettings(merged);
      })
      .catch(() => {
        /* keep the localStorage copy */
      });
    return () => {
      alive = false;
    };
  }, [canManageSettings]);

  const loadBackups = async (showSpinner = false) => {
    if (showSpinner) setBackupsLoading(true);
    try {
      const res = await api.get('/admin/backups');
      setBackups(Array.isArray(res.data?.backups) ? res.data.backups : []);
      setBackupsLoading(false);
    } catch {
      setBackupsLoading(false);
    }
  };

  useEffect(() => {
    if (!canManageSettings) return undefined;
    let alive = true;
    api
      .get('/admin/backups')
      .then((res) => {
        if (alive) setBackups(Array.isArray(res.data?.backups) ? res.data.backups : []);
      })
      .finally(() => {
        if (alive) setBackupsLoading(false);
      });
    return () => {
      alive = false;
    };
  }, [canManageSettings]);

  const handleLogout = () => { if (typeof onLogout === 'function') return onLogout(); logout(); };

  const update = (section, field, value) => {
    setForm((prev) => {
      const next = { ...prev, [section]: { ...prev[section], [field]: value } };
      persistSettings(next);
      return next;
    });
  };

  const handleSave = async (section, payload) => {
    const sectionValues = payload ?? form[section];
    setSaving(true); setError(''); setSaved('');
    try {
      const res = await api.patch(`/admin/settings/${section}`, sectionValues);
      const remote = res.data?.settings;
      if (remote) {
        const merged = mergeSettings(form, { [section]: remote });
        setForm(merged);
        persistSettings(merged);
      } else {
        setForm((prev) => ({ ...prev, [section]: sectionValues }));
        persistSettings(sectionValues);
      }
      if (section === 'branding') {
        await refreshBranding();
      }
      if (section === 'voting') {
        // Re-derive the phase now instead of waiting out the poll interval.
        // Fire-and-forget on purpose: the save already succeeded, so a failed
        // status fetch must not turn a good save into a reported error — the
        // poller retries on its next tick and the badge self-heals.
        refreshElectionStatus();
      }
      setSaved(`${section.charAt(0).toUpperCase() + section.slice(1)} settings saved.`);
      setTimeout(() => setSaved(''), 3000);
      return { ok: true, message: null };
    } catch (err) {
      persistSettings(form);
      const message = err.response?.data?.message || `Failed to save ${section} settings.`;
      setError(message);
      return { ok: false, message };
    } finally {
      setSaving(false);
    }
  };

  // Guided window flow: set the new value in the form, then save so the dialog
  // can surface server-side validation (e.g. "end must be after start") inline.
  const commitVotingWindow = async (iso) =>
    handleSave('voting', { ...form.voting, [windowDialog.field]: iso });

  const clearVotingWindows = () => {
    update('voting', 'registrationStart', '');
    update('voting', 'registrationEnd', '');
    update('voting', 'votingStart', '');
    update('voting', 'votingEnd', '');
    setResetDialog(false);
  };

  const runBackupNow = async () => {
    setCreatingBackup(true); setError(''); setSaved('');
    try {
      await api.post('/admin/backups', { encrypt: form.backup.backupEncryption });
      setSaved('Backup created.');
      setTimeout(() => setSaved(''), 3000);
      await loadBackups();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not create a backup.');
    } finally {
      setCreatingBackup(false);
    }
  };

  const downloadBackup = async (file) => {
    try {
      const res = await api.get(`/admin/backups/${encodeURIComponent(file)}/download`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = file;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(err.response?.data?.message || 'Could not download the backup.');
    }
  };

  const deleteBackup = async (file) => {
    if (!window.confirm(`Delete backup ${file}?`)) return;
    try {
      await api.delete(`/admin/backups/${encodeURIComponent(file)}`);
      await loadBackups();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not delete the backup.');
    }
  };

  const restoreBackup = async (file) => {
    setRestoreDialog({ file, busy: false });
  };

  const confirmRestore = async () => {
    setRestoreDialog({ ...restoreDialog, busy: true });
    setError(''); setSaved('');
    try {
      await api.post('/admin/backups/restore', { file: restoreDialog.file });
      setSaved('Database restored from backup.');
      setTimeout(() => setSaved(''), 3000);
      setRestoreDialog(null);
      await loadBackups();
    } catch (err) {
      setError(err.response?.data?.message || 'Restore failed.');
      setRestoreDialog({ ...restoreDialog, busy: false });
    }
  };

  const tabs = [
    { id: 'profile', label: 'My Profile', icon: CircleUserRound },
    ...(canManageSettings
      ? [
        { id: 'security', label: 'Security', icon: Shield },
        { id: 'voting', label: 'Voting Windows', icon: Clock },
        { id: 'branding', label: 'Branding', icon: Palette },
        { id: 'backup', label: 'Backup & Restore', icon: Database },
        { id: 'notifications', label: 'Notifications', icon: Bell },
      ]
      : []),
    { id: 'appearance', label: 'Appearance', icon: Moon },
  ];

  return (
    <div className="settings-app">
      <aside className="sidebar">
        <div className="logo-area">
          <div className="logo-icon-bg"><OmniVoteMark className="sidebar-logo-img" /></div>
          <div><h1 className="brand-name">OmniVote</h1><p className="brand-sub">SETTINGS</p></div>
        </div>
        {typeof onNavigate === 'function' && (
          <button
            type="button"
            className="back-dashboard-button"
            onClick={() => onNavigate('dashboard')}
            aria-label="Back to Dashboard"
          >
            <ArrowLeft size={18} />
          </button>
        )}
        <nav className="nav-menu">
          {tabs.map(t => (
            <button
              key={t.id}
              type="button"
              className={`nav-item ${activeTab === t.id ? 'active' : ''}`}
              onClick={() => setActiveTab(t.id)}
              aria-current={activeTab === t.id ? 'page' : undefined}
            >
              <t.icon size={18} /> {t.label}
            </button>
          ))}
        </nav>
        <div className="sidebar-footer-container">
          <button type="button" onClick={handleLogout} className="logout-button"><LogOut size={18} /> Logout</button>
          <div className="sidebar-footer"><span className="status-dot-green" /> {user?.name || 'Admin'}</div>
        </div>
      </aside>

      <main className="settings-main">
        <header className="settings-header">
          <div className="settings-title-block">
            <div className="breadcrumb"><span className="muted">Administration / </span><strong>Configuration</strong></div>
            <h2 className="settings-title">Configuration</h2>
            <p className="settings-subtitle">Manage platform security, voting windows, branding, backups, and notifications.</p>
          </div>
          <div className="settings-profile">
            <span className="voting-status-badge"><span className="status-dot-green" /> Configured</span>
          </div>
        </header>

        <div className="settings-content">
          {saved && <div className="settings-banner settings-banner-success"><CheckCircle size={16} /> {saved}</div>}
          {error && <div className="settings-banner settings-banner-error"><AlertTriangle size={16} /> {error}</div>}

          {activeTab === 'profile' && (() => {
            const profile = user;
            const isLocked = Boolean(profile?.locked_until && new Date(profile.locked_until) > new Date());
            return (
              <section className="settings-section">
                <div className="settings-panel">
                  <div className="section-header">
                    <div>
                      <h3>My Profile</h3>
                      <p className="muted-text">Your account details and security status</p>
                    </div>
                    <CircleUserRound size={20} className="section-icon" />
                  </div>

                  <div className="profile-identity">
                    <div className="profile-avatar" aria-hidden="true">{initials(profile?.name)}</div>
                    <div className="profile-identity-copy">
                      <div className="profile-name">{profile?.name || '—'}</div>
                      <div className="profile-email">{profile?.email || '—'}</div>
                      <div className="profile-badges">
                        <span className={`settings-role-badge role-badge-${profile?.role || 'student'}`}>{roleLabel(profile?.role)}</span>
                        {profile?.is_active === false && <span className="profile-badge profile-badge-danger">Account disabled</span>}
                        {isLocked && <span className="profile-badge profile-badge-warning">Temporarily locked</span>}
                        {profile?.two_factor_enabled && <span className="profile-badge profile-badge-success">2FA enabled</span>}
                        {profile?.two_factor_required && <span className="profile-badge profile-badge-info">2FA required by policy</span>}
                      </div>
                    </div>
                  </div>

<div className="profile-avatar-picker">
                    <div className="avatar-picker-head">
                      <div>
                        <h4>Avatar</h4>
                        <p className="muted-text">Upload a photo from your device</p>
                      </div>
                      {avatar ? (
                        <img
                          src={avatar}
                          alt="Avatar preview"
                          className="avatar-preview-img"
                          onError={fallbackAvatarOnError(profile?.name)}
                        />
                      ) : (
                        <div className="avatar-preview-img avatar-preview-initials">{initials(profile?.name)}</div>
                      )}
                    </div>

                    <div className="avatar-device-row">
                      <input
                        type="file"
                        id="avatar-file-input"
                        accept="image/*"
                        className="avatar-file-input"
                        onChange={handleAvatarFile}
                      />
                      <label htmlFor="avatar-file-input" className="btn-secondary avatar-upload-btn">
                        <Upload size={16} /> Choose from Device
                      </label>
                      <label className="avatar-pixelate-label">
                        <input type="checkbox" checked={pixelateUpload} onChange={(e) => setPixelateUpload(e.target.checked)} />
                        Pixelate upload
                      </label>
                      <span className="avatar-hint">PNG or JPG, cropped square</span>
                    </div>

                    <div className="settings-panel-footer">
                      <div className="avatar-actions">
                        <button className="btn-save" onClick={saveAvatar} disabled={!avatar || avatarSaving}>
                          <Save size={16} /> {avatarSaving ? 'Saving…' : 'Save Avatar'}
                        </button>
                        {user?.avatar_url && (
                          <button className="btn-secondary avatar-remove-btn" onClick={clearAvatar} disabled={avatarSaving}>
                            <Trash2 size={16} /> Remove Avatar
                          </button>
                        )}
                      </div>
                    </div>
                  </div>

                  <div className="settings-grid">
                    <div className="setting-card">
                      <div className="setting-label">Full Name</div>
                      <p className="setting-desc">{profile?.name || '—'}</p>
                    </div>

                    <div className="setting-card">
                      <div className="setting-label">Email Address</div>
                      <p className="setting-desc">{profile?.email || '—'}</p>
                    </div>

                    <div className="setting-card">
                      <div className="setting-label">Account Role</div>
                      <p className="setting-desc">{roleLabel(profile?.role) || '—'}</p>
                    </div>

                    <div className="setting-card">
                      <div className="setting-label">Account Status</div>
                      <p className="setting-desc">
                        {profile?.is_active === false ? 'Disabled' : 'Active'}
                        {isLocked ? ' · temporarily locked' : ''}
                      </p>
                    </div>

                    {profile?.department && (
                      <div className="setting-card">
                        <div className="setting-label">Department</div>
                        <p className="setting-desc">{profile.department}</p>
                      </div>
                    )}

                    {profile?.student_id && (
                      <div className="setting-card">
                        <div className="setting-label">Student ID</div>
                        <p className="setting-desc">{profile.student_id}</p>
                      </div>
                    )}

                    {profile?.year_level && (
                      <div className="setting-card">
                        <div className="setting-label">Year Level</div>
                        <p className="setting-desc">{profile.year_level}</p>
                      </div>
                    )}

                    {profile?.block_number && (
                      <div className="setting-card">
                        <div className="setting-label">Section / Block</div>
                        <p className="setting-desc">{profile.block_number}</p>
                      </div>
                    )}

                    {(profile?.has_voted || profile?.voted_at) && (
                      <div className="setting-card">
                        <div className="setting-label">Voting Status</div>
                        <p className="setting-desc">
                          {profile?.voted_at ? `Voted on ${formatCalendarDate(profile.voted_at)}` : 'Voted'}
                        </p>
                      </div>
                    )}

                    <div className="setting-card">
                      <div className="setting-label">Two-Factor Authentication</div>
                      <p className="setting-desc">
                        {profile?.two_factor_enabled ? 'Enabled' : 'Disabled'}
                        {profile?.two_factor_required ? ' · required by policy' : ''}
                      </p>
                    </div>

                    <div className="setting-card">
                      <div className="setting-label">Member Since</div>
                      <p className="setting-desc">{formatCalendarDate(profile?.created_at)}</p>
                    </div>
                  </div>

                  {profile?.needs_review && (
                    <div className="settings-banner settings-banner-error">
                      <AlertTriangle size={16} />
                      <span>
                        This profile is flagged for review{profile?.review_reason ? `: ${profile.review_reason}` : ''}.
                      </span>
                    </div>
                  )}
                </div>
              </section>
            );
          })()}

          {activeTab === 'security' && (
            <section className="settings-section">
              <div className="settings-panel">
                <div className="section-header">
                  <div>
                    <h3>Security Policies</h3>
                    <p className="muted-text">Session, authentication, and access controls</p>
                  </div>
                  <Lock size={20} className="section-icon" />
                </div>

                <div className="settings-grid">
                  <div className="setting-card">
                    <div className="setting-label">Session Timeout (minutes)</div>
                    <p className="setting-desc">Automatically log out inactive admin sessions</p>
                    <input type="number" className="setting-input" min={5} max={480} value={form.security.sessionTimeout} onChange={e => update('security', 'sessionTimeout', Number(e.target.value))} />
                    <div className="setting-hint">30 minutes recommended for shared devices</div>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Max Login Attempts</div>
                    <p className="setting-desc">Start an exponential sign-in pause after this many consecutive failures</p>
                    <input type="number" className="setting-input" min={1} max={20} value={form.security.maxLoginAttempts} onChange={e => update('security', 'maxLoginAttempts', Number(e.target.value))} />
                    <div className="setting-hint">Beyond this the wait doubles each attempt, up to 60 minutes, then decays to zero</div>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Minimum Password Length</div>
                    <p className="setting-desc">Enforced for all user accounts</p>
                    <input type="number" className="setting-input" min={6} max={64} value={form.security.passwordMinLength} onChange={e => update('security', 'passwordMinLength', Number(e.target.value))} />
                    <div className="setting-hint">NIST recommends at least 8 characters</div>
                  </div>

                  <div className="setting-card setting-card-full">
                    <div className="setting-label">Password Complexity Requirements</div>
                    <p className="setting-desc">Rules applied when users set or reset passwords</p>
                    <div className="checkbox-group">
                      <label className="checkbox-row">
                        <input type="checkbox" checked={form.security.passwordRequireSpecial} onChange={e => update('security', 'passwordRequireSpecial', e.target.checked)} />
                        <span className="checkbox-label">Require special characters (!@#$%^&amp;*...)</span>
                      </label>
                      <label className="checkbox-row">
                        <input type="checkbox" checked={form.security.passwordRequireNumber} onChange={e => update('security', 'passwordRequireNumber', e.target.checked)} />
                        <span className="checkbox-label">Require at least one number</span>
                      </label>
                      <label className="checkbox-row">
                        <input type="checkbox" checked={form.security.passwordRequireUpper} onChange={e => update('security', 'passwordRequireUpper', e.target.checked)} />
                        <span className="checkbox-label">Require at least one uppercase letter</span>
                      </label>
                    </div>
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Two-Factor Authentication (2FA) Required"
                      desc="Enforce 2FA for all admin and staff accounts"
                      checked={form.security.twoFactorRequired}
                      onChange={v => update('security', 'twoFactorRequired', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Student Self-Registration"
                      desc="Let students claim their own account using a registrar-issued activation code"
                      checked={form.security.selfRegistrationEnabled}
                      onChange={v => update('security', 'selfRegistrationEnabled', v)}
                    />
                    <div className="setting-hint">
                      Off by default. Leave it off when every account is provisioned through the CSV
                      import — the registrar flow is the supported path. When on, a student still needs
                      the one-time code from their registrar slip; a student ID alone is not enough.
                    </div>
                  </div>
                </div>

                <TwoFactorSetup />

                <div className="settings-panel-footer">
                  <button className="btn-save" onClick={() => handleSave('security')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Security Settings'}</button>
                </div>
              </div>
            </section>
          )}

          {activeTab === 'voting' && (
            <section className="settings-section">
              <div className="settings-panel">
                <div className="section-header">
                  <div>
                    <h3>Voting Windows</h3>
                    <p className="muted-text">Schedule registration, voting, and results visibility</p>
                  </div>
                  <Clock size={20} className="section-icon" />
                </div>

                <div className="settings-grid">
                  <div className="setting-card">
                    <div className="setting-label">Registration Opens</div>
                    <p className="setting-desc">When students can begin registering</p>
                    <button type="button" className="setting-window-button" onClick={() => setWindowDialog({ field: 'registrationStart' })}>
                      <Clock size={16} className="setting-window-icon" />
                      <span className="setting-window-value">{formatDateTime(form.voting.registrationStart)}</span>
                      <span className="setting-window-edit">Edit</span>
                    </button>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Registration Closes</div>
                    <p className="setting-desc">Deadline for voter registration</p>
                    <button type="button" className="setting-window-button" onClick={() => setWindowDialog({ field: 'registrationEnd' })}>
                      <Clock size={16} className="setting-window-icon" />
                      <span className="setting-window-value">{formatDateTime(form.voting.registrationEnd)}</span>
                      <span className="setting-window-edit">Edit</span>
                    </button>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Voting Opens</div>
                    <p className="setting-desc">When the ballot becomes available</p>
                    <button type="button" className="setting-window-button" onClick={() => setWindowDialog({ field: 'votingStart' })}>
                      <Clock size={16} className="setting-window-icon" />
                      <span className="setting-window-value">{formatDateTime(form.voting.votingStart)}</span>
                      <span className="setting-window-edit">Edit</span>
                    </button>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Voting Closes</div>
                    <p className="setting-desc">Ballots are no longer accepted after this time</p>
                    <button type="button" className="setting-window-button" onClick={() => setWindowDialog({ field: 'votingEnd' })}>
                      <Clock size={16} className="setting-window-icon" />
                      <span className="setting-window-value">{formatDateTime(form.voting.votingEnd)}</span>
                      <span className="setting-window-edit">Edit</span>
                    </button>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Term Ends</div>
                    <p className="setting-desc">
                      When this term ends, winners are archived to a Past Terms record
                      {schoolYearOf(form.voting.termEndsAt) && ` under ${schoolYearOf(form.voting.termEndsAt)}`}
                    </p>
                    <button type="button" className="setting-window-button" onClick={() => setWindowDialog({ field: 'termEndsAt' })}>
                      <Clock size={16} className="setting-window-icon" />
                      <span className="setting-window-value">{formatDateTime(form.voting.termEndsAt)}</span>
                      <span className="setting-window-edit">Edit</span>
                    </button>
                  </div>

                  <div className="setting-card setting-card-full">
                    <div className="setting-label">Maximum Votes Per Voter</div>
                    <p className="setting-desc">Number of positions a voter can cast in one session</p>
                    <input type="number" className="setting-input" min={1} max={20} value={form.voting.maxVotesPerVoter} onChange={e => update('voting', 'maxVotesPerVoter', Number(e.target.value))} />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Allow Vote Change"
                      desc="Voters may modify their ballot before the voting window closes"
                      checked={form.voting.allowVoteChange}
                      onChange={v => update('voting', 'allowVoteChange', v)}
                      onLabel="Enabled — voters may update selections"
                      offLabel="Disabled — ballot is final on submission"
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Vote Confirmation Required"
                      desc="Show a review screen before finalizing the ballot"
                      checked={form.voting.voteConfirmationRequired}
                      onChange={v => update('voting', 'voteConfirmationRequired', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Show Results After Close"
                      desc="Automatically reveal results when voting ends"
                      checked={form.voting.showResultsAfterClose}
                      onChange={v => update('voting', 'showResultsAfterClose', v)}
                    />
                  </div>
                </div>

                <div className="settings-panel-footer split">
                  <button type="button" className="btn-secondary" onClick={() => setResetDialog(true)} disabled={saving}><Trash2 size={16} /> Reset Windows</button>
                  <button className="btn-save" onClick={() => handleSave('voting')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Voting Window Settings'}</button>
                </div>
              </div>
            </section>
          )}

          {activeTab === 'branding' && (
            <section className="settings-section">
              <div className="settings-panel">
                <div className="section-header">
                  <div>
                    <h3>Platform Branding</h3>
                    <p className="muted-text">Customize the look and feel of the election portal</p>
                  </div>
                  <Palette size={20} className="section-icon" />
                </div>

                <div className="settings-grid">
                  <div className="setting-card">
                    <div className="setting-label">Site Name</div>
                    <p className="setting-desc">Displayed in headers and emails</p>
                    <input type="text" className="setting-input" value={form.branding.siteName} onChange={e => update('branding', 'siteName', e.target.value)} />
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Header Text</div>
                    <p className="setting-desc">Tagline shown on the voting portal</p>
                    <input type="text" className="setting-input" value={form.branding.headerText} onChange={e => update('branding', 'headerText', e.target.value)} />
                  </div>

                  <div className="setting-card setting-card-full">
                    <div className="setting-label">Footer Text</div>
                    <p className="setting-desc">Displayed at the bottom of all pages</p>
                    <input type="text" className="setting-input" value={form.branding.footerText} onChange={e => update('branding', 'footerText', e.target.value)} />
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Primary Color</div>
                    <p className="setting-desc">Main accent color used across the interface</p>
                    <div className="color-picker-row">
                      <span className="color-swatch-preview" style={{ backgroundColor: form.branding.primaryColor }} aria-hidden="true" />
                      <input type="color" className="color-swatch" value={form.branding.primaryColor} onChange={e => update('branding', 'primaryColor', e.target.value)} aria-label="Pick primary color" />
                      <input type="text" className="setting-input color-hex-input" value={form.branding.primaryColor} onChange={e => update('branding', 'primaryColor', e.target.value)} pattern="^#[0-9a-fA-F]{6}$" aria-label="Primary color hex value" />
                    </div>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Secondary Color</div>
                    <p className="setting-desc">Supporting accent and text colors</p>
                    <div className="color-picker-row">
                      <span className="color-swatch-preview" style={{ backgroundColor: form.branding.secondaryColor }} aria-hidden="true" />
                      <input type="color" className="color-swatch" value={form.branding.secondaryColor} onChange={e => update('branding', 'secondaryColor', e.target.value)} aria-label="Pick secondary color" />
                      <input type="text" className="setting-input color-hex-input" value={form.branding.secondaryColor} onChange={e => update('branding', 'secondaryColor', e.target.value)} pattern="^#[0-9a-fA-F]{6}$" aria-label="Secondary color hex value" />
                    </div>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Logo URL</div>
                    <p className="setting-desc">Public URL to the organization logo (PNG or SVG)</p>
                    <input type="url" className="setting-input" value={form.branding.logoUrl} onChange={e => update('branding', 'logoUrl', e.target.value)} placeholder="https://example.edu/logo.png" />
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Favicon URL</div>
                    <p className="setting-desc">Small icon shown in browser tabs</p>
                    <input type="url" className="setting-input" value={form.branding.faviconUrl} onChange={e => update('branding', 'faviconUrl', e.target.value)} placeholder="https://example.edu/favicon.ico" />
                  </div>
                </div>

                <div className="settings-panel-footer">
                  <button className="btn-save" onClick={() => handleSave('branding')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Branding Settings'}</button>
                </div>
              </div>
            </section>
          )}

          {activeTab === 'backup' && (
            <section className="settings-section">
              <div className="settings-panel">
                <div className="section-header">
                  <div>
                    <h3>Backup &amp; Restore</h3>
                    <p className="muted-text">Automated backups protect your election data</p>
                  </div>
                  <Database size={20} className="section-icon" />
                </div>

                <div className="backup-status-banner">
                  <Database size={18} className="backup-status-icon" />
                  {backupsLoading ? (
                    <div className="backup-status-list"><div><strong>Loading backups…</strong></div></div>
                  ) : backups.length === 0 ? (
                    <div className="backup-status-list">
                      <div><strong>No backups yet</strong> <span className="muted-text">— click “Run Backup Now” to create your first snapshot.</span></div>
                    </div>
                  ) : (
                    <div className="backup-status-list">
                      <div><strong>Last Backup:</strong> {backups[0].label}</div>
                      <div><strong>Backup Size:</strong> {backups[0].size_label}{backups[0].encrypted ? ' encrypted' : ''}</div>
                      <div><strong>Total Snapshots:</strong> {backups.length}</div>
                    </div>
                  )}
                </div>

                <div className="backup-list">
                  <div className="backup-list-header"><strong>Available Backups</strong></div>
                  {backupsLoading ? (
                    <div className="backup-row">Loading…</div>
                  ) : backups.length === 0 ? (
                    <div className="backup-row muted-text">No backups stored yet.</div>
                  ) : (
                    backups.map(b => (
                      <div className="backup-row" key={b.filename}>
                        <div className="backup-row-info">
                          <div><strong>{b.label}</strong>{b.encrypted && <span className="backup-encrypted-badge">encrypted</span>}</div>
                          <div className="muted-text">{b.size_label} · {b.filename}</div>
                        </div>
                        <div className="backup-row-actions">
                          <button className="btn-icon" title="Download" onClick={() => downloadBackup(b.filename)}><Download size={16} /></button>
                          <button className="btn-icon" title="Restore" onClick={() => restoreBackup(b.filename)}><RotateCcw size={16} /></button>
                          <button className="btn-icon btn-icon-danger" title="Delete" onClick={() => deleteBackup(b.filename)}><Trash2 size={16} /></button>
                        </div>
                      </div>
                    ))
                  )}
                </div>

                <div className="settings-grid">
                  <div className="setting-card">
                    <ToggleRow
                      label="Auto Backup"
                      desc="Automatically create backups on schedule"
                      checked={form.backup.autoBackup}
                      onChange={v => update('backup', 'autoBackup', v)}
                    />
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Backup Frequency</div>
                    <p className="setting-desc">How often to create backups</p>
                    <select className="setting-select" value={form.backup.backupFrequency} onChange={e => update('backup', 'backupFrequency', e.target.value)}>
                      <option value="hourly">Hourly</option>
                      <option value="daily">Daily</option>
                      <option value="weekly">Weekly</option>
                    </select>
                  </div>

                  <div className="setting-card">
                    <div className="setting-label">Retention Period (days)</div>
                    <p className="setting-desc">How many days of backups to keep</p>
                    <input type="number" className="setting-input" min={1} max={365} value={form.backup.backupRetention} onChange={e => update('backup', 'backupRetention', Number(e.target.value))} />
                  </div>

                  <div className="setting-card">
                    <ToggleRow
                      label="Remote Storage"
                      desc="Upload encrypted backups to off-site storage"
                      checked={form.backup.remoteStorage}
                      onChange={v => update('backup', 'remoteStorage', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Backup Encryption"
                      desc="Encrypt all backups with AES-256"
                      checked={form.backup.backupEncryption}
                      onChange={v => update('backup', 'backupEncryption', v)}
                    />
                  </div>
                </div>

                <div className="settings-panel-footer split">
                  <div className="backup-actions">
                    <button className="btn-secondary" onClick={runBackupNow} disabled={creatingBackup || saving}><RefreshCw size={16} /> {creatingBackup ? 'Creating…' : 'Run Backup Now'}</button>
                  </div>
                  <button className="btn-save" onClick={() => handleSave('backup')} disabled={saving || creatingBackup}><Save size={16} /> {saving ? 'Saving...' : 'Save Backup Settings'}</button>
                </div>
              </div>
            </section>
          )}

          {activeTab === 'notifications' && (
            <section className="settings-section">
              <div className="settings-panel">
                <div className="section-header">
                  <div>
                    <h3>Notification Preferences</h3>
                    <p className="muted-text">Configure alerts and email/SMS digests</p>
                  </div>
                  <Bell size={20} className="section-icon" />
                </div>

                <div className="settings-grid settings-grid-3">
                  <div className="setting-card setting-card-full channel-group-label">
                    <div className="setting-label">Delivery Channels</div>
                    <p className="setting-desc">Choose how OmniVote reaches administrators</p>
                  </div>
                </div>

                <div className="settings-grid settings-grid-3">
                  <div className="setting-card">
                    <ToggleRow
                      label="Email Notifications"
                      desc="Send email alerts for platform events"
                      checked={form.notifications.emailEnabled}
                      onChange={v => update('notifications', 'emailEnabled', v)}
                    />
                  </div>

                  <div className="setting-card">
                    <ToggleRow
                      label="SMS Notifications"
                      desc="Send text messages for critical alerts"
                      checked={form.notifications.smsEnabled}
                      onChange={v => update('notifications', 'smsEnabled', v)}
                    />
                  </div>

                  <div className="setting-card">
                    <ToggleRow
                      label="Push Notifications"
                      desc="Browser push notifications for real-time updates"
                      checked={form.notifications.pushEnabled}
                      onChange={v => update('notifications', 'pushEnabled', v)}
                    />
                  </div>
                </div>

                <div className="settings-grid">
                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Notify on New Registration"
                      desc="Alert admin when a student registers"
                      checked={form.notifications.notifyOnRegistration}
                      onChange={v => update('notifications', 'notifyOnRegistration', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Email on Vote Cast"
                      desc="Send confirmation email after each vote"
                      checked={form.notifications.emailOnVote}
                      onChange={v => update('notifications', 'emailOnVote', v)}
                      offLabel="Not recommended for large elections"
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Email on Results Published"
                      desc="Notify admin when election results are released"
                      checked={form.notifications.emailOnResult}
                      onChange={v => update('notifications', 'emailOnResult', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Email on Admin Action"
                      desc="Notify on role changes, status updates, etc."
                      checked={form.notifications.emailOnAdminAction}
                      onChange={v => update('notifications', 'emailOnAdminAction', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="SMS on Critical Events"
                      desc="Immediate text for security incidents or system issues"
                      checked={form.notifications.smsOnCritical}
                      onChange={v => update('notifications', 'smsOnCritical', v)}
                    />
                  </div>

                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Daily Digest"
                      desc="Summary of election activity sent each morning"
                      checked={form.notifications.dailyDigest}
                      onChange={v => update('notifications', 'dailyDigest', v)}
                    />
                  </div>
                </div>

                <div className="settings-panel-footer">
                  <button className="btn-save" onClick={() => handleSave('notifications')} disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Notification Settings'}</button>
                </div>
              </div>
            </section>
          )}

          {activeTab === 'notifications' && (
            <NotificationBroadcast />
          )}

          {activeTab === 'appearance' && (
            <section className="settings-section">
              <div className="settings-panel">
                <div className="section-header">
                  <div>
                    <h3>Appearance</h3>
                    <p className="muted-text">Control how the admin dashboard looks</p>
                  </div>
                  <Moon size={20} className="section-icon" />
                </div>

                <div className="settings-grid">
                  <div className="setting-card setting-card-full">
                    <ToggleRow
                      label="Dark Mode"
                      desc="Apply the dark theme across the entire dashboard (sidebar stays dark in both themes)"
                      checked={theme === 'dark'}
                      onChange={toggleTheme}
                      onLabel="Dark theme active"
                      offLabel="Light theme active"
                    />
                  </div>
                </div>
              </div>
            </section>
          )}
        </div>
      </main>

      {windowDialog && (
        <VotingWindowDialog
          field={windowDialog.field}
          voting={form.voting}
          onCommit={commitVotingWindow}
          onClose={() => setWindowDialog(null)}
        />
      )}
      {resetDialog && (
        <ResetVotingWindowDialog
          onConfirm={clearVotingWindows}
          onClose={() => setResetDialog(false)}
        />
      )}
      {restoreDialog && (
        <div className="settings-modal-overlay" onClick={() => { if (!restoreDialog.busy) setRestoreDialog(null); }}>
          <div
            className="settings-modal settings-modal-restore"
            role="dialog"
            aria-modal="true"
            aria-labelledby="restore-dialog-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="settings-modal-header">
              <div>
                <h3 id="restore-dialog-title">Restore From Backup</h3>
              </div>
            </div>
            <p className="restore-dialog-body">
              This will <strong>overwrite all current election data</strong> with the snapshot
              from <strong>{restoreDialog.file}</strong>. Proceed?
            </p>
            {restoreDialog.busy && <p className="muted-text">Restoring…</p>}
            <div className="settings-modal-actions">
              <button className="btn-secondary" type="button" disabled={restoreDialog.busy} onClick={() => setRestoreDialog(null)}>Cancel</button>
              <button className="btn-danger" type="button" disabled={restoreDialog.busy} onClick={confirmRestore}>
                <Trash2 size={16} /> {restoreDialog.busy ? 'Restoring…' : 'Restore'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}