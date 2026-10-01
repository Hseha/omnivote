import { useState } from 'react';
import { CheckCircle2, KeyRound, Lock, Eye, EyeOff } from 'lucide-react';
import api from './lib/api';
import { useBranding } from './lib/branding';
import { passwordProblem } from './lib/passwordRules';
import './AdminLogin.css';

/*
 * Forgot-password step 2 (public screen, opened from the emailed link): the
 * SPA reads ?token= & ?email= from the URL, the user picks a new password, and
 * this posts it to the API. On success the URL params are cleared so a stale
 * link can't randomly reset the password again, and the user signs in.
 */
export default function ResetPassword({ token, email, onCompleted }) {
  const branding = useBranding();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);

  // Convenience feedback only — the API still validates and has the final say.
  const passwordIssue = password ? passwordProblem(password) : null;
  const confirmIssue =
    confirm && confirm !== password ? 'Passwords do not match yet.' : null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await api.post('/admin/password/reset', {
        token,
        email,
        password,
        password_confirmation: confirm,
      });
      setDone(true);
      if (typeof onCompleted === 'function') onCompleted();
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Unable to reset the password. The link may have expired.');
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
              {branding.logoUrl ? <img src={branding.logoUrl} alt="" className="brand-logo-img" /> : <KeyRound size={24} />}
            </div>
            <div className="brand-text">
              <h1 className="brand-title">{branding.siteName || 'OmniVote'}</h1>
              <span className="brand-subtitle">SET A NEW PASSWORD</span>
            </div>
          </div>
          <p className="login-description">
            {done
              ? 'Your password was reset successfully.'
              : `Choose a new password for ${email || 'your account'}.`}
          </p>
        </div>

        {error && (
          <div className="login-error" style={{ color: '#dc2626', marginBottom: '12px', fontSize: '0.9rem' }}>
            {error}
          </div>
        )}

        {done ? (
          <div className="forgot-success" role="status">
            <CheckCircle2 size={32} color="#10b981" />
            <p>You can now sign in with your new password. If you use two-factor authentication, verify with your code as usual.</p>
            <a className="submit-btn reset-signin-link" href="/">
              Sign In to Dashboard
            </a>
          </div>
        ) : (
          <form className="login-form" onSubmit={handleSubmit}>
            <div className="form-group">
              <label className="form-label">New Password</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
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
                  onClick={() => setShowPassword(!showPassword)}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {passwordIssue && (
                <p className="forgot-password-hint">{passwordIssue}</p>
              )}
            </div>

            <div className="form-group">
              <label className="form-label">Confirm New Password</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
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
                  onClick={() => setShowConfirm(!showConfirm)}
                >
                  {showConfirm ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {confirmIssue && (
                <p className="forgot-password-hint">{confirmIssue}</p>
              )}
            </div>

            <button type="submit" className="submit-btn" disabled={loading || Boolean(passwordIssue) || Boolean(confirmIssue)}>
              {loading ? 'Saving...' : 'Save New Password'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}