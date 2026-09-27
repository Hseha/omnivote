import { useState, useEffect, useCallback } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import * as authService from './lib/auth';
import './TwoFactorSetup.css';

/**
 * Shared self-service TOTP enrollment surface.
 *
 * Modes:
 *  - enrolled   -> shows current status + offers a guarded disable flow
 *  - enroll     -> prepares a secret, renders the QR/otpauth URI, then
 *                  confirms with a live code and displays the recovery codes
 */
export default function TwoFactorSetup({ variant = 'panel', onEnabled, onDisabled }) {
  const [status, setStatus] = useState(null);
  const [prepared, setPrepared] = useState(null);
  const [recoveryCodes, setRecoveryCodes] = useState([]);
  const [step, setStep] = useState('idle'); // idle | enroll | codes
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [notify, setNotify] = useState('');

  const refreshStatus = useCallback(async () => {
    try {
      const s = await authService.getTwoFactorStatus();
      setStatus(s);
      return s;
    } catch {
      setError('Could not load your 2FA status.');
      return null;
    }
  }, []);

  const refreshStatusSync = useCallback(() => {
    authService.getTwoFactorStatus().then(setStatus).catch(() => setError('Could not load your 2FA status.'));
  }, []);

  useEffect(() => {
    refreshStatusSync();
  }, [refreshStatusSync]);

  const startEnrollment = async (e) => {
    e?.preventDefault();
    setLoading(true);
    setError('');
    try {
      const p = await authService.prepareTwoFactor({ password });
      setPrepared(p);
      setStep('enroll');
      setCode('');
      setPassword('');
    } catch (err) {
      setError(err.response?.data?.message || 'Could not start setup.');
    } finally {
      setLoading(false);
    }
  };

  const confirmEnrollment = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const res = await authService.confirmTwoFactor({ code: code.trim() });
      setRecoveryCodes(res.recovery_codes || []);
      setStep('codes');
      setPrepared(null);
      setCode('');
      await refreshStatus();
      if (typeof onEnabled === 'function') onEnabled();
    } catch (err) {
      setError(err.response?.data?.message || 'Invalid code.');
    } finally {
      setLoading(false);
    }
  };

  const cancelEnrollment = () => {
    setStep('idle');
    setPrepared(null);
    setCode('');
    setError('');
  };

  const disableTwoFactor = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await authService.disableTwoFactor({ password, code: code.trim() });
      setCode('');
      setPassword('');
      setStep('idle');
      setNotify('Two-factor authentication has been disabled.');
      await refreshStatus();
      if (typeof onDisabled === 'function') onDisabled();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not disable 2FA.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!notify) return undefined;
    const t = setTimeout(() => setNotify(''), 4000);
    return () => clearTimeout(t);
  }, [notify]);

  const enabled = Boolean(status?.enabled);

  if (step === 'codes') {
    return (
      <div className={`twofa ${variant}`}>
        <div className="twofa-codes">
          <h4 className="twofa-title">Recovery Codes</h4>
          <p className="twofa-desc">
            Each code can be used once if you lose your authenticator. Store these
            somewhere safe now — they won&apos;t be shown again.
          </p>
          <ul className="twofa-code-list">
            {recoveryCodes.map((c) => (
              <li key={c} className="twofa-code">
                {c}
              </li>
            ))}
          </ul>
          <button type="button" className="twofa-btn-primary" onClick={cancelEnrollment}>
            Done
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className={`twofa ${variant}`}>
      {notify && <div className="twofa-notify">{notify}</div>}
      {error && <div className="twofa-error">{error}</div>}

      {status !== null && enabled && step !== 'enroll' && (
        <div className="twofa-card">
          <div className="twofa-row">
            <div>
              <h4 className="twofa-title">Two-Factor Authentication</h4>
              <p className="twofa-desc" style={{ color: '#10b981' }}>
                Enabled — your account requires an authentication code at sign-in.
              </p>
            </div>
            <span className="twofa-badge twofa-badge-on">ON</span>
          </div>
          <div className="twofa-recovery-note">
            {status.recovery_codes_remaining > 0
              ? `${status.recovery_codes_remaining} recovery ${status.recovery_codes_remaining === 1 ? 'code remains' : 'codes remain'}.`
              : 'No recovery codes remain — enroll again if you lose your device access.'}
          </div>
          {status.required ? (
            <p className="twofa-desc twofa-enforced-note">
              2FA is enforced by your administrator and cannot be disabled.
            </p>
          ) : (
            <form className="twofa-form" onSubmit={disableTwoFactor}>
              <input
                className="twofa-input"
                type="password"
                placeholder="Current password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
              />
              <input
                className="twofa-input"
                placeholder="6-digit code"
                inputMode="numeric"
                autoComplete="one-time-code"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
              />
              <button type="submit" className="twofa-btn-danger" disabled={loading}>
                {loading ? 'Disabling...' : 'Disable 2FA'}
              </button>
            </form>
          )}
        </div>
      )}

      {(!enabled || step === 'enroll') && (
        <div className="twofa-card">
          <h4 className="twofa-title">Two-Factor Authentication</h4>
          <p className="twofa-desc">
            Add a free authenticator app (Google Authenticator, Authy, 1Password) and
            scan the QR code to start pairing.
          </p>

          {step === 'enroll' && prepared ? (
            <div className="twofa-enroll">
              <div className="twofa-qr-wrap">
                <QRCodeSVG value={prepared.otpauth_uri} size={180} />
              </div>
              <p className="twofa-desc twofa-secret">
                Can&apos;t scan? Enter this key manually in your app:
              </p>
              <code className="twofa-secret-code">{prepared.secret}</code>
              <form className="twofa-form" onSubmit={confirmEnrollment}>
                <input
                  className="twofa-input twofa-code-input"
                  placeholder="Enter 6-digit code"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  required
                  autoFocus
                />
                <div className="twofa-actions">
                  <button type="button" className="twofa-btn-secondary" onClick={cancelEnrollment} disabled={loading}>
                    Cancel
                  </button>
                  <button type="submit" className="twofa-btn-primary" disabled={loading}>
                    {loading ? 'Verifying...' : 'Confirm'}
                  </button>
                </div>
              </form>
            </div>
          ) : (
            <form className="twofa-form" onSubmit={startEnrollment}>
              <input
                className="twofa-input"
                type="password"
                placeholder="Re-enter your password to begin"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
              />
              <button type="submit" className="twofa-btn-primary" disabled={loading}>
                {loading ? 'Preparing...' : 'Set Up 2FA'}
              </button>
            </form>
          )}
        </div>
      )}
    </div>
  );
}