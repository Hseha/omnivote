import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { setAuthHandlers } from './api';
import * as authService from './auth';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  // True after step 1 of a 2FA login: password accepted, TOTP code still due.
  // The SPA swaps the login form for the code entry screen until the full
  // sign-in completes.
  const [requiresTwoFactor, setRequiresTwoFactor] = useState(false);

  const clearSession = useCallback(() => {
    setUser(null);
  }, []);

  useEffect(() => {
    setAuthHandlers({
      unauthorized: () => clearSession(),
      forbidden: (data) => {
        // A disabled account gets 403 from every panel call (EnsurePermission
        // gate). Treat it like an expired session: drop the display cache and
        // bounce to the login screen instead of leaving a half-working panel.
        if (data?.message === 'Your account has been disabled.') {
          authService.writeDisplayUser(null);
          clearSession();
          return;
        }
        setError(data?.message || 'Access forbidden');
      },
    });

    // Validate any cached session against the server on app load.
    const bootstrap = async () => {
      try {
        const cached = authService.readDisplayUser();
        if (!cached) {
          setReady(true);
          return;
        }
        try {
          const current = await authService.getCurrentUser();
          setUser(current);
        } catch {
          // Session cookie is invalid/expired -> drop the display cache.
          authService.writeDisplayUser(null);
          setUser(null);
        }
      } finally {
        setReady(true);
      }
    };

    bootstrap();
  }, [clearSession]);

  const login = useCallback(async ({ email, password }) => {
    setLoading(true);
    setError(null);
    setRequiresTwoFactor(false);
    try {
      const result = await authService.login({ email, password });
      if (result.requiresTwoFactor) {
        setRequiresTwoFactor(true);
        return result.user;
      }
      setUser(result.user);
      return result.user;
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Login failed');
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const verifyTwoFactor = useCallback(async (code) => {
    setLoading(true);
    setError(null);
    try {
      const verifiedUser = await authService.completeTwoFactorLogin({ code });
      setRequiresTwoFactor(false);
      setUser(verifiedUser);
      return verifiedUser;
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Invalid code');
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const cancelTwoFactor = useCallback(() => {
    setRequiresTwoFactor(false);
  }, []);

  const logout = useCallback(async () => {
    setLoading(true);
    try {
      await authService.logoutRequest();
    } catch {
      // Ignore failures from the server; always clear local state.
    } finally {
      clearSession();
      setRequiresTwoFactor(false);
      setLoading(false);
    }
  }, [clearSession]);

  const refreshUser = useCallback(async () => {
    const current = await authService.getCurrentUser();
    setUser(current);
    return current;
  }, []);

  // Optimistically reflect a just-saved avatar across the shell (Header,
  // dashboard) and the display cache so the UI updates instantly. The
  // per-account device cache is written by the caller (it knows the user).
  const updateUserAvatar = useCallback((avatarUrl) => {
    setUser((prev) => {
      if (!prev || prev.avatar_url === avatarUrl) return prev;
      const next = { ...prev, avatar_url: avatarUrl };
      authService.writeDisplayUser(next);
      return next;
    });
  }, []);

  const value = useMemo(
    () => ({
      user,
      ready,
      loading,
      error,
      requiresTwoFactor,
      login,
      verifyTwoFactor,
      cancelTwoFactor,
      logout,
      refreshUser,
      updateUserAvatar,
    }),
    [user, ready, loading, error, requiresTwoFactor, login, verifyTwoFactor, cancelTwoFactor, logout, refreshUser, updateUserAvatar],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider');
  return ctx;
}
