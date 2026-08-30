import axios from 'axios';

// If a request needs CSRF protection (any non-safe method) and we have a
// session cookie, ensure the XSRF token has been fetched and attached.
let csrfPromise = null;

export const API_BASE_URL = import.meta.env?.VITE_API_BASE_URL || 'http://127.0.0.1:8000';

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

const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
  withCredentials: true,
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
