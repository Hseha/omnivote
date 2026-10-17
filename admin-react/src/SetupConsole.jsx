import { useState } from 'react';
import { CheckCircle2, Lock, Eye, EyeOff, UserRound } from 'lucide-react';
import api from './lib/api';
import { useBranding } from './lib/branding';
import { passwordProblem } from './lib/passwordRules';
import OmniVoteMark from './components/OmniVoteMark';
import './AdminLogin.css';

/*
 * SSG President console onboarding (public screen, opened from the one-time
 * setup link in the President's notification feed: /ssg/setup?token=...).
 *
 * The link carries no secret credential — only a single-use, expiring token.
 * This screen exchanges that token for the username and password the President
 * will sign in with; the server revokes every pre-handoff session and burns the
 * link. Mirrors ResetPassword by design (a public, token-bound credential set).
 */
export default function SetupConsole({ token, onCompleted }) {
  const branding = useBranding();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);
  const [finalUsername, setFinalUsername] = useState('');

  const passwordIssue = password ? passwordProblem(password) : null;
  const confirmIssue =
    confirm && confirm !== password ? 'Passwords do not match yet.' : null;
  const usernameIssue =
    username && !/^[A-Za-z0-9._-]{3,50}$/.test(username)
      ? 'Use 3–50 letters, numbers, dots, dashes or underscores.'
      : null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const { data } = await api.post('/ssg/console/setup', {
        token,
        username,
        password,
        password_confirmation: confirm,
      });
      setFinalUsername(data?.username || username.toLowerCase());
      setDone(true);
      if (typeof onCompleted === 'function') onCompleted();
    } catch (err) {
      const validation = err.response?.data?.errors;
      setError(
        (validation && Object.values(validation).flat()[0]) ||
          err.response?.data?.message ||
          err.message ||
          'Unable to finish setup. The link may have expired.',
      );
      setPassword('');
      setConfirm('');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-card">
        <div className="login-header">
          <div className="brand-wrapper">
            <div className="brand-icon-box">
              {branding.logoUrl ? <img src={branding.logoUrl} alt="" className="brand-logo-img" /> : <OmniVoteMark className="brand-logo-img" />}
            </div>
            <div className="brand-text">
              <h1 className="brand-title">{branding.siteName || 'OmniVote'}</h1>
              <span className="brand-subtitle">SSG PRESIDENT SETUP</span>
            </div>
          </div>
          <p className="login-description">
            {done
              ? 'Your SSG President console account is ready.'
              : 'Choose the username and password you will use to sign in to your governance console.'}
          </p>
        </div>

        {error && (
          <div className="login-error" role="alert" style={{ color: '#dc2626', marginBottom: '12px', fontSize: '0.9rem' }}>
            {error}
          </div>
        )}

        {!token && !done ? (
          <div className="forgot-success" role="status">
            <p>
              Open the one-time setup link from your OmniVote notifications to finish
              activating your SSG President console. For your security the console stays
              locked until you choose your own username and password.
            </p>
          </div>
        ) : done ? (
          <div className="forgot-success" role="status">
            <CheckCircle2 size={32} color="#10b981" />
            <p>
              Sign in with the username <strong>{finalUsername}</strong> and the password you
              just chose. You will set up two-factor authentication on first sign in.
            </p>
            <a className="submit-btn reset-signin-link" href="./">
              Go to Sign In
            </a>
          </div>
        ) : (
          <form className="login-form" onSubmit={handleSubmit}>
            <div className="form-group">
              <label htmlFor="setup-username" className="form-label">Username</label>
              <div className="input-wrapper">
                <UserRound className="input-icon" size={18} />
                <input
                  id="setup-username"
                  type="text"
                  className="form-input"
                  placeholder="Choose a username"
                  autoComplete="username"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  required
                  minLength={3}
                  maxLength={50}
                />
              </div>
              {usernameIssue && <p className="forgot-password-hint">{usernameIssue}</p>}
            </div>

            <div className="form-group">
              <label htmlFor="setup-password" className="form-label">New Password</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
                  id="setup-password"
                  type={showPassword ? 'text' : 'password'}
                  className="form-input"
                  placeholder="At least 8 characters"
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  minLength={8}
                />
                <button
                  type="button"
                  className="toggle-password-btn"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  onClick={() => setShowPassword(!showPassword)}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {passwordIssue && <p className="forgot-password-hint">{passwordIssue}</p>}
            </div>

            <div className="form-group">
              <label htmlFor="setup-confirm" className="form-label">Confirm New Password</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
                  id="setup-confirm"
                  type={showConfirm ? 'text' : 'password'}
                  className="form-input"
                  placeholder="Repeat your new password"
                  autoComplete="new-password"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  required
                  minLength={8}
                />
                <button
                  type="button"
                  className="toggle-password-btn"
                  aria-label={showConfirm ? 'Hide password' : 'Show password'}
                  onClick={() => setShowConfirm(!showConfirm)}
                >
                  {showConfirm ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {confirmIssue && <p className="forgot-password-hint">{confirmIssue}</p>}
            </div>

            <button
              type="submit"
              className="submit-btn"
              disabled={loading || Boolean(passwordIssue) || Boolean(confirmIssue) || Boolean(usernameIssue)}
            >
              {loading ? 'Saving...' : 'Activate My Console'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
