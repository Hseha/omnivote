import axios from 'axios';

// If a request needs CSRF protection (any non-safe method) and we have a
// session cookie, ensure the XSRF token has been fetched and attached.
let csrfPromise = null;

// Bumped by every reset. A `getCsrfCookie()` call captures the generation it
// started under and, once its fetch settles, only keeps the cached promise if
// the generation still matches. Without this, a fetch that was already in
// flight when a reset happened can resolve *after* the reset and re-seed
// `csrfPromise` with a token belonging to the previous session — which is what
// produces the logout → login → 419 loop.
let csrfGeneration = 0;

/**
 * Base URL for the Laravel API. In production the admin panel is served from
 * the same origin as the API (so an empty string yields correct relative URLs),
 * but when served cross-origin (e.g. a separate web server or CDN), set
 * VITE_API_BASE_URL in a .env file to the absolute API origin.
 */
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';

/**
 * True when this bundle is served from the SSG President console mount (/ssg/)
 * rather than the staff admin mount (/admin/). One build backs both: nginx
 * leaves /ssg/ and /api/ssg/console/ out of the staff HTTP Basic gate so the
 * elected student President can sign in without a staff credential. Detecting
 * the mount here lets the rest of the app keep using its /admin/... call sites
 * — the request interceptor below rewrites them to the console prefix.
 */
export const IS_SSG_CONSOLE =
  typeof window !== 'undefined' && /^\/ssg(\/|$)/.test(window.location.pathname);

/**
 * Hard ceiling on every API call. Without it a slow or silently dropped
 * connection can leave a request — notably the boot-time `/admin/me` session
 * check — hanging indefinitely, which the shell renders as a blank page.
 * 15 s is comfortably above a healthy round-trip but short enough that the
 * UI always reaches a real state (loaded or error) instead of freezing.
 */
export const API_TIMEOUT_MS = 15000;

/**
 * Fetches the CSRF cookie (and therefore the XSRF-TOKEN cookie) that Laravel
 * Sanctum issues to first-party SPA clients. The token is then read by axios
 * (via `xsrfCookieName`) and attached to the next stateful request.
 */
export function getCsrfCookie() {
  const generation = csrfGeneration;

  if (!csrfPromise) {
    const pending = axios
      .get(`${API_BASE_URL}/sanctum/csrf-cookie`, { withCredentials: true })
      .catch((error) => {
        // Allow retry on the next call; a missing CSRF cookie is handled by
        // the response interceptor with a 419/401. Only clear the cache if
        // this fetch is still the current one — a newer fetch may already own it.
        if (csrfPromise === pending) csrfPromise = null;
        throw error;
      });

    csrfPromise = pending;

    // If a reset landed while this request was in flight, the cookie it just
    // fetched belongs to a session that no longer exists. Drop it so the next
    // caller starts clean instead of replaying a stale X-XSRF-TOKEN header.
    pending.finally(() => {
      if (csrfGeneration !== generation && csrfPromise === pending) {
        csrfPromise = null;
      }
    });
  }

  return csrfPromise;
}

/**
 * Drops the cached CSRF-cookie request so the next mutating request fetches a
 * fresh XSRF-TOKEN cookie. Laravel rotates the CSRF token whenever the session
 * is invalidated or regenerated (logout, expiry, session()->regenerateToken()),
 * so keeping the cached promise across a session boundary makes the SPA replay
 * a stale X-XSRF-TOKEN header and loop on 419 TokenMismatch responses.
 */
export function resetCsrfCookie() {
  // Advancing the generation invalidates any in-flight fetch started before
  // this point, so it cannot re-seed the cache when it settles.
  csrfGeneration += 1;
  csrfPromise = null;
}

const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
  timeout: API_TIMEOUT_MS,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
});

/**
 * Resolves auth/phase errors centrally. `onUnauthorized` is provided by the
 * auth provider so a single 401 can force a clean logout; `onForbidden` lets
 * callers surface the phase/role message. Returns a normalized error.
 */
export let onUnauthorizedHandler = null;
export let onForbiddenHandler = null;

export function setAuthHandlers({ unauthorized, forbidden }) {
  onUnauthorizedHandler = unauthorized;
  onForbiddenHandler = forbidden;
}

function isAuthenticatingRequest(config) {
  const url = config?.url || '';
  return url.includes('/login') || url.includes('/sanctum/csrf-cookie');
}

// Attach CSRF protection before mutating requests that can change state.
api.interceptors.request.use(
  async (config) => {
    const method = (config.method || 'get').toLowerCase();
    const isCsrfEndpoint = config.url?.includes('/sanctum/csrf-cookie');

    // On the SSG console mount, redirect the staff-shaped call sites to the
    // dedicated console API surface (e.g. /admin/ssg/officers ->
    // /ssg/console/ssg/officers). Absolute paths that do not start with
    // /admin (e.g. the setup endpoint) pass through untouched.
    if (IS_SSG_CONSOLE && config.url?.startsWith('/admin')) {
      config.url = config.url.replace(/^\/admin/, '/ssg/console');
    }

    if (isCsrfEndpoint) {
      return config;
    }

    if (['post', 'put', 'patch', 'delete'].includes(method)) {
      try {
        await getCsrfCookie();
      } catch {
        // Ignore here; the call itself will surface the real error.
      }
    }

    return config;
  },
  (error) => Promise.reject(error),
);

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;

    // 401/419 mean the server-side session or CSRF token no longer matches
    // what the browser holds (logout, session expiry, token rotation). Drop
    // the cached csrf-cookie request so the next mutating request fetches a
    // fresh XSRF-TOKEN cookie instead of replaying a stale token.
    if (status === 401 || status === 419) {
      resetCsrfCookie();
    }

    if (status === 401 && !isAuthenticatingRequest(error.config)) {
      if (onUnauthorizedHandler) onUnauthorizedHandler();
    }

    if (status === 419) {
      if (onUnauthorizedHandler) onUnauthorizedHandler();
    }

    if (status === 403 && onForbiddenHandler) {
      onForbiddenHandler(error.response.data);
    }

    return Promise.reject(error);
  },
);

export default api;
