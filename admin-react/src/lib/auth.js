import api, { getCsrfCookie } from './api';

// Temporary in-memory display cache for the authenticated admin profile.
// This is NOT an authorization boundary — authorization lives in HttpOnly
// session cookies on the server. Never persist credentials or tokens here.
const DISPLAY_CACHE_KEY = 'omnivote_user_display';

export function readDisplayUser() {
  try {
    const raw = localStorage.getItem(DISPLAY_CACHE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export function writeDisplayUser(user) {
  try {
    if (user) localStorage.setItem(DISPLAY_CACHE_KEY, JSON.stringify(user));
    else localStorage.removeItem(DISPLAY_CACHE_KEY);
  } catch {
    // ignore storage failures
  }
}

export async function login({ email, password }) {
  await getCsrfCookie();

  const { data } = await api.post('/admin/login', { email, password });

  const user = data.user || data;
  writeDisplayUser(user);
  return user;
}

export async function getCurrentUser() {
  const { data } = await api.get('/admin/me');
  return data.user || data;
}

export async function logoutRequest() {
  try {
    await api.post('/admin/logout');
  } finally {
    writeDisplayUser(null);
  }
}
