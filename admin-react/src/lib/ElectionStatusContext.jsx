import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import api from './api';

const POLL_INTERVAL_MS = 30000;

const ElectionStatusContext = createContext(null);

/*
 * Single source of truth for the live election phase across every admin view.
 *
 * Polls GET /election/status every 30 s so the header badge, dashboard phase
 * card, and Results gating all flip the moment a configured window boundary is
 * crossed — without each screen running its own poll (the shared Header used
 * to be the only poller, and even then only once on mount).
 */
export function ElectionStatusProvider({ children }) {
  const [phase, setPhase] = useState(null);
  const [lastUpdated, setLastUpdated] = useState(null);
  const frameRef = useRef(0);

  const refresh = useCallback(async () => {
    const frame = frameRef.current + 1;
    frameRef.current = frame;
    try {
      const res = await api.get('/election/status');
      const data = res.data?.data ?? res.data ?? {};
      // Ignore stale responses so a slow fetch can't clobber a newer one.
      // A null-ish phase is a valid state ("Not Configured") and must replace
      // a previously configured one after the admin clears the windows.
      if (frame === frameRef.current) {
        setPhase(data.phase ?? null);
        setLastUpdated(new Date());
      }
    } catch {
      // Endpoint unreachable: keep the last known phase so the badge and
      // results gating show a stale-but-safe value instead of flashing.
    }
  }, []);

  useEffect(() => {
    let alive = true;
    const poll = () => { if (alive) refresh(); };
    const first = setTimeout(poll, 0);
    const id = setInterval(poll, POLL_INTERVAL_MS);
    return () => {
      alive = false;
      clearTimeout(first);
      clearInterval(id);
    };
  }, [refresh]);

  return (
    <ElectionStatusContext.Provider value={{ phase, lastUpdated, refresh }}>
      {children}
    </ElectionStatusContext.Provider>
  );
}

// Same provider+hook pairing as AuthContext — matching the repo convention.
// eslint-disable-next-line react-refresh/only-export-components
export function useElectionStatus() {
  return useContext(ElectionStatusContext);
}