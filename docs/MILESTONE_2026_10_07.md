# Milestone — 2026-10-07: Production Deploy Pipeline + First Signed Android Release

Status: **REACHED** (all checklist items done and verified)

This milestone makes OmniVote actually shippable: a repeatable CI→VPS deploy
pipeline, a spoof-proof admin gate, and the first signed student-app APK
release published to GitHub Releases.

## Scope

1. **Production deploy pipeline** — automated end-to-end
2. **Security gate** — admin console tailnet-only, public funnel API-only
3. **First signed Android release** — `v1.1.0-test.2` prerelease published
4. **Release infrastructure** — reproducible signing + release automation

## 1. Production deploy pipeline

Manual, copy-the-right-confs deployment is gone. Pushing to `main` (or a
`workflow_dispatch` with `environment=production`) now:

- runs the review gates (`master` / `stable` merged into main): `test`,
  `Release size budget`;
- then the `Deploy` job:
  - Tailscale OpenID Connect (GitHub Actions OIDC) — Tailscale trust
    credential `T7CEGuRiFU11CNTRL-kVgxyRtJFa11CNTRL`,
    `TS_AUDIENCE=api.tailscale.com/T7CEGuRiFU11CNTRL-kVgxyRtJFa11CNTRL`,
    credential subject
    `repo:Hseha@152290531/omnivote@1316818266:environment:production`;
  - SSH into the VPS as `omnivote-deploy`;
  - `deploy/deploy.sh` — git sync, frontend/backend builds, security
    assertions, migrations, cache/config/route refresh, storage perms,
    `storage:link --force`, queue/php-fpm restart, backup timer enforcement.

First successful end-to-end deploy: `Deployed 61517b6 on main` (run
`37622054804`).

### What had to be fixed to get here

| Blocked at | Root cause | Fix |
|---|---|---|
| Deploy skipped | repo var `PRODUCTION_DEPLOY_ENABLED` literally `"true\r\n"` | set to exactly `"true"` |
| `403 token exchange` | GitHub immutable OIDC subject (truncated repo id) | correct subject `@1316818266` (repo created 2026-07-30, post immutable roll-out) |
| `/admin/` public 404 (expected) | funnel gate | deployed `deploy/nginx-funnel-api.conf` |

## 2. Security gate

- **Public funnel** (`:443` via Tailscale Funnel, IP `103.84.155.153`):
  serves **only** `/api/` + `/storage/`. `/admin/*`, `/up`, `/sanctum/*`,
  `/api/admin/*` return **404**.
- **Tailnet** (`debian.tail7e9e1e.ts.net` → `100.84.115.25`): full admin
  console at `/admin/` + admin API.
- Gate is keyed on Tailscale's `Tailscale-Funnel-Request: ?1` header (client
  cannot spoof; fails closed). Admin console link:
  `https://debian.tail7e9e1e.ts.net/admin/`.

## 3. First signed Android release

Published as a **prerelease** on GitHub Releases:

- **`v1.1.0-test.2` — OmniVote v1.1.0 Device Test Prerelease**
- URL: `https://github.com/Hseha/omnivote/releases/tag/v1.1.0-test.2`
- Assets (all 3 ABIs, signed + obfuscated split APKs):
  - `app-arm64-v8a-release.apk` ← modern phones (99% of installs)
  - `app-armeabi-v7a-release.apk`
  - `app-x86_64-release.apk`

They come from the workflow `Test and build signed APKs` (run
`37629090738`, green), which the release references via `build_run_id`.

### Signing setup (do not lose)

- Keystore: `~/omnivote-release.keystore` (JKS, RSA-2048, alias
  `omnivote-release`, validity 10000 days, SHA-256 fingerprint
  `2A:70:37:6D:29:51:69:B5:EB:EA:2E:11:F0:57:52:15:6E:57:4E:64:88:BC:5A:4B:89:D0:2A:EF:D6:69:29:94`).
- Credentials: `~/omnivote-release-credentials.env` (chmod 600).
- Old, unreadable keystore preserved as `~/omnivote-release.keystore.deprecated`.
- Secrets on the `production` environment: `ANDROID_RELEASE_KEYSTORE_BASE64`,
  `ANDROID_RELEASE_STORE_PASSWORD`, `ANDROID_RELEASE_KEY_ALIAS`,
  `ANDROID_RELEASE_KEY_PASSWORD`.

### Release workflow

`Publish student app release` (workflow_dispatch):
- inputs `build_run_id`, `smoke_test_passed`, `release_channel` (`test`/`stable`);
- gate: `ref == main && (channel == 'test' || smoke_test_passed == true)`;
- test prerelease tag `v<ver>-test.<n>`; stable requires a passed smoke test.

## 4. Repo / doc / ops fixes rolled into this milestone

- Nginx confs: `deploy/nginx-funnel-api.conf`, nginx vhost `/admin/`
  blocks now send `Referrer-Policy: same-origin`. Why: Sanctum's
  `EnsureFrontendRequestsAreStateful::fromFrontend` keys off
  `Referer`/`Origin`; `no-referrer` made the browser drop `Referer` on
  same-origin calls, so the admin console's authenticated GETs
  (`2fa/status`, `/me`) returned 401 → login bounced back to the form.
  `same-origin` restores the header to the same origin only.
- `deploy/setup-server.sh`: installs the funnel conf.
- `deploy/deploy.sh`: adds `php artisan storage:link --force` on deploy.
- `docs/DEPLOYMENT_BACKUP_GUIDE.md`: updated to real runbook.
- Pushed baseline tag `archive/user-flutter` (needed by release-notes step;
  it existed locally but was never on the remote — release step failed with
  `fatal: Needed a single revision` until pushed).

## Verified after milestone

- Public funnel: `GET /api/election/status` → 200; `GET /admin/` → 404.
- Tailnet: `GET /up` → 200; `GET /admin/` → 200; admin login (POST
  `/api/admin/login`) → 200 with `two_factor: { enabled: false, required: true }`.
- 2FA enrollment endpoints reachable and functional.

## Known trade-offs / open items

- `deploy.sh` does **not** rewrite `/etc/nginx/` — nginx config drift stays
  manual (documented in `deploy/README.md`).
- The admin-cookie/`Referrer-Policy` fix was a **night-of** change (ngnix
  confs edited on the VPS during this milestone). Re-verify the confs are
  committed in `deploy/` on `main` before the next deploy.
- Workstation `~/.ssh/omnivote-github-deploy` mirrors the CI deploy key;
  recommend removing it (CI uses the `DEPLOY_SSH_PRIVATE_KEY` secret).
- Dev-fixture creds in `database/data/users.json` are git history / burned;
  do not reuse in production.

## Next steps (not part of this milestone)

1. Real-device smoke of `v1.1.0-test.2` on a **non-Tailscale** phone
   (login/register, candidates + photos, forgot/change password, ballot/vote,
   profile, network switch).
2. Once smoke passes → publish **stable** `v1.1.0` with
   `smoke_test_passed=true` and `release_channel=stable`.
3. Track a saved copy of `~/omnivote-release-credentials.env`.