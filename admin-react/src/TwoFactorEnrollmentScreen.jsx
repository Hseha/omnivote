import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import TwoFactorSetup from './TwoFactorSetup';
import { useAuth } from './lib/AuthContext';
import './TwoFactorEnrollmentScreen.css';

/**
 * Full-screen forced-enrollment interstitial. When Security → 2FA Required is
 * ON, a panel staff member who is not yet enrolled is locked out of every
 * permission-gated route (EnsurePermission) until they complete enrollment.
 * This screen gives them the one thing they can still do — set up 2FA — and
 * reloads the profile into the fully-authenticated shell after success.
 */
export default function TwoFactorEnrollmentScreen() {
  const { logout, refreshUser } = useAuth();
  const [done, setDone] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [error, setError] = useState('');

  const handleEnabled = async () => {
    setDone(true);
    setError('');
    // Re-read /me now that two_factor_enabled is true; the shell drops out of
    // this interstitial once `user.two_factor_enabled` flips.
    try {
      await refreshUser();
    } catch {
      /* refreshUser re-syncs on the next bootstrap if this fails */
    }
  };

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      setLoggingOut(false);
    }
  };

  return (
    <div className="twofa-screen">
      <div className="twofa-screen-card">
        <div className="twofa-screen-icon"><ShieldCheck size={28} /></div>
        {done ? (
          <>
            <h2 className="twofa-screen-title">Almost there.</h2>
            <p className="twofa-screen-desc">
              Two-factor authentication is set up. Reloading the dashboard…
            </p>
          </>
        ) : (
          <>
            <h2 className="twofa-screen-title">Two-Factor Authentication Required</h2>
            <p className="twofa-screen-desc">
              Your administrator requires two-factor authentication. Scan the code below on
              the next step to secure your account before using the console.
            </p>

            {error && <div className="twofa-screen-error">{error}</div>}

            <TwoFactorSetup onEnabled={handleEnabled} />

            <button type="button" className="twofa-screen-logout" onClick={handleLogout} disabled={loggingOut}>
              {loggingOut ? 'Signing out…' : 'Sign out instead'}
            </button>
          </>
        )}
      </div>
    </div>
  );
}