import { useState } from 'react';
import { Vote, Lock, Mail, Eye, EyeOff, ShieldCheck, KeyRound, LoaderCircle } from 'lucide-react';
import { useAuth } from './lib/AuthContext';
import { useBranding } from './lib/branding';
import './AdminLogin.css';

/* Translate backend responses into non-technical, role-neutral guidance so a
 * Teacher or SSG President (who may not be technical) sees a clear next step
 * instead of a raw API message. */
function friendlyLoginError(err) {
  const status = err?.response?.status;
  const msg = err?.response?.data?.message;

  if (status === 401) return 'Incorrect email or password. Check your details and try again.';
  if (status === 423) return msg || 'This account is temporarily locked. Try again in a few minutes.';
  if (status === 403 && msg === 'Your account has been disabled.') {
    return 'This account has been disabled. Contact an administrator to get it reactivated.';
  }
  if (status === 403) return 'This account is not enrolled to use the console. Contact an administrator.';
  if (status === 429) return msg || 'Too many attempts. Wait a moment and try again.';
  if (err?.code === 'ERR_NETWORK') return "Can't reach the server. Check your connection and try again.";
  return msg || err?.message || 'Something went wrong. Please try again.';
}

export default function AdminLogin({ onForgot }) {
  const { login, verifyTwoFactor, cancelTwoFactor, requiresTwoFactor } = useAuth();
  const branding = useBranding();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      // login() obtains the Sanctum CSRF cookie, posts to /api/admin/login
      // with credentials, then stores the profile. The HttpOnly session cookie
      // set by the server is the real authorization boundary.
      await login({ email, password });
    } catch (err) {
      setError(friendlyLoginError(err));
    } finally {
      setLoading(false);
    }
  };

  const handleTwoFactorSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      // Step 2 of a 2FA login: verify the TOTP code (or one-time recovery
      // code) against the pending server-side session. On success the full
      // admin session + token is minted and the panel loads.
      await verifyTwoFactor(code.trim());
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Invalid code');
      setCode('');
    } finally {
      setLoading(false);
    }
  };

  const goBack = () => {
    cancelTwoFactor();
    setCode('');
    setError('');
  };

  return (
    <div className="login-page">
      <div className="login-card">
        <div className="login-header">
          <div className="brand-wrapper">
            <div className="brand-icon-box">
              {branding.logoUrl ? <img src={branding.logoUrl} alt="" className="brand-logo-img" /> : <Vote size={24} />}
            </div>
            <div className="brand-text">
              <h1 className="brand-title">{branding.siteName || 'OmniVote'}</h1>
              <span className="brand-subtitle">ELECTION CONSOLE</span>
            </div>
          </div>
          {requiresTwoFactor ? (
            <>
              <h2 className="welcome-title">Welcome back</h2>
              <p className="login-description">Enter the code from your authenticator app</p>
            </>
          ) : (
            <>
              <h2 className="welcome-title">Welcome back</h2>
              <p className="login-description">Enter your credentials to continue</p>
            </>
          )}
        </div>

        {error && (
          <div className="login-error" role="alert" aria-live="polite" style={{ color: '#dc2626', marginBottom: '12px', fontSize: '0.9rem' }}>
            {error}
          </div>
        )}

        {requiresTwoFactor ? (
          <form className="login-form" onSubmit={handleTwoFactorSubmit}>
            <div className="form-group">
              <label htmlFor="login-code" className="form-label">Authentication Code</label>
              <div className="input-wrapper">
                <KeyRound className="input-icon" size={18} />
                <input
                  id="login-code"
                  type="text"
                  className="form-input"
                  placeholder="6-digit code"
                  autoComplete="one-time-code"
                  autoFocus
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  required
                />
              </div>
              <p className="two-factor-hint">
                Lost your app? Enter one of your account&apos;s recovery codes instead.
              </p>
            </div>

            <button type="submit" className="submit-btn" disabled={loading}>
              {loading ? <><LoaderCircle className="spinner" size={16} /> Verifying...</> : 'Verify & Sign In'}
            </button>
            <button type="button" className="back-to-login-btn" onClick={goBack} disabled={loading}>
              Back to sign in
            </button>
          </form>
        ) : (
          <form className="login-form" onSubmit={handleSubmit}>
            <div className="form-group">
              <label htmlFor="login-email" className="form-label">Email Address</label>
              <div className="input-wrapper">
                <Mail className="input-icon" size={18} />
                <input
                  id="login-email"
                  type="email"
                  className="form-input"
                  placeholder="you@school.edu"
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  disabled={loading}
                  required
                />
              </div>
            </div>

            <div className="form-group">
              <div className="label-row">
                <label htmlFor="login-password" className="form-label">Password</label>
                <a
                  href="#forgot"
                  className="forgot-link"
                  onClick={(ev) => {
                    ev.preventDefault();
                    if (typeof onForgot === 'function') onForgot();
                  }}
                >
                  Forgot?
                </a>
              </div>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
                  id="login-password"
                  type={showPassword ? 'text' : 'password'}
                  className="form-input"
                  placeholder="••••••••"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={loading}
                  required
                />
                <button
                  type="button"
                  className="toggle-password-btn"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  aria-pressed={showPassword}
                  onClick={() => setShowPassword(!showPassword)}
                  tabIndex={0}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </div>

            <button type="submit" className="submit-btn" disabled={loading}>
              {loading ? <><LoaderCircle className="spinner" size={16} /> Signing In...</> : 'Sign In to Dashboard'}
            </button>
          </form>
        )}

        <div className="login-footer">
          <ShieldCheck size={16} className="security-icon" />
          <span>Encrypted End-to-End System Access</span>
        </div>
      </div>
    </div>
  );
}