import { useState } from 'react';
import { ArrowLeft, Mail } from 'lucide-react';
import api from './lib/api';
import { API_BASE_URL } from './lib/api';
import { useBranding } from './lib/branding';
import OmniVoteMark from './components/OmniVoteMark';
import './AdminLogin.css';

/*
 * Forgot-password step 1 (public screen): enter the account email and the API
 * emails a reset link to the SPA's Reset Password screen. The response is
 * deliberately generic (no account fingerprinting), matching the backend.
 */
export default function ForgotPassword({ onBack }) {
  const branding = useBranding();
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await api.post('/admin/password/email', { email });
      setSent(true);
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Unable to send the reset link. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-card">
        <div className="login-header">
          <a
            href="#"
            className="forgot-back-link"
            aria-label="Back to sign in"
            onClick={(ev) => {
              ev.preventDefault();
              if (typeof onBack === 'function') onBack();
            }}
          >
            <ArrowLeft size={16} />
          </a>
          <div className="brand-wrapper">
            <div className="brand-icon-box">
              {branding.logoUrl ? <img src={branding.logoUrl} alt="" className="brand-logo-img" /> : <OmniVoteMark className="brand-logo-img" />}
            </div>
            <div className="brand-text">
              <h1 className="brand-title">{branding.siteName || 'OmniVote'}</h1>
              <span className="brand-subtitle">RECOVER ACCESS</span>
            </div>
          </div>
          <p className="login-description">Enter the email for your panel account and we&apos;ll send a reset link.</p>
        </div>

        {error && (
          <div className="login-error" style={{ color: '#dc2626', marginBottom: '12px', fontSize: '0.9rem' }}>
            {error}
          </div>
        )}

        {sent ? (
          <div className="forgot-success" role="status">
            <p>If an account exists for that email, a reset link is on its way. Check your inbox (and spam).</p>
            <p className="forgot-success-hint">
              Until SMTP is configured, the link is also written to <code>storage/logs/laravel.log</code> on the server.
            </p>
            <button type="button" className="submit-btn" onClick={() => onBack && onBack()}>
              Back to sign in
            </button>
          </div>
        ) : (
          <form className="login-form" onSubmit={handleSubmit}>
            <div className="form-group">
              <label className="form-label">Email Address</label>
              <div className="input-wrapper">
                <Mail className="input-icon" size={18} />
                <input
                  type="email"
                  className="form-input"
                  placeholder="you@school.edu"
                  autoComplete="email"
                  autoFocus
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                />
              </div>
            </div>

            <button type="submit" className="submit-btn" disabled={loading}>
              {loading ? 'Sending...' : 'Send Reset Link'}
            </button>
            <button type="button" className="back-to-login-btn" onClick={() => onBack && onBack()} disabled={loading}>
              Back to sign in
            </button>
          </form>
        )}

        <div className="login-footer">
          <span style={{ fontSize: '0.75rem', color: 'var(--text-muted, #64748b)' }}>
            Reset links expire after 60 minutes{API_BASE_URL ? '' : ''}.
          </span>
        </div>
      </div>
    </div>
  );
}