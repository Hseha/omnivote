import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { setAuthHandlers } from './api';
import * as authService from './auth';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const clearSession = useCallback(() => {
    setUser(null);
  }, []);

  useEffect(() => {
    setAuthHandlers({
      unauthorized: () => clearSession(),
      forbidden: () => setError(null),
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
    try {
      const loggedInUser = await authService.login({ email, password });
      setUser(loggedInUser);
      return loggedInUser;
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Login failed');
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    setLoading(true);
    try {
      await authService.logoutRequest();
    } catch {
      // Ignore failures from the server; always clear local state.
    } finally {
      clearSession();
      setLoading(false);
    }
  }, [clearSession]);

  const refreshUser = useCallback(async () => {
    const current = await authService.getCurrentUser();
    setUser(current);
    return current;
  }, []);

  const value = useMemo(
    () => ({ user, ready, loading, error, login, logout, refreshUser }),
    [user, ready, loading, error, login, logout, refreshUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider');
  return ctx;
}
