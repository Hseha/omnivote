import axios from 'axios';

// If a request needs CSRF protection (any non-safe method) and we have a
// session cookie, ensure the XSRF token has been fetched and attached.
let csrfPromise = null;

/**
 * Base URL for the Laravel API. In production the admin panel is served from
 * the same origin as the API (so an empty string yields correct relative URLs),
 * but when served cross-origin (e.g. a separate web server or CDN), set
 * VITE_API_BASE_URL in a .env file to the absolute API origin.
 */
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';

/**
 * Fetches the CSRF cookie (and therefore the XSRF-TOKEN cookie) that Laravel
 * Sanctum issues to first-party SPA clients. The token is then read by axios
 * (via `xsrfCookieName`) and attached to the next stateful request.
 */
export function getCsrfCookie() {
  if (!csrfPromise) {
    csrfPromise = axios
      .get(`${API_BASE_URL}/sanctum/csrf-cookie`, { withCredentials: true })
      .catch((error) => {
        // Allow retry on the next call; a missing CSRF cookie is handled by
        // the response interceptor with a 419/401.
        csrfPromise = null;
        throw error;
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
  csrfPromise = null;
}

const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
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
