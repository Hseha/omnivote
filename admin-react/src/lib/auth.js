import api, { getCsrfCookie, resetCsrfCookie } from './api';

// Temporary in-memory display cache for the authenticated admin profile.
// This is NOT an authorization boundary — authorization lives in HttpOnly
// session cookies on the server. Never persist credentials or tokens here.
const DISPLAY_CACHE_KEY = 'omnivote_user_display';

// Per-account avatar cache. The avatar column/endpoint on the backend is still
// pending (Cline), so a chosen avatar is persisted under this key to survive
// reloads on this device/browser until the server becomes the source of truth.
function avatarStorageKey(user) {
  const id = user?.id ?? 'anonymous';
  return `omnivote:admin:avatar:${id}`;
}

export function readLocalAvatar(user) {
  try {
    return localStorage.getItem(avatarStorageKey(user)) || null;
  } catch {
    return null;
  }
}

export function writeLocalAvatar(user, url) {
  try {
    if (url) localStorage.setItem(avatarStorageKey(user), url);
    else localStorage.removeItem(avatarStorageKey(user));
  } catch {
    /* private mode / storage unavailable — avatar just stays in-memory */
  }
}

/* Fall back to the per-account cached avatar ONLY when the server has not yet
 * deployed the avatar column (the `avatar_url` key is absent from its user
 * payload). Once the column exists the server value is authoritative —
 * including an explicit null after the user clears their avatar, which must
 * not resurrect an old device cache. */
function withLocalAvatar(user) {
  if (!user) return user;
  if (Object.prototype.hasOwnProperty.call(user, 'avatar_url')) return user;
  const local = readLocalAvatar(user);
  return local ? { ...user, avatar_url: local } : user;
}

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

  const user = withLocalAvatar(mergeTwoFactor(data.user || data, data?.two_factor));

  // Step 1 of a 2FA flow: password accepted, but the session token is only
  // issued after POST /login/2fa. Do NOT cache a session here — that only
  // happens once the full sign-in completes.
  if (data.requires_two_factor) {
    return { requiresTwoFactor: true, user };
  }

  writeDisplayUser(user);
  return { requiresTwoFactor: false, user };
}

export async function completeTwoFactorLogin({ code }) {
  const { data } = await api.post('/admin/login/2fa', { code });

  const user = withLocalAvatar(mergeTwoFactor(data.user || data, data?.two_factor));
  writeDisplayUser(user);
  return user;
}

/** Merge the /me-style `two_factor: { enabled, required }` envelope onto a user. */
function mergeTwoFactor(user, envelope = null) {
  return {
    ...user,
    two_factor_enabled: Boolean(user.two_factor_enabled ?? envelope?.enabled),
    two_factor_required: Boolean(envelope?.required),
  };
}

export async function getTwoFactorStatus() {
  const { data } = await api.get('/admin/2fa/status');
  return data;
}

export async function prepareTwoFactor({ password }) {
  const { data } = await api.post('/admin/2fa/prepare', { password });
  return data;
}

export async function confirmTwoFactor({ code }) {
  const { data } = await api.post('/admin/2fa/confirm', { code });
  return data;
}

export async function disableTwoFactor({ password, code }) {
  const { data } = await api.post('/admin/2fa/disable', { password, code });
  return data;
}

export async function getCurrentUser() {
  const { data } = await api.get('/admin/me');
  return withLocalAvatar(mergeTwoFactor(data.user || data, data?.two_factor));
}

export async function logoutRequest() {
  try {
    await api.post('/admin/logout');
  } finally {
    // The server invalidates the session and rotates the CSRF token on
    // logout (session()->invalidate() + regenerateToken()). Drop the cached
    // csrf-cookie request so the next login fetches a fresh XSRF-TOKEN
    // cookie instead of posting a stale one and failing with 419.
    resetCsrfCookie();
    writeDisplayUser(null);
  }
}
