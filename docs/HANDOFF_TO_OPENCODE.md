# Handoff → opencode

> **From:** Cline session, 2026-10-09. **Purpose:** transfer pending work with full context so another agent can continue without re-investigating.
> **Scope:** read this fully before touching anything. Do not redo completed work.

---

## 1. COMPLETED & VERIFIED — do not redo

### Pixel-avatar removal (admin console My Profile)

**Root cause found (for context):** the pixel-avatar preset gallery looked "gone" because the `/admin/` CSP header (`img-src 'self' data:` — added 2026-10-01 in `27ef041`) blocks the external DiceBear images the gallery used (feature added 2026-09-27 in `0b00ef8`). Decision (Michael, 2026-10-09): **remove the presets entirely**, keep the default profile icon + device upload. Do NOT change the CSP/nginx — strict header stays.

**Changes made (uncommitted, in working tree):**

| File | Change |
|---|---|
| `admin-react/src/Settings.jsx` | Removed `PIXEL_AVATAR_PALETTE`, `PIXEL_AVATAR_SEEDS`, `pixelAvatarUrl()`, `handlePickAvatar()`, gallery JSX. Kept preview icon, **Choose from Device** upload, Pixelate-upload checkbox, Save/Remove. Caption now "Upload a photo from your device". Added `onError={fallbackAvatarOnError(profile?.name)}` to preview `<img>` for legacy saved URLs. Added `import { fallbackAvatarOnError } from './lib/avatar'`. |
| `admin-react/src/Settings.css` | Removed dead `.avatar-presets` / `.avatar-option*` rules |
| `admin-react/src/components/Header.jsx` | Default avatar now `initialsAvatarDataUri(...)` (local) instead of `api.dicebear.com` |
| `admin-react/src/components/DashboardWidgets.jsx` | Same swap in `authorAvatarFor()` |

**Already verified:** `npm run build` ✓ · `npx eslint src/Settings.jsx src/components/Header.jsx src/components/DashboardWidgets.jsx` ✓ · repo-wide grep shows **zero** `dicebear`/`PIXEL_AVATAR`/`avatar-presets` leftovers ✓ · net diff −79/+16.

---

## 2. PENDING TASKS

### Task A — Clean legacy pixel-avatar values from `users.avatar_url` (needs Michael)

Rows where `avatar_url LIKE '%dicebear%'` are dead (CSP-blocked). Fallback UI already handles them, so this is housekeeping — **run the SELECT first, show results, then UPDATE**.

**Local** (MySQL root password required — ask Michael; he agreed to provide it):
```bash
sudo mariadb -u root -p omnivote_local <<'SQL'
SELECT id, name, LEFT(avatar_url, 60) AS avatar FROM users WHERE avatar_url LIKE '%dicebear%';
UPDATE users SET avatar_url = NULL WHERE avatar_url LIKE '%dicebear%';
SELECT ROW_COUNT() AS rows_updated;
SQL
```

**Production** (no SSH key on the dev machine works — Michael runs this himself on `debian`, or grant access first):
```bash
cd /var/www/omnivote/backend-laravel
DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2)
U=$(grep '^DB_USERNAME=' .env | cut -d= -f2)
P=$(grep '^DB_PASSWORD=' .env | cut -d= -f2)
mariadb -u"$U" -p"$P" "$DB" <<'SQL'
SELECT id, name, LEFT(avatar_url, 60) AS avatar FROM users WHERE avatar_url LIKE '%dicebear%';
UPDATE users SET avatar_url = NULL WHERE avatar_url LIKE '%dicebear%';
SELECT ROW_COUNT() AS rows_updated;
SQL
```
Note: production may already be `0` rows (the feature only worked in prod 2026-09-27 → 10-01).

### Task B — Commit the avatar work correctly (coordinate with other agents!)

⚠️ **The working tree also contains another agent's in-progress "candidacy withdraw" work** (backend controllers/routes/migration/tests + Flutter files + `deploy/nginx-*.conf` + `.github/workflows/publish-student-app.yml`). Do **not** `git add -A`. Commit only:
```
admin-react/src/Settings.jsx admin-react/src/Settings.css admin-react/src/components/Header.jsx admin-react/src/components/DashboardWidgets.jsx
```
Suggested message: `fix(admin): remove CSP-blocked pixel avatar presets; keep device upload`
(Also `docs/CODE_FILE_FUNCTIONS_GUIDE.md` is a separate completed deliverable — commit separately if Michael wants it tracked.)

### Task C — Low-end Android compatibility (DIAGNOSED, awaiting Michael's decision)

Root causes confirmed — **implementation blocked on Michael choosing an option:**
1. **minSdk = 24 (Android 7.0)** — silent Flutter 3.47 default at `user-flutter/android/app/build.gradle.kts:36` (`minSdk = flutter.minSdkVersion`). Flutter's hard floor is API 23 (warns below 24). Fix if chosen: set `minSdk = 23` explicitly with a comment, rebuild, republish.
2. **Wrong APK handed to 32-bit phones** — releases ship all 3 ABIs but docs/in-app updater/release notes all point to arm64. Confirmed real-world failure: Vivo Y15s (2015 model = 32-bit, Android 5.1 → *cannot* run the app at all; Flutter won't build below API 23 either). Candidate fixes: "which APK" table in release notes (`publish-student-app.yml` notes template) + `docs/08_BUILD_RELEASE.md` reword, ABI-aware in-app download (currently just opens `latest.htmlUrl`).
Do not implement until Michael picks.

### Task D — Optional backend perf backlog (NOT approved, low priority)

Diagnosed while ruling out backend as the compat cause — each needs Michael's OK: enable nginx `gzip` for JSON · downscale candidate photos on upload (accepts up to 5 MB, never resized) · rate-limit real-IP problem (all funnel traffic arrives as 127.0.0.1 → shared `api-public` 300/min bucket across whole school; ~150+ polling students = 429s on election day).

---

## 3. DO NOT TOUCH

- **iOS**: explicitly out of scope (Michael: "no"). Status quo stands.
- **CSP / `deploy/nginx-*.conf`**: strict `img-src 'self' data:` is intentional; another agent is also editing these files.
- **Other agent's candidacy-withdraw files** (listed in Task B) — don't edit or commit them.
- The repo has **no commits from this session** — everything above is working-tree only.

## 4. Verification commands

```bash
cd admin-react && npm run build && npx eslint src/Settings.jsx src/components/Header.jsx src/components/DashboardWidgets.jsx
```
