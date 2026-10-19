#!/usr/bin/env bash
# ============================================================================
# Exercises deploy/omnivote-nginx-vhosts against a temporary directory.
#
#   bash deploy/tests/nginx-vhost-helper_test.sh
#
# The helper overwrites the live nginx vhosts, and it is the one piece of
# the deploy path with a destructive failure mode: if it cannot put the
# previous vhosts back, a bad render leaves nginx with a half-updated
# configuration. The real helper hard-codes /etc/nginx, so it is run here
# against a temp directory with a fake `nginx` that fails on a config that
# is not marked VALID.
#
# Three cases, and each one is a state a bad deploy could produce:
#
#   1. a valid render installs and succeeds
#   2. an invalid render is detected, and the previous vhosts are restored
#   3. the install itself fails part way through, and the vhost that WAS
#      updated is put back — the case the helper's install guard exists for
# ============================================================================
set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
deploy_dir="$(cd "$here/.." && pwd)"
helper="$deploy_dir/omnivote-nginx-vhosts"

if [[ ! -x "$helper" ]]; then
    echo "FAIL: $helper is missing or not executable"
    exit 1
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

status=0

fail() {
    echo "FAIL: $1"
    status=1
}

ok() {
    echo "ok: $1"
}

sites="$tmp/sites-available"
mkdir -p "$sites"

# A fake nginx: fails only on a vhost that is explicitly marked BROKEN, and
# accepts an empty one. That is deliberate — the real nginx rejects an empty
# file too, so if the fake also rejected it, the empty-render guard in the
# helper would be masked by the nginx -t check and never exercised.
cat > "$tmp/nginx" <<NGX
#!/bin/sh
for f in "$sites"/*; do
  [ -e "\$f" ] || continue
  if grep -q BROKEN "\$f"; then
    echo "nginx: invalid configuration in \$f" >&2
    exit 1
  fi
done
echo "nginx: configuration file test is successful"
NGX
chmod +x "$tmp/nginx"

# An installer that fails on the SECOND file only, so the first vhost is
# updated and then the install dies part way through. This is the state the
# helper's install guard exists to recover from: without the guard, `set -e`
# aborts on the failing install and the first vhost stays updated with no
# rollback.
cat > "$tmp/installer" <<INS
#!/bin/sh
# usage: installer -m 644 <src> <dest>
dest="\$4"
# Only fail when the test asks for it, so the earlier cases exercise the
# normal install path.
[ -f "$tmp/fail-install" ] || { exec /usr/bin/install "\$@"; }
case "\$dest" in
  *omnivote-funnel-api)
    echo "install: cannot create regular file '\$dest': Permission denied" >&2
    exit 1
    ;;
esac
exec /usr/bin/install "\$@"
INS
chmod +x "$tmp/installer"

# Each case starts from the vhosts as they are on the server right now, so
# one case cannot leave state behind for the next.
reset_sites() {
    rm -rf "$sites"
    mkdir -p "$sites"
    # The injected install failure belongs to case 3 alone.
    rm -f "$tmp/fail-install"
    printf 'server { listen 80; # VALID previous omnivote }\n' > "$sites/omnivote"
    printf 'server { listen 127.0.0.1:8080; # VALID previous funnel }\n' > "$sites/omnivote-funnel-api"
}

run_helper() {
    NGINX_SITE_DIR="$sites" \
    NGINX_BIN="$tmp/nginx" \
    NGINX_RELOAD_CMD="true" \
    INSTALL_BIN="$tmp/installer" \
    SRC_OMNIVOTE="$tmp/new-omnivote.conf" \
    SRC_FUNNEL="$tmp/new-funnel.conf" \
        "$helper" >"$tmp/helper.out" 2>&1
}

# --- 1. a valid render installs and succeeds -------------------------------
reset_sites
printf 'server { listen 80; # VALID new omnivote }\n' > "$tmp/new-omnivote.conf"
printf 'server { listen 127.0.0.1:8080; # VALID new funnel }\n' > "$tmp/new-funnel.conf"

if run_helper; then
    ok "a valid render exits 0"
else
    fail "a valid render exited non-zero: $(cat "$tmp/helper.out")"
fi

if grep -q "VALID new omnivote" "$sites/omnivote"; then
    ok "the valid render is installed"
else
    fail "the valid render was not installed"
fi

if "$tmp/nginx" -t >/dev/null 2>&1; then
    ok "nginx -t passes after a valid install"
else
    fail "nginx -t fails after a valid install"
fi

# --- 2. an invalid render is detected and rolled back ----------------------
reset_sites
printf 'server { listen 80; # VALID new omnivote }\n' > "$tmp/new-omnivote.conf"
printf 'server { BROKEN SYNTAX <<< }\n' > "$tmp/new-funnel.conf"

if run_helper; then
    fail "an invalid render exited 0"
else
    ok "an invalid render exits non-zero"
fi

if grep -q "VALID previous funnel" "$sites/omnivote-funnel-api"; then
    ok "the invalid vhost is rolled back to the previous one"
else
    fail "the invalid vhost was left on disk"
fi

if grep -q "VALID previous omnivote" "$sites/omnivote"; then
    ok "the valid sibling vhost is rolled back too"
else
    fail "the sibling vhost was left updated after a failed install"
fi

if "$tmp/nginx" -t >/dev/null 2>&1; then
    ok "nginx -t passes after the rollback — nginx is never left broken"
else
    fail "nginx is left with a broken configuration after a failed render"
fi

# --- 3. the install itself fails part way through -------------------------
# The installer is told to fail on the second file, so the first vhost is
# updated and then the install dies. This is the case the helper's install
# guard exists for: without the guard, `set -e` aborts on the failing
# install and the FIRST vhost stays updated with no rollback.
reset_sites
: > "$tmp/fail-install"
printf 'server { listen 80; # VALID new omnivote }\n' > "$tmp/new-omnivote.conf"
printf 'server { listen 127.0.0.1:8080; # VALID new funnel }\n' > "$tmp/new-funnel.conf"

if run_helper; then
    fail "a failed install exited 0"
else
    ok "a failed install exits non-zero"
fi

if grep -q "VALID previous omnivote" "$sites/omnivote"; then
    ok "the vhost updated before the failure is rolled back"
else
    fail "the vhost updated before the failure was left updated"
fi

if [ -f "$sites/omnivote-funnel-api" ] &&
   grep -q "VALID previous funnel" "$sites/omnivote-funnel-api"; then
    ok "the vhost whose install failed is left exactly as it was"
else
    fail "the vhost whose install failed was modified"
fi

# --- 4. an empty render is refused outright -------------------------------
# A shell redirect creates the output file before the command that fills it
# runs, so a render that fails part way through leaves a zero-byte file.
# Installing that over the live configuration empties it — so the helper
# must refuse an empty source before touching anything.
reset_sites
: > "$tmp/new-omnivote.conf"
printf 'server { listen 127.0.0.1:8080; # VALID new funnel }\n' > "$tmp/new-funnel.conf"

if run_helper; then
    fail "an empty render exited 0"
else
    ok "an empty render exits non-zero"
fi

if grep -q "VALID previous omnivote" "$sites/omnivote"; then
    ok "the live vhost is untouched by a refused empty render"
else
    fail "the live vhost was modified by a refused empty render"
fi

if grep -q "VALID previous funnel" "$sites/omnivote-funnel-api"; then
    ok "the sibling vhost is untouched too"
else
    fail "the sibling vhost was modified by a refused empty render"
fi

exit "$status"
