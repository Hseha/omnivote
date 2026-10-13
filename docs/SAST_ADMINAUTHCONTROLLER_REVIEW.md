# SAST Review — AdminAuthController.php

**Date:** 2026-10-13
**File:** `backend-laravel/app/Http/Controllers/AdminAuthController.php` (505 lines)
**Scope:** CompleteTwoFactorLogin method flow — role validation, account activation checks, password-change constraints, and session-finalization security.
**Focus:** Static analysis of authentication sanitization, authorization boundaries, and privilege-escalation vectors.

---

## Executive Summary

The `AdminAuthController` implements a two-step admin authentication flow: `login()` (password + optional 2FA) and `completeTwoFactorLogin()` (TOTP/recovery code). The file demonstrates strong defensive design: role checks are re-validated at the moment of session finalization, the pending session marker is dropped on any failure path, and session regeneration occurs before authorization tokens are issued.

**However, three security gaps were identified** in the session-finalization chain:

| ID | Severity | Finding |
|----|----------|---------|
| **S-1** | 🔴 Critical | `must_change_password` (password-change constraint) is **not enforced** in `completeTwoFactorLogin()` — account with unrotated temp credentials can complete the 2FA handshake and obtain a session. |
| **S-2** | 🔴 Critical | The `/admin/login/2fa` route in `routes/api.php` is **not protected** by the `EnsurePasswordChanged` middleware. |
| **S-3** | 🟠 High | The role check (`panel_roles`) happens **before** the 2FA code validation. The pending session marker survives the role check but is dropped in the code-validation failure path. However, the ordering creates a race window that must be understood by reviewers. |

---

## Detailed Findings

### S-1: Critical — `must_change_password` Not Enforced at Session Finalization

**File:** `AdminAuthController.php`
**Method:** `completeTwoFactorLogin()`
**Lines:** 119–184

**Description:**
The `completeTwoFactorLogin()` method performs the following checks before calling `completeLogin()`:

1. Session availability
2. Pending user ID exists in session
3. Pending session has not expired
4. User exists and has 2FA enabled
5. **Account is active** (`is_active` check)
6. **Role is in `panel_roles`** (with comment: "Step 1's eligibility checks have to still hold at the moment the session is actually minted")
7. 2FA attempts throttled
8. 2FA code is valid

**Missing check:** There is **no check for `must_change_password`** on line 136 or anywhere in the `completeTwoFactorLogin()` method. The method relaxes the `must_change_password` flag by design: the comment on line 136 suggests that `passwordChanged` middleware is expected to handle this at the route level. However, the `EnsurePasswordChanged` middleware is NOT applied to the `/admin/login/2fa` route (see S-2).

**Impact:**
- Student accounts provisioned with registrar-issued temporary credentials (with `must_change_password = true`) can complete the 2FA login flow and obtain a Sanctum bearer token.
- The Flutter client enforces this on the UI, but any HTTP client (or a compromised mobile device) can bypass the UI and call `/api/admin/login/2fa` directly.
- A compromised mobile device with a unrotated temporary credential can be used to vote (if the student has eligibility) without ever setting a strong password.

**Code evidence:**
```php
// completeTwoFactorLogin() lines 136-142
if (! $user->is_active) {
    $request->session()->forget(['two_factor_pending_user_id', 'two_factor_pending_at']);
    return response()->json(['message' => 'Your account has been disabled.'], 403);
}

// Line 142-146: Role check — but NO must_change_password check
if (! in_array($user->role, config('permissions.panel_roles', []), true)) {
    $this->dropPendingTwoFactor($request);
    return response()->json(['message' => 'Forbidden'], 403);
}
```

**Remediation:**
```php
// Add between is_active check and role check (lines 136-146):
if ((bool) $user->must_change_password) {
    $request->session()->forget(['two_factor_pending_user_id', 'two_factor_pending_at']);
    return response()->json([
        'message' => 'You must set a new password before completing sign-in.',
        'must_change_password' => true,
    ], 403);
}
```

---

### S-2: Critical — `/admin/login/2fa` Route Missing `passwordChanged` Middleware

**File:** `backend-laravel/routes/api.php`
**Lines:** 50–52

**Description:**
The `/admin/login/2fa` route is defined without the `EnsurePasswordChanged` middleware:

```php
// routes/api.php:50-52
Route::post('/login/2fa', [AdminAuthController::class, 'completeTwoFactorLogin']);
```

Compare with the student routes (lines 269-279), which explicitly apply `passwordChanged`:

```php
// routes/api.php:269-279
Route::middleware(['auth:sanctum', 'role:student', 'passwordChanged'])->group(function () {
    Route::post('/logout', ...);
    Route::get('/me', ...);
    Route::post('/password/change', ...);
});
```

The admin login 2FA route is **outside** any middleware group, so the `EnsurePasswordChanged` middleware is never evaluated for this endpoint.

**Impact:**
This is a direct consequence of S-1. Even if the `completeTwoFactorLogin()` method is fixed to check `must_change_password`, the route itself will still bypass the middleware. Both must be addressed.

**Remediation:**
```php
// Option A: Add passwordChanged to the existing admin route group
Route::middleware(['auth:sanctum', 'passwordChanged'])->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::post('/login/2fa', [AdminAuthController::class, 'completeTwoFactorLogin']);
    // ... other admin routes
});
```

```php
// Option B: Add withoutMiddleware for 2FA (like student routes)
Route::post('/login/2fa', [AdminAuthController::class, 'completeTwoFactorLogin'])
    ->withoutMiddleware(EnsurePasswordChanged::class);
```

The server-side gate (`EnsurePasswordChanged` middleware) must be the authoritative enforcement; the client UI flow is not a security control.

---

### S-3: High — Role Check Ordering Creates a Race Window

**File:** `AdminAuthController.php`
**Method:** `completeTwoFactorLogin()`
**Lines:** 136–146, 160–171

**Description:**
The role check (`panel_roles` membership) at line 142 happens **before** the 2FA code is validated (line 160). The code verification happens last among the eligibility checks:

1. Session availability (line 123)
2. Pending ID/expiration (line 125-130)
3. User + 2FA enabled (line 133-136)
4. Account active (line 136-142)
5. Role in panel_roles (line 142-146) ← after inactive check
6. 2FA throttle check (line 149-153)
7. Code validation (line 160-168)

**Finding:** The comment on lines 140-142 states this is intentional: "Step 1's eligibility checks have to still hold at the moment the session is actually minted." However, the race window exists because:

- Between step 1 (`login()`) and step 2 (`completeTwoFactorLogin()`), an attacker with a stolen password AND code could:
  1. Attempt login (password fails, but email doesn't exist → 401)
  2. Wait for the legitimate user to be provisioned with a new password
  3. Complete the 2FA flow with a stolen code

- More critically, the **role check happens before code validation**. If an attacker has:
  1. Valid password (used only for step 1)
  2. Valid 2FA code (stolen)
  3. Role changed to admin during the 5-minute verification window

  Then the role check at step 2 would pass (because the role was already changed), and the code validation would succeed.

**Impact:**
A privilege escalation race: the attacker who obtains both a password and a 2FA code can escalate their role during the 5-minute 2FA verification window and obtain a session.

**Remediation:**
```php
// Option A: Move role check to AFTER code validation (defense in depth)
// (Note: this slightly reduces usability — a failed code will drop the pending session anyway)
if ($this->twoFactorAttemptThrottled($user)) { /* ... */ }

$code = trim($request->code);
if (! $this->validSecondFactor($user, $code)) { /* ... */ }  // 422

// Now role check
if (! in_array($user->role, config('permissions.panel_roles', []), true)) { /* ... */ }
```

**Recommended combined remediation:**
Apply the role check and code validation in a single atomic transaction or step, and drop the pending session if ANY check fails:

```php
// 1. Verify code first (validate attacker has the second factor)
if (! $this->validSecondFactor($user, $code)) {
    $this->dropPendingTwoFactor($request);
    return response()->json(['message' => 'Invalid authentication code.'], 422);
}

// 2. Then check role and other eligibility
if (! in_array($user->role, config('permissions.panel_roles', []), true)) {
    $this->dropPendingTwoFactor($request);
    return response()->json(['message' => 'Forbidden'], 403);
}

// 3. Then check account activation and password change constraints
if (! $user->is_active) { /* ... */ }
if ((bool) $user->must_change_password) { /* ... */ }
```

---

## Additional Security Observations

### S-4: Missing Audit Logging on 2FA Authentication Events

**Severity:** 🟡 Medium

**Description:**
The `completeTwoFactorLogin()` flow does not emit any audit logs for:
- Failed 2FA code attempts (temporary lockout is implemented via `RateLimiter`, but no audit record)
- Successful 2FA completions
- Role changes that reject a pending 2FA login

**Recommendation:**
```php
// After successful 2FA verification
Audit::log('admin_login_2fa_success', [
    'user_id' => $user->id,
    'ip_address' => $request->ip(),
    'user_agent' => $request->userAgent(),
]);
```

### S-5: Session Regeneration Timing is Correct but Should Be Documented

**Severity:** 🟢 Low

**Description:**
The `$request->session()->regenerate()` is called in `completeLogin()` after `Auth::login($user)`. This is the correct order: regenerate the session ID AFTER the user is authenticated to prevent session fixation. The session regeneration occurs before any token is issued.

**No action required.** This is already implemented correctly.

### S-6: 2FA Recovery Code Mutation Not Atomic

**Severity:** 🟡 Medium

**Description:**
The `validSecondFactor()` method removes a used recovery code from the database inline:

```php
$index = TwoFactor::findRecoveryIndex($user->two_factor_recovery_codes, $code);
if ($index === null) {
    return false;
}

$codes = $user->two_factor_recovery_codes;
unset($codes[$index]);
$user->two_factor_recovery_codes = array_values($codes);
$user->save();  // ← Not in a transaction
```

If the application crashes between `unset()` and `save()`, the recovery code is consumed but not recorded as used.

**Recommendation:**
Wrap the mutation in a database transaction or use a database-level array manipulation.

---

## Flow Diagram: CompleteTwoFactorLogin() Validation Order

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│ completeTwoFactorLogin(Request)                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│ 1. sessionUnavailable() → 400 if session missing                          │
│ 2. pendingUserId ← session.get('two_factor_pending_user_id')              │
│ 3. Expired? → 401 if TTL exceeded (5 min)                                 │
│ 4. User not found? → 401 (drops pending)                                  │
│ 5. User has 2FA enabled? → 401 (drops pending)                            │
│ 6. is_active? → 403 (drops pending)                                       │
│ 7. Role in panel_roles? → 403 (drops pending)                             │
│ 8. 2FA throttled? → 429 (drops pending)                                   │
│ 9. Code validation → 422 if invalid                                       │
│ 10. Clear 2FA throttle, drop pending                                      │
│ 11. completeLogin() → Auth::login, session.regenerate(), tokens issued    │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Gaps identified:** Steps 8 and 9 occur OUTSIDE the role/activation check. Steps 1, 4, 5, 6, 7, and 8 are performed, but the pending session is dropped on ANY failure. The correct order for defense-in-depth (per S-3) is:

```text
1. Session availability
2. Pending ID/expiration
3. User exists + has 2FA enabled
4. **Code validation**
5. **Role check**
6. **Account active**
7. **Password change constraint** (MISSING)
8. 2FA throttle (only fails, doesn't drop session)
9. completeLogin()
```

---

## Route-Level Missing Security Controls

| Route | Middleware Missing | Impact |
|-------|-------------------|--------|
| `POST /admin/login/2fa` | `EnsurePasswordChanged` | Enables login with unrotated temporary credentials (S-1, S-2) |
| `POST /api/admin/login/2fa` | `Enforcement` 2FA | N/A (2FA is always enabled for panel roles with 2FA enforced) |

---

## Verification Commands

```bash
# 1. Verify role check in completeTwoFactorLogin()
grep -n "panel_roles" app/Http/Controllers/AdminAuthController.php
# Expected: Line 142 (in_array)

# 2. Verify must_change_password check
grep -n "must_change_password" app/Http/Controllers/AdminAuthController.php
# Expected: NO output (MISSING)

# 3. Verify passwordChanged middleware on /admin/login/2fa route
grep -B5 -A5 "login/2fa" routes/api.php
# Expected: No middleware listed on the route

# 4. Verify EnsurePasswordChanged is in the file
grep -n "EnsurePasswordChanged" app/Http/Controllers/AdminAuthController.php routes/api.php
# Expected: routes/api.php has ->withoutMiddleware or is not in the group

# 5. Verify session regeneration happens AFTER Auth::login
grep -n "Auth::login\|session()->regenerate" app/Http/Controllers/AdminAuthController.php
# Expected: regenerate() at line 180, before tokens issued

# 6. Verify role check happens BEFORE session creation
# Login() flow:
#   Line 78: role check
#   Line 99: session put (after role check)
# Complete: role validated BEFORE session is created

# 7. Verify 2FA code validation happens AFTER role check
# completeTwoFactorLogin() flow:
#   Line 142: role check
#   Line 160: code validation
#   Expected: role check BEFORE code validation (S-3)
```

---

## Priority Remediation Order

| Priority | Action | Owner | Effort |
|----------|--------|-------|--------|
| **P0** | Add `must_change_password` check in `completeTwoFactorLogin()` | Backend | 15 min |
| **P0** | Apply `EnsurePasswordChanged` middleware to `/admin/login/2fa` route (or use `withoutMiddleware`) | Backend + Routes | 15 min |
| **P1** | Reorder role check to after code validation (S-3) | Backend | 1 hour |
| **P1** | Add audit logging for 2FA success/failure | Backend | 1 hour |
| **P2** | Make 2FA recovery code mutation atomic | Backend | 2 hours |
| **P2** | Document session regeneration and check ordering in code comments | Developer | 30 min |

---

## References

- `backend-laravel/app/Http/Middleware/EnsurePasswordChanged.php` — Server-side password-change enforcement
- `backend-laravel/config/permissions.php` — Panel roles definition
- `backend-laravel/app/Support/TwoFactor.php` — 2FA verification logic
- `backend-laravel/database/migrations/2026_09_22_000002_add_vote_ledger_indexes_and_must_change_password.php` — `must_change_password` column
- `docs/SECURITY_ASSESSMENT_2026_09_26.md` — Original security assessment (C-1, M-2)

---

*Prepared by: Security Engineering Review\n*Assessment Date: 2026-10-13*
