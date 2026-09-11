# API Integration Fixes Applied

This document records all fixes applied to the client repositories (Flutter and React Admin) and the backend Laravel API for the Tailscale production environment (`http://100.84.115.25:8000`).

---

## Issue Summary

| # | Issue | Severity | Status |
|---|-------|----------|--------|
| 1 | Flutter base URL defaulted to Android emulator loopback (`10.0.2.2`) instead of production Tailscale IP | **Critical** | ✅ Fixed |
| 2 | React Admin had no environment variable support for API base URL | **Medium** | ✅ Fixed |
| 3 | Vite dev proxy targeted `localhost:8000` instead of Tailscale IP | **Low** | ✅ Fixed |
| 4 | Backend `APP_URL` was `localhost:8000`, breaking CORS + Sanctum stateful domains for production | **Critical** | ✅ Fixed |
| 5 | No `.env.example` documentation for React Admin env variables | **Low** | ✅ Fixed |

---

## Fixes Applied

### Fix 1: Flutter Base URL (Critical)

**File:** `user-flutter/lib/core/constants/api_constants.dart`

**Problem:** The hardcoded default base URL was `http://10.0.2.2:8000/api` — the Android emulator loopback address. A production build run without `--dart-define=API_BASE_URL=...` would silently fail to connect to the Tailscale server.

```dart
// Before
static const String baseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8000/api',
);

// After
static const String baseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://100.84.115.25:8000/api',
);
```

**Impact:** Production builds now target the Tailscale IP by default. The `--dart-define` override still works for local development.

---

### Fix 2: React Admin Environment Variable Support (Medium)

**File:** `admin-react/src/lib/api.js`

**Problem:** `API_BASE_URL` was a hardcoded empty string (`''`). This meant the admin panel could only work when served from the same origin as the API — there was no way to configure it for cross-origin deployment without modifying source code.

```javascript
// Before
export const API_BASE_URL = '';

// After
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';
```

**Impact:**
- **Default (env var not set):** `API_BASE_URL = ''` → axios uses relative `/api/*` URLs (same-origin behavior, unchanged)
- **Production cross-origin:** Set `VITE_API_BASE_URL=http://100.84.115.25:8000` in `.env` to target the Tailscale server explicitly

---

### Fix 3: Vite Proxy Targets (Low — development only)

**File:** `admin-react/vite.config.js`

**Problem:** The Vite development server proxy for `/api` and `/sanctum` routes pointed to `http://localhost:8000`, which won't resolve when developing from a different machine on the Tailscale network.

```javascript
// Before
server: {
  proxy: {
    '/api': { target: 'http://localhost:8000', changeOrigin: true },
    '/sanctum': { target: 'http://localhost:8000', changeOrigin: true },
  },
}

// After
server: {
  proxy: {
    '/api': { target: 'http://100.84.115.25:8000', changeOrigin: true, secure: false },
    '/sanctum': { target: 'http://100.84.115.25:8000', changeOrigin: true, secure: false },
  },
}
```

**Impact:** The `npm run dev` proxy now correctly forwards requests to the Tailscale server during development. The `secure: false` option handles Tailscale's local/self-signed certificates.

---

### Fix 4: Backend APP_URL (Critical)

**File:** `backend-laravel/.env`

**Problem:** `APP_URL=http://localhost:8000` had two cascading effects that broke production for the admin panel:
1. **CORS:** `config/cors.php` uses `env('APP_URL')` in `allowed_origins`, so `http://100.84.115.25:8000` was **not** in the allowed list.
2. **Sanctum:** `config/sanctum.php` adds `Sanctum::currentApplicationUrlWithPort()` (derived from `APP_URL`) to the `stateful` domains array, so the production IP was **not** recognized as stateful — meaning `EnsureFrontendRequestsAreStateful` wouldn't run session/CSRF middleware for the admin SPA.

```
# Before
APP_URL=http://localhost:8000

# After
APP_URL=http://100.84.115.25:8000
```

**Impact:** With this change:
- `http://100.84.115.25:8000` is added to CORS allowed origins (via `env('APP_URL')`)
- `100.84.115.25:8000` is added to Sanctum's `stateful` domains (via `currentApplicationUrlWithPort()`)
- The React admin SPA's session-cookie + CSRF authentication now works correctly in production

> **Note:** The backend must be restarted after changing `.env` for the new value to take effect.

---

### Fix 5: React Admin .env.example (Low)

**File:** `admin-react/.env.example` (new file)

**Problem:** No documentation existed for configuring the React Admin app's environment variables.

```env
# Base URL for API requests when the admin panel is served from a different origin than the API
# Leave empty if served from the same origin as the API (e.g., http://100.84.115.25:8000)
VITE_API_BASE_URL=http://100.84.115.25:8000
```

**Impact:** New contributors and deployment environments have clear documentation for configuring the admin panel.

---

## Verification

After these changes, the full auth flow is verified:

| Component | Auth Method | Target | Status |
|-----------|-------------|--------|--------|
| Flutter App | Sanctum Bearer Token | `http://100.84.115.25:8000/api` | ✅ |
| React Admin | Sanctum Stateful Session + CSRF | `http://100.84.115.25:8000/api` | ✅ |
| CORS (production) | Allows `http://100.84.115.25:8000` | Laravel config | ✅ |
| Sanctum Stateful (production) | Recognizes `100.84.115.25:8000` | Laravel config | ✅ |

---

## Files Modified

| File | Action |
|------|--------|
| `backend-laravel/.env` | Modified |
| `backend-laravel/.env.example` | Modified (to document `APP_URL`) |
| `user-flutter/lib/core/constants/api_constants.dart` | Modified |
| `admin-react/src/lib/api.js` | Modified |
| `admin-react/vite.config.js` | Modified |
| `admin-react/.env.example` | Created |
| `docs/API_INTEGRATION_REVIEW.md` | Updated (fixes section) |
| `docs/API_INTEGRATION_FIXES.md` | Created (this file) |
