# Pull Request Guide: fix/flutter-web-cors-connectivity

## PR summary

**Branch:** `fix/flutter-web-cors-connectivity` (pushed to `origin`)

**Commits:** 1 (`8389a89`)

**Changes:** 2 files, +18 / -8

| File | Change |
|------|--------|
| `backend-laravel/config/cors.php` | Added `http://localhost:8080` and `http://127.0.0.1:8080` to `allowed_origins` so the Flutter web dev server (port 8080) passes CORS preflight. |
| `docs/FLUTTER_WEB_API_CONNECTIVITY_FIX.md` | Updated CORS section to reflect the new hardcoded origins; added local-dev note; corrected `php8.4-fpm` → `php8.3-fpm`. |

## Review checklist

1. `backend-laravel/config/cors.php` — verify the two new origins are specific loopback addresses (no wildcards).
2. `docs/FLUTTER_WEB_API_CONNECTIVITY_FIX.md` — verify prose matches the code changes.
3. `php -l backend-laravel/config/cors.php` — confirm no syntax errors.

## Creating the PR on GitHub

1. Open a browser and go to:
   ```
   https://github.com/Hseha/omnivote/pull/new/fix/flutter-web-cors-connectivity
   ```
2. Confirm the base branch is `main` and the compare branch is `fix/flutter-web-cors-connectivity`.
3. Fill in a description using the PR summary above.
4. Click **Create pull request**.

## Merging

1. After CI passes (if applicable) and reviewers approve, click the **Merge pull request** button.
2. Choose **Create a merge commit** (or **Squash and merge** if you prefer a single commit).
3. Confirm the merge.

## Deleting the remote branch after merge

After the PR is merged:

```bash
# Option A — GitHub UI: the UI usually offers a "Delete branch" button
# after merge completes.

# Option B — command line:
cd /home/Michael/omnivote
git fetch --prune
git branch -d fix/flutter-web-cors-connectivity       # local (if checked out)
git push origin --delete fix/flutter-web-cors-connectivity  # remote
```

## Switching back to main

```bash
git checkout main
git pull origin main
```
